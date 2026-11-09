<?php

declare(strict_types=1);

namespace PSBits\Qa\PhpStan;

use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports every method or function that throws exceptions or documents
 * them in the docblock, whatever the match, so that the throws fixer
 * can read off the complete thrown set per core.
 *
 * The rule is only registered in the temporary phpstan configuration
 * the fixer creates for its run; it must never end up in a lint
 * configuration, where its unconditional reports would fail the gate.
 *
 * @see ThrowsDocblockRuleBase
 */
final class ThrowsDocblockAuditRule extends ThrowsDocblockRuleBase
{
    public const string IDENTIFIER = 'psbits.throwsDocblockAudit';

    /**
     * @param list<string> $thrown   object class names, without leading backslash
     * @param list<string> $declared object class names, without leading backslash
     * @return list<IdentifierRuleError>
     */
    protected function report(string $subject, array $thrown, array $declared): array
    {
        if ([] === $thrown && [] === $declared) {
            return [];
        }

        return [RuleErrorBuilder::message(self::message($subject, $thrown, $declared))->identifier(self::IDENTIFIER)->build()];
    }
}
