<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\Console\Application;
use Random\RandomException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final class ApplicationTest extends TestCase
{
    private const array BASE_SCRIPTS = [
        'analyze:php'       => 'phpstan analyse --configuration=Build/Quality/phpstan.neon.dist --no-progress',
        'audit:composer'    => 'composer audit --abandoned=report',
        'fix:all'           => ['@fix:php'],
        'fix:php'           => 'php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php fix',
        'lint:all'          => ['@analyze:php', '@audit:composer', '@lint:php', '@validate:composer'],
        'lint:php'          => 'php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php lint',
        'post-install-cmd'  => ['.Build/bin/qa sync'],
        'post-update-cmd'   => ['.Build/bin/qa sync'],
        'qa'                => ['@fix:all', '@lint:all', '@test:all'],
        'test:all'          => ['@test:unit'],
        'test:unit'         => 'phpunit',
        'validate:composer' => 'composer validate --no-check-publish',
    ];
    private const array MINIMAL_SCRIPTS = [
        'post-install-cmd' => ['.Build/bin/qa sync'],
        'post-update-cmd'  => ['.Build/bin/qa sync'],
        'test:all'         => ['@test:unit'],
        'test:unit'        => 'phpunit',
    ];

    private string $root;

    /**
     * @throws LogicException
     * @throws RandomException
     * @throws RuntimeException
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-app-' . bin2hex(random_bytes(6));

        foreach (['psbits-qa', 'psbits-cgl', 'psbits-foundation', 'psbits-v14-compat'] as $skill) {
            $skillDir = $this->root . '/.Build/vendor/psbits/qa/Resources/Skill/' . $skill;
            mkdir($skillDir, 0755, true);
            file_put_contents($skillDir . '/SKILL.md', "# Skill\n");
        }

        file_put_contents($this->root . '/composer.json', "{}\n");
        (new Process(['git', 'init', '--quiet', $this->root]))->mustRun();
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function tearDown(): void
    {
        chdir(sys_get_temp_dir());
        $this->removeTree($this->root);
    }

    /** @return array<string, array{string, string}> */
    public static function conflictingPaths(): array
    {
        return [
            'hook'                      => ['.git/hooks/pre-commit', 'file'],
            'hook symlink'              => ['.git/hooks/pre-commit', 'symlink'],
            'missing skill link target' => ['.opencode/skills/psbits-qa', 'broken symlink'],
            'skill directory'           => ['.opencode/skills/psbits-qa', 'directory'],
            'skill symlink'             => ['.opencode/skills/psbits-qa', 'symlink'],
            'workflow'                  => ['.github/workflows/ci.yml', 'file'],
            'workflow symlink'          => ['.github/workflows/ci.yml', 'symlink'],
        ];
    }

    /** @return array<string, array{string, list<string>, string}> */
    public static function invalidAggregates(): array
    {
        return [
            'duplicate fix'  => ['fix:all', ['@fix:composer', '@fix:throws', '@fix:php', '@fix:php'], 'duplicates: @fix:php'],
            'duplicate lint' => ['lint:all', ['@analyze:php', '@audit:composer', '@lint:composer', '@lint:php', '@validate:composer', '@lint:php'], 'duplicates: @lint:php'],
            'duplicate qa'   => ['qa', ['@fix:all', '@lint:all', '@lint:all'], 'duplicates: @lint:all'],
            'fix order'      => ['fix:all', ['@fix:php', '@fix:throws', '@fix:composer'], 'order differs'],
            'qa order'       => ['qa', ['@lint:all', '@fix:all'], 'order differs'],
        ];
    }

    /** @return array<string, array{list<string>}> */
    public static function suites(): array
    {
        return ['both' => [['Unit', 'Functional']], 'functional' => [['Functional']], 'none' => [[]], 'unit' => [['Unit']]];
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckFailsOnAggregateDeviation(): void
    {
        $scripts               = self::BASE_SCRIPTS;
        $scripts['fix:throws'] = '.Build/bin/qa throws-fix';
        $scripts['fix:xlf']    = '.Build/bin/qa xlf-fix';
        $scripts['lint:xlf']   = '.Build/bin/qa xlf-check';
        $this->writeRepositoryFixtures($scripts);
        chdir($this->root);

        [$exit, $output] = $this->check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: lint:all deviates from the expected standard', $output);
        self::assertStringContainsString('missing: @lint:xlf', $output);
        self::assertStringContainsString('"lint:all": [', $output);
    }

    /**
     * @param list<string> $members
     * @throws ExpectationFailedException
     */
    #[DataProvider('invalidAggregates')]
    public function testCheckFailsOnInvalidAggregateWithoutSyncRewritingIt(string $aggregate, array $members, string $reason): void
    {
        $this->writeCsFixerShim();
        $this->writeRepositoryFixtures([], false);
        chdir($this->root);
        $this->sync();
        $scripts             = $this->composerScripts();
        $scripts[$aggregate] = $members;
        $this->writeRepositoryFixtures($scripts, false);
        $before = file_get_contents($this->root . '/composer.json');

        $output = $this->sync();
        self::assertStringContainsString('hint: ' . $aggregate . ' deviates', $output);
        self::assertStringContainsString($reason, $output);
        self::assertSame($before, file_get_contents($this->root . '/composer.json'));
        [$exit, $output] = $this->check();
        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: ' . $aggregate . ' deviates', $output);
        self::assertStringContainsString($reason, $output);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckFailsOnMissingAllowPluginsConfig(): void
    {
        $this->writeRepositoryFixtures();
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));

        $scripts = $this->composerScripts();
        $this->writeRepositoryFixtures($scripts, true, true, false);

        [$exit, $output] = $this->check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: allow-plugins.dealerdirect/phpcodesniffer-composer-installer missing or not true (run qa sync and commit)', $output);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckFailsOnMissingSharedScript(): void
    {
        $this->writeRepositoryFixtures();
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));

        $scripts = $this->composerScripts();
        unset($scripts['lint:xlf']);
        $this->writeRepositoryFixtures($scripts);

        [$exit, $output] = $this->check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: script lint:xlf missing (run qa sync and commit)', $output);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckFailsOnMissingSingleSkillLink(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'sync']);
        unlink($this->root . '/.opencode/skills/psbits-foundation');
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    /**
     * @throws ExpectationFailedException
     * @throws UnexpectedValueException
     */
    public function testCheckFailsOnMissingSkillTarget(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'sync']);
        $this->removeTree($this->root . '/.Build/vendor/psbits/qa/Resources/Skill');
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckFailsOnMissingToolingScript(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS);
        $this->writeConfiguration();
        $this->writeCsFixerShim();
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));

        $scripts = $this->composerScripts();
        unset($scripts['lint:typoscript']);
        $this->writeRepositoryFixtures($scripts);

        [$exit, $output] = $this->check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: script lint:typoscript missing (run qa sync and commit)', $output);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckFailsOnTamperedCallerWorkflow(): void
    {
        chdir($this->root);
        $workflow = $this->root . '/.github/workflows/ci.yml';
        mkdir(dirname($workflow), 0755, true);
        file_put_contents($workflow, "# This file is managed by `qa sync`\nname: CI\n# stale\n");
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileEquals($workflow, __DIR__ . '/../../Resources/CI/ci.yml');
        file_put_contents($workflow, "name: CI\n# drifted\n");
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckFailsOnTamperedHook(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'sync']);
        file_put_contents($this->root . '/.git/hooks/pre-commit', "echo tampered\n");
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckIgnoresLintAndTestOrderButRejectsDuplicateTests(): void
    {
        mkdir($this->root . '/Tests/Unit', 0755, true);
        file_put_contents($this->root . '/Tests/Unit/ExampleTest.php', '<?php');
        chdir($this->root);
        $this->sync();
        $scripts             = $this->composerScripts();
        $scripts['lint:all'] = array_reverse($scripts['lint:all']);
        $scripts['test:all'] = array_reverse($scripts['test:all']);
        $this->writeComposerJson($scripts);
        self::assertSame(0, $this->check()[0]);

        $scripts['test:all'][] = '@test:unit';
        $this->writeComposerJson($scripts);
        [$exit, $output] = $this->check();
        self::assertSame(1, $exit);
        self::assertStringContainsString('duplicates: @test:unit', $output);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckPassesAfterSync(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testCheckPassesAfterSyncAndAlignedAggregates(): void
    {
        $this->writeRepositoryFixtures();
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));

        // The maintainer aligns the aggregates with the reported standard.
        $scripts             = $this->composerScripts();
        $scripts['lint:all'] = ['@analyze:php', '@audit:composer', '@lint:composer', '@lint:php', '@lint:xlf', '@validate:composer'];
        $scripts['fix:all']  = ['@fix:composer', '@fix:throws', '@fix:xlf', '@fix:php'];
        $this->writeRepositoryFixtures($scripts);

        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncAddsCompleteToolchainToCompleteRepository(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS);
        $this->writeNpmScripts(['fix:classes', 'fix:htmlxml', 'fix:json', 'lint:classes', 'lint:htmlxml', 'lint:json']);
        $this->writeConfiguration();
        $this->writeCsFixerShim();
        $this->writePhpcsShim();
        chdir($this->root);

        $this->sync();

        $scripts = $this->composerScripts();

        foreach (
            [
                'analyze:php', 'audit:composer', 'fix:classes', 'fix:composer', 'fix:htmlxml', 'fix:json',
                'fix:php', 'fix:throws', 'fix:xlf', 'lint:classes', 'lint:composer',
                'lint:htmlxml', 'lint:json', 'lint:php', 'lint:typoscript', 'lint:xlf',
                'lint:yaml', 'validate:composer',
            ] as $name
        ) {
            self::assertArrayHasKey($name, $scripts);
        }

        self::assertSame('npm run lint:classes', $scripts['lint:classes']);
        self::assertSame('npm run fix:htmlxml', $scripts['fix:htmlxml']);
        self::assertSame('composer normalize', $scripts['fix:composer']);
        self::assertSame('composer normalize --dry-run --diff --no-check-lock --no-update-lock', $scripts['lint:composer']);
        self::assertSame('npm run fix:json', $scripts['fix:json']);
        self::assertSame('npm run lint:json', $scripts['lint:json']);
        self::assertSame('php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php fix', $scripts['fix:php']);
        self::assertSame('php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php lint', $scripts['lint:php']);
        self::assertSame(
            'typoscript-lint --config=.Build/vendor/psbits/qa/Resources/Quality/typoscript-lint.yml --ansi Configuration/TypoScript',
            $scripts['lint:typoscript'],
        );
        self::assertSame('yamllint -c .Build/vendor/psbits/qa/Resources/Quality/yamllint.yml Configuration', $scripts['lint:yaml']);
        self::assertSame(
            ['@analyze:php', '@audit:composer', '@lint:classes', '@lint:composer', '@lint:htmlxml', '@lint:json', '@lint:php', '@lint:typoscript', '@lint:xlf', '@lint:yaml', '@validate:composer'],
            $scripts['lint:all'],
        );
        self::assertSame(['@fix:classes', '@fix:composer', '@fix:htmlxml', '@fix:json', '@fix:throws', '@fix:xlf', '@fix:php'], $scripts['fix:all']);
        self::assertSame(['@fix:all', '@lint:all', '@test:all'], $scripts['qa']);
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncAddsDockerImagesOnlyWhenDockerTestsExist(): void
    {
        chdir($this->root);
        $this->sync();
        self::assertArrayNotHasKey('docker:images', $this->composerScripts());

        $scripts                = $this->composerScripts();
        $scripts['test:docker'] = 'custom docker runner';
        $this->writeRepositoryFixtures($scripts, false, false);
        [$exit, $output] = $this->check();
        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: script docker:images missing', $output);

        $this->sync();
        self::assertSame([
            'Composer\\Config::disableProcessTimeout',
            'sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/buildTestImages.sh',
        ], $this->composerScripts()['docker:images']);
        $before = file_get_contents($this->root . '/composer.json');
        $this->sync();
        self::assertSame($before, file_get_contents($this->root . '/composer.json'));
        self::assertSame(0, $this->check()[0]);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncAddsMissingAllowPluginsConfig(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS, false, false, false);
        chdir($this->root);

        $output = $this->sync();

        self::assertStringContainsString('added config allow-plugins.dealerdirect/phpcodesniffer-composer-installer', $output);
        $data = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        self::assertSame(true, $data['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer']);
        self::assertSame(0, $this->check()[0]);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncAddsOnlyAlwaysScriptsToBareRepository(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS, withXlf: false, withPhpstan: false);
        chdir($this->root);

        $this->sync();

        $scripts = $this->composerScripts();
        self::assertSame('composer audit --abandoned=report', $scripts['audit:composer']);
        self::assertSame('composer normalize --dry-run --diff --no-check-lock --no-update-lock', $scripts['lint:composer']);
        self::assertSame('composer normalize', $scripts['fix:composer']);
        self::assertSame('composer validate --no-check-publish', $scripts['validate:composer']);
        self::assertArrayNotHasKey('analyze:php', $scripts);
        self::assertArrayNotHasKey('lint:php', $scripts);
        self::assertArrayNotHasKey('fix:php', $scripts);
        self::assertArrayNotHasKey('fix:throws', $scripts);
        self::assertArrayNotHasKey('lint:xlf', $scripts);
        self::assertArrayNotHasKey('lint:yaml', $scripts);
        self::assertArrayNotHasKey('lint:typoscript', $scripts);
        self::assertArrayNotHasKey('lint:classes', $scripts);
        self::assertArrayNotHasKey('lint:json', $scripts);
        self::assertArrayNotHasKey('fix:json', $scripts);
        self::assertSame(['@audit:composer', '@lint:composer', '@validate:composer'], $scripts['lint:all']);
        self::assertSame(['@fix:composer'], $scripts['fix:all']);
        self::assertSame(['@fix:all', '@lint:all', '@test:all'], $scripts['qa']);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncAddsPhpScriptsWhenEitherShimExists(): void
    {
        $this->writePhpcsShim();
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS, false, false);
        chdir($this->root);

        $this->sync();
        $scripts = $this->composerScripts();
        self::assertSame('php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php lint', $scripts['lint:php']);
        self::assertSame('php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php fix', $scripts['fix:php']);
        self::assertContains('@lint:php', $scripts['lint:all']);
        self::assertContains('@fix:php', $scripts['fix:all']);

        /*
         * Adding the PHP-CS-Fixer shim keeps the scripts unchanged - both
         * tools run behind the same lint:php/fix:php pair.
         */
        $this->writeCsFixerShim();
        $before = file_get_contents($this->root . '/composer.json');
        $this->sync();
        self::assertSame($before, file_get_contents($this->root . '/composer.json'));
        self::assertSame(0, $this->check()[0]);
    }

    /**
     * @param list<string> $suites
     * @throws Exception
     * @throws ExpectationFailedException
     */
    #[DataProvider('suites')]
    public function testSyncAddsScriptsForActualTestSuites(array $suites): void
    {
        foreach (['Unit', 'Functional'] as $suite) {
            mkdir($this->root . '/Tests/' . $suite . '/Nested', 0755, true);
            file_put_contents($this->root . '/Tests/' . $suite . '/Fixture.php', '<?php');

            if (in_array($suite, $suites, true)) {
                file_put_contents($this->root . '/Tests/' . $suite . '/Nested/ExampleTest.php', '<?php');
            }
        }

        chdir($this->root);
        $this->sync();
        $scripts = $this->composerScripts();

        foreach (['Functional' => 'test:functional', 'Unit' => 'test:unit'] as $suite => $script) {
            self::assertSame(in_array($suite, $suites, true), isset($scripts[$script]));
        }

        foreach (['test:local', 'test:docker', 'test:coverage', 'test:coverage:update-baseline', 'test:all', 'docker:images'] as $script) {
            self::assertSame([] !== $suites, isset($scripts[$script]));
        }

        if ([] !== $suites) {
            $members = [];

            foreach (['Functional' => '@test:functional', 'Unit' => '@test:unit'] as $suite => $member) {
                if (in_array($suite, $suites, true)) {
                    $members[] = $member;
                }
            }

            self::assertSame(array_merge($members, ['@test:local', '@test:docker']), $scripts['test:all']);
            self::assertContains('@test:all', $scripts['qa']);
        }

        self::assertSame(0, $this->check()[0]);
        $before = file_get_contents($this->root . '/composer.json');
        $this->sync();
        self::assertSame($before, file_get_contents($this->root . '/composer.json'));

        if ([] !== $suites) {
            unset($scripts['test:coverage']);
            file_put_contents($this->root . '/composer.json', json_encode(['scripts' => $scripts]));
            [$code, $output] = $this->check();
            self::assertSame(1, $code);
            self::assertStringContainsString('FAIL: script test:coverage missing', $output);
        }
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncAddsSharedScriptsWithoutTouchingExistingAggregates(): void
    {
        $this->writeRepositoryFixtures();
        chdir($this->root);

        $output = $this->sync();

        $scripts = $this->composerScripts();
        self::assertSame('.Build/bin/qa xlf-check', $scripts['lint:xlf']);
        self::assertSame('.Build/bin/qa xlf-fix', $scripts['fix:xlf']);
        self::assertSame('.Build/bin/qa throws-fix', $scripts['fix:throws']);
        self::assertSame(['@analyze:php', '@audit:composer', '@lint:php', '@validate:composer'], $scripts['lint:all']);
        self::assertSame(['@fix:php'], $scripts['fix:all']);
        self::assertStringContainsString('hint: lint:all deviates from the expected standard', $output);
        self::assertStringContainsString('missing: @lint:composer, @lint:xlf', $output);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncAppliesNpmScriptConditionsPerPackageJson(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS, withXlf: false, withPhpstan: false);
        $this->writeNpmScripts(['fix:json', 'lint:js', 'lint:json', 'lint:scss']);
        chdir($this->root);

        $this->sync();

        $scripts = $this->composerScripts();
        self::assertSame('npm run lint:js', $scripts['lint:js']);
        self::assertSame('npm run lint:json', $scripts['lint:json']);
        self::assertSame('npm run lint:scss', $scripts['lint:scss']);
        self::assertArrayNotHasKey('lint:classes', $scripts);
        self::assertArrayNotHasKey('fix:classes', $scripts);
        self::assertArrayNotHasKey('lint:htmlxml', $scripts);
        self::assertArrayNotHasKey('fix:htmlxml', $scripts);
        self::assertArrayNotHasKey('fix:throws', $scripts);
        self::assertArrayNotHasKey('lint:xlf', $scripts);
        self::assertSame(['@audit:composer', '@lint:composer', '@lint:js', '@lint:json', '@lint:scss', '@validate:composer'], $scripts['lint:all']);
        self::assertSame(['@fix:composer', '@fix:json'], $scripts['fix:all']);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncCreatesMissingAggregatesWithTheStandard(): void
    {
        $scripts = self::BASE_SCRIPTS;
        unset($scripts['fix:all'], $scripts['qa']);
        $this->writeRepositoryFixtures($scripts);
        chdir($this->root);

        self::assertSame(0, Application::execute(['qa', 'sync']));

        $scripts = $this->composerScripts();
        self::assertSame(['@fix:composer', '@fix:throws', '@fix:xlf', '@fix:php'], $scripts['fix:all']);
        self::assertSame(['@fix:all', '@lint:all', '@test:all'], $scripts['qa']);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncCustomFixersRunBeforeFinalPhpFormatter(): void
    {
        $this->writeCsFixerShim();
        $this->writeRepositoryFixtures(['fix:alpha' => 'custom a', 'fix:zeta' => 'custom z'], false);
        chdir($this->root);
        $this->sync();
        self::assertSame(['@fix:composer', '@fix:throws', '@fix:alpha', '@fix:zeta', '@fix:php'], $this->composerScripts()['fix:all']);
        self::assertSame(0, $this->check()[0]);
        $scripts            = $this->composerScripts();
        $scripts['fix:all'] = ['@fix:composer', '@fix:throws', '@fix:php', '@fix:alpha', '@fix:zeta'];
        $this->writeRepositoryFixtures($scripts, false);
        self::assertSame(1, $this->check()[0]);
        self::assertSame($scripts['fix:all'], $this->composerScripts()['fix:all']);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncDryRunDoesNotCreateFiles(): void
    {
        chdir($this->root);
        $composer = file_get_contents($this->root . '/composer.json');
        ob_start();
        $exit   = Application::execute(['qa', 'sync', '--dry-run']);
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit);
        self::assertStringContainsString('would sync pre-commit hook', $output);
        self::assertStringContainsString('would add script', $output);
        self::assertSame($composer, file_get_contents($this->root . '/composer.json'));
        self::assertFileDoesNotExist($this->root . '/.git/hooks/pre-commit');
        self::assertDirectoryDoesNotExist($this->root . '/.github');
        self::assertDirectoryDoesNotExist($this->root . '/.opencode');
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncInstallsHookAndSkillLinks(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));

        $hook = $this->root . '/.git/hooks/pre-commit';
        self::assertFileExists($hook);
        self::assertFileEquals($hook, __DIR__ . '/../../Resources/GitHooks/pre-commit');
        self::assertNotSame(0, fileperms($hook) & 0111);

        foreach (['psbits-qa', 'psbits-cgl', 'psbits-foundation', 'psbits-v14-compat'] as $skill) {
            $link = $this->root . '/.opencode/skills/' . $skill;
            self::assertTrue(is_link($link));
            self::assertSame('../../.Build/vendor/psbits/qa/Resources/Skill/' . $skill, readlink($link));
            self::assertFileExists($link . '/SKILL.md');
        }
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncInstallsMissingCallerWorkflow(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileEquals($this->root . '/.github/workflows/ci.yml', __DIR__ . '/../../Resources/CI/ci.yml');
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncIsIdempotent(): void
    {
        $this->writeRepositoryFixtures();
        chdir($this->root);

        self::assertSame(0, Application::execute(['qa', 'sync']));
        $first      = (string) file_get_contents($this->root . '/composer.json');
        $secondSync = Application::execute(['qa', 'sync']);

        self::assertSame(0, $secondSync);

        self::assertSame($first, (string) file_get_contents($this->root . '/composer.json'));
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncKeepsCustomScriptEntriesAndReportsTheirDeviation(): void
    {
        $scripts             = self::BASE_SCRIPTS;
        $scripts['lint:all'] = [
            '@analyze:php',
            "composer audit --abandoned=report || echo 'Warning: audit reported findings'",
            '@lint:php',
            '@validate:composer',
        ];
        $scripts['lint:yaml'] = 'yamllint -c .Build/vendor/psbits/qa/Resources/Quality/yamllint.yml Build/Quality Configuration';
        $this->writeRepositoryFixtures($scripts);
        chdir($this->root);

        $output = $this->sync();

        $scripts = $this->composerScripts();
        self::assertSame(
            'yamllint -c .Build/vendor/psbits/qa/Resources/Quality/yamllint.yml Build/Quality Configuration',
            $scripts['lint:yaml'],
        );
        self::assertSame(
            [
                '@analyze:php',
                "composer audit --abandoned=report || echo 'Warning: audit reported findings'",
                '@lint:php',
                '@validate:composer',
            ],
            $scripts['lint:all'],
        );
        self::assertStringContainsString('hint: lint:all deviates from the expected standard', $output);
        self::assertStringContainsString('missing: @audit:composer', $output);
        self::assertStringContainsString("extra: composer audit --abandoned=report || echo 'Warning: audit reported findings'", $output);
        self::assertStringContainsString('@lint:yaml', $output);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncKeepsDisabledAllowPluginsAndCheckFails(): void
    {
        $data = [
            'config'  => ['allow-plugins' => ['dealerdirect/phpcodesniffer-composer-installer' => false]],
            'name'    => 'fixture-extension',
            'scripts' => self::MINIMAL_SCRIPTS,
        ];
        file_put_contents(
            $this->root . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
        chdir($this->root);

        $output = $this->sync();

        self::assertStringContainsString('hint: allow-plugins.dealerdirect/phpcodesniffer-composer-installer is false, the shared tooling requires true', $output);
        $data = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        self::assertSame(false, $data['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer']);

        $before = (string) file_get_contents($this->root . '/composer.json');
        $this->sync();
        self::assertSame($before, (string) file_get_contents($this->root . '/composer.json'));
        [$exit, $output] = $this->check();
        self::assertSame(1, $exit);
        self::assertStringContainsString('FAIL: allow-plugins.dealerdirect/phpcodesniffer-composer-installer missing or not true (run qa sync and commit)', $output);
    }

    /** @throws ExpectationFailedException */
    public function testSyncMissingSkillTargetDoesNotWriteFiles(): void
    {
        chdir($this->root);
        unlink($this->root . '/.Build/vendor/psbits/qa/Resources/Skill/psbits-cgl/SKILL.md');
        self::assertSame(1, Application::execute(['qa', 'sync']));
        self::assertFileDoesNotExist($this->root . '/.git/hooks/pre-commit');
        self::assertDirectoryDoesNotExist($this->root . '/.github');
        self::assertDirectoryDoesNotExist($this->root . '/.opencode');
        self::assertSame("{}\n", file_get_contents($this->root . '/composer.json'));
    }

    /** @throws ExpectationFailedException */
    #[DataProvider('conflictingPaths')]
    public function testSyncPreservesConflictsBeforeWritingAnything(string $path, string $kind): void
    {
        chdir($this->root);
        $destination = $this->root . '/' . $path;

        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        $sentinel = $this->root . '/keep-me.txt';
        file_put_contents($sentinel, 'user content');

        if ('directory' === $kind) {
            mkdir($destination);
            file_put_contents($destination . '/custom.md', 'user skill');
            symlink($sentinel, $destination . '/external-link');
        } elseif ('broken symlink' === $kind) {
            symlink($this->root . '/missing-target', $destination);
        } elseif ('symlink' === $kind) {
            symlink($sentinel, $destination);
        } else {
            file_put_contents($destination, 'user content');
        }

        $composer = file_get_contents($this->root . '/composer.json');

        foreach ([['qa', 'sync'], ['qa', 'sync', '--dry-run']] as $arguments) {
            ob_start();
            $exit   = Application::execute($arguments);
            $output = (string) ob_get_clean();
            self::assertSame(1, $exit);
            self::assertStringContainsString('CONFLICT: ' . $destination, $output);
            self::assertSame($composer, file_get_contents($this->root . '/composer.json'));
            self::assertSame('user content', file_get_contents($sentinel));

            if ('broken symlink' === $kind) {
                self::assertSame($this->root . '/missing-target', readlink($destination));
            } elseif ('directory' === $kind) {
                self::assertSame('user skill', file_get_contents($destination . '/custom.md'));
            } else {
                self::assertSame('user content', file_get_contents($destination));
            }
        }

        self::assertFileDoesNotExist($this->root . '/.opencode/skills/psbits-cgl');
        self::assertSame(0, Application::execute(['qa', 'sync', '--force', '--dry-run']));
        self::assertSame($composer, file_get_contents($this->root . '/composer.json'));
        self::assertSame(0, Application::execute(['qa', 'sync', '--force']));
        self::assertSame('user content', file_get_contents($sentinel));
        self::assertSame(0, $this->check()[0]);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncPreservesCustomDockerImagesScript(): void
    {
        $scripts                  = self::MINIMAL_SCRIPTS;
        $scripts['test:docker']   = 'custom runner';
        $scripts['docker:images'] = 'custom builder';
        $this->writeRepositoryFixtures($scripts, false, false);
        chdir($this->root);
        $this->sync();
        self::assertSame('custom builder', $this->composerScripts()['docker:images']);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncPreservesCustomTestsAndReportsTestAllDeviation(): void
    {
        mkdir($this->root . '/Tests/Unit', 0755, true);
        file_put_contents($this->root . '/Tests/Unit/ExampleTest.php', '<?php');
        file_put_contents($this->root . '/composer.json', json_encode(['scripts' => ['test:all' => ['@test:unit'], 'test:unit' => 'custom unit']]));
        chdir($this->root);
        $output = $this->sync();
        self::assertSame('custom unit', $this->composerScripts()['test:unit']);
        self::assertSame(['@test:unit'], $this->composerScripts()['test:all']);
        self::assertStringContainsString('hint: test:all deviates', $output);
        [$code, $output] = $this->check();
        self::assertSame(1, $code);
        self::assertStringContainsString('FAIL: test:all deviates', $output);
    }

    /** @throws ExpectationFailedException */
    public function testSyncRepairsHookPermissionsAndUpgradesLegacyHook(): void
    {
        chdir($this->root);
        $hook = $this->root . '/.git/hooks/pre-commit';
        file_put_contents($hook, "#!/usr/bin/env bash\nset -euo pipefail\n\ncomposer lint:all\n");
        self::assertSame(0, Application::execute(['qa', 'sync']));
        chmod($hook, 0644);
        clearstatcache();
        self::assertSame(1, $this->check()[0]);
        self::assertSame(0, Application::execute(['qa', 'sync', '--dry-run']));
        clearstatcache();
        self::assertFalse(is_executable($hook));
        self::assertSame(0, Application::execute(['qa', 'sync']));
        clearstatcache();
        self::assertTrue(is_executable($hook));
        self::assertSame(0, $this->check()[0]);
    }

    /**
     * @throws ExpectationFailedException
     * @throws LogicException
     * @throws RuntimeException
     */
    public function testSyncResolvesGitFileAndCustomHooksPath(): void
    {
        chdir($this->root);
        $metadata = $this->root . '/git-metadata';
        rename($this->root . '/.git', $metadata);
        file_put_contents($this->root . '/.git', 'gitdir: ' . $metadata . "\n");
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileExists($metadata . '/hooks/pre-commit');
        self::assertSame(0, $this->check()[0]);

        // Linked worktrees keep hooks in the common Git directory.
        $worktreeMetadata = $metadata . '/worktrees/fixture';
        mkdir($worktreeMetadata, 0755, true);
        copy($metadata . '/HEAD', $worktreeMetadata . '/HEAD');
        file_put_contents($worktreeMetadata . '/commondir', "../..\n");
        file_put_contents($worktreeMetadata . '/gitdir', $this->root . "/.git\n");
        file_put_contents($this->root . '/.git', 'gitdir: ' . $worktreeMetadata . "\n");
        unlink($metadata . '/hooks/pre-commit');
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileExists($metadata . '/hooks/pre-commit');
        self::assertDirectoryDoesNotExist($worktreeMetadata . '/hooks');
        self::assertSame(0, $this->check()[0]);

        foreach (['custom-hooks', $this->root . '/absolute-hooks'] as $hooksPath) {
            (new Process(['git', 'config', 'core.hooksPath', $hooksPath], $this->root))->mustRun();
            self::assertSame(0, Application::execute(['qa', 'sync']));
            $absolute = str_starts_with($hooksPath, '/') ? $hooksPath : $this->root . '/' . $hooksPath;
            self::assertFileExists($absolute . '/pre-commit');
            self::assertSame(0, $this->check()[0]);
        }
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncSkipsSharedScriptsOfUnapplicableRepositories(): void
    {
        $this->writeRepositoryFixtures(self::BASE_SCRIPTS, withXlf: false);
        chdir($this->root);

        self::assertSame(0, Application::execute(['qa', 'sync']));

        $scripts = $this->composerScripts();
        self::assertArrayNotHasKey('lint:xlf', $scripts);
        self::assertArrayNotHasKey('fix:xlf', $scripts);
        self::assertArrayHasKey('fix:throws', $scripts);
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     */
    public function testSyncSkipsTyposcriptScriptForEmptyTyposcriptDirectory(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS);
        mkdir($this->root . '/Configuration/TypoScript', 0755, true);
        chdir($this->root);

        $this->sync();

        $scripts = $this->composerScripts();
        self::assertArrayNotHasKey('lint:typoscript', $scripts);
        self::assertArrayHasKey('lint:yaml', $scripts);
    }

    /**
     * @throws ExpectationFailedException
     */
    public function testSyncUpdatesCallerWorkflowWhenPresent(): void
    {
        chdir($this->root);
        $workflow = $this->root . '/.github/workflows/ci.yml';
        mkdir(dirname($workflow), 0755, true);
        file_put_contents($workflow, "# This file is managed by `qa sync`\nname: CI\n# stale\n");
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileEquals($workflow, __DIR__ . '/../../Resources/CI/ci.yml');
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    /** @throws ExpectationFailedException */
    public function testSyncWithoutGitMetadataSkipsHook(): void
    {
        chdir($this->root);
        rename($this->root . '/.git', $this->root . '/git-metadata');
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileDoesNotExist($this->root . '/.git');
        self::assertSame(0, $this->check()[0]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function check(): array
    {
        ob_start();
        $exit = Application::execute(['qa', 'check']);

        return [$exit, (string) ob_get_clean()];
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function composerScripts(): array
    {
        $data = json_decode((string) file_get_contents($this->root . '/composer.json'), true);

        return $data['scripts'];
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
     * @throws ExpectationFailedException
     */
    private function sync(): string
    {
        ob_start();
        self::assertSame(0, Application::execute(['qa', 'sync']));

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, string|list<string>> $scripts
     */
    private function writeComposerJson(array $scripts): void
    {
        $data = [
            'config'  => ['allow-plugins' => ['dealerdirect/phpcodesniffer-composer-installer' => true]],
            'name'    => 'fixture-extension',
            'scripts' => $scripts,
        ];
        file_put_contents(
            $this->root . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private function writeConfiguration(): void
    {
        $typoscriptDir = $this->root . '/Configuration/TypoScript';
        mkdir($typoscriptDir, 0755, true);
        file_put_contents($typoscriptDir . '/setup.typoscript', "page = DIV\n");
    }

    private function writeCsFixerShim(): void
    {
        $qualityDir = $this->root . '/Build/Quality';

        if (!is_dir($qualityDir)) {
            mkdir($qualityDir, 0755, true);
        }

        file_put_contents($qualityDir . '/.php-cs-fixer.dist.php', "<?php\nreturn [];\n");
    }

    /**
     * @param list<string> $scripts
     */
    private function writeNpmScripts(array $scripts): void
    {
        $data = ['name' => 'fixture-extension', 'scripts' => array_fill_keys($scripts, 'echo ok')];
        file_put_contents(
            $this->root . '/package.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private function writePhpcsShim(): void
    {
        $qualityDir = $this->root . '/Build/Quality';

        if (!is_dir($qualityDir)) {
            mkdir($qualityDir, 0755, true);
        }

        file_put_contents($qualityDir . '/.phpcs.xml.dist', '<?xml version="1.0"?>\n<ruleset name="fixture">\n</ruleset>\n');
    }

    /**
     * @param array<string, string|list<string>> $scripts
     */
    private function writeRepositoryFixtures(array $scripts = self::BASE_SCRIPTS, bool $withXlf = true, bool $withPhpstan = true, bool $withAllowPlugins = true): void
    {
        if ($withAllowPlugins) {
            $this->writeComposerJson($scripts);
        } else {
            file_put_contents(
                $this->root . '/composer.json',
                json_encode(['name' => 'fixture-extension', 'scripts' => $scripts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }

        if ($withXlf) {
            $languageDir = $this->root . '/Resources/Private/Language';

            if (!is_dir($languageDir)) {
                mkdir($languageDir, 0755, true);
            }

            file_put_contents($languageDir . '/locallang.xlf', '');
        }

        if ($withPhpstan) {
            $qualityDir = $this->root . '/Build/Quality';

            if (!is_dir($qualityDir)) {
                mkdir($qualityDir, 0755, true);
            }

            file_put_contents($qualityDir . '/phpstan.neon.dist', "# fixture\n");
        }
    }
}
