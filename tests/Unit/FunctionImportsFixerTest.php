<?php

declare(strict_types=1);

use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\FunctionImportsFixer;

final class FunctionImportsFixerTest extends TestCase
{
    public function testFixIsIdempotent(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            function demo(): int
            {
                return \count(['a', 'b']);
            }
            php;
        $fixer = new FunctionImportsFixer();
        $once  = $this->fix($fixer, $code);

        self::assertSame($once, $this->fix($fixer, $once));
    }

    public function testIgnoresMethodsWithTheSameName(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            final class Registry
            {
                public function count(): int
                {
                    return 42;
                }
            }

            function demo(): int
            {
                return \count(['a', 'b']);
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;
            use function count;

            final class Registry
            {
                public function count(): int
                {
                    return 42;
                }
            }

            function demo(): int
            {
                return count(['a', 'b']);
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testIgnoresUnqualifiedMemberCalls(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            final class Demo
            {
                public static function count(): int
                {
                    return 42;
                }

                public function run(): int
                {
                    $other = new Demo();

                    return self::count() + $other->count() + count(['a', 'b']);
                }
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;
            use function count;

            final class Demo
            {
                public static function count(): int
                {
                    return 42;
                }

                public function run(): int
                {
                    $other = new Demo();

                    return self::count() + $other->count() + count(['a', 'b']);
                }
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testImportsAfterTheExistingImports(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            use App\Other;

            function demo(): int
            {
                return \count(['a', 'b']);
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;

            use App\Other;
            use function count;

            function demo(): int
            {
                return count(['a', 'b']);
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testImportsAndShortensFullyQualifiedCall(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            function demo(): int
            {
                return \count(['a', 'b']);
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;
            use function count;

            function demo(): int
            {
                return count(['a', 'b']);
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testImportsMixedQualifiedAndUnqualifiedCalls(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            function demo(string $line): int
            {
                return \count(['a', 'b']) + strlen($line);
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;
            use function count;
            use function strlen;

            function demo(string $line): int
            {
                return count(['a', 'b']) + strlen($line);
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testImportsMultipleFunctionsSorted(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            function demo(string $line): array
            {
                return [
                    'count' => \strlen($line),
                    'name'  => \strtolower($line),
                ];
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;
            use function strlen;
            use function strtolower;

            function demo(string $line): array
            {
                return [
                    'count' => strlen($line),
                    'name'  => strtolower($line),
                ];
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testImportsUnqualifiedCallsOfListedFunctions(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            use App\Other;

            function demo(): int
            {
                return count(['a', 'b']) + strlen('x');
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;

            use App\Other;
            use function count;
            use function strlen;

            function demo(): int
            {
                return count(['a', 'b']) + strlen('x');
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testLeavesClassContextsUntouched(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            final class Demo
            {
                public function run(): \JsonException
                {
                    return new \JsonException('bad');
                }
            }
            php;

        self::assertSame($code, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testLeavesGlobalNamespaceFilesUntouched(): void
    {
        $code = <<<'php'
            <?php

            function demo(): int
            {
                return \count(['a', 'b']);
            }
            php;

        self::assertSame($code, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testLeavesNamespacedFunctionCallsUntouched(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            function demo(): string
            {
                return \App\Utility\slug('x');
            }
            php;

        self::assertSame($code, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testLeavesUnqualifiedCallsOfUnlistedFunctionsUntouched(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            function demo(): string
            {
                return filter_var('abc', FILTER_SANITIZE_STRING);
            }
            php;

        self::assertSame($code, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testReusesAnExistingFunctionImport(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            use function count;

            function demo(): int
            {
                return \count(['a', 'b']);
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;

            use function count;

            function demo(): int
            {
                return count(['a', 'b']);
            }
            php, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testSkipsFunctionsDeclaredInTheSameNamespace(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            function count(array $items): int
            {
                return 42;
            }

            function demo(): int
            {
                return \count(['a', 'b']);
            }
            php;

        self::assertSame($code, $this->fix(new FunctionImportsFixer(), $code));
    }

    public function testSkipsUnqualifiedCallsOfShadowedNames(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            use function App\count;

            function strlen(): int
            {
                return 42;
            }

            function demo(): int
            {
                return count(['a', 'b']) + strlen('x');
            }
            php;

        self::assertSame($code, $this->fix(new FunctionImportsFixer(), $code));
    }

    private function fix(FunctionImportsFixer $fixer, string $code): string
    {
        $tokens = Tokens::fromCode($code);
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig('    ', "\n"));
        $fixer->fix(new SplFileInfo(__FILE__), $tokens);

        return $tokens->generateCode();
    }
}
