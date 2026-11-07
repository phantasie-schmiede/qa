<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Console\Application;
use Random\RandomException;

final class ApplicationTest extends TestCase
{
    private const array BASE_SCRIPTS = [
        'analyze:php'       => 'phpstan analyse --configuration=Build/Quality/phpstan.neon.dist --no-progress',
        'audit:composer'    => 'composer audit --abandoned=report',
        'fix:all'           => ['@fix:php'],
        'fix:php'           => 'php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --verbose',
        'lint:all'          => ['@analyze:php', '@audit:composer', '@lint:php', '@validate:composer'],
        'lint:php'          => 'php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose',
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
     * @throws RandomException
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
    }

    protected function tearDown(): void
    {
        chdir(sys_get_temp_dir());
        $this->removeTree($this->root);
    }

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

    public function testCheckFailsOnMissingSingleSkillLink(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'sync']);
        unlink($this->root . '/.opencode/skills/psbits-foundation');
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    public function testCheckFailsOnMissingSkillTarget(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'sync']);
        $this->removeTree($this->root . '/.Build/vendor/psbits/qa/Resources/Skill');
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

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

    public function testCheckFailsOnTamperedCallerWorkflow(): void
    {
        chdir($this->root);
        $workflow = $this->root . '/.github/workflows/ci.yml';
        mkdir(dirname($workflow), 0755, true);
        file_put_contents($workflow, "name: CI\n# stale\n");
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileEquals($workflow, __DIR__ . '/../../Resources/CI/ci.yml');
        file_put_contents($workflow, "name: CI\n# drifted\n");
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    public function testCheckFailsOnTamperedHook(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'sync']);
        file_put_contents($this->root . '/.git/hooks/pre-commit', "echo tampered\n");
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    public function testCheckPassesAfterLink(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    public function testCheckPassesAfterLinkAndAlignedAggregates(): void
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

    public function testLinkAddsCompleteToolchainToCompleteRepository(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS);
        $this->writeNpmScripts(['fix:classes', 'fix:htmlxml', 'fix:json', 'lint:classes', 'lint:htmlxml', 'lint:json']);
        $this->writeConfiguration();
        $this->writeCsFixerShim();
        chdir($this->root);

        $this->link();

        $scripts = $this->composerScripts();

        foreach (
            [
                'analyze:php', 'audit:composer', 'fix:classes', 'fix:composer', 'fix:htmlxml', 'fix:json',
                'fix:php', 'fix:throws', 'fix:xlf', 'lint:classes', 'lint:composer', 'lint:htmlxml',
                'lint:json', 'lint:php', 'lint:typoscript', 'lint:xlf', 'lint:yaml', 'validate:composer',
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

    public function testLinkAddsOnlyAlwaysScriptsToBareRepository(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS, withXlf: false, withPhpstan: false);
        chdir($this->root);

        $this->link();

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

    public function testLinkAddsSharedScriptsWithoutTouchingExistingAggregates(): void
    {
        $this->writeRepositoryFixtures();
        chdir($this->root);

        $output = $this->link();

        $scripts = $this->composerScripts();
        self::assertSame('.Build/bin/qa xlf-check', $scripts['lint:xlf']);
        self::assertSame('.Build/bin/qa xlf-fix', $scripts['fix:xlf']);
        self::assertSame('.Build/bin/qa throws-fix', $scripts['fix:throws']);
        self::assertSame(['@analyze:php', '@audit:composer', '@lint:php', '@validate:composer'], $scripts['lint:all']);
        self::assertSame(['@fix:php'], $scripts['fix:all']);
        self::assertStringContainsString('hint: lint:all deviates from the expected standard', $output);
        self::assertStringContainsString('missing: @lint:composer, @lint:xlf', $output);
    }

    public function testLinkAppliesNpmScriptConditionsPerPackageJson(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS, withXlf: false, withPhpstan: false);
        $this->writeNpmScripts(['fix:json', 'lint:js', 'lint:json', 'lint:scss']);
        chdir($this->root);

        $this->link();

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

    public function testLinkCreatesMissingAggregatesWithTheStandard(): void
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

    public function testLinkInstallsHookAndSkillLinks(): void
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

    public function testLinkInstallsMissingCallerWorkflow(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileEquals($this->root . '/.github/workflows/ci.yml', __DIR__ . '/../../Resources/CI/ci.yml');
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    public function testLinkIsIdempotent(): void
    {
        $this->writeRepositoryFixtures();
        chdir($this->root);

        self::assertSame(0, Application::execute(['qa', 'sync']));
        $first = (string) file_get_contents($this->root . '/composer.json');
        self::assertSame(0, Application::execute(['qa', 'sync']));

        self::assertSame($first, (string) file_get_contents($this->root . '/composer.json'));
    }

    public function testLinkKeepsCustomScriptEntriesAndReportsTheirDeviation(): void
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

        $output = $this->link();

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

    public function testLinkSkipsSharedScriptsOfUnapplicableRepositories(): void
    {
        $this->writeRepositoryFixtures(self::BASE_SCRIPTS, withXlf: false);
        chdir($this->root);

        self::assertSame(0, Application::execute(['qa', 'sync']));

        $scripts = $this->composerScripts();
        self::assertArrayNotHasKey('lint:xlf', $scripts);
        self::assertArrayNotHasKey('fix:xlf', $scripts);
        self::assertArrayHasKey('fix:throws', $scripts);
    }

    public function testLinkSkipsTyposcriptScriptForEmptyTyposcriptDirectory(): void
    {
        $this->writeRepositoryFixtures(self::MINIMAL_SCRIPTS);
        mkdir($this->root . '/Configuration/TypoScript', 0755, true);
        chdir($this->root);

        $this->link();

        $scripts = $this->composerScripts();
        self::assertArrayNotHasKey('lint:typoscript', $scripts);
        self::assertArrayHasKey('lint:yaml', $scripts);
    }

    public function testLinkSyncsCallerWorkflowWhenPresent(): void
    {
        chdir($this->root);
        $workflow = $this->root . '/.github/workflows/ci.yml';
        mkdir(dirname($workflow), 0755, true);
        file_put_contents($workflow, "name: CI\n# stale\n");
        self::assertSame(0, Application::execute(['qa', 'sync']));
        self::assertFileEquals($workflow, __DIR__ . '/../../Resources/CI/ci.yml');
        self::assertSame(0, Application::execute(['qa', 'check']));
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
     * @return array<string, array|string>
     */
    private function composerScripts(): array
    {
        $data = json_decode((string) file_get_contents($this->root . '/composer.json'), true);

        return $data['scripts'];
    }

    private function link(): string
    {
        ob_start();
        self::assertSame(0, Application::execute(['qa', 'sync']));

        return (string) ob_get_clean();
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

    /**
     * @param array<string, array|string> $scripts
     */
    private function writeRepositoryFixtures(array $scripts = self::BASE_SCRIPTS, bool $withXlf = true, bool $withPhpstan = true): void
    {
        $data = ['name' => 'fixture-extension', 'scripts' => $scripts];
        file_put_contents(
            $this->root . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

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
