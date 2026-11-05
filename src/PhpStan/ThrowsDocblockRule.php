<?php

declare(strict_types=1);

namespace PSBits\Qa\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FunctionReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Node\ReturnStatementsNode;
use PHPStan\PhpDoc\ResolvedPhpDocBlock;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\Rules\MultipleNodeTypesRule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

use function sprintf;

/**
 * Checks that the @throws types of a method or function match exactly the
 * exceptions that are thrown (explicit throw points, including transitive
 * ones that are not caught).
 *
 * The message is machine readable and consumed by the throws docblock
 * applier (bin/qa throws-fix), which rewrites the @throws types to the
 * exact set of thrown exceptions. One error is emitted per function-like
 * construct whose @throws do not match, regardless of how many types
 * differ.
 *
 * The declared types are read tag by tag (never as a combined type) so
 * that every documented exception is taken into account even when the
 * exceptions form a subtype pair.
 *
 * Message format:
 *   Method Class\Name::method() @throws do not match the thrown exceptions: expected \A\B, \C\D, declared none.
 *   Function some\ns\function() @throws do not match the thrown exceptions: expected none, declared \A\B.
 * ("none" = no @throws types at all)
 */
final class ThrowsDocblockRule implements MultipleNodeTypesRule
{
    public const string IDENTIFIER = 'psbits.throwsDocblock';

    public function __construct(
        private readonly FileTypeMapper   $fileTypeMapper,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {
    }

    /**
     * @param list<string> $names class names without leading backslash
     * @return list<string>
     */
    private static function describe(array $names): string
    {
        return [] === $names ? 'none' : implode(', ', array_map(static fn(string $name): string => '\\' . $name, $names));
    }

    /**
     * @param list<string> $names
     * @return list<string> unique, case-insensitively sorted
     */
    private static function normalize(array $names): array
    {
        $names = array_values(array_unique($names));
        usort($names, static fn(string $a, string $b): int => strcasecmp($a, $b));

        return $names;
    }

    /**
     * @return list<string> object class names (interfaces included), without leading backslash
     */
    private static function objectClassNames(?Type $type): array
    {
        if (null === $type) {
            return [];
        }

        $names = [];

        foreach (TypeUtils::flattenTypes($type) as $flattened) {
            if (!$flattened instanceof ObjectType) {
                continue;
            }

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
        if ($node instanceof MethodReturnStatementsNode) {
            if ($node->methodReflection->isAbstract()->yes()) {
                /*
                 * Abstract and interface methods have no body, so the thrown
                 * set cannot be determined; their @throws is a contract.
                 */
                return [];
            }

            $method   = $node->methodReflection;
            $subject  = 'Method ' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
            $resolved = $this->fileTypeMapper->getResolvedPhpDoc(
                $scope->getFile(),
                $method->getDeclaringClass()->getName(),
                $scope->isInTrait() ? $scope->getTraitReflection()->getName() : null,
                $method->getName(),
                null === $node->getDocComment() ? '' : $node->getDocComment()->getText(),
            );
        } else {
            $function = $node->functionReflection;
            $subject  = 'Function ' . $node->function->namespacedName . '()';
            $resolved = $this->fileTypeMapper->getResolvedPhpDoc(
                $scope->getFile(),
                null,
                null,
                $function->getName(),
                null === $node->getDocComment() ? '' : $node->getDocComment()->getText(),
            );
        }

        $declared = $this->declaredClassNames($resolved);
        $thrown   = [];

        foreach ($node->statementResult->getThrowPoints() as $point) {
            /*
             * Implicit points mean "something may be thrown here" and are not
             * part of the exact thrown set with exceptions.implicitThrows=false.
             */
            if (!$point->isExplicit()) {
                continue;
            }

            $thrown = [...$thrown, ...$this->objectClassNames($point->getType())];
        }

        $thrown   = self::normalize($thrown);
        $declared = self::normalize($declared);

        if ($thrown === $declared) {
            return [];
        }

        $message = sprintf(
            '%s @throws do not match the thrown exceptions: expected %s, declared %s.',
            $subject,
            self::describe($thrown),
            self::describe($declared),
        );

        return [RuleErrorBuilder::message($message)->identifier(self::IDENTIFIER)->build()];
    }

    /**
     * Reads the documented exception classes tag by tag (one entry per
     * @throws or @phpstan-throws line, @phpstan-throws taking precedence),
     * so no subtype elimination is applied between the documented types.
     *
     * @return list<string> object class names, without leading backslash
     */
    private function declaredClassNames(ResolvedPhpDocBlock $resolved): array
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
}
