<?php

declare(strict_types=1);

namespace PSBits\Qa\Console;

use FilesystemIterator;
use PSBits\Qa\Quality\ThrowsFixer;
use PSBits\Qa\Quality\XlfSorter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function chmod;
use function count;
use function dirname;
use function file_get_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

final class Application
{
    /** @var string[] shared skills installed as symlinks by qa link */
    private const array SKILLS = [
        'psbits-qa',
        'psbits-cgl',
        'psbits-foundation',
        'psbits-v14-compat',
    ];
    private const string USAGE = <<<text
    Usage:
      qa link       Install the git pre-commit hook and the shared skill
                    symlinks (psbits-qa, psbits-cgl, psbits-foundation,
                    psbits-v14-compat), and create or sync the caller CI
                    workflow (.github/workflows/ci.yml)
      qa check      Verify hook, skill links and caller CI workflow match the
                    installed package (CI gate)
      qa paths      Print the locations of the shared QA resources
      qa throws-fix Rewrite the @throws docblocks to the exactly thrown
                    exceptions (phpstan loop, max 10 fix rounds)
      qa xlf-check  Report the XLIFF files with unsorted trans-unit ids
      qa xlf-fix    Sort the XLIFF trans-unit ids alphabetically per body

    The repository root is taken from PSBITS_QA_REPO_ROOT or the current
    working directory.
    text;

    public static function execute(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        try {
            switch ($command) {
                case 'link':
                    return self::link(self::repoRoot());
                case 'check':
                    return self::check(self::repoRoot());
                case 'paths':
                    return self::paths();
                case 'throws-fix':
                    return (new ThrowsFixer(self::repoRoot()))->run();
                case 'xlf-check':
                    return self::xlf(self::repoRoot(), false);
                case 'xlf-fix':
                    return self::xlf(self::repoRoot(), true);
                case 'help':
                case '--help':
                case '-h':
                    echo self::USAGE . "\n";

                    return 0;
                default:
                    fwrite(STDERR, "Unknown command: {$command}\n\n" . self::USAGE . "\n");

                    return 1;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    private static function callerWorkflow(string $root): string
    {
        return $root . '/.github/workflows/ci.yml';
    }

    private static function callerWorkflowTemplate(): string
    {
        return self::packageRoot() . '/Resources/CI/ci.yml';
    }

    private static function check(string $root): int
    {
        $failed = 0;

        $hook   = $root . '/.git/hooks/pre-commit';
        $source = self::packageRoot() . '/Resources/GitHooks/pre-commit';

        if (self::filesIdentical($hook, $source)) {
            echo 'ok: pre-commit hook' . "\n";
        } else {
            echo 'FAIL: pre-commit hook differs from the installed package' . "\n";
            $failed = 1;
        }

        foreach (self::skillLinks($root) as $skill => $link) {
            if (!is_link($link)) {
                echo "FAIL: skill link missing at {$link} ({$skill})\n";
                $failed = 1;
            } elseif (readlink($link) !== self::skillTarget($skill)) {
                echo "FAIL: skill link {$skill} points to " . readlink($link) . "\n";
                $failed = 1;
            } elseif (!is_file($link . '/SKILL.md')) {
                echo "FAIL: skill link {$skill} does not resolve (is .Build/vendor installed?)\n";
                $failed = 1;
            } else {
                echo "ok: skill link {$skill}\n";
            }
        }

        $workflow = self::callerWorkflow($root);

        if (!file_exists($workflow)) {
            echo 'ok: caller workflow not present (skipped)' . "\n";
        } elseif (self::filesIdentical($workflow, self::callerWorkflowTemplate())) {
            echo 'ok: caller workflow' . "\n";
        } else {
            echo 'FAIL: caller workflow differs from the installed package (run qa link and commit)' . "\n";
            $failed = 1;
        }

        return $failed;
    }

    private static function filesIdentical(string $a, string $b): bool
    {
        if (!is_file($a) || !is_file($b)) {
            return false;
        }
        $contentA = file_get_contents($a);
        $contentB = file_get_contents($b);

        return false !== $contentA && $contentA === $contentB;
    }

    private static function link(string $root): int
    {
        $hooks = $root . '/.git/hooks';

        if (!is_dir($hooks) && !mkdir($hooks, 0755, true)) {
            throw new RuntimeException('could not create ' . $hooks);
        }

        $hook   = $hooks . '/pre-commit';
        $source = self::packageRoot() . '/Resources/GitHooks/pre-commit';

        if (!self::filesIdentical($hook, $source)) {
            if (false === copy($source, $hook) || !chmod($hook, 0755)) {
                throw new RuntimeException('could not install pre-commit hook to ' . $hook);
            }

            echo "installed pre-commit hook\n";
        } else {
            echo "pre-commit hook unchanged\n";
        }

        foreach (self::skillLinks($root) as $skill => $link) {
            $target = self::skillTarget($skill);
            $linkOk = is_link($link) && readlink($link) === $target && is_dir($link);

            if ($linkOk) {
                echo "skill link unchanged: {$skill}\n";
            } else {
                if (is_link($link)) {
                    unlink($link);
                } elseif (file_exists($link)) {
                    self::remove($link);
                }

                $linkDir = dirname($link);

                if (!is_dir($linkDir) && !mkdir($linkDir, 0755, true)) {
                    throw new RuntimeException('could not create ' . $linkDir);
                }

                if (!symlink($target, $link)) {
                    throw new RuntimeException('could not create skill link at ' . $link);
                }

                if (!is_dir($link)) {
                    throw new RuntimeException('skill link target does not resolve: ' . $target . ' (run composer install first)');
                }

                echo "installed skill link: {$skill}\n";
            }
        }

        /*
         * The caller workflow is created on first run and kept in sync with
         * the template from then on, so every repository opts into the
         * shared CI with the next composer install.
         */
        $workflow = self::callerWorkflow($root);
        $source   = self::callerWorkflowTemplate();
        $existed  = file_exists($workflow);

        if (self::filesIdentical($workflow, $source)) {
            echo 'caller workflow unchanged' . "\n";
        } else {
            $workflowDir = dirname($workflow);

            if (!is_dir($workflowDir) && !mkdir($workflowDir, 0755, true)) {
                throw new RuntimeException('could not create ' . $workflowDir);
            }

            if (false === copy($source, $workflow)) {
                throw new RuntimeException('could not sync caller workflow to ' . $workflow);
            }

            echo $existed ? 'synced caller workflow' : 'installed caller workflow' . "\n";
        }

        return 0;
    }

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function paths(): int
    {
        $root = self::packageRoot();

        foreach ([
            'caller workflow template' => 'Resources/CI/ci.yml',
            'cs-fixer config'          => 'Resources/Quality/cs-fixer.dist.php',
            'docker setup'             => 'Resources/Testing/Docker/',
            'git hooks'                => 'Resources/GitHooks/',
            'lint-class-attributes'    => 'Resources/Quality/lint-class-attributes.mjs',
            'phpstan config'           => 'Resources/Quality/phpstan.neon',
            'phpunit xml renderer'     => 'Resources/Testing/renderPhpunitXml.php',
            'prettier config'          => 'Resources/Quality/prettier.config.mjs',
            'psbits-cgl skill'         => 'Resources/Skill/psbits-cgl/',
            'psbits-foundation skill'  => 'Resources/Skill/psbits-foundation/',
            'psbits-qa skill'          => 'Resources/Skill/psbits-qa/',
            'psbits-v14-compat skill'  => 'Resources/Skill/psbits-v14-compat/',
            'test matrix scripts'      => 'Resources/Testing/Scripts/',
            'typoscript-lint config'   => 'Resources/Quality/typoscript-lint.yml',
            'yamllint config'          => 'Resources/Quality/yamllint.yml',
        ] as $label => $path) {
            printf("  %-24s %s\n", $label . ':', $root . '/' . $path);
        }

        return 0;
    }

    private static function relative(string $root, string $path): string
    {
        $prefix = $root . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
    }

    private static function repoRoot(): string
    {
        $root = getenv('PSBITS_QA_REPO_ROOT');

        if (false === $root || '' === $root) {
            $root = getcwd();
        }

        if (!is_dir($root) || !file_exists($root . '/composer.json')) {
            throw new RuntimeException('repository root with a composer.json not found at ' . $root);
        }

        return $root;
    }

    /**
     * @return array<string, string>
     */
    private static function skillLinks(string $root): array
    {
        $links = [];

        foreach (self::SKILLS as $skill) {
            $links[$skill] = $root . '/.opencode/skills/' . $skill;
        }

        return $links;
    }

    private static function skillTarget(string $skill): string
    {
        return '../../.Build/vendor/psbits/qa/Resources/Skill/' . $skill;
    }

    private static function xlf(string $root, bool $apply): int
    {
        $sorter = new XlfSorter();
        $files  = XlfSorter::findFiles($root);

        if ([] === $files) {
            echo 'xlf: no XLIFF files found' . "\n";

            return 0;
        }

        if (!$apply) {
            $result = $sorter->check($files);

            foreach ($result['unsorted'] as $file) {
                echo 'xlf: unsorted trans-unit ids in ' . self::relative($root, $file) . "\n";
            }

            foreach ($result['problems'] as $problem) {
                echo 'xlf: ' . $problem . "\n";
            }

            if ([] === $result['unsorted'] && [] === $result['problems']) {
                echo 'xlf: trans-unit ids sorted in ' . count($files) . ' file(s)' . "\n";

                return 0;
            }

            return 1;
        }

        $result = $sorter->apply($files);

        if ([] === $result['changed']) {
            echo 'xlf: trans-unit ids already sorted in ' . count($files) . ' file(s)' . "\n";
        }

        foreach ($result['changed'] as $file) {
            echo 'xlf: sorted trans-unit ids in ' . self::relative($root, $file) . "\n";
        }

        foreach ($result['skipped'] as $skip) {
            echo 'xlf: ' . $skip . "\n";
        }

        return 0;
    }
}
