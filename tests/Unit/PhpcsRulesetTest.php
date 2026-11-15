<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Ruleset;
use PHP_CodeSniffer\Util\Tokens;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/*
 * PHP_CodeSniffer ships its own autoloader (its composer package has no
 * autoload section), the tests register it before the Config and Ruleset
 * classes are loaded. The Runner entry point defines the execution
 * constants and loads the token polyfills (Util\Tokens) when the
 * binaries run; direct API use reproduces both here.
 */
if (false === defined('PHP_CODESNIFFER_VERBOSITY')) {
    define('PHP_CODESNIFFER_VERBOSITY', 0);
}

if (false === defined('PHP_CODESNIFFER_CBF')) {
    define('PHP_CODESNIFFER_CBF', false);
}

require_once __DIR__ . '/../../vendor/squizlabs/php_codesniffer/autoload.php';

/* Loads the token constants the sniffs register against. */
Tokens::tokenName(T_WHITESPACE);

final class PhpcsRulesetTest extends TestCase
{
    /**
     * @throws ExpectationFailedException
     * @throws RuntimeException
     */
    public function testTheSharedRulesetCarriesOnlyTheSlevomatBlockControlStructureSpacingSniff(): void
    {
        $config  = new Config(['--standard=' . __DIR__ . '/../../Resources/Quality/phpcs.xml']);
        $ruleset = new Ruleset($config);

        self::assertSame(['SlevomatCodingStandard\Sniffs\ControlStructures\BlockControlStructureSpacingSniff'], array_keys($ruleset->sniffs));
    }
}
