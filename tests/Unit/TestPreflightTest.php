<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

final class TestPreflightTest extends TestCase
{
    private string $root;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-preflight-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/bin', 0755, true);
        mkdir($this->root . '/Tests/Unit', 0755, true);
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->root . '/vendor-sentinel', 'unchanged');

        foreach (['cp', 'dirname', 'mkdir', 'mktemp', 'rm', 'sh'] as $tool) {
            symlink('/usr/bin/' . $tool, $this->root . '/bin/' . $tool);
        }
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            is_link($item->getPathname()) || !$item->isDir() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }

        rmdir($this->root);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testAvailableImagePassesDockerPreflight(): void
    {
        $this->docker('never');
        $script = dirname(__DIR__, 2) . '/Resources/Testing/Scripts/preflight.sh';
        $this->tool('check', '. ' . escapeshellarg($script) . '; docker_preflight');
        [$code, $output] = $this->process([$this->root . '/bin/check']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('using psbits/fixture-test:8.4', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testDockerMatrixSurvivesRemovedVendorInfrastructure(): void
    {
        $scripts = $this->installTestingInfrastructure();
        $this->tool('composer', 'echo "$*" >> "$PSBITS_QA_REPO_ROOT/composer-calls"');
        $this->tool('docker', <<<'SH'
echo "$*" >> "$PSBITS_QA_REPO_ROOT/docker-calls"
if [ "$1" = compose ] && [ "$2" = -f ]; then
    [ -f "$3" ] || exit 90
    [ "$4" = -p ] && [ "$5" = psbits-fixture-tests ] || exit 91
    if [ "$6" = run ]; then
        shift 9
        runner="$PSBITS_QA_REPO_ROOT${1#/repo}"
        [ -f "$runner" ] || exit 92
        echo run >> "$PSBITS_QA_REPO_ROOT/legs"
        if [ -d "$PSBITS_QA_REPO_ROOT/.Build/vendor" ]; then
            rm -rf "$PSBITS_QA_REPO_ROOT/.Build/vendor"
            exit 1
        fi
    fi
fi
SH);
        [$code, $output] = $this->process(['/bin/sh', $scripts . '/runDockerTests.sh']);
        self::assertSame(1, $code, $output);
        self::assertSame(str_repeat("run\n", 8), file_get_contents($this->root . '/legs'));
        self::assertStringContainsString('1 of 8 legs failed.', $output);
        self::assertStringNotContainsString('could not stop', $output);
        self::assertStringContainsString('down', (string) file_get_contents($this->root . '/docker-calls'));
        self::assertSame([], glob($this->root . '/.Build/qa-runtime/run-*'));
        self::assertSame("install --no-scripts --no-interaction\n", file_get_contents($this->root . '/composer-calls'));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testFailedLegStillRestoresHostComposerInstallation(): void
    {
        $this->tool('docker', 'echo "$*" >> "$PSBITS_QA_REPO_ROOT/docker-calls"; [ "$6" != run ]');
        $this->tool('composer', 'echo "$*" >> "$PSBITS_QA_REPO_ROOT/composer-calls"');
        [$code, $output] = $this->runScript('runDockerTests.sh', ['13.4']);
        self::assertNotSame(0, $code, $output);
        self::assertSame("install --no-scripts --no-interaction\n", file_get_contents($this->root . '/composer-calls'));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testFailedRestoreFailsSuccessfulRunAndPreservesMatrixFailure(): void
    {
        $this->docker('never');
        $this->tool('composer', 'exit 1');
        [$code, $output] = $this->runScript('runDockerTests.sh', ['13.4']);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('could not restore the host Composer installation', $output);
        self::assertSame([], glob($this->root . '/.Build/qa-runtime/run-*'));
        $this->tool('docker', '[ "$6" != run ] || exit 7');
        [$code, $output] = $this->runScript('runDockerTests.sh', ['13.4']);
        self::assertSame(7, $code, $output);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testImageBuildPullsEveryBaseImage(): void
    {
        foreach (['basename', 'find', 'id', 'tr', 'wc'] as $tool) {
            symlink('/usr/bin/' . $tool, $this->root . '/bin/' . $tool);
        }

        $this->docker('never');
        [$code, $output] = $this->runScript('buildTestImages.sh', [], false, ['EXTRA_CA_FILES' => '/nonexistent']);
        self::assertSame(0, $code, $output);
        $calls = (string) file_get_contents($this->root . '/docker-calls');
        self::assertSame(3, substr_count($calls, 'build --pull --build-arg php_version='));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testInvalidComposerFileIsReported(): void
    {
        file_put_contents($this->root . '/composer.json', 'invalid');
        [$code, $output] = $this->runScript('checkTestEnvironment.php', [$this->root, 'sqlite'], true);
        self::assertSame(1, $code);
        self::assertStringContainsString('valid composer.json is required', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testLocalMatrixSurvivesComposerFailureAfterVendorRemoval(): void
    {
        $scripts = $this->installTestingInfrastructure();
        symlink('/usr/bin/grep', $this->root . '/bin/grep');
        $this->tool('php', 'exec ' . escapeshellarg(PHP_BINARY) . ' "$@"');
        $this->tool('composer', 'echo "$*" >> "$PSBITS_QA_REPO_ROOT/composer-calls"; [ "$1" != update ]');
        [$code, $output] = $this->process(['/bin/sh', $scripts . '/runLocalTests.sh']);
        self::assertSame(4, $code, $output);
        self::assertSame(4, substr_count((string) file_get_contents($this->root . '/composer-calls'), 'update --with'));
        self::assertStringContainsString('4 of 4 legs failed.', $output);
        self::assertStringNotContainsString('cannot open', $output);
        self::assertSame([], glob($this->root . '/.Build/qa-runtime/run-*'));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testLowestLegUsesCurrentQaInfrastructure(): void
    {
        foreach (['grep'] as $tool) {
            symlink('/usr/bin/' . $tool, $this->root . '/bin/' . $tool);
        }

        $this->tool('php', 'exec ' . escapeshellarg(PHP_BINARY) . ' "$@"');
        $this->tool('composer', 'echo "$*" >> "$PSBITS_QA_REPO_ROOT/composer-calls"; [ "$1" != update ]');
        [$code, $output] = $this->runScript('runTests.sh', ['13.4', '--lowest']);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString(
            'require --dev --no-update --no-interaction psbits/qa:dev-main',
            (string) file_get_contents($this->root . '/composer-calls'),
        );
        self::assertStringNotContainsString(
            'phpunit/phpunit:',
            (string) file_get_contents($this->root . '/composer-calls'),
        );
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testMissingCommandsStopLocalMatrixBeforeAnyLeg(): void
    {
        [$code, $output] = $this->runScript('runLocalTests.sh');
        self::assertSame(1, $code);
        self::assertStringContainsString('Missing command: php', $output);
        self::assertStringContainsString('Missing command: composer', $output);
        self::assertStringNotContainsString('Leg 1/', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testMissingComposeStopsBeforeAnyContainerOperation(): void
    {
        $this->docker('compose');
        [$code, $output] = $this->runScript('runDockerTests.sh');
        self::assertSame(1, $code);
        self::assertStringContainsString('Docker Compose is unavailable', $output);
        self::assertSame("compose version\n", file_get_contents($this->root . '/docker-calls'));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testMissingDockerStopsBeforeAnyContainerOperation(): void
    {
        [$code, $output] = $this->runScript('runDockerTests.sh');
        self::assertSame(1, $code);
        self::assertStringContainsString('Docker is missing', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testMissingImageUsesSelectedTagAndDoesNotRunOrCleanUpContainers(): void
    {
        $this->docker('image');
        [$code, $output] = $this->runScript('runDockerTests.sh', [], false, ['PHP_VERSION' => '8.5']);
        self::assertSame(1, $code);
        self::assertStringContainsString('missing image psbits/fixture-test:8.5', $output);
        self::assertStringContainsString('composer docker:images', $output);
        self::assertSame("compose version\ninfo\nimage inspect psbits/fixture-test:8.5\n", file_get_contents($this->root . '/docker-calls'));
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testMissingSqliteStopsSingleLegBeforeComposer(): void
    {
        mkdir($this->root . '/Tests/Functional');
        $this->tool('php', 'exec ' . escapeshellarg(PHP_BINARY) . ' -n "$@"');
        $this->tool('composer', 'echo called >> "$PSBITS_QA_REPO_ROOT/composer-calls"');
        [$code, $output] = $this->runScript('runTests.sh', ['13.4']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Missing PHP extension: pdo_sqlite', $output);
        self::assertStringContainsString('php --ri pdo_sqlite', $output);
        self::assertStringNotContainsString('step 1/4', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testMissingSuiteStopsBeforeDependencyResolution(): void
    {
        rmdir($this->root . '/Tests/Unit');
        [$code, $output] = $this->runScript('checkTestEnvironment.php', [$this->root, 'sqlite'], true);
        self::assertSame(1, $code);
        self::assertStringContainsString('nothing to test', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testRepositoryExtensionRequirementsAreChecked(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require":{"ext-psbits_missing":"*"}}');
        [$code, $output] = $this->runScript('checkTestEnvironment.php', [$this->root, 'sqlite'], true);
        self::assertSame(1, $code);
        self::assertStringContainsString('Missing PHP extension: psbits_missing', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testSingleLegRestoresHostComposerInstallation(): void
    {
        $this->docker('');
        $this->tool('composer', 'echo "$*" >> "$PSBITS_QA_REPO_ROOT/composer-calls"');
        [$code, $output] = $this->runScript('runDockerTests.sh', ['13.4']);
        self::assertSame(0, $code, $output);
        self::assertSame("install --no-scripts --no-interaction\n", file_get_contents($this->root . '/composer-calls'));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testUnitOnlyPreflightDoesNotRequireDatabaseDriver(): void
    {
        $this->tool('php', 'exec ' . escapeshellarg(PHP_BINARY) . ' "$@"');
        [$code, $output] = $this->runScript('checkTestEnvironment.php', [$this->root, 'sqlite'], true);
        self::assertSame(0, $code, $output);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testUnreachableDaemonStopsBeforeAnyContainerOperation(): void
    {
        $this->docker('info');
        [$code, $output] = $this->runScript('runDockerTests.sh');
        self::assertSame(1, $code);
        self::assertStringContainsString('Docker daemon is unreachable', $output);
        self::assertSame("compose version\ninfo\n", file_get_contents($this->root . '/docker-calls'));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    public function testUnreachableDatabaseIsReportedWithoutCreatingDatabase(): void
    {
        mkdir($this->root . '/Tests/Functional');
        [$code, $output] = $this->runScript('checkTestEnvironment.php', [$this->root, 'mysql'], true, [
            'typo3DatabaseHost' => '127.0.0.1',
            'typo3DatabasePort' => '1',
        ]);
        self::assertSame(1, $code);
        self::assertTrue(str_contains($output, 'Cannot connect to mysql') || str_contains($output, 'Missing PHP extension: pdo_mysql'));
        self::assertStringContainsString('typo3DatabaseHost/Port/Username/Password', $output);
        $this->assertNoTestRun();
    }

    /**
     * @throws ExpectationFailedException
     */
    private function assertNoTestRun(): void
    {
        self::assertFileDoesNotExist($this->root . '/composer-matrix.json');
        self::assertFileDoesNotExist($this->root . '/composer-calls');
        self::assertSame('unchanged', file_get_contents($this->root . '/vendor-sentinel'));
    }

    private function docker(string $failure): void
    {
        $this->tool('docker', 'echo "$*" >> "$PSBITS_QA_REPO_ROOT/docker-calls"' . "\n" . '[ "$1" != ' . escapeshellarg($failure) . ' ]');
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws ValueError
     */
    private function installTestingInfrastructure(): string
    {
        $target = $this->root . '/.Build/vendor/psbits/qa/Resources';
        mkdir($target, 0755, true);
        [$code, $output] = $this->process(['/usr/bin/cp', '-R', dirname(__DIR__, 2) . '/Resources/Testing', $target . '/Testing']);
        self::assertSame(0, $code, $output);

        return $target . '/Testing/Scripts';
    }

    /** @param list<string> $command
     * @param array<string, string> $environment
     * @return array{int, string}
     * @throws Exception
     * @throws ValueError
     */
    private function process(array $command, array $environment = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, array_merge([
            'PATH'                => $this->root . '/bin',
            'PSBITS_QA_EXTENSION' => 'fixture',
            'PSBITS_QA_REPO_ROOT' => $this->root,
            'PHP_VERSION'         => '8.4',
        ], $environment));
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /** @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{int, string}
     * @throws Exception
     * @throws ValueError
     */
    private function runScript(string $script, array $arguments = [], bool $php = false, array $environment = []): array
    {
        $path = dirname(__DIR__, 2) . '/Resources/Testing/Scripts/' . $script;

        return $this->process([$php ? PHP_BINARY : '/bin/sh', $path, ...$arguments], $environment);
    }

    private function tool(string $name, string $body): void
    {
        $path = $this->root . '/bin/' . $name;
        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0755);
    }
}
