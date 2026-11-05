<?php

declare(strict_types=1);

use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\BlockCommentsFixer;

final class BlockCommentsFixerTest extends TestCase
{
    public function testConsecutiveLineCommentsBecomeSingleBlock(): void
    {
        $code = <<<'php'
            <?php

            // line one
            // line two
            foo();
            php;

        self::assertSame(<<<'php'
            <?php

            /*
             * line one
             * line two
             */
            foo();
            php, $this->fix(new BlockCommentsFixer(), $code));
    }

    public function testFixIsIdempotent(): void
    {
        $code = <<<'php'
            <?php

            // a
            // b
            // c
            foo();
            php;
        $fixer = new BlockCommentsFixer();
        $once  = $this->fix($fixer, $code);

        self::assertSame($once, $this->fix($fixer, $once));
    }

    public function testHashCommentsBecomeSingleBlock(): void
    {
        $code = <<<'php'
            <?php

            class Demo
            {
                # a
                # b
                #
                public function x(): void
                {
                }
            }
            php;

        self::assertSame(<<<'php'
            <?php

            class Demo
            {
                /*
                 * a
                 * b
                 *
                 */
                public function x(): void
                {
                }
            }
            php, $this->fix(new BlockCommentsFixer(), $code));
    }

    public function testSingleAndTrailingCommentsStayUntouched(): void
    {
        $code = <<<'php'
            <?php

            // alone

            bar(); // trailing
            php;

        self::assertSame($code, $this->fix(new BlockCommentsFixer(), $code));
    }

    private function fix(BlockCommentsFixer $fixer, string $code): string
    {
        $tokens = Tokens::fromCode($code);
        $fixer->fix(new SplFileInfo(__FILE__), $tokens);

        return $tokens->generateCode();
    }
}
