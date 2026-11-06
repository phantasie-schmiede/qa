---
name: psbits-cgl
description: PSBits Code Generation Language (CGL) - the code style rules that every PSBits extension must follow, with the psbits/qa tooling that enforces each rule (php-cs-fixer PSBits/* fixers, PHPStan psbits.throwsDocblock rule, the throws-fix cascade loop, the XLIFF trans-unit id sorter) and the conventions that are AI-side only (import conflict resolution, method parameter order). Use when writing or reviewing PHP code, docblocks, array keys, XLIFF language files or class structure in any PSBits extension, and when deciding how to resolve a lint or fix report.
---

# PSBits CGL (Code Generation Language)

The CGL is the shared code style of all PSBits extensions. Every rule below is
either enforced by the `psbits/qa` tooling (the repo's `lint:all` / `fix:all`) or
is an AI-side convention: when generating code, follow it, but do not try to
install tooling for it.

## The rules

### 1. Multi-line comments are one `/* ... */` block

Consecutive whole-line `//` or `#` comments are a single block comment:

```php
/*
 * Returns the configured provider.
 *
 * The provider is resolved lazily.
 */
```

- A single standalone `//` line and a trailing comment after code stay as they
  are; only *runs* of whole-line comments are converted.
- Tooling: `PSBits/block_comments` (php-cs-fixer) - `fix:php` converts,
  `lint:php` reports.

### 2. No empty comments

A comment without content does not exist: no empty docblocks, no empty block
comments, no bare `//` or `#` lines. This includes the empty shells that
`no_superfluous_phpdoc_tags` leaves behind when it drops the only tags of a
docblock (see 4) - the shell goes with the tags.

- Tooling: `PSBits/empty_comments` (php-cs-fixer).

### 3. `@throws` matches the thrown exceptions exactly

- The `@throws` list of a method or function is the **exact set** of exceptions
  that can escape it - no more, no less. Documenting a parent class to cover a
  child (or vice versa) is a violation; document the most specific class that
  is thrown.
- One `@throws` **per line**, sorted case-insensitively, no duplicates, no
  descriptions, always by **short name** with a `use` statement (see 8):

  ```php
  /**
   * @throws DomainException
   * @throws InvalidArgumentException
   */
  ```

- If nothing is thrown, there is **no `@throws` at all** - and therefore (see
  4) often no docblock at all.
- Abstract and interface methods have no body; their `@throws` is a contract
  and is not checked.
- Tooling: the `psbits.throwsDocblock` PHPStan rule (registered in the shared
  baseline, driven by `exceptions.implicitThrows: false`) reports every
  mismatch, and `composer fix:throws` (`.Build/bin/qa throws-fix`) rewrites the
  docblocks and repeats the analysis until nothing changed anymore - fixing a
  callee changes what its callers have to declare, so the fix cascades. The
  loop aborts with a message after 10 rounds; if it does, read the message and
  fix the reported methods by hand.
- The tooling writes the short name and adds the missing `use` statement to
  the file itself: an exception from the method's own namespace needs no
  import, an already imported one is reused, and a colliding short name gets a
  descriptive alias (last non-generic namespace segment + class name, e.g.
  `ConsoleInvalidArgumentException` - see 8). The output is complete house
  style on its own; `fix:all` still runs `fix:throws` before `fix:php`, which
  then only normalizes import order and whitespace.

### 4. `@param` / `@return` only carry what the type hints cannot

A docblock tag that only repeats the native parameter or return type is
removed. Keep the tag when it adds information (descriptions, generics,
`@return void` is gone because the type hint says it):

```php
/**
 * @param list<string> $ids the identifiers to resolve
 */
public function resolve(array $ids): array
{
}
```

- Tooling: `no_superfluous_phpdoc_tags` (php-cs-fixer).

### 5. Alphabetical order

- **Method parameters**: required parameters first, then optional parameters;
  within each group the names are sorted case-insensitively. AI-side only -
  no tool reorders method parameters (that would be a breaking change).
- **Array keys**: in every array literal whose keys are string literals, the
  keys are sorted case-insensitively. Tooling: `PSBits/alphabetical_array_keys`
  (php-cs-fixer). Arrays with dynamic keys or side-effect-carrying values are
  left alone by the tooling - do not reorder them by hand either.
- **JSON keys**: same order as array keys (JSON that lives in PHP files is an
  array; in standalone `.json` files apply the same rule when writing) - with
  two tool-managed exceptions: `composer.json` follows the canonical key order
  of `ergebnis/composer-normalize` (`lint:composer` / `fix:composer`), and
  `package.json` carries the Prettier format (2-space indentation, the key
  order as generated).
- **XLIFF `trans-unit` ids**: within each `<body>` the `<trans-unit>` elements
  are sorted by `id` (case-insensitive). Tooling: `composer fix:xlf`
  (`.Build/bin/qa xlf-fix`) rewrites, `composer lint:xlf`
  (`.Build/bin/qa xlf-check`) reports; the check is part of `lint:all`.

### 6. Constants are always typed

Class constants carry a type declaration when the value reveals it
unambiguously: scalar literals (int, float, string, bool, null) and array
literals (array); a statement with several constants is typed only when
they all share the type.

```php
final class Config
{
    private const int LIMIT = 10;

    private const string NAME = 'demo';
}
```

- Tooling: `PSBits/typed_constant` (php-cs-fixer).

### 7. Global functions use `use function` imports

Global-namespace functions are imported with a `use function` statement and
called by their short name; a call with a leading backslash (`\count(...)`)
does not occur anywhere in PSBits code:

```php
use function count;
use function in_array;

final class CleanupService
{
    public function isKnown(array $ids, string $id): bool
    {
        return 0 < count($ids) && in_array($id, $ids, true);
    }
}
```

- The fixer converts every backslash-prefixed call of a global function into
  the import + short name. It also adds the import for unqualified calls of
  the functions that are already imported somewhere in the psbits
  extensions (the performance-relevant set, maintained as
  `PERFORMANCE_FUNCTIONS` in the fixer) - when you import a new global
  function in an extension, add its name to that list. Every other
  unqualified call stays as it is: the house style does not import every
  global function.
- It leaves a call untouched when the file's namespace declares a local
  function of the same name or the name is already function-imported
  (importing the global one would be a compile error); resolve such a spot
  by hand.
- Tooling: `PSBits/function_imports` (php-cs-fixer).

### 8. Always use imports - and how to resolve a conflict

- Classes, functions and constants are referenced by short name with a `use`
  statement; no `\Vendor\Package\Class` inline. This holds for exception
  classes in `@throws` docblocks too - even global-namespace ones
  (`use JsonException;`), and for global functions (see 7).
- Tooling: `fully_qualified_strict_types` (`import_symbols: true`) plus
  `no_unused_imports` and `ordered_imports` (alpha; class, function, const
  groups).
- **Name conflict (AI-side only, no fixer/linter)**: when two different
  classes share a short name, import the **longer** FQN plain and give the
  **shorter** FQN a descriptive alias:

  ```php
  use PSBits\Storyteller\Domain\Model\Story;
  use TYPO3\CMS\Core\Domain\Model\Story as CoreStory;
  ```

  (Both conflict states are stable fixed points of the tooling, so `lint:php`
  never fights the alias - keep it for readability.)

### 9. Class element order

Members are ordered by visibility and staticness, names sorted
case-insensitively within each group:

1. `public static` methods
2. `protected static` methods
3. `private static` methods
4. `public` methods
5. `protected` methods
6. `private` methods

(`use` trait imports, `case` blocks, constants, properties and the
constructor follow the same principle ahead of the methods - see the
`ordered_class_elements` configuration in the shared fixer config.)

- Tooling: `ordered_class_elements` (php-cs-fixer) plus
  `PSBits/class_block_separation` (one blank line between methods, meta
  separation for constants/properties).

### 10. String concatenation has a space around the dot

A concatenation is written with exactly one space on each side of the
concatenation operator:

```php
$label = 'count: ' . count($ids);
```

- Tooling: `concat_space` (php-cs-fixer).

### 11. Class docblocks carry no boilerplate

The docblock of a class-like declaration carries only what the code
itself does not show. The declaration name - plain or as a `Class Name`,
`Enum Name`, `Interface Name` or `Trait Name` line - and every
`@package` tag are dropped; a docblock that holds nothing else
disappears together with its line, while a docblock with valuable
content (a real description, other tags like `@extends`) keeps that
content and loses only the boilerplate:

```php
/**
 * @extends RepositoryInterface<Song>
 */
final class SongRepository extends AbstractRepository
{
}
```

- Tooling: `PSBits/class_phpdoc_cleanup` (php-cs-fixer).

### 12. Always use `JSON_THROW_ON_ERROR` with `json_decode` / `json_encode`

`json_decode` and `json_encode` are called with the `JSON_THROW_ON_ERROR`
flag, so a malformed payload or an unencodable value fails with a
`JsonException` instead of a silent `false`:

```php
use function json_decode;
use function json_encode;

$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$raw  = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
```

- The flag is the last argument (`flags`) for `json_encode` and the fourth
  argument (`flags`) for `json_decode`; the other arguments (assoc, depth)
  stay as they are.
- A call with the flag can throw `JsonException` - declare it with
  `@throws JsonException` (see 3) and import it with `use JsonException;`
  (see 8).
- AI-side only - no fixer or PHPStan rule enforces the flag.

## The tooling

Everything lives in the `psbits/qa` package (`.Build/vendor/psbits/qa/`), not
in the repository (see the `psbits-qa` skill for the layout):

- `composer lint:all` - the QA gate: `analyze:php` (PHPStan with the shared
  baseline incl. the `psbits.throwsDocblock` rule), `lint:xlf`, the php-cs-fixer
  dry-run with all the `PSBits/*` fixers, yaml/typoscript/class linters.
- `composer fix:all` - applies the fixes in this order: `fix:classes`,
  `fix:composer` (the composer.json normalization), `fix:htmlxml`,
  `fix:json` (Prettier for the package.json), `fix:throws` (the @throws
  cascade loop, which writes short names + the missing `use` statements
  itself), `fix:xlf`, `fix:php` (the php-cs-fixer run; its
  `PSBits/empty_comments` drops the empty docblock shells and its
  `PSBits/function_imports` converts any backslash-prefixed global function
  call into a `use function` import).
- `composer fix:throws` / `composer lint:xlf` / `composer fix:xlf` - the
  individual new commands (`.Build/bin/qa throws-fix|check|fix`).
- After `fix:all`, always run `lint:all` and the test suites; a green
  `lint:all` is the definition of a CGL-compliant change.
