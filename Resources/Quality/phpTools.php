<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/*
 * The shared PHP tool runner behind the `lint:php` and `fix:php` composer
 * scripts. It drives the repository's PHP style tools in one command -
 * PHP-CS-Fixer (the shared CGL configuration) and PHP_CodeSniffer (the
 * shared Slevomat control-structure spacing rule, see the psbits-cgl
 * skill, 15). A tool runs when its configuration exists: in an extension
 * repository the shims `Build/Quality/.php-cs-fixer.dist.php` and
 * `Build/Quality/.phpcs.xml.dist`, in the psbits/qa repository itself the
 * root `.php-cs-fixer.php` and `.phpcs.xml.dist`.
 *
 * Usage: php phpTools.php <lint|fix> [paths...]
 *
 * lint  the PHP-CS-Fixer dry-run, then PHP_CodeSniffer;
 * fix   the PHP_CodeSniffer autofix first, then the PHP-CS-Fixer run,
 *       which normalizes the result (the Slevomat fixer adjusts the
 *       blank lines only).
 *
 * The given paths are passed to every tool that runs and replace its
 * configured scope. Every tool runs, even when an earlier one reports
 * findings; the exit code is non-zero when any tool failed. The
 * repository root is taken from PSBITS_QA_REPO_ROOT or the current
 * working directory.
 */

$mode = $argv[1] ?? '';

if ('lint' !== $mode && 'fix' !== $mode) {
    fwrite(STDERR, 'Usage: php phpTools.php <lint|fix> [paths...]' . "\n");

    exit(1);
}

$root = getenv('PSBITS_QA_REPO_ROOT') ?: getcwd();

if (is_file($root . '/Build/Quality/.php-cs-fixer.dist.php') || is_file($root . '/Build/Quality/.phpcs.xml.dist')) {
    /*
     * An extension repository: the shim configurations live in
     * Build/Quality, the tool binaries in .Build/bin.
     */
    $csFixerConfig = 'Build/Quality/.php-cs-fixer.dist.php';
    $phpcsStandard = 'Build/Quality/.phpcs.xml.dist';
    $binDir        = $root . '/.Build/bin';
} else {
    /*
     * The psbits/qa repository itself: the self-lint configurations at
     * the root, the tool binaries in its vendor directory.
     */
    $csFixerConfig = '.php-cs-fixer.php';
    $phpcsStandard = '.phpcs.xml.dist';
    $binDir        = $root . '/vendor/bin';
}

$paths = [];

foreach (array_slice($argv, 2) as $path) {
    if ('--' === $path && [] === $paths) {
        continue;
    }

    $paths[] = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
}

$tools = [];

if (is_file($root . '/' . $csFixerConfig)) {
    $arguments = ['fix', '--config=' . $csFixerConfig];

    if ('fix' === $mode) {
        $arguments[] = '--verbose';
    } else {
        $arguments[] = '--dry-run';
        $arguments[] = '--diff';
        $arguments[] = '--verbose';
    }

    $tools['php-cs-fixer'] = array_merge($arguments, $paths);
}

if (is_file($root . '/' . $phpcsStandard)) {
    $tools['fix' === $mode ? 'phpcbf' : 'phpcs'] = array_merge(['--standard=' . $phpcsStandard], $paths);
}

if ([] === $tools) {
    fwrite(STDERR, 'error: no PHP style configuration found in ' . $root . ' (expected the PHP-CS-Fixer or the PHP_CodeSniffer configuration)' . "\n");

    exit(1);
}

$order = 'lint' === $mode ? ['php-cs-fixer', 'phpcs'] : ['phpcbf', 'php-cs-fixer'];
chdir($root);
$failures = 0;

foreach ($order as $tool) {
    if (!isset($tools[$tool])) {
        continue;
    }

    $exitCode = runTool($mode, $binDir . '/' . $tool, $tools[$tool]);

    if (0 !== $exitCode) {
        $failures++;
    }
}

exit(0 < $failures ? 1 : 0);

function runTool(string $mode, string $binary, array $arguments): int
{
    $command = array_merge([$binary], $arguments);

    fwrite(STDERR, '[' . $mode . '] ' . implode(' ', $command) . "\n");

    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (false === $process) {
        fwrite(STDERR, 'error: could not start ' . $binary . "\n");

        return 1;
    }

    fclose($pipes[0]);
    stream_copy_to_stream($pipes[1], STDOUT);
    fclose($pipes[1]);
    stream_copy_to_stream($pipes[2], STDERR);
    fclose($pipes[2]);

    return proc_close($process);
}
