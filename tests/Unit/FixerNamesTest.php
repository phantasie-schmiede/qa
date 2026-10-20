<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\ClassBlockSeparationFixer;
use PSBits\Qa\PhpCsFixer\Fixer\PropertyNamesAlignmentFixer;

final class FixerNamesTest extends TestCase
{
    public function testFixerRuleNamesAreStableAcrossNamespaces(): void
    {
        self::assertSame('PSBits/class_block_separation', (new ClassBlockSeparationFixer())->getName());
        self::assertSame('PSBits/property_names_alignment', (new PropertyNamesAlignmentFixer())->getName());
    }

    public function testFixerDefinitionsAreComplete(): void
    {
        foreach ([new ClassBlockSeparationFixer(), new PropertyNamesAlignmentFixer()] as $fixer) {
            $definition = $fixer->getDefinition();
            self::assertNotSame('', $definition->getSummary());
            self::assertNotEmpty($definition->getCodeSamples());
        }
    }
}
