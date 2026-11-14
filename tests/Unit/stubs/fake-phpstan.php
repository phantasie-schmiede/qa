<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/**
 * Fake phpstan binary for the ThrowsFixerTest orchestration tests: prints
 * the staged JSON responses (one per call, in order), counts the calls, and
 * logs every invocation. The working directory is the fixture repository
 * root.
 */
$state = file_get_contents(__DIR__ . '/test-state');
$call  = is_file($state . '/phpstan-calls') ? (int) file_get_contents($state . '/phpstan-calls') : 0;
file_put_contents($state . '/phpstan-calls', (string) ($call + 1));
file_put_contents($state . '/phpstan.log', json_encode($argv) . "\n", FILE_APPEND);

if (is_file($state . '/large-stderr')) {
    fwrite(STDERR, str_repeat('phpstan diagnostic ', 65536));
}

$responses = json_decode((string) file_get_contents($state . '/responses.json'), true);

if (!is_array($responses)) {
    fwrite(STDERR, "the staged responses are not parseable\n");

    exit(1);
}

$data = $responses[$call] ?? null;

if (null === $data) {
    fwrite(STDERR, 'no staged response for call ' . ($call + 1) . "\n");

    exit(1);
}

echo json_encode($data);
file_put_contents($state . '/sources.log', file_get_contents(getcwd() . '/src/Service.php') . "\n", FILE_APPEND);

exit(is_file($state . '/phpstan-exit') ? (int) file_get_contents($state . '/phpstan-exit') : 0);
