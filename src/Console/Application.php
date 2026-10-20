<?php

declare(strict_types=1);

namespace PSBits\Qa\Console;

final class Application
{
    private const USAGE = <<<text
    Usage:
      qa link   Install the git pre-commit hook and the psbits-qa skill symlink
      qa check  Verify hook and skill link match the installed package (CI gate)
      qa paths  Print the locations of the shared QA resources

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

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function repoRoot(): string
    {
        $root = getenv('PSBITS_QA_REPO_ROOT');
        if ($root === false || $root === '') {
            $root = getcwd();
        }
        if (!is_dir($root) || !file_exists($root . '/composer.json')) {
            throw new \RuntimeException('repository root with a composer.json not found at ' . $root);
        }
        return $root;
    }

    private static function skillLink(string $root): string
    {
        return $root . '/.opencode/skills/psbits-qa';
    }

    private static function skillTarget(): string
    {
        return '../../.Build/vendor/psbits/qa/Resources/Skill/psbits-qa';
    }

    private static function link(string $root): int
    {
        $hooks = $root . '/.git/hooks';
        if (!is_dir($hooks) && !mkdir($hooks, 0755, true)) {
            throw new \RuntimeException('could not create ' . $hooks);
        }
        $hook = $hooks . '/pre-commit';
        $source = self::packageRoot() . '/Resources/GitHooks/pre-commit';
        if (!self::filesIdentical($hook, $source)) {
            if (copy($source, $hook) === false || !chmod($hook, 0755)) {
                throw new \RuntimeException('could not install pre-commit hook to ' . $hook);
            }
            echo "installed pre-commit hook\n";
        } else {
            echo "pre-commit hook unchanged\n";
        }

        $link = self::skillLink($root);
        $target = self::skillTarget();
        if (is_link($link)) {
            if (readlink($link) === $target && is_dir($link)) {
                echo 'skill link unchanged' . "\n";
                return 0;
            }
            unlink($link);
        } elseif (file_exists($link)) {
            self::remove($link);
        }
        $linkDir = dirname($link);
        if (!is_dir($linkDir) && !mkdir($linkDir, 0755, true)) {
            throw new \RuntimeException('could not create ' . $linkDir);
        }
        if (!symlink($target, $link)) {
            throw new \RuntimeException('could not create skill link at ' . $link);
        }
        if (!is_dir($link)) {
            throw new \RuntimeException('skill link target does not resolve: ' . $target . ' (run composer install first)');
        }
        echo 'installed skill link' . "\n";
        return 0;
    }

    private static function check(string $root): int
    {
        $failed = 0;

        $hook = $root . '/.git/hooks/pre-commit';
        $source = self::packageRoot() . '/Resources/GitHooks/pre-commit';
        if (self::filesIdentical($hook, $source)) {
            echo 'ok: pre-commit hook' . "\n";
        } else {
            echo 'FAIL: pre-commit hook differs from the installed package' . "\n";
            $failed = 1;
        }

        $link = self::skillLink($root);
        if (!is_link($link)) {
            echo 'FAIL: skill link missing at ' . $link . "\n";
            $failed = 1;
        } elseif (readlink($link) !== self::skillTarget()) {
            echo 'FAIL: skill link points to ' . readlink($link) . "\n";
            $failed = 1;
        } elseif (!is_file($link . '/SKILL.md')) {
            echo 'FAIL: skill link does not resolve (is .Build/vendor installed?)' . "\n";
            $failed = 1;
        } else {
            echo 'ok: skill link' . "\n";
        }

        return $failed;
    }

    private static function paths(): int
    {
        $root = self::packageRoot();
        foreach ([
            'cs-fixer config' => 'Resources/Quality/cs-fixer.dist.php',
            'phpstan config' => 'Resources/Quality/phpstan.neon',
            'prettier config' => 'Resources/Quality/prettier.config.mjs',
            'yamllint config' => 'Resources/Quality/yamllint.yml',
            'typoscript-lint config' => 'Resources/Quality/typoscript-lint.yml',
            'lint-class-attributes' => 'Resources/Quality/lint-class-attributes.mjs',
            'phpunit xml renderer' => 'Resources/Testing/renderPhpunitXml.php',
            'test matrix scripts' => 'Resources/Testing/Scripts/',
            'docker setup' => 'Resources/Testing/Docker/',
            'git hooks' => 'Resources/GitHooks/',
            'psbits-qa skill' => 'Resources/Skill/psbits-qa/',
        ] as $label => $path) {
            printf("  %-24s %s\n", $label . ':', $root . '/' . $path);
        }
        return 0;
    }

    private static function filesIdentical(string $a, string $b): bool
    {
        if (!is_file($a) || !is_file($b)) {
            return false;
        }
        $contentA = file_get_contents($a);
        $contentB = file_get_contents($b);
        return $contentA !== false && $contentA === $contentB;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
    }
}
