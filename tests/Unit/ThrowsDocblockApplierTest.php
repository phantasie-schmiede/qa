<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Quality\ThrowsDocblockApplier;

/**
 * Class ThrowsDocblockApplierTest
 */
final class ThrowsDocblockApplierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-applier-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testAddsThrowsToExistingDocblock(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * Does work.
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * Does work.
                 *
                 * @throws NewError
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    public function testCollidingShortNameGetsDescriptiveAlias(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            use App\Local\InvalidArgumentException;

            final class Service
            {
                public function run(): void
                {
                    throw new \Symfony\Component\Console\Exception\InvalidArgumentException('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \Symfony\Component\Console\Exception\InvalidArgumentException, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws ConsoleInvalidArgumentException', $content);
        self::assertStringContainsString(
            'use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;',
            $content,
        );
        self::assertStringNotContainsString('@throws InvalidArgumentException', $content);
    }

    public function testCreatesDocblock(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 7,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws NewError
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    public function testCreatesDocblockForEnumMethod(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            enum Suit
            {
                case HEARTS;

                public function label(): string
                {
                    if (self::HEARTS !== $this) {
                        throw new \InvalidArgumentException('x');
                    }

                    return 'Hearts';
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Suit::label() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws InvalidArgumentException', $content);
        self::assertStringContainsString('use InvalidArgumentException;', $content);
    }

    public function testCreatesDocblockForGlobalFunction(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            function helper(): void
            {
                throw new \RuntimeException('x');
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 5,
                'message' => 'Function App\helper() @throws do not match the thrown exceptions: expected \RuntimeException, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            use RuntimeException;

            /**
             * @throws RuntimeException
             */
            function helper(): void
            {
                throw new \RuntimeException('x');
            }
            php, file_get_contents($file));
    }

    public function testExpandsSingleLineDocblockWhenAddingThrows(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Helper
            {
                /** @return array<string, string> */
                public function render(): array
                {
                    return [];
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 8,
                'message' => 'Method App\Helper::render() @throws do not match the thrown exceptions: expected \ValueError, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringNotContainsString('/** @return', $content);
        self::assertStringContainsString("     * @return array<string, string>\n", $content);
        self::assertStringContainsString("     * @throws ValueError\n", $content);
        self::assertStringContainsString("use ValueError;\n", $content);
    }

    public function testIgnoresClassDeclarationsInsideHeredocs(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Generator
            {
                public function render(): string
                {
                    $template = <<<'TPL'
                        <?php

                        class FakeModel
                        {
                        }
                        TPL;

                    return $template;
                }

                public function build(): string
                {
                    if (null === $this->name) {
                        throw new \InvalidArgumentException('no name');
                    }

                    return $this->name;
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 20,
                'message' => 'Method App\Generator::build() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws InvalidArgumentException', $content);
        self::assertStringContainsString('use InvalidArgumentException;', $content);
    }

    public function testIgnoresClassDeclarationsInsideMultiLineStrings(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            class FixtureProvider
            {
                public static function source(): array
                {
                    yield [
                        '<?php
            class Fake
            {
            }
            ',
                    ];
                }

                public function build(): string
                {
                    if (null === $this->name) {
                        throw new \InvalidArgumentException('no name');
                    }

                    return $this->name;
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 18,
                'message' => 'Method App\FixtureProvider::build() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws InvalidArgumentException', $content);
        self::assertStringContainsString('use InvalidArgumentException;', $content);
    }

    public function testImportsExceptionFromAnotherNamespace(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                public function run(): void
                {
                    throw new \Other\Error\SomethingError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 7,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \Other\Error\SomethingError, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            use Other\Error\SomethingError;

            final class Service
            {
                /**
                 * @throws SomethingError
                 */
                public function run(): void
                {
                    throw new \Other\Error\SomethingError('x');
                }
            }
            php, file_get_contents($file));
    }

    public function testKeepsOtherTagsOfASingleLineDocblockWhenRemovingThrows(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Helper
            {
                /** @return array<string, string> @throws \App\Old */
                public function render(): array
                {
                    return [];
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 8,
                'message' => 'Method App\Helper::render() @throws do not match the thrown exceptions: expected none, declared \App\Old.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringNotContainsString('@throws', $content);
        self::assertStringContainsString(' * @return array<string, string>', $content);
    }

    public function testMergesReportsForTheSameTraitMethod(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            trait Filler
            {
                /**
                 * @throws \ReflectionException
                 */
                public function fillProperties(): void
                {
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Consumer\Alpha::fillProperties() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared \ReflectionException.',
            ],
            [
                'line'    => 10,
                'message' => 'Method App\Consumer\Beta::fillProperties() @throws do not match the thrown exceptions: expected \InvalidArgumentException, \RuntimeException, declared \ReflectionException.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertSame(1, substr_count($content, '@throws InvalidArgumentException'));
        self::assertSame(1, substr_count($content, '@throws RuntimeException'));
        self::assertStringNotContainsString('@throws ReflectionException', $content);
        self::assertSame(2, substr_count($content, 'use '));
    }

    public function testRemovesDocblockThatOnlyCarriedThrows(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws \App\Old
                 */
                public function run(): void
                {
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected none, declared \App\Old.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertStringNotContainsString('/**', file_get_contents($file));
        self::assertStringContainsString(
            "    public function run(): void\n",
            file_get_contents($file),
        );
    }

    public function testRemovesSingleLineDocblockThatOnlyCarriesThrows(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Helper
            {
                /** @throws \App\Old */
                public function render(): array
                {
                    return [];
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 8,
                'message' => 'Method App\Helper::render() @throws do not match the thrown exceptions: expected none, declared \App\Old.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertStringNotContainsString('@throws', file_get_contents($file));
        self::assertStringNotContainsString('/**', file_get_contents($file));
    }

    public function testRemovesStaleThrowsAndKeepsDocblockContent(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * Does work.
                 *
                 * @throws \App\Old
                 */
                public function run(): void
                {
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 12,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected none, declared \App\Old.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * Does work.
                 */
                public function run(): void
                {
                }
            }
            php, file_get_contents($file));
    }

    public function testReplacesWrongThrows(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * Does work.
                 *
                 * @throws \App\Old
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 12,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, declared \App\Old.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertStringContainsString('@throws NewError', file_get_contents($file));
        self::assertStringNotContainsString('@throws Old', file_get_contents($file));
    }

    public function testResolvesTraitMethodReportedForTheUsingClass(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            trait Filler
            {
                /**
                 * @throws \ReflectionException
                 */
                public function fillProperties(): void
                {
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Consumer\Element::fillProperties() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared \ReflectionException.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws InvalidArgumentException', $content);
        self::assertStringNotContainsString('@throws ReflectionException', $content);
        self::assertStringContainsString('use InvalidArgumentException;', $content);
    }

    public function testReusesAnExistingAliasedImport(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            use Other\Error\SomethingError as Err;

            final class Service
            {
                public function run(): void
                {
                    throw new Err('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \Other\Error\SomethingError, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws Err', $content);
        self::assertStringNotContainsString('@throws SomethingError', $content);
        self::assertSame(1, substr_count($content, 'use Other\Error\SomethingError as Err;'));
    }

    public function testReusesAnExistingImport(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            use Other\Error\SomethingError;

            final class Service
            {
                public function run(): void
                {
                    throw new SomethingError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 10,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \Other\Error\SomethingError, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws SomethingError', $content);
        self::assertSame(1, substr_count($content, 'use Other\Error\SomethingError;'));
    }

    public function testSkipsUnrecognizedMessages(): void
    {
        $code = <<<php
            <?php

            namespace App;

            final class Service
            {
                public function run(): void
                {
                }
            }
            php;
        $file = $this->fixture($code);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 7,
                'message' => 'Something completely different happened.',
            ],
        ]);

        self::assertSame(0, $result['fixed']);
        self::assertCount(1, $result['skipped']);
        self::assertStringEndsWith(': unrecognized message', $result['skipped'][0]);
        self::assertSame($code, file_get_contents($file));
    }

    private function fixture(string $code): string
    {
        $file = $this->root . '/Fixture.php';
        self::assertTrue(false !== file_put_contents($file, $code));

        return $file;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            is_link($itemPath) ? unlink($itemPath) : ($item->isDir() ? rmdir($itemPath) : unlink($itemPath));
        }

        rmdir($path);
    }
}
