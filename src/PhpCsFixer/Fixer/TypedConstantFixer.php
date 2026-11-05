<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Qa\PhpCsFixer\Fixer;

use LogicException;
use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use SplFileInfo;

use function count;
use function in_array;
use function strlen;

use const T_CONST;
use const T_DNUMBER;
use const T_FINAL;
use const T_LNUMBER;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_STRING;
use const T_WHITESPACE;

/**
 * Adds a type declaration to class constants whose value is a scalar
 * literal the type can be inferred from (int, float, string, bool, null).
 *
 * Constants with expressions, array values or any other value that does not
 * reveal the type unambiguously are left untouched. A statement with
 * multiple constants is only typed when all of them share the same type.
 *
 * The fixer works on the token list: the TokensAnalyzer locates the
 * constant declarations of all class-like blocks by their token indices,
 * and a statement is split at its top-level = and , tokens with a plain
 * nesting-depth counter, so operators inside a nested expression are not
 * mistaken for separators. The inferred type is inserted with insertAt(),
 * which shifts the indices of all tokens behind the insertion - the
 * fixer therefore types one constant at a time and rescans until a pass
 * changes nothing.
 */
final class TypedConstantFixer extends AbstractFixer
{
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Adds a type declaration to class constants with scalar literal values.',
            [
                new CodeSample(
                    "<?php\nclass Demo\n{\n    const LIMIT = 10;\n    const NAME = 'demo';\n}\n"
                ),
            ],
        );
    }

    public function getName(): string
    {
        return 'PSBits/typed_constant';
    }

    /**
     * {@inheritdoc}
     *
     * Must run before ClassBlockSeparationFixer.
     */
    public function getPriority(): int
    {
        return 40;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_CONST);
    }

    /**
     * Types at most one constant per round: the class elements are
     * re-located after every insertion, because insertAt() shifts all
     * token indices behind it.
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $iterations = 0;

        do {
            $fixed    = false;
            $analyzer = new TokensAnalyzer($tokens);

            foreach ($analyzer->getClassyElements() as $index => $element) {
                if ('const' !== $element['type']) {
                    continue;
                }

                $statement = $this->findConstantStatement($tokens, $index);

                if (null === $statement || $this->isAlreadyTyped($tokens, $statement['constIndex'])) {
                    continue;
                }

                $type = $this->inferStatementType($tokens, $statement);

                if (null === $type) {
                    continue;
                }

                // the type is inserted before the first constant's name
                $nameIndex = $tokens->getNextMeaningfulToken($statement['constIndex']);

                if (null === $nameIndex) {
                    throw new LogicException('TypedConstantFixer: malformed const statement.');
                }

                $tokens->insertAt($nameIndex, [
                    new Token([T_STRING, $type]),
                    new Token([T_WHITESPACE, ' ']),
                ]);
                $fixed = true;

                break; // token positions changed, rescan
            }

            if (1000 < ++$iterations) {
                throw new LogicException('TypedConstantFixer did not converge.');
            }
        } while ($fixed);
    }

    /**
     * Finds the full statement a const declaration belongs to: its start
     * (walking back over the visibility and final modifiers), the
     * terminating semicolon and the token range of every value. The =
     * and , tokens are only collected at nesting depth 0, so operators
     * inside a nested expression (arrays, calls, ...) are not mistaken
     * for statement separators.
     *
     * @return null|array{
     *     start: int,
     *     constIndex: int,
     *     end: int,
     *     values: list<array{start: int, end: int}>
     * }
     */
    private function findConstantStatement(Tokens $tokens, int $constIndex): ?array
    {
        $end = null;

        for ($i = $constIndex; $i < $tokens->count(); ++$i) {
            if ($tokens[$i]->equals(';')) {
                $end = $i;

                break;
            }
        }

        if (null === $end) {
            return null;
        }

        $equalsIndices = [];
        $commaIndices  = [];
        $depth         = 0;

        for ($i = $constIndex + 1; $i < $end; ++$i) {
            $token = $tokens[$i];

            if ($token->equals('(') || $token->equals('[') || $token->equals('{')) {
                ++$depth;

                continue;
            }

            if ($token->equals(')') || $token->equals(']') || $token->equals('}')) {
                --$depth;

                continue;
            }

            if (0 !== $depth) {
                continue;
            }

            if ($token->equals('=')) {
                $equalsIndices[] = $i;

                continue;
            }

            if ($token->equals(',')) {
                $commaIndices[] = $i;
            }
        }

        if ([] === $equalsIndices) {
            return null;
        }

        $values = [];

        foreach ($equalsIndices as $equals) {
            $valueStart = $tokens->getNextMeaningfulToken($equals);

            if (null === $valueStart) {
                return null;
            }

            $limit = $end;

            foreach ($commaIndices as $comma) {
                if ($comma > $equals) {
                    $limit = $comma;

                    break;
                }
            }

            $valueEnd = $tokens->getPrevMeaningfulToken($limit);

            if (null === $valueEnd || $valueEnd < $valueStart) {
                return null;
            }

            $values[] = ['end' => $valueEnd, 'start' => $valueStart];
        }

        $start = $constIndex;

        while (true) {
            $prev = $tokens->getPrevMeaningfulToken($start);

            if (null === $prev || !$tokens[$prev]->isGivenKind([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL])) {
                break;
            }

            $start = $prev;
        }

        return [
            'constIndex' => $constIndex,
            'end'        => $end,
            'start'      => $start,
            'values'     => $values,
        ];
    }

    /**
     * The type of a statement that declares several constants at once is
     * only usable when every value infers to the same type.
     *
     * @param array{start: int, end: int, constants: list<int>} $statement
     */
    private function inferStatementType(Tokens $tokens, array $statement): ?string
    {
        $type = null;

        foreach ($statement['values'] as $value) {
            $valueType = $this->inferType($tokens, $value['start'], $value['end']);

            if (null === $valueType || (null !== $type && $type !== $valueType)) {
                return null;
            }

            $type = $valueType;
        }

        return $type;
    }

    /**
     * Infers the type from the literal tokens of one value: int/float
     * number tokens, string literals (matched by content, the token kind
     * is PHP-version dependent), and the true/false/null keywords, which
     * the tokenizer delivers as T_STRING tokens; a negative number is
     * the two-token case of - plus a number token.
     */
    private function inferType(Tokens $tokens, int $valueStart, int $valueEnd): ?string
    {
        $valueTokens = [];

        for ($i = $valueStart; $i <= $valueEnd; ++$i) {
            $token = $tokens[$i];

            if (!$token->isGivenKind(T_WHITESPACE)) {
                $valueTokens[] = $token;
            }
        }

        $count = count($valueTokens);

        if (1 === $count) {
            $token = $valueTokens[0];

            if ($token->isGivenKind(T_LNUMBER)) {
                return 'int';
            }

            if ($token->isGivenKind(T_DNUMBER)) {
                return 'float';
            }

            if ($this->isStringLiteralToken($token)) {
                return 'string';
            }

            if ($token->isGivenKind(T_STRING)) {
                if (in_array($token->getContent(), ['true', 'false'], true)) {
                    return 'bool';
                }

                if ('null' === $token->getContent()) {
                    return 'null';
                }
            }

            return null;
        }

        if (2 === $count && '-' === $valueTokens[0]->getContent()) {
            if ($valueTokens[1]->isGivenKind(T_LNUMBER)) {
                return 'int';
            }

            if ($valueTokens[1]->isGivenKind(T_DNUMBER)) {
                return 'float';
            }
        }

        return null;
    }

    /**
     * const NAME = ... has one meaningful token between the const keyword
     * and the =; const TYPE NAME = ... has two.
     */
    private function isAlreadyTyped(Tokens $tokens, int $constIndex): bool
    {
        $equals = $tokens->getNextTokenOfKind($constIndex, ['=']);

        if (null === $equals) {
            return false;
        }

        $nameIndex = $tokens->getNextMeaningfulToken($constIndex);
        $afterName = null === $nameIndex ? null : $tokens->getNextMeaningfulToken($nameIndex);

        return null !== $afterName && $afterName < $equals;
    }

    /**
     * A string literal is the single token whose content is a quoted string.
     * The token kind of string literals differs between PHP versions, so
     * the content is the reliable marker.
     */
    private function isStringLiteralToken(Token $token): bool
    {
        $content = $token->getContent();

        return 1 < strlen($content) && (bool) preg_match('/^([\'"]).*\\1$/s', $content);
    }
}
