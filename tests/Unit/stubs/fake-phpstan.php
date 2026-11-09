<?php

declare(strict_types=1);

/**
 * Fake phpstan binary for the ThrowsFixerTest orchestration tests: prints
 * the staged JSON responses (one per call, in order) and counts the calls.
 * The working directory is the fixture repository root.
 */
$state = getcwd() . '/.state';
$call  = is_file($state . '/phpstan-calls') ? (int) file_get_contents($state . '/phpstan-calls') : 0;
file_put_contents($state . '/phpstan-calls', (string) ($call + 1));

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
