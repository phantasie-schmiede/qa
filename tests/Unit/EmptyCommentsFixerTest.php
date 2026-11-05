<?php

declare(strict_types=1);

use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\EmptyCommentsFixer;

final class EmptyCommentsFixerTest extends TestCase
{
    public function testFixIsIdempotent(): void
    {
        $code = <<<'php'
            <?php

            final class Service
            {
                /**
                 */
                public function run(): void
                {
                }
            }
            php;
        $fixer = new EmptyCommentsFixer();
        $once  = $this->fix($fixer, $code);

        self::assertSame($once, $this->fix($fixer, $once));
    }

    public function testKeepsBlockCommentWithContent(): void
    {
        $code = <<<'php'
            <?php

            /*
             * The template code below is not php.
             */
            foo();
            php;

        self::assertSame($code, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testKeepsDocblockWithContent(): void
    {
        $code = <<<'php'
            <?php

            final class Service
            {
                /**
                 * Does work.
                 */
                public function run(): void
                {
                }
            }
            php;

        self::assertSame($code, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testKeepsDocblockWithOnlyTags(): void
    {
        $code = <<<'php'
            <?php

            final class Service
            {
                /**
                 * @param string $name
                 *
                 * @throws \InvalidArgumentException
                 */
                public function run(string $name): void
                {
                }
            }
            php;

        self::assertSame($code, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testKeepsNonEmptySingleLineComments(): void
    {
        $code = <<<'php'
            <?php

            // a remark
            foo(); // trailing
            php;

        self::assertSame($code, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testRemovesEmptyBlockComment(): void
    {
        $code = <<<'php'
            <?php

            /*
             */
            foo();
            php;

        self::assertSame(<<<'php'
            <?php

            foo();
            php, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testRemovesEmptyCommentBeforeCodeOnTheSameLine(): void
    {
        $code = <<<'php'
            <?php

            /* */ $value = 1;
            php;

        self::assertSame(<<<'php'
            <?php

            $value = 1;
            php, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testRemovesEmptyDocblock(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 */
                public function run(): void
                {
                }
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                public function run(): void
                {
                }
            }
            php, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testRemovesEmptySingleLineComment(): void
    {
        $code = <<<'php'
            <?php

            //
            foo();
            php;

        self::assertSame(<<<'php'
            <?php

            foo();
            php, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testRemovesInlineEmptyComment(): void
    {
        $code = <<<'php'
            <?php

            $value = foo( /* */ );
            php;

        self::assertSame(<<<'php'
            <?php

            $value = foo( );
            php, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testRemovesInlineEmptyDocblockAfterCode(): void
    {
        $code = <<<'php'
            <?php

            $value = 1; /** */
            php;

        self::assertSame(<<<'php'
            <?php

            $value = 1;
            php, $this->fix(new EmptyCommentsFixer(), $code));
    }

    public function testRemovesSingleLineEmptyDocblock(): void
    {
        $code = <<<'php'
            <?php

            namespace App;

            final class Service
            {
                /** */
                public function run(): void
                {
                }
            }
            php;

        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                public function run(): void
                {
                }
            }
            php, $this->fix(new EmptyCommentsFixer(), $code));
    }

    private function fix(EmptyCommentsFixer $fixer, string $code): string
    {
        $tokens = Tokens::fromCode($code);
        $fixer->fix(new SplFileInfo(__FILE__), $tokens);

        return $tokens->generateCode();
    }
}
