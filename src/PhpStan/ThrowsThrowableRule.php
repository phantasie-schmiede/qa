<?php

declare(strict_types=1);

namespace PSBits\Qa\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FunctionReturnStatementsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\PhpDoc\ResolvedPhpDocBlock;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\MultipleNodeTypesRule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ConditionalType;
use PHPStan\Type\ConditionalTypeForParameter;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use Throwable;

use function explode;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function ltrim;
use function sprintf;

/**
 * Validates the @throws types of methods and functions against Throwable,
 * marker aware.
 *
 * The @throws docblock carries the union of the exceptions thrown on all
 * supported core majors (CGL rule 3), so a line seen by a single major
 * names a class the installed core does not provide; the built-in
 * throws.notThrowable check (ignored in the shared baseline) validates
 * the combined tag type against the installed core only and would report
 * it. This rule takes its place: a line is valid when the documented
 * type is a Throwable subtype, or when every class the installed core
 * does not provide is covered by the trailing "// t3:N" marker of the
 * line naming a different major. A resolvable non-Throwable, an
 * unmarked class the installed core does not provide, and a marker for
 * the installed major itself are errors.
 *
 * The installed major is read from the composer installed.json the
 * throws fixer uses; when it cannot be determined (e.g. no vendor
 * state), every marker is tolerated and only unmarked missing classes
 * are reported.
 *
 * @implements MultipleNodeTypesRule<Node>
 * @see ThrowsDocblockRuleBase
 */
final class ThrowsThrowableRule implements MultipleNodeTypesRule
{
    public const string IDENTIFIER = 'psbits.throwsThrowable';

    private ?int $installedMajor       = null;
    private bool $installedMajorLoaded = false;

    public function __construct(
        private readonly FileTypeMapper     $fileTypeMapper,
        private readonly TypeNodeResolver   $typeNodeResolver,
        private readonly ReflectionProvider $classReflectionProvider,
        private readonly string             $installedJsonPath,
    ) {
    }

    /**
     * The major of the installed typo3/cms-core (e.g. 13), or null when
     * the version cannot be determined from the installed.json.
     */
    public static function majorFromInstalledJson(string $installedJsonPath): ?int
    {
        if (!is_file($installedJsonPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($installedJsonPath), true);

        if (!is_array($data)) {
            return null;
        }

        foreach (($data['packages'] ?? []) as $package) {
            if (!is_array($package) || 'typo3/cms-core' !== ($package['name'] ?? null)) {
                continue;
            }

            /*
             * Composer records versions with a leading "v" (e.g. v14.3.10);
             * a branch alias (e.g. dev-main) carries no numeric major.
             */
            $major = explode('.', ltrim((string) ($package['version'] ?? ''), 'v'))[0];

            return is_numeric($major) ? (int) $major : null;
        }

        return null;
    }

    /**
     * The trailing "// t3:13" or "// t3:14" marker of a @throws tag line,
     * or null. The fixer writes the marker as " // t3:N" at the end of
     * the line; it is part of the tag description and never part of the
     * resolved tag type.
     */
    public static function markerOf(string $description): ?int
    {
        return 1 === preg_match('/^\s*\/\/\s*t3:(13|14)\s*$/', $description, $match) ? (int) $match[1] : null;
    }

    /**
     * The report for one @throws tag line, or null when the line is
     * valid.
     *
     * @param list<string> $missing the class names the installed core does not provide
     */
    public static function reportForTag(?int $installedMajor, ?int $markerMajor, bool $throwable, array $missing): ?string
    {
        if ($throwable) {
            return null;
        }

        if ([] === $missing) {
            return 'is not a Throwable subtype';
        }

        if (null !== $markerMajor && null !== $installedMajor && $markerMajor === $installedMajor) {
            return sprintf('is not available on core major %d, where the line carries the marker', $installedMajor);
        }

        if (null === $markerMajor) {
            return 'is not available on the installed core; mark the line for the core major that throws it';
        }

        return null;
    }

    public function getNodeType(): string
    {
        return MethodReturnStatementsNode::class;
    }

    public function getNodeTypes(): array
    {
        return [
            MethodReturnStatementsNode::class,
            FunctionReturnStatementsNode::class,
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        /*
         * The registry only dispatches the node types listed in
         * getNodeTypes(), so the union is the actual input type here.
         */
        /** @var MethodReturnStatementsNode|FunctionReturnStatementsNode $node */

        $resolved = $this->resolvedPhpDoc($node, $scope);

        if (null === $resolved->getNullableNameScope()) {
            return [];
        }

        $errors = [];

        foreach ($resolved->getPhpDocNodes() as $phpDocNode) {
            $tagValues = $phpDocNode->getThrowsTagValues('@phpstan-throws');

            if ([] === $tagValues) {
                $tagValues = $phpDocNode->getThrowsTagValues('@throws');
            }

            foreach ($tagValues as $tagValue) {
                $errors = [...$errors, ...$this->reportTag($resolved, $tagValue, $this->subject($node, $scope))];
            }
        }

        return $errors;
    }

    private function installedMajor(): ?int
    {
        if (!$this->installedMajorLoaded) {
            $this->installedMajor       = self::majorFromInstalledJson($this->installedJsonPath);
            $this->installedMajorLoaded = true;
        }

        return $this->installedMajor;
    }

    /**
     * Whether the documented type is a valid @throws type: a Throwable
     * subtype, with the house allowances for standalone void,
     * conditional types and unions (the same semantics as the built-in
     * check this rule replaces).
     */
    private function isThrowableType(Type $type): bool
    {
        // `void` standalone means "does not throw" and is a valid @throws type.
        if ($type->isVoid()->yes()) {
            return true;
        }

        if ($type instanceof ConditionalType) {
            return $this->isThrowableType($type->getIf()) && $this->isThrowableType($type->getElse());
        }

        if ($type instanceof ConditionalTypeForParameter) {
            return $this->isThrowableType($type->getIf()) && $this->isThrowableType($type->getElse());
        }

        if ($type instanceof UnionType) {
            foreach ($type->getTypes() as $innerType) {
                if ($innerType->isVoid()->yes() || !$this->isThrowableType($innerType)) {
                    return false;
                }
            }

            return true;
        }

        $toIntersectWith = [];

        foreach ($type->getObjectClassReflections() as $classReflection) {
            if (!$classReflection->isInterface()) {
                continue;
            }

            foreach ($classReflection->getRequireExtendsTags() as $requireExtendsTag) {
                $toIntersectWith[] = $requireExtendsTag->getType();
            }
        }

        return (new ObjectType(Throwable::class))->isSuperTypeOf(TypeCombinator::intersect($type, ...$toIntersectWith))->yes();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function reportTag(ResolvedPhpDocBlock $resolved, ThrowsTagValueNode $tagValue, string $subject): array
    {
        $type = $this->typeNodeResolver->resolve($tagValue->type, $resolved->getNullableNameScope());

        if ($this->isThrowableType($type)) {
            return [];
        }

        $missing = [];

        foreach (TypeUtils::flattenTypes($type) as $flattened) {
            /*
             * getObjectClassNames() yields nothing for non-object types, so
             * no instanceof check is needed.
             */
            foreach ($flattened->getObjectClassNames() as $class) {
                if (!$this->classReflectionProvider->hasClass($class)) {
                    $missing[] = $class;
                }
            }
        }

        $reason = self::reportForTag($this->installedMajor(), self::markerOf($tagValue->description), false, $missing);

        if (null === $reason) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    '%s @throws tag with type %s %s.',
                    $subject,
                    $type->describe(VerbosityLevel::typeOnly()),
                    $reason,
                )
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }

    private function resolvedPhpDoc(MethodReturnStatementsNode|FunctionReturnStatementsNode $node, Scope $scope): ResolvedPhpDocBlock
    {
        if ($node instanceof MethodReturnStatementsNode) {
            $method = $node->getMethodReflection();

            return $this->fileTypeMapper->getResolvedPhpDoc(
                $scope->getFile(),
                $method->getDeclaringClass()->getName(),
                $scope->isInTrait() ? $scope->getTraitReflection()->getName() : null,
                $method->getName(),
                null === $node->getDocComment() ? '' : $node->getDocComment()->getText(),
            );
        }

        $function = $node->getFunctionReflection();

        return $this->fileTypeMapper->getResolvedPhpDoc(
            $scope->getFile(),
            null,
            null,
            $function->getName(),
            null === $node->getDocComment() ? '' : $node->getDocComment()->getText(),
        );
    }

    private function subject(MethodReturnStatementsNode|FunctionReturnStatementsNode $node, Scope $scope): string
    {
        if ($node instanceof MethodReturnStatementsNode) {
            $method = $node->getMethodReflection();

            return 'Method ' . $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
        }

        $function  = $node->getFunctionReflection();
        $namespace = $scope->getNamespace();

        return 'Function ' . (null !== $namespace ? $namespace . '\\' : '') . $function->getName() . '()';
    }
}
