<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

use PSBits\Qa\PhpStan\ThrowsDocblockRule;

use function array_slice;
use function count;
use function implode;
use function is_array;
use function is_file;
use function is_resource;
use function json_decode;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Runs the PHPStan analysis and rewrites the @throws docblocks until the
 * analysis reports no @throws mismatch (the psbits.throwsDocblock
 * identifier) anymore.
 *
 * The loop is necessary because fixing the @throws of a method changes
 * what its callers have to declare (with exceptions.implicitThrows=false
 * the thrown set of a call is the declared @throws of the callee), so the
 * fix cascades through the call graph. The loop aborts with a message
 * after ten fix rounds.
 */
final class ThrowsFixer
{
    public const int MAX_ROUNDS = 10;

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
             * the bare file path
             */
            $file = preg_replace('/ \(in context of class [^)]+\)$/', '', (string) $file);

            foreach (($info['messages'] ?? []) as $message) {
                if (ThrowsDocblockRule::IDENTIFIER !== ($message['identifier'] ?? null)) {
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

    public function run(): int
    {
        $phpstan = $this->repoRoot . '/.Build/bin/phpstan';
        $config  = $this->repoRoot . '/Build/Quality/phpstan.neon.dist';

        if (!is_executable($phpstan)) {
            fwrite(STDERR, "error: phpstan not found at {$phpstan} (run composer install first)\n");

            return 1;
        }

        if (!is_file($config)) {
            fwrite(STDERR, "error: phpstan configuration not found at {$config}\n");

            return 1;
        }

        $applier = new ThrowsDocblockApplier();
        $round   = 0;

        while (true) {
            $data = $this->analyse($phpstan, $config);

            if (null === $data) {
                fwrite(STDERR, "error: the phpstan output is not parseable (round {$round})\n");

                return 1;
            }

            if (($data['totals']['errors'] ?? 0) > 0) {
                $internal = implode(' | ', (array) ($data['errors'] ?? []));
                fwrite(STDERR, "error: phpstan reported internal errors (round {$round}): {$internal}\n");

                return 1;
            }

            $entries = self::collectEntries($data);

            if ([] === $entries) {
                echo "throws-fix: converged after {$round} fix round(s)\n";

                return 0;
            }

            if (self::MAX_ROUNDS <= $round) {
                fwrite(STDERR, sprintf(
                    "error: @throws did not converge after %d fix rounds. %d unresolved report(s):\n",
                    self::MAX_ROUNDS,
                    count($entries),
                ));

                foreach (array_slice($entries, 0, 20) as $entry) {
                    fwrite(STDERR, '  ' . $entry['file'] . ':' . $entry['line'] . ' ' . $entry['message'] . "\n");
                }

                return 1;
            }

            ++$round;
            $result = $this->apply($applier, $entries);

            $message = sprintf('throws-fix: round %d: %d method(s) updated', $round, $result['fixed']);

            if ([] !== $result['skipped']) {
                $message .= sprintf(', %d skipped', count($result['skipped']));
            }

            echo $message . "\n";

            foreach ($result['skipped'] as $skip) {
                echo "  skipped: {$skip}\n";
            }
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
     * @return null|array<string, mixed> the decoded phpstan JSON
     */
    private function analyse(string $phpstan, string $config): ?array
    {
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
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (false === $output) {
            return null;
        }

        $start = strpos($output, '{"totals"');

        if (false === $start) {
            fwrite(STDERR, "error: no phpstan JSON in the output\n");

            if ('' !== trim((string) $stderr)) {
                fwrite(STDERR, rtrim((string) $stderr) . "\n");
            }

            return null;
        }

        $data = json_decode(substr($output, $start), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param list<array{file: string, line: int, message: string}> $entries
     * @return array{fixed: int, skipped: list<string>}
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
}
