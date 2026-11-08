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
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use SplFileInfo;
use ValueError;

use function array_diff_key;
use function array_flip;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function ltrim;
use function preg_match;
use function preg_replace;
use function range;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const T_ABSTRACT;
use const T_CLASS;
use const T_DOC_COMMENT;
use const T_ENUM;
use const T_FINAL;
use const T_INTERFACE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_READONLY;
use const T_TRAIT;
use const T_WHITESPACE;

/**
 * Removes the boilerplate from the docblocks of class-like declarations:
 * the short description is dropped when it merely repeats the name (the
 * plain name, or the name preceded by one of the keywords "Class",
 * "Enum", "Interface" or "Trait"), every @package tag is dropped, and
 * a docblock that carries nothing else is removed together with its line.
 *
 * A docblock that holds valuable content - a real description, other tags
 * like @extends - keeps that content; only the boilerplate parts are cut
 * out of it.
 *
 * The fixer works on the token list: a class-like declaration is located
 * by its T_CLASS / T_INTERFACE / T_TRAIT / T_ENUM token (an anonymous
 * class is skipped), and its docblock is the T_DOC_COMMENT token that
 * stands directly before it, whitespace, class modifiers and attributes
 * apart. The docblock content is one single token, so the cleanup is
 * plain string handling: the lines are split into the summary (the part
 * before the first @ tag) and the tags; the name paragraph and the
 * package tags are cut out, and the cleaned lines are either written
 * back as the new token content or the whole token (and the rest of
 * its line) is cleared.
 */
final class ClassPhpdocCleanupFixer extends AbstractFixer
{
    /**
     * Cleans the docblock content: the name paragraph (a short
     * description that merely repeats the class name) and the @package
     * tags are cut out, and the rest is rebuilt in the canonical
     * form - the summary, a blank line, then the tags.
     *
     * When nothing is cut out, the original content is returned
     * unchanged; when nothing valuable is left, null - the docblock
     * itself has to go.
     *
     * @throws ValueError
     */
    private static function cleanDocblock(string $content, string $className): ?string
    {
        $logical          = self::logicalLines($content);
        [$summary, $tags] = self::splitSummaryAndTags($logical);

        $summary = self::dropNameParagraph($summary, $className);
        $tags    = self::dropPackageTags($tags);

        /*
         * nothing was cut out: the summary and the tags are the logical
         * lines themselves, so an equal count means the content stays
         */
        if (count($logical) === count($summary) + count($tags)) {
            return $content;
        }

        $summaryText = self::trimBlankLines($summary);
        $tagsText    = self::trimBlankLines($tags);

        if ([] === $summaryText && [] === $tagsText) {
            return null;
        }

        $lines = ['/**'];

        foreach ($summaryText as $line) {
            $lines[] = '' === trim($line) ? ' *' : ' * ' . $line;
        }

        if ([] !== $summaryText && [] !== $tagsText) {
            $lines[] = ' *';
        }

        foreach ($tagsText as $line) {
            $lines[] = '' === trim($line) ? ' *' : ' * ' . $line;
        }

        $lines[] = ' */';

        return implode("\n", $lines);
    }

    /**
     * Drops the first summary paragraph when it merely names the class
     * (the plain name, or the name preceded by one of the keywords
     * "Class", "Enum", "Interface" or "Trait"; case-insensitive) - the
     * paragraph and the blank lines that separate it from the remaining
     * text.
     *
     * @param list<string> $summary
     * @return list<string>
     * @throws ValueError
     */
    private static function dropNameParagraph(array $summary, string $className): array
    {
        $first     = null;
        $last      = null;
        $paragraph = [];

        foreach ($summary as $index => $line) {
            $trimmed = trim($line);

            if ('' === $trimmed) {
                if (null !== $last) {
                    break;
                }

                continue;
            }

            if (null === $first) {
                $first = $index;
            }

            $last        = $index;
            $paragraph[] = $trimmed;
        }

        if (null === $first) {
            return $summary;
        }

        $text = implode(' ', $paragraph);
        $name = 0 === strcasecmp($text, $className);

        if (!$name && 1 === preg_match('/^(?:class|enum|interface|trait)\s+([a-z_][a-z0-9_]*)$/i', $text, $matches)) {
            $name = 0 === strcasecmp($matches[1], $className);
        }

        if (!$name) {
            return $summary;
        }

        $drop = range($first, $last);

        for ($index = $last + 1; $index < count($summary) && '' === trim($summary[$index]); ++$index) {
            $drop[] = $index;
        }

        return array_values(array_diff_key($summary, array_flip($drop)));
    }

    /**
     * Drops every @package tag (and its continuation lines) from the tag
     * lines; the other tags stay.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private static function dropPackageTags(array $tags): array
    {
        $kept    = [];
        $dropped = false;

        foreach ($tags as $line) {
            if ('' !== trim($line) && str_starts_with(ltrim($line), '@')) {
                $dropped = '@package' === strtolower(trim(explode(' ', ltrim($line), 2)[0]));
            }

            if (true === $dropped) {
                continue;
            }

            $kept[] = $line;
        }

        return $kept;
    }

    /**
     * The docblock index of the class-like declaration at $index - the
     * T_DOC_COMMENT standing directly before it, whitespace, the class
     * modifiers (final, abstract, visibility, readonly) and attributes
     * apart - or null.
     */
    private static function findDocblock(Tokens $tokens, int $index): ?int
    {
        for ($i = $index - 1; 0 <= $i; --$i) {
            $token = $tokens[$i];

            if (
                $token->isGivenKind(T_WHITESPACE)
                || $token->isGivenKind([T_ABSTRACT, T_FINAL, T_PROTECTED, T_PUBLIC, T_READONLY])
            ) {
                continue;
            }

            if ($token->isGivenKind(CT::T_ATTRIBUTE_CLOSE)) {
                $i = $tokens->findBlockStart(Tokens::BLOCK_TYPE_ATTRIBUTE, $i);

                continue;
            }

            return $token->isGivenKind(T_DOC_COMMENT) ? $i : null;
        }

        return null;
    }

    /**
     * The lines of the docblock without the delimiters and the star
     * markers; a single-line docblock yields its single inner line.
     *
     * @return list<string>
     */
    private static function logicalLines(string $content): array
    {
        $lines = explode("\n", $content);

        if (1 === count($lines)) {
            $inner = trim(substr($lines[0], 3, -2));

            return ['' === $inner ? '' : $inner];
        }

        $logical = [trim(substr($lines[0], 3))];

        for ($i = 1; $i < count($lines) - 1; ++$i) {
            $logical[] = self::stripLinePrefix($lines[$i]);
        }

        $logical[] = self::stripLinePrefix(substr($lines[count($lines) - 1], 0, -2));

        return $logical;
    }

    /**
     * Removes the docblock at $index together with the rest of its own
     * line and the line break before the following line; the whitespace
     * tokens around it are contiguous, so the line ownership is decided
     * by the single tokens right before and after the docblock.
     *
     * @throws ValueError
     */
    private static function removeDocblock(Tokens $tokens, int $index): void
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

                // drop the indentation of the docblock's own line
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
            // drop the space between the docblock and the following code
            $tokens->clearAt($nextIndex);
        } elseif (0 <= $prevIndex && $tokens[$prevIndex]->isGivenKind(T_WHITESPACE)) {
            // drop the space between the preceding code and the docblock
            $tokens->clearAt($prevIndex);
        }

        $tokens->clearAt($index);
    }

    /**
     * The summary is the part before the first @ tag line; a tag
     * continues on the following lines until the next @ tag line.
     *
     * @param list<string> $logical
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function splitSummaryAndTags(array $logical): array
    {
        $summary = [];
        $tags    = [];
        $tagged  = false;

        foreach ($logical as $line) {
            if (!$tagged && '' !== trim($line) && str_starts_with(ltrim($line), '@')) {
                $tagged = true;
            }

            if (true === $tagged) {
                $tags[] = $line;
            } else {
                $summary[] = $line;
            }
        }

        return [$summary, $tags];
    }

    /**
     * Strips the star marker (and the single space after it) from a
     * docblock line; the indentation of continuation lines is kept.
     */
    private static function stripLinePrefix(string $line): string
    {
        return rtrim(preg_replace('/^\s*\*\s?/', '', $line) ?? '');
    }

    /**
     * Drops the leading and trailing blank lines of the lines; the
     * blank lines between text lines (the paragraph separation) stay.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function trimBlankLines(array $lines): array
    {
        $first = null;
        $last  = null;

        foreach ($lines as $index => $line) {
            if ('' !== trim($line)) {
                if (null === $first) {
                    $first = $index;
                }

                $last = $index;
            }
        }

        if (null === $first) {
            return [];
        }

        return array_slice($lines, $first, $last - $first + 1);
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Removes the class name line and @package tags from class docblocks; a docblock without any content left is removed.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php

                        /**
                         * Class Demo
                         *
                         * @package App
                         */
                        class Demo
                        {
                        }
                        PHP
                ),
            ],
        );
    }

    public function getName(): string
    {
        return 'PSBits/class_phpdoc_cleanup';
    }

    public function isCandidate(Tokens $tokens): bool
    {
        if (!$tokens->isTokenKindFound(T_DOC_COMMENT)) {
            return false;
        }

        foreach ([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM] as $kind) {
            if ($tokens->isTokenKindFound($kind)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per class-like declaration: locates the docblock standing directly
     * before it, cleans the content, and either rewrites the token or
     * removes it together with the rest of its line.
     *
     * @throws ValueError
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $analyzer = new TokensAnalyzer($tokens);

        for ($index = 0; $index < count($tokens); ++$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                continue;
            }

            if ($analyzer->isAnonymousClass($index)) {
                continue;
            }

            $docIndex = self::findDocblock($tokens, $index);

            if (null === $docIndex) {
                continue;
            }

            $className = $tokens[$tokens->getNextMeaningfulToken($index)]->getContent();
            $cleaned   = self::cleanDocblock($tokens[$docIndex]->getContent(), $className);

            if (null === $cleaned) {
                self::removeDocblock($tokens, $docIndex);
            } else {
                $tokens[$docIndex] = new Token([T_DOC_COMMENT, $cleaned]);
            }
        }
    }
}
