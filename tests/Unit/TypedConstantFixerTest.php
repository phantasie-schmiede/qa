<?php

declare(strict_types=1);

use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\TypedConstantFixer;

final class TypedConstantFixerTest extends TestCase
{
    /**
     * @throws ExpectationFailedException
     */
    public function testFixIsIdempotent(): void
    {
        $code = <<<'php'
            <?php

            class Demo
            {
                const LIMIT = 10;
            }
            php;
        $fixer = new TypedConstantFixer();
        $once  = $this->fix($fixer, $code);

        self::assertSame($once, $this->fix($fixer, $once));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testIgnoresAlreadyTypedConstants(): void
    {
        $code = <<<'php'
            <?php

            class Demo
            {
                const int LIMIT = 10;
            }
            php;

        self::assertSame($code, $this->fix(new TypedConstantFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testIgnoresMixedAndUntypeableConstants(): void
    {
        $code = <<<'php'
            <?php

            class Demo
            {
                const EXPRESSION = [1, 2] + [3];
                const ACCESS = [1][0];
                const LONG = array(1, 2);
                const MIXED = 1, OTHER = 'x';
            }
            php;

        self::assertSame($code, $this->fix(new TypedConstantFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testTypesArrayLiteralConstants(): void
    {
        $code = <<<'php'
            <?php

            class Demo
            {
                const LIST = [1, 2];
                const NESTED = [1, ['a', 2]];
                const EMPTY = [];
                const PAIRED = [], OTHER = [1];
            }
            php;

        self::assertSame(<<<'php'
            <?php

            class Demo
            {
                const array LIST = [1, 2];
                const array NESTED = [1, ['a', 2]];
                const array EMPTY = [];
                const array PAIRED = [], OTHER = [1];
            }
            php, $this->fix(new TypedConstantFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testTypesConstantsWithInferredScalarTypes(): void
    {
        $code = <<<'php'
            <?php

            class Demo
            {
                const LIMIT = 10;
                const NAME = 'demo';
                const FLAG = true;
                const NEG = -5;
            }
            php;

        self::assertSame(<<<'php'
            <?php

            class Demo
            {
                const int LIMIT = 10;
                const string NAME = 'demo';
                const bool FLAG = true;
                const int NEG = -5;
            }
            php, $this->fix(new TypedConstantFixer(), $code));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testTypesMultiConstantStatements(): void
    {
        $code = <<<'php'
            <?php

            class Demo
            {
                const A = 1, B = 2;
            }
            php;

        self::assertSame(<<<'php'
            <?php

            class Demo
            {
                const int A = 1, B = 2;
            }
            php, $this->fix(new TypedConstantFixer(), $code));
    }

    private function fix(TypedConstantFixer $fixer, string $code): string
    {
        $tokens = Tokens::fromCode($code);
        $fixer->fix(new SplFileInfo(__FILE__), $tokens);

        return $tokens->generateCode();
    }
}
