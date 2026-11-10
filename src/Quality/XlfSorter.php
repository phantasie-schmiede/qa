<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

use DirectoryIterator;
use RuntimeException;

use function array_merge;
use function array_slice;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_file;
use function preg_match;
use function sort;
use function str_ends_with;
use function strcasecmp;
use function trim;
use function usort;

/**
 * Keeps the <trans-unit> and <group> elements of XLIFF language files
 * sorted by their id attribute (case-insensitive) within each <body>.
 *
 * A body is a list of items - trans-units and groups - sorted by their
 * id; the items of a group form the same kind of nested list, so groups
 * within groups are sorted as well.
 *
 * The sorter is line based: an item runs from its opening tag (which may
 * span several lines) to its closing tag. The item text is preserved
 * exactly, only the order changes; blank lines between items are carried
 * along with the item that precedes them. A container that contains
 * anything besides complete items and blank lines is reported, not
 * touched.
 */
final class XlfSorter
{
    private const string BODY_END    = '/^\s*<\/body>\s*$/';
    private const string BODY_START  = '/^\s*<body>\s*$/';
    private const string GROUP_CLOSE = '/^\s*<\/group>\s*$/';
    private const string GROUP_OPEN  = '/^\s*<group(?=[\s>\/]|$)/';
    private const string UNIT_CLOSE  = '/^\s*<\/trans-unit>\s*$/';
    private const string UNIT_OPEN   = '/^\s*<trans-unit(?=[\s>\/]|$)/';

    /**
     * Finds all *.xlf files below the repository root, excluding the
     * build, dependency and version control directories.
     *
     * @return list<string>
     * @throws RuntimeException
     */
    public static function findFiles(string $root): array
    {
        $excluded = ['.Build', '.git', 'node_modules', 'vendor', 'var'];
        $files    = [];
        $stack    = [$root];

        while ([] !== $stack) {
            $directory = array_pop($stack);
            $iterator  = new DirectoryIterator($directory);

            foreach ($iterator as $item) {
                if ($item->isDot()) {
                    continue;
                }

                if ($item->isDir()) {
                    if (!in_array($item->getFilename(), $excluded, true)) {
                        $stack[] = $item->getPathname();
                    }

                    continue;
                }

                if (str_ends_with($item->getFilename(), '.xlf')) {
                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string> the lines without the trailing empty element
     */
    private static function readLines(string $file, bool &$readable): array
    {
        $content = is_file($file) ? file_get_contents($file) : false;

        if (false === $content) {
            $readable = false;

            return [];
        }

        $readable = true;
        $lines    = explode("\n", $content);

        if ('' === $lines[count($lines) - 1]) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param list<string> $files
     * @return array{changed: list<string>, skipped: list<string>}
     * @throws RuntimeException
     */
    public function apply(array $files): array
    {
        $changed = [];
        $skipped = [];

        foreach ($files as $file) {
            $readable = true;
            $lines    = self::readLines($file, $readable);

            if (false === $readable) {
                $skipped[] = $file . ': unreadable';

                continue;
            }

            $problems = [];
            $newLines = $this->sortLines($lines, $problems);

            foreach ($problems as $problem) {
                $skipped[] = $file . ': ' . $problem;
            }

            if ($newLines === $lines) {
                continue;
            }

            if (false === file_put_contents($file, implode("\n", $newLines) . "\n")) {
                throw new RuntimeException('could not write ' . $file);
            }

            $changed[] = $file;
        }

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * @param list<string> $files
     * @return array{unsorted: list<string>, problems: list<string>}
     *             unsorted: files with at least one unsorted body
     *             problems: unreadable files and containers the sorter
     *             refuses to touch
     */
    public function check(array $files): array
    {
        $unsorted = [];
        $problems = [];

        foreach ($files as $file) {
            $readable = true;
            $lines    = self::readLines($file, $readable);

            if (false === $readable) {
                $problems[] = $file . ': unreadable';

                continue;
            }

            $bodyProblems = [];

            if ($this->sortLines($lines, $bodyProblems) !== $lines) {
                $unsorted[] = $file;
            }

            foreach ($bodyProblems as $problem) {
                $problems[] = $file . ': ' . $problem;
            }
        }

        return ['problems' => $problems, 'unsorted' => $unsorted];
    }

    /**
     * The <body> regions of the file, by line index. A body that is never
     * closed runs to the end of the file; a body opened inside another
     * body closes the outer one on the line before it.
     *
     * @param list<string> $lines
     * @return list<array{start: int, end: int}>
     */
    private function bodies(array $lines): array
    {
        $bodies    = [];
        $bodyStart = null;

        foreach ($lines as $index => $line) {
            if (1 === preg_match(self::BODY_START, $line)) {
                if (null !== $bodyStart) {
                    $bodies[] = ['start' => $bodyStart, 'end' => $index - 1];
                }

                $bodyStart = $index;

                continue;
            }

            if (1 === preg_match(self::BODY_END, $line) && null !== $bodyStart) {
                $bodies[]  = ['end' => $index, 'start' => $bodyStart];
                $bodyStart = null;
            }
        }

        if (null !== $bodyStart) {
            $bodies[] = ['start' => $bodyStart, 'end' => count($lines) - 1];
        }

        return $bodies;
    }

    /**
     * The id attribute of the opening tag spanning lines $start to $end,
     * or null when the tag carries no id="..." attribute.
     *
     * @param list<string> $lines
     */
    private function openingId(array $lines, int $start, int $end): ?string
    {
        $tag = implode(' ', array_slice($lines, $start, $end - $start + 1));

        if (1 === preg_match('/\bid="([^"]+)"/', $tag, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * The last line of the (possibly multi-line) opening tag that starts
     * at $start - the first line that, trimmed, ends with ">" - or -1
     * when no such line exists before $limit.
     *
     * @param list<string> $lines
     */
    private function openTagEnd(array $lines, int $start, int $limit): int
    {
        for ($i = $start; $i < $limit; ++$i) {
            if (str_ends_with(trim($lines[$i]), '>')) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Parses the items (trans-units and groups) of one container - a
     * body or a group - into a nested list. Any line that is neither
     * blank nor part of a complete item is collected as a problem; the
     * items are only reliable when the problem list stays empty.
     *
     * For a group container the closing </group> line is discovered by
     * the scan; for a body container it is the limit of the scan.
     *
     * @param list<string> $lines
     * @param int          $start         first line after the container's opening tag
     * @param int          $limit         first line the container cannot use (a body: the
     *                                    </body> line; a group: one past the end of the
     *                                    file)
     * @param int          $containerOpen first line of the container's own
     *                                    opening tag, for the unclosed-group
     *                                    message; unused for a body
     * @return array{items: list<XlfItem>, close: int, problems: list<string>}
     */
    private function parseItems(array $lines, int $start, int $limit, int $containerOpen, bool $group): array
    {
        $items    = [];
        $problems = [];
        $close    = $limit;
        $closed   = false;
        $i        = $start;

        while ($i < $limit) {
            $line = $lines[$i];

            if ('' === trim($line)) {
                ++$i;

                continue;
            }

            if (1 === preg_match(self::GROUP_CLOSE, $line)) {
                if (!$group) {
                    $problems[] = 'body with non-block content at line ' . ($i + 1) . ' is not sorted';

                    break;
                }

                $close  = $i;
                $closed = true;

                break;
            }

            if (1 === preg_match(self::UNIT_OPEN, $line)) {
                $open = $this->openTagEnd($lines, $i, $limit);
                $id   = 0 <= $open ? $this->openingId($lines, $i, $open) : null;

                if (0 > $open || null === $id) {
                    $problems[] = (0 > $open ? 'unclosed trans-unit' : 'trans-unit without id')
                        . ' at line ' . ($i + 1) . ' is not sorted';

                    break;
                }

                if (str_ends_with(trim($lines[$open]), '/>')) {
                    $items[] = new XlfItem('trans-unit', $id, $i, $open, $open, []);
                    $i       = $open + 1;

                    continue;
                }

                if ($open === $i && 1 === preg_match('/<\/trans-unit>\s*$/', $lines[$i])) {
                    // the unit opens and closes on one line
                    $items[] = new XlfItem('trans-unit', $id, $i, $open, $open, []);
                    $i       = $open + 1;

                    continue;
                }

                $end = $open + 1;

                while ($end < $limit && 1 !== preg_match(self::UNIT_CLOSE, $lines[$end])) {
                    ++$end;
                }

                if ($end >= $limit) {
                    $problems[] = 'unclosed trans-unit at line ' . ($i + 1) . ' is not sorted';

                    break;
                }

                $items[] = new XlfItem('trans-unit', $id, $i, $open, $end, []);
                $i       = $end + 1;

                continue;
            }

            if (1 === preg_match(self::GROUP_OPEN, $line)) {
                $open = $this->openTagEnd($lines, $i, $limit);
                $id   = 0 <= $open ? $this->openingId($lines, $i, $open) : null;

                if (0 > $open || null === $id) {
                    $problems[] = (0 > $open ? 'unclosed group' : 'group without id')
                        . ' at line ' . ($i + 1) . ' is not sorted';

                    break;
                }

                if (str_ends_with(trim($lines[$open]), '/>')) {
                    $items[] = new XlfItem('group', $id, $i, $open, $open, []);
                    $i       = $open + 1;

                    continue;
                }

                if ($open === $i && str_ends_with(trim($lines[$i]), '</group>')) {
                    // the group opens and closes on one line
                    $items[] = new XlfItem('group', $id, $i, $open, $open, []);
                    $i       = $open + 1;

                    continue;
                }

                $inner = $this->parseItems($lines, $open + 1, count($lines), $i, true);

                if ([] !== $inner['problems']) {
                    $problems = array_merge($problems, $inner['problems']);

                    break;
                }

                $items[] = new XlfItem('group', $id, $i, $open, $inner['close'], $inner['items']);
                $i       = $inner['close'] + 1;

                continue;
            }

            $problems[] = ($group ? 'group' : 'body') . ' with non-block content at line ' . ($i + 1) . ' is not sorted';

            break;
        }

        if ($group && !$closed && [] === $problems) {
            $problems[] = 'unclosed group at line ' . ($containerOpen + 1) . ' is not sorted';
        }

        return ['close' => $close, 'items' => $items, 'problems' => $problems];
    }

    /**
     * The lines of the container's interior - the lines after its opening
     * tag up to its closing line - re-ordered: the leading blank lines,
     * then the items sorted by id, each item carrying the blank lines
     * that follow it.
     *
     * @param list<string>  $lines
     * @param int           $start first line after the container's opening tag
     * @param int           $end   the container's closing line
     * @param list<XlfItem> $items
     * @return list<string>
     */
    private function renderInterior(array $lines, int $start, int $end, array $items): array
    {
        if ([] === $items) {
            return array_slice($lines, $start, $end - $start);
        }

        $units = [];

        foreach ($items as $index => $item) {
            $nextStart = $index + 1 < count($items) ? $items[$index + 1]->start : $end;
            $units[]   = ['id' => $item->id, 'lines' => $this->renderItem($lines, $item, $nextStart)];
        }

        usort($units, static fn(array $a, array $b): int => strcasecmp($a['id'], $b['id']));

        $region = array_slice($lines, $start, $items[0]->start - $start);

        foreach ($units as $unit) {
            $region = array_merge($region, $unit['lines']);
        }

        return $region;
    }

    /**
     * The lines of one item in its new position: the opening tag, the
     * interior - verbatim for a trans-unit, recursively sorted for a
     * group -, the closing line, and the blank lines up to $nextStart.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function renderItem(array $lines, XlfItem $item, int $nextStart): array
    {
        $rendered = array_slice($lines, $item->start, $item->open - $item->start + 1);

        if ($item->open < $item->end) {
            if ('group' === $item->type) {
                $rendered = array_merge(
                    $rendered,
                    $this->renderInterior($lines, $item->open + 1, $item->end, $item->children),
                );
            } else {
                $rendered = array_merge($rendered, array_slice($lines, $item->open + 1, $item->end - $item->open - 1));
            }

            $rendered[] = $lines[$item->end];
        }

        for ($i = $item->end + 1; $i < $nextStart; ++$i) {
            $rendered[] = $lines[$i];
        }

        return $rendered;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $problems
     * @return list<string>
     */
    private function sortLines(array $lines, array &$problems): array
    {
        $edits = [];

        foreach ($this->bodies($lines) as $body) {
            $parsed = $this->parseItems($lines, $body['start'] + 1, $body['end'], $body['start'], false);

            if ([] === $parsed['items'] && [] === $parsed['problems']) {
                continue;
            }

            foreach ($parsed['problems'] as $problem) {
                $problems[] = $problem;
            }

            if ([] !== $parsed['problems']) {
                continue;
            }

            $original = array_slice($lines, $body['start'] + 1, $body['end'] - $body['start'] - 1);
            $replaced = $this->renderInterior($lines, $body['start'] + 1, $body['end'], $parsed['items']);

            if ($original === $replaced) {
                continue;
            }

            $edits[] = [$body['start'] + 1, $body['end'] - 1, $replaced];
        }

        if ([] === $edits) {
            return $lines;
        }

        $newLines = $lines;
        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($edits as [$start, $end, $replacement]) {
            array_splice($newLines, $start, $end - $start + 1, $replacement);
        }

        return $newLines;
    }
}
