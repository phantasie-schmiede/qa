<?php

declare(strict_types=1);

use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Quality\XlfSorter;
use Random\RandomException;

final class XlfSorterTest extends TestCase
{
    private string $root;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-xlf-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testApplyMovesGroupBeforeUnitsWithHigherIds(): void
    {
        $file   = $this->xlfFile('GroupFirst.xlf', $this->fileWith([$this->unit('message'), $this->group('day', ['day[0]', 'day[1]'])]));
        $sorted = $this->fileWith([$this->group('day', ['day[0]', 'day[1]']), $this->unit('message')]);
        $sorter = new XlfSorter();

        self::assertSame(['changed' => [$file], 'skipped' => []], $sorter->apply([$file]));
        self::assertSame($sorted . "\n", file_get_contents($file));

        // a second run changes nothing
        self::assertSame(['changed' => [], 'skipped' => []], $sorter->apply([$file]));
        self::assertSame(['problems' => [], 'unsorted' => []], $sorter->check([$file]));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testApplySortsGroupInteriorsWhenTheBodyOrderIsSorted(): void
    {
        $file   = $this->xlfFile('Interior.xlf', $this->fileWith([$this->group('day', ['day[1]', 'day[0]']), $this->unit('message')]));
        $sorted = $this->fileWith([$this->group('day', ['day[0]', 'day[1]']), $this->unit('message')]);
        $sorter = new XlfSorter();

        self::assertSame(['problems' => [], 'unsorted' => [$file]], $sorter->check([$file]));
        self::assertSame(['changed' => [$file], 'skipped' => []], $sorter->apply([$file]));
        self::assertSame($sorted . "\n", file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testApplySortsNestedGroupsRecursively(): void
    {
        $file = $this->xlfFile('Nested.xlf', $this->fileWith([
            $this->unit('zeta'),
            $this->group('outer', [], nested: [
                $this->unit('b2', '                '),
                $this->group('inner', [], indent: '                ', nested: [
                    $this->unit('x2', '                    '),
                    $this->unit('x1', '                    '),
                ]),
                $this->unit('b1', '                '),
            ]),
            $this->unit('alpha'),
        ]));
        $sorted = $this->fileWith([
            $this->unit('alpha'),
            $this->group('outer', [], nested: [
                $this->unit('b1', '                '),
                $this->unit('b2', '                '),
                $this->group('inner', [], indent: '                ', nested: [
                    $this->unit('x1', '                    '),
                    $this->unit('x2', '                    '),
                ]),
            ]),
            $this->unit('zeta'),
        ]);
        $sorter = new XlfSorter();

        self::assertSame(['changed' => [$file], 'skipped' => []], $sorter->apply([$file]));
        self::assertSame($sorted . "\n", file_get_contents($file));
        self::assertSame(['problems' => [], 'unsorted' => []], $sorter->check([$file]));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testApplySortsTransUnitIdsCaseInsensitively(): void
    {
        $file   = $this->xlfFile('Unsorted.xlf', $this->template(['zeta', 'Alpha', 'tab.example.label', 'mid']));
        $sorter = new XlfSorter();

        self::assertSame(['changed' => [$file], 'skipped' => []], $sorter->apply([$file]));
        self::assertSame($this->template(['Alpha', 'mid', 'tab.example.label', 'zeta']) . "\n", file_get_contents($file));

        // a second run changes nothing
        self::assertSame(['changed' => [], 'skipped' => []], $sorter->apply([$file]));
        self::assertSame(['problems' => [], 'unsorted' => []], $sorter->check([$file]));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testApplySortsUnitsAndGroupsCaseInsensitivelyTogether(): void
    {
        $file   = $this->xlfFile('Mixed.xlf', $this->fileWith([$this->unit('active'), $this->group('contacts', ['contacts[0]', 'contacts[1]']), $this->group('contactGroups', ['contactGroups[0]', 'contactGroups[1]']), $this->unit('create')]));
        $sorted = $this->fileWith([$this->unit('active'), $this->group('contactGroups', ['contactGroups[0]', 'contactGroups[1]']), $this->group('contacts', ['contacts[0]', 'contacts[1]']), $this->unit('create')]);
        $sorter = new XlfSorter();

        self::assertSame(['changed' => [$file], 'skipped' => []], $sorter->apply([$file]));
        self::assertSame($sorted . "\n", file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testCheckAndApplyLeaveSortedFilesUntouched(): void
    {
        $file   = $this->xlfFile('Sorted.xlf', $this->template(['Alpha', 'mid', 'tab.example.label', 'zeta']));
        $sorter = new XlfSorter();

        self::assertSame(['problems' => [], 'unsorted' => []], $sorter->check([$file]));
        self::assertSame(['changed' => [], 'skipped' => []], $sorter->apply([$file]));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testCheckAndApplyLeaveSortedGroupFilesUntouched(): void
    {
        $file   = $this->xlfFile('SortedGroup.xlf', $this->fileWith([$this->group('day', ['day[0]', 'day[1]']), $this->unit('message')]));
        $sorter = new XlfSorter();

        self::assertSame(['problems' => [], 'unsorted' => []], $sorter->check([$file]));
        self::assertSame(['changed' => [], 'skipped' => []], $sorter->apply([$file]));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testCheckReportsGroupWithoutIdWithoutTouchingIt(): void
    {
        /*
         * A group without id is not sortable, so the file is reported as
         * a whole (its interior is unsorted on purpose).
         */
        $content = $this->fileWith([
            $this->unit('message'),
            $this->group('', ['day[1]', 'day[0]']),
        ]);
        $file = $this->xlfFile('GroupWithoutId.xlf', $content);

        $result = (new XlfSorter())->check([$file]);

        self::assertSame([], $result['unsorted']);
        self::assertCount(1, $result['problems']);
        self::assertStringContainsString('group without id', $result['problems'][0]);

        self::assertSame(['changed' => [], 'skipped' => [$result['problems'][0]]], (new XlfSorter())->apply([$file]));
        self::assertSame($content . "\n", file_get_contents($file));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testCheckReportsMalformedBodyWithoutTouchingIt(): void
    {
        $content   = $this->template(['zeta', 'Alpha']);
        $malformed = str_replace(
            '        <body>',
            "        <body>\n            <!-- not a trans-unit -->",
            $content,
        );
        $file = $this->xlfFile('Malformed.xlf', $malformed);

        $result = (new XlfSorter())->check([$file]);

        self::assertSame([], $result['unsorted']);
        self::assertCount(1, $result['problems']);
        self::assertStringContainsString('non-block content', $result['problems'][0]);

        self::assertSame(['changed' => [], 'skipped' => [$result['problems'][0]]], (new XlfSorter())->apply([$file]));
        self::assertSame($malformed . "\n", file_get_contents($file));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testCheckReportsStrayContentInsideGroupWithoutTouchingIt(): void
    {
        $content = $this->fileWith([$this->unit('message'), $this->group('day', ['day[1]', 'day[0]'])]);
        $content = str_replace(
            'restype="x-gettext-plurals">',
            'restype="x-gettext-plurals">' . "\n" . '                <!-- not a trans-unit -->',
            $content,
        );
        $file = $this->xlfFile('StrayInGroup.xlf', $content);

        $result = (new XlfSorter())->check([$file]);

        self::assertSame([], $result['unsorted']);
        self::assertCount(1, $result['problems']);
        self::assertStringContainsString('non-block content', $result['problems'][0]);

        self::assertSame(['changed' => [], 'skipped' => [$result['problems'][0]]], (new XlfSorter())->apply([$file]));
        self::assertSame($content . "\n", file_get_contents($file));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckReportsUnreadableFile(): void
    {
        $missing = $this->root . '/missing.xlf';

        self::assertSame(['problems' => [$missing . ': unreadable'], 'unsorted' => []], (new XlfSorter())->check([$missing]));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckReportsUnsortedFile(): void
    {
        $file = $this->xlfFile('Unsorted.xlf', $this->template(['zeta', 'Alpha', 'tab.example.label', 'mid']));

        self::assertSame(['problems' => [], 'unsorted' => [$file]], (new XlfSorter())->check([$file]));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testFindFilesExcludesBuildAndDependencyDirectories(): void
    {
        $kept = [
            'Resources/Private/Language/locallang.xlf',
            'Resources/Sub/Other.xlf',
        ];
        $excluded = [
            '.Build/should-not-scan.xlf',
            '.git/hooks/scan.xlf',
            'node_modules/lib/scan.xlf',
            'vendor/psbits/qa/scan.xlf',
            'var/cache/scan.xlf',
        ];

        foreach ([...$kept, ...$excluded] as $relative) {
            $path = $this->root . '/' . $relative;
            mkdir(dirname($path), 0755, true);
            file_put_contents($path, $this->template(['a']));
        }

        $found    = XlfSorter::findFiles($this->root);
        $relative = array_map(
            fn(string $file): string => substr($file, strlen($this->root) + 1),
            $found,
        );

        self::assertSame(['Resources/Private/Language/locallang.xlf', 'Resources/Sub/Other.xlf'], $relative);
    }

    /**
     * @param list<string> $blocks
     */
    private function fileWith(array $blocks): string
    {
        return <<<xlf
        <?xml version="1.0" encoding="utf-8" standalone="yes" ?>
        <xliff version="1.0">
            <file datatype="plaintext" source-language="en">
                <body>
        xlf
            . "\n" . implode('', $blocks) . <<<xlf
                </body>
            </file>
        </xliff>
        xlf;
    }

    /**
     * @param list<string> $unitIds
     * @param list<string> $nested
     */
    private function group(string $id, array $unitIds, array $nested = [], string $indent = '            '): string
    {
        $opening = '' === $id
            ? "{$indent}<group\n{$indent}    restype=\"x-gettext-plurals\">\n"
            : "{$indent}<group\n{$indent}    id=\"{$id}\"\n{$indent}    restype=\"x-gettext-plurals\">\n";
        $blocks = $nested;

        foreach ($unitIds as $unitId) {
            $blocks[] = $this->unit($unitId, $indent . '    ');
        }

        return $opening . implode('', $blocks) . "{$indent}</group>\n";
    }

    /**
     * @throws UnexpectedValueException
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            is_link($itemPath) ? unlink($itemPath) : ($item->isDir() ? rmdir($itemPath) : unlink($itemPath));
        }

        rmdir($path);
    }

    /**
     * @param list<string> $ids
     */
    private function template(array $ids): string
    {
        $units = '';

        foreach ($ids as $id) {
            $units .= <<<xlf
                    <trans-unit id="{$id}">
                        <source>{$id}</source>
                    </trans-unit>
            xlf . "\n";
        }

        return <<<xlf
            <?xml version="1.0" encoding="utf-8" standalone="yes" ?>
            <xliff version="1.0">
                <file
                    datatype="plaintext"
                    source-language="en">
                    <body>
            xlf
            . "\n" . $units . <<<xlf
                    </body>
                </file>
            </xliff>
            xlf;
    }

    private function unit(string $id, string $indent = '            '): string
    {
        return "{$indent}<trans-unit id=\"{$id}\">\n{$indent}    <source>{$id}</source>\n{$indent}</trans-unit>\n";
    }

    /**
     * @throws ExpectationFailedException
     */
    private function xlfFile(string $name, string $content): string
    {
        $file = $this->root . '/' . $name;
        self::assertTrue(false !== file_put_contents($file, $content . "\n"));

        return $file;
    }
}
