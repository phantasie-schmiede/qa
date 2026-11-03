---
name: psbits-foundation
description: PSBits Foundation 4 integration reference for psbits TYPO3 extensions - why there are no TCA files and usually no ext_tables.sql (both are generated from model attributes), how plugins/modules/page types are registered in ExtensionInformation.php, default language label file paths and key conventions, and auto-registration file locations (FlexForms, TypoScript, TSconfig, icons). Use when working in any psbits extension that depends on psbits/foundation, when a file expected from a standard TYPO3 layout is missing, when adding a domain model, plugin, module or language label, or before deciding that a missing file must be created or "repaired".
---

# PSBits Foundation 4 integration

All psbits extensions build on `psbits/foundation` (^4.2.2). Foundation generates
the TCA and the database schema from PHP attributes on the domain models and
centralizes the registration of plugins, modules and page types. Repos of
foundation-based extensions therefore **deliberately deviate from the standard
TYPO3 extension layout** - the absence of files is the normal state, not a bug.

## No TCA files for own models

- foundation scans `Classes/Domain/Model/` at boot and generates the TCA of
  every non-abstract model that carries a `PSBits\Foundation\Attribute\TCA\Ctrl`
  attribute, using `PSBits\Foundation\Attribute\TCA\Column` (and the column
  type attributes from `foundation/Classes/Attribute/TCA/ColumnType/`) for the
  properties.
- There is therefore **no `Configuration/TCA/<table>.php` file for own
  models**. Do not create one; it would conflict with the generated TCA.
- `Configuration/TCA/Overrides/` is the only place for manual TCA code (e.g.
  adjustments to core tables such as `tt_content` or side-effect builder
  files). Files there run at boot via TcaFactory on both core majors; on
  v14 flat `Configuration/TCA/*.php` files must return their array, so
  side-effect files belong in `Overrides/` (see the `psbits-v14-compat` skill).

## No ext_tables.sql (in most repos)

- The database definitions are derived from the same TCA attributes - no
  `ext_tables.sql` is needed, and most repos do not ship one. Do not create
  one to "fix" missing tables.
- If a repo **does** ship an `ext_tables.sql` (legacy tables, core table
  overrides, or explicit column definitions), it takes priority in this order:
  1. `ext_tables.sql`
  2. `#[Column(databaseDefinition: '...')]`
  3. the default of the column type attribute
- Schema state is visible in the ddev test site after a cache flush; the
  extension's own `.Build` environment is used for tests, not for schema
  inspection.

## Registration: ExtensionInformation.php

- The single registration point is `Classes/Data/ExtensionInformation.php`,
  which extends `PSBits\Foundation\Data\AbstractExtensionInformation` and
  declares plugins (`addPlugin()`), main modules and modules
  (`addMainModule()`, `addModule()`) and page types (`addPageType()`) in its
  constructor.
- Controller actions are registered via the `#[PluginAction]` /
  `#[ModuleAction]` attributes - only actions carrying the attribute are
  registered.
- `ext_localconf.php` is usually **absent** - there is nothing to register
  there. Where it exists, it only holds special wiring (e.g. registering
  GlobalVariableService providers, cache configuration).
- Modules additionally require the thin `Configuration/Backend/Modules.php`
  wrapper, which always reads the same: it instantiates
  `PSBits\Foundation\Service\Configuration\ModuleService` and passes the repo's
  own `ExtensionInformation` class to `buildModuleConfiguration()`. Never add
  module logic to that file.

## Language label conventions

Default TCA labels are looked up in
`Resources/Private/Language/Backend/Configuration/TCA/[modelName].xlf`
(lower-case model name) unless overridden in the attributes:

| Configuration value   | Default label key                  |
|-----------------------|------------------------------------|
| ctrl->title           | `ctrl.title`                       |
| property->label       | `[propertyName]`                   |
| property->description | `[propertyName].description`       |
| palette->description  | `palette.[identifier].description` |
| palette->label        | `palette.[identifier].label`       |
| tab->label            | `tab.[identifier].label`           |

- Select-field `items` arrays: label key
  `[propertyName].[arrayKeyLowerCamelCase]`; backed enums: sanitized case
  name or `getBackendLabel()`, overridable via localized labels
  `[propertyName].[enumCaseLowerCamelCase]`.
- Labels for adjustments of core tables live in the `Overrides/` subdirectory
  with the core table name (e.g. `Overrides/tt_content.xlf`,
  `Overrides/page.xlf` - page type label key `pageType.[name]`).
- FlexForm labels: `Resources/Private/Language/Backend/Configuration/FlexForms/[formName].xlf`.
- Backend module labels (`mlang_labels_tabdescr`, `mlang_labels_tablabel`,
  `mlang_tabs_tab`): `Resources/Private/Language/Backend/Modules/[moduleName].xlf`
  (lower-case file name).
- TypoScript constant/setting labels:
  `Resources/Private/Language/Backend/Configuration/TypoScript/*.xlf`.
- Content element wizard:
  `Resources/Private/Language/Backend/Configuration/TSconfig/Page/Mod/Wizards/newContentElement.xlf`.
- Convention: punctuation (e.g. trailing colons) belongs in the `.xlf`, not in
  templates or TCA code.
- The foundation extension setting "log missing language labels" stores gaps
  in `tx_foundation_missing_language_labels` - check it during development.

## Auto-registration file locations

Files in these locations are picked up automatically - no registration code:

- FlexForms: `Configuration/FlexForms/[PluginName].xml` (supports
  `###...###` runtime markers)
- TypoScript: `Configuration/TypoScript/` (`constants.typoscript` /
  `setup.typoscript` entrypoints); template module title via
  `Resources/Private/Language/Backend/Configuration/TCA/Overrides/sys_template.xlf:template.title`
- TSconfig: `Configuration/User.tsconfig` (foundation) and
  `Configuration/Page.tsconfig` (core)
- Icons: every PNG/SVG in `Resources/Public/Icons/` is registered as
  `<extension-key>-<filename>` - there is **no `Configuration/Icons.php`**
- Site set: `Configuration/Sets/` (foundation ships `psbits/foundation`;
  extensions may add their own sets)

## Do not "repair"

The following are intentional states of a foundation-based repo - do not
create, "fix" or delete them:

- missing `Configuration/TCA/<table>.php` for own domain models
- missing `ext_tables.sql` (unless the repo documents a legacy/override reason)
- missing `ext_localconf.php` registration code
- missing `Configuration/Icons.php`
- a nearly empty `Configuration/Backend/Modules.php` (it is a thin wrapper by
  design)

If a model's table or TCA seems to be missing at runtime, the problem is in
the attributes, the boot process, or the package state - see the
`typo3-composer-env-debug` and `psbits-v14-compat` skills before touching the
file layout.

## Pointer

The authoritative, fully worked documentation is
`vendor/psbits/foundation/README.md` (sections "TCA generation", "Database
definitions", "Default language label paths", "Registering and configuring
plugins/modules", "Registering custom page types", "Auto-registration of
TypoScript-/TSconfig-files/icons").
