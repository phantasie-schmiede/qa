<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

use FilesystemIterator;
use PSBits\Qa\PhpStan\ThrowsDocblockAuditRule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;
use ValueError;

use function array_diff;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function reset;
use function rmdir;
use function sprintf;
use function str_starts_with;
use function strcasecmp;
use function stream_get_contents;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usort;

/**
 * Rewrites the @throws docblocks against the full matrix of supported
 * TYPO3 core majors.
 *
 * The matrix is read from the typo3/cms-core constraint in the
 * repository's composer.json. For every matrix entry the fixer switches
 * the installed core (composer update --with ... -W, preceded by a full
 * vendor wipe when the core major changes - composer loads the composer
 * plugins from the pre-update vendor state, and a mismatched
 * class-alias-loader major crashes the update) and runs the PHPStan
 * analysis (with the psbits.throwsDocblockAudit rule) in a loop
 * until the docblocks carry the core's thrown set - fixing a callee
 * changes what its callers have to declare, so the fix cascades; the
 * loop aborts with a message after twenty-five rounds per core. A cross
 * validation over all cores follows, because the additions of one core
 * can change the thrown set of another, and it repeats with a repair
 * loop until every core is clean.
 *
 * Only after every core has converged the fixer performs the final
 * merge: one write per method with the exact union of the per-core
 * thrown sets. A line seen by a single core carries a trailing "// t3:N"
 * marker (analyzer visibility, not runtime throwability); lines seen by
 * no core are removed. The starting core is restored when the run ends,
 * whatever the outcome.
 *
 * The per-core loops are additive (only missing @throws lines are
 * added), so a failed or interrupted run never leaves the docblocks in
 * a state that is worse than the starting state; the removal happens in
 * the final merge only. The use statements orphaned by the merge are
 * dropped by the php-cs-fixer run (no_unused_imports).
 */
final class ThrowsFixer
{
    public const int MAX_REPAIR_ROUNDS = 3;
    public const int MAX_ROUNDS        = 25;

    public function __construct(private readonly string $repoRoot)
    {
    }

    /**
     * @param array<string, mixed> $data phpstan JSON output
     * @return list<array{file: string, line: int, message: string}>
     */
    public static function collectEntries(array $data): array
    {
        $entries = [];

        foreach (($data['files'] ?? []) as $file => $info) {
            /*
             * PHPStan reports trait methods under the file key
             * "Trait.php (in context of class Foo\Bar)"; the applier needs
             * the bare file path. The context may name an anonymous class
             * used in a test ("class@anonymous/path/to/Test.php:42"), so
             * no space after "class" is required.
             */
            $file = preg_replace('/ \(in context of class[^)]*\)$/', '', (string) $file);

            foreach (($info['messages'] ?? []) as $message) {
                if (ThrowsDocblockAuditRule::IDENTIFIER !== ($message['identifier'] ?? null)) {
                    continue;
                }

                $entries[] = [
                    'file'    => (string) $file,
                    'line'    => (int) ($message['line'] ?? 0),
                    'message' => (string) ($message['message'] ?? ''),
                ];
            }
        }

        return $entries;
    }

    /**
     * @return null|list<string> the supported cores as "major.minor"
     *                          pairs (e.g. ["13.4", "14.3"]) in the order
     *                          of the constraint, or null when the
     *                          constraint is missing or not parseable
     */
    public static function matrixFromComposerJson(string $composerJson): ?array
    {
        $data       = json_decode((string) file_get_contents($composerJson), true);
        $constraint = $data['require']['typo3/cms-core'] ?? null;

        if (!is_string($constraint)) {
            return null;
        }

        $matrix = [];

        foreach (explode('||', $constraint) as $segment) {
            if (1 !== preg_match('/^(?:[\^~]|>=|<=|>|<)?(\d+)(?:\.(\d+))?$/', trim($segment), $match)) {
                return null;
            }

            $key = $match[1] . '.' . ($match[2] ?? '0');

            if (!in_array($key, $matrix, true)) {
                $matrix[] = $key;
            }
        }

        return $matrix;
    }

    /**
     * @param list<array{file: string, line: int, message: string}> $entries
     */
    private static function formatEntries(array $entries): string
    {
        $lines = '';

        foreach ($entries as $entry) {
            $lines .= "\n  " . $entry['file'] . ':' . $entry['line'] . ' ' . $entry['message'];
        }

        return $lines;
    }

    /**
     * @param string $version e.g. 13.4.37
     */
    private static function majorMinorOf(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /**
     * The entries whose expected set is not a subset of the declared set
     * (the methods with @throws lines still to add).
     *
     * @param list<array{file: string, line: int, message: string}> $entries
     * @return list<array{file: string, line: int, message: string}>
     */
    private static function missingEntries(array $entries): array
    {
        $missing = [];

        foreach ($entries as $entry) {
            $parsed = ThrowsDocblockApplier::parseMessage($entry['message']);

            if (null === $parsed) {
                continue;
            }

            $expected = array_map(static fn(array $item): string => $item['name'], $parsed['expected']);

            if ([] !== array_diff($expected, $parsed['declared'])) {
                $missing[] = $entry;
            }
        }

        return $missing;
    }

    /**
     * @throws RuntimeException
     * @throws ValueError
     */
    public function run(): int
    {
        $matrix = self::matrixFromComposerJson($this->repoRoot . '/composer.json');

        if (null === $matrix) {
            fwrite(STDERR, "error: the typo3/cms-core constraint in composer.json is missing or not parseable (expected e.g. \"^13.4 || ^14.3\")\n");

            return 1;
        }

        $startingVersion = $this->installedCoreVersion();

        if (null === $startingVersion) {
            fwrite(STDERR, "error: typo3/cms-core is not installed (run composer install first)\n");

            return 1;
        }

        $config = $this->repoRoot . '/Build/Quality/phpstan.neon.dist';

        if (!is_file($config)) {
            fwrite(STDERR, "error: phpstan configuration not found at {$config}\n");

            return 1;
        }

        $tempConfig = $this->createTempConfig($config);
        $applier    = new ThrowsDocblockApplier();
        $sets       = [];

        try {
            foreach ($this->orderedMatrix($matrix, $startingVersion) as $majorMinor) {
                $this->switchToCore($majorMinor);
                $sets[$majorMinor] = $this->converge($applier, $tempConfig, $majorMinor);
            }

            $this->validateAndRepair($applier, $tempConfig, $matrix, $sets);
            $this->merge($applier, $sets);

            return 0;
        } finally {
            $this->restoreCore($startingVersion);
            unlink($tempConfig);
        }
    }

    private function absolute(string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }

        return $this->repoRoot . '/' . $file;
    }

    /**
     * @return array<string, mixed> the decoded phpstan JSON
     * @throws RuntimeException
     * @throws ValueError
     */
    private function analyse(string $config): array
    {
        $phpstan = $this->repoRoot . '/.Build/bin/phpstan';

        if (!is_executable($phpstan)) {
            throw new RuntimeException('phpstan not found at ' . $phpstan . ' (run composer install first)');
        }

        $command = [
            PHP_BINARY,
            $phpstan,
            'analyse',
            '--configuration=' . $config,
            '--error-format=json',
            '--no-progress',
        ];

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->repoRoot);

        if (!is_resource($process)) {
            throw new RuntimeException('could not run phpstan');
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $start = strpos($output, '{"totals"');

        if (false === $start) {
            $detail = trim($stderr);

            throw new RuntimeException('no phpstan JSON in the output' . ('' !== $detail ? ': ' . $detail : ''));
        }

        $data = json_decode(substr($output, $start), true);

        if (!is_array($data)) {
            throw new RuntimeException('the phpstan output is not parseable');
        }

        if (($data['totals']['errors'] ?? 0) > 0) {
            $internal = implode(' | ', (array) ($data['errors'] ?? []));

            throw new RuntimeException('phpstan reported internal errors: ' . $internal);
        }

        return $data;
    }

    /**
     * @param list<array{file: string, line: int, message: string}> $entries
     * @return array{fixed: int, skipped: list<string>}
     * @throws RuntimeException
     * @throws ValueError
     */
    private function apply(ThrowsDocblockApplier $applier, array $entries): array
    {
        $byFile = [];

        foreach ($entries as $entry) {
            $entry['file']            = $this->absolute($entry['file']);
            $byFile[$entry['file']][] = $entry;
        }

        $fixed   = 0;
        $skipped = [];

        foreach ($byFile as $file => $fileEntries) {
            $result = $applier->apply($file, $fileEntries);
            $fixed += $result['fixed'];

            foreach ($result['skipped'] as $skip) {
                $skipped[] = $skip;
            }
        }

        return ['fixed' => $fixed, 'skipped' => $skipped];
    }

    /**
     * @param list<string> $arguments
     * @throws RuntimeException
     * @throws ValueError
     */
    private function composer(array $arguments): void
    {
        $this->exec(['composer', ...$arguments]);
    }

    /**
     * Runs the analysis loop for the installed core until the docblocks
     * carry the core's thrown set (additive: only missing exception
     * lines are added, existing lines and markers are preserved).
     *
     * @return list<array{file: string, line: int, message: string}> the final audit snapshot
     * @throws RuntimeException
     * @throws ValueError
     */
    private function converge(ThrowsDocblockApplier $applier, string $tempConfig, string $majorMinor): array
    {
        $round = 0;

        while (true) {
            $entries = self::collectEntries($this->analyse($tempConfig));
            $missing = self::missingEntries($entries);

            if ([] === $missing) {
                echo "throws-fix: [{$majorMinor}] converged after {$round} fix round(s)\n";

                return $entries;
            }

            if (self::MAX_ROUNDS <= $round) {
                throw new RuntimeException(sprintf(
                    '@throws did not converge after %d fix rounds (%s). %d unresolved report(s):%s',
                    self::MAX_ROUNDS,
                    $majorMinor,
                    count($missing),
                    self::formatEntries(array_slice($missing, 0, 20)),
                ));
            }

            ++$round;
            $result = $this->apply($applier, $missing);

            $message = sprintf('throws-fix: [%s] round %d: %d method(s) updated', $majorMinor, $round, $result['fixed']);

            if ([] !== $result['skipped']) {
                $message .= sprintf(', %d skipped', count($result['skipped']));
            }

            echo $message . "\n";

            foreach ($result['skipped'] as $skip) {
                echo "  skipped: {$skip}\n";
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    private function createTempConfig(string $repoConfig): string
    {
        $file = tempnam(sys_get_temp_dir(), 'psbits-throws-');

        if (false === $file) {
            throw new RuntimeException('could not create the temporary phpstan configuration');
        }

        $content = sprintf(
            "includes:\n    - %s\nrules:\n    - %s\n",
            $repoConfig,
            ThrowsDocblockAuditRule::class,
        );

        if (false === file_put_contents($file, $content)) {
            unlink($file);

            throw new RuntimeException('could not write the temporary phpstan configuration');
        }

        return $file;
    }

    /**
     * @param list<string> $command
     * @throws RuntimeException
     * @throws ValueError
     */
    private function exec(array $command): void
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->repoRoot);

        if (!is_resource($process)) {
            throw new RuntimeException('could not run ' . $command[0]);
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if (0 !== $code) {
            $detail = trim('' !== $stderr ? $stderr : substr($output, -400));

            throw new RuntimeException('"' . implode(' ', $command) . '" failed' . ('' !== $detail ? ': ' . $detail : ''));
        }
    }

    /**
     * @return null|string the installed typo3/cms-core version (e.g. 13.4.37)
     */
    private function installedCoreVersion(): ?string
    {
        $installed = $this->repoRoot . '/.Build/vendor/composer/installed.json';

        if (!is_file($installed)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($installed), true);

        if (!is_array($data)) {
            return null;
        }

        foreach (($data['packages'] ?? []) as $package) {
            if (is_array($package) && 'typo3/cms-core' === ($package['name'] ?? null)) {
                /*
                 * Composer records versions with a leading "v" (e.g.
                 * v14.3.10); strip it so the major compares equal to
                 * the matrix entries.
                 */
                $version = ltrim((string) ($package['version'] ?? ''), 'v');

                return '' !== $version ? $version : null;
            }
        }

        return null;
    }

    /**
     * The final write: per method the exact union of the per-core thrown
     * sets, a "// t3:N" marker on the lines a single major sees and no
     * line for an exception no core sees.
     *
     * @param array<string, list<array{file: string, line: int, message: string}>> $sets major.minor => final audit snapshot
     * @throws RuntimeException
     * @throws ValueError
     */
    private function merge(ThrowsDocblockApplier $applier, array $sets): void
    {
        $methods = [];

        foreach ($sets as $majorMinor => $entries) {
            $major = explode('.', $majorMinor)[0];

            foreach ($entries as $entry) {
                $parsed = ThrowsDocblockApplier::parseMessage($entry['message']);

                if (null === $parsed) {
                    continue;
                }

                $position = (int) strpos($entry['message'], ' @throws');
                $subject  = substr($entry['message'], 0, $position);
                $key      = $entry['file'] . "\0" . $subject;

                $methods[$key]['file']     = $entry['file'];
                $methods[$key]['line']     = $entry['line'];
                $methods[$key]['subject']  = $subject;
                $methods[$key]['declared'] = $parsed['declared'];

                if (!isset($methods[$key]['names'])) {
                    $methods[$key]['names'] = [];
                }

                foreach ($parsed['expected'] as $item) {
                    $methods[$key]['names'][$item['name']] = array_unique(
                        array_merge($methods[$key]['names'][$item['name']] ?? [], [$major]),
                    );
                }
            }
        }

        $entriesByFile = [];

        foreach ($methods as $method) {
            $items = [];

            foreach ($method['names'] as $name => $majors) {
                $marker  = 1 === count($majors) ? ' // t3:' . reset($majors) : '';
                $items[] = $name . $marker;
            }

            usort($items, 'strcasecmp');

            $message = $method['subject'] . ' @throws do not match the thrown exceptions: expected '
                . (implode(', ', $items) ?: 'none')
                . ', declared '
                . (implode(', ', array_map(static fn(string $name): string => '\\' . $name, $method['declared'])) ?: 'none')
                . '.';

            $entriesByFile[$this->absolute($method['file'])][] = [
                'file'    => $this->absolute($method['file']),
                'line'    => $method['line'],
                'message' => $message,
            ];
        }

        $fixed = 0;

        foreach ($entriesByFile as $file => $fileEntries) {
            $result = $applier->apply($file, $fileEntries, true);
            $fixed += $result['fixed'];

            foreach ($result['skipped'] as $skip) {
                echo "  skipped: {$skip}\n";
            }
        }

        echo sprintf("throws-fix: merge: %d method(s) updated\n", $fixed);
    }

    /**
     * The matrix ordered with the major of the installed core first, so
     * that the first leg needs no core switch.
     *
     * @param list<string> $matrix
     * @return list<string>
     */
    private function orderedMatrix(array $matrix, string $installedVersion): array
    {
        $installedMajor = explode('.', $installedVersion)[0];

        usort($matrix, static function(string $a, string $b) use ($installedMajor): int {
            $rankA = explode('.', $a)[0] === $installedMajor ? 0 : 1;
            $rankB = explode('.', $b)[0] === $installedMajor ? 0 : 1;

            return $rankA <=> $rankB ?: strcasecmp($a, $b);
        });

        return $matrix;
    }

    /**
     * Restores the starting core; a failed restore is reported, but does
     * not mask the original error (this runs in the finally block).
     */
    private function restoreCore(string $startingVersion): void
    {
        $installed = (string) $this->installedCoreVersion();

        if (0 === strcasecmp($installed, $startingVersion)) {
            return;
        }

        try {
            if (explode('.', $installed)[0] !== explode('.', $startingVersion)[0]) {
                $this->wipeVendor();
            }

            $this->composer(['update', '--with', 'typo3/cms-core:' . $startingVersion, '-W']);
            echo "throws-fix: core restored to {$startingVersion}\n";
        } catch (RuntimeException|ValueError $exception) {
            fwrite(
                STDERR,
                sprintf(
                    "error: the core could not be restored to %s (%s); run \"composer update --with typo3/cms-core:%s -W\" manually\n",
                    $startingVersion,
                    $exception->getMessage(),
                    $startingVersion,
                ),
            );
        }
    }

    /**
     * Switches the installed core to the matrix entry; a no-op when the
     * entry already matches the installed version. A core major change
     * wipes the vendor directory first (see wipeVendor()).
     *
     * @throws RuntimeException
     * @throws ValueError
     */
    private function switchToCore(string $majorMinor): void
    {
        $installed = $this->installedCoreVersion();

        if (null !== $installed && 0 === strcasecmp($majorMinor, self::majorMinorOf($installed))) {
            return;
        }

        if (null !== $installed && explode('.', $majorMinor)[0] !== explode('.', $installed)[0]) {
            $this->wipeVendor();
        }

        echo 'throws-fix: switching core ' . (null !== $installed ? $installed . ' -> ' : '') . '^' . $majorMinor . "\n";

        $this->composer(['update', '--with', 'typo3/cms-core:^' . $majorMinor, '-W']);
    }

    /**
     * Re-analyses every core (the additions of one core can change the
     * thrown set of another) and re-converges the cores with missing
     * lines, repeating until every core is clean.
     *
     * @param list<string>                                                         $matrix
     * @param array<string, list<array{file: string, line: int, message: string}>> $sets   major.minor => final audit snapshot
     * @throws RuntimeException
     * @throws ValueError
     */
    private function validateAndRepair(ThrowsDocblockApplier $applier, string $tempConfig, array $matrix, array &$sets): void
    {
        for ($cycle = 1; self::MAX_REPAIR_ROUNDS >= $cycle; ++$cycle) {
            $repair = [];

            foreach ($matrix as $majorMinor) {
                $this->switchToCore($majorMinor);
                $entries = self::collectEntries($this->analyse($tempConfig));
                $missing = self::missingEntries($entries);

                if ([] === $missing) {
                    $sets[$majorMinor] = $entries;

                    continue;
                }

                $repair[$majorMinor] = true;
            }

            if ([] === $repair) {
                return;
            }

            if (self::MAX_REPAIR_ROUNDS === $cycle) {
                throw new RuntimeException(sprintf(
                    'no @throws fixpoint after %d validation round(s); cores with missing lines: %s',
                    self::MAX_REPAIR_ROUNDS,
                    implode(', ', array_keys($repair)),
                ));
            }

            foreach (array_keys($repair) as $majorMinor) {
                $this->switchToCore($majorMinor);
                $sets[$majorMinor] = $this->converge($applier, $tempConfig, $majorMinor);
            }
        }
    }

    /**
     * Removes the whole vendor directory (no-op when it does not exist)
     * so that the composer run starts from a clean vendor state:
     * composer loads the composer plugins from the pre-update vendor
     * state, and a class-alias-loader major that mismatches the target
     * core crashes the update (missing CaseSensitiveToken/SuffixToken
     * class).
     *
     * @throws RuntimeException
     */
    private function wipeVendor(): void
    {
        $directory = $this->repoRoot . '/.Build/vendor';

        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();
                $removed  = is_dir($itemPath) && !is_link($itemPath) ? rmdir($itemPath) : unlink($itemPath);

                if (false === $removed) {
                    throw new RuntimeException('could not remove ' . $itemPath);
                }
            }
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('could not remove ' . $directory, 0, $exception);
        }

        if (false === rmdir($directory)) {
            throw new RuntimeException('could not remove ' . $directory);
        }
    }
}
