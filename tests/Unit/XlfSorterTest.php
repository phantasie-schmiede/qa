<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Quality\XlfSorter;

final class XlfSorterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-xlf-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

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

    public function testCheckAndApplyLeaveSortedFilesUntouched(): void
    {
        $file   = $this->xlfFile('Sorted.xlf', $this->template(['Alpha', 'mid', 'tab.example.label', 'zeta']));
        $sorter = new XlfSorter();

        self::assertSame(['problems' => [], 'unsorted' => []], $sorter->check([$file]));
        self::assertSame(['changed' => [], 'skipped' => []], $sorter->apply([$file]));
    }

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

    public function testCheckReportsUnreadableFile(): void
    {
        $missing = $this->root . '/missing.xlf';

        self::assertSame(['problems' => [$missing . ': unreadable'], 'unsorted' => []], (new XlfSorter())->check([$missing]));
    }

    public function testCheckReportsUnsortedFile(): void
    {
        $file = $this->xlfFile('Unsorted.xlf', $this->template(['zeta', 'Alpha', 'tab.example.label', 'mid']));

        self::assertSame(['problems' => [], 'unsorted' => [$file]], (new XlfSorter())->check([$file]));
    }

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

    private function xlfFile(string $name, string $content): string
    {
        $file = $this->root . '/' . $name;
        self::assertTrue(false !== file_put_contents($file, $content . "\n"));

        return $file;
    }
}
