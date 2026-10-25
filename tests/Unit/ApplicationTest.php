<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\Console\Application;

final class ApplicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psbits-qa-app-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.Build/vendor/psbits/qa/Resources/Skill/psbits-qa', 0755, true);
        file_put_contents($this->root . '/.Build/vendor/psbits/qa/Resources/Skill/psbits-qa/SKILL.md', "# Skill\n");
        file_put_contents($this->root . '/composer.json', "{}\n");
    }

    protected function tearDown(): void
    {
        chdir(sys_get_temp_dir());
        $this->removeTree($this->root);
    }

    public function testLinkInstallsHookAndSkillLink(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'link']));

        $hook = $this->root . '/.git/hooks/pre-commit';
        self::assertFileExists($hook);
        self::assertFileEquals($hook, __DIR__ . '/../../Resources/GitHooks/pre-commit');
        self::assertNotSame(0, fileperms($hook) & 0111);

        $link = $this->root . '/.opencode/skills/psbits-qa';
        self::assertTrue(is_link($link));
        self::assertSame('../../.Build/vendor/psbits/qa/Resources/Skill/psbits-qa', readlink($link));
        self::assertFileExists($link . '/SKILL.md');
    }

    public function testCheckPassesAfterLink(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'link']));
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    public function testCheckFailsOnTamperedHook(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'link']);
        file_put_contents($this->root . '/.git/hooks/pre-commit', "echo tampered\n");
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    public function testCheckFailsOnMissingSkillTarget(): void
    {
        chdir($this->root);
        Application::execute(['qa', 'link']);
        $this->removeTree($this->root . '/.Build/vendor/psbits/qa/Resources/Skill');
        self::assertSame(1, Application::execute(['qa', 'check']));
    }

    public function testLinkSyncsCallerWorkflowWhenPresent(): void
    {
        chdir($this->root);
        $workflow = $this->root . '/.github/workflows/ci.yml';
        mkdir(dirname($workflow), 0755, true);
        file_put_contents($workflow, "name: CI\n# stale\n");
        self::assertSame(0, Application::execute(['qa', 'link']));
        self::assertFileEquals($workflow, __DIR__ . '/../../Resources/CI/ci.yml');
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    public function testLinkInstallsMissingCallerWorkflow(): void
    {
        chdir($this->root);
        self::assertSame(0, Application::execute(['qa', 'link']));
        self::assertFileEquals($this->root . '/.github/workflows/ci.yml', __DIR__ . '/../../Resources/CI/ci.yml');
        self::assertSame(0, Application::execute(['qa', 'check']));
    }

    public function testCheckFailsOnTamperedCallerWorkflow(): void
    {
        chdir($this->root);
        $workflow = $this->root . '/.github/workflows/ci.yml';
        mkdir(dirname($workflow), 0755, true);
        file_put_contents($workflow, "name: CI\n# stale\n");
        self::assertSame(0, Application::execute(['qa', 'link']));
        self::assertFileEquals($workflow, __DIR__ . '/../../Resources/CI/ci.yml');
        file_put_contents($workflow, "name: CI\n# drifted\n");
        self::assertSame(1, Application::execute(['qa', 'check']));
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
