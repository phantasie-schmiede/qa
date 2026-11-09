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
    public function testOffDirectiveDoesNotProtectNestedArrays(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=off
                'z' => ['d' => 4, 'c' => 3],
                'y' => 1,
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=off
                'z' => ['c' => 3, 'd' => 4],
                'y' => 1,
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testOffDirectiveLeavesArrayUntouched(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=off
                'b' => 2,
                'a' => 1,
            ];
            php;

        self::assertSame($code, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testOffDirectiveWithBlockComment(): void
    {
        $code = <<<'php'
            <?php

            $a = [ /* PSBits/alphabetical_array_keys=off */
                'b' => 2,
                'a' => 1,
            ];
            php;

        self::assertSame($code, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testOffDirectiveWithReasonText(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=off - the field order is part of the sorting
                'b' => 2,
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

    /**
     * @throws ExpectationFailedException
     */
    public function testTrailingCommentDoesNotExclude(): void
    {
        $code = <<<'php'
            <?php

            $a = ['b' => 2, 'a' => 1]; // PSBits/alphabetical_array_keys=off
            php;

        self::assertSame(<<<'php'
            <?php

            $a = ['a' => 1, 'b' => 2]; // PSBits/alphabetical_array_keys=off
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveAllowsIntegerKeys(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                2 => 'b',
                1 => 'a',
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                1 => 'a',
                2 => 'b',
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveIsIdempotent(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'b' => 2,
                'a' => 1,
            ];
            php;
        $fixer = new AlphabeticalArrayKeysFixer();
        $once  = $this->fix($fixer, $code);

        self::assertSame(<<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'a' => 1,
                'b' => 2,
            ];
            php, $once);
        self::assertSame($once, $this->fix($fixer, $once));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveLeavesNestedNonLiteralsAlone(): void
    {
        $code = <<<'php'
            <?php

            $x = 2;

            $a = [
                // PSBits/alphabetical_array_keys=values
                'z' => ['k' => $x],
                'a' => 1,
            ];
            php;

        self::assertSame($code, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveLeavesNonLiteralValuesAlone(): void
    {
        $code = <<<'php'
            <?php

            $b = 2;

            $a = [
                // PSBits/alphabetical_array_keys=values
                'z' => $b,
                'a' => 1,
            ];
            php;

        self::assertSame($code, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveSingleLineArray(): void
    {
        $code = <<<'php'
            <?php

            $a = [ /* PSBits/alphabetical_array_keys=values */ 'b' => 2, 'a' => 1];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [ /* PSBits/alphabetical_array_keys=values */ 'a' => 1, 'b' => 2];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveSortsByValues(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'apple' => 'zebra',
                'zebra' => 'apple',
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'zebra' => 'apple',
                'apple' => 'zebra',
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveSortsMixedTypes(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'text' => 'x',
                'none' => null,
                'yes'  => true,
                'num'  => 2,
                'no'   => false,
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'none' => null,
                'no'   => false,
                'yes'  => true,
                'num'  => 2,
                'text' => 'x',
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveSortsNestedLiteralArrays(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'b' => ['x' => 2],
                'a' => ['x' => 2, 'y' => 1],
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'b' => ['x' => 2],
                'a' => ['x' => 2, 'y' => 1],
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testValuesDirectiveTiesFallBackToKeys(): void
    {
        $code = <<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'zebra' => 2,
                'apple' => 2,
                'mango' => 1,
            ];
            php;

        self::assertSame(<<<'php'
            <?php

            $a = [
                // PSBits/alphabetical_array_keys=values
                'mango' => 1,
                'apple' => 2,
                'zebra' => 2,
            ];
            php, $this->fix(new AlphabeticalArrayKeysFixer(), $code));
    }

    private function fix(AlphabeticalArrayKeysFixer $fixer, string $code): string
    {
        $tokens = Tokens::fromCode($code);
        $fixer->fix(new SplFileInfo(__FILE__), $tokens);

        return $tokens->generateCode();
    }
}
