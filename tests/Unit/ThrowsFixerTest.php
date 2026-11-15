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
use PSBits\Qa\PhpStan\ThrowsDocblockAuditRule;
use PSBits\Qa\PhpStan\ThrowsDocblockRule;
use PSBits\Qa\Quality\ThrowsFixer;
use Random\RandomException;

final class ThrowsFixerTest extends TestCase
{
    private string $oldPath;

    /** @var array<string, string> */
    private array  $protectedFiles = [];
    private string $root;

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     */
    protected function setUp(): void
    {
        $this->root    = sys_get_temp_dir() . '/psbits-qa-fix-' . bin2hex(random_bytes(6));
        $this->oldPath = (string) getenv('PATH');
        mkdir($this->root, 0755, true);
    }

    /**
     * @throws ExpectationFailedException
     * @throws UnexpectedValueException
     */
    protected function tearDown(): void
    {
        foreach ($this->protectedFiles as $file => $content) {
            self::assertSame($content, file_get_contents($file), $file . ' must remain unchanged');
        }

        if (is_dir($this->root . '/repo/.Build/throws-fix')) {
            self::assertSame([], array_values(array_diff(scandir($this->root . '/repo/.Build/throws-fix'), ['.', '..'])));
        }

        $this->removeTree($this->root);
        putenv('PATH=' . $this->oldPath);
    }

    /**
     * @param list<string> $configurations
     * @throws ExpectationFailedException
     */
    private static function assertTempConfigurationCarriesTheNeonExtension(array $configurations): void
    {
        /*
         * PHPStan picks the config loader by file extension; the
         * extensionless name tempnam() produces is rejected with
         * "Unknown file extension".
         */
        self::assertGreaterThan(0, count($configurations));

        foreach ($configurations as $configuration) {
            self::assertSame(true, str_ends_with($configuration, '.neon'));
        }
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testAnalysisFailureLeavesDevelopmentInstallationUntouched(): void
    {
        $root = $this->fixtureRepo('^13.4 || ^14.3', '14.3.9', array_fill(0, 3, $this->audit('expected none, declared \\App\\Old')));
        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        try {
            (new ThrowsFixer($root))->run();
            self::fail('expected analysis failure');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('no phpstan JSON', $exception->getMessage());
            self::assertStringContainsString('no staged response', $exception->getMessage());
        }

        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCollectEntriesKeepsOnlyRuleMessages(): void
    {
        $data = [
            'files'  => [
                'src/App/Functions.php' => [
                    'messages' => [
                        [
                            'identifier' => ThrowsDocblockAuditRule::IDENTIFIER,
                            'line'       => 3,
                            'message'    => 'Function App\helper() @throws do not match the thrown exceptions: expected none, declared \RuntimeException.',
                        ],
                    ],
                ],
                'src/App/Service.php'   => [
                    'messages' => [
                        [
                            'identifier' => ThrowsDocblockAuditRule::IDENTIFIER,
                            'line'       => 12,
                            'message'    => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\Error, declared none.',
                        ],
                        [
                            /*
                             * the lint rule is no longer collected: the
                             * audit rule supersedes it for the fixer
                             */
                            'identifier' => ThrowsDocblockRule::IDENTIFIER,
                            'line'       => 18,
                            'message'    => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\Error, declared none.',
                        ],
                        [
                            'identifier' => 'phpdoc.throws',
                            'line'       => 20,
                            'message'    => 'must not be reported',
                        ],
                    ],
                ],
            ],
            'totals' => ['errors' => 0],
        ];

        self::assertSame([
            [
                'file'    => 'src/App/Functions.php',
                'line'    => 3,
                'message' => 'Function App\helper() @throws do not match the thrown exceptions: expected none, declared \RuntimeException.',
            ],
            [
                'file'    => 'src/App/Service.php',
                'line'    => 12,
                'message' => 'Method App\Service::run() @throws do not match the thrown exceptions: expected \App\Error, declared none.',
            ],
        ], ThrowsFixer::collectEntries($data));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCollectEntriesReturnsEmptyListForForeignOutput(): void
    {
        self::assertSame([], ThrowsFixer::collectEntries([
            'files'  => [
                'src/App/Service.php' => [
                    'messages' => [
                        [
                            'identifier' => 'missingType.missingReturnType',
                            'line'       => 7,
                            'message'    => 'Method App\Service::run() should return specifying the exact returned type.',
                        ],
                    ],
                ],
            ],
            'totals' => ['errors' => 0],
        ]));
        self::assertSame([], ThrowsFixer::collectEntries([]));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCollectEntriesStripsTraitContextFromFileKey(): void
    {
        $data = [
            'files'  => [
                'src/App/Traits/Filler.php (in context of class App\Consumer\Element)' => [
                    'messages' => [
                        [
                            'identifier' => ThrowsDocblockAuditRule::IDENTIFIER,
                            'line'       => 10,
                            'message'    => 'Method App\Consumer\Element::fillProperties() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared \ReflectionException.',
                        ],
                    ],
                ],
            ],
            'totals' => ['errors' => 0],
        ];

        self::assertSame([
            [
                'file'    => 'src/App/Traits/Filler.php',
                'line'    => 10,
                'message' => 'Method App\Consumer\Element::fillProperties() @throws do not match the thrown exceptions: expected \InvalidArgumentException, declared \ReflectionException.',
            ],
        ], ThrowsFixer::collectEntries($data));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testDualMajorRunUsesIsolatedCoresAndMergesWithMarkers(): void
    {
        $root = $this->fixtureRepo('^14.3 || ^13.4', '14.3.10', [
            // the 14.3 fix loop (the installed major runs first)
            $this->audit('expected \App\NewError, declared \App\Old'),
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old'),
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old, \App\Other'),
            // the 13.4 fix loop (no missing lines: converged in round 0)
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old, \App\Other'),
            // the cross validation over both isolated cores (matrix order)
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old, \App\Other'),
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old, \App\Other'),
        ]);

        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        self::assertSame(0, (new ThrowsFixer($root))->run());

        self::assertSame([
            ['update', '-W', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist'],
            ['update', '-W', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist'],
        ], $this->composerCalls($root));

        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));

        // Both installations are disposable; the original vendor survives.
        self::assertFileExists($root . '/.Build/vendor/keep-me.txt');
        self::assertStringContainsString('@throws NewError', file_get_contents($root . '/.state/sources.log'));

        /*
         * NewError is thrown on both majors (no marker), Other only on
         * 14.3 (marker), Old on none (line removed).
         */
        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws NewError
                 * @throws Other // t3:14
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($root . '/src/Service.php'));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testFailedIsolatedInstallPreservesTheStartingCoreAndSkipsTheMerge(): void
    {
        $root = $this->fixtureRepo('^14.3 || ^13.4', '14.3.10', [
            $this->audit('expected \App\NewError, declared \App\Old'),
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old'),
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old, \App\Other'),
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old, \App\Other'),
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old, \App\Other'),
        ]);
        file_put_contents($root . '/.state/fail-calls', '2');

        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        try {
            (new ThrowsFixer($root))->run();
            self::fail('expected the switch failure to surface as an exception');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('boom', $exception->getMessage());
        }

        $installed = json_decode((string) file_get_contents($root . '/.Build/vendor/composer/installed.json'), true);
        self::assertSame('14.3.10', $installed['packages'][0]['version']);

        self::assertSame([
            ['update', '-W', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist'],
            ['update', '-W', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist'],
        ], $this->composerCalls($root));

        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));

        // Even a failed install cannot wipe the original vendor.
        self::assertFileExists($root . '/.Build/vendor/keep-me.txt');

        /*
         * The merge only runs after every core converged, so a failed run
         * leaves the additive state: no markers, the extra line intact.
         */
        $content = (string) file_get_contents($root . '/src/Service.php');
        self::assertStringNotContainsString('t3:', $content);
        self::assertStringContainsString('@throws Other', $content);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testIsolatedManifestsAndCopiedSourcesAreUsed(): void
    {
        $root                     = $this->fixtureRepo('^13.4 || ^14.3', '14.3.9', array_fill(0, 4, $this->audit('expected none, declared \\App\\Old')));
        $manifest                 = json_decode(file_get_contents($root . '/composer.json'), true);
        $manifest['repositories'] = [['options' => ['symlink' => true], 'type' => 'path', 'url' => '../sibling']];
        $manifest['scripts']      = ['post-update-cmd' => 'touch original-must-not-change'];
        $manifest['autoload']     = ['psr-4' => ['App\\' => 'src/']];
        file_put_contents($root . '/composer.json', json_encode($manifest));
        $this->protectedFiles[$root . '/composer.json'] = file_get_contents($root . '/composer.json');
        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        self::assertSame(0, (new ThrowsFixer($root))->run());
        $environments = array_map(static fn(string $line): array => json_decode($line, true), file($root . '/.state/manifests.log', FILE_IGNORE_NEW_LINES));
        self::assertNotSame($environments[0]['root'], $environments[1]['root']);

        foreach ($environments as $environment) {
            self::assertSame('.Build/vendor', $environment['manifest']['config']['vendor-dir']);
            self::assertSame('src/', $environment['manifest']['autoload']['psr-4']['App\\']);
            self::assertArrayNotHasKey('scripts', $environment['manifest']);
            self::assertFalse($environment['manifest']['repositories'][0]['options']['symlink']);
            self::assertSame($root . '/../sibling', $environment['manifest']['repositories'][1]['url']);
            self::assertFalse($environment['manifest']['repositories'][1]['options']['symlink']);
            self::assertDirectoryDoesNotExist($environment['root']);
        }

        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testIsolatedRunNeverAttemptsARestore(): void
    {
        $root = $this->fixtureRepo('^13.4 || ^14.3', '14.3.9', array_fill(0, 4, $this->audit('expected none, declared \\App\\Old')));
        file_put_contents($root . '/.state/fail-calls', '3');
        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        self::assertSame(0, (new ThrowsFixer($root))->run());
        self::assertSame(2, count($this->composerCalls($root)));
        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testLargeStderrDoesNotBlockComposerOrPhpstan(): void
    {
        $root = $this->fixtureRepo('^13.4 || ^14.3', '14.3.10', array_fill(0, 4, $this->audit('expected none, declared \\App\\Old')));
        file_put_contents($root . '/.state/large-stderr', '1');
        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        self::assertSame(0, (new ThrowsFixer($root))->run());
        self::assertSame(2, count($this->composerCalls($root)));
        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     */
    public function testMatrixFromComposerJsonParsesTheSupportedMajors(): void
    {
        $file = $this->root . '/composer.json';
        file_put_contents($file, json_encode(['require' => ['typo3/cms-core' => '^13.4 || ^14.3']]));
        self::assertSame(['13.4', '14.3'], ThrowsFixer::matrixFromComposerJson($file));

        file_put_contents($file, json_encode(['require' => ['typo3/cms-core' => '^14.3']]));
        self::assertSame(['14.3'], ThrowsFixer::matrixFromComposerJson($file));

        file_put_contents($file, json_encode(['require' => ['typo3/cms-core' => '>=13.4']]));
        self::assertSame(['13.4'], ThrowsFixer::matrixFromComposerJson($file));

        file_put_contents($file, json_encode(['require' => ['typo3/cms-core' => '~14']]));
        self::assertSame(['14.0'], ThrowsFixer::matrixFromComposerJson($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     */
    public function testMatrixFromComposerJsonReturnsNullForUnparseableConstraints(): void
    {
        $file = $this->root . '/composer.json';

        file_put_contents($file, json_encode(['require' => ['php' => '^8.3']]));
        self::assertNull(ThrowsFixer::matrixFromComposerJson($file));

        file_put_contents($file, json_encode(['require' => ['typo3/cms-core' => '>=13.4 <15.0']]));
        self::assertNull(ThrowsFixer::matrixFromComposerJson($file));

        file_put_contents($file, 'not json');
        self::assertNull(ThrowsFixer::matrixFromComposerJson($file));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testPhpstanFindingExitCodeStillAllowsFixing(): void
    {
        $root = $this->fixtureRepo('^14.3', '14.3.10', array_fill(0, 2, $this->audit('expected none, declared \\App\\Old')));
        file_put_contents($root . '/.state/phpstan-exit', '1');

        self::assertSame(0, (new ThrowsFixer($root))->run());
        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));
    }

    /**
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testPhpstanUnexpectedExitCodeRejectsEvenValidJson(): void
    {
        $root   = $this->fixtureRepo('^14.3', '14.3.10', [$this->audit('expected none, declared \\App\\Old')]);
        $before = file_get_contents($root . '/src/Service.php');
        file_put_contents($root . '/.state/phpstan-exit', '2');

        try {
            (new ThrowsFixer($root))->run();
            self::fail('expected failed analysis');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('phpstan failed', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($root . '/src/Service.php'));
        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws RandomException
     * @throws RuntimeException
     * @throws ValueError
     */
    public function testSingleMajorRunNeedsNoCoreSwitch(): void
    {
        $root = $this->fixtureRepo('^14.3', '14.3.10', [
            $this->audit('expected \App\NewError, declared \App\Old'),
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old'),
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old'),
        ]);

        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        self::assertSame(0, (new ThrowsFixer($root))->run());

        self::assertSame(<<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws NewError // t3:14
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php, file_get_contents($root . '/src/Service.php'));

        self::assertTempConfigurationCarriesTheNeonExtension($this->phpstanConfigurations($root));

        /*
         * No core switch, no vendor wipe.
         */
        self::assertFileExists($root . '/.Build/vendor/keep-me.txt');
        self::assertCount(1, $this->composerCalls($root));
    }

    /**
     * @return array{files: array<string, mixed>, totals: array<string, mixed>}
     */
    private function audit(string $expectedAndDeclared): array
    {
        /*
         * The totals key comes first in the encoded JSON: the fixer
         * anchors the phpstan output at '{"totals"' exactly like the
         * real phpstan JSON does.
         */
        return array_merge(
            ['totals' => ['errors' => 0]],
            [
                'files' => [
                    'src/Service.php' => [
                        'messages' => [
                            [
                                'identifier' => ThrowsDocblockAuditRule::IDENTIFIER,
                                'line'       => 10,
                                'message'    => 'Method App\Service::run() @throws do not match the thrown exceptions: ' . $expectedAndDeclared . '.',
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @return list<list<string>>
     * @throws ValueError
     */
    private function composerCalls(string $root): array
    {
        $calls = [];

        foreach (file($root . '/.state/composer.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $arguments = (array) json_decode($line, true);

            /*
             * argv[0] is the resolved path of the fake binary, not the
             * command name the fixer used.
             */
            $calls[] = array_slice($arguments, 1);
        }

        return $calls;
    }

    /**
     * @param list<array<string, mixed>> $responses
     * @throws ExpectationFailedException
     * @throws RandomException
     */
    private function fixtureRepo(string $coreConstraint, string $installedVersion, array $responses): string
    {
        $root = $this->root . '/repo';

        foreach (['/.Build/bin', '/.Build/vendor/composer', '/.state/bin', '/Build/Quality', '/src'] as $dir) {
            mkdir($root . $dir, 0755, true);
        }

        file_put_contents($root . '/composer.json', json_encode(['require' => ['typo3/cms-core' => $coreConstraint]]));
        file_put_contents($root . '/.Build/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'typo3/cms-core', 'version' => $installedVersion]]]));
        file_put_contents($root . '/.Build/vendor/keep-me.txt', 'wiped on core major switch');
        file_put_contents($root . '/Build/Quality/phpstan.neon.dist', "parameters:\n    level: 0\n");
        file_put_contents($root . '/.state/responses.json', json_encode($responses));
        file_put_contents($root . '/.state/fail-calls', '');
        copy(__DIR__ . '/stubs/fake-phpstan.php', $root . '/.state/fake-phpstan.php');
        file_put_contents($root . '/src/Service.php', <<<'php'
            <?php

            namespace App;

            final class Service
            {
                /**
                 * @throws \App\Old
                 */
                public function run(): void
                {
                    throw new \App\NewError('x');
                }
            }
            php);

        copy(__DIR__ . '/stubs/fake-phpstan.php', $root . '/.Build/bin/phpstan');
        chmod($root . '/.Build/bin/phpstan', 0755);
        copy(__DIR__ . '/stubs/fake-composer.php', $root . '/.state/bin/composer');
        chmod($root . '/.state/bin/composer', 0755);
        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);
        file_put_contents($root . '/composer.lock', 'original lock');

        foreach (['composer.json', 'composer.lock', '.Build/vendor/keep-me.txt', '.Build/vendor/composer/installed.json', '.Build/bin/phpstan'] as $file) {
            $this->protectedFiles[$root . '/' . $file] = file_get_contents($root . '/' . $file);
        }

        return $root;
    }

    /**
     * The --configuration values the fixer passed to phpstan, in call
     * order.
     *
     * @return list<string>
     * @throws ValueError
     */
    private function phpstanConfigurations(string $root): array
    {
        $configurations = [];

        if (!is_file($root . '/.state/phpstan.log')) {
            return $configurations;
        }

        foreach (file($root . '/.state/phpstan.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $arguments = (array) json_decode($line, true);

            foreach ($arguments as $argument) {
                if (is_string($argument) && str_starts_with($argument, '--configuration=')) {
                    $configurations[] = substr($argument, strlen('--configuration='));
                }
            }
        }

        return $configurations;
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
