<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Qa\Console;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use FilesystemIterator;
use JsonException;
use PSBits\Qa\Quality\ThrowsFixer;
use PSBits\Qa\Quality\XlfSorter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function chmod;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function ksort;
use function ltrim;
use function mkdir;
use function rmdir;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strcmp;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unlink;

final class Application
{
    /**
     * The Composer plugins the caller repository must allow for the shared
     * tooling: Composer blocks a plugin that is not present in the root
     * allow-plugins config, so without the entry the composer install fails
     * before the shared tooling can run.
     *
     * @var array<string, bool>
     */
    private const array ALLOW_PLUGINS = [
        'dealerdirect/phpcodesniffer-composer-installer' => true,
        'ergebnis/composer-normalize'                    => true,
    ];

    /**
     * The canonical fix:all order: the repository formatters first, then
     * the docblock and XLIFF fixers, and finally fix:php, which normalizes
     * the import order and whitespace of what the other fixers wrote.
     *
     * @var list<string>
     */
    private const array FIX_ALL_ORDER = ['fix:classes', 'fix:composer', 'fix:htmlxml', 'fix:json', 'fix:throws', 'fix:xlf', 'fix:php'];

    /**
     * The repository-root paths the shared tooling writes at runtime,
     * listed in the append order for newly created .gitignore files:
     * .Build (the resolved test matrix core and tool state),
     * .php-cs-fixer.cache (the PHP CS Fixer cache),
     * Build/Quality/.phpstan (the PHPStan tmp directory; the caller shim
     * resolves tmpDir relative to its config file),
     * composer-matrix.json and its lock (the per-leg test matrix
     * environment), packages/autoload-tests (the class loading
     * information dump of the oldest supported testing-framework, the
     * test-context target of TYPO3 >= 14.3.1) and var (the TYPO3 v14.3
     * asset:publish kernel boot on every Composer run).
     *
     * @var list<string>
     */
    private const array GITIGNORE_ENTRIES = [
        '/.Build/',
        '/.php-cs-fixer.cache',
        '/Build/Quality/.phpstan/',
        '/composer-matrix.json',
        '/composer-matrix.lock',
        '/packages/autoload-tests/',
        '/var/',
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
     * The npm scripts the shared tooling manages in the caller
     * package.json: the shared Prettier runner behind lint:htmlxml and
     * fix:htmlxml. "expected" is the managed command, "legacy" the exact
     * prettier command of the previous tooling that sync migrates
     * automatically; every other existing command is a conflict that only
     * qa sync --force replaces. The commands run in the caller repository
     * root, where npm keeps the prettier binary in node_modules/.bin.
     *
     * @var array<string, array{expected: string, legacy: string}>
     */
    private const array NPM_SCRIPTS = [
        'fix:htmlxml'  => [
            'expected' => 'node .Build/vendor/psbits/qa/Resources/Quality/prettier.mjs fix',
            'legacy'   => 'prettier --config .Build/vendor/psbits/qa/Resources/Quality/prettier.config.mjs --ignore-path Build/Quality/.prettierignore --write "**/*.{html,xml,xlf}"',
        ],
        'lint:htmlxml' => [
            'expected' => 'node .Build/vendor/psbits/qa/Resources/Quality/prettier.mjs check',
            'legacy'   => 'prettier --config .Build/vendor/psbits/qa/Resources/Quality/prettier.config.mjs --ignore-path Build/Quality/.prettierignore --check "**/*.{html,xml,xlf}"',
        ],
    ];

    /**
     * The shared QA scripts that sync adds to the caller composer.json when
     * missing. The condition decides whether the script applies to a
     * repository at all: "always" does, "xlf" requires *.xlf files below the
     * root, "phpstan" a PHPStan configuration in Build/Quality, "php" at
     * least one of the PHP style configurations in Build/Quality (the
     * PHP-CS-Fixer or the PHP_CodeSniffer configuration - both tools run
     * behind lint:php/fix:php via the shared phpTools runner),
     * "configuration" the Configuration directory, "typoscript" at least
     * one .typoscript file below Configuration/TypoScript, and
     * "npm:<script>" the npm script in the caller package.json (the composer
     * script is its proxy), and "composer:<script>" requires that script in
     * the caller composer.json. "htmlxml" requires the managed htmlxml npm
     * scripts (html, xml or xlf files in the Prettier scope and a
     * package.json - see NPM_SCRIPTS).
     * "tests:<suite>" requires *Test.php files in
     * that suite, "tests" either suite, and "docker" tests or an existing
     * test:docker script.
     *
     * @var list<array{name: string, command: list<string>|string, condition: string}>
     */
    private const array SCRIPTS = [
        ['command' => 'phpstan analyse --configuration=Build/Quality/phpstan.neon.dist --no-progress', 'condition' => 'phpstan', 'name' => 'analyze:php'],
        ['command' => 'composer audit --abandoned=report', 'condition' => 'always', 'name' => 'audit:composer'],
        ['command' => ['Composer\\Config::disableProcessTimeout', 'sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/buildTestImages.sh'], 'condition' => 'docker', 'name' => 'docker:images'],
        ['command' => 'npm run fix:classes', 'condition' => 'npm:fix:classes', 'name' => 'fix:classes'],
        ['command' => 'composer normalize', 'condition' => 'always', 'name' => 'fix:composer'],
        ['command' => 'npm run fix:htmlxml', 'condition' => 'htmlxml', 'name' => 'fix:htmlxml'],
        ['command' => 'npm run fix:json', 'condition' => 'npm:fix:json', 'name' => 'fix:json'],
        ['command' => 'php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php fix', 'condition' => 'php', 'name' => 'fix:php'],
        ['command' => '.Build/bin/qa throws-fix', 'condition' => 'phpstan', 'name' => 'fix:throws'],
        ['command' => '.Build/bin/qa xlf-fix', 'condition' => 'xlf', 'name' => 'fix:xlf'],
        ['command' => 'npm run lint:classes', 'condition' => 'npm:lint:classes', 'name' => 'lint:classes'],
        ['command' => 'composer normalize --dry-run --diff --no-check-lock --no-update-lock', 'condition' => 'always', 'name' => 'lint:composer'],
        ['command' => 'npm run lint:htmlxml', 'condition' => 'htmlxml', 'name' => 'lint:htmlxml'],
        ['command' => 'npm run lint:js', 'condition' => 'npm:lint:js', 'name' => 'lint:js'],
        ['command' => 'npm run lint:json', 'condition' => 'npm:lint:json', 'name' => 'lint:json'],
        ['command' => 'php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php lint', 'condition' => 'php', 'name' => 'lint:php'],
        ['command' => 'npm run lint:scss', 'condition' => 'npm:lint:scss', 'name' => 'lint:scss'],
        ['command' => 'typoscript-lint --config=.Build/vendor/psbits/qa/Resources/Quality/typoscript-lint.yml --ansi Configuration/TypoScript', 'condition' => 'typoscript', 'name' => 'lint:typoscript'],
        ['command' => '.Build/bin/qa xlf-check', 'condition' => 'xlf', 'name' => 'lint:xlf'],
        ['command' => 'yamllint -c .Build/vendor/psbits/qa/Resources/Quality/yamllint.yml Configuration', 'condition' => 'configuration', 'name' => 'lint:yaml'],
        ['command' => 'sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/runCoverage.sh @additional_args', 'condition' => 'tests', 'name' => 'test:coverage'],
        ['command' => 'sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/runCoverage.sh --update-baseline @additional_args', 'condition' => 'tests', 'name' => 'test:coverage:update-baseline'],
        ['command' => ['Composer\\Config::disableProcessTimeout', 'sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/runDockerTests.sh @additional_args'], 'condition' => 'tests', 'name' => 'test:docker'],
        ['command' => ['php .Build/vendor/psbits/qa/Resources/Testing/renderPhpunitXml.php functional', 'php -d memory_limit=1G .Build/bin/phpunit -c .Build/phpunit/FunctionalTests.xml'], 'condition' => 'tests:Functional', 'name' => 'test:functional'],
        ['command' => ['Composer\\Config::disableProcessTimeout', 'sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/runLocalTests.sh @additional_args'], 'condition' => 'tests', 'name' => 'test:local'],
        ['command' => ['php .Build/vendor/psbits/qa/Resources/Testing/renderPhpunitXml.php unit', 'php -d memory_limit=1G .Build/bin/phpunit -c .Build/phpunit/UnitTests.xml'], 'condition' => 'tests:Unit', 'name' => 'test:unit'],
        ['command' => 'composer validate --no-check-publish', 'condition' => 'always', 'name' => 'validate:composer'],
    ];

    /** @var string[] shared skills installed as symlinks by qa sync */
    private const array SKILLS = [
        'psbits-qa',
        'psbits-cgl',
        'psbits-foundation',
        'psbits-v14-compat',
    ];
    private const string USAGE = <<<text
    Usage:
      qa sync       Sync the package-managed QA state: install the git
                    pre-commit hook and the shared skill symlinks
                    (psbits-qa, psbits-cgl, psbits-foundation,
                    psbits-v14-compat), create or sync the caller CI
                    workflow (.github/workflows/ci.yml), sync the caller
                    composer.json scripts (add the shared scripts when
                    missing, test scripts based on *Test.php suites,
                    create test:all, lint:all, fix:all and qa when
                    missing), sync the htmlxml npm scripts of the caller
                    package.json (add them when missing, migrate the
                    legacy prettier command to the shared Prettier
                    runner; a custom command is a conflict that --force
                    replaces), add the Composer allow-plugins entries the
                    shared tooling requires, append the runtime
                    .gitignore entries, and report existing aggregates
                    that deviate from the expected standard; warn about
                    redundant dev requirements and core-blocking pins
      qa check      Verify hook, skill links and caller CI workflow match
                    the installed package, verify that the caller
                    composer.json carries the shared QA scripts, that the
                    caller package.json carries the managed htmlxml npm
                    scripts and the required Composer allow-plugins
                    entries, and that the caller .gitignore carries the
                    runtime entries (CI gate); dev requirement warnings do
                    not fail the check
      qa sync --dry-run
                    Preview changes without writing files; conflicts fail
      qa sync --force
                    Explicitly replace conflicting hooks, workflows or skills
      qa paths      Print the locations of the shared QA resources
      qa throws-fix Rewrite the @throws docblocks against all supported
                    core majors (phpstan loop per major, max 25 fix
                    rounds each): the docblocks end up as the union of
                    the per-major thrown sets, a "// t3:N" marker on
                    the lines a single major sees (only with several
                    majors in the matrix), the lines no major sees are
                    removed. Uses disposable repository copies and
                    Composer installations per core in .Build/throws-fix;
                    normal vendor/bin and Composer files remain unchanged.
                    Requires network access; no core restoration is needed
      qa xlf-check  Report the XLIFF files with unsorted trans-unit or
                    group ids
      qa xlf-fix    Sort the XLIFF trans-unit and group ids
                    alphabetically per body and per group

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
                case 'sync':
                    $options = array_slice($argv, 2);

                    foreach ($options as $option) {
                        if (!in_array($option, ['--dry-run', '--force'], true)) {
                            throw new RuntimeException('unknown sync option: ' . $option);
                        }
                    }

                    return self::sync(self::repoRoot(), in_array('--dry-run', $options, true), in_array('--force', $options, true));

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
     * @return array{missing: list<string>, extra: list<string>, duplicates: list<string>, order: bool}
     */
    private static function aggregateDeviations(array $actual, array $standard, bool $checkOrder): array
    {
        $counts     = array_count_values($actual);
        $duplicates = array_keys(array_filter($counts, static fn(int $count): bool => 1 < $count));
        $unique     = array_values(array_unique($actual));

        return [
            'missing'    => array_values(array_diff($standard, $actual)),
            'extra'      => array_values(array_diff($actual, $standard)),
            'duplicates' => $duplicates,
            'order'      => $checkOrder
                && [] === array_diff($standard, $actual)
                && [] === array_diff($actual, $standard)
                && $unique !== $standard,
        ];
    }

    /**
     * The expected standard membership of the aggregates: lint:all carries
     * the canonical members (when the referenced script exists) plus every
     * lint:* script of the repository, fix:all every fix:* script in the
     * canonical order, and qa the fix, lint and test aggregates.
     *
     * @param array<string, list<string>|string> $scripts
     * @return array<string, list<string>>
     * @throws UnexpectedValueException
     */
    private static function aggregateStandards(array $scripts, string $root): array
    {
        $lintAll = array_values(array_filter(
            self::LINT_ALL_MEMBERS,
            static fn(string $member): bool => isset($scripts[ltrim($member, '@')]),
        ));
        $fixAll = [];
        $rest   = [];

        foreach (array_keys($scripts) as $name) {
            if (str_starts_with($name, 'lint:') && 'lint:all' !== $name) {
                $lintAll[] = '@' . $name;
            }

            if (str_starts_with($name, 'fix:') && 'fix:all' !== $name && !in_array($name, self::FIX_ALL_ORDER, true)) {
                $rest[] = '@' . $name;
            }
        }

        foreach (self::FIX_ALL_ORDER as $name) {
            if ('fix:php' !== $name && isset($scripts[$name])) {
                $fixAll[] = '@' . $name;
            }
        }

        sort($lintAll);
        sort($rest);

        $fixAll = array_merge($fixAll, $rest);

        if (isset($scripts['fix:php'])) {
            $fixAll[] = '@fix:php';
        }

        $standards = ['fix:all' => $fixAll, 'lint:all' => $lintAll];
        $testAll   = [];

        foreach (['Functional' => 'test:functional', 'Unit' => 'test:unit'] as $suite => $script) {
            if (self::hasTests($root, $suite)) {
                $testAll[] = '@' . $script;
            }
        }

        if ([] !== $testAll) {
            $standards['test:all'] = array_merge($testAll, ['@test:local', '@test:docker']);
        }

        $qa = ['@fix:all', '@lint:all'];

        if (isset($scripts['test:all']) || isset($standards['test:all'])) {
            $qa[] = '@test:all';
        }

        $standards['qa'] = $qa;

        return $standards;
    }

    /**
     * Applies the managed npm script actions while preserving all other
     * values. A missing scripts object is created.
     *
     * @param array<string, array{action: string, command: string}> $actions
     * @throws JsonException
     */
    private static function applyNpmScriptActions(string $content, array $actions): string
    {
        $data    = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $scripts = (array) ($data->scripts ?? []);

        foreach ($actions as $name => $action) {
            if ('skip' === $action['action']) {
                continue;
            }

            $scripts[$name] = $action['command'];
        }

        $data->scripts = (object) $scripts;
        $encoded       = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^ +/m', static fn(array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)), $encoded) . "\n";
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
     * @throws JsonException
     * @throws LogicException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function check(string $root): int
    {
        $failed = 0;

        $hook   = self::hookPath($root);
        $source = self::packageRoot() . '/Resources/GitHooks/pre-commit';

        if (null === $hook) {
            echo 'ok: no Git metadata (hook skipped)' . "\n";
        } elseif (!is_link($hook) && self::filesIdentical($hook, $source) && is_executable($hook)) {
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
        } elseif (!is_link($workflow) && self::filesIdentical($workflow, self::callerWorkflowTemplate())) {
            echo 'ok: caller workflow' . "\n";
        } else {
            echo 'FAIL: caller workflow differs from the installed package (run qa sync and commit)' . "\n";
            $failed = 1;
        }

        $failed |= self::verifyScripts($root);
        $failed |= self::verifyNpmScripts($root);
        $failed |= self::verifyAllowPlugins($root);
        $failed |= self::verifyGitignore($root);
        self::warnDevRequirements($root);

        return $failed;
    }

    /**
     * Creates the directory with all missing parent directories when it
     * does not exist yet. The existence is re-checked after a failed
     * mkdir, so a concurrent creation by another process is tolerated
     * instead of reported.
     *
     * @throws RuntimeException
     */
    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('could not create ' . $directory);
        }
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
     * Whether the repository carries html, xml or xlf files in the shared
     * Prettier scope (Classes, Configuration, Resources or Tests) - the
     * htmlxml tooling (the runner command in package.json and its composer
     * proxy) only applies to such repositories.
     *
     * @throws UnexpectedValueException
     */
    private static function hasHtmlXmlFiles(string $root): bool
    {
        foreach (['Classes', 'Configuration', 'Resources', 'Tests'] as $directory) {
            $path = $root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $item) {
                if ($item->isFile() && in_array(strtolower($item->getExtension()), ['html', 'xlf', 'xml'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function hasTests(string $root, string $suite): bool
    {
        $directory = $root . '/Tests/' . $suite;

        if (!is_dir($directory)) {
            return false;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                return true;
            }
        }

        return false;
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
     * Git resolves both worktree metadata and core.hooksPath. A source
     * archive without its own Git metadata must not use a parent repo's hook.
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     * @throws LogicException
     * @throws RuntimeException
     */
    private static function hookPath(string $root): ?string
    {
        if (!file_exists($root . '/.git')) {
            return null;
        }

        $process = new Process(['git', 'rev-parse', '--path-format=absolute', '--git-path', 'hooks'], $root);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('could not resolve Git hook path: ' . trim($process->getErrorOutput()));
        }

        return rtrim(trim($process->getOutput()), '/') . '/pre-commit';
    }

    /**
     * Inserts the entry before the first key sorting after $name, keeping
     * the existing key order of all other entries (no re-sorting).
     *
     * @param array<string, list<string>|string> $scripts
     * @param list<string>|string|bool           $value
     * @return array<string, list<string>|string|bool>
     */
    private static function insertSorted(array $scripts, string $name, array|string|bool $value): array
    {
        $result = [];

        foreach ($scripts as $key => $existing) {
            if (!isset($result[$name]) && 0 < strcmp($key, $name)) {
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
     * The sync action for every managed npm script (see NPM_SCRIPTS):
     * "skip" when the expected command is in place, "add" when the script
     * is missing, "update" for the known legacy command, "replace" for a
     * custom command with qa sync --force and "conflict" for a custom
     * command without. The "command" carries the current value of a
     * conflict and the expected value of every other action. Repositories
     * without html, xml or xlf files in the Prettier scope, without a
     * package.json are not managed. A missing scripts object is created.
     *
     * @return array<string, array{action: string, command: string}>
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function npmScriptActions(string $root, bool $force = false): array
    {
        $file = $root . '/package.json';

        if (!is_file($file) || !self::hasHtmlXmlFiles($root)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException('could not parse ' . $file);
        }

        if (array_key_exists('scripts', $data) && !is_array($data['scripts'])) {
            return [];
        }

        $scripts = $data['scripts'] ?? [];
        $actions = [];

        foreach (self::NPM_SCRIPTS as $name => $commands) {
            if (!array_key_exists($name, $scripts)) {
                $actions[$name] = ['action' => 'add', 'command' => $commands['expected']];

                continue;
            }

            if ($scripts[$name] === $commands['expected']) {
                $actions[$name] = ['action' => 'skip', 'command' => $scripts[$name]];

                continue;
            }

            if ($scripts[$name] === $commands['legacy']) {
                $actions[$name] = ['action' => 'update', 'command' => $commands['expected']];

                continue;
            }

            $actions[$name] = ['action' => $force ? 'replace' : 'conflict', 'command' => $force ? $commands['expected'] : $scripts[$name]];
        }

        return $actions;
    }

    /**
     * The conflicts of the managed npm scripts (see NPM_SCRIPTS): the
     * custom commands a sync without --force must not replace.
     *
     * @return list<string>
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function npmScriptConflicts(string $root, bool $force = false): array
    {
        $conflicts = [];

        foreach (self::npmScriptActions($root, $force) as $name => $action) {
            if ('conflict' === $action['action']) {
                $conflicts[] = 'npm script ' . $name . ' in package.json (custom command: ' . $action['command'] . ')';
            }
        }

        return $conflicts;
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

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Composer does not document the invalid-constraint exception on parseConstraints().
     *
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function parseVersionConstraint(string $constraint): ConstraintInterface
    {
        return (new VersionParser())->parseConstraints($constraint);
    }

    private static function paths(): int
    {
        $root = self::packageRoot();

        foreach ([
            'caller workflow template'   => 'Resources/CI/ci.yml',
            'cs-fixer config'            => 'Resources/Quality/cs-fixer.dist.php',
            'docker setup'               => 'Resources/Testing/Docker/',
            'git hooks'                  => 'Resources/GitHooks/',
            'lint-class-attributes'      => 'Resources/Quality/lint-class-attributes.mjs',
            'php tools runner'           => 'Resources/Quality/phpTools.php',
            'phpcs ruleset'              => 'Resources/Quality/phpcs.xml',
            'phpstan config'             => 'Resources/Quality/phpstan.neon',
            'phpunit xml renderer'       => 'Resources/Testing/renderPhpunitXml.php',
            'prettier config'            => 'Resources/Quality/prettier.config.mjs',
            'prettier runner'            => 'Resources/Quality/prettier.mjs',
            'psbits-cgl skill'           => 'Resources/Skill/psbits-cgl/',
            'psbits-foundation skill'    => 'Resources/Skill/psbits-foundation/',
            'psbits-qa skill'            => 'Resources/Skill/psbits-qa/',
            'psbits-v14-compat skill'    => 'Resources/Skill/psbits-v14-compat/',
            'test matrix scripts'        => 'Resources/Testing/Scripts/',
            'typoscript-lint config'     => 'Resources/Quality/typoscript-lint.yml',
            'yamllint config'            => 'Resources/Quality/yamllint.yml',
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
     * @param array{missing: list<string>, extra: list<string>, duplicates: list<string>, order: bool} $deviation
     * @param list<string>                                                                             $standard
     */
    private static function printAggregateStandard(string $aggregate, array $deviation, array $standard): void
    {
        if ([] !== $deviation['missing']) {
            echo '  missing: ' . implode(', ', $deviation['missing']) . "\n";
        }

        if ([] !== $deviation['extra']) {
            echo '  extra: ' . implode(', ', $deviation['extra']) . "\n";
        }

        if ([] !== $deviation['duplicates']) {
            echo '  duplicates: ' . implode(', ', $deviation['duplicates']) . "\n";
        }

        if ($deviation['order']) {
            echo "  order differs from the expected standard\n";
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
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
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
     * @param array{name: string, command: list<string>|string, condition: string} $script
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function scriptApplicable(array $script, string $root): bool
    {
        $condition = $script['condition'];

        if (str_starts_with($condition, 'tests:')) {
            return self::hasTests($root, substr($condition, strlen('tests:')));
        }

        if ('tests' === $condition || 'docker' === $condition) {
            if (self::hasTests($root, 'Unit') || self::hasTests($root, 'Functional')) {
                return true;
            }

            if ('docker' === $condition) {
                $data = json_decode((string) file_get_contents($root . '/composer.json'), true);

                return is_array($data) && isset($data['scripts']['test:docker']);
            }

            return false;
        }

        if (str_starts_with($condition, 'composer:')) {
            $data = json_decode((string) file_get_contents($root . '/composer.json'), true);

            return is_array($data) && isset($data['scripts'][substr($condition, strlen('composer:'))]);
        }

        if (str_starts_with($condition, 'npm:')) {
            return self::npmScriptDefined($root, substr($condition, strlen('npm:')));
        }

        if ('htmlxml' === $condition) {
            /*
             * The htmlxml tooling applies when the Prettier scope carries
             * files and the npm scripts can be managed: a package.json
             * (see npmScriptActions).
             */
            return [] !== self::npmScriptActions($root);
        }

        if ('xlf' === $condition) {
            return [] !== XlfSorter::findFiles($root);
        }

        if ('phpstan' === $condition) {
            return is_file($root . '/Build/Quality/phpstan.neon.dist');
        }

        if ('php' === $condition) {
            return is_file($root . '/Build/Quality/.php-cs-fixer.dist.php') || is_file($root . '/Build/Quality/.phpcs.xml.dist');
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
     * @throws JsonException
     * @throws LogicException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function sync(string $root, bool $dryRun = false, bool $force = false): int
    {
        $hook     = self::hookPath($root);
        $workflow = self::callerWorkflow($root);
        $files    = [$workflow => self::callerWorkflowTemplate()];

        if (null !== $hook) {
            $files[$hook] = self::packageRoot() . '/Resources/GitHooks/pre-commit';
        }

        // Validate all destinations before writing anything, including composer.json.
        $conflicts = [];

        if (!is_array(json_decode((string) file_get_contents($root . '/composer.json'), true))) {
            throw new RuntimeException('could not parse ' . $root . '/composer.json');
        }

        if (null === $hook) {
            echo "no Git metadata (hook skipped)\n";
        }

        foreach ($files as $destination => $source) {
            if (file_exists($destination) || is_link($destination)) {
                $managed = !is_link($destination) && is_file($destination)
                    && (self::filesIdentical($destination, $source)
                        || ($destination === $hook && "#!/usr/bin/env bash\nset -euo pipefail\n\ncomposer lint:all\n" === file_get_contents($destination))
                        || str_contains((string) file_get_contents($destination), 'This file is managed by `qa sync`'));

                if (!$managed && !$force) {
                    $conflicts[] = $destination;
                }
            }
        }

        foreach (self::skillLinks($root) as $skill => $link) {
            $target = self::skillTarget($skill);

            if (!is_file($root . '/.Build/vendor/psbits/qa/Resources/Skill/' . $skill . '/SKILL.md')) {
                throw new RuntimeException('skill target does not resolve: ' . $target . ' (run composer install first)');
            }

            if ((file_exists($link) || is_link($link)) && !(is_link($link) && readlink($link) === $target) && !$force) {
                $conflicts[] = $link;
            }
        }

        $conflicts = array_merge($conflicts, self::npmScriptConflicts($root, $force));

        if ([] !== $conflicts) {
            foreach ($conflicts as $conflict) {
                echo 'CONFLICT: ' . $conflict . "\n";
            }

            echo "No files changed. Review these paths; use qa sync --force only to replace them explicitly.\n";

            return 1;
        }

        foreach ($files as $destination => $source) {
            $label      = $destination === $hook ? 'pre-commit hook' : 'caller workflow';
            $identical  = !is_link($destination) && self::filesIdentical($destination, $source);
            $executable = $destination !== $hook || is_executable($destination);

            if ($identical && $executable) {
                echo $label . " unchanged\n";

                continue;
            }

            echo ($dryRun ? 'would sync ' : 'synced ') . $label . ': ' . $destination . "\n";

            if ($dryRun) {
                continue;
            }

            if (!$identical) {
                if (is_link($destination) || is_dir($destination)) {
                    self::remove($destination);
                }

                self::ensureDirectory(dirname($destination));

                if (!copy($source, $destination)) {
                    throw new RuntimeException('could not sync ' . $destination);
                }
            }

            if ($destination === $hook && !chmod($destination, 0755)) {
                throw new RuntimeException('could not make hook executable: ' . $destination);
            }
        }

        foreach (self::skillLinks($root) as $skill => $link) {
            $target = self::skillTarget($skill);
            $linkOk = is_link($link) && readlink($link) === $target && is_dir($link);

            if ($linkOk) {
                echo "skill link unchanged: {$skill}\n";
            } else {
                if ($dryRun) {
                    echo "would install skill link: {$skill}\n";

                    continue;
                }

                if (is_link($link)) {
                    unlink($link);
                } elseif (file_exists($link)) {
                    self::remove($link);
                }

                $linkDir = dirname($link);

                self::ensureDirectory($linkDir);

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
         * The npm scripts run before the composer scripts: the htmlxml
         * composer proxies only apply when the npm scripts they wrap exist,
         * so both converge in the same sync run.
         */
        self::syncNpmScripts($root, $dryRun, $force);
        self::syncScripts($root, $dryRun);
        self::syncAllowPlugins($root, $dryRun);
        self::syncGitignore($root, $dryRun);
        self::warnDevRequirements($root);

        return 0;
    }

    /**
     * Keeps the caller composer.json allow-plugins config in sync with the
     * plugins the shared tooling requires (see ALLOW_PLUGINS): the missing
     * entries are added, an entry with the expected value is left
     * untouched, and an entry with a different value is reported without
     * being modified.
     *
     * @throws RuntimeException
     */
    private static function syncAllowPlugins(string $root, bool $dryRun = false): void
    {
        $file = $root . '/composer.json';
        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException('could not parse ' . $file);
        }

        if (!is_array($data['config'] ?? null)) {
            $data['config'] = [];
        }

        $allow   = is_array($data['config']['allow-plugins'] ?? null) ? $data['config']['allow-plugins'] : [];
        $changed = false;

        foreach (self::ALLOW_PLUGINS as $plugin => $expected) {
            if (array_key_exists($plugin, $allow)) {
                if ($allow[$plugin] !== $expected) {
                    echo 'hint: allow-plugins.' . $plugin . ' is ' . json_encode($allow[$plugin]) . ', the shared tooling requires ' . json_encode($expected) . "\n";
                }

                continue;
            }

            $allow = self::insertSorted($allow, $plugin, $expected);
            echo ($dryRun ? 'would add config ' : 'added config ') . 'allow-plugins.' . $plugin . "\n";
            $changed = true;
        }

        if ($changed && !$dryRun) {
            $data['config']['allow-plugins'] = $allow;
            $encoded                         = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            if (false === file_put_contents($file, $encoded)) {
                throw new RuntimeException('could not write ' . $file);
            }
        }
    }

    /**
     * Keeps the caller .gitignore in sync with the runtime directories the
     * shared tooling writes below the repository root (see
     * GITIGNORE_ENTRIES): the missing entries are appended at the end, the
     * existing entries and every other line are left untouched.
     *
     * @throws RuntimeException
     */
    private static function syncGitignore(string $root, bool $dryRun = false): void
    {
        $file     = $root . '/.gitignore';
        $content  = is_file($file) ? (string) file_get_contents($file) : '';
        $existing = [];

        foreach (explode("\n", $content) as $line) {
            $existing[] = trim($line);
        }

        $missing = array_values(array_diff(self::GITIGNORE_ENTRIES, $existing));

        if ([] === $missing) {
            return;
        }

        if ('' !== $content && !str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        foreach ($missing as $entry) {
            $content .= $entry . "\n";
            echo ($dryRun ? 'would add gitignore entry ' : 'added gitignore entry ') . $entry . "\n";
        }

        if (!$dryRun && false === file_put_contents($file, $content)) {
            throw new RuntimeException('could not write ' . $file);
        }
    }

    /**
     * Applies the npm script actions to the caller package.json (see
     * NPM_SCRIPTS), preserving all other values. The custom-command
     * conflicts are reported in the sync validation phase, so an action of
     * "conflict" cannot reach this method.
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private static function syncNpmScripts(string $root, bool $dryRun = false, bool $force = false): void
    {
        $actions = self::npmScriptActions($root, $force);

        if ([] === $actions) {
            return;
        }

        $changed = false;

        foreach ($actions as $name => $action) {
            if ('skip' === $action['action']) {
                continue;
            }

            $verbs = ['add' => ['added', 'add'], 'replace' => ['replaced', 'replace'], 'update' => ['updated', 'update']];
            echo ($dryRun ? 'would ' . $verbs[$action['action']][1] : $verbs[$action['action']][0]) . ' npm script ' . $name . "\n";
            $changed = true;
        }

        if ($dryRun || !$changed) {
            return;
        }

        $file    = $root . '/package.json';
        $encoded = self::applyNpmScriptActions((string) file_get_contents($file), $actions);

        if (false === file_put_contents($file, $encoded)) {
            throw new RuntimeException('could not write ' . $file);
        }
    }

    /**
     * Keeps the caller composer.json scripts in sync with the shared QA
     * scripts: the applicable shared scripts are added when missing, the
     * applicable aggregates test:all, lint:all, fix:all and qa are created
     * when missing.Existing script entries and aggregate members are never
     * modified - an existing aggregate deviating from the standard is
     * reported with the expected standard so it can be aligned manually.
     *
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function syncScripts(string $root, bool $dryRun = false): void
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
            echo ($dryRun ? 'would add script ' : 'added script ') . $script['name'] . "\n";
            $changed = true;
        }

        $standards = self::aggregateStandards($scripts, $root);

        foreach ($standards as $aggregate => $members) {
            if (isset($scripts[$aggregate])) {
                continue;
            }

            $scripts = self::insertSorted($scripts, $aggregate, $members);
            echo ($dryRun ? 'would create ' : 'created ') . $aggregate . "\n";
            $changed = true;
        }

        if ($changed && !$dryRun) {
            $data['scripts'] = $scripts;
            $encoded         = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            if (false === file_put_contents($file, $encoded)) {
                throw new RuntimeException('could not write ' . $file);
            }
        }

        foreach ($standards as $aggregate => $members) {
            $deviation = self::aggregateDeviations((array) $scripts[$aggregate], $members, in_array($aggregate, ['fix:all', 'qa'], true));

            if ([] === $deviation['missing'] && [] === $deviation['extra'] && [] === $deviation['duplicates'] && !$deviation['order']) {
                echo "aggregate {$aggregate} unchanged\n";

                continue;
            }

            echo "hint: {$aggregate} deviates from the expected standard\n";
            self::printAggregateStandard($aggregate, $deviation, $members);
        }
    }

    /**
     * Verifies that the caller composer.json allows the plugins the shared
     * tooling requires (CI gate, see ALLOW_PLUGINS).
     *
     * @throws RuntimeException
     */
    private static function verifyAllowPlugins(string $root): int
    {
        $data   = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $allow  = is_array($data['config']['allow-plugins'] ?? null) ? $data['config']['allow-plugins'] : [];
        $failed = 0;

        foreach (self::ALLOW_PLUGINS as $plugin => $expected) {
            if (($allow[$plugin] ?? null) === $expected) {
                echo 'ok: allow-plugins.' . $plugin . "\n";
            } else {
                echo 'FAIL: allow-plugins.' . $plugin . ' missing or not ' . json_encode($expected) . ' (run qa sync and commit)' . "\n";
                $failed = 1;
            }
        }

        return $failed;
    }

    /**
     * Verifies that the caller .gitignore carries the entries for the
     * runtime directories the shared tooling writes below the repository
     * root (CI gate, see GITIGNORE_ENTRIES).
     *
     * @throws RuntimeException
     */
    private static function verifyGitignore(string $root): int
    {
        $file  = $root . '/.gitignore';
        $lines = [];

        if (is_file($file)) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $lines[] = trim($line);
            }
        }

        $failed = 0;

        foreach (self::GITIGNORE_ENTRIES as $entry) {
            if (in_array($entry, $lines, true)) {
                echo 'ok: gitignore entry ' . $entry . "\n";
            } else {
                echo 'FAIL: gitignore entry ' . $entry . ' missing (run qa sync and commit)' . "\n";
                $failed = 1;
            }
        }

        return $failed;
    }

    /**
     * Verifies that the caller package.json carries the managed htmlxml
     * npm scripts (CI gate, see NPM_SCRIPTS): repositories without html,
     * xml or xlf files in the Prettier scope or without a package.json
     * are skipped. A missing scripts object requires synchronization.
     *
     * @throws UnexpectedValueException
     */
    private static function verifyNpmScripts(string $root): int
    {
        $file = $root . '/package.json';

        if (!is_file($file) || !self::hasHtmlXmlFiles($root)) {
            return 0;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            echo 'FAIL: package.json is not valid JSON (run qa sync and commit)' . "\n";

            return 1;
        }

        if (array_key_exists('scripts', $data) && !is_array($data['scripts'])) {
            return 0;
        }

        $failed = 0;

        foreach (self::NPM_SCRIPTS as $name => $commands) {
            if (!array_key_exists($name, $data['scripts'] ?? [])) {
                echo 'FAIL: npm script ' . $name . ' missing (run qa sync and commit)' . "\n";
                $failed = 1;
            } elseif ($data['scripts'][$name] !== $commands['expected']) {
                echo 'FAIL: npm script ' . $name . ' deviates from the expected command (run qa sync and commit)' . "\n";
                echo '  expected: ' . $commands['expected'] . "\n";
                $failed = 1;
            } else {
                echo 'ok: npm script ' . $name . "\n";
            }
        }

        return $failed;
    }

    /**
     * Verifies that the caller composer.json carries the shared QA state
     * (CI gate): the applicable shared scripts exist and every aggregate
     * matches the standard.
     *
     * @throws RuntimeException
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
                echo 'FAIL: script ' . $script['name'] . ' missing (run qa sync and commit)' . "\n";
                $failed = 1;
            }
        }

        $standards = self::aggregateStandards($scripts, $root);

        foreach ($standards as $aggregate => $members) {
            if (!isset($scripts[$aggregate])) {
                echo "FAIL: {$aggregate} missing (run qa sync and commit)\n";
                $failed = 1;

                continue;
            }

            $deviation = self::aggregateDeviations((array) $scripts[$aggregate], $members, in_array($aggregate, ['fix:all', 'qa'], true));

            if ([] === $deviation['missing'] && [] === $deviation['extra'] && [] === $deviation['duplicates'] && !$deviation['order']) {
                echo "ok: {$aggregate}\n";
            } else {
                echo "FAIL: {$aggregate} deviates from the expected standard\n";
                self::printAggregateStandard($aggregate, $deviation, $members);
                $failed = 1;
            }
        }

        return $failed;
    }

    /**
     * Reports declared version coverage without changing dependencies or the exit code.
     * Composer's matrix installations remain responsible for dependency resolution.
     *
     * @throws JsonException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private static function warnDevRequirements(string $root): void
    {
        $data         = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $requirements = [];
        $parser       = new VersionParser();

        if (isset($data['require-dev']['saschaegerer/phpstan-typo3'])) {
            $branches = [];
            $core     = $data['require']['typo3/cms-core'] ?? $data['require-dev']['typo3/cms-core'] ?? '^13.4 || ^14.3';

            try {
                $constraint = self::parseVersionConstraint($core);

                foreach (['^13.4' => '^2.1', '^14.3' => '^3.1'] as $coreBranch => $toolBranch) {
                    if (Intervals::haveIntersections($constraint, $parser->parseConstraints($coreBranch))) {
                        $branches[] = $toolBranch;
                    }
                }
            } catch (UnexpectedValueException $exception) {
                echo 'warning: typo3/cms-core has an invalid version constraint: ' . $exception->getMessage() . "\n";
            }

            if ([] !== $branches) {
                $requirements['saschaegerer/phpstan-typo3'] = ['branches' => $branches, 'condition' => 'always'];
            }
        }

        ksort($requirements);

        foreach ($requirements as $package => $requirement) {
            if (!self::scriptApplicable(['command' => '', 'condition' => $requirement['condition'], 'name' => ''], $root)) {
                continue;
            }

            $actual      = $data['require-dev'][$package] ?? null;
            $recommended = implode(' || ', $requirement['branches']);

            try {
                $constraint = self::parseVersionConstraint($actual);

                foreach ($requirement['branches'] as $branch) {
                    if (!Intervals::haveIntersections($constraint, $parser->parseConstraints($branch))) {
                        echo "warning: require-dev.{$package} is \"{$actual}\"; version branch {$branch} is not covered; recommended: {$recommended}\n";
                    }
                }
            } catch (UnexpectedValueException $exception) {
                echo "warning: require-dev.{$package} has an invalid version constraint: " . $exception->getMessage() . "; recommended: {$recommended}\n";
            }
        }

        self::warnRedundantDevRequirements($root);
    }

    /**
     * Only direct package requirements are a stable dependency contract.
     * A narrower caller constraint may be an intentional pin and is preserved.
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private static function warnRedundantDevRequirements(string $root): void
    {
        $caller  = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $tooling = json_decode((string) file_get_contents(self::packageRoot() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($tooling['require'] as $package => $required) {
            if (!str_contains($package, '/') || !isset($caller['require-dev'][$package])) {
                continue;
            }

            $actual = $caller['require-dev'][$package];

            try {
                if (Intervals::isSubsetOf(self::parseVersionConstraint($required), self::parseVersionConstraint($actual))) {
                    echo "warning: require-dev.{$package} can be removed; psbits/qa already requires {$required}, fully covered by your constraint {$actual}\n";
                }
            } catch (UnexpectedValueException $exception) {
                echo "warning: require-dev.{$package} has an invalid version constraint: " . $exception->getMessage() . "\n";
            }
        }
    }

    /**
     * @throws RuntimeException
     */
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
                echo 'xlf: unsorted trans-unit/group ids in ' . self::relative($root, $file) . "\n";
            }

            foreach ($result['problems'] as $problem) {
                echo 'xlf: ' . $problem . "\n";
            }

            if ([] === $result['unsorted'] && [] === $result['problems']) {
                echo 'xlf: trans-unit and group ids sorted in ' . count($files) . ' file(s)' . "\n";

                return 0;
            }

            return 1;
        }

        $result = $sorter->apply($files);

        if ([] === $result['changed']) {
            echo 'xlf: trans-unit and group ids already sorted in ' . count($files) . ' file(s)' . "\n";
        }

        foreach ($result['changed'] as $file) {
            echo 'xlf: sorted trans-unit and group ids in ' . self::relative($root, $file) . "\n";
        }

        foreach ($result['skipped'] as $skip) {
            echo 'xlf: ' . $skip . "\n";
        }

        return 0;
    }
}
