<?php

declare(strict_types=1);

use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\ClassPhpdocCleanupFixer;

final class ClassPhpdocCleanupFixerTest extends TestCase
{
    public function testFixIsIdempotent(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Class Demo
             *
             * @package App
             */
            class Demo
            {
            }

            /**
             * A real description.
             *
             * @extends RepositoryInterface<Demo>
             * @package App
             */
            final class Repository
            {
            }
            php;
        $fixer = new ClassPhpdocCleanupFixer();
        $once  = $this->fix($fixer, $code);

        self::assertSame($once, $this->fix($fixer, $once));
    }

    public function testIgnoresDocblocksOfNonClassDeclarations(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Demo
             *
             * @package App
             */
            function demo(): void
            {
            }

            final class Wrapper
            {
                /**
                 * Demo
                 *
                 * @package App
                 */
                public function run(): void
                {
                }
            }
            php;

        self::assertSame($code, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testKeepsAMismatchedNameLine(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Class Other
             *
             * @package App
             */
            class Demo
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            /**
             * Class Other
             */
            class Demo
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testKeepsValuableContent(): void
    {
        $code = <<<'php'
            <?php

            /**
             * The demo class.
             *
             * @extends RepositoryInterface<Demo>
             * @template T
             */
            class Demo
            {
            }
            php;

        self::assertSame($code, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testRemovesBoilerplateDocblocksCompletely(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Class One
             *
             * @package App
             */
            class One
            {
            }

            /**
             * Two
             *
             * @package App
             */
            class Two
            {
            }

            /**
             * @package App
             */
            class Three
            {
            }

            /**
             * Four
             */
            class Four
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            class One
            {
            }

            class Two
            {
            }

            class Three
            {
            }

            class Four
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testRemovesOnlyThePackageTag(): void
    {
        $code = <<<'php'
            <?php

            /**
             * The demo class.
             *
             * @package App
             */
            class Demo
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            /**
             * The demo class.
             */
            class Demo
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testRemovesSingleLineDocblocks(): void
    {
        $code = <<<'php'
            <?php

            /** Class One */
            class One
            {
            }

            /** @package App */
            class Two
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            class One
            {
            }

            class Two
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testRemovesTheNameLineAndKeepsTheDescription(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Class ListenerProvider
             *
             * A PSR-14 listener provider with listeners registered at runtime, for use with the core
             * EventDispatcher in unit and functional tests.
             */
            class ListenerProvider
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            /**
             * A PSR-14 listener provider with listeners registered at runtime, for use with the core
             * EventDispatcher in unit and functional tests.
             */
            class ListenerProvider
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testRemovesTheNameLineAndPackageTagAndKeepsTheOtherTags(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Class SongRepository
             *
             * @extends RepositoryInterface<Song>
             * @package App\Domain\Repository
             */
            final class SongRepository
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            /**
             * @extends RepositoryInterface<Song>
             */
            final class SongRepository
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testWorksOnInterfacesTraitsAndEnums(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Class One
             *
             * @package App
             */
            interface One
            {
            }

            /**
             * Class Two
             *
             * @package App
             */
            trait Two
            {
            }

            /**
             * Class Three
             *
             * @package App
             */
            enum Three
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            interface One
            {
            }

            trait Two
            {
            }

            enum Three
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    public function testWorksWithModifiers(): void
    {
        $code = <<<'php'
            <?php

            /**
             * Class One
             *
             * @package App
             */
            final class One
            {
            }

            /**
             * Class Two
             *
             * @package App
             */
            abstract class Two
            {
            }
            php;

        self::assertSame(<<<'php'
            <?php

            final class One
            {
            }

            abstract class Two
            {
            }
            php, $this->fix(new ClassPhpdocCleanupFixer(), $code));
    }

    private function fix(ClassPhpdocCleanupFixer $fixer, string $code): string
    {
        $tokens = Tokens::fromCode($code);
        $fixer->fix(new SplFileInfo(__FILE__), $tokens);

        return $tokens->generateCode();
    }
}
