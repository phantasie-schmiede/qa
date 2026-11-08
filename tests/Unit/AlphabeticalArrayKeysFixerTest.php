<?php

declare(strict_types=1);

use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\AlphabeticalArrayKeysFixer;

final class AlphabeticalArrayKeysFixerTest extends TestCase
{
    /**
     * @throws ExpectationFailedException
     */
    public function testFixIsIdempotent(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                'b' => 2,
                'a' => 1,
            ];
            php;
        $fixer = new AlphabeticalArrayKeysFixer();
        $once  = $this->fix($fixer, $code);

        self::assertSame($once, $this->fix($fixer, $once));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testIgnoresIntegerKeys(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                2 => 'b',
                1 => 'a',
            ];
            php;

        self::assertSame($code, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testIgnoresValuesWithSideEffects(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                'b' => static fn (): int => 2,
                'a' => 1,
            ];
            php;

        self::assertSame($code, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSortsMultilineArrays(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                'b' => 2,
                'a' => 1,
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                'a' => 1,
                'b' => 2,
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSortsNestedArrays(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                'z' => ['d' => 4, 'c' => 3],
                'y' => 1,
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                'y' => 1,
                'z' => ['c' => 3, 'd' => 4],
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSortsSingleLineArrays(): void
    {
        $code = <<<'php'
            <?php

            $a = ['b' => 2, 'a' => 1];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = ['a' => 1, 'b' => 2];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    private function fix(AlphabeticalArrayKeysFixer $fixer, string $code): string
    {
        $tokens = Tokens::fromCode($code);
        $fixer->fix(new SplFileInfo(__FILE__), $tokens);

        return $tokens->generateCode();
    }
}
