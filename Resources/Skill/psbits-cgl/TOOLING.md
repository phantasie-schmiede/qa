# PSBits CGL - the tooling

How the `psbits/qa` tooling that enforces the CGL rules actually works.
The rules themselves live in `SKILL.md`.

Everything lives in the `psbits/qa` package (`.Build/vendor/psbits/qa/`),
not in the repository (see the `psbits-qa` skill for the layout).

## The commands

- `composer lint:all` - the QA gate: `analyze:php` (PHPStan with the
  shared baseline incl. the `psbits.throwsDocblock` rule), `lint:xlf`,
  the php-cs-fixer dry-run with all the `PSBits/*` fixers, yaml/
  typoscript/class linters.
- `composer fix:all` - applies the fixes in this order: `fix:classes`,
  `fix:composer` (the composer.json normalization), `fix:htmlxml`,
  `fix:json` (Prettier for the package.json), `fix:throws` (the @throws
  cascade loop, which writes short names + the missing `use` statements
  itself), `fix:xlf`, `fix:php` (the php-cs-fixer run; its
  `phpdoc_order` and `phpdoc_separation` sort the docblock tags and drop
  the blank lines between them, its `PSBits/empty_comments` drops the
  stray blank star lines and the empty docblock shells, and its
  `PSBits/function_imports` converts any backslash-prefixed global
  function call into a `use function` import).
- `composer fix:throws` / `composer lint:xlf` / `composer fix:xlf` - the
  individual commands (`.Build/bin/qa throws-fix|check|fix`).
- After `fix:all`, always run `lint:all` and the test suites; a green
  `lint:all` is the definition of a CGL-compliant change.

## The throws-fix cascade

`composer fix:throws` (`.Build/bin/qa throws-fix`) rewrites the
`@throws` docblocks and repeats the PHPStan analysis until nothing
changed anymore - fixing a callee changes what its callers have to
declare, so the fix cascades. The loop aborts with a message after 10
rounds; if it does, read the message and fix the reported methods by
hand.

The tooling writes the short name and adds the missing `use` statement
to the file itself: an exception from the method's own namespace needs
no import, an already imported one is reused, and a colliding short
name gets a descriptive alias (last non-generic namespace segment +
class name, e.g. `ConsoleInvalidArgumentException` - see the name
conflict rule in `SKILL.md`, 8). Every rewritten docblock follows the
house layout on its own (see `SKILL.md`, 13: description, one blank
star line, the tags sorted alphabetically without blank lines in
between); `fix:all` still runs `fix:throws` before `fix:php`, which
then only normalizes import order and whitespace.

## The PERFORMANCE_FUNCTIONS list

The `PSBits/function_imports` fixer adds the `use function` import for
unqualified calls of the performance-relevant global functions,
maintained as `PERFORMANCE_FUNCTIONS` in
`src/PhpCsFixer/Fixer/FunctionImportsFixer.php` of `psbits/qa`. When
you import a new global function in an extension, add its name to that
list.
