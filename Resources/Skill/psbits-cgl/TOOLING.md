# PSBits CGL - the tooling

How the `psbits/qa` tooling that enforces the CGL rules actually works.
The rules themselves live in `SKILL.md`.

Everything lives in the `psbits/qa` package (`.Build/vendor/psbits/qa/`),
not in the repository (see the `psbits-qa` skill for the layout).

## The commands

- `composer lint:all` - the QA gate: `analyze:php` (PHPStan with the
  shared baseline incl. the `psbits.throwsDocblock` rule and the
  marker-aware `psbits.throwsThrowable` rule, which takes the built-in
  `throws.notThrowable` check's place - that check sees the installed
  core only, while the docblock carries the union of all supported
  core majors), `lint:xlf`, `lint:php` (the shared phpTools runner: the
  php-cs-fixer dry-run with all the `PSBits/*` fixers plus
  PHP_CodeSniffer with the Slevomat control-structure spacing rule, see
  `SKILL.md`, 15), yaml/typoscript/class linters.
- `composer fix:all` - applies the fixes in this order: `fix:classes`,
  `fix:composer` (the composer.json normalization), `fix:htmlxml`,
  `fix:json` (Prettier for the package.json), `fix:throws` (the
  @throws full-matrix loop, which writes short names + the missing
  `use` statements itself), `fix:xlf`, `fix:php` (the shared phpTools
  runner: the PHP_CodeSniffer autofix for the Slevomat
  control-structure spacing rule - it adjusts the blank lines around
  block control structures only, no indentation - followed by the
  php-cs-fixer run, which normalizes the docblock and import layout the
  other fixers wrote; what its fixers do is documented in `SKILL.md`,
  2, 7, 8 and 13, and the Slevomat rule in 15).
- `composer fix:throws` / `composer lint:xlf` / `composer fix:xlf` - the
  individual commands (`.Build/bin/qa throws-fix|check|fix`).
- After `fix:all`, always run `lint:all` and the test suites; a green
  `lint:all` is the definition of a CGL-compliant change.

## Tool ownership: blank lines around control structures

The Slevomat rule (PHP_CodeSniffer, behind `lint:php` / `fix:php` via
the shared phpTools runner) owns the blank lines around block control
structures, `case` labels included; the php-cs-fixer configuration
therefore does not manage them: the `no_extra_blank_lines` tokens
`break`, `continue`, `return` and `throw` were removed on purpose - they
drop the blank line a closing jump statement leaves at the end of a
`case` body, exactly the spot where the Slevomat rule requires the
separation from the next `case`. With both tools active the results are
stable fixed points of each other (inside `fix:php` the PHP_CodeSniffer
autofix runs before the PHP-CS-Fixer run, whose `statement_indentation`
repairs any line the Slevomat fixer touched but does not re-indent). Do
not re-add the jump-statement tokens to `no_extra_blank_lines` - the
linters would fight forever.

## The throws-fix full matrix

`composer fix:throws` (`.Build/bin/qa throws-fix`) rewrites the
`@throws` docblocks against the full matrix of supported TYPO3 core
majors (the `typo3/cms-core` constraint in the repository's
composer.json). Every matrix entry gets a disposable repository copy
with its own Composer installation under
`.Build/throws-fix/<run-id>/<core>` (dependencies resolved fresh with
`--no-scripts`, the current QA code snapshotted into the copy) - the
repository's normal `vendor/bin` and Composer files stay untouched, so
no core switch and no restore step are needed. For every copy it runs
the PHPStan analysis (with the `psbits.throwsDocblockAudit` rule) in
an additive loop until the docblocks carry the core's thrown set -
fixing a callee changes what its callers have to declare, so the fix
cascades; the loop aborts with a message after 25 rounds per core. A
cross validation over all cores follows, with a repair loop when the
additions of one core changed the thrown set of another, and only when
every core is clean does the final merge write each docblock exactly
once: the union of the per-core sets, a `// t3:N` marker on the lines
a single core sees, and the lines no core sees removed (see
`SKILL.md`, 3).

Copies are refreshed after docblock edits; only copied source files
can be mapped back to the originals, relative path repositories are
mirrored, and source symlinks or external autoload paths are rejected
(custom bootstraps must work in the copy - this is not an OS security
sandbox). Do not edit source concurrently. Cleanup runs on completion
and on exceptions; a forced termination may leave disposable files
behind, but never a changed normal installation. The happy path costs
one composer resolution per matrix entry - run the command when
`@throws`-relevant code changed, not on every save.

The tooling writes the short name and adds the missing `use` statement
to the file itself: an exception from the method's own namespace needs
no import, an already imported one is reused, and a colliding short
name gets a descriptive alias (last non-generic namespace segment +
class name, e.g. `ConsoleInvalidArgumentException` - see the name
conflict rule in `SKILL.md`, 8). Every rewritten docblock follows the
house layout on its own (see `SKILL.md`, 13: description, one blank
star line, the tags sorted by name - `@todo` first, then alphabetical
- without blank lines in between); `fix:all` still runs `fix:throws`
before `fix:php`, which then only normalizes import order and
whitespace. The `use`
statements the merge orphans are left on purpose: `fix:php` drops
them with `no_unused_imports` (or `lint:php` reports them until then).

## The PERFORMANCE_FUNCTIONS list

The `PSBits/function_imports` fixer adds the `use function` import for
unqualified calls of the performance-relevant global functions,
maintained as `PERFORMANCE_FUNCTIONS` in
`src/PhpCsFixer/Fixer/FunctionImportsFixer.php` of `psbits/qa`. When
you import a new global function in an extension, add its name to that
list.
