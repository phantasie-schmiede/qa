# psbits/qa

Shared QA infrastructure for the PSBits TYPO3 extension repositories. The package
holds the single source of truth for:

- the unit/functional test matrix (v13.4 / v14.3, sqlite / mysql / postgres, local
  and Docker legs) - `Resources/Testing/Scripts/`, `Resources/Testing/Docker/`
- the PHPUnit suite configurations - rendered into `<repo>/.Build/phpunit/`
  (git-ignored) with absolute paths by `Resources/Testing/renderPhpunitXml.php`
- linter and fixer configuration - `Resources/Quality/` (php-cs-fixer incl. the
  shared `PSBits/*` custom fixers, the `phpTools.php` runner behind
  `lint:php`/`fix:php`, PHP_CodeSniffer with the Slevomat
  control-structure spacing ruleset, PHPStan baseline, prettier, yamllint,
  typoscript-lint, lint-class-attributes)
- the shared skills (`psbits-qa`, `psbits-foundation`, `psbits-v14-compat`) and
  the git pre-commit hook - `Resources/Skill/`, `Resources/GitHooks/`, installed
  by `bin/qa sync`
- the shared GitHub CI workflow (`extension-ci.yml` in this repository's
  `.github/workflows/`) and its caller template - `Resources/CI/ci.yml`,
  kept in sync with the repositories by `bin/qa sync` / `bin/qa check`

## Usage in an extension

```json
{
    "require-dev": {
        "psbits/qa": "@dev"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/phantasie-schmiede/qa.git"
        }
    ],
    "scripts": {
        "post-install-cmd": [".Build/bin/qa sync"],
        "post-update-cmd": [".Build/bin/qa sync"]
    }
}
```

### Caller CI workflow

The extension repository keeps only a thin caller workflow
(`.github/workflows/ci.yml`) that invokes the shared test-matrix workflow of
this repository:

```yaml
jobs:
  ci:
    uses: phantasie-schmiede/qa/.github/workflows/extension-ci.yml@main
    secrets: inherit
```

Adopt it by copying `Resources/CI/ci.yml` from this package into
`.github/workflows/ci.yml` once; from then on `bin/qa sync`
(composer post-install/update) keeps the file in sync with the template and
`bin/qa check` (run by the CI qa job) fails on drift. Repositories without
`Tests/Functional` run unit-only: the functional suite is skipped by
`runTests.sh`/`runCoverage.sh` and the database legs (MariaDB/PostgreSQL)
are skipped entirely by the shared workflow. The `TYPO3_API_TOKEN` secret
is optional: repositories without it skip the TER publish, everything else
runs unchanged.

### Safe synchronization

`qa sync --dry-run` previews hook, workflow, skill and Composer script changes
without writing files. Conflicting existing contents abort the entire sync
before any file is changed. Review them and use `qa sync --force` only when
you explicitly want to replace them (including existing skill directories).
Combine both flags to preview a forced takeover.

Files identical to the package templates or carrying the comment
`This file is managed by \`qa sync\`` are package-managed and updated normally.
The exact previous PSBits pre-commit hook is also recognized for migration.
Other files and symlinks are conflicts; skill links are accepted only when
they already point to the expected package target.

The hook location is resolved by Git, including worktrees and relative or
absolute `core.hooksPath`. Repositories without their own Git metadata skip
the hook. `qa check` also verifies hook executability; `qa sync` repairs
permissions even when the content is already identical. Git must be available
when Git metadata exists.

The extension keeps only its repo-specific QA files:
`Build/Quality/phpstan.neon.dist` (thin shim over the package baseline),
`Build/Quality/.php-cs-fixer.dist.php` (thin shim calling `CsFixerFactory` with
the repository's analysis scope), `Build/Quality/.phpcs.xml.dist` (thin shim
over the shared PHP_CodeSniffer ruleset, declaring the analysis scope),
`Build/Quality/coverage-baseline.json` and `Build/Quality/.prettierignore`
(where applicable). The composer scripts point at the package resources, e.g.

```
php .Build/vendor/psbits/qa/Resources/Testing/renderPhpunitXml.php unit
php -d memory_limit=1G .Build/bin/phpunit -c .Build/phpunit/UnitTests.xml
```

The `lint:php`/`fix:php` scripts drive both PHP tools through the shared
runner `php .Build/vendor/psbits/qa/Resources/Quality/phpTools.php
lint|fix`: it runs the PHP-CS-Fixer and the PHP_CodeSniffer configuration
that exists in the repository and passes the CLI paths to every tool that
runs. When upgrading to the package version that introduced the runner,
the existing `lint:php`/`fix:php` lines are updated manually - `qa sync`
never rewrites existing script entries.

Besides the scripts, `qa sync` keeps the `config.allow-plugins` entries the
shared tooling requires in the caller `composer.json` (currently the
`dealerdirect/phpcodesniffer-composer-installer` plugin, which registers the
Slevomat standard with PHP_CodeSniffer) and `qa check` verifies them: missing
or disabled entries are added by sync (or left untouched, with a hint, when
explicitly disabled) and fail check. A repository's first `composer update`
after the plugin becomes part of the dependency tree must carry the entry
already - Composer blocks not-allowed plugins before any script can run.

The test matrix scripts resolve the repository root from `PSBITS_QA_REPO_ROOT`
or the current working directory (composer and CI both run from the repo
root); the Docker image name derives from `PSBITS_QA_EXTENSION` (default:
the repository directory name).

### Preparing local and Docker tests

`qa sync` detects `*Test.php` files recursively in `Tests/Unit` and
`Tests/Functional` and adds the matching `test:unit` / `test:functional` scripts.
With either suite it also adds local/Docker matrix and coverage scripts, plus
`test:all` (existing suites, then local and Docker). Empty test directories do
not opt a repository into testing. `docker:images` is added for detected tests
or an existing `test:docker`, in the same sync run. `qa check` verifies required
scripts and aggregates. Existing custom scripts and aggregates are preserved;
aggregate deviations are reported by sync and fail check. Obsolete entries are
not removed automatically.

`qa check` rejects duplicate aggregate members. It also enforces the execution
order of `fix:all` and `qa`: custom `fix:*` scripts run alphabetically after
the shared fixers but before the final `fix:php`; `qa` runs fixes, then lints,
then tests (when present). Existing aggregates are not rewritten by sync.
For `lint:all` and `test:all`, membership and duplicates are checked, not order.
Individual script commands may be customized; check verifies their presence,
not that their command text matches the package default.

- `composer test:local` needs PHP >= 8.3, Composer, the PHP extensions required
  by the repository and test tools, and (for functional tests) the selected PDO
  driver. SQLite is the default; Debian/Ubuntu typically supplies `pdo_sqlite`
  through `php<major.minor>-sqlite3`. Check the CLI configuration with `php --ini`
  and `php --ri pdo_sqlite`. Unit-only repositories do not need a database.
- MySQL/PostgreSQL legs need an accessible server and credentials configured via
  `typo3DatabaseHost`, `typo3DatabasePort`, `typo3DatabaseUsername` and
  `typo3DatabasePassword`. Users must be allowed to create and drop test databases.
  Preparation checks connect without creating a database; PostgreSQL's CREATEDB
  permission is also checked. MySQL's effective per-database permissions remain
  the test framework's responsibility.
- Before `composer test:docker`, run `composer docker:images`. Docker and Compose
  must be available, the daemon reachable, and the selected test image present.
  `PHP_VERSION` selects the image tag (default: 8.4). Rebuild after Dockerfile or
  additional CA certificate changes.

The runners abort on missing prerequisites before resolving dependencies or
starting containers. Single-leg runs are checked too. The local matrix checks
preparation before its first leg rather than reporting the same setup failure
four times. Dependency resolution still needs repository/network access;
preparation checks cannot guarantee successful downloads or successful tests.

## Linting the package itself

The package is linted with the tooling it ships: the root
`phpstan.neon.dist` includes the shared baseline
`Resources/Quality/phpstan.neon` (paths `src`, `tests`, `bin/qa`), the root
`.php-cs-fixer.php` calls `CsFixerFactory::create(['src', 'tests',
'Resources'])`, the root `.phpcs.xml.dist` references the shared ruleset
`Resources/Quality/phpcs.xml` (analysis scope `src`, `tests`, `Resources`),
and `composer normalize` keeps the `composer.json` clean.
The CI `lint` job (single PHP 8.4) runs `composer analyze:php`,
`composer lint:composer` and `composer lint:php` (both PHP tools via the
shared phpTools runner).

## CLI

### Side effects of `qa throws-fix`

The fixer creates one disposable repository copy and Composer installation per
core version in `.Build/throws-fix/<run-id>/<core>/`. The normal vendor/bin
directories, `composer.json` and `composer.lock` remain unchanged; no restore
is needed. Dependencies are freshly resolved with Composer `update -W
--no-scripts`; the regular Composer download cache is reused. Network access
and additional disk space are needed, even for a single core.

The current QA code is copied into the run directory and installed as a mirrored
path package in every environment. Repository-relative PHPStan includes and
autoload paths keep working in the copied directory structure. Absolute NEON
references into the repository are redirected into the copy. Relative path
repositories are resolved against the original root and mirrored, not symlinked.
Source symlinks and autoload paths outside the repository are rejected rather
than exposing original files through a link. Custom bootstraps must work within
the repository copy; this is filesystem isolation, not an OS security sandbox.

Only files copied from the repository are eligible for docblock updates in the
original source. Updated files are refreshed before each analysis; dependency
paths and paths outside the copy are rejected. Avoid concurrent edits to the
source during the fixer run. Existing additive convergence and the final merge
remain unchanged.

Run directories are removed after completion and exceptions. Cleanup failure
fails an otherwise successful run without hiding an existing analysis error.
Forced termination (e.g. SIGKILL) can leave a run directory behind, but does not
require restoring the normal dependency installation.

Composer and PHPStan output streams are drained concurrently to avoid blocking
on large stderr output. Processes have no fixed runtime timeout, matching long
dependency resolutions and iterative analysis.

```
bin/qa sync    install git hook + skill symlinks and create or sync the
               caller CI workflow (idempotent)
bin/qa check   verify hook, skill links and caller CI workflow (CI gate,
               exits non-zero on drift)
bin/qa paths   print the shared resource locations
```
