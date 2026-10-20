<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PhpCsFixer\Config;

final class CsFixerConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-csfixer-' . bin2hex(random_bytes(6));
        foreach (['Classes', 'Configuration', 'Tests'] as $directory) {
            mkdir($this->root . '/' . $directory, 0755, true);
            file_put_contents($this->root . '/' . $directory . '/' . $directory . '.php', "<?php\n");
        }
    }

    protected function tearDown(): void
    {
        chdir(sys_get_temp_dir());
        $this->removeTree($this->root);
    }

    public function testConfigUsesTheRepositoryDirectoriesAndTheSharedFixers(): void
    {
        chdir($this->root);
        $config = require __DIR__ . '/../../Resources/Quality/cs-fixer.dist.php';

        self::assertInstanceOf(Config::class, $config);
        $rules = $config->getRules();
        self::assertArrayHasKey('@PSR12', $rules);
        self::assertArrayHasKey('PSBits/class_block_separation', $rules);
        self::assertArrayHasKey('PSBits/property_names_alignment', $rules);

        $files = [];
        foreach ($config->getFinder() as $file) {
            $files[] = $file->getRealPath();
        }
        sort($files);
        self::assertSame(
            [
                $this->root . '/Classes/Classes.php',
                $this->root . '/Configuration/Configuration.php',
                $this->root . '/Tests/Tests.php',
            ],
            $files,
        );
    }

    public function testConfigHonoursExcludeDirectories(): void
    {
        mkdir($this->root . '/Tests/Fixtures', 0755, true);
        file_put_contents($this->root . '/Tests/Fixtures/Expected.php', "<?php\n");
        chdir($this->root);
        $config = \PSBits\Qa\Quality\CsFixerFactory::create(['Classes', 'Configuration', 'Tests'], ['Tests/Fixtures']);

        $files = [];
        foreach ($config->getFinder() as $file) {
            $files[] = $file->getRealPath();
        }
        sort($files);
        self::assertSame(
            [
                $this->root . '/Classes/Classes.php',
                $this->root . '/Configuration/Configuration.php',
                $this->root . '/Tests/Tests.php',
            ],
            $files,
        );
    }

    public function testConfigSkipsMissingDirectories(): void
    {
        unlink($this->root . '/Configuration/Configuration.php');
        rmdir($this->root . '/Configuration');
        chdir($this->root);
        $config = require __DIR__ . '/../../Resources/Quality/cs-fixer.dist.php';

        $files = [];
        foreach ($config->getFinder() as $file) {
            $files[] = $file->getRealPath();
        }
        self::assertContains($this->root . '/Classes/Classes.php', $files);
        self::assertCount(2, $files);
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
