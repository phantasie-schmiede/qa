<?php

declare(strict_types=1);

namespace PSBits\Qa\PhpStan;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function array_diff;

/**
 * Checks that the @throws docblock of a method or function documents
 * every exception that is thrown on the active core (explicit throw
 * points, including transitive ones that are not caught).
 *
 * The docblock carries the union of the exceptions thrown on all
 * supported core majors: a surplus @throws line documents an exception
 * that a different core reports (a single-major line carries a trailing
 * "// t3:N" marker that is inert for the analyser), so only a missing
 * line is an error.
 *
 * The message is machine readable and consumed by the throws docblock
 * applier (bin/qa throws-fix).
 *
 * @see ThrowsDocblockRuleBase
 */
final class ThrowsDocblockRule extends ThrowsDocblockRuleBase
{
    public const string IDENTIFIER = 'psbits.throwsDocblock';

    /**
     * @param list<string> $thrown   object class names, without leading backslash
     * @param list<string> $declared object class names, without leading backslash
     * @return list<IdentifierRuleError>
     */
    protected function report(string $subject, array $thrown, array $declared): array
    {
        if ([] === array_diff($thrown, $declared)) {
            return [];
        }

        return [RuleErrorBuilder::message(self::message($subject, $thrown, $declared))->identifier(self::IDENTIFIER)->build()];
    }
}
