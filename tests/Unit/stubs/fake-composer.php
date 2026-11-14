#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fake composer binary for the ThrowsFixerTest orchestration tests: logs
 * every invocation, switches the installed typo3/cms-core version in
 * .Build/vendor/composer/installed.json, and fails the calls listed in
 * .state/fail-calls (one-based call numbers, comma separated). The working
 * directory is the fixture repository root.
 */
$root  = getcwd();
$state = dirname(__DIR__);

$call = is_file($state . '/composer-calls') ? (int) file_get_contents($state . '/composer-calls') : 0;
file_put_contents($state . '/composer-calls', (string) ($call + 1));
file_put_contents($state . '/composer.log', json_encode($argv) . "\n", FILE_APPEND);

if (is_file($state . '/large-stderr')) {
    fwrite(STDERR, str_repeat('composer diagnostic ', 65536));
}

$failCalls = array_map(
    'intval',
    array_filter(explode(',', trim((string) file_get_contents($state . '/fail-calls')))),
);

if (in_array($call + 1, $failCalls, true)) {
    fwrite(STDERR, "boom: simulated composer failure\n");

    exit(1);
}

$arguments = $argv;
$manifest  = json_decode(file_get_contents($root . '/composer.json'), true);
$version   = $manifest['require']['typo3/cms-core'];

for ($i = 1; $i + 1 < count($arguments); ++$i) {
    if ('--with' !== $arguments[$i]) {
        continue;
    }

    $parts = explode(':', (string) $arguments[$i + 1]);

    if (2 === count($parts) && str_starts_with($parts[1], '^13')) {
        $version = '13.4.20';
    } elseif (2 === count($parts) && str_starts_with($parts[1], '^14')) {
        $version = '14.3.10';
    } else {
        $version = (string) ($parts[1] ?? '');
    }
}

if (str_starts_with($version, '^13')) {
    $version = '13.4.20';
} elseif (str_starts_with($version, '^14')) {
    $version = '14.3.10';
}

if ('' === $version) {
    fwrite(STDERR, 'unexpected composer arguments: ' . implode(' ', $arguments) . "\n");

    exit(1);
}

if (is_file($state . '/wrong-restore') && !str_contains(implode(' ', $arguments), 'typo3/cms-core:^')) {
    $version = '13.4.20';
}

/*
 * A vendor dir wiped by the fixer is recreated by a real composer
 * run; do the same so the installed.json write always succeeds.
 */
$composerDir = $root . '/.Build/vendor/composer';

if (!is_dir($composerDir)) {
    mkdir($composerDir, 0755, true);
}

file_put_contents($root . '/.Build/vendor/composer/installed.json', json_encode([
    'packages' => [
        ['name' => 'typo3/cms-core', 'version' => $version],
    ],
], JSON_PRETTY_PRINT));
mkdir($root . '/.Build/bin', 0755, true);
copy($state . '/fake-phpstan.php', $root . '/.Build/bin/phpstan');
chmod($root . '/.Build/bin/phpstan', 0755);
file_put_contents($root . '/.Build/bin/test-state', $state);
file_put_contents($state . '/manifests.log', json_encode(['manifest' => $manifest, 'root' => $root]) . "\n", FILE_APPEND);
