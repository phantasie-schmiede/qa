<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PSBits\Qa\PhpCsFixer\Fixer\ClassBlockSeparationFixer;
use PSBits\Qa\PhpCsFixer\Fixer\PropertyNamesAlignmentFixer;

/**
 * Builds the shared php-cs-fixer configuration for a psbits extension.
 *
 * The repository keeps a thin Build/Quality/.php-cs-fixer.dist.php shim that
 * calls this factory with its analysis scope - the directories differ
 * between repositories (the Tests directory is style-checked only where the
 * repo opts in).
 */
final class CsFixerFactory
{
    /**
     * @param list<string> $directories repository-relative directories to analyse
     * @param list<string> $excludes    repository-relative directories to skip
     *                                   (e.g. test fixtures that hold expected
     *                                   output data instead of code to lint)
     */
    public static function create(array $directories, array $excludes = []): Config
    {
        $root = getcwd();
        $paths = array_values(array_diff($directories, $excludes));
        $paths = array_filter(
            $paths,
            static fn (string $directory): bool => is_dir($root . '/' . $directory),
        );

        // notPath() patterns are relative to the in() directories, so an
        // exclusion is expressed relative to the scope directory it lives in.
        $finder = Finder::create()
            ->in(array_map(static fn (string $directory): string => $root . '/' . $directory, $paths))
            ->name('*.php');

        foreach ($paths as $directory) {
            foreach ($excludes as $exclude) {
                $prefix = $directory . '/';
                if (str_starts_with($exclude, $prefix)) {
                    $relative = substr($exclude, strlen($prefix));
                    $finder = $finder->notPath('#^' . preg_quote($relative, '#') . '/#');
                }
            }
        }

        return (new Config())->setRiskyAllowed(true)
            ->registerCustomFixers([
                new ClassBlockSeparationFixer(),
                new PropertyNamesAlignmentFixer(),
            ])
            ->setRules([
                '@PSR12'                          => true,
                'attribute_empty_parentheses'     => true,
                'binary_operator_spaces'          => [
                    'operators' => [
                        '.='  => 'align_single_space_minimal',
                        '='   => 'align_single_space_minimal',
                        '=>'  => 'align_by_scope',
                        '??=' => 'align_single_space_minimal',
                    ],
                ],
                'blank_line_before_statement'     => [
                    'statements' => [
                        'break',
                        'continue',
                        'declare',
                        'do',
                        'exit',
                        'for',
                        'foreach',
                        'goto',
                        'if',
                        'return',
                        'switch',
                        'throw',
                        'try',
                        'while',
                    ],
                ],
                'declare_strict_types'            => true,
                'function_declaration'            => [
                    'closure_fn_spacing'       => 'none',
                    'closure_function_spacing' => 'none',
                ],
                'method_argument_space'           => [
                    'after_heredoc' => false,
                    'on_multiline'  => 'ensure_fully_multiline',
                ],
                'no_extra_blank_lines'            => [
                    'tokens' => [
                        'attribute',
                        'break',
                        'case',
                        'comma',
                        'continue',
                        'curly_brace_block',
                        'default',
                        'extra',
                        'parenthesis_brace_block',
                        'return',
                        'square_brace_block',
                        'switch',
                        'throw',
                        'use',
                    ],
                ],
                'no_trailing_whitespace'          => true,
                'no_unused_imports'               => true,
                'no_unneeded_control_parentheses' => true,
                'ordered_imports'                 => [
                    'imports_order'  => [
                        'class',
                        'function',
                        'const',
                    ],
                    'sort_algorithm' => 'alpha',
                ],
                'phpdoc_align'                    => [
                    'align' => 'vertical',
                    'tags'  => ['param'],
                ],
                'PSBits/class_block_separation'   => [
                    'elements' => [
                        'case'         => 'none',
                        'const'        => 'only_if_meta',
                        'method'       => 'one',
                        'property'     => 'only_if_meta',
                        'trait_import' => 'only_if_meta',
                    ],
                ],
                'PSBits/property_names_alignment' => true,
                'single_quote'                    => true,
                'single_space_around_construct'   => [
                    'constructs_followed_by_a_single_space' => ['named_argument'],
                ],
                'spaces_inside_parentheses'       => false,
                'trailing_comma_in_multiline'     => [
                    'elements' => [
                        'arrays',
                    ],
                ],
                'yoda_style'                      => [
                    'equal'            => true,
                    'identical'        => true,
                    'less_and_greater' => true,
                ],
            ])
            ->setFinder($finder);
    }
}
