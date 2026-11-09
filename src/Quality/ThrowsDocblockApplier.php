<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

use RuntimeException;
use ValueError;

use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function ltrim;
use function max;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function substr_count;
use function trim;
use function usort;

/**
 * Rewrites the @throws lines of the methods and functions named in the
 * throws rule messages (psbits.throwsDocblock for the lint rule,
 * psbits.throwsDocblockAudit for the fixer's audit rule): one name per
 * line, sorted case-insensitively, no exception lines at all when the
 * target set is empty.
 *
 * The applier is line based. It locates the signature named in the rule
 * message and rewrites, completes or removes the docblock above it (a
 * docblock sits directly above the signature or the attributes above it).
 *
 * Two write modes:
 * - additive (the per-core fix loop): the @throws lines of the docblock
 *   are extended by the names of the message; existing lines (including
 *   their "// t3:N" markers) are preserved, new lines carry no marker.
 * - exact (the final merge): the @throws lines are replaced by the
 *   names of the message, which carry their markers (a line seen by a
 *   single supported core is marked "// t3:N"), and lines the message
 *   does not carry are removed.
 *
 * Every rewritten docblock follows the house layout: the description,
 * one blank star line, then all tags sorted by tag name without blank
 * lines in between - the @param lines keep their parameter order and
 * the @throws lines their name-sorted order.
 *
 * The @throws lines use the short name of the exception and the missing
 * use statement is added to the file (an exception from the own namespace
 * needs no import; a colliding short name gets a descriptive alias);
 * the use statements orphaned by removed lines are dropped by the
 * php-cs-fixer run (no_unused_imports).
 */
final class ThrowsDocblockApplier
{
    private const string CLASS_PATTERN       = '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|trait|enum)\s+([A-Za-z_]\w*)/';
    private const string HEREDOC_PATTERN     = '/<<<[ \t]*([\'"]?)([A-Za-z_]\w*)\1[ \t]*$/D';
    private const string MESSAGE_PATTERN     = '#^(?:Method (?<class>[A-Za-z0-9_\\\\]+)::(?<name>[A-Za-z_][A-Za-z0-9_]*)\(\)|Function (?<function>[A-Za-z0-9_\\\\]+)\(\)) @throws do not match the thrown exceptions: expected (?<expected>.+), declared (?<declared>.+)\.$#';
    private const string NAME_VALUE_PATTERN  = '/^(?<name>\\\\?\S+)(?:\s+\/\/\s*t3:(?<core>13|14))?\s*$/';
    private const string NAMESPACE_PATTERN   = '/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/';
    private const string SIGNATURE_PATTERN   = '/^(\s*)(?:(?:final|abstract)\s+)*(?:(?:public|protected|private)\s+)*(?:static\s+)?function\s+([A-Za-z_]\w*)\s*\(/';
    private const string THROW_ENTRY_PATTERN = '/^@(?:phpstan-)?throws\s+(?<name>\S+)(?:\s+\/\/\s*t3:(?<core>13|14))?\s*$/';

    /**
     * Parses a rule message; the name list may carry trailing marker
     * comments (the merge messages of the throws fixer do).
     *
     * @return null|array{
     *   class: ?string,
     *   name: string,
     *   expected: list<array{name: string, marker: ?string}>,
     *   declared: list<string>,
     * }
     */
    public static function parseMessage(string $message): ?array
    {
        if (1 !== preg_match(self::MESSAGE_PATTERN, $message, $matches)) {
            return null;
        }

        $declared = [];

        if ('none' !== $matches['declared']) {
            foreach (explode(', ', $matches['declared']) as $name) {
                $declared[] = ltrim($name, '\\');
            }

            usort($declared, static fn(string $a, string $b): int => strcasecmp($a, $b));
        }

        return [
            'class'    => '' === $matches['class'] ? null : $matches['class'],
            'declared' => $declared,
            'expected' => self::parseNames($matches['expected']),
            'name'     => '' !== $matches['name'] ? $matches['name'] : $matches['function'],
        ];
    }

    private static function charAt(string $line, int $position): ?string
    {
        return $position < strlen($line) ? $line[$position] : null;
    }

    /**
     * @return string the trailing marker comment or an empty string
     */
    private static function markerSuffix(?string $marker): string
    {
        return null === $marker ? '' : ' // t3:' . $marker;
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
     * Parses the name list of a rule message; a name may carry a trailing
     * marker comment (the merge messages of the throws fixer do).
     *
     * @return list<array{name: string, marker: ?string}> names without a
     *                                                    leading backslash,
     *                                                    case-insensitively
     *                                                    sorted
     */
    private static function parseNames(string $names): array
    {
        $items = [];

        if ('none' !== $names) {
            foreach (explode(', ', $names) as $name) {
                if (1 === preg_match(self::NAME_VALUE_PATTERN, $name, $match)) {
                    $items[] = [
                        'marker' => $match['core'] ?? null,
                        'name'   => ltrim($match['name'], '\\'),
                    ];
                }
            }
        }

        usort($items, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * @param list<array{line: int, message: string}> $entries rule messages for one file
     * @param bool                                    $exact   true for the final merge (the
     *                                                         exception lines become the
     *                                                         message's names, the lines the
     *                                                         message does not carry are
     *                                                         removed); false for the per-core
     *                                                         fix loop (the message's names
     *                                                         are added, everything else is
     *                                                         kept)
     * @return array{fixed: int, skipped: list<string>}
     * @throws RuntimeException
     * @throws ValueError
     */
    public function apply(string $filePath, array $entries, bool $exact = false): array
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

            $markers = [];

            foreach ($parsed['expected'] as $item) {
                $markers[$item['name']] = $item['marker'];
            }

            $targets[] = [
                'class'   => $parsed['class'],
                'line'    => $entry['line'],
                'markers' => $markers,
                'name'    => $parsed['name'],
                'names'   => array_map(static fn(array $item): string => $item['name'], $parsed['expected']),
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
                $bySignature[$candidate[0]] = ['candidate' => $candidate, 'markers' => [], 'names' => []];
            }

            $bySignature[$candidate[0]]['names']   = array_merge($bySignature[$candidate[0]]['names'], $target['names']);
            $bySignature[$candidate[0]]['markers'] = array_merge($bySignature[$candidate[0]]['markers'], $target['markers']);
        }

        $uses     = $this->parseUses($lines);
        $allNames = [];

        foreach ($bySignature as $key => $group) {
            $names   = array_values(array_unique($group['names']));
            $markers = $group['markers'];

            usort($names, static fn(string $a, string $b): int => strcasecmp($a, $b));

            if (false === $exact) {
                /*
                 * The fix loop only adds: a @throws line that the
                 * message does not carry is kept as is (it belongs to
                 * another supported core or waits for the final merge),
                 * together with its marker.
                 */
                foreach ($this->existingThrowEntries($lines, $group['candidate']) as $existing) {
                    $fqcn = $this->resolveDisplayName($existing['display'], $uses);

                    if (!in_array($fqcn, $names, true)) {
                        $names[] = $fqcn;
                        usort($names, static fn(string $a, string $b): int => strcasecmp($a, $b));
                    }

                    $markers[$fqcn] = $existing['marker'] ?? ($markers[$fqcn] ?? null);
                }
            }

            $bySignature[$key]['names']   = $names;
            $bySignature[$key]['markers'] = $markers;
            $allNames                     = array_merge($allNames, $names);
        }

        $imports = $this->resolveImports($uses, array_values(array_unique($allNames)));

        foreach ($bySignature as $group) {
            $edit = $this->editForCandidate($lines, $group['candidate'], $group['names'], $group['markers'], $imports);

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
            throw new RuntimeException('could not write ' . $filePath);
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
     * @param list<string>                                               $lines
     * @param array{0: int, 1: string}                                   $candidate [signatureIndex (0-based), indent]
     * @param list<string>                                               $names     exception FQCNs without a leading backslash
     * @param array<string, null|string>                                 $markers   marker core per FQCN or null
     * @param array{display: array<string, string>, lines: list<string>} $imports
     * @return null|array{0: int, 1: int, 2: list<string>} [startIdx, endIdx, replacementLines]
     * @throws ValueError
     */
    private function editForCandidate(array $lines, array $candidate, array $names, array $markers, array $imports): ?array
    {
        [$signatureIndex, $indent]          = $candidate;
        $scan                               = $this->scanAbove($lines, $signatureIndex);
        [$docStart, $docEnd, $attributeTop] = $scan;

        if (null !== $docStart && null !== $docEnd) {
            return $this->rewriteExistingDocblock($lines, $docStart, $docEnd, $names, $markers, $imports);
        }

        if ([] === $names) {
            return null;
        }

        $position = null !== $attributeTop ? $attributeTop : $signatureIndex;
        $block    = [$indent . '/**'];

        foreach ($names as $name) {
            $block[] = $indent . ' * @throws ' . $imports['display'][$name] . self::markerSuffix($markers[$name] ?? null);
        }

        $block[] = $indent . ' */';

        return [$position, $position - 1, $block];
    }

    /**
     * Reads the @throws / @phpstan-throws lines of the docblock above the
     * candidate; a single-line docblock is split in a local copy.
     *
     * @param list<string>             $lines
     * @param array{0: int, 1: string} $candidate [signatureIndex (0-based), indent]
     * @return list<array{display: string, marker: ?string}>
     */
    private function existingThrowEntries(array $lines, array $candidate): array
    {
        $scan = $this->scanAbove($lines, $candidate[0]);

        if (null === $scan[0] || null === $scan[1]) {
            return [];
        }

        if ($scan[0] === $scan[1]) {
            if (1 !== preg_match('/^\s*\/\*\*(.*)\*\/\s*$/', $lines[$scan[0]], $single)) {
                return [];
            }

            $parts = trim($single[1]);
            $parts = '' === $parts ? [] : preg_split('/ (?=@\w)/', $parts);
        } else {
            $parts = array_map(
                static fn(string $line): string => ltrim($line, " \t*"),
                array_slice($lines, $scan[0] + 1, max(0, $scan[1] - $scan[0] - 1)),
            );
        }

        $entries = [];

        foreach ($parts as $part) {
            if (1 === preg_match(self::THROW_ENTRY_PATTERN, $part, $match)) {
                $entries[] = [
                    'display' => $match['name'],
                    'marker'  => $match['core'] ?? null,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param list<string>                                   $lines
     * @param array{line: int, class: ?string, name: string} $target
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
     * @return list<string>
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
     * @throws ValueError
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
                'kind'  => '' === $match['kind'] ? 'class' : trim($match['kind']),
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
     * Resolves the display name of an @throws line back to the exception
     * FQCN (without leading backslash): a backslash-prefixed FQCN counts
     * as itself, an imported short name (or alias) resolves to its use
     * statement, and an unimported short name is taken from the own
     * namespace; a name without a namespace to fall back on is returned
     * unchanged.
     *
     * @param array{
     *   namespace: ?string,
     *   byFqcn: array<string, array{kind: string, short: string}>,
     * } $uses the parseUses() result
     */
    private function resolveDisplayName(string $display, array $uses): string
    {
        if (str_starts_with($display, '\\')) {
            return ltrim($display, '\\');
        }

        foreach ($uses['byFqcn'] as $fqcn => $info) {
            if ('class' === $info['kind'] && $info['short'] === $display) {
                return $fqcn;
            }
        }

        if (null !== $uses['namespace']) {
            return $uses['namespace'] . '\\' . $display;
        }

        return $display;
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
     * @param array{
     *   namespace: ?string,
     *   unparseable: bool,
     *   takenShorts: array<string, true>,
     *   byFqcn: array<string, array{kind: string, short: string}>,
     * } $uses the parseUses() result
     * @param list<string> $names exception FQCNs without a leading backslash
     * @return array{display: array<string, string>, lines: list<string>}
     * @throws ValueError
     */
    private function resolveImports(array $uses, array $names): array
    {
        if ([] === $names) {
            return ['display' => [], 'lines' => []];
        }

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
     * Normalizes the docblock at $docStart..$docEnd to the house layout:
     * the description, one blank star line, then all tags sorted by tag
     * name without blank lines in between (the @param lines keep their
     * parameter order, the @throws lines their name-sorted order). The
     * exception tags are replaced with one line per name in $names, and
     * a docblock without description and without tags is dropped.
     *
     * @param list<string>                                               $lines
     * @param list<string>                                               $names   exception FQCNs without a leading backslash
     * @param array<string, null|string>                                 $markers marker core per FQCN or null
     * @param array{display: array<string, string>, lines: list<string>} $imports
     * @return null|array{0: int, 1: int, 2: list<string>}
     * @throws ValueError
     */
    private function rewriteExistingDocblock(array $lines, int $docStart, int $docEnd, array $names, array $markers, array $imports): ?array
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

        $hasPhpStanTag = false;
        $description   = [];
        $tagChunks     = [];
        $seenTag       = false;

        if ($docStart + 1 <= $docEnd - 1) {
            foreach (range($docStart + 1, $docEnd - 1) as $index) {
                $line = $lines[$index];

                if (1 === preg_match('/^\s*\*\s*@(phpstan-)?throws(\s|$)/', $line, $throwMatch)) {
                    // replaced by $names below
                    $hasPhpStanTag = $hasPhpStanTag || 'phpstan-' === $throwMatch[1];
                    $seenTag       = true;

                    continue;
                }

                if (1 === preg_match('/^\s*\*\s*@[a-z-]+/', $line)) {
                    $tagChunks[] = [$line];
                    $seenTag     = true;

                    continue;
                }

                if (1 === preg_match('/^\s*\*\s*$/', $line)) {
                    if (false === $seenTag) {
                        $description[] = $line;
                    }

                    continue;
                }

                if (false === $seenTag) {
                    $description[] = $line;
                } elseif ([] !== $tagChunks) {
                    /*
                     * a text line below the first tag belongs to the tag
                     * chunk above it (a multi-line annotation)
                     */
                    $tagChunks[count($tagChunks) - 1][] = $line;
                }
            }
        }

        while ([] !== $description && 1 === preg_match('/^\s*\*\s*$/', $description[count($description) - 1])) {
            array_pop($description);
        }

        $tag          = $hasPhpStanTag ? 'phpstan-throws' : 'throws';
        $throwEntries = [];

        foreach ($names as $name) {
            $throwEntries[] = [$imports['display'][$name], $markers[$name] ?? null];
        }

        usort($throwEntries, static fn(array $a, array $b): int => strcasecmp($a[0], $b[0]));

        foreach ($throwEntries as [$display, $marker]) {
            $tagChunks[] = [$indent . ' * @' . $tag . ' ' . $display . self::markerSuffix($marker)];
        }

        /*
         * usort is stable since PHP 8, so equal keys keep their original
         * relative order: the @param lines stay in parameter order and
         * the @throws lines keep the name-sorted order of $names.
         */
        usort($tagChunks, static function(array $a, array $b): int {
            preg_match('/^\s*\*\s*@(?:phpstan-|psalm-)?([a-z-]+)/', $a[0], $matchA);
            preg_match('/^\s*\*\s*@(?:phpstan-|psalm-)?([a-z-]+)/', $b[0], $matchB);

            return strcasecmp((string) ($matchA[1] ?? ''), (string) ($matchB[1] ?? ''));
        });

        if ([] === $description && [] === $tagChunks) {
            return [$docStart, $end, []];
        }

        $block = [$lines[$docStart]];

        foreach ($description as $line) {
            $block[] = $line;
        }

        if ([] !== $description && [] !== $tagChunks) {
            $block[] = $indent . ' *';
        }

        foreach ($tagChunks as $chunk) {
            foreach ($chunk as $line) {
                $block[] = $line;
            }
        }

        $block[] = $lines[$docEnd];

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
                 * matters. The terminator line starts with the tag and may
                 * continue with any expression the tag is part of (a
                 * semicolon, a comma, a call argument, ...), so only the
                 * tag as a whole word on the line counts (PHP grammar).
                 */
                if (1 === preg_match('/^\s*' . preg_quote($heredocTag, '/') . '(?![A-Za-z0-9_])/', $line)) {
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
