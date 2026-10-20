<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RenderPhpunitXmlTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/Classes', 0755, true);
        mkdir($this->root . '/Tests/Unit', 0755, true);
        mkdir($this->root . '/Tests/Functional', 0755, true);
        mkdir($this->root . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build', 0755, true);
        file_put_contents($this->root . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/UnitTestsBootstrap.php', "<?php\n");
        file_put_contents($this->root . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php', "<?php\n");
        file_put_contents($this->root . '/composer.json', "{}\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testRendersUnitAndFunctionalWithAbsolutePaths(): void
    {
        [$code, ] = $this->runRenderer();
        self::assertSame(0, $code);

        $unit = simplexml_load_file($this->root . '/.Build/phpunit/UnitTests.xml');
        self::assertNotNull($unit);
        self::assertSame($this->root . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/UnitTestsBootstrap.php', (string) $unit['bootstrap']);
        self::assertSame($this->root . '/.Build/.phpunit.cache', (string) $unit['cacheDirectory']);
        self::assertSame($this->root . '/Tests/Unit', (string) $unit->testsuites->testsuite->directory);
        self::assertSame($this->root . '/Classes', (string) $unit->source->include->directory);

        $functional = simplexml_load_file($this->root . '/.Build/phpunit/FunctionalTests.xml');
        self::assertNotNull($functional);
        self::assertSame($this->root . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php', (string) $functional['bootstrap']);
        self::assertSame($this->root . '/Tests/Functional', (string) $functional->testsuites->testsuite->directory);
    }

    public function testRenderedXmlMatchesTheCommittedReferenceTemplates(): void
    {
        [$code, ] = $this->runRenderer();
        self::assertSame(0, $code);

        foreach (['Unit', 'Functional'] as $name) {
            $reference = file_get_contents(__DIR__ . '/../Fixtures/' . $name . 'Tests.reference.xml');
            self::assertNotFalse($reference);
            $expected = str_replace(
                ['../../.Build', '../../Classes', '../../Tests/' . $name],
                [$this->root . '/.Build', $this->root . '/Classes', $this->root . '/Tests/' . $name],
                $reference,
            );
            self::assertSame(
                $expected,
                file_get_contents($this->root . '/.Build/phpunit/' . $name . 'Tests.xml'),
                $name . ' XML deviates from the committed reference template',
            );
        }
    }

    public function testSkipsMissingFunctionalSuite(): void
    {
        $this->removeTree($this->root . '/Tests/Functional');
        [$code, ] = $this->runRenderer();
        self::assertSame(0, $code);

        self::assertFileExists($this->root . '/.Build/phpunit/UnitTests.xml');
        self::assertFileDoesNotExist($this->root . '/.Build/phpunit/FunctionalTests.xml');
    }

    public function testFailsWithoutAnySuiteDirectory(): void
    {
        $this->removeTree($this->root . '/Tests');
        [$code, $output] = $this->runRenderer();
        self::assertSame(1, $code);
        self::assertStringContainsString('no Tests/Unit or Tests/Functional', $output);
    }

    /** @return array{0: int, 1: string} */
    private function runRenderer(): array
    {
        $output = [];
        $code = 0;
        exec(
            'PSBITS_QA_REPO_ROOT=' . escapeshellarg($this->root)
            . ' php ' . escapeshellarg(__DIR__ . '/../../Resources/Testing/renderPhpunitXml.php')
            . ' all 2>&1',
            $output,
            $code,
        );
        return [$code, implode("\n", $output)];
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
}
