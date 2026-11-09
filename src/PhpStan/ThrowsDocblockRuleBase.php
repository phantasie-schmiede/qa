<?php

declare(strict_types=1);

namespace PSBits\Qa\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ThrowPoint;
use PHPStan\Node\FunctionReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatementsNode;
use PHPStan\PhpDoc\ResolvedPhpDocBlock;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\MultipleNodeTypesRule;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function sprintf;
use function usort;

/**
 * Shared machinery for the rules that compare a method or function's
 * documented @throws with the exceptions it throws (explicit throw
 * points, including transitive ones that are not caught).
 *
 * The message is machine readable and consumed by the throws docblock
 * applier (bin/qa throws-fix), which rewrites the @throws types. One
 * error is emitted per function-like construct, regardless of how many
 * types differ.
 *
 * The declared types are read tag by tag (never as a combined type) so
 * that every documented exception is taken into account even when the
 * exceptions form a subtype pair. A trailing marker comment on a tag
 * line (// t3:13) is part of the tag description and never influences
 * the resolved type.
 *
 * Message format:
 *   Method Class\Name::method() @throws do not match the thrown exceptions: expected \A\B, \C\D, declared none.
 *   Function some\ns\function() @throws do not match the thrown exceptions: expected none, declared \A\B.
 * ("none" = no @throws types at all)
 *
 * @implements MultipleNodeTypesRule<Node>
 */
abstract class ThrowsDocblockRuleBase implements MultipleNodeTypesRule
{
    public function __construct(
        protected readonly FileTypeMapper   $fileTypeMapper,
        protected readonly TypeNodeResolver $typeNodeResolver,
    ) {
    }

    /**
     * @param list<string> $names class names without leading backslash
     */
    protected static function describe(array $names): string
    {
        return [] === $names ? 'none' : implode(', ', array_map(static fn(string $name): string => '\\' . $name, $names));
    }

    /**
     * Builds the machine readable report message shared by the rules.
     *
     * @param list<string> $thrown   object class names, without leading backslash
     * @param list<string> $declared object class names, without leading backslash
     */
    protected static function message(string $subject, array $thrown, array $declared): string
    {
        return sprintf(
            '%s @throws do not match the thrown exceptions: expected %s, declared %s.',
            $subject,
            self::describe($thrown),
            self::describe($declared),
        );
    }

    /**
     * @param list<string> $names
     * @return list<string> unique, case-insensitively sorted
     */
    protected static function normalize(array $names): array
    {
        $names = array_values(array_unique($names));
        usort($names, static fn(string $a, string $b): int => strcasecmp($a, $b));

        return $names;
    }

    /**
     * @return list<string> object class names (interfaces included), without leading backslash
     */
    protected static function objectClassNames(?Type $type): array
    {
        if (null === $type) {
            return [];
        }

        $names = [];

        foreach (TypeUtils::flattenTypes($type) as $flattened) {
            /*
             * getObjectClassNames() yields nothing for non-object types, so
             * no instanceof check is needed.
             */
            foreach ($flattened->getObjectClassNames() as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function getNodeType(): string
    {
        return ReturnStatementsNode::class;
    }

    public function getNodeTypes(): array
    {
        return [
            MethodReturnStatementsNode::class,
            FunctionReturnStatementsNode::class,
        ];
    }

    public function processNode(Node $node, Scope $scope): array
    {
        /*
         * The registry only dispatches the node types listed in
         * getNodeTypes(), so the union is the actual input type here.
         */
        /** @var MethodReturnStatementsNode|FunctionReturnStatementsNode $node */

        return $this->processReturnStatements($node, $scope);
    }

    /**
     * Reads the documented exception classes tag by tag (one entry per
     * line carrying @throws or @phpstan-throws, @phpstan-throws taking
     * precedence), so no subtype elimination is applied between the
     * documented types.
     *
     * @return list<string> object class names, without leading backslash
     */
    protected function declaredClassNames(ResolvedPhpDocBlock $resolved): array
    {
        $nameScope = $resolved->getNullableNameScope();

        if (null === $nameScope) {
            return [];
        }

        $names = [];

        foreach ($resolved->getPhpDocNodes() as $phpDocNode) {
            $tagValues = $phpDocNode->getThrowsTagValues('@phpstan-throws');

            if ([] === $tagValues) {
                $tagValues = $phpDocNode->getThrowsTagValues('@throws');
            }

            foreach ($tagValues as $tagValue) {
                $names = [...$names, ...$this->objectClassNames($this->typeNodeResolver->resolve($tagValue->type, $nameScope))];
            }
        }

        return $names;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    protected function processReturnStatements(MethodReturnStatementsNode|FunctionReturnStatementsNode $node, Scope $scope): array
    {
        if ($node instanceof MethodReturnStatementsNode) {
            if ($node->getMethodReflection()->isAbstract()->yes()) {
                /*
                 * Abstract and interface methods have no body, so the thrown
                 * set cannot be determined; their @throws is a contract.
                 */
                return [];
            }

            $method   = $node->getMethodReflection();
            $subject  = 'Method ' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
            $resolved = $this->fileTypeMapper->getResolvedPhpDoc(
                $scope->getFile(),
                $method->getDeclaringClass()->getName(),
                $scope->isInTrait() ? $scope->getTraitReflection()->getName() : null,
                $method->getName(),
                null === $node->getDocComment() ? '' : $node->getDocComment()->getText(),
            );
            $points = $node->getStatementResult()->getThrowPoints();
        } else {
            $function  = $node->getFunctionReflection();
            $namespace = $scope->getNamespace();
            $subject   = 'Function ' . (null !== $namespace ? $namespace . '\\' : '') . $function->getName() . '()';
            $resolved  = $this->fileTypeMapper->getResolvedPhpDoc(
                $scope->getFile(),
                null,
                null,
                $function->getName(),
                null === $node->getDocComment() ? '' : $node->getDocComment()->getText(),
            );
            $points = $node->getStatementResult()->getThrowPoints();
        }

        $declared = self::normalize($this->declaredClassNames($resolved));
        $thrown   = self::normalize($this->thrownClassNames($points));

        return $this->report($subject, $thrown, $declared);
    }

    /**
     * Builds the report for the resolved name sets; an empty list means
     * the method passes.
     *
     * @param list<string> $thrown   object class names, without leading backslash
     * @param list<string> $declared object class names, without leading backslash
     * @return list<IdentifierRuleError>
     */
    abstract protected function report(string $subject, array $thrown, array $declared): array;

    /**
     * @param list<ThrowPoint> $points
     * @return list<string> object class names, without leading backslash
     */
    protected function thrownClassNames(array $points): array
    {
        $names = [];

        foreach ($points as $point) {
            /*
             * Implicit points mean "something may be thrown here" and are not
             * part of the exact thrown set with exceptions.implicitThrows=false.
             */
            if (!$point->isExplicit()) {
                continue;
            }

            $names = [...$names, ...$this->objectClassNames($point->getType())];
        }

        return $names;
    }
}
