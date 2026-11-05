<?php

declare(strict_types=1);

use PhpCsFixer\AbstractFixer;
use PHPUnit\Framework\TestCase;
use PSBits\Qa\PhpCsFixer\Fixer\AlphabeticalArrayKeysFixer;
use PSBits\Qa\PhpCsFixer\Fixer\BlockCommentsFixer;
use PSBits\Qa\PhpCsFixer\Fixer\ClassBlockSeparationFixer;
use PSBits\Qa\PhpCsFixer\Fixer\EmptyCommentsFixer;
use PSBits\Qa\PhpCsFixer\Fixer\FunctionImportsFixer;
use PSBits\Qa\PhpCsFixer\Fixer\PropertyNamesAlignmentFixer;
use PSBits\Qa\PhpCsFixer\Fixer\TypedConstantFixer;

final class FixerNamesTest extends TestCase
{
    public function testFixerDefinitionsAreComplete(): void
    {
        foreach ($this->fixers() as $fixer) {
            $definition = $fixer->getDefinition();

            self::assertNotSame('', $definition->getSummary(), get_class($fixer));
            self::assertNotEmpty($definition->getCodeSamples(), get_class($fixer));
        }
    }

    public function testFixerRuleNamesAreStableAcrossNamespaces(): void
    {
        $expected = [
            AlphabeticalArrayKeysFixer::class        => 'PSBits/alphabetical_array_keys',
            BlockCommentsFixer::class                => 'PSBits/block_comments',
            ClassBlockSeparationFixer::class         => 'PSBits/class_block_separation',
            EmptyCommentsFixer::class                => 'PSBits/empty_comments',
            FunctionImportsFixer::class              => 'PSBits/function_imports',
            PropertyNamesAlignmentFixer::class       => 'PSBits/property_names_alignment',
            TypedConstantFixer::class                => 'PSBits/typed_constant',
        ];
        $actual = [];

        foreach ($this->fixers() as $fixer) {
            $actual[get_class($fixer)] = $fixer->getName();
        }

        self::assertSame($expected, $actual);
    }

    /**
     * @return list<AbstractFixer>
     */
    private function fixers(): array
    {
        return [
            new AlphabeticalArrayKeysFixer(),
            new BlockCommentsFixer(),
            new ClassBlockSeparationFixer(),
            new EmptyCommentsFixer(),
            new FunctionImportsFixer(),
            new PropertyNamesAlignmentFixer(),
            new TypedConstantFixer(),
        ];
    }
}
