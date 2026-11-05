<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

use function count;
use function in_array;
use function strlen;

/**
 * Rewrites the @throws types of the methods and functions that the
 * ThrowsDocblockRule reports, so that the docblock declares exactly the
 * exceptions that are thrown: one name per @throws line, sorted
 * case-insensitively, no @throws at all when nothing is thrown.
 *
 * The applier is line based. It locates the signature named in the rule
 * message and rewrites, completes or removes the docblock above it (a
 * docblock sits directly above the signature or the attributes above it).
 *
 * The @throws lines use the short name of the exception and the missing
 * use statement is added to the file (an exception from the own namespace
 * needs no import; a colliding short name gets a descriptive alias).
 */
final class ThrowsDocblockApplier
{
    private const string CLASS_PATTERN     = '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|trait|enum)\s+([A-Za-z_]\w*)/';
    private const string HEREDOC_PATTERN   = '/<<<[ \t]*([\'"]?)([A-Za-z_]\w*)\1[ \t]*$/D';
    private const string MESSAGE_PATTERN   = '#^(?:Method (?<class>[A-Za-z0-9_\\\\]+)::(?<name>[A-Za-z_][A-Za-z0-9_]*)\(\)|Function (?<function>[A-Za-z0-9_\\\\]+)\(\)) @throws do not match the thrown exceptions: expected (?<expected>.+), declared (?<declared>.+)\.$#';
    private const string NAMESPACE_PATTERN = '/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/';
    private const string SIGNATURE_PATTERN = '/^(\s*)(?:(?:final|abstract)\s+)*(?:(?:public|protected|private)\s+)*(?:static\s+)?function\s+([A-Za-z_]\w*)\s*\(/';

    private static function charAt(string $line, int $position): ?string
    {
        return $position < strlen($line) ? $line[$position] : null;
    }

    /**
     * Replaces the content of strings, line comments and block comments with
     * spaces so that declarations embedded in them (template code, help
     * texts, test fixtures) cannot be mistaken for real ones.
     *
     * PHP string literals may span multiple lines, so the open quote is
     * carried across lines.
     *
     * @param null|string $inString the open quote of a string started on a
     *                              previous line, or null
     *
     * @return array{0: string, 1: bool, 2: null|string} [masked line, block
     *                                                      comment still open,
     *                                                      open quote or null]
     */
    private static function maskInline(string $line, bool $inBlockComment, ?string $inString): array
    {
        $out       = '';
        $blockOpen = $inBlockComment;
        $string    = $inString;
        $i         = 0;
        $length    = strlen($line);

        while ($i < $length) {
            $char = $line[$i];

            if (null !== $string) {
                /*
                 * inside a string; a backslash escapes the next
                 * character, everything else until the closing quote
                 * is string content
                 */
                if ('\\' === $char && $i + 1 < $length) {
                    $out .= '  ';
                    $i += 2;

                    continue;
                }

                if ($char === $string) {
                    $string = null;
                }

                $out .= ' ';
                ++$i;

                continue;
            }

            if (true === $blockOpen) {
                if ('*' === $char && '/' === self::charAt($line, $i + 1)) {
                    $blockOpen = false;
                    $out .= '  ';
                    $i += 2;

                    continue;
                }

                $out .= ' ';
                ++$i;

                continue;
            }

            if ("'" === $char || '"' === $char) {
                $string = $char;
                $out .= ' ';
                ++$i;

                continue;
            }

            if ('/' === $char) {
                $next = self::charAt($line, $i + 1);

                if ('/' === $next) {
                    break;
                }

                if ('*' === $next) {
                    $blockOpen = true;
                    $out .= '  ';
                    $i += 2;

                    continue;
                }
            }

            if ('<' === $char && '<' === self::charAt($line, $i + 1) && '<' === self::charAt($line, $i + 2)) {
                $rest = substr($line, $i);

                if (1 === preg_match('/^<<<[ \t]*([\'"]?)([A-Za-z_]\w*)\1[ \t]*$/D', $rest, $match)) {
                    // a heredoc opener stays visible so the caller can track it
                    $out .= $match[0];
                    $i += strlen($match[0]);

                    continue;
                }
            }

            $out .= $char;
            ++$i;
        }

        return [$out, $blockOpen, $string];
    }

    /**
     * @return null|array{class: ?string, name: string, names: list<string>}
     */
    private static function parseMessage(string $message): ?array
    {
        if (1 !== preg_match(self::MESSAGE_PATTERN, $message, $matches)) {
            return null;
        }

        $names = [];

        if ('none' !== $matches['expected']) {
            foreach (explode(', ', $matches['expected']) as $name) {
                $names[] = ltrim($name, '\\');
            }
        }

        usort($names, static fn(string $a, string $b): int => strcasecmp($a, $b));

        return [
            'class' => '' === $matches['class'] ? null : $matches['class'],
            'name'  => '' !== $matches['name'] ? $matches['name'] : $matches['function'],
            'names' => $names,
        ];
    }

    /**
     * @param list<array{line: int, message: string}> $entries rule messages for one file
     * @return array{fixed: int, skipped: list<string>}
     */
    public function apply(string $filePath, array $entries): array
    {
        $content = file_get_contents($filePath);

        if (false === $content) {
            return ['fixed' => 0, 'skipped' => [$filePath . ': unreadable']];
        }

        $lines              = explode("\n", $content);
        $hadTrailingNewline = '' === $lines[count($lines) - 1];

        if ($hadTrailingNewline) {
            array_pop($lines);
        }

        $targets = [];
        $skipped = [];

        foreach ($entries as $entry) {
            $parsed = self::parseMessage($entry['message']);

            if (null === $parsed) {
                $skipped[] = $filePath . ':' . $entry['line'] . ': unrecognized message';

                continue;
            }

            $targets[] = [
                'line'  => $entry['line'],
                'class' => $parsed['class'],
                'name'  => $parsed['name'],
                'names' => $parsed['names'],
            ];
        }

        $edits       = [];
        $bySignature = [];

        foreach ($targets as $target) {
            $candidate = $this->findCandidate($lines, $target);

            if (null === $candidate) {
                $skipped[] = $filePath . ':' . $target['line'] . ': signature not found for ' . $target['name'] . '()';

                continue;
            }

            /*
             * The same method can be reported more than once (a trait
             * method is reported once per using class); merge the name
             * sets that resolve to the same signature into one edit.
             */
            if (!isset($bySignature[$candidate[0]])) {
                $bySignature[$candidate[0]] = ['candidate' => $candidate, 'names' => []];
            }

            $bySignature[$candidate[0]]['names'] = array_merge($bySignature[$candidate[0]]['names'], $target['names']);
        }

        $allNames = [];

        foreach ($bySignature as $group) {
            $allNames = array_merge($allNames, $group['names']);
        }

        $imports = $this->resolveImports($lines, array_values(array_unique($allNames)));

        foreach ($bySignature as $group) {
            $names = array_values(array_unique($group['names']));

            usort($names, static fn(string $a, string $b): int => strcasecmp($a, $b));

            $display = array_map(static fn(string $name): string => $imports['display'][$name], $names);

            usort($display, static fn(string $a, string $b): int => strcasecmp($a, $b));

            $edit = $this->editForCandidate($lines, $group['candidate'], $display);

            if (null === $edit) {
                continue;
            }

            $edits[] = $edit;
        }

        if ([] === $edits) {
            return ['fixed' => 0, 'skipped' => $skipped];
        }

        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($edits as [$start, $end, $replacement]) {
            $length = $end >= $start ? $end - $start + 1 : 0;
            array_splice($lines, $start, $length, $replacement);
        }

        if ([] !== $imports['lines']) {
            $lines = $this->insertImportLines($lines, $imports['lines']);
        }

        $newContent = implode("\n", $lines) . ($hadTrailingNewline ? "\n" : '');

        if (false === file_put_contents($filePath, $newContent)) {
            throw new \RuntimeException('could not write ' . $filePath);
        }

        return ['fixed' => count($edits), 'skipped' => $skipped];
    }

    /**
     * Builds a descriptive alias for a colliding short name: the last
     * non-generic namespace segment plus the class name, following the
     * house style (Typo3ArrayUtility, DbalException, ComdirectApiService).
     *
     * @param array<string, true> $taken the short names in use in the file
     */
    private function buildAlias(string $fqcn, array $taken): string
    {
        $generic = [
            'Abstract',
            'Attribute',
            'Attributes',
            'Base',
            'Common',
            'Core',
            'Data',
            'Domain',
            'Error',
            'Exception',
            'General',
            'Helper',
            'Helpers',
            'Interface',
            'Logic',
            'Model',
            'Models',
            'Repository',
            'Repositories',
            'Result',
            'Results',
            'Service',
            'Services',
            'Utility',
            'Utilities',
        ];
        $segments = explode('\\', $fqcn);
        $class    = (string) array_pop($segments);

        foreach (array_reverse($segments) as $segment) {
            if (in_array($segment, $generic, true)) {
                continue;
            }

            $alias = ucfirst($segment) . ucfirst($class);

            if (!isset($taken[$alias])) {
                return $alias;
            }
        }

        $alias  = $class . '2';
        $suffix = 3;

        while (isset($taken[$alias])) {
            $alias = $class . $suffix;
            ++$suffix;
        }

        return $alias;
    }

    /**
     * @param list<string>             $lines
     * @param array{0: int, 1: string} $candidate [signatureIndex (0-based), indent]
     * @param list<string>             $names     display names (short name or alias)
     *
     * @return null|array{0: int, 1: int, 2: list<string>} [startIdx, endIdx, replacementLines]
     */
    private function editForCandidate(array $lines, array $candidate, array $names): ?array
    {
        [$signatureIndex, $indent]          = $candidate;
        $scan                               = $this->scanAbove($lines, $signatureIndex);
        [$docStart, $docEnd, $attributeTop] = $scan;

        if (null !== $docStart && null !== $docEnd) {
            return $this->rewriteExistingDocblock($lines, $docStart, $docEnd, $names);
        }

        if ([] === $names) {
            return null;
        }

        $position = null !== $attributeTop ? $attributeTop : $signatureIndex;
        $block    = [$indent . '/**'];

        foreach ($names as $name) {
            $block[] = $indent . ' * @throws ' . $name;
        }

        $block[] = $indent . ' */';

        return [$position, $position - 1, $block];
    }

    /**
     * @param list<string>                                   $lines
     * @param array{line: int, class: ?string, name: string} $target
     *
     * @return null|array{0: int, 1: string} [signatureIndex (0-based), indent]
     */
    private function findCandidate(array $lines, array $target): ?array
    {
        $candidate = $this->scanCandidates($lines, $target, true);

        if (null !== $candidate) {
            return $candidate;
        }

        /*
         * A trait method is reported against the class that uses the
         * trait, which does not exist in the trait file; when the
         * class-restricted pass finds nothing, match by name alone.
         */
        return $this->scanCandidates($lines, $target, false);
    }

    /**
     * Inserts the use statements after the last file-level use line (or
     * after the namespace declaration when the file has none), keeping
     * the surrounding blank lines intact.
     *
     * @param list<string> $lines
     * @param list<string> $importLines
     */
    private function insertImportLines(array $lines, array $importLines): array
    {
        $lastUse        = null;
        $namespaceIndex = null;

        foreach ($lines as $index => $line) {
            if (1 === preg_match('/^use\s/', $line)) {
                $lastUse = $index;
            }

            if (null === $namespaceIndex && 1 === preg_match(self::NAMESPACE_PATTERN, $line, $match)) {
                $namespaceIndex = $index;
            }
        }

        if (null === $lastUse) {
            if (null === $namespaceIndex) {
                return $lines;
            }

            $position = $namespaceIndex + 1;

            if (1 === preg_match('/^\s*$/', $lines[$position] ?? '')) {
                ++$position;
            }

            array_splice($lines, $position, 0, [...$importLines, '']);

            return $lines;
        }

        $position = $lastUse + 1;
        $payload  = 1 === preg_match('/^\s*$/', $lines[$position] ?? '')
            ? $importLines
            : [...$importLines, ''];

        array_splice($lines, $position, 0, $payload);

        return $lines;
    }

    /**
     * The line after which the @throws lines are inserted when the
     * docblock has no @throws yet: after the last content line (or after
     * the opening /** of an empty docblock).
     *
     * @param list<string> $lines
     */
    private function insertionPosition(array $lines, int $docStart, int $docEnd): int
    {
        $lastContent = null;

        foreach (range($docStart, $docEnd) as $index) {
            $line = $lines[$index];

            if (
                1 === preg_match('/^\s*\/\*\*/', $line)
                || 1 === preg_match('/^\s*\*\/\s*$/', $line)
                || 1 === preg_match('/^\s*\*\s*$/', $line)
            ) {
                continue;
            }

            $lastContent = $index;
        }

        return null === $lastContent ? $docStart + 1 : $lastContent + 1;
    }

    /**
     * Collects the namespace, the used short names and the file-level use
     * lines; an unparseable use statement (multi or group imports) marks
     * the file for the FQCN fallback.
     *
     * @param list<string> $lines
     * @return array{
     *   namespace: ?string,
     *   unparseable: bool,
     *   takenShorts: array<string, true>,
     *   byFqcn: array<string, array{kind: string, short: string}>,
     *   lastUse: ?int,
     * }
     */
    private function parseUses(array $lines): array
    {
        $namespace   = null;
        $unparseable = false;
        $takenShorts = [];
        $byFqcn      = [];
        $lastUse     = null;

        $usePattern = '/^\s*use\s+(?<kind>function\s+|const\s+)?(?<name>[A-Za-z0-9_\\\\]+)(?:\s+as\s+(?<alias>[A-Za-z0-9_]+))?\s*;/';

        foreach ($lines as $index => $line) {
            if (null === $namespace && 1 === preg_match(self::NAMESPACE_PATTERN, $line, $match)) {
                $namespace = $match[1];
            }

            if (1 !== preg_match('/^\s*use\s+/', $line)) {
                continue;
            }

            if (1 !== preg_match($usePattern, $line, $match)) {
                $unparseable = true;

                continue;
            }

            $position = strrpos($match['name'], '\\');
            $fqcn     = $match['name'];
            $alias    = $match['alias'] ?? '';
            $short    = '' !== $alias
                ? $alias
                : (false === $position ? $fqcn : substr($fqcn, $position + 1));

            $takenShorts[$short] = true;
            $byFqcn[$fqcn]       = [
                'kind'  => '' === ($match['kind'] ?? '') ? 'class' : trim($match['kind']),
                'short' => $short,
            ];

            if (1 === preg_match('/^use\s/', $line)) {
                $lastUse = $index;
            }
        }

        return [
            'byFqcn'      => $byFqcn,
            'lastUse'     => $lastUse,
            'namespace'   => $namespace,
            'takenShorts' => $takenShorts,
            'unparseable' => $unparseable,
        ];
    }

    /**
     * Resolves the display name and the missing use statements for the
     * exception FQCNs.
     *
     * An exception from the own namespace needs no import, an already
     * imported one is reused, a colliding short name gets a descriptive
     * alias. Without a namespace (or with unparseable imports) the FQCN
     * with a leading backslash stays the only safe form.
     *
     * @param list<string> $lines
     * @param list<string> $names exception FQCNs without a leading backslash
     * @return array{display: array<string, string>, lines: list<string>}
     */
    private function resolveImports(array $lines, array $names): array
    {
        if ([] === $names) {
            return ['display' => [], 'lines' => []];
        }

        $uses        = $this->parseUses($lines);
        $display     = [];
        $importLines = [];

        foreach ($names as $name) {
            $position = strrpos($name, '\\');
            $short    = false === $position ? $name : substr($name, $position + 1);

            if (null === $uses['namespace'] || true === $uses['unparseable']) {
                $display[$name] = '\\' . $name;

                continue;
            }

            if ($uses['namespace'] . '\\' . $short === $name) {
                $display[$name] = $short;

                continue;
            }

            if (isset($uses['byFqcn'][$name]) && 'class' === $uses['byFqcn'][$name]['kind']) {
                $display[$name] = $uses['byFqcn'][$name]['short'];

                continue;
            }

            if (!isset($uses['takenShorts'][$short])) {
                $display[$name] = $short;
                $importLines[]  = 'use ' . $name . ';';

                continue;
            }

            $alias = $this->buildAlias($name, $uses['takenShorts']);

            $display[$name] = $alias;
            $importLines[]  = 'use ' . $name . ' as ' . $alias . ';';
        }

        usort($importLines, 'strcasecmp');

        return ['display' => $display, 'lines' => $importLines];
    }

    /**
     * @param list<string> $lines
     * @param list<string> $names
     * @return null|array{0: int, 1: int, 2: list<string>}
     */
    private function rewriteExistingDocblock(array $lines, int $docStart, int $docEnd, array $names): ?array
    {
        if (1 !== preg_match('/^(\s*)/', $lines[$docStart], $indentMatch)) {
            return null;
        }

        $indent = $indentMatch[1];
        $end    = $docEnd;

        if ($docStart === $docEnd) {
            /*
             * A single-line docblock: expand it in a local copy so the
             * multi-line logic below applies; the edit still replaces
             * the original single line.
             */
            if (1 !== preg_match('/^\s*\/\*\*(.*)\*\/\s*$/', $lines[$docStart], $single)) {
                return null;
            }

            $inner = trim($single[1]);
            $parts = '' === $inner ? [] : preg_split('/ (?=@\w)/', $inner);

            if (1 < count($parts)) {
                $allTags = true;

                foreach ($parts as $part) {
                    if (1 !== preg_match('/^@\w+/', $part)) {
                        $allTags = false;

                        break;
                    }
                }

                if (false === $allTags) {
                    $parts = [$inner];
                }
            }

            $expanded = [$indent . '/**'];

            foreach ($parts as $part) {
                $expanded[] = $indent . ' * ' . $part;
            }

            $expanded[] = $indent . ' */';

            array_splice($lines, $docStart, 1, $expanded);
            $docEnd += count($expanded) - 1;
        }

        $throwIndices  = [];
        $hasPhpStanTag = false;

        foreach (range($docStart, $docEnd) as $index) {
            if (1 === preg_match('/^\s*\*\s*@(phpstan-)?throws(\s|$)/', $lines[$index], $tagMatch)) {
                $throwIndices[] = $index;
                $hasPhpStanTag  = $hasPhpStanTag || 'phpstan' === $tagMatch[1];
            }
        }

        $tag        = $hasPhpStanTag ? 'phpstan-throws' : 'throws';
        $throwLines = [];

        foreach ($names as $name) {
            $throwLines[] = $indent . ' * @' . $tag . ' ' . $name;
        }

        if ([] === $names) {
            $block = [];

            foreach (range($docStart, $docEnd) as $index) {
                if (!in_array($index, $throwIndices, true)) {
                    $block[] = $lines[$index];
                }
            }

            // drop a dangling blank star directly above the closing */
            if (
                2 <= count($block)
                && 1 === preg_match('/^\s*\*\s*$/', $block[count($block) - 2])
                && 1 === preg_match('/^\s*\*\/\s*$/', $block[count($block) - 1])
            ) {
                array_splice($block, count($block) - 2, 1);
            }

            $hasContent = false;

            foreach ($block as $line) {
                if (
                    1 === preg_match('/^\s*\/\*\*/', $line)
                    || 1 === preg_match('/^\s*\*\/\s*$/', $line)
                    || 1 === preg_match('/^\s*\*\s*$/', $line)
                ) {
                    continue;
                }

                $hasContent = true;

                break;
            }

            return [$docStart, $end, $hasContent ? $block : []];
        }

        $position = [] === $throwIndices ? $this->insertionPosition($lines, $docStart, $docEnd) : $throwIndices[0];
        $block    = [];
        $inserted = false;

        foreach (range($docStart, $docEnd) as $index) {
            if (in_array($index, $throwIndices, true)) {
                if (!$inserted) {
                    $block    = [...$block, ...$throwLines];
                    $inserted = true;
                }

                continue;
            }

            if ([] === $throwIndices && $index === $position) {
                $last          = [] === $block ? null : $block[count($block) - 1];
                $lastIsBlank   = null !== $last && 1 === preg_match('/^\s*\*\s*$/', $last);
                $lastIsOpening = null !== $last && 1 === preg_match('/^\s*\/\*\*/', $last);
                $nextIsBlank   = 1 === preg_match('/^\s*\*\s*$/', $lines[$index]);

                if (null !== $last && !$lastIsBlank && !$lastIsOpening && !$nextIsBlank) {
                    $block[] = $indent . ' *';
                }

                $block = [...$block, ...$throwLines];
            }

            $block[] = $lines[$index];
        }

        return [$docStart, $end, $block];
    }

    /**
     * Scans the lines above the signature for a docblock and the attribute
     * lines between the docblock and the signature.
     *
     * @param list<string> $lines
     * @return array{0: ?int, 1: ?int, 2: ?int} [docStart, docEnd, attributeTop] (0-based)
     */
    private function scanAbove(array $lines, int $signatureIndex): array
    {
        $docStart     = null;
        $docEnd       = null;
        $attributeTop = null;

        $index = $signatureIndex - 1;

        while (0 <= $index) {
            $line = $lines[$index];

            if (1 === preg_match('/^\s*\/\*\*.*\*\/\s*$/', $line)) {
                $docStart = $index;
                $docEnd   = $index;

                break;
            }

            if (1 === preg_match('/^\s*\/\*\*/', $line)) {
                $docStart = $index;

                break;
            }

            if (1 === preg_match('/^\s*\*\/\s*$/', $line)) {
                if (null === $docEnd) {
                    $docEnd = $index;
                }

                --$index;

                continue;
            }

            if (1 === preg_match('/^\s*\*/', $line)) {
                if (null !== $docEnd) {
                    --$index;

                    continue;
                }

                break;
            }

            if (1 === preg_match('/^\s*#\[/', $line)) {
                $depth  = 0;
                $cursor = $index;

                do {
                    $depth += substr_count($lines[$cursor], '[') - substr_count($lines[$cursor], ']');
                    --$cursor;
                } while (0 <= $cursor && 0 < $depth);

                $attributeTop = $cursor + 1;
                $index        = $cursor;

                continue;
            }

            break;
        }

        return [$docStart, $docEnd, $attributeTop];
    }

    /**
     * @param list<string>                                   $lines
     * @param array{line: int, class: ?string, name: string} $target
     *
     * @return null|array{0: int, 1: string} [signatureIndex (0-based), indent]
     */
    private function scanCandidates(array $lines, array $target, bool $matchClass): ?array
    {
        $shortClass     = null === $target['class'] ? null : ltrim((string) strrchr($target['class'], '\\'), '\\');
        $best           = null;
        $class          = null;
        $namespace      = null;
        $heredocTag     = null;
        $inBlockComment = false;
        $inString       = null;

        foreach ($lines as $index => $line) {
            if (null !== $heredocTag) {
                /*
                 * heredoc content is data, not code - only its terminator
                 * matters (optionally followed by a semicolon)
                 */
                if (1 === preg_match('/^\s*' . preg_quote($heredocTag, '/') . '[ \t]*;?[ \t]*$/D', $line)) {
                    $heredocTag = null;
                }

                continue;
            }

            [$line, $inBlockComment, $inString] = self::maskInline($line, $inBlockComment, $inString);

            if (1 === preg_match(self::HEREDOC_PATTERN, $line, $match)) {
                $heredocTag = $match[2];

                continue;
            }

            if (1 === preg_match(self::SIGNATURE_PATTERN, $line, $match)) {
                $candidateClass = '' === $match[1] ? null : $class;

                if (null === $target['class']) {
                    // functions are named by their FQN in the message
                    $candidateName = null === $namespace ? $match[2] : $namespace . '\\' . $match[2];
                    $nameMatches   = $candidateName === $target['name'];
                    $classMatches  = null === $candidateClass;
                } else {
                    $nameMatches  = $match[2] === $target['name'];
                    $classMatches = true === $matchClass ? $candidateClass === $shortClass : true;
                }

                if ($nameMatches && $classMatches) {
                    $diff = abs($index + 1 - $target['line']);

                    if (null === $best || $diff < $best[0] || ($diff === $best[0] && $index < $best[1])) {
                        $best = [$diff, $index, $match[1]];
                    }
                }

                continue;
            }

            if (1 === preg_match(self::CLASS_PATTERN, $line, $match)) {
                $class = $match[1];

                continue;
            }

            if (1 === preg_match(self::NAMESPACE_PATTERN, $line, $match)) {
                $namespace = $match[1];

                continue;
            }

            if ('}' === $line) {
                $class = null;
            }
        }

        return null === $best ? null : [$best[1], $best[2]];
    }
}
