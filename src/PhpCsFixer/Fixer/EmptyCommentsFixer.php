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

use function count;
use function explode;
use function str_contains;
use function str_starts_with;
use function strrpos;
use function substr;
use function trim;

use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;

/**
 * Removes comments that carry no content: empty docblocks, empty block
 * comments and single-line comments without text (// or #).
 *
 * The empty docblocks are mainly left behind by the no_superfluous_phpdoc_tags
 * fixer, which drops the redundant tags but keeps the docblock shell.
 *
 * The fixer works on the token list: docblocks are T_DOC_COMMENT tokens,
 * every other comment style is a T_COMMENT token, and each comment
 * carries its full text as content, so the emptiness check is plain
 * string handling.
 */
final class EmptyCommentsFixer extends AbstractFixer
{
    /**
     * A comment is empty when nothing but the delimiters (and, for block
     * comments, the star prefixes of the inner lines) is left of it.
     */
    private static function isEmpty(string $content): bool
    {
        if (str_starts_with($content, '/**')) {
            $inner = substr($content, 3, -2);
        } elseif (str_starts_with($content, '/*')) {
            $inner = substr($content, 2, -2);
        } elseif (str_starts_with($content, '//') || str_starts_with($content, '#')) {
            return '' === trim(substr($content, 2));
        } else {
            return false;
        }

        foreach (explode("\n", $inner) as $line) {
            $line = preg_replace('/^\s*\*?/', '', $line);

            if ('' !== trim($line)) {
                return false;
            }
        }

        return true;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Removes empty comments and docblocks without content.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php

                        class Demo
                        {
                            /**
                             */
                            public function run(): void
                            {
                            }
                        }

                        PHP
                ),
            ],
        );
    }

    public function getName(): string
    {
        return 'PSBits/empty_comments';
    }

    /**
     * {@inheritdoc}
     *
     * Must run after NoSuperfluousPhpdocTagsFixer, which leaves the empty
     * docblock shells behind.
     */
    public function getPriority(): int
    {
        return -10;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_DOC_COMMENT) || $tokens->isTokenKindFound(T_COMMENT);
    }

    /**
     * Backwards, so the tokens cleared or rewritten by a removal (the
     * direct neighbours of the comment) are never visited for removal
     * themselves.
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = count($tokens) - 1; 0 <= $index; --$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind([T_DOC_COMMENT, T_COMMENT])) {
                continue;
            }

            if (!self::isEmpty($token->getContent())) {
                continue;
            }

            $this->removeComment($tokens, $index);
        }
    }

    /**
     * Removes the comment at $index together with the rest of its line
     * (a comment standing on its own) or the surrounding space
     * (a comment sitting on a line with code).
     *
     * Whitespace tokens are contiguous, so the line ownership is decided
     * by the single tokens right before and after the comment. A
     * whitespace token can span several lines, so dropping a whole line
     * means cutting the token at the newline inside its content.
     */
    private function removeComment(Tokens $tokens, int $index): void
    {
        $size      = count($tokens);
        $prevIndex = $index - 1;
        $nextIndex = $index + 1;
        $ownLine   = 0 > $prevIndex
            || ($tokens[$prevIndex]->isGivenKind(T_WHITESPACE) && str_contains($tokens[$prevIndex]->getContent(), "\n"));

        if (true === $ownLine && $nextIndex < $size) {
            $next = $tokens[$nextIndex];

            $ownLine = !$next->isGivenKind(T_WHITESPACE)
                ? false
                : str_contains($next->getContent(), "\n");
        }

        if (true === $ownLine) {
            if (0 <= $prevIndex && $tokens[$prevIndex]->isGivenKind(T_WHITESPACE)) {
                $content   = $tokens[$prevIndex]->getContent();
                $lastBreak = strrpos($content, "\n");

                // drop the indentation of the comment's own line
                $tokens[$prevIndex] = new Token([T_WHITESPACE, substr($content, 0, $lastBreak + 1)]);
            }

            if ($nextIndex < $size && $tokens[$nextIndex]->isGivenKind(T_WHITESPACE)) {
                $content   = $tokens[$nextIndex]->getContent();
                $nextBreak = strpos($content, "\n");

                $rest = false !== $nextBreak ? substr($content, $nextBreak + 1) : '';

                if ('' === $rest) {
                    $tokens->clearAt($nextIndex);
                } else {
                    // keep the indentation of the following line
                    $tokens[$nextIndex] = new Token([T_WHITESPACE, $rest]);
                }
            }
        } elseif ($nextIndex < $size && $tokens[$nextIndex]->isGivenKind(T_WHITESPACE) && !str_contains($tokens[$nextIndex]->getContent(), "\n")) {
            // drop the space between the comment and the following code
            $tokens->clearAt($nextIndex);
        } elseif (0 <= $prevIndex && $tokens[$prevIndex]->isGivenKind(T_WHITESPACE)) {
            // drop the space between the preceding code and the comment
            $tokens->clearAt($prevIndex);
        }

        $tokens->clearAt($index);
    }
}
