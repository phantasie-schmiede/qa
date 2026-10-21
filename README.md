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
- the `psbits-qa` skill and the git pre-commit hook - `Resources/Skill/`,
  `Resources/GitHooks/`, installed by `bin/qa link`
- the shared GitHub CI workflow (`extension-ci.yml` in this repository's
  `.github/workflows/`) and its caller template - `Resources/CI/ci.yml`,
  kept in sync with the repositories by `bin/qa link` / `bin/qa check`

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
        "post-install-cmd": [".Build/bin/qa link"],
        "post-update-cmd": [".Build/bin/qa link"]
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
`.github/workflows/ci.yml` once; from then on `bin/qa link`
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

## CLI

```
bin/qa link    install git hook + skill symlink and sync the caller CI
               workflow when present (idempotent)
bin/qa check   verify hook, skill link and caller CI workflow (CI gate,
               exits non-zero on drift)
bin/qa paths   print the shared resource locations
```
