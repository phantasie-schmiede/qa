---
name: psbits-cgl
description: PSBits Code Generation Language (CGL) - the house code style every PSBits extension follows. Use when writing or reviewing PHP code, docblocks, array keys, XLIFF files or class structure in a PSBits extension, or when resolving a lint or fix report.
---

# PSBits CGL (Code Generation Language)

The CGL is the shared code style of all PSBits extensions. Every rule is
either enforced by the `psbits/qa` tooling (the repo's `lint:all` /
`fix:all`) or is an AI-side convention: follow it when generating code,
but do not install tooling for it. How the tooling actually works (the
fix:all order, the throws-fix full matrix, the PERFORMANCE_FUNCTIONS
list) is in `TOOLING.md` in this skill.

## The rules

### 1. Multi-line comments are one `/* ... */` block

Consecutive whole-line `//` or `#` comments are a single block comment;
a single standalone `//` line and a trailing comment after code stay as
they are.
Tooling: `PSBits/block_comments` (php-cs-fixer).

### 2. No empty comments

No empty docblocks, no empty block comments, no bare `//` or `#` lines
- including the empty shells `no_superfluous_phpdoc_tags` leaves behind
when it drops the only tags of a docblock (see 4): the shell goes with
the tags.
Tooling: `PSBits/empty_comments` (php-cs-fixer).

### 3. `@throws` documents every supported core major

- The `@throws` list of a method or function is the **union** of the
  exceptions that can escape it on all supported TYPO3 core majors
  (v13.4 and v14.3): a missing line is an error, a surplus line is
  tolerated. Document the most specific class that is thrown -
  documenting a parent class to cover a child is no substitute for the
  child's own line.
- A line that only one major sees carries a trailing marker comment
  `// t3:13` or `// t3:14`; lines seen by every major are unmarked.
  The marker is documentation, not code: the analysers ignore it, the
  CS-Fixer keeps it.
- One `@throws` **per line**, sorted case-insensitively, no duplicates,
  no descriptions, always by **short name** with a `use` statement (see
  8).
- If nothing is thrown on any supported major, there is **no
  `@throws` at all** - and therefore (see 4) often no docblock at all.
- Abstract and interface methods have no body; their `@throws` is a
  contract and is not checked.
- Tooling: the `psbits.throwsDocblock` PHPStan rule (shared baseline,
  `exceptions.implicitThrows: false`) reports every missing line;
  `composer fix:throws` rewrites the docblocks against the full major
  matrix, writes the markers, and drops the lines no major sees (see
  `TOOLING.md`).

### 4. `@param` / `@return` only carry what the type hints cannot

A docblock tag that only repeats the native parameter or return type is
removed; keep the tag when it adds information (descriptions, generics).
Tooling: `no_superfluous_phpdoc_tags` (php-cs-fixer).

### 5. Alphabetical order

- **Method parameters** (AI-side only - no tool reorders method
  parameters, that would be a breaking change): required parameters
  first, then optional; within each group the names are sorted
  case-insensitively.
- **Array keys**: in every array literal whose keys are string
  literals, the keys are sorted case-insensitively. Tooling:
  `PSBits/alphabetical_array_keys` (php-cs-fixer). Arrays with dynamic
  keys or side-effect-carrying values are left alone by the tooling -
  do not reorder them by hand either. Two per-array exceptions are
  declared with a directive in a comment between the opening bracket
  and the first item (the directive applies to the marked array only,
  nested arrays keep the default):

  - `// PSBits/alphabetical_array_keys=off` - the array is excluded
    from the sort because the item order is part of the semantics
    (e.g. sorting configurations); the reason may follow the directive
    on the same line.
  - `// PSBits/alphabetical_array_keys=values` - the items are sorted
    by their values instead of the keys: the keys may then be string
    or integer literals, and every value must be a statically
    evaluable literal (numbers, strings, true, false, null, nested
    literal arrays). The order is the natural value order (null,
    false, true, numbers, strings, arrays; strings
    case-insensitive); equal values fall back to the key order.
- **JSON keys**: same order as array keys (JSON that lives in PHP files
  is an array; in standalone `.json` files apply the rule when writing)
  - with two tool-managed exceptions: `composer.json` follows the
  canonical key order of `ergebnis/composer-normalize`
  (`lint:composer` / `fix:composer`), and `package.json` carries the
  Prettier format (2-space indentation, the key order as generated).
- **XLIFF `trans-unit` and `group` ids**: within each `<body>` the
  `<trans-unit>` and `<group>` elements are sorted by their `id`
  attribute (case-insensitive); the `<trans-unit>` elements inside a
  `<group>` are sorted the same way (groups may be nested). Tooling:
  `composer lint:xlf` / `composer fix:xlf`; the check is part of
  `lint:all`.

### 6. Constants are always typed

Class constants carry a type declaration when the value reveals it
unambiguously: scalar literals (int, float, string, bool, null) and
array literals; a statement with several constants is typed only when
they all share the type.
Tooling: `PSBits/typed_constant` (php-cs-fixer).

### 7. Global functions use `use function` imports

Global-namespace functions are imported with a `use function` statement
and called by their short name; a call with a leading backslash
(`\count(...)`) does not occur anywhere in PSBits code.

- The fixer converts every backslash-prefixed call of a global function
  into the import + short name. It also adds the import for unqualified
  calls of the performance-relevant set (maintained as
  `PERFORMANCE_FUNCTIONS` in the fixer - see `TOOLING.md`). Every other
  unqualified call stays as it is: the house style does not import
  every global function.
- It leaves a call untouched when the file's namespace declares a
  local function of the same name or the name is already
  function-imported (importing the global one would be a compile
  error); resolve such a spot by hand.
- Tooling: `PSBits/function_imports` (php-cs-fixer).

### 8. Always use imports - and how to resolve a conflict

- Classes, functions and constants are referenced by short name with a
  `use` statement; no `\Vendor\Package\Class` inline. This holds for
  exception classes in `@throws` docblocks too - even global-namespace
  ones (`use JsonException;`) - and for global functions (see 7).
- **Name conflict (AI-side only, no fixer/linter)**: when two different
  classes share a short name, import the **longer** FQN plain and give
  the **shorter** FQN a descriptive alias:

  ```php
  use PSBits\Storyteller\Domain\Model\Story;
  use TYPO3\CMS\Core\Domain\Model\Story as CoreStory;
  ```

  (Both conflict states are stable fixed points of the tooling, so
  `lint:php` never fights the alias - keep it for readability.)
- Tooling: `fully_qualified_strict_types` (`import_symbols: true`) plus
  `no_unused_imports` and `ordered_imports` (alpha; class, function,
  const groups).

### 9. Class element order

Members are ordered by visibility and staticness, names sorted
case-insensitively within each group: `public static`, `protected
static`, `private static`, `public`, `protected`, `private` methods
(`use` trait imports, `case` blocks, constants, properties and the
constructor follow the same principle ahead of the methods - see the
`ordered_class_elements` configuration in the shared fixer config).
Tooling: `ordered_class_elements` plus `PSBits/class_block_separation`
(one blank line between methods, meta separation for constants/
properties).

### 10. String concatenation has a space around the dot

A concatenation is written with exactly one space on each side of the
concatenation operator: `$label = 'count: ' . count($ids);`
Tooling: `concat_space` (php-cs-fixer).

### 11. Class docblocks carry no boilerplate

The docblock of a class-like declaration carries only what the code
itself does not show: the declaration name - plain or as a `Class
Name`, `Enum Name`, `Interface Name` or `Trait Name` line - and every
`@package` tag are dropped; a docblock that holds nothing else
disappears together with its line, while a docblock with valuable
content (a real description, other tags like `@extends`) keeps that
content and loses only the boilerplate.
Tooling: `PSBits/class_phpdoc_cleanup` (php-cs-fixer).

### 12. Always use `JSON_THROW_ON_ERROR` with `json_decode` / `json_encode`

`json_decode` and `json_encode` are called with the `JSON_THROW_ON_ERROR`
flag, so a malformed payload or an unencodable value fails with a
`JsonException` instead of a silent `false`:

- The flag is the last argument (`flags`) for `json_encode` and the
  fourth argument (`flags`) for `json_decode`; the other arguments
  (assoc, depth) stay as they are.
- A call with the flag can throw `JsonException` - declare it with
  `@throws JsonException` (see 3) and import it with
  `use JsonException;` (see 8).
- AI-side only - no fixer or PHPStan rule enforces the flag.

### 13. Method docblocks are description, blank line, sorted tags

The docblock of a method or function carries the description first, then
one blank star line, then the tags as one block without blank lines in
between: the tags are sorted alphabetically by tag name (`@param`,
`@return`, `@see`, `@template`, `@throws`, `@var`), the `@param` lines
keep their parameter order, and the `@throws` lines their name-sorted
order (see 3). No blank star line stands directly after the opening
`/**` or directly before the closing `*/`, and a description line
never starts with `@` (the tooling would parse it as a tag).
Tooling: `composer fix:throws` rewrites the docblocks to this layout,
`phpdoc_order` and `phpdoc_separation` (php-cs-fixer) enforce it in
`lint:php` / `fix:php`, and `PSBits/empty_comments` drops the stray
blank star lines and the empty shells they leave behind.
