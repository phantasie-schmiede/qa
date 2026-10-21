---
name: psbits-qa
description: QA and verification protocol for psbits TYPO3 extension repositories: composer validate, PHPStan (analyze:php), php-cs-fixer, yamllint, unit and functional tests, the v13.4/v14.3 test matrix (runTests.sh, runLocalTests.sh, runDockerTests.sh), .Build core switching, coverage baselines. Use when running or planning tests, static analysis, code style checks, or any verification round for a psbits extension.
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
- The skill file you are reading, the git pre-commit hook and the caller CI
  workflow (`.github/workflows/ci.yml`) are installed/synced by `bin/qa link`
  (composer post-install/update hook); `bin/qa check` verifies all three
  (CI gate, run by the shared qa job)
- The caller CI workflow is a template-managed file: it invokes the shared
  test-matrix workflow `phantasie-schmiede/qa/.github/workflows/extension-ci.yml`
  (single source of truth for the whole CI - fix it there, not per repo)
- Run `bin/qa paths` to list the shared resource locations
- Upgrade the tooling with `composer update psbits/qa`

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

`.Build/` resolves *published* versions of sibling psbits repos. If a bug lives in a
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
- `.opencode/skills/psbits-qa` - symlink into the package (created by `bin/qa link`)

## Environment notes

- DDEV with PHP 8.3 as the workspace interpreter.
- `yamllint` CLI is required for `lint:yaml`.
- In restricted environments, `composer install`/`update` can fail on GitHub dist downloads
  without authentication.
- `composer.lock` is gitignored in these repos; `.Build/` is local only.
