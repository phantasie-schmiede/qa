<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Quality\CsFixerFactory;
use Random\RandomException;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;

final class CsFixerConfigTest extends TestCase
{
    private string $root;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-csfixer-' . bin2hex(random_bytes(6));

        foreach (['Classes', 'Configuration', 'Tests'] as $directory) {
            mkdir($this->root . '/' . $directory, 0755, true);
            file_put_contents($this->root . '/' . $directory . '/' . $directory . '.php', "<?php\n");
        }
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function tearDown(): void
    {
        chdir(sys_get_temp_dir());
        $this->removeTree($this->root);
    }

    /**
     * @throws DirectoryNotFoundException
     * @throws ExpectationFailedException
     */
    public function testConfigHonoursExcludeDirectories(): void
    {
        mkdir($this->root . '/Tests/Fixtures', 0755, true);
        file_put_contents($this->root . '/Tests/Fixtures/Expected.php', "<?php\n");
        chdir($this->root);
        $config = CsFixerFactory::create(['Classes', 'Configuration', 'Tests'], ['Tests/Fixtures']);

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

    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testConfigUsesTheRepositoryDirectoriesAndTheSharedFixers(): void
    {
        chdir($this->root);
        $config = require __DIR__ . '/../../Resources/Quality/cs-fixer.dist.php';

        self::assertInstanceOf(Config::class, $config);
        $rules = $config->getRules();
        self::assertArrayHasKey('@PSR12', $rules);
        self::assertArrayHasKey('PSBits/alphabetical_array_keys', $rules);
        self::assertArrayHasKey('PSBits/block_comments', $rules);
        self::assertArrayHasKey('PSBits/class_block_separation', $rules);
        self::assertArrayHasKey('PSBits/class_phpdoc_cleanup', $rules);
        self::assertArrayHasKey('PSBits/empty_comments', $rules);
        self::assertArrayHasKey('PSBits/function_imports', $rules);
        self::assertArrayHasKey('PSBits/property_names_alignment', $rules);
        self::assertArrayHasKey('PSBits/typed_constant', $rules);
        self::assertArrayHasKey('concat_space', $rules);
        self::assertArrayNotHasKey('native_function_invocation', $rules);
        self::assertSame(
            [
                'order' => [
                    'todo',
                    'access',
                    'api',
                    'author',
                    'category',
                    'copyright',
                    'deprecated',
                    'example',
                    'extends',
                    'final',
                    'implements',
                    'import-type',
                    'inheritDoc',
                    'internal',
                    'license',
                    'link',
                    'method',
                    'mixin',
                    'no-named-arguments',
                    'package',
                    'param',
                    'property',
                    'property-read',
                    'property-write',
                    'readonly',
                    'return',
                    'see',
                    'since',
                    'subpackage',
                    'template',
                    'template-contravariant',
                    'template-covariant',
                    'throws',
                    'type',
                    'use',
                    'var',
                    'version',
                ],
            ],
            $rules['phpdoc_order'],
        );
        self::assertSame(
            [
                'annotations' => [
                    'method',
                    'mixin',
                    'property',
                    'property-read',
                    'property-write',
                    'throws',
                ],
            ],
            $rules['phpdoc_order_by_value'],
        );
        self::assertSame(
            [
                'groups'                    => [['*']],
                'skip_unlisted_annotations' => true,
            ],
            $rules['phpdoc_separation'],
        );

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
}
