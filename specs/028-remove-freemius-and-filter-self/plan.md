# Implementation Plan: Retire Freemius integration; consume main-menu 0.0.22+ filter-driven Add-ons page

**Branch**: `028-remove-freemius-and-filter-self` (nominal; implementation on `feature/remove-freemius` — see §Note on branch naming below) | **Date**: 2026-07-17 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/028-remove-freemius-and-filter-self/spec.md`
**Companion docs**: [pre-Spec-Kit planning doc](../../docs/planings-tasks/028-remove-freemius-and-filter-self.md)

## Note on branch naming

This feature shipped as a fast-path change on `feature/remove-freemius` (not the Spec-Kit-conventional `028-remove-freemius-and-filter-self`). The naming divergence is intentional and one-off:
- The code change is 3 files (composer.json bump + 94-line delete in Main.php + 1 new tiny Partial class + 1 new PHPUnit file); the Spec-Kit ceremony was reverse-engineered *after* the code shipped to give `/speckit-analyze` inputs.
- PR #34 is already open against `main`; renaming the branch would break the PR ref.
- Future features should still use `NNN-slug` branch names from the start (see the `/speckit-git-feature` hook in `.specify/extensions.yml`).

## Summary

Retire the Freemius WordPress SDK integration this plugin inherited via `acrossai-co/main-menu` 0.0.15–0.0.21. The upstream vendor bumped to `0.0.22` (then `0.0.23`) with `freemius/wordpress-sdk` removed from its `require` block and the `AcrossAI_Addon\` PSR-4 namespace deleted. The Add-ons page in 0.0.22+ is filter-driven: `AddonsPageRenderer::get_addons()` runs `apply_filters( 'acrossai_addons', self::ADDONS )` and renders card entries with no license/opt-in state.

Consumer side:
- **Bump**: `composer.json` pins main-menu at `0.0.23`; `composer update` uninstalls `freemius/wordpress-sdk 2.13.4` transitively.
- **Delete**: 94-line block in `Main::define_admin_hooks()` that instantiated `\AcrossAI_Addon\AddonsPage` with `fs_product_id=34418` / `fs_public_key` / `fs_slug=acrossai-add-ons` / `fs_menu` / `fs_has_addons=true` — the class is gone in 0.0.22, so the `class_exists` guard would fall false permanently anyway.
- **Add**: `admin/Partials/AddonsFilter.php` singleton with `remove_self()` — hooked to `acrossai_addons` via the Loader, strips the entry with `slug === 'acrossai-mcp-manager'` from the array (an already-active plugin should not advertise itself as installable).
- **Test**: 4 phpunit cases in `tests/phpunit/Admin/AddonsFilterTest.php`.
- **Supersede**: `docs/memory/INDEX.md`, `docs/memory/DECISIONS.md`, and `docs/memory/BUGS.md` flip status on `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`, `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT`, and `B28` to `Superseded (F028)`; entry bodies preserved verbatim per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION.
- **WORKLOG**: durable-lesson entry codifying the vendor-shed retirement pattern + consumer self-exclusion filter pattern for any future AcrossAI plugin.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline per `composer.json`).
**Primary Dependencies**: `acrossai-co/main-menu` ^0.0.23; `automattic/jetpack-autoloader` ^5.0; existing plugin dependencies unchanged. `freemius/wordpress-sdk` REMOVED.
**Storage**: None. Feature is code-only; no schema, no `wp_options`.
**Testing**: PHPUnit — new `tests/phpunit/Admin/AddonsFilterTest.php` (4 cases) in the `admin` testsuite (extends `WP_UnitTestCase`, needs WP-PHPUnit harness at `/tmp/wordpress-tests-lib` or CI).
**Target Platform**: WordPress 6.9+ single-site admin (multisite out of scope per plugin baseline).
**Project Type**: WordPress plugin, single project.
**Performance Goals**: Register-time cost of the added filter is one function call per admin request. `AddonsPageRenderer::get_addons()` memoizes filter output per request (`static $cache = null`), so `remove_self()` executes at most once per admin page load.
**Constraints**: No schema change. No new REST route. No new user input. No JS. No admin UI beyond what the vendor renders. Loader-only hook registration per A1. Singleton per §II. Every FR is a subtractive edit or a 1-file addition.
**Scale/Scope**: Filter input is 4 entries in the vendor baseline; typical companion input adds 0–5 more. `remove_self` is O(n) in the array size; negligible.

## Constitution Check

*GATE: Must pass before implementation. Re-check after code lands.*

Constitution v1.1.0 (ratified 2026-05-28, last amended 2026-07-12).

| Principle | Gate | Status | Notes |
|---|---|---|---|
| **I. Modular Architecture** | Single-purpose module, no cross-module coupling, shared logic in `includes/Utilities/` | **PASS** | New class lives in `admin/Partials/` (correct namespace per A3). It has one public method with one responsibility. Zero cross-module coupling — no new imports beyond the standard `defined( 'ABSPATH' ) || exit;` guard. |
| **II. WordPress Standards Compliance** | WPCS strict, PHPStan L8, ESLint clean, WP 6.9+ / PHP 8.1+, multisite unless justified | **PASS** | `declare( strict_types = 1 )`, singleton pattern with private `__construct`, docblocks on both `instance()` and `__construct()`, WPCS-clean, PHPStan L8 clean (return type `array<int, array<string, mixed>>`). No multisite considerations. |
| **III. Security First** | Sanitization, escaping, nonces, capability checks, prepared statements, `permission_callback`, hashed secrets | **PASS** *(vacuously)* | No user input. No REST route. No output rendering (the filter returns an array; the vendor renders cards). No storage. Security surface unchanged. |
| **IV. User-Centric Design** | New admin UI uses DataForm/DataViews unless pre-approved exception | **PASS** *(no new UI)* | Zero new UI code. The vendor renders the Add-ons page; F028 only reshapes its filter input. |
| **V. Extensibility Without Core Modification** | Actions/filters/extension points; graceful degradation for optional integrations | **PASS** | Uses the vendor-published `acrossai_addons` filter — this is the intended extension point. `remove_self` is defensive: non-array input normalizes to `array()`; non-array entries are dropped. No vendor file is touched. |
| **VI. Reusability & DRY** | Shared logic centralized; `@wordpress/*` first, npm second; `validate-packages` runs pre-commit | **PASS** | The `OWN_SLUG` constant is the single source of truth for this plugin's slug in the filter context. If another plugin needs the same self-exclusion pattern, it copies the class and changes the constant — no duplication in *this* plugin. No npm changes. |
| **VII. Definition of Done** | PHPCS / PHPStan L8 / ESLint / security / tests / DataForm / DRY / prefix / AGENTS.md / validate-packages | **PASS** | All gates addressed. Local runs: PHPCS clean, PHPStan L8 clean, phpunit `mcpclients` suite green (67/67), CI green on all 8 checks including `PHPUnit (integration) — PHP 8.4 / WP latest`. |

**Post-check verdict**: No violations. No new deviations. One decision is *retired* (`DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`) because its subject class no longer exists.

## Project Structure

### Documentation (this feature)

```text
specs/028-remove-freemius-and-filter-self/
├── plan.md                       # This file
├── spec.md                       # Feature specification
└── tasks.md                      # Reverse-engineered task list (all tasks marked [X] — code already shipped)
```

Companion (outside the specs/ dir):

```text
docs/planings-tasks/028-remove-freemius-and-filter-self.md   # Pre-Spec-Kit design doc that fed the implementation
docs/memory/INDEX.md                                          # 3 supersede-status flips + 1 new Worklog row
docs/memory/DECISIONS.md                                      # Status line flips at DEC entry sources
docs/memory/BUGS.md                                           # Status line flip at B28 source
docs/memory/WORKLOG.md                                        # New 2026-07-17 F028 entry
```

### Source Code (repository root)

```text
composer.json                                          # main-menu 0.0.18 → 0.0.23
composer.lock                                          # regenerated; freemius/wordpress-sdk uninstalled
includes/Main.php                                      # -94 lines (AddonsPage block); +8 lines (AddonsFilter wiring)
admin/Partials/AddonsFilter.php                        # NEW — singleton with remove_self()
tests/phpunit/Admin/AddonsFilterTest.php               # NEW — 4 cases
```

## Related memory

| Entry | Status change | Reason |
|---|---|---|
| `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` | Active (F022) → Superseded (F028) | `\AcrossAI_Addon\AddonsPage` no longer exists in main-menu 0.0.22+; no self-registering vendor class to guard. |
| `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` | Active (F022) → Superseded (F028) | This plugin no longer loads Freemius; the opt-in state machine has no live surface here. |
| `B28` (Freemius two-level menu enablement) | Active (F022) → Superseded (F028) | The bug can't recur without the SDK loaded. |

Bodies retained verbatim under a `**Status**: Superseded (Feature 028 — 2026-07-17). …` line per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION.

## Verification

Command lines (all commands run from plugin root, use `TMPDIR=/tmp` to avoid the per-session tmpfs cap):

```bash
composer run phpcs -- admin/Partials/AddonsFilter.php includes/Main.php
composer run phpstan
composer run test -- --testsuite mcpclients
# CI runs the admin/oauth/cli-rest/renderers integration suites via bootstrap-wp.php
```

Manual (post-deploy):

- Open the AcrossAI → Add-ons page on a wp-admin where at least one *other* `acrossai_addons` consumer is active. Confirm this plugin's card is absent.
- Deactivate this plugin. Reload the Add-ons page. Confirm the card reappears (baseline vendor behavior).
- `composer show freemius/wordpress-sdk` returns "package not found".
- `grep -rn 'AcrossAI_Addon\|freemius\|fs_dynamic_init\|acrossai-add-ons' includes/ admin/ public/ src/ tests/` returns zero matches.

## Out of scope

- **Migration of orphan `fs_*` `wp_options` rows** left by the Freemius SDK on prior versions. Per D21 (fresh-install-only retirement, established by F016), operators clean up manually with `DELETE FROM wp_options WHERE option_name LIKE 'fs_%';` if desired.
- **Historical planning docs**: `specs/022-addons-page-registration/` and `docs/planings-tasks/022-addons-page-registration.md` are frozen historical record; not edited.
- **Anonymous usage telemetry**: F022 relied on Freemius' opt-in card for anonymous usage tracking; that surface is retired without replacement. If future telemetry is needed, it will require a fresh design in a separate feature.
