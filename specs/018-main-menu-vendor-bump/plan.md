# Implementation Plan: Bump `acrossai-co/main-menu` to 0.0.13 and adopt tab-scoped Settings API

**Branch**: `017-per-server-ability-selection` (piggy-backed) | **Date**: 2026-07-08 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/018-main-menu-vendor-bump/spec.md`

## Summary

Move `acrossai-co/main-menu` from `0.0.11` → `0.0.13` in `composer.json` + `composer.lock` + `vendor/`, and align `admin/Partials/SettingsMenu.php::register_settings()` with the two breaking changes shipped in `0.0.13`:

1. `SettingsPage::tab_page_slug()` (static) → `SettingsPage::get_settings_renderer()->tab_page_slug()` (instance-via-accessor).
2. `option_group` for each tab's `register_setting()` calls moves from the shared `'acrossai-settings'` to the tab-scoped `acrossai-settings-mcp` slug — otherwise `options.php` rejects the Save POST as "not in the allowed options list."

The fix is confined to two files: `composer.json` (one pin bump) and `admin/Partials/SettingsMenu.php` (three edits — the slug lookup, two `register_setting()` calls, and two docblocks). No other consumer of the vendor package exists in this plugin.

The vendor bump is not optional. `acrossai-abilities-manager` already pins `0.0.13`; the shared Jetpack Autoloader loads the newest copy of `\AcrossAI_Main_Menu\SettingsPage` across every active plugin. When both plugins are active, this plugin's code runs against the `0.0.13` class regardless of what `composer.json` says here. Feature 018 makes the code and the pin agree with the class version actually loaded at runtime.

## Technical Context

**Language/Version**: PHP 8.1+ (per composer.json `require.php`); no JS changes.
**Primary Dependencies**: `acrossai-co/main-menu` — bumped `0.0.11` → `0.0.13` (exact pin). No other dependency changes. `automattic/jetpack-autoloader ^5.0` unchanged; it is the loader responsible for the cross-plugin class collision resolution that motivates this bump.
**Storage**: No schema changes. Two existing `wp_options` rows (`acrossai_mcp_npm_login_enabled`, `acrossai_mcp_uninstall_delete_data`) retain their names, defaults, and sanitize callbacks. No migration is needed on any install.
**Testing**: PHPUnit + PHPCS + PHPStan gates from the existing `composer test`/`composer phpcs`/`composer phpstan` scripts. No net-new unit tests are added — the `register_settings()` method exercises the Settings API at boot; behaviour is verified by (a) `php -l` on the file, (b) manual save flow on the developer's local install (Quickstart §Save flow), (c) grep audits per FR-011 / FR-012.
**Target Platform**: WordPress 6.9+ single-site primary; multisite behaviour unchanged.
**Project Type**: WordPress plugin (PHP + SCSS build via `@wordpress/scripts` webpack).
**Performance Goals**: None — the patch is a compatibility fix with equivalent runtime cost. `SettingsPage::get_settings_renderer()` is a static accessor returning a cached instance; the fallback branch is a string concat.
**Constraints**:
- MUST NOT downgrade `acrossai-abilities-manager`, add a fork, or otherwise force `0.0.11` to load.
- MUST NOT change option names, defaults, or sanitize callbacks (would silently break persisted values on any install).
- MUST NOT introduce a `class_exists()` guard around `\AcrossAI_Main_Menu\SettingsPage` (would mask future dep breakage).
- MUST NOT introduce a `method_exists()` guard around `get_settings_renderer()` or `tab_page_slug()` (pinned version guarantees them present; fallback covers the null-return case only).
- Post-patch grep audit MUST return zero results for both `'acrossai-settings'` literal and `SettingsPage::tab_page_slug` symbol under `admin/Partials/SettingsMenu.php`.
- MUST NOT bump the plugin's own version or edit the changelog — Feature 018 rides PR #22 (Feature 017).
**Scale/Scope**: 2 files edited (`composer.json`, `admin/Partials/SettingsMenu.php`), `composer.lock` + `vendor/acrossai-co/main-menu/` refreshed as a byproduct of `composer update`. ~15 lines of PHP change plus two docblock rewrites. Zero files deleted.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Feature 018 is a two-file compatibility patch. Every constitution principle applies but is either untouched or reinforced:

| Principle | Verdict | Notes |
|---|---|---|
| **I. Modular Architecture** | PASS | No new module coupling. The patch preserves the existing hard-require on `acrossai-co/main-menu` and does not spread awareness of the vendor into any additional file. |
| **II. WordPress Standards** | PASS | PHPCS + PHPStan L8 gates apply per DoD; the patch introduces no new PHP beyond a single accessor call and a fallback string concat, both idiomatic WordPress. |
| **III. Security First** | PASS | The fix ALIGNS this plugin's registration site with WordPress's `options.php` whitelist, which is the mechanism that prevents cross-tab option-clobber attacks. Pre-fix, save was fatal; post-fix, save resolves through the tab-scoped whitelist. No new user-facing input surface introduced; sanitize callbacks unchanged. |
| **IV. User-Centric Design** | PASS | Restores a broken save flow. No new admin UI. The MCP tab's field labels, descriptions, and warning banner are all unchanged. |
| **V. Extensibility** | PASS | Uses the extension point the vendor documents (`SettingsPage::get_settings_renderer()->tab_page_slug()`). No core-modification, no vendor forking. |
| **VI. Reusability & DRY** | PASS | No duplication introduced. The tab-scoped slug is computed once, stored in `$page_slug`, and passed to all downstream Settings API calls. |
| **VII. Definition of Done** | PASS | DoD gates apply and are enumerated in `tasks.md` §Verification (Phase 4). No net-new tests are added; the file's public method signatures are unchanged, so the existing `SettingsMenuTest` suite (if it references `register_settings()`) continues to run unmodified. |

**GATE VERDICT: PASS.** No hard violations. No memory-hygiene follow-ups.

## Project Structure

### Documentation (this feature)

```text
specs/018-main-menu-vendor-bump/
├── plan.md                    # This file
├── spec.md                    # Feature specification
├── tasks.md                   # Task breakdown (Phase 2 output)
├── quickstart.md              # Manual verification recipe
└── checklists/                # (empty — no additional quality gates beyond FR-011..014)
```

### Source Code (repository root)

Compatibility patch. Below is the effective post-Feature-018 layout with `[EDIT]` and `[REFRESH]` annotations:

```text
acrossai-mcp-manager/
├── composer.json                     # [EDIT] pin bump acrossai-co/main-menu 0.0.11 → 0.0.13
├── composer.lock                     # [REFRESH] regenerated by `composer update acrossai-co/main-menu`
├── vendor/acrossai-co/main-menu/     # [REFRESH] contents replaced by 0.0.13 tarball
│   └── src/
│       ├── SettingsPage.php          # 0.0.13 shape (no more static tab_page_slug)
│       ├── SettingsPageRenderer.php  # 0.0.13 new file (was TabbedPageRenderer subclass)
│       ├── TabbedPageRenderer.php    # 0.0.13 new file (holds tab_page_slug + tab-scoped render)
│       └── [other vendor files]     # 0.0.13 shape
│
├── admin/Partials/
│   └── SettingsMenu.php              # [EDIT] register_settings() slug lookup + option_group + 2 docblocks
│
└── [everything else]                 # [UNCHANGED]
```

**Structure Decision**: No directory changes. Feature 018 does not create, delete, or move any file authored by this plugin.

## Complexity Tracking

Zero hard violations. The only conditional in the patch is the `$renderer ? … : …` fallback in `register_settings()`, which handles a rare-but-observable null return from `SettingsPage::get_settings_renderer()`. The fallback reconstructs the exact string that `SettingsPageRenderer::tab_page_slug()` would return — no divergence, no drift risk. Documented inline via the paragraph in the `register_settings()` docblock added by Task-3.
