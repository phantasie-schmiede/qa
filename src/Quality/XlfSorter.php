<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

use DirectoryIterator;

use RuntimeException;
use function array_slice;
use function count;
use function in_array;

/**
 * Keeps the <trans-unit> elements of XLIFF language files sorted by id
 * (case-insensitive) within each <body>.
 *
 * The sorter is line based: a block runs from the opening <trans-unit>
 * line to the closing </trans-unit> line. The block text is preserved
 * exactly, only the order changes; blank lines between blocks are carried
 * along with the block that precedes them. A body that contains anything
 * besides trans-unit blocks and blank lines is reported, not touched.
 */
final class XlfSorter
{
    private const string BLOCK_END   = '/^\s*<\/trans-unit>\s*$/';
    private const string BLOCK_START = '/^\s*<trans-unit\s+[^>]*id="([^"]+)"[^>]*>\s*$/';
    private const string BODY_END    = '/^\s*<\/body>\s*$/';
    private const string BODY_START  = '/^\s*<body>\s*$/';

    /**
     * Finds all *.xlf files below the repository root, excluding the
     * build, dependency and version control directories.
     *
     * @return list<string>
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
     *
     * @return array{changed: list<string>, skipped: list<string>}
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
     *
     * @return array{unsorted: list<string>, problems: list<string>}
     *             unsorted: files with at least one unsorted body
     *             problems: unreadable files and bodies the sorter refuses to touch
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
     * @param list<string> $lines
     * @return list<array{start: int, end: int, blocks: list<array{start: int, end: int, id: string}>}>
     */
    private function bodies(array $lines): array
    {
        $bodies     = [];
        $bodyStart  = null;
        $blocks     = [];
        $blockStart = null;
        $blockId    = null;

        foreach ($lines as $index => $line) {
            if (1 === preg_match(self::BLOCK_START, $line, $match)) {
                $blockStart = $index;
                $blockId    = $match[1];

                continue;
            }

            if (null !== $blockStart && 1 === preg_match(self::BLOCK_END, $line)) {
                $blocks[]   = ['end' => $index, 'id' => $blockId, 'start' => $blockStart];
                $blockStart = null;
                $blockId    = null;

                continue;
            }

            if (1 === preg_match(self::BODY_START, $line)) {
                if (null !== $bodyStart) {
                    $bodies[] = ['start' => $bodyStart, 'end' => $index - 1, 'blocks' => $blocks];
                    $blocks   = [];
                }

                $bodyStart = $index;

                continue;
            }

            if (1 === preg_match(self::BODY_END, $line) && null !== $bodyStart) {
                $bodies[]  = ['blocks' => $blocks, 'end' => $index, 'start' => $bodyStart];
                $bodyStart = null;
                $blocks    = [];
            }
        }

        if (null !== $bodyStart) {
            $bodies[] = ['start' => $bodyStart, 'end' => count($lines) - 1, 'blocks' => $blocks];
        }

        return $bodies;
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
            if ([] === $body['blocks']) {
                continue;
            }

            if (null !== $problem = $this->validateBody($lines, $body)) {
                $problems[] = $problem;

                continue;
            }

            $ids    = array_map(static fn(array $block): string => $block['id'], $body['blocks']);
            $sorted = $ids;
            usort($sorted, static fn(string $a, string $b): int => strcasecmp($a, $b));

            if ($ids === $sorted) {
                continue;
            }

            $units = [];

            foreach ($body['blocks'] as $index => $block) {
                $nextStart = $index + 1 < count($body['blocks'])
                    ? $body['blocks'][$index + 1]['start']
                    : $body['end'];

                $unit = array_slice($lines, $block['start'], $block['end'] - $block['start'] + 1);

                for ($i = $block['end'] + 1; $i < $nextStart; ++$i) {
                    $unit[] = $lines[$i];
                }

                $units[] = ['id' => $block['id'], 'lines' => $unit];
            }

            usort($units, static fn(array $a, array $b): int => strcasecmp($a['id'], $b['id']));

            $region = [];

            foreach ($units as $unit) {
                $region = [...$region, ...$unit['lines']];
            }

            $edits[] = [
                $body['start'] + 1,
                $body['end'] - 1,
                array_merge(
                    array_slice($lines, $body['start'] + 1, $body['blocks'][0]['start'] - $body['start'] - 1),
                    $region,
                ),
            ];
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

    /**
     * @param list<string>                                                                       $lines
     * @param array{start: int, end: int, blocks: list<array{start: int, end: int, id: string}>} $body
     */
    private function validateBody(array $lines, array $body): ?string
    {
        $covered = [];

        foreach ($body['blocks'] as $block) {
            for ($i = $block['start']; $i <= $block['end']; ++$i) {
                $covered[$i] = true;
            }
        }

        for ($i = $body['start'] + 1; $body['end'] - 1 >= $i; ++$i) {
            if (isset($covered[$i])) {
                continue;
            }

            if ('' === trim($lines[$i])) {
                continue;
            }

            return 'body with non-block content at line ' . ($i + 1) . ' is not sorted';
        }

        return null;
    }
}
