<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final class PhpToolsTest extends TestCase
{
    private string $root;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-php-tools-' . bin2hex(random_bytes(6));
        $runnerDir  = $this->root . '/.Build/vendor/psbits/qa/Resources/Quality';
        mkdir($runnerDir, 0755, true);
        copy(__DIR__ . '/../../Resources/Quality/phpTools.php', $runnerDir . '/phpTools.php');

        mkdir($this->root . '/.Build/bin', 0755, true);

        foreach (['php-cs-fixer', 'phpcbf', 'phpcs'] as $tool) {
            $this->writeStub($tool, 0);
        }
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
     * @throws LogicException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testFixRunsThePhpCbFBeforeTheCsFixer(): void
    {
        $this->writeCsFixerShim();
        $this->writePhpcsShim();
        [$exit, $log] = $this->runTools('fix');
        self::assertSame(0, $exit);
        self::assertStringContainsString('phpcbf --standard=Build/Quality/.phpcs.xml.dist', $log);
        self::assertStringContainsString('php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --verbose', $log);
        self::assertStringNotContainsString('--dry-run', $log);
        self::assertLessThan(
            strpos($log, 'php-cs-fixer fix --config='),
            strpos($log, 'phpcbf --standard='),
        );
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testLintExitsNonZeroWhenAnyToolFails(): void
    {
        $this->writeCsFixerShim();
        $this->writePhpcsShim();
        $this->writeStub('php-cs-fixer', 8);
        $this->writeStub('phpcs', 1);
        [$exit, $log] = $this->runTools('lint');
        self::assertSame(1, $exit);
        self::assertStringContainsString('php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose', $log);
        self::assertStringContainsString('phpcs --standard=Build/Quality/.phpcs.xml.dist', $log);
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testLintRunsBothToolsWithTheirConfigurations(): void
    {
        $this->writeCsFixerShim();
        $this->writePhpcsShim();
        [$exit, $log] = $this->runTools('lint');
        self::assertSame(0, $exit);
        self::assertStringContainsString('php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose', $log);
        self::assertStringContainsString('phpcs --standard=Build/Quality/.phpcs.xml.dist', $log);
        self::assertLessThan(
            strpos($log, 'phpcs --standard='),
            strpos($log, 'php-cs-fixer fix --config='),
        );
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testLintRunsTheRemainingToolsWhenAnEarlierOneFails(): void
    {
        $this->writeCsFixerShim();
        $this->writePhpcsShim();
        $this->writeStub('php-cs-fixer', 8);
        [$exit, $log] = $this->runTools('lint');
        self::assertSame(1, $exit);
        self::assertStringContainsString('php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose', $log);
        self::assertStringContainsString('phpcs --standard=Build/Quality/.phpcs.xml.dist', $log);
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testNoConfigurationFails(): void
    {
        [$exit, , $output] = $this->runTools('lint');
        self::assertSame(1, $exit);
        self::assertStringContainsString('error: no PHP style configuration found', $output);
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testPathsArePassedToEveryTool(): void
    {
        $this->writeCsFixerShim();
        $this->writePhpcsShim();
        mkdir($this->root . '/Classes');
        [$exit, $log] = $this->runTools('lint', ['Classes']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose ' . $this->root . '/Classes', $log);
        self::assertStringContainsString('phpcs --standard=Build/Quality/.phpcs.xml.dist ' . $this->root . '/Classes', $log);
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testToolWithoutItsConfigurationIsSkipped(): void
    {
        $this->writeCsFixerShim();
        [$exit, $log] = $this->runTools('lint');
        self::assertSame(0, $exit);
        self::assertStringContainsString('php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose', $log);
        self::assertStringNotContainsString('phpcs --standard=', $log);

        unlink($this->root . '/Build/Quality/.php-cs-fixer.dist.php');
        $this->writePhpcsShim();
        [$exit, $log] = $this->runTools('lint');
        self::assertSame(0, $exit);
        self::assertStringContainsString('phpcs --standard=Build/Quality/.phpcs.xml.dist', $log);
        self::assertStringNotContainsString('php-cs-fixer fix --config=', $log);
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testUnknownModePrintsTheUsage(): void
    {
        [$exit, , $output] = $this->runTools('bogus');
        self::assertSame(1, $exit);
        self::assertStringContainsString('Usage: php phpTools.php <lint|fix> [paths...]', $output);
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
     * @param list<string> $paths
     * @return array{0: int, 1: string, 2: string}
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    private function runTools(string $mode, array $paths = []): array
    {
        if (is_file($this->root . '/stub.log')) {
            unlink($this->root . '/stub.log');
        }

        $runner  = $this->root . '/.Build/vendor/psbits/qa/Resources/Quality/phpTools.php';
        $process = new Process(array_merge(['php', $runner, $mode], $paths), $this->root);
        $process->run();

        return [
            $process->getExitCode(),
            is_file($this->root . '/stub.log') ? (string) file_get_contents($this->root . '/stub.log') : '',
            $process->getOutput() . $process->getErrorOutput(),
        ];
    }

    private function writeCsFixerShim(): void
    {
        $qualityDir = $this->root . '/Build/Quality';

        if (!is_dir($qualityDir)) {
            mkdir($qualityDir, 0755, true);
        }

        file_put_contents($qualityDir . '/.php-cs-fixer.dist.php', "<?php\nreturn [];\n");
    }

    private function writePhpcsShim(): void
    {
        $qualityDir = $this->root . '/Build/Quality';

        if (!is_dir($qualityDir)) {
            mkdir($qualityDir, 0755, true);
        }

        file_put_contents($qualityDir . '/.phpcs.xml.dist', '<?xml version="1.0"?>\n<ruleset name="fixture">\n</ruleset>\n');
    }

    private function writeStub(string $tool, int $exitCode): void
    {
        $stub = $this->root . '/.Build/bin/' . $tool;
        file_put_contents(
            $stub,
            '#!/bin/sh' . "\n" . 'echo "$0 $*" >> "' . $this->root . '/stub.log"' . "\n" . 'exit ' . $exitCode . "\n",
        );
        chmod($stub, 0755);
    }
}
