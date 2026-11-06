<?php

declare(strict_types=1);

/*
 * This file is part of PSBits QA.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Qa\PhpCsFixer\Fixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\NamespaceAnalysis;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\NamespaceUseAnalysis;
use PhpCsFixer\Tokenizer\Analyzer\NamespaceUsesAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Processor\ImportProcessor;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

use function array_keys;
use function in_array;

use const T_CLASS;
use const T_ENUM;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_PAAMAYIM_NEKUDOTAYIM;
use const T_STRING;
use const T_TRAIT;

/**
 * Replaces fully qualified calls of global functions (\count(...)) with
 * the plain short name and imports the function (use function count;).
 *
 * Unqualified calls of the global functions listed in
 * PERFORMANCE_FUNCTIONS (count(...)) get the import, too - the call
 * itself stays as it is.
 *
 * The import is skipped when the file declares a local function of the
 * same name in the same namespace or when the function is already
 * imported under an alias.
 *
 * The fixer works on the token list: the tokenizer normalises a fully
 * qualified name into one T_NS_SEPARATOR (\) plus one T_STRING per part,
 * so a global-namespace reference like \count is a separator that is not
 * preceded by a name token. The neighbors decide what it names:
 * preceded by T_NEW or a name token it is a class expression (new \Foo,
 * Foo::BAR), and followed by ( it starts a call. The leading backslashes
 * are emptied with clearAt(), which does not shift the other token
 * indices, and the missing use function imports are inserted by the
 * ImportProcessor right after the last use statement of the namespace
 * (or after the namespace declaration itself).
 */
final class FunctionImportsFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    /**
     * The global functions that carry a use function import somewhere in
     * the psbits extensions: the house style treats them as
     * performance-relevant, so an unqualified call of one of them gets
     * the import, too. When an extension imports a new global function,
     * add its name here.
     *
     * @var list<string>
     */
    private const array PERFORMANCE_FUNCTIONS = [
        'array_filter',
        'array_key_exists',
        'array_key_first',
        'array_key_last',
        'array_keys',
        'array_map',
        'array_merge',
        'array_replace_recursive',
        'array_search',
        'array_slice',
        'array_unique',
        'array_values',
        'assert',
        'basename',
        'chmod',
        'class_exists',
        'clearstatcache',
        'constant',
        'count',
        'define',
        'defined',
        'dirname',
        'explode',
        'file_get_contents',
        'file_put_contents',
        'function_exists',
        'get_class',
        'gettype',
        'glob',
        'implode',
        'in_array',
        'is_a',
        'is_array',
        'is_bool',
        'is_dir',
        'is_file',
        'is_float',
        'is_int',
        'is_object',
        'is_resource',
        'is_scalar',
        'is_string',
        'json_decode',
        'json_encode',
        'ksort',
        'lcfirst',
        'ltrim',
        'max',
        'mb_strtolower',
        'method_exists',
        'mkdir',
        'ob_get_clean',
        'ob_start',
        'pathinfo',
        'rmdir',
        'sort',
        'sprintf',
        'strcmp',
        'str_contains',
        'str_ends_with',
        'strlen',
        'str_repeat',
        'str_replace',
        'strrpos',
        'str_starts_with',
        'strtolower',
        'substr',
        'substr_count',
        'sys_get_temp_dir',
        'trim',
        'uniqid',
        'unlink',
        'usort',
    ];

    /**
     * Checks whether the namespace declares its own function with the given
     * name; adding a use function import for it would be a compile error.
     *
     * The namespace scope is walked token by token with a stack of the
     * open braces; only a T_FUNCTION at class depth 0 counts, so methods
     * and anonymous functions never shadow the global function.
     */
    private static function declaresLocalFunction(Tokens $tokens, NamespaceAnalysis $namespace, string $name): bool
    {
        $classyDepth = 0;
        $braces      = [];

        for ($index = $namespace->getScopeStartIndex(); $index <= $namespace->getScopeEndIndex(); ++$index) {
            $token = $tokens[$index];

            if ($token->equals('{')) {
                $classy   = self::isClassyOpener($tokens, $index);
                $braces[] = $classy;

                if (true === $classy) {
                    ++$classyDepth;
                }
            } elseif ($token->equals('}')) {
                if (true === array_pop($braces)) {
                    --$classyDepth;
                }
            } elseif ($token->isGivenKind(T_FUNCTION) && 0 === $classyDepth) {
                $next = $tokens->getNextMeaningfulToken($index);

                if (null !== $next && $tokens[$next]->isGivenKind(T_STRING) && $name === $tokens[$next]->getContent()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the brace at $braceIndex opens the body of a class,
     * interface, trait or enum (including anonymous classes) as opposed
     * to a function body or a control structure.
     */
    private static function isClassyOpener(Tokens $tokens, int $braceIndex): bool
    {
        $index = $tokens->getPrevMeaningfulToken($braceIndex);

        /*
         * Skip the class name or the return type between the keyword and
         * the brace (class Foo {, enum Suit: string {, function demo():
         * int {).
         */
        while (
            null !== $index
            && (
                $tokens[$index]->isGivenKind([T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])
                || $tokens[$index]->isGivenKind(CT::T_TYPE_COLON)
                || $tokens[$index]->isGivenKind(CT::T_NULLABLE_TYPE)
                || $tokens[$index]->isGivenKind(CT::T_TYPE_ALTERNATION)
                || $tokens[$index]->isGivenKind(CT::T_TYPE_INTERSECTION)
                || $tokens[$index]->equals(',')
            )
        ) {
            $index = $tokens->getPrevMeaningfulToken($index);
        }

        if (null !== $index && $tokens[$index]->isGivenKind([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_EXTENDS, T_IMPLEMENTS, T_NEW])) {
            return true;
        }

        /*
         * A brace opened by a closing parenthesis is a class body only
         * for an anonymous class (new class(...) {, new \Foo(...) {).
         */
        if (null !== $index && $tokens[$index]->equals(')')) {
            $open   = $tokens->findBlockStart(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
            $before = null !== $open ? $tokens->getPrevMeaningfulToken($open) : null;

            return null !== $before && $tokens[$before]->isGivenKind([T_CLASS, T_NEW]);
        }

        return false;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Replaces \function(...) calls of global functions with the short name and a use function import.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php

                        namespace App;

                        function demo(): int
                        {
                            return \count(['a', 'b']);
                        }

                        PHP
                ),
            ],
        );
    }

    public function getName(): string
    {
        return 'PSBits/function_imports';
    }

    /**
     * {@inheritdoc}
     *
     * Must run before OrderedImportsFixer, so the added imports are
     * ordered in the same pass, and before NoUnusedImportsFixer.
     */
    public function getPriority(): int
    {
        return 30;
    }

    /**
     * Cheap pre-check on the token kinds: a file without any name token
     * has no function call this fixer can touch.
     */
    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_STRING);
    }

    /**
     * Per namespace: collects the global function calls, empties their
     * leading backslashes (the name tokens stay - they just become short
     * names), and inserts the missing use function imports. The
     * unqualified calls of PERFORMANCE_FUNCTIONS keep their short name,
     * they only get the import.
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $useAnalyzer = new NamespaceUsesAnalyzer();

        foreach ($tokens->getNamespaceDeclarations() as $namespace) {
            if ('' === $namespace->getFullName()) {
                // importing a global function in the global namespace is pointless
                continue;
            }

            $calls      = $this->findFunctionCalls($tokens, $namespace);
            $shortCalls = $this->findUnqualifiedCalls($tokens, $namespace);
            $uses       = $useAnalyzer->getDeclarationsInNamespace($tokens, $namespace, true);
            $imports    = [];
            $lastUse    = null;

            foreach ($uses as $use) {
                $lastUse = $use;
            }

            foreach ($calls as $name) {
                if ($this->isAlreadyImported($uses, $name)) {
                    continue;
                }

                $imports[$name] = true;
            }

            foreach ($shortCalls as $name) {
                if (isset($imports[$name]) || $this->isAlreadyImported($uses, $name)) {
                    continue;
                }

                $imports[$name] = true;
            }

            foreach (array_keys($calls) as $index) {
                $tokens->clearAt($index);
            }

            if ([] === $imports) {
                continue;
            }

            $atIndex = null;

            if (null !== $lastUse) {
                $atIndex = $lastUse->getEndIndex() + 1;
            } elseif (0 !== $namespace->getEndIndex()) {
                $atIndex = $namespace->getEndIndex() + 1;
            }

            if (null !== $atIndex) {
                (new ImportProcessor($this->whitespacesConfig))->insertImports($tokens, ['function' => array_keys($imports)], $atIndex);
            }
        }
    }

    /**
     * Collects the leading backslashes of global function calls
     * (\count(...)). The php-cs-fixer tokenizer normalises a fully
     * qualified name into a T_NS_SEPARATOR plus one T_STRING per
     * segment, so a leading backslash is a separator that is not
     * preceded by a name part. The neighbours exclude the other uses of
     * a leading separator: preceded by T_NEW or a name token it names a
     * class (new \Foo, Foo::BAR), and one not followed by ( is no call.
     *
     * @return array<int, string> token index of the backslash => function name
     */
    private function findFunctionCalls(Tokens $tokens, NamespaceAnalysis $namespace): array
    {
        $calls = [];

        for ($index = $namespace->getScopeStartIndex(); $index <= $namespace->getScopeEndIndex(); ++$index) {
            if (!$tokens[$index]->isGivenKind(T_NS_SEPARATOR)) {
                continue;
            }

            $nameIndex = $index + 1;

            if ($nameIndex > $namespace->getScopeEndIndex() || !$tokens[$nameIndex]->isGivenKind(T_STRING)) {
                continue;
            }

            $prev = $tokens->getPrevMeaningfulToken($index);

            if (null !== $prev && ($tokens[$prev]->isGivenKind(T_NEW) || $tokens[$prev]->isGivenKind(T_STRING))) {
                continue;
            }

            $next = $tokens->getNextMeaningfulToken($nameIndex);

            if (null === $next || !$tokens[$next]->equals('(')) {
                continue;
            }

            $name = $tokens[$nameIndex]->getContent();

            if (self::declaresLocalFunction($tokens, $namespace, $name)) {
                continue;
            }

            $calls[$index] = $name;
        }

        return $calls;
    }

    /**
     * Collects the unqualified calls of the functions from
     * PERFORMANCE_FUNCTIONS (count(...)). The neighbors exclude the
     * other uses of a short name: preceded by T_NEW, T_FUNCTION, an
     * object or scope operator, or a namespace separator it names a
     * class or a member (new count(), function count(), $a->count(),
     * NS\count()), and one not followed by ( is no call.
     *
     * @return array<int, string> token index of the name => function name
     */
    private function findUnqualifiedCalls(Tokens $tokens, NamespaceAnalysis $namespace): array
    {
        $calls = [];

        for ($index = $namespace->getScopeStartIndex(); $index <= $namespace->getScopeEndIndex(); ++$index) {
            $token = $tokens[$index];

            if (!$token->isGivenKind(T_STRING) || !in_array($token->getContent(), self::PERFORMANCE_FUNCTIONS, true)) {
                continue;
            }

            $next = $tokens->getNextMeaningfulToken($index);

            if (null === $next || !$tokens[$next]->equals('(')) {
                continue;
            }

            $prev = $tokens->getPrevMeaningfulToken($index);

            if (
                null !== $prev
                && $tokens[$prev]->isGivenKind([T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_PAAMAYIM_NEKUDOTAYIM, T_NS_SEPARATOR])
            ) {
                continue;
            }

            $name = $token->getContent();

            if (self::declaresLocalFunction($tokens, $namespace, $name)) {
                continue;
            }

            $calls[$index] = $name;
        }

        return $calls;
    }

    /**
     * @param list<NamespaceUseAnalysis> $uses
     */
    private function isAlreadyImported(array $uses, string $name): bool
    {
        foreach ($uses as $use) {
            if ($use->isFunction() && $name === $use->getShortName()) {
                return true;
            }
        }

        return false;
    }
}
