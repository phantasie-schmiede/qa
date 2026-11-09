<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpStan\ThrowsDocblockAuditRule;
use PSBits\Qa\PhpStan\ThrowsDocblockRule;
use PSBits\Qa\Quality\ThrowsFixer;
use Random\RandomException;
use RuntimeException;
use ValueError;

final class ThrowsFixerTest extends TestCase
{
    private string $oldPath;
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
     * @throws UnexpectedValueException
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        putenv('PATH=' . $this->oldPath);
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
    public function testDualMajorRunSwitchesCoresAndMergesWithMarkers(): void
    {
        $root = $this->fixtureRepo('^13.4 || ^14.3', '14.3.10', [
            // the 14.3 fix loop (the installed major runs first)
            $this->audit('expected \App\NewError, declared \App\Old'),
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old'),
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old, \App\Other'),
            // the 13.4 fix loop (no missing lines: converged in round 0)
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old, \App\Other'),
            // the cross validation over both cores (matrix order)
            $this->audit('expected \App\NewError, declared \App\NewError, \App\Old, \App\Other'),
            $this->audit('expected \App\NewError, \App\Other, declared \App\NewError, \App\Old, \App\Other'),
        ]);

        putenv('PATH=' . $root . '/.state/bin:' . $this->oldPath);

        self::assertSame(0, (new ThrowsFixer($root))->run());

        self::assertSame([
            ['update', '--with', 'typo3/cms-core:^13.4', '-W'],
            ['update', '--with', 'typo3/cms-core:^14.3', '-W'],
        ], $this->composerCalls($root));

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
    public function testFailedSwitchRestoresTheStartingCoreAndSkipsTheMerge(): void
    {
        $root = $this->fixtureRepo('^13.4 || ^14.3', '14.3.10', [
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
            ['update', '--with', 'typo3/cms-core:^13.4', '-W'],
            ['update', '--with', 'typo3/cms-core:^14.3', '-W'],
            ['update', '--with', 'typo3/cms-core:14.3.10', '-W'],
        ], $this->composerCalls($root));

        /*
         * The merge only runs after every core converged, so a failed run
         * leaves the additive state: no markers, the extra line intact.
         */
        $content = (string) file_get_contents($root . '/src/Service.php');
        self::assertStringNotContainsString('t3:', $content);
        self::assertStringContainsString('@throws Other', $content);
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
        self::assertFileDoesNotExist($root . '/.state/composer.log');
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
        file_put_contents($root . '/Build/Quality/phpstan.neon.dist', "parameters:\n    level: 0\n");
        file_put_contents($root . '/.state/responses.json', json_encode($responses));
        file_put_contents($root . '/.state/fail-calls', '');
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

        return $root;
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
