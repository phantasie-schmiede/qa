<?php

declare(strict_types=1);

// Renders the phpunit suite configurations into <repo>/.Build/phpunit/
// (git-ignored) with absolute paths, because the committed XMLs would
// resolve their relative bootstrap/cache/testsuite paths against the
// psbits/qa package instead of the repository when shared from vendor/.
//
// Usage:
//   php renderPhpunitXml.php [unit|functional|all]
//
// A suite is rendered when its Tests directory exists in the repository
// root (PSBITS_QA_REPO_ROOT or the current working directory).

$mode = $argv[1] ?? 'all';
if (!in_array($mode, ['unit', 'functional', 'all'], true)) {
    fwrite(STDERR, "Usage: php renderPhpunitXml.php [unit|functional|all]\n");
    exit(1);
}

$root = getenv('PSBITS_QA_REPO_ROOT') ?: getcwd();
$testingFramework = $root . '/.Build/vendor/typo3/testing-framework';
$bootstrapUnit = $testingFramework . '/Resources/Core/Build/UnitTestsBootstrap.php';
$bootstrapFunctional = $testingFramework . '/Resources/Core/Build/FunctionalTestsBootstrap.php';

$written = 0;

if ($mode === 'unit' || $mode === 'all') {
    if (is_dir($root . '/Tests/Unit')) {
        if (!is_file($bootstrapUnit)) {
            fwrite(STDERR, "error: unit bootstrap missing ({$bootstrapUnit}); run composer install first\n");
            exit(1);
        }
        writeXml($root, 'unit', $bootstrapUnit);
        $written++;
    }
}

if ($mode === 'functional' || $mode === 'all') {
    if (is_dir($root . '/Tests/Functional')) {
        if (!is_file($bootstrapFunctional)) {
            fwrite(STDERR, "error: functional bootstrap missing ({$bootstrapFunctional}); run composer install first\n");
            exit(1);
        }
        writeXml($root, 'functional', $bootstrapFunctional);
        $written++;
    }
}

if ($written === 0) {
    fwrite(STDERR, "error: no Tests/Unit or Tests/Functional found in {$root}; nothing rendered\n");
    exit(1);
}

function writeXml(string $root, string $suite, string $bootstrap): void
{
    $name = ucfirst($suite);
    $cache = $root . '/.Build/.phpunit.cache';
    $classes = $root . '/Classes';
    $tests = $root . '/Tests/' . $name;

    $header = $suite === 'unit'
        ? "Unit test suite setup."
        : "Functional test suite setup.";
    $note = "The bootstrap file is intentionally not copied into this repository. It is" . "\n"
        . "    provided by typo3/testing-framework and tracked there, so upgrading the" . "\n"
        . "    framework keeps the bootstrap in sync automatically.";
    if ($suite === 'functional') {
        $note .= "\n\n"
            . "    backupGlobals stays on, but beStrictAboutChangesToGlobalState is off: a" . "\n"
            . "    functional test boots a complete TYPO3 instance, which legitimately populates" . "\n"
            . "    \$GLOBALS['TYPO3_CONF_VARS'], the container and \$_SERVER. The testing-framework" . "\n"
            . "    default configuration does not enable the flag either.";
    }

    $phpBlock = '    <php>' . "\n"
        . '        <ini' . "\n"
        . '            name="display_errors"' . "\n"
        . '            value="1" />' . "\n";
    if ($suite === 'unit') {
        $phpBlock .= '        <ini' . "\n"
            . '            name="error_reporting"' . "\n"
            . '            value="-1" />' . "\n";
    }
    $phpBlock .= '        <env' . "\n"
        . '            name="TYPO3_CONTEXT"' . "\n"
        . '            value="Testing" />' . "\n";
    if ($suite === 'functional') {
        $phpBlock .= "        <!--\n"
            . "            PHPUnit only calls putenv() for <env> when the variable is not\n"
            . "            already set. Setting typo3DatabaseDriver in the shell therefore wins,\n"
            . "            e.g. typo3DatabaseDriver=pdo_mysql composer test:functional\n"
            . "        -->\n"
            . '        <env' . "\n"
            . '            name="typo3DatabaseDriver"' . "\n"
            . '            value="pdo_sqlite" />' . "\n";
    }
    $phpBlock .= '    </php>' . "\n";

    $xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . "<!--\n    {$header}\n\n    {$note}\n-->\n"
        . '<phpunit' . "\n"
        . '    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n"
        . '    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"' . "\n"
        . '    backupGlobals="true"' . "\n"
        . '    beStrictAboutChangesToGlobalState="false"' . "\n"
        . '    beStrictAboutOutputDuringTests="true"' . "\n"
        . '    bootstrap="' . esc($bootstrap) . '"' . "\n"
        . '    cacheDirectory="' . esc($cache) . '"' . "\n"
        . '    cacheResult="false"' . "\n"
        . '    colors="true"' . "\n"
        . '    displayDetailsOnTestsThatTriggerDeprecations="true"' . "\n"
        . '    displayDetailsOnTestsThatTriggerErrors="true"' . "\n"
        . '    displayDetailsOnTestsThatTriggerNotices="true"' . "\n"
        . '    displayDetailsOnTestsThatTriggerWarnings="true"' . "\n"
        . '    failOnDeprecation="true"' . "\n"
        . '    failOnEmptyTestSuite="true"' . "\n"
        . '    failOnNotice="true"' . "\n"
        . '    failOnRisky="true"' . "\n"
        . '    failOnWarning="true"' . "\n"
        . '    requireCoverageMetadata="false">' . "\n"
        . $phpBlock
        . '    <source' . "\n"
        . '        ignoreIndirectDeprecations="true"' . "\n"
        . '        restrictNotices="true"' . "\n"
        . '        restrictWarnings="true">' . "\n"
        . '        <include>' . "\n"
        . '            <directory>' . esc($classes) . '</directory>' . "\n"
        . '        </include>' . "\n"
        . '    </source>' . "\n"
        . '    <testsuites>' . "\n"
        . '        <testsuite name="' . $name . '">' . "\n"
        . '            <directory>' . esc($tests) . '</directory>' . "\n"
        . '        </testsuite>' . "\n"
        . '    </testsuites>' . "\n"
        . '</phpunit>' . "\n";

    $dir = $root . '/.Build/phpunit';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        fwrite(STDERR, "error: could not create {$dir}\n");
        exit(1);
    }
    file_put_contents($dir . '/' . $name . 'Tests.xml', $xml);
    echo "rendered {$name}Tests.xml\n";
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
