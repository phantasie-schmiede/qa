---
name: psbits-qa
description: QA and verification protocol for PSBits TYPO3 extension repositories: composer validate, PHPStan (analyze:php), php-cs-fixer, yamllint, unit and functional tests, the v13.4/v14.3 test matrix (runTests.sh, runLocalTests.sh, runDockerTests.sh), .Build core switching, coverage baselines. Use when running or planning tests, static analysis, code style checks, or any verification round for a PSBits extension.
---

# PSBits QA Protocol

## Mandatory verification block

After any code change, run these steps (skip an individual step only when the repo does not
have it):

1. `composer validate --no-check-publish`
2. `php -l` for every changed PHP file
3. `composer analyze:php` (PHPStan) - **never skip**, even for docblock-only changes
4. `composer lint:php -- <changed files>` (the shared phpTools runner:
   the PHP-CS-Fixer dry-run plus PHP_CodeSniffer with the Slevomat
   control-structure spacing rule; a CLI path replaces the configured
   scope of both tools)
5. `composer lint:yaml` (or `yamllint`) for changed YAML files
6. `composer test:unit` and `composer test:functional` when the repo defines them

A fully green block is the definition of done for a change.

## Shared infrastructure (`psbits/qa`)

The QA tooling lives in the `psbits/qa` package (installed into
`.Build/vendor/psbits/qa/`), not in the repository:

- Linter/fixer configuration: `.Build/vendor/psbits/qa/Resources/Quality/`
  (cs-fixer config with the shared `PSBits/*` fixers, the `phpTools.php`
  runner behind `lint:php`/`fix:php`, the PHP_CodeSniffer ruleset
  `phpcs.xml` with the Slevomat control-structure spacing rule,
  phpstan baseline, prettier, yamllint, typoscript-lint,
  lint-class-attributes)
- Test matrix scripts: `.Build/vendor/psbits/qa/Resources/Testing/Scripts/`
- Docker setup: `.Build/vendor/psbits/qa/Resources/Testing/Docker/`
- PHPUnit XMLs are **generated** into `.Build/phpunit/` (git-ignored) before
  every test run by `renderPhpunitXml.php`; no XML is committed to the repo
- The skill files you are reading (this one, `psbits-cgl`,
  `psbits-foundation` and `psbits-v14-compat`), the git pre-commit hook, the
  caller CI workflow (`.github/workflows/ci.yml`) and the caller
  `composer.json` scripts are installed/synced by `bin/qa sync` (composer
  post-install/update hook); `bin/qa check` verifies all of them (CI gate,
  run by the shared qa job)
- The caller CI workflow is a template-managed file: it invokes the shared
  test-matrix workflow `phantasie-schmiede/qa/.github/workflows/extension-ci.yml`
  (single source of truth for the whole CI - fix it there, not per repo)
- Run `bin/qa paths` to list the shared resource locations
- Upgrade the tooling with `composer update psbits/qa`
- `qa sync --dry-run` previews all changes without writing files. Conflicts
  abort before any writes; review them before using `qa sync --force` to
  explicitly replace foreign hooks, workflows or skill directories/links.
  Both flags can be combined. Identical templates, marked package-managed
  files and the exact legacy PSBits hook are updated normally.
- Hook paths are resolved by Git (worktrees and `core.hooksPath` supported).
  Without repository-local Git metadata the hook is skipped. `qa check`
  checks executable permissions too; `qa sync` repairs missing permissions.

## Shared QA scripts (synced by `bin/qa sync`)

QA supplies Composer Normalize, PHPStan, TypoScript lint, PHPUnit, the testing
framework and `saschaegerer/phpstan-typo3` as regular dependencies, alongside
the PHP style tools. No duplicate root declarations are required.
`qa sync` (including `--dry-run`) and `qa check` warn about existing root
`require-dev` pins for `saschaegerer/phpstan-typo3` that do not intersect each branch
needed by the caller's TYPO3 constraint (`^2.1` for v13, `^3.1` for v14).
Equivalent and narrower intersecting constraints are accepted via Composer
Semver. This checks declared coverage only; matrix dependency resolution remains
necessary. Tools supplied by `psbits/qa` need no duplicate root requirement.
These commands also warn about redundant `require-dev` entries for direct
package dependencies read from `psbits/qa`'s own `composer.json`. Removal is
recommended only when the QA constraint is a subset of the caller constraint;
narrower caller pins, platform requirements and transitive-only dependencies
are excluded. Entries are never removed automatically.
Sync also adds missing root `allow-plugins` authorizations for Composer Normalize
and the PHP_CodeSniffer Composer installer, preserving explicit disables. Check
fails when a required authorization is missing or disabled.

The caller `composer.json` `scripts` section is part of the package-managed
state. `bin/qa sync` adds the full set of shared scripts when missing, each
with its own applicability condition, so only scripts that fit the
repository are added:

- `analyze:php` (PHPStan, needs `Build/Quality/phpstan.neon.dist`)
- `audit:composer`, `validate:composer` (always)
- `docker:images` (when tests exist or `test:docker` already exists; prepares
  the extension's test images using `buildTestImages.sh`)
- `lint:composer` / `fix:composer` (always; the `ergebnis/composer-normalize`
  plugin normalizes the `composer.json` key order and formatting)
- `lint:php` / `fix:php` (the shared phpTools runner: PHP-CS-Fixer and
  PHP_CodeSniffer with the shared Slevomat control-structure spacing
  ruleset; needs `Build/Quality/.php-cs-fixer.dist.php` and/or
  `Build/Quality/.phpcs.xml.dist` - a missing tool is skipped, the CLI
  paths are passed to every tool that runs, and `fix:php` runs the
  PHP_CodeSniffer autofix before the PHP-CS-Fixer run; the
  `dealerdirect/phpcodesniffer-composer-installer` plugin - required by
  the Slevomat dependency - registers the standard with PHP_CodeSniffer
  on every composer install/update, and `qa sync` keeps the matching
  `config.allow-plugins` entry in the caller `composer.json` (added when
  missing, reported without modification when explicitly disabled,
  verified by `qa check`))
- `lint:classes` / `fix:classes`, `lint:htmlxml` / `fix:htmlxml`,
  `lint:json` / `fix:json`, `lint:js`, `lint:scss` (npm proxies, need the
  matching npm script in the repository `package.json`; the json pair runs
  Prettier on `package.json`)
- `lint:typoscript` (needs at least one `.typoscript` file below
  `Configuration/TypoScript`)
- `lint:yaml` (needs the `Configuration` directory)
- `lint:xlf` / `fix:xlf` (check/fix the XLIFF trans-unit and group id
  order, need `*.xlf` files) and `fix:throws` (rewrite the `@throws`
  docblocks
  against the full matrix of supported core majors: the tooling
  creates isolated Composer installations per matrix entry, converges the PHPStan
  audit loop per major, then merges the per-major sets into one
  docblock with `// t3:N` markers on the major-specific lines, needs
  the PHPStan configuration and network access for the core switches)

`fix:throws` uses disposable repository copies and Composer installations under
`.Build/throws-fix/<run-id>/<core>`. Normal vendor/bin and Composer files remain
unchanged; no restore is needed. Every core resolves fresh dependencies with
`--no-scripts` and uses a snapshot of the current QA code. Copies are refreshed
after docblock edits; only copied source files can be mapped back to originals.
Relative path repositories are mirrored. Source symlinks and external autoload
paths are rejected. Custom bootstraps must work in the copy; this is not an OS
security sandbox. Do not edit source concurrently. Cleanup runs on completion
and exceptions; failures fail successful runs without hiding analysis errors.
Forced termination may leave disposable files, not a changed normal installation.

`bin/qa sync` detects `*Test.php` recursively in `Tests/Unit` and
`Tests/Functional` and adds the corresponding `test:unit` / `test:functional`.
If either suite exists, it adds `test:local`, `test:docker`, `test:coverage`,
`test:coverage:update-baseline` and `test:all` (detected suites, then local and
Docker). Empty test directories do not count. `docker:images` is added in the
same run. It also creates `lint:all`, `fix:all` and `qa` when missing. Existing
scripts remain untouched; obsolete entries are not removed. The
`post-install-cmd` / `post-update-cmd` bootstrap stays repository-managed.

- Existing script entries and aggregate members are never modified. An
  aggregate deviating from the expected standard is reported with the
  standard printed in the output - align it manually and commit. The
  standard order of `fix:all` is `fix:classes`, `fix:composer`, `fix:htmlxml`,
  `fix:json`, `fix:throws`, `fix:xlf`, `fix:php` (the php-cs-fixer run
  last, normalizing what the other fixers wrote - inside `fix:php` the
  phpTools runner executes the PHP_CodeSniffer autofix before that
  run); `lint:all` is alphabetical.
  Additional repository `fix:*` scripts run alphabetically before the final
  `fix:php`, after the other shared fixers.
- `bin/qa check` enforces the same in the CI (missing scripts, missing
  aggregates and aggregate deviations all fail the gate).
  Duplicate members fail for all aggregates; execution order is enforced for
  `fix:all` and `qa` (fixes, lints, then tests). `lint:all` and `test:all`
  are membership-checked without enforcing order. Individual script commands
  remain customizable: check verifies presence, not their command text.

## The qa package itself (self-lint)

Inside the `psbits/qa` repository, the package is linted with the very
tooling it ships - its CI `lint` job (single PHP 8.4, runs after the test
jobs) executes:

- `composer analyze:php` - the root `phpstan.neon.dist` includes the shared
  baseline `Resources/Quality/phpstan.neon` and analyses `src`, `tests` and
  `bin/qa`; version-gated core code (v13.4/v14.3) is covered by justified
  `ignoreErrors` entries with `reportUnmatched: false`, and the
  conditionally declared `FakeLanguageServiceFactory` fixture is excluded
  via `excludePaths` (its `class.nonReadOnly` report is not ignorable)
- `composer lint:composer`
- `composer lint:php` - the shared phpTools runner drives both PHP tools
  in the self layout: the root `.php-cs-fixer.php` calls
  `CsFixerFactory::create(['src', 'tests', 'Resources'])`, i.e. the same
  shared CGL configuration the extension repositories use, and the root
  `.phpcs.xml.dist` references the shared ruleset
  `Resources/Quality/phpcs.xml` and declares the scope `src`, `tests`,
  `Resources`; the Slevomat standard is registered via the
  `dealerdirect/phpcodesniffer-composer-installer` plugin (allowed in the
  package `composer.json`); `bin/qa` is PHPStan-analysed but not
  style-checked (no `.php` extension)

## Test matrix (repos with `test:local` / `test:docker`)

The GitHub CI of the extension repositories is the shared workflow
`extension-ci.yml` in the psbits/qa repository (called by the
template-managed `.github/workflows/ci.yml`); locally the same scripts run
directly:

- `sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/runTests.sh <core>` - resolves the
  core via composer and runs the suites that exist (e.g. `13.4`, `14.3`)
- Suite detection: a suite runs when its `Tests/<Suite>` directory exists
  (same convention in `runCoverage.sh`). Unit-only repositories (no
  `Tests/Functional`) run unchanged; in the CI the MariaDB/PostgreSQL legs
  are skipped for them entirely
- `runLocalTests.sh` - local PHP with docker databases, `runDockerTests.sh` - everything in
  containers, `buildTestImages.sh` - builds the test images
  (`psbits/<extension>-test:<php>`)
- Preparation is checked before dependency changes or container starts. Local
  tests require PHP >= 8.3, Composer, required PHP extensions and (only for
  functional suites) the selected PDO driver. SQLite is the default; inspect
  it with `php --ri pdo_sqlite`. MySQL/PostgreSQL connections are checked without
  creating databases; PostgreSQL users also need CREATEDB permission.
- Before Docker tests, run `composer docker:images`. The runner checks Docker,
  Compose, daemon access and the selected image (`PHP_VERSION`, default 8.4),
  and aborts with preparation instructions if a prerequisite is missing.
- The matrix environment is written to `composer-matrix.json` (locked in
  `composer-matrix.lock`; both gitignored); `.Build/` holds the resolved core.
- Always verify on **both** supported majors (v13.4 and v14.3) before declaring a change
  done.

## `.Build` core switching

- Switch `.Build` between v13.4 and v14.3 with `composer update 'typo3/*'` inside the repo,
  using the target major constraint.
- After switching cores, wipe stale TF functional instances:
  `.Build/public/typo3temp/var/tests/functional-*` (per-test-class caches hold stale TCA).
- After a core switch, restart DDEV (`ddev restart`) before smoke tests.

## Vendor surgery (published dependency bugs)

`.Build/` resolves *published* versions of sibling PSBits repos. If a bug lives in a
published version:

1. Patch `.Build/vendor/<vendor>/<repo>/...` locally to prove the diagnosis and unblock
   verification.
2. Fix the actual source in the sibling repo - the fix only propagates after that repo is
   pushed and re-released or re-tagged.
3. Note which published version is affected (e.g. "foundation 4.0.0 ships the broken TCA
   layout until the 4.0.1 re-release").

## Coverage

- `sh .Build/vendor/psbits/qa/Resources/Testing/Scripts/runCoverage.sh` (where present)
  runs phpunit with coverage and compares against `Build/Quality/coverage-baseline.json`.
- The baseline update script (`test:coverage:update-baseline` /
  `runCoverage.sh --update-baseline`) rewrites the baseline - only with explicit user
  approval.

## Repo-specific QA files

Only these QA files remain in the repository (everything else is shared):

- `Build/Quality/phpstan.neon.dist` - thin shim: includes the package baseline and adds
  `paths` / `excludePaths` / `ignoreErrors` / the phpstan-typo3 extension include
- `Build/Quality/.php-cs-fixer.dist.php` - thin shim: calls
  `CsFixerFactory::create([...])` with the repository's analysis scope
- `Build/Quality/.phpcs.xml.dist` - thin shim: references the shared
  ruleset (`.Build/vendor/psbits/qa/Resources/Quality/phpcs.xml`) and
  declares the analysis scope via `<file>` elements
- `Build/Quality/coverage-baseline.json` - per-repo coverage baseline
- `Build/Quality/.prettierignore` - where prettier is used (repo-relative ignore list)
- `composer.json` - the `scripts` section and the `config.allow-plugins`
  entries of the shared tooling are kept in sync by `bin/qa sync`;
  repo-specific scripts (e.g. `test:unit:update-fixtures`) are never touched
- `.opencode/skills/psbits-qa`, `.opencode/skills/psbits-cgl`,
  `.opencode/skills/psbits-foundation` and
  `.opencode/skills/psbits-v14-compat` - symlinks into the package (created
  by `bin/qa sync`)

## Environment notes

- DDEV with PHP 8.3 as the workspace interpreter.
- `yamllint` CLI is required for `lint:yaml`.
- In restricted environments, `composer install`/`update` can fail on GitHub dist downloads
  without authentication.
- `composer.lock` is gitignored in these repos; `.Build/` is local only.
