<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Qa\Quality;

use PSBits\Qa\PhpStan\ThrowsDocblockAuditRule;
use Random\RandomException;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use UnexpectedValueException;
use ValueError;

use function array_diff;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function is_string;
use function json_decode;
use function ltrim;
use function mkdir;
use function preg_match;
use function preg_replace;
use function reset;
use function sprintf;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;
use function usort;

/**
 * Rewrites the @throws docblocks against the full matrix of supported
 * TYPO3 core majors.
 *
 * The matrix is read from the typo3/cms-core constraint in the
 * repository's composer.json. For every matrix entry the fixer creates a
 * disposable repository copy with its own Composer installation and runs
 * PHPStan analysis (with the psbits.throwsDocblockAudit rule) in a loop
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
 * marker when the matrix spans several cores (analyzer visibility, not
 * runtime throwability); lines seen by no core are removed. The normal vendor/bin directories and Composer files
 * remain untouched; no dependency restoration is needed. Only copied repository
 * files may be mapped back to the original source. The current QA code is
 * snapshotted for every run, so all majors use the same rules and applier.
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

    private ?ThrowsAnalysisEnvironment $environment = null;

    /** @var array<string, ThrowsAnalysisEnvironment> */
    private array           $environments = [];
    private readonly string $repoRoot;

    /** @throws RuntimeException */
    public function __construct(string $repoRoot)
    {
        $this->repoRoot = realpath($repoRoot) ?: throw new RuntimeException('repository root not found: ' . $repoRoot);
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
     * @throws RandomException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws ValueError
     */
    public function run(): int
    {
        $matrix = self::matrixFromComposerJson($this->repoRoot . '/composer.json');

        if (null === $matrix) {
            fwrite(STDERR, "error: the typo3/cms-core constraint in composer.json is missing or not parseable (expected e.g. \"^13.4 || ^14.3\")\n");

            return 1;
        }

        $config = $this->repoRoot . '/Build/Quality/phpstan.neon.dist';

        if (!is_file($config)) {
            fwrite(STDERR, "error: phpstan configuration not found at {$config}\n");

            return 1;
        }

        $build = $this->repoRoot . '/.Build/throws-fix';

        if (!is_dir($build) && !mkdir($build, 0755, true)) {
            throw new RuntimeException('could not create ' . $build);
        }

        $workspace = $build . '/' . bin2hex(random_bytes(8));

        if (!mkdir($workspace, 0755)) {
            throw new RuntimeException('could not create ' . $workspace);
        }

        $applier = new ThrowsDocblockApplier();
        $sets    = [];
        $cleaned = true;

        try {
            $snapshot = new ThrowsAnalysisEnvironment($this->repoRoot, $workspace);
            $snapshot->snapshotTooling(dirname(__DIR__, 2), $workspace . '/tooling');

            foreach ($matrix as $majorMinor) {
                $this->environments[$majorMinor] = new ThrowsAnalysisEnvironment($this->repoRoot, $workspace . '/' . $majorMinor);
                $this->switchToCore($majorMinor);
                $this->activeEnvironment()->prepare($majorMinor, $workspace . '/tooling');
                $this->composer(['update', '-W', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist']);
                $installed = json_decode((string) file_get_contents($this->activeEnvironment()->root . '/.Build/vendor/composer/installed.json'), true);
                $versions  = array_column($installed['packages'] ?? [], 'version', 'name');

                if (!str_starts_with(ltrim($versions['typo3/cms-core'] ?? '', 'v'), $majorMinor . '.')) {
                    throw new RuntimeException('isolated installation does not contain core ' . $majorMinor);
                }

                $sets[$majorMinor] = $this->converge($applier, $majorMinor);
            }

            $this->validateAndRepair($applier, $matrix, $sets);
            $this->merge($applier, $sets);
        } finally {
            $this->environment  = null;
            $this->environments = [];

            try {
                ThrowsAnalysisEnvironment::remove($workspace);
            } catch (RuntimeException|UnexpectedValueException $exception) {
                $cleaned = false;
                fwrite(STDERR, 'warning: isolated analysis cleanup failed: ' . $exception->getMessage() . "\n");
            }
        }

        return $cleaned ? 0 : 1;
    }

    private function absolute(string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }

        return $this->repoRoot . '/' . $file;
    }

    /** @throws RuntimeException */
    private function activeEnvironment(): ThrowsAnalysisEnvironment
    {
        return $this->environment ?? throw new RuntimeException('no active analysis environment');
    }

    /**
     * @return array<string, mixed> the decoded phpstan JSON
     * @throws RuntimeException
     * @throws ValueError
     */
    private function analyse(): array
    {
        $environment = $this->activeEnvironment();
        $environment->refreshSources();
        $config  = $environment->root . '/throws-audit.neon';
        $content = "includes:\n    - Build/Quality/phpstan.neon.dist\nrules:\n    - " . ThrowsDocblockAuditRule::class . "\n";

        if (false === file_put_contents($config, $content)) {
            throw new RuntimeException('could not write ' . $config);
        }

        $phpstan = $environment->root . '/.Build/bin/phpstan';

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

        $process = $this->process($command);
        $output  = $process['stdout'];
        $stderr  = $process['stderr'];

        // PHPStan uses 1 for findings; other statuses indicate a failed analysis.
        if (!in_array($process['code'], [0, 1], true)) {
            throw new RuntimeException('phpstan failed: ' . trim($stderr ?: substr($output, -400)));
        }

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
     * @return list<array{file: string, line: int, message: string}>
     * @throws RuntimeException
     * @throws ValueError
     */
    private function analysisEntries(): array
    {
        $entries = self::collectEntries($this->analyse());

        foreach ($entries as &$entry) {
            $entry['file'] = $this->activeEnvironment()->originalFile($entry['file']);
        }

        unset($entry);

        return $entries;
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
    private function converge(ThrowsDocblockApplier $applier, string $majorMinor): array
    {
        $round = 0;

        while (true) {
            $entries = $this->analysisEntries();
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
     * @param list<string> $command
     * @throws RuntimeException
     */
    private function exec(array $command): void
    {
        $process = $this->process($command);
        $output  = $process['stdout'];
        $stderr  = $process['stderr'];
        $code    = $process['code'];

        if (0 !== $code) {
            $detail = trim('' !== $stderr ? $stderr : substr($output, -400));

            throw new RuntimeException('"' . implode(' ', $command) . '" failed' . ('' !== $detail ? ': ' . $detail : ''));
        }
    }

    /**
     * The final write: per method the exact union of the per-core thrown
     * sets, a "// t3:N" marker on the lines a single major sees (only
     * when the matrix spans several majors; with one major every line is
     * seen by all majors and stays unmarked) and no line for an exception
     * no core sees.
     *
     * @param array<string, list<array{file: string, line: int, message: string}>> $sets major.minor => final audit snapshot
     * @throws RuntimeException
     * @throws ValueError
     */
    private function merge(ThrowsDocblockApplier $applier, array $sets): void
    {
        $markSingleMajor = 1 < count($sets);
        $methods         = [];

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
                $marker = $markSingleMajor && 1 === count($majors)
                    ? ' // t3:' . reset($majors)
                    : '';
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
     * Drain stdout and stderr concurrently; Composer can fill stderr while
     * stdout is idle. Updates/analysis have no fixed wall-clock timeout.
     *
     * @param list<string> $command
     * @return array{stdout: string, stderr: string, code: int|null}
     * @throws RuntimeException
     */
    private function process(array $command): array
    {
        try {
            $root    = $this->activeEnvironment()->root;
            $process = new Process($command, $root, [
                'COMPOSER'                 => 'composer.json',
                'COMPOSER_VENDOR_DIR'      => false,
                'COMPOSER_BIN_DIR'         => false,
                'PSBITS_QA_REPO_ROOT'      => $root,
                'TYPO3_PATH_APP'           => $root,
                'TYPO3_PATH_ROOT'          => $root . '/.Build/public',
                'TYPO3_PATH_WEB'           => $root . '/.Build/public',
                'TYPO3_PATH_COMPOSER_ROOT' => $root,
            ], timeout: null);
            $process->run();

            return ['stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(), 'code' => $process->getExitCode()];
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException('could not run ' . $command[0] . ': ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Selects the disposable installation for a matrix entry.
     *
     * @throws RuntimeException
     * @throws ValueError
     */
    private function switchToCore(string $majorMinor): void
    {
        $this->environment = $this->environments[$majorMinor];
        echo 'throws-fix: isolated core ' . $majorMinor . ' at ' . $this->environment->root . "\n";
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
    private function validateAndRepair(ThrowsDocblockApplier $applier, array $matrix, array &$sets): void
    {
        for ($cycle = 1; self::MAX_REPAIR_ROUNDS >= $cycle; ++$cycle) {
            $repair = [];

            foreach ($matrix as $majorMinor) {
                $this->switchToCore($majorMinor);
                $entries = $this->analysisEntries();
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
                $sets[$majorMinor] = $this->converge($applier, $majorMinor);
            }
        }
    }
}
