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
4. `composer lint:php -- <changed files>` (or php-cs-fixer dry-run with
   `--path-mode=intersection` on the package config, see below)
5. `composer lint:yaml` (or `yamllint`) for changed YAML files
6. `composer test:unit` and `composer test:functional` when the repo defines them

A fully green block is the definition of done for a change.

## Shared infrastructure (`psbits/qa`)

The QA tooling lives in the `psbits/qa` package (installed into
`.Build/vendor/psbits/qa/`), not in the repository:

- Linter/fixer configuration: `.Build/vendor/psbits/qa/Resources/Quality/`
  (cs-fixer config with the shared `PSBits/*` fixers, phpstan baseline,
  prettier, yamllint, typoscript-lint, lint-class-attributes)
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

## Shared QA scripts (synced by `bin/qa sync`)

The caller `composer.json` `scripts` section is part of the package-managed
state. `bin/qa sync` adds the full set of shared scripts when missing, each
with its own applicability condition, so only scripts that fit the
repository are added:

- `analyze:php` (PHPStan, needs `Build/Quality/phpstan.neon.dist`)
- `audit:composer`, `validate:composer` (always)
- `lint:composer` / `fix:composer` (always; the `ergebnis/composer-normalize`
  plugin normalizes the `composer.json` key order and formatting)
- `lint:php` / `fix:php` (PHP-CS-Fixer, needs
  `Build/Quality/.php-cs-fixer.dist.php`)
- `lint:classes` / `fix:classes`, `lint:htmlxml` / `fix:htmlxml`,
  `lint:json` / `fix:json`, `lint:js`, `lint:scss` (npm proxies, need the
  matching npm script in the repository `package.json`; the json pair runs
  Prettier on `package.json`)
- `lint:typoscript` (needs at least one `.typoscript` file below
  `Configuration/TypoScript`)
- `lint:yaml` (needs the `Configuration` directory)
- `lint:xlf` / `fix:xlf` (check/fix the XLIFF trans-unit id order, need
  `*.xlf` files) and `fix:throws` (rewrite the `@throws` docblocks
  against the full matrix of supported core majors: the tooling
  switches the installed core per matrix entry, converges the PHPStan
  audit loop per major, then merges the per-major sets into one
  docblock with `// t3:N` markers on the major-specific lines, needs
  the PHPStan configuration and network access for the core switches)

`bin/qa sync` also creates `lint:all`, `fix:all` and `qa` when missing. The
`test:*` scripts and the `post-install-cmd` / `post-update-cmd` bootstrap
stay under repository management (profile-dependent, handled by
psbits/crafter where applicable).

- Existing script entries and aggregate members are never modified. An
  aggregate deviating from the expected standard is reported with the
  standard printed in the output - align it manually and commit. The
  standard order of `fix:all` is `fix:classes`, `fix:composer`, `fix:htmlxml`,
  `fix:json`, `fix:throws`, `fix:xlf`, `fix:php` (the php-cs-fixer run last,
  normalizing what the other fixers wrote); `lint:all` is alphabetical.
- `bin/qa check` enforces the same in the CI (missing scripts, missing
  aggregates and aggregate deviations all fail the gate).

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
- `composer lint:php` - the root `.php-cs-fixer.php` calls
  `CsFixerFactory::create(['src', 'tests', 'Resources'])`, i.e. the same
  shared CGL configuration the extension repositories use; `bin/qa` is
  PHPStan-analysed but not style-checked (no `.php` extension)

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
- `Build/Quality/coverage-baseline.json` - per-repo coverage baseline
- `Build/Quality/.prettierignore` - where prettier is used (repo-relative ignore list)
- `composer.json` - the `scripts` section is kept in sync by `bin/qa sync`
  (shared scripts, `lint:all` / `fix:all` / `qa` aggregates); repo-specific
  scripts (e.g. `test:unit:update-fixtures`) are never touched
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
