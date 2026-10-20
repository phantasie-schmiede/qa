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

## Usage in an extension

```json
{
    "require-dev": {
        "psbits/qa": "@dev"
    },
    "scripts": {
        "post-install-cmd": ["bin/qa link"],
        "post-update-cmd": ["bin/qa link"]
    }
}
```

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
bin/qa link    install git hook + skill symlink (idempotent)
bin/qa check   verify hook and skill link (CI gate, exits non-zero on drift)
bin/qa paths   print the shared resource locations
```
