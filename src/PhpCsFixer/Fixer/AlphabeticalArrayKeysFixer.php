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
use PhpCsFixer\Preg;
use PhpCsFixer\PregException;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;
use ValueError;

use function array_merge;
use function count;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function str_contains;
use function strlen;
use function strrpos;
use function substr;
use function usort;

use const T_CLASS;
use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DNUMBER;
use const T_DOC_COMMENT;
use const T_DOUBLE_ARROW;
use const T_LNUMBER;
use const T_NS_SEPARATOR;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_PAAMAYIM_NEKUDOTAYIM;
use const T_STRING;
use const T_VARIABLE;
use const T_WHITESPACE;

/**
 * Sorts the keys of literal arrays alphabetically.
 *
 * Only arrays where every key is a string literal are touched. Arrays with
 * integer keys, dynamic keys, spread items or values that may have side
 * effects (function or method calls, instantiation, ...) are left as they
 * are, so no evaluation order can ever change.
 *
 * A comment between the opening bracket and the first item of an array
 * carries a directive for the fixer:
 *
 * - "PSBits/alphabetical_array_keys=off" excludes the array from the fix
 *   entirely - for cases where the item order is part of the semantics,
 *   like sorting configurations. The directive only applies to the marked
 *   array, nested arrays keep the default sort.
 * - "PSBits/alphabetical_array_keys=values" sorts the items by their
 *   values instead of their keys; the keys may then be string or integer
 *   literals and every value must be a statically evaluable literal
 *   (numbers, strings, true, false, null, nested literal arrays).
 *
 * Like every php-cs-fixer fixer, this class works on the token list: the
 * source file is split into a flat, zero-indexed list of Token objects
 * (type + content), no parser tree - whitespace and comments are tokens,
 * too. Array literals are found by their opening bracket, which the
 * tokenizer marks with the custom token CT::T_ARRAY_SQUARE_BRACE_OPEN
 * (a plain [ could also be an index or a list). The tokenizer keeps
 * track of the matching brackets, so findBlockEnd() jumps over any
 * nested expression in a single call. Rewriting one array shifts the
 * indices of all tokens behind it, so the fixer stops after each fix and
 * rescans the list, with an iteration cap as a convergence guard.
 *
 * @phpstan-type _Item array{first: int, key: string|int, last: int, value: mixed}
 */
final class AlphabeticalArrayKeysFixer extends AbstractFixer
{
    /**
     * @internal
     */
    public const string DIRECTIVE_KEYS = 'keys';

    /**
     * @internal
     */
    public const string DIRECTIVE_OFF = 'off';

    /**
     * @internal
     */
    public const string DIRECTIVE_VALUES = 'values';

    /**
     * Order of two value-mode items: the literal values in the natural
     * value order (null, booleans, numbers, strings, arrays; strings
     * case-insensitive, numbers and arrays in PHP's native order), the
     * keys as tie-break for equal values - string keys
     * case-insensitive, integer keys numerical, the remaining key
     * mixtures in PHP's native order.
     *
     * @param _Item $a
     * @param _Item $b
     */
    private static function compareItemsByValue(array $a, array $b): int
    {
        $valueA = $a['value'];
        $valueB = $b['value'];

        $rankA = self::valueRank($valueA);
        $rankB = self::valueRank($valueB);

        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        $comparison = is_string($valueA) ? strcasecmp($valueA, $valueB) : $valueA <=> $valueB;

        if (0 !== $comparison) {
            return $comparison;
        }

        $keyA = $a['key'];
        $keyB = $b['key'];

        if (is_int($keyA) && is_int($keyB)) {
            return $keyA <=> $keyB;
        }

        if (is_string($keyA) && is_string($keyB)) {
            return strcasecmp($keyA, $keyB);
        }

        return $keyA <=> $keyB;
    }

    /**
     * The rank of a literal value in the natural value order:
     * null, booleans, numbers, strings, arrays.
     */
    private static function valueRank(mixed $value): int
    {
        if (null === $value) {
            return 0;
        }

        if (is_bool($value)) {
            return 1;
        }

        if (is_int($value) || is_float($value)) {
            return 2;
        }

        if (is_string($value)) {
            return 3;
        }

        return 4;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Sorts the keys of literal arrays (all string literal keys, side-effect-free values) alphabetically; a directive comment in an array may exclude it from the fix or switch the sort to the values.',
            [
                new CodeSample(
                    "<?php\n\$a = [\n    'b' => 2,\n    'a' => 1,\n];\n"
                ),
                new CodeSample(
                    "<?php\n\$a = [\n    // PSBits/alphabetical_array_keys=off\n    'b' => 2,\n    'a' => 1,\n];\n"
                ),
                new CodeSample(
                    "<?php\n\$a = [\n    // PSBits/alphabetical_array_keys=values\n    'b' => 2,\n    'a' => 1,\n];\n"
                ),
            ],
        );
    }

    public function getName(): string
    {
        return 'PSBits/alphabetical_array_keys';
    }

    /**
     * {@inheritdoc}
     *
     * Must run after OrderedClassElementsFixer.
     */
    public function getPriority(): int
    {
        return 30;
    }

    /**
     * Cheap pre-check on the token kinds: without => there are no keyed
     * arrays to sort.
     */
    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_DOUBLE_ARROW);
    }

    /**
     * Fixes at most one array per round: the [open, close] pairs of the
     * other arrays are stale after a rewrite, so they are re-collected
     * until a round changes nothing.
     *
     * @throws LogicException
     * @throws PregException
     * @throws ValueError
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $iterations = 0;

        do {
            $fixed = false;

            foreach ($this->findArrays($tokens) as [$open, $close]) {
                if ($this->fixArray($tokens, $open, $close)) {
                    $fixed = true;

                    break; // token positions changed, rescan
                }
            }

            if (1000 < ++$iterations) {
                throw new LogicException('AlphabeticalArrayKeysFixer did not converge.');
            }
        } while ($fixed);
    }

    /**
     * Describes one array item as key plus token range, or null when the
     * item is not a key => value pair that this fixer handles, i.e. when
     * it must not be moved. In key mode the key is a string literal and
     * the value must be side effect free; in value mode the key may be a
     * string or integer literal and the value a statically evaluable
     * literal, which is then carried along for the comparison.
     *
     * @return null|_Item
     * @throws PregException
     */
    private function describeItem(Tokens $tokens, int $start, int $end, bool $values = false): ?array
    {
        $first = $this->skipWhitespace($tokens, $start, $end, true);
        $last  = $this->skipWhitespace($tokens, $end, $start, false);

        if ($first > $last) {
            return null;
        }

        // every item must be a literal key => value
        $key = $this->literalKey($tokens, $first, $values);

        if (null === $key) {
            return null;
        }

        $arrow = $tokens->getNextMeaningfulToken($first);

        if (null === $arrow || $arrow > $last || !$tokens[$arrow]->isGivenKind(T_DOUBLE_ARROW)) {
            return null;
        }

        $valueStart = $tokens->getNextMeaningfulToken($arrow);

        if (null === $valueStart || $valueStart > $last) {
            return null;
        }

        if (true === $values) {
            $evaluation = $this->evaluateLiteralValue($tokens, $valueStart, $last);

            if (false === $evaluation['evaluable']) {
                return null;
            }

            $value = $evaluation['value'];
        } else {
            if (!$this->isSideEffectFreeValue($tokens, $valueStart, $last)) {
                return null;
            }

            $value = null;
        }

        return [
            'first' => $first,
            'key'   => $key,
            'last'  => $last,
            'value' => $value,
        ];
    }

    /**
     * Evaluates a value that must be a literal: scalar literals
     * (numbers, strings, true, false, null), optionally signed numbers,
     * grouping parentheses and nested array literals of the same.
     * Anything else (variables, constants, calls, ...) is not statically
     * evaluable.
     *
     * @return array{evaluable: bool, value: mixed}
     * @throws PregException
     */
    private function evaluateLiteralValue(Tokens $tokens, int $start, int $end): array
    {
        $start = $this->skipWhitespace($tokens, $start, $end, true);
        $end   = $this->skipWhitespace($tokens, $end, $start, false);

        if ($start > $end) {
            return ['evaluable' => false, 'value' => null];
        }

        $token = $tokens[$start];

        // unary sign on a numeric literal
        if ($token->equalsAny(['-', '+'])) {
            $number = $this->skipWhitespace($tokens, $start + 1, $end, true);

            if ($number > $end || !$tokens[$number]->isGivenKind([T_LNUMBER, T_DNUMBER])) {
                return ['evaluable' => false, 'value' => null];
            }

            $sign  = '-' === $token->getContent() ? -1 : 1;
            $value = $tokens[$number]->isGivenKind(T_DNUMBER)
                ? (float) $tokens[$number]->getContent()
                : (int) $tokens[$number]->getContent();

            return ['evaluable' => true, 'value' => $sign * $value];
        }

        if ($start === $end) {
            if ($token->isGivenKind(T_LNUMBER)) {
                return ['evaluable' => true, 'value' => (int) $token->getContent()];
            }

            if ($token->isGivenKind(T_DNUMBER)) {
                return ['evaluable' => true, 'value' => (float) $token->getContent()];
            }

            if ($this->isStringLiteralToken($tokens, $start)) {
                return ['evaluable' => true, 'value' => substr($token->getContent(), 1, -1)];
            }

            if ($token->isGivenKind(T_STRING)) {
                $literal = $token->getContent();

                if ('true' === $literal) {
                    return ['evaluable' => true, 'value' => true];
                }

                if ('false' === $literal) {
                    return ['evaluable' => true, 'value' => false];
                }

                if ('null' === $literal) {
                    return ['evaluable' => true, 'value' => null];
                }
            }

            return ['evaluable' => false, 'value' => null];
        }

        // grouping parenthesis around the whole value
        if ($token->equals('(')) {
            $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $start);

            if ($close !== $end) {
                return ['evaluable' => false, 'value' => null];
            }

            return $this->evaluateLiteralValue($tokens, $start + 1, $close - 1);
        }

        // nested array literal
        if ($token->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
            $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $start);

            if ($this->skipWhitespace($tokens, $close + 1, $end, true) <= $end) {
                return ['evaluable' => false, 'value' => null];
            }

            $value = [];

            foreach ($this->findItemRanges($tokens, $start, $close) as $range) {
                $item = $this->describeItem($tokens, $range[0], $range[1], true);

                if (null === $item) {
                    return ['evaluable' => false, 'value' => null];
                }

                $value[$item['key']] = $item['value'];
            }

            return ['evaluable' => true, 'value' => $value];
        }

        return ['evaluable' => false, 'value' => null];
    }

    /**
     * All short-syntax array literals as [opening index, closing index]
     * pairs, rightmost first: a rewrite only shifts the token positions
     * behind the edited range, so the indices of the arrays further left
     * stay valid while the rightmost one is fixed.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function findArrays(Tokens $tokens): array
    {
        $arrays = [];

        foreach ($tokens as $index => $token) {
            if ($token->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
                $arrays[] = [$index, $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $index)];
            }
        }

        return array_reverse($arrays);
    }

    /**
     * The [first, last] token ranges of the top-level items of one
     * array, split at the top-level commas.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function findItemRanges(Tokens $tokens, int $open, int $close): array
    {
        $ranges = [];
        $start  = $open + 1;

        /*
         * The tokens between the opening bracket and the first item (a
         * directive, or any other comment) are no part of an item.
         */
        while ($start < $close && $tokens[$start]->isGivenKind([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            ++$start;
        }

        foreach ($this->findTopLevelCommas($tokens, $start, $close - 1) as $comma) {
            $ranges[] = [$start, $comma - 1];
            $start    = $comma + 1;
        }

        /*
         * Without a trailing comma the last item ends at the closing bracket;
         * with one, every item was already captured in the loop above.
         */
        $lastMeaningful = $tokens->getPrevMeaningfulToken($close);

        if (null !== $lastMeaningful && !$tokens[$lastMeaningful]->equals(',') && $start <= $close - 1) {
            $ranges[] = [$start, $close - 1];
        }

        return $ranges;
    }

    /**
     * The comma indices that separate the items of one array, i.e. the
     * commas at nesting depth 0 inside it. Nested constructs (array
     * literals, calls, index accesses, class-like bodies) are jumped over
     * with findBlockEnd(), so a comma inside them is never returned.
     *
     * @return list<int>
     */
    private function findTopLevelCommas(Tokens $tokens, int $start, int $end): array
    {
        $commas = [];

        for ($i = $start; $i <= $end; ++$i) {
            $token = $tokens[$i];

            if ($token->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
                $i = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $i);

                continue;
            }

            if ($token->equals('[')) {
                $i = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_INDEX_BRACKET, $i);

                continue;
            }

            if ($token->equals('(')) {
                $i = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $i);

                continue;
            }

            if ($token->equals('{') || $token->isGivenKind(T_CURLY_OPEN)) {
                $i = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_BRACE, $i);

                continue;
            }

            if ($token->equals(',')) {
                $commas[] = $i;
            }
        }

        return $commas;
    }

    /**
     * Replaces the tokens between the brackets with the items in sorted
     * order. A multi-line array gets one item per line, the indent of
     * each line is taken from the original line of that item, so a
     * per-item indentation is preserved; a single-line array keeps the
     * whitespace padding around its items. The tokens between the
     * opening bracket and the first item (a directive comment, or any
     * other comment) are carried over unchanged.
     *
     * @throws PregException
     * @throws ValueError
     */
    private function fixArray(Tokens $tokens, int $open, int $close): bool
    {
        $directive = $this->getDirective($tokens, $open, $close);

        if (self::DIRECTIVE_OFF === $directive['mode']) {
            return false;
        }

        $values = self::DIRECTIVE_VALUES === $directive['mode'];
        $items  = $this->getItems($tokens, $open, $close, $values);

        if (null === $items) {
            return false;
        }

        $sortedItems = $items;

        if (true === $values) {
            usort($sortedItems, static fn(array $a, array $b): int => self::compareItemsByValue($a, $b));
        } else {
            usort($sortedItems, static fn(array $a, array $b): int => strcasecmp($a['key'], $b['key']));
        }

        $changed = false;

        foreach ($items as $index => $item) {
            if ($item['first'] !== $sortedItems[$index]['first']) {
                $changed = true;

                break;
            }
        }

        if (false === $changed) {
            return false;
        }

        $multiLine = false;

        for ($i = $open + 1; $i < $close; ++$i) {
            if (str_contains($tokens[$i]->getContent(), "\n")) {
                $multiLine = true;

                break;
            }
        }

        $newTokens = [];

        for ($i = $open + 1; $i <= $directive['preItemLast']; ++$i) {
            $newTokens[] = $tokens[$i];
        }

        if ([] === $newTokens) {
            if (true === $multiLine) {
                $newTokens = [new Token([T_WHITESPACE, "\n" . $this->getLineIndent($tokens, $sortedItems[0]['first'])])];
            } else {
                $newTokens = $this->getSurroundingWhitespace($tokens, $open);
            }
        }

        foreach ($sortedItems as $index => $item) {
            for ($i = $item['first']; $i <= $item['last']; ++$i) {
                $newTokens[] = $tokens[$i];
            }

            if (true === $multiLine) {
                $newTokens[] = new Token(',');

                if ($index === count($sortedItems) - 1) {
                    // the closing bracket stands on its own line at the array's indent
                    $newTokens[] = new Token([T_WHITESPACE, "\n" . $this->getLineIndent($tokens, $open)]);
                } else {
                    $next        = $sortedItems[$index + 1];
                    $newTokens[] = new Token([T_WHITESPACE, "\n" . $this->getLineIndent($tokens, $next['first'])]);
                }
            } elseif ($index < count($sortedItems) - 1) {
                $newTokens[] = new Token([T_WHITESPACE, ', ']);
            }
        }

        if (false === $multiLine) {
            $newTokens = array_merge($newTokens, $this->getSurroundingWhitespace($tokens, $close, true));
        }

        $tokens->overrideRange($open + 1, $close - 1, $newTokens);

        return true;
    }

    /**
     * The directive for one array, read from the comment tokens between
     * the opening bracket and the first item: no directive (or an
     * unknown one) keeps the default key sort, "off" excludes the array
     * from the fix entirely and "values" switches the sort to the
     * values. The returned index bounds the pre-item region, which the
     * rewrite carries over unchanged.
     *
     * @return array{mode: string, preItemLast: int}
     * @throws PregException
     */
    private function getDirective(Tokens $tokens, int $open, int $close): array
    {
        $mode  = self::DIRECTIVE_KEYS;
        $index = $open + 1;

        while ($index < $close && $tokens[$index]->isGivenKind([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            if ($tokens[$index]->isGivenKind([T_COMMENT, T_DOC_COMMENT])
                && Preg::match('/PSBits\/alphabetical_array_keys\s*=\s*(off|values)/', $tokens[$index]->getContent(), $matches)
            ) {
                $mode = 'off' === $matches[1] ? self::DIRECTIVE_OFF : self::DIRECTIVE_VALUES;
            }

            ++$index;
        }

        return ['mode' => $mode, 'preItemLast' => $index - 1];
    }

    /**
     * Splits the array content into items at the top-level commas and
     * describes each of them. Returns null as soon as one item does not
     * fit the shape this fixer handles, so the whole array is left alone.
     *
     * @return null|list<_Item>
     * @throws PregException
     */
    private function getItems(Tokens $tokens, int $open, int $close, bool $values = false): ?array
    {
        $items = [];

        foreach ($this->findItemRanges($tokens, $open, $close) as $range) {
            $item = $this->describeItem($tokens, $range[0], $range[1], $values);

            if (null === $item) {
                return null;
            }

            $items[] = $item;
        }

        if (2 > count($items)) {
            return null;
        }

        $seen = [];

        foreach ($items as $current) {
            $identity = is_int($current['key']) ? 'i' . $current['key'] : 's' . $current['key'];

            if (isset($seen[$identity])) {
                return null;
            }

            $seen[$identity] = true;
        }

        return $items;
    }

    /**
     * The indentation of the line that contains the given token. Tokens
     * can span several lines (a whitespace token usually does), so the
     * tokens are walked backwards until one contains a newline; the
     * indent is what follows its last newline.
     *
     * @throws ValueError
     */
    private function getLineIndent(Tokens $tokens, int $index): string
    {
        $i      = $index;
        $buffer = '';

        while (0 <= $i) {
            $content  = $tokens[$i]->getContent();
            $position = strrpos($content, "\n");

            if (false !== $position) {
                $buffer = substr($content, $position + 1) . $buffer;

                break;
            }

            $buffer = $content . $buffer;
            --$i;
        }

        $stripped = ltrim($buffer);

        return substr($buffer, 0, strlen($buffer) - strlen($stripped));
    }

    /**
     * The whitespace right after the opening bracket (or left before the
     * closing bracket), kept so single line arrays keep their padding.
     *
     * @return list<Token>
     */
    private function getSurroundingWhitespace(Tokens $tokens, int $bracket, bool $before = false): array
    {
        $index = $before ? $bracket - 1 : $bracket + 1;
        $token = $tokens[$index];

        if ($token->isGivenKind(T_WHITESPACE) && !str_contains($token->getContent(), "\n")) {
            return [$token];
        }

        return [];
    }

    /**
     * Whether the (...) range is a cast, i.e. the tokens between the
     * brackets are exactly one of the cast keywords and nothing else.
     */
    private function isCastKeyword(Tokens $tokens, int $open, int $close): bool
    {
        $inner = $tokens->getNextMeaningfulToken($open);

        if (null === $inner || $inner >= $close) {
            return false;
        }

        if (!$tokens[$inner]->isGivenKind(T_STRING)) {
            return false;
        }

        $keyword = $tokens[$inner]->getContent();

        return in_array($keyword, ['array', 'bool', 'float', 'int', 'object', 'string', 'unset'], true)
            && $tokens->getPrevMeaningfulToken($close) === $inner;
    }

    /**
     * Whether the token range is a class-constant or ::class fetch, i.e.
     * a name (possibly namespace-qualified) followed by :: and exactly
     * one more name - pure reads, no call.
     */
    private function isConstantFetch(Tokens $tokens, int $start, int $end): bool
    {
        $i = $start;

        while ($i <= $end && $tokens[$i]->isGivenKind([T_STRING, T_NS_SEPARATOR])) {
            ++$i;
        }

        if ($i > $end) {
            return false;
        }

        $i = $this->skipWhitespace($tokens, $i, $end, true);

        if ($i > $end || !$tokens[$i]->isGivenKind(T_PAAMAYIM_NEKUDOTAYIM)) {
            return false;
        }

        $name = $this->skipWhitespace($tokens, $i + 1, $end, true);

        if ($name > $end || !$tokens[$name]->isGivenKind([T_STRING, T_CLASS])) {
            return false;
        }

        return $this->skipWhitespace($tokens, $name + 1, $end, true) > $end;
    }

    /**
     * @throws PregException
     */
    private function isConstantName(Token $token): bool
    {
        return Preg::match('/^[A-Z_][A-Z0-9_]*$/', $token->getContent());
    }

    /**
     * Whether the token range after a variable is a pure read chain of
     * ->name, ?->name or ::name parts; any other token (a call, ...)
     * would be a side effect.
     */
    private function isPropertyChain(Tokens $tokens, int $start, int $end): bool
    {
        $i = $start + 1;

        while (true) {
            $i = $this->skipWhitespace($tokens, $i, $end, true);

            if ($i > $end) {
                return true;
            }

            $token = $tokens[$i];

            if ($token->isGivenKind([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                $name = $this->skipWhitespace($tokens, $i + 1, $end, true);

                if ($name > $end || !$tokens[$name]->isGivenKind(T_STRING)) {
                    return false;
                }

                $i = $name + 1;

                continue;
            }

            if ($token->isGivenKind(T_PAAMAYIM_NEKUDOTAYIM)) {
                $name = $this->skipWhitespace($tokens, $i + 1, $end, true);

                if ($name > $end || !$tokens[$name]->isGivenKind([T_STRING, T_CLASS])) {
                    return false;
                }

                $i = $name + 1;

                continue;
            }

            return false;
        }
    }

    /**
     * Checks whether an array value consists only of reads and literals, so
     * reordering the item cannot change the program's side effect order.
     *
     * @throws PregException
     */
    private function isSideEffectFreeValue(Tokens $tokens, int $start, int $end): bool
    {
        $start = $this->skipWhitespace($tokens, $start, $end, true);
        $end   = $this->skipWhitespace($tokens, $end, $start, false);

        if ($start > $end) {
            return false;
        }

        $token = $tokens[$start];

        // cast or parenthesis
        if ($token->equals('(')) {
            $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $start);

            if ($this->isCastKeyword($tokens, $start, $close)) {
                return $this->isSideEffectFreeValue($tokens, $close + 1, $end);
            }

            // grouping parenthesis: must wrap the whole value
            return $close === $end && $this->isSideEffectFreeValue($tokens, $start + 1, $close - 1);
        }

        // unary operator
        if ($token->equalsAny(['-', '+', '!', '~'])) {
            return $this->isSideEffectFreeValue($tokens, $start + 1, $end);
        }

        if ($start === $end) {
            if ($token->isGivenKind([T_LNUMBER, T_DNUMBER]) || $this->isStringLiteralToken($tokens, $start)) {
                return true;
            }

            if ($token->isGivenKind(T_STRING) && in_array($token->getContent(), ['true', 'false', 'null'], true)) {
                return true;
            }

            if ($token->isGivenKind(T_VARIABLE)) {
                return true;
            }

            // a bare constant name (single segment, no namespace, no call)
            if ($token->isGivenKind(T_STRING) && $this->isConstantName($token)) {
                return true;
            }

            return false;
        }

        // variable with a property chain: $a->b, $this->x->y
        if ($token->isGivenKind(T_VARIABLE)) {
            return $this->isPropertyChain($tokens, $start, $end);
        }

        // class constant or ::class fetch
        if ($token->isGivenKind([T_NS_SEPARATOR, T_STRING])) {
            return $this->isConstantFetch($tokens, $start, $end);
        }

        // nested array literal
        if ($token->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
            $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $start);

            if ($this->skipWhitespace($tokens, $close + 1, $end, true) <= $end) {
                return false;
            }

            foreach ($this->getItems($tokens, $start, $close) ?? [] as $item) {
                $arrow      = $tokens->getNextMeaningfulToken($item['first']);
                $valueStart = null === $arrow ? null : $tokens->getNextMeaningfulToken($arrow);

                if (null === $valueStart || !$this->isSideEffectFreeValue($tokens, $valueStart, $item['last'])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * A string literal is the single token whose content is a quoted string.
     * The token kind of string literals differs between PHP versions, so
     * the content is the reliable marker.
     */
    private function isStringLiteralToken(Tokens $tokens, int $index): bool
    {
        $content = $tokens[$index]->getContent();

        return 1 < strlen($content) && preg_match('/^([\'"]).*\\1$/s', $content);
    }

    /**
     * The literal key of an array item, or null when the token is no
     * usable key: key mode accepts string literals only, value mode
     * string and integer literals.
     */
    private function literalKey(Tokens $tokens, int $index, bool $values): string|int|null
    {
        $token = $tokens[$index];

        if ($this->isStringLiteralToken($tokens, $index)) {
            return substr($token->getContent(), 1, -1);
        }

        if (true === $values && $token->isGivenKind(T_LNUMBER)) {
            return (int) $token->getContent();
        }

        return null;
    }

    /**
     * Moves the index over whitespace tokens to the next meaningful
     * token, but never past $limit. Whitespace is a token kind
     * (T_WHITESPACE), so skipping blanks is an explicit token walk.
     */
    private function skipWhitespace(Tokens $tokens, int $index, int $limit, bool $forward): int
    {
        if (true === $forward) {
            while ($index <= $limit && $tokens[$index]->isGivenKind(T_WHITESPACE)) {
                ++$index;
            }

            return $index;
        }

        while ($index >= $limit && $tokens[$index]->isGivenKind(T_WHITESPACE)) {
            --$index;
        }

        return $index;
    }
}
