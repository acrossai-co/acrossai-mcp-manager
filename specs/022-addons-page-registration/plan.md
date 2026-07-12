# Implementation Plan: Register shared AcrossAI Add-ons submenu

**Branch**: `022-addons-page-registration` | **Date**: 2026-07-12 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification at `specs/022-addons-page-registration/spec.md`
**Planning Doc**: [`docs/planings-tasks/022-addons-page-registration.md`](../../docs/planings-tasks/022-addons-page-registration.md)

## Summary

Insert a single 25-line block into `AcrossAI_MCP_Manager\Includes\Main::define_admin_hooks()` that instantiates `\AcrossAI_Addon\AddonsPage` under a `class_exists` guard + `try/catch`, passing this plugin's Freemius credentials (product `34418`, public key `pk_d61a7ddb1a619f7697fbb4fc397b6`, slug `acrossai-mcp-manager`). The class is bundled inside the vendored `acrossai-co/main-menu` package and self-registers all its WordPress hooks in its constructor, so no `Loader` wiring is needed. Copy the sibling plugin `acrossai-abilities-manager/includes/Main.php:316-349` pattern verbatim, adjusting only the credential values. Update `README.txt`, `docs/planings-tasks/README.md`, and (if present) the memory hub with the accepted-deviation `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`.

## Technical Context

**Language/Version**: PHP 8.1+.
**Primary Dependencies**: Vendored `acrossai-co/main-menu` package — bumped from `0.0.14` to `0.0.16` in this feature. `0.0.15` flipped the vendor-level `account`/`contact`/`support` defaults to `true`; `0.0.16` promotes those defaults to `FreemiusInitializer::DEFAULT_MENU` and adds an `fs_menu` override key on `AddonsPage`'s `$args` so each consumer plugin explicitly declares its intent for every Freemius auto-submenu. Autoloaded via `vendor/autoload_packages.php`. No new composer requires beyond the version bump.
**Storage**: None. This feature is UI wiring only.
**Testing**: Manual smoke via wp-admin (see `spec.md` §Success Criteria + §Definition of Done Gates). No new PHPUnit tests — the block is a single external-package instantiation with error-handling that is already covered by the sibling plugin's identical shape.
**Target Platform**: WordPress 6.9+ (matches plugin `Requires WP: 7.0` header; the vendor's own `AddonsPage::assert_wp_version()` requires WP ≥ 6.0 and would throw on older installs, but the plugin's own header floor is already tighter).
**Project Type**: WordPress plugin.
**Performance Goals**: The `class_exists` check + object construction adds < 1 ms to `admin_menu` wiring. The vendor's own `admin_menu` hooks fire at priority 20/21, unchanged.
**Constraints**: Zero new composer runtime dependencies. Zero database changes. Do not modify vendor code. Do not disturb adjacent `define_admin_hooks()` wiring.
**Scale/Scope**: One block insertion in `includes/Main.php` (~25 lines including docblock). One bullet in `README.txt`. One row in `docs/planings-tasks/README.md`. One optional DEC entry.

## Constitution Check

Constitution version: **1.1.0** (per the plugin's ratified constitution referenced in Feature 021's plan.md).

### Principle I — Modular Architecture — ✅ PASS

- Single-block insertion into an existing wiring method. No new modules, no new classes, no cross-module dependency edits.
- The vendored `\AcrossAI_Addon\AddonsPage` remains fully self-contained inside its package boundary.

### Principle II — WordPress Standards Compliance — ✅ PASS

- PHPCS + PHPStan level 8 gates preserved. `\Throwable` catch + `esc_html()` escaping in the fallback closure are WPCS-compliant.
- Text domain `'acrossai-mcp-manager'` not needed here — the `printf` argument passes through `esc_html()` on a runtime-generated exception message; no user-visible translatable string introduced.

### Principle III — Security First (NON-NEGOTIABLE) — ✅ PASS

- **S1 (nonce)**: N/A — this feature does not add any user-input surface. The vendor's own `admin_post_acrossai_addons_connect_again` handler (`AddonsPage.php:123-151`) has its own `check_admin_referer()` guard.
- **S2 (permission_callback)**: The vendor's `MenuRegistrar::register()` (`MenuRegistrar.php:39`) requires `install_plugins` for the submenu — unchanged.
- **S3 (secrets hashed)**: The `fs_public_key` is a Freemius PUBLIC key (`pk_` prefix), not a secret. Freemius's own SDK considers this value safe to embed in shipped code.
- **S4 (`$wpdb->prepare`)**: N/A — no DB access.
- **S9 (no leaked internal errors)**: The exception-message admin notice is gated on `current_user_can( 'manage_options' )` to prevent leaking to lower-role users. Message content is exception messages from the vendor's own constructor, which only include the constructor's own error strings (not stack traces, not user data).

### Principle IV — User-Centric Design — ✅ PASS

- Adds a NEW submenu that gives operators self-service access to add-on discovery + installation. Aligned with the shared AcrossAI menu experience already in production for the sibling plugin.

### Principle V — Extensibility Without Core Modification — ✅ PASS

- Zero core-modification. Zero vendor-code modification. The `class_exists` guard is a Constitution §V Integration Resilience gate.

### Principle VI — Reusability & DRY — ✅ PASS

- The block is a verbatim copy of the sibling plugin's pattern. Both plugins can safely load simultaneously because the vendor's `MenuRegistrar::$registered` process-wide guard dedupes the nav row.

### Principle VII — Definition of Done — ✅ PASS

- All applicable DoD gates itemized in `spec.md` §Definition of Done Gates.

**Constitution Check Result**: All 7 principles pass. One accepted deviation from Boot Flow Rule AC-HOOKS-MAIN is documented as `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` in `docs/memory/DECISIONS.md`.

## Design

### Insertion point

```
includes/Main.php:
  define_admin_hooks() {
    ...
    $settings_menu = ... register_tab, register_settings   // lines 350-352 (unchanged)
    /* ---- INSERT HERE, ~line 353 ---- */
    <AddonsPage instantiation block>
    /* ---- END INSERT ---- */
    ... admin notice hooks ...                              // line 354+ (unchanged)
  }
```

### Exact block

```php
/**
 * Add-ons submenu page — bundled in acrossai-co/main-menu (\AcrossAI_Addon\AddonsPage).
 *
 * The AddonsPage constructor self-registers all WordPress hooks
 * (admin_menu, admin_init, admin_enqueue_scripts, admin_notices,
 * wp_ajax_acrossai_addons_*, admin_post_acrossai_addons_connect_again)
 * — no Loader wiring needed. Accepted deviation from Boot Flow Rule
 * (see DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT) because the external package's
 * public API does not expose individual hook methods. Guarded per
 * Constitution §V Integration Resilience — fails gracefully when the
 * vendor package is stripped from a build. Freemius credentials are
 * scoped to this plugin's Freemius product (id 34418).
 * Mirrors acrossai-abilities-manager Feature 038 DEC-EXTERNAL-PACKAGE-HOOK-CTOR.
 */
if ( class_exists( \AcrossAI_Addon\AddonsPage::class ) ) {
    try {
        new \AcrossAI_Addon\AddonsPage(
            ACROSSAI_MCP_MANAGER_PLUGIN_FILE,
            array(
                'fs_product_id' => '34418',
                'fs_public_key' => 'pk_d61a7ddb1a619f7697fbb4fc397b6',
                'fs_slug'       => 'acrossai-add-ons',
                'fs_menu'       => array(
                    'account' => true,
                    'contact' => true,
                    'support' => true,
                    'upgrade' => true,
                    'pricing' => true,
                    'addons'  => true,
                ),
            )
        );
    } catch ( \Throwable $e ) {
        $error_message = $e->getMessage();
        add_action(
            'admin_notices',
            function () use ( $error_message ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    return;
                }
                printf(
                    '<div class="notice notice-error"><p><strong>AcrossAI MCP Manager:</strong> %s</p></div>',
                    esc_html( $error_message )
                );
            }
        );
    }
}
```

### Reference reads (do before editing)

1. `admin/../vendor/acrossai-co/main-menu/src/Addons/AddonsPage.php:69-115` — constructor signature + throws + `boot()` hook list.
2. `vendor/acrossai-co/main-menu/src/Addons/MenuRegistrar.php:5-50` — cap requirement + `self::$registered` dedup guard.
3. `acrossai-abilities-manager/includes/Main.php:316-349` — canonical pattern to mirror.
4. This plugin's `includes/Main.php:285-374` — where the block goes.

### Interactions with other features

- **Feature 010 (`acrossai-co/main-menu` install)**: This feature depends on that package being present in `vendor/`. The `class_exists` guard handles the absent case.
- **Feature 018 (`main-menu vendor bump`)**: Any future bump of the vendor package must preserve the `\AcrossAI_Addon\AddonsPage` FQN + constructor signature. If the vendor renames the class or changes the constructor arity, this block breaks visibly (the `class_exists` guard falls false + no notice fires; regression is caught by SC-001 smoke).
- **Sibling plugin (`acrossai-abilities-manager`)**: When both plugins are active, `MenuRegistrar::$registered` static flag lets only one contribute the nav row. Both plugins still contribute Freemius product config to the shared page — that is intended.

## Testing Strategy

Manual E2E only. No new PHPUnit tests — the block is textbook error-handling around a single external-package instantiation. The vendor package has its own test coverage (out of this plugin's scope).

### E2E smoke matrix

| # | Setup | Expected |
|---|---|---|
| 1 | Fresh WP install, only this plugin active, admin user | AcrossAI → Add-ons submenu appears, page renders |
| 2 | Sibling plugin also active | Exactly one Add-ons row (vendor dedup guard) |
| 3 | Editor role (no `install_plugins`) | Submenu hidden; direct URL returns denial screen |
| 4 | Blank `fs_product_id` in code | Admin sees red notice; Editor sees nothing; wp-admin still loads |
| 5 | `vendor/acrossai-co/main-menu/` deleted | `class_exists` false; block skipped; no fatal; other submenus still work |

## Rollback Plan

Revert the block in `includes/Main.php` + the changelog bullet + the docs/planings-tasks index row + the DEC memory entry. Zero data-migration reversal needed. Any Freemius state persisted server-side by prior loads is untouched by the rollback.
