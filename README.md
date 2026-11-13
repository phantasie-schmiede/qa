# psbits/qa

Shared QA infrastructure for the PSBits TYPO3 extension repositories. The package
holds the single source of truth for:

- the unit/functional test matrix (v13.4 / v14.3, sqlite / mysql / postgres, local
  and Docker legs) - `Resources/Testing/Scripts/`, `Resources/Testing/Docker/`
- the PHPUnit suite configurations - rendered into `<repo>/.Build/phpunit/`
  (git-ignored) with absolute paths by `Resources/Testing/renderPhpunitXml.php`
- linter and fixer configuration - `Resources/Quality/` (php-cs-fixer incl. the
  shared `PSBits/*` custom fixers, PHPStan baseline, prettier, yamllint,
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

The extension keeps only its repo-specific QA files:
`Build/Quality/phpstan.neon.dist` (thin shim over the package baseline),
`Build/Quality/coverage-baseline.json` and `Build/Quality/.prettierignore`
(where applicable). The composer scripts point at the package resources, e.g.

```
php .Build/vendor/psbits/qa/Resources/Testing/renderPhpunitXml.php unit
php -d memory_limit=1G .Build/bin/phpunit -c .Build/phpunit/UnitTests.xml
```

The test matrix scripts resolve the repository root from `PSBITS_QA_REPO_ROOT`
or the current working directory (composer and CI both run from the repo
root); the Docker image name derives from `PSBITS_QA_EXTENSION` (default:
the repository directory name).

### Preparing local and Docker tests

`qa sync` adds `docker:images` when the repository already defines `test:docker`;
`qa check` verifies its presence. Existing custom scripts are preserved. The
selection of test suites and `test:all` remains repository-managed.

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
'Resources'])`, and `composer normalize` keeps the `composer.json` clean.
The CI `lint` job (single PHP 8.4) runs `composer analyze:php`,
`composer lint:composer` and `composer lint:php`.

## CLI

```
bin/qa sync    install git hook + skill symlinks and create or sync the
               caller CI workflow (idempotent)
bin/qa check   verify hook, skill links and caller CI workflow (CI gate,
               exits non-zero on drift)
bin/qa paths   print the shared resource locations
```
