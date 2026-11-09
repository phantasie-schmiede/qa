<?php

declare(strict_types=1);

use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\InvalidArgumentException as FrameworkInvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Quality\ThrowsDocblockApplier;
use Random\RandomException;

final class ThrowsDocblockApplierTest extends TestCase
{
    private string $root;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-applier-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testAdditiveModeAddsMissingLinesAndKeepsExistingOnes(): void
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
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, \App\Old, declared \App\Old.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertSame(1, substr_count($content, '@throws NewError'));
        self::assertSame(1, substr_count($content, '@throws Old'));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testAdditiveModeKeepsTheMarkersOfTheExistingLines(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws \App\Old // t3:13
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, \App\Old, declared \App\Old.',
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
                 * @throws Old // t3:13
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testAddsThrowsDirectlyAfterTheOtherTagsWithoutABlankLine(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @return array<string, mixed>
                 */
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
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
                 * @return array<string, mixed>
                 * @throws NewError
                 */
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testAddsThrowsToAnEmptyDocblock(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 */
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
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
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testDropsALeadingBlankStarLine(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 *
                 * @return array<string, mixed>
                 */
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
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
                 * @return array<string, mixed>
                 * @throws NewError
                 */
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testDropsBlankStarLinesBetweenTheTags(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @return array<string, mixed>
                 *
                 * @throws \App\Old
                 */
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, declared \App\Old.',
            ],
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @return array<string, mixed>
                 * @throws NewError
                 */
                public function run(): array
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testExactModeCreatesDocblockWithMarkers(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                public function run(): void
                {
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 8,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError // t3:13, declared none.',
            ],
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertStringContainsString(
            "    /**\n     * @throws NewError // t3:13\n     */\n",
            file_get_contents($file),
        );
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testExactModeLeavesOrphanedImports(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            use Doctrine\Stale;

            final class Service
            {
                /**
                 * @throws Stale
                 */
                public function run(): void
                {
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 11,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \RuntimeException, declared \Doctrine\Stale.',
            ],
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws RuntimeException', $content);
        self::assertStringNotContainsString('@throws Stale', $content);
        self::assertStringContainsString('use Doctrine\Stale;', $content);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testExactModeWritesTheMarkersOfTheMessage(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws \App\Stale
                 */
                public function run(): void
                {
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError // t3:14, \App\Shared, declared \App\Stale.',
            ],
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws NewError // t3:14
                 * @throws Shared
                 */
                public function run(): void
                {
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        self::assertSame(<<<'php'
            <?php

            namespace App;

            use ValueError;

            final class Helper
            {
                /**
                 * @return array<string, string>
                 * @throws ValueError
                 */
                public function render(): array
                {
                    return [];
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringNotContainsString('@throws', $content);
        self::assertStringContainsString(' * @return array<string, string>', $content);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testKeepsScanningAfterAHeredocWithATrailingExpression(): void
    {
        /*
         * The heredoc in the fixture terminates with a trailing expression
         * (the form the fixer test fixtures use: "php, $this->fix(...)");
         * the scanner must not stay inside the heredoc after that line.
         */
        $file = $this->fixture(<<<'tpl'
            <?php

            namespace App;

            final class Generator
            {
                public function render(): void
                {
                    self::assertSame(
                        <<<'php'
                            <?php

                            return [];
                            php, $this->normalize(),
                    );
                }

                public function build(): string
                {
                    if (null === $this->name) {
                        throw new \InvalidArgumentException('no name');
                    }

                    return $this->name;
                }
            }
            tpl);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 18,
                'message' => 'Method App\Generator::build() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared none.',
            ],
        ]);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws InvalidArgumentException', $content);
        self::assertStringContainsString('use InvalidArgumentException;', $content);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testKeepsTheParameterOrderOfTheParamLines(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @param string $b
                 * @param int $a
                 * @return string
                 */
                public function run(string $b, int $a): string
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
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
                 * @param string $b
                 * @param int $a
                 * @return string
                 * @throws NewError
                 */
                public function run(string $b, int $a): string
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testKeepsThePhpStanThrowsVariant(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @phpstan-throws \App\Old
                 * @return string
                 */
                public function run(): string
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, declared \App\Old.',
            ],
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @return string
                 * @phpstan-throws NewError
                 */
                public function run(): string
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        self::assertSame(1, substr_count($content, '@throws ReflectionException'));
        self::assertSame(1, substr_count($content, '@throws RuntimeException'));
        self::assertSame(3, substr_count($content, 'use '));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testParseMessageReadsNoneAsAnEmptyList(): void
    {
        self::assertSame([
            'class'    => 'App\Service',
            'declared' => [],
            'expected' => [],
            'name'     => 'run',
        ], ThrowsDocblockApplier::parseMessage('Method App\Service::run() @throws do not match the thrown exceptions: expected none, declared none.'));
        self::assertNull(ThrowsDocblockApplier::parseMessage('some foreign message'));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testParseMessageReadsTheMarkersOfTheNames(): void
    {
        self::assertSame([
            'class'    => 'App\Service',
            'declared' => ['App\Old'],
            'expected' => [
                ['marker' => null, 'name' => 'App\NewError'],
                ['marker' => '14', 'name' => 'App\Other'],
            ],
            'name'     => 'run',
        ], ThrowsDocblockApplier::parseMessage('Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, \App\Other // t3:14, declared \App\Old.'));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertStringNotContainsString('/**', file_get_contents($file));
        self::assertStringContainsString(
            "    public function run(): void\n",
            file_get_contents($file),
        );
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertStringNotContainsString('@throws', file_get_contents($file));
        self::assertStringNotContainsString('/**', file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        ], true);

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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertStringContainsString('@throws NewError', file_get_contents($file));
        self::assertStringNotContainsString('@throws Old', file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('@throws InvalidArgumentException', $content);
        self::assertStringNotContainsString('@throws ReflectionException', $content);
        self::assertStringContainsString('use InvalidArgumentException;', $content);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws FrameworkInvalidArgumentException
     * @throws RuntimeException
     * @throws ValueError
     */
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

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testSortsTheTagsAlphabetically(): void
    {
        $file = $this->fixture(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws \App\Old
                 * @param int $a
                 * @return string
                 */
                public function run(int $a): string
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        $result = (new ThrowsDocblockApplier())->apply($file, [
            [
                'line'    => 9,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\NewError, declared \App\Old.',
            ],
        ], true);

        self::assertSame(['fixed' => 1, 'skipped' => []], $result);
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @param int $a
                 * @return string
                 * @throws NewError
                 */
                public function run(int $a): string
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     */
    private function fixture(string $code): string
    {
        $file = $this->root . '/Fixture.php';
        self::assertTrue(false !== file_put_contents($file, $code));

        return $file;
    }

    /**
     * @throws UnexpectedValueException
     */
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
