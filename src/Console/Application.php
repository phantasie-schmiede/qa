<?php

declare(strict_types=1);

namespace PSBits\Qa\Console;

use FilesystemIterator;
use PSBits\Qa\Quality\ThrowsFixer;
use PSBits\Qa\Quality\XlfSorter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use UnexpectedValueException;
use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function chmod;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function ltrim;
use function mkdir;
use function rmdir;
use function sort;
use function strcmp;
use function str_starts_with;
use function strlen;
use function substr;
use function strtolower;
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

    /**
     * The shared QA scripts that link adds to the caller composer.json when
     * missing. The condition decides whether the script applies to a
     * repository at all: "always" does, "xlf" requires *.xlf files below the
     * root, "phpstan" a PHPStan configuration in Build/Quality, "csfixer" a
     * PHP-CS-Fixer configuration in Build/Quality, "configuration" the
     * Configuration directory, "typoscript" at least one .typoscript file
     * below Configuration/TypoScript, and "npm:<script>" the npm script in
     * the caller package.json (the composer script is its proxy).
     *
     * @var list<array{name: string, command: string, condition: string}>
     */
    private const array SCRIPTS = [
        ['name' => 'analyze:php', 'command' => 'phpstan analyse --configuration=Build/Quality/phpstan.neon.dist --no-progress', 'condition' => 'phpstan'],
        ['name' => 'audit:composer', 'command' => 'composer audit --abandoned=report', 'condition' => 'always'],
        ['name' => 'fix:classes', 'command' => 'npm run fix:classes', 'condition' => 'npm:fix:classes'],
        ['name' => 'fix:htmlxml', 'command' => 'npm run fix:htmlxml', 'condition' => 'npm:fix:htmlxml'],
        ['name' => 'fix:php', 'command' => 'php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --verbose', 'condition' => 'csfixer'],
        ['name' => 'fix:throws', 'command' => '.Build/bin/qa throws-fix', 'condition' => 'phpstan'],
        ['name' => 'fix:xlf', 'command' => '.Build/bin/qa xlf-fix', 'condition' => 'xlf'],
        ['name' => 'lint:classes', 'command' => 'npm run lint:classes', 'condition' => 'npm:lint:classes'],
        ['name' => 'lint:htmlxml', 'command' => 'npm run lint:htmlxml', 'condition' => 'npm:lint:htmlxml'],
        ['name' => 'lint:js', 'command' => 'npm run lint:js', 'condition' => 'npm:lint:js'],
        ['name' => 'lint:php', 'command' => 'php-cs-fixer fix --config=Build/Quality/.php-cs-fixer.dist.php --dry-run --diff --verbose', 'condition' => 'csfixer'],
        ['name' => 'lint:scss', 'command' => 'npm run lint:scss', 'condition' => 'npm:lint:scss'],
        ['name' => 'lint:typoscript', 'command' => 'typoscript-lint --config=.Build/vendor/psbits/qa/Resources/Quality/typoscript-lint.yml --ansi Configuration/TypoScript', 'condition' => 'typoscript'],
        ['name' => 'lint:xlf', 'command' => '.Build/bin/qa xlf-check', 'condition' => 'xlf'],
        ['name' => 'lint:yaml', 'command' => 'yamllint -c .Build/vendor/psbits/qa/Resources/Quality/yamllint.yml Configuration', 'condition' => 'configuration'],
        ['name' => 'validate:composer', 'command' => 'composer validate --no-check-publish', 'condition' => 'always'],
    ];

    /**
     * The canonical lint:all members besides the repository's own lint:*
     * scripts; a member is part of the standard only when the referenced
     * script exists in the repository.
     *
     * @var list<string>
     */
    private const array LINT_ALL_MEMBERS = ['@analyze:php', '@audit:composer', '@validate:composer'];

    /**
     * The canonical fix:all order: the repository formatters first, then
     * the docblock and XLIFF fixers, and finally fix:php, which normalizes
     * the import order and whitespace of what the other fixers wrote.
     *
     * @var list<string>
     */
    private const array FIX_ALL_ORDER = ['fix:classes', 'fix:htmlxml', 'fix:throws', 'fix:xlf', 'fix:php'];

    private const string USAGE = <<<text
    Usage:
      qa link       Install the git pre-commit hook and the shared skill
                    symlinks (psbits-qa, psbits-cgl, psbits-foundation,
                    psbits-v14-compat), create or sync the caller CI
                    workflow (.github/workflows/ci.yml), and sync the
                    caller composer.json scripts: add the shared scripts
                    when missing, create lint:all, fix:all and qa when
                    missing, and report existing aggregates that deviate
                    from the expected standard
      qa check      Verify hook, skill links and caller CI workflow match
                    the installed package, and verify that the caller
                    composer.json carries the shared QA scripts (CI gate)
      qa paths      Print the locations of the shared QA resources
      qa throws-fix Rewrite the @throws docblocks to the exactly thrown
                    exceptions (phpstan loop, max 10 fix rounds)
      qa xlf-check  Report the XLIFF files with unsorted trans-unit ids
      qa xlf-fix    Sort the XLIFF trans-unit ids alphabetically per body

    The repository root is taken from PSBITS_QA_REPO_ROOT or the current
    working directory.
    text;

    /**
     * @param list<string> $argv
     */
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

    /**
     * @param list<string> $actual
     * @param list<string> $standard
     * @return array{missing: list<string>, extra: list<string>}
     */
    private static function aggregateDeviations(array $actual, array $standard): array
    {
        return [
            'missing' => array_values(array_diff($standard, $actual)),
            'extra'   => array_values(array_diff($actual, $standard)),
        ];
    }

    /**
     * The expected standard membership of the aggregates: lint:all carries
     * the canonical members (when the referenced script exists) plus every
     * lint:* script of the repository, fix:all every fix:* script in the
     * canonical order, and qa the fix, lint and test aggregates.
     *
     * @param array<string, list<string>|string> $scripts
     * @return array{'lint:all': list<string>, 'fix:all': list<string>, 'qa': list<string>}
     */
    private static function aggregateStandards(array $scripts): array
    {
        $lintAll = array_values(array_filter(
            self::LINT_ALL_MEMBERS,
            static fn (string $member): bool => isset($scripts[ltrim($member, '@')]),
        ));
        $fixAll  = [];
        $rest    = [];

        foreach (array_keys($scripts) as $name) {
            if (str_starts_with($name, 'lint:') && 'lint:all' !== $name) {
                $lintAll[] = '@' . $name;
            }

            if (str_starts_with($name, 'fix:') && 'fix:all' !== $name && !in_array($name, self::FIX_ALL_ORDER, true)) {
                $rest[] = '@' . $name;
            }
        }

        foreach (self::FIX_ALL_ORDER as $name) {
            if (isset($scripts[$name])) {
                $fixAll[] = '@' . $name;
            }
        }

        sort($lintAll);
        sort($rest);

        $qa = ['@fix:all', '@lint:all'];

        if (isset($scripts['test:all'])) {
            $qa[] = '@test:all';
        }

        return ['lint:all' => $lintAll, 'fix:all' => array_merge($fixAll, $rest), 'qa' => $qa];
    }

    private static function callerWorkflow(string $root): string
    {
        return $root . '/.github/workflows/ci.yml';
    }

    private static function callerWorkflowTemplate(): string
    {
        return self::packageRoot() . '/Resources/CI/ci.yml';
    }

    /**
     * @throws UnexpectedValueException
     */
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

        $failed |= self::verifyScripts($root);

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

    /**
     * Whether the repository carries TypoScript configuration, i.e. at least
     * one .typoscript file below Configuration/TypoScript. An empty directory
     * keeps the lint:typoscript script out of the repository.
     *
     * @throws UnexpectedValueException
     */
    private static function hasTyposcriptFiles(string $root): bool
    {
        $directory = $root . '/Configuration/TypoScript';

        if (!is_dir($directory)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && 'typoscript' === strtolower($item->getExtension())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inserts the entry before the first key sorting after $name, keeping
     * the existing key order of all other entries (no re-sorting).
     *
     * @param array<string, list<string>|string> $scripts
     * @param list<string>|string $value
     * @return array<string, list<string>|string>
     */
    private static function insertSorted(array $scripts, string $name, array|string $value): array
    {
        $result = [];

        foreach ($scripts as $key => $existing) {
            if (!isset($result[$name]) && strcmp((string) $key, $name) > 0) {
                $result[$name] = $value;
            }
            $result[$key] = $existing;
        }

        if (!isset($result[$name])) {
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
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

        self::syncScripts($root);

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

    /**
     * Prints the expected standard of an aggregate in the composer.json
     * formatting (plus the missing and extra members), so a deviation can
     * be aligned manually.
     *
     * @param array{missing: list<string>, extra: list<string>} $deviation
     * @param list<string> $standard
     */
    private static function printAggregateStandard(string $aggregate, array $deviation, array $standard): void
    {
        if ([] !== $deviation['missing']) {
            echo '  missing: ' . implode(', ', $deviation['missing']) . "\n";
        }

        if ([] !== $deviation['extra']) {
            echo '  extra: ' . implode(', ', $deviation['extra']) . "\n";
        }

        $members = [];

        foreach ($standard as $index => $member) {
            $members[] = '      ' . json_encode($member) . ($index === count($standard) - 1 ? '' : ',');
        }

        echo "  expected standard for {$aggregate}:\n";
        echo "    \"{$aggregate}\": [\n" . implode("\n", $members) . "\n    ]\n";
    }

    private static function relative(string $root, string $path): string
    {
        $prefix = $root . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * @throws UnexpectedValueException
     */
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

    /**
     * @throws RuntimeException
     */
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
     * @param array{name: string, command: string, condition: string} $script
     *
     * @throws UnexpectedValueException
     */
    private static function scriptApplicable(array $script, string $root): bool
    {
        $condition = $script['condition'];

        if (str_starts_with($condition, 'npm:')) {
            return self::npmScriptDefined($root, substr($condition, strlen('npm:')));
        }

        if ('xlf' === $condition) {
            return [] !== XlfSorter::findFiles($root);
        }

        if ('phpstan' === $condition) {
            return is_file($root . '/Build/Quality/phpstan.neon.dist');
        }

        if ('csfixer' === $condition) {
            return is_file($root . '/Build/Quality/.php-cs-fixer.dist.php');
        }

        if ('configuration' === $condition) {
            return is_dir($root . '/Configuration');
        }

        if ('typoscript' === $condition) {
            return self::hasTyposcriptFiles($root);
        }

        return 'always' === $condition;
    }

    /**
     * Whether the caller package.json defines the given npm script - the
     * composer scripts for the node tooling are thin proxies and only apply
     * when the npm script they wrap exists.
     */
    private static function npmScriptDefined(string $root, string $script): bool
    {
        $file = $root . '/package.json';

        if (!is_file($file)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data)
            && is_array($data['scripts'] ?? null)
            && array_key_exists($script, $data['scripts']);
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

    /**
     * Keeps the caller composer.json scripts in sync with the shared QA
     * scripts: the applicable shared scripts are added when missing, the
     * aggregates lint:all, fix:all and qa are created when missing.
     * Existing script entries and aggregate members are never modified -
     * an existing aggregate deviating from the standard is reported with
     * the expected standard so it can be aligned manually.
     *
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function syncScripts(string $root): void
    {
        $file = $root . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException('could not parse ' . $file);
        }

        if (!is_array($data['scripts'] ?? null)) {
            $data['scripts'] = [];
        }

        $scripts = $data['scripts'];
        $changed = false;

        foreach (self::SCRIPTS as $script) {
            if (!self::scriptApplicable($script, $root) || isset($scripts[$script['name']])) {
                continue;
            }

            $scripts = self::insertSorted($scripts, $script['name'], $script['command']);
            echo "added script {$script['name']}\n";
            $changed = true;
        }

        $standards = self::aggregateStandards($scripts);

        foreach (['lint:all', 'fix:all', 'qa'] as $aggregate) {
            if (isset($scripts[$aggregate])) {
                continue;
            }

            $scripts = self::insertSorted($scripts, $aggregate, $standards[$aggregate]);
            echo "created {$aggregate}\n";
            $changed = true;
        }

        if ($changed) {
            $data['scripts'] = $scripts;
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            if (false === file_put_contents($file, $encoded)) {
                throw new RuntimeException('could not write ' . $file);
            }
        }

        foreach (['lint:all', 'fix:all', 'qa'] as $aggregate) {
            $deviation = self::aggregateDeviations((array) $scripts[$aggregate], $standards[$aggregate]);

            if ([] === $deviation['missing'] && [] === $deviation['extra']) {
                echo "aggregate {$aggregate} unchanged\n";
                continue;
            }

            echo "hint: {$aggregate} deviates from the expected standard\n";
            self::printAggregateStandard($aggregate, $deviation, $standards[$aggregate]);
        }
    }

    /**
     * Verifies that the caller composer.json carries the shared QA state
     * (CI gate): the applicable shared scripts exist and every aggregate
     * matches the standard.
     *
     * @throws UnexpectedValueException
     */
    private static function verifyScripts(string $root): int
    {
        $data    = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $scripts = is_array($data['scripts'] ?? null) ? $data['scripts'] : [];
        $failed  = 0;

        foreach (self::SCRIPTS as $script) {
            if (!self::scriptApplicable($script, $root)) {
                continue;
            }

            if (isset($scripts[$script['name']])) {
                echo 'ok: script ' . $script['name'] . "\n";
            } else {
                echo 'FAIL: script ' . $script['name'] . ' missing (run qa link and commit)' . "\n";
                $failed = 1;
            }
        }

        $standards = self::aggregateStandards($scripts);

        foreach (['lint:all', 'fix:all', 'qa'] as $aggregate) {
            if (!isset($scripts[$aggregate])) {
                echo "FAIL: {$aggregate} missing (run qa link and commit)\n";
                $failed = 1;
                continue;
            }

            $deviation = self::aggregateDeviations((array) $scripts[$aggregate], $standards[$aggregate]);

            if ([] === $deviation['missing'] && [] === $deviation['extra']) {
                echo "ok: {$aggregate}\n";
            } else {
                echo "FAIL: {$aggregate} deviates from the expected standard\n";
                self::printAggregateStandard($aggregate, $deviation, $standards[$aggregate]);
                $failed = 1;
            }
        }

        return $failed;
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
