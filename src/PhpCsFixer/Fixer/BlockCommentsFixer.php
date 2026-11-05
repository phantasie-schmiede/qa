<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Qa\PhpCsFixer\Fixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

use const T_COMMENT;
use const T_OPEN_TAG;
use const T_WHITESPACE;

/**
 * Converts runs of consecutive whole-line single-line comments (// or #)
 * into a single block comment.
 *
 * Single comments that stand on their own (one line only) and trailing
 * comments (a comment after code on the same line) are left untouched.
 *
 * The fixer works on the token list, not on lines: every comment style is
 * a T_COMMENT token, told apart by the prefix of its content, and
 * whitespace is a token, too - one whitespace token can hold several
 * lines at once. A run therefore counts as consecutive when the single
 * whitespace token between two comments contains exactly one newline,
 * i.e. the next comment starts on the very next line. The whole run is
 * replaced by one T_COMMENT token in block comment style; the runs are
 * applied rightmost first, so the token indices of the runs further left
 * stay valid.
 */
final class BlockCommentsFixer extends AbstractFixer
{
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Converts consecutive whole-line // or # comments into a single /* ... */ block comment.',
            [
                new CodeSample(
                    "<?php\n// line one\n// line two\nfoo();\n"
                ),
            ],
        );
    }

    public function getName(): string
    {
        return 'PSBits/block_comments';
    }

    /**
     * {@inheritdoc}
     *
     * Must run before ClassBlockSeparationFixer, NoExtraBlankLinesFixer.
     */
    public function getPriority(): int
    {
        return 100;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_COMMENT);
    }

    /**
     * Strips the comment marker from each line (// is two characters, #
     * one) and a single leading space; an empty line becomes a bare * so
     * the block comment keeps the line structure of the run.
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $runs = $this->findCommentRuns($tokens);

        foreach (array_reverse($runs) as [$start, $end]) {
            $indent = $this->getLineIndent($tokens, $start);

            $lines = [];

            for ($i = $start; $i <= $end; ++$i) {
                $token = $tokens[$i];

                if (!$token->isGivenKind(T_COMMENT)) {
                    continue;
                }

                $content = $token->getContent();

                // the // marker is two characters, the # marker is one
                $text = '#' === $content[0] ? substr($content, 1) : substr($content, 2);

                if (str_starts_with($text, ' ')) {
                    $text = substr($text, 1);
                }

                $lines[] = rtrim($text);
            }

            $comment = "/*\n".implode("\n", array_map(
                static fn(string $line): string => $indent.' *'.('' === $line ? '' : ' '.$line),
                $lines,
            ))."\n".$indent.' */';

            $tokens->overrideRange($start, $end, [new Token([T_COMMENT, $comment])]);
        }
    }

    /**
     * The runs of consecutive whole-line comments as [start, end] index
     * pairs. A run grows to the right as long as the next token is a
     * whole-line comment with the same marker that starts on the very
     * next line; a run of a single comment is no fix candidate.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function findCommentRuns(Tokens $tokens): array
    {
        $runs  = [];
        $count = $tokens->count();
        $i     = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if (!$token->isGivenKind(T_COMMENT) || !$this->isLineComment($token) || !$this->isWholeLineComment($tokens, $i)) {
                ++$i;

                continue;
            }

            $marker = $token->getContent()[0];
            $end    = $i;

            while (true) {
                $wsIndex = $end + 1;

                if ($wsIndex >= $count || !$tokens[$wsIndex]->isGivenKind(T_WHITESPACE)) {
                    break;
                }

                if (1 !== substr_count($tokens[$wsIndex]->getContent(), "\n")) {
                    break;
                }

                $nextIndex = $wsIndex + 1;

                if ($nextIndex >= $count) {
                    break;
                }

                $next = $tokens[$nextIndex];

                if (!$next->isGivenKind(T_COMMENT) || !$this->isLineComment($next) || $next->getContent()[0] !== $marker) {
                    break;
                }

                if (!$this->isWholeLineComment($tokens, $nextIndex)) {
                    break;
                }

                $end = $nextIndex;
            }

            if ($end > $i) {
                $runs[] = [$i, $end];
                $i      = $end;
            }

            ++$i;
        }

        return $runs;
    }

    /**
     * The indentation of the line the comment starts on, read from the
     * whitespace token in front of it. The token can span several lines,
     * so only the part after its last newline is the indent.
     */
    private function getLineIndent(Tokens $tokens, int $index): string
    {
        $prevIndex = $index - 1;

        if (0 > $prevIndex || !$tokens[$prevIndex]->isGivenKind(T_WHITESPACE)) {
            return '';
        }

        $content  = $tokens[$prevIndex]->getContent();
        $position = strrpos($content, "\n");

        return false === $position ? '' : substr($content, $position + 1);
    }

    /**
     * T_COMMENT covers all comment styles; a line comment is the one
     * whose content starts with // or #, block comments are excluded.
     */
    private function isLineComment(Token $token): bool
    {
        $content = $token->getContent();

        return str_starts_with($content, '//') || str_starts_with($content, '#');
    }

    /**
     * Whether the comment stands on its own line: nothing but the PHP
     * opening tag or whitespace containing a newline precedes it, and
     * nothing but whitespace containing a newline - or the end of the
     * file - follows it.
     */
    private function isWholeLineComment(Tokens $tokens, int $index): bool
    {
        // the comment must start on its own line
        $prevIndex = $index - 1;

        if (0 > $prevIndex) {
            return true;
        }

        $prev = $tokens[$prevIndex];

        if ($prev->isGivenKind(T_OPEN_TAG)) {
            return true;
        }

        if (!$prev->isGivenKind(T_WHITESPACE) || !str_contains($prev->getContent(), "\n")) {
            return false;
        }

        // the comment must end on its own line (no code after it)
        $nextIndex = $index + 1;

        if ($nextIndex >= $tokens->count()) {
            return true;
        }

        $next = $tokens[$nextIndex];

        return $next->isGivenKind(T_WHITESPACE) && str_contains($next->getContent(), "\n");
    }
}
