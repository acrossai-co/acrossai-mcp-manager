# Feature 010 — Research Notes

**Feature**: 010-composer-dependencies
**Created**: 2026-07-02 (Phase 0 executed inline via reference-plugin inspection)
**Reference plugin**: `/wp-content/plugins/acrossai-abilities-manager` (Feature 038 — integrates all 4 new packages)

---

## R1 — `acrossai-co/main-menu 0.0.10` API Surface

Inspected: `vendor/acrossai-co/main-menu/{README.md,src/SettingsPage.php,src/MenuRegistrar.php}` (in the reference plugin's vendor tree).

### Public API

| Symbol | Purpose |
|---|---|
| `\AcrossAI_Main_Menu\SettingsPage` | Entry class — construct once per request via `new SettingsPage()`. Safe to construct from every consumer plugin — jetpack-autoloader picks one copy to boot. |
| `\AcrossAI_Main_Menu\SettingsPage::PARENT_SLUG` | `'acrossai'` — the parent menu slug (used as the 1st arg to `add_submenu_page`) |
| `\AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG` | `'acrossai-settings'` — the Settings submenu slug + shared option_group |
| `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug( string $tab_slug )` | Returns the per-tab page slug for tabbed Settings mode |
| `\AcrossAI_Addon\AddonsPage` | Add-ons page entry (per-consumer Freemius credentials); Feature 010 does NOT use this |

### Registration Pattern

Consumer plugins register their own submenus via **standard `add_submenu_page`**:

```php
add_submenu_page(
    SettingsPage::PARENT_SLUG,     // 'acrossai'
    __( 'MCP Manager', ... ),      // page_title
    __( 'MCP', ... ),              // menu_title (sidebar label)
    'manage_options',              // capability
    'acrossai_mcp_manager',        // slug — matches consumer plugin's URL contract
    [ $this, 'contents' ],         // render callback
    <position>                     // integer; positions coordinated across consumers
);
```

### `admin_menu` hook behavior

The package **auto-hooks `admin_menu` internally**. Verified at `vendor/acrossai-co/main-menu/src/SettingsPage.php:53`:

```php
add_action( 'admin_menu', [ $this->menu_registrar, 'register_parent' ] );
add_action( 'admin_menu', [ $this->menu_registrar, 'register_settings_submenu' ], 1000 );
```

Consumer plugins DO still need to Loader-wire their OWN submenu callback on `admin_menu` — the auto-hook only registers the shared parent + Settings, not consumer-specific submenus.

### Screen ID prefix

`WordPress derives screen ID prefix from parent menu **title**. Verified at `vendor/acrossai-co/main-menu/src/MenuRegistrar.php:38`:

```php
add_menu_page(
    __( 'AcrossAI', 'acrossai' ),   // parent title
    __( 'AcrossAI', 'acrossai' ),   // menu title
    ...
);
```

`sanitize_title('AcrossAI') = 'acrossai'` → submenu screen IDs are `acrossai_page_<slug>`.

Post-Feature-010:
- Main MCP page: `acrossai_page_acrossai_mcp_manager` (was `toplevel_page_acrossai_mcp_manager`)
- CLI Auth Log: `acrossai_page_acrossai_mcp_manager_cli_auth_log` (was `mcp-manager_page_...`)
- Access Control: `acrossai_page_acrossai_mcp_manager_access_control` (was `mcp-manager_page_...`)

Legacy IDs retained in `AdminPageSlugs::plugin_screen_ids()` per A9 additive rule (defense against multi-plugin version-resolution scenarios).

### Public-static predicates for consumers

**None consumer-usable for screen matching.** Package publishes `PARENT_SLUG` + `SETTINGS_SLUG` + `tab_page_slug()`, but no `is_plugin_screen()` / `current_page_id()` style predicate. D14 opportunity NOT available. `AdminPageSlugs::plugin_screen_ids()` remains canonical per A9.

### Multi-plugin coordination

`jetpack-autoloader ^5.0` picks the highest version to boot. Feature 010 pins `0.0.10` (one patch higher than `acrossai-abilities-manager`'s `0.0.9`) so this plugin's copy wins version resolution if both are active. `did_action('acrossai_main_menu_bootstrapped')` guard makes the FR-029 bootstrap idempotent across sibling plugins.

### Capability convention

Default `manage_options` throughout the package (matches WordPress default admin gate). Feature 010 preserves this — no capability constant changes required (FR-024).

### Package internal dependency on `wpb-access-control`

**Verified NOT required.** `vendor/acrossai-co/main-menu/composer.json` does NOT list `wpboilerplate/wpb-access-control`. Feature 010's FR-003 requirement for `wpb-access-control` originates from THIS plugin's own consumers (guards in Menu.php, Settings.php, CliController.php, Main.php), not transitively via main-menu.

Transitive dep of main-menu that IS surfaced: `freemius/wordpress-sdk ^2.0`. Trusted publisher (Freemius powers 15,000+ WordPress plugins). Not blocking; not consumed by Feature 010.

---

## R2 — `allow-plugins` Verification

Verified via `composer show <pkg> --tree` (2026-07-02):

| Package | Ships composer plugin? | Requires allow-plugins entry? |
|---|---|---|
| `wpboilerplate/wpb-access-control ^2.0.0` | No | **No** |
| `berlindb/core ^3.0.0` | No | **No** |
| `acrossai-co/main-menu 0.0.10` | No | **No** |

Existing 2 entries in `config.allow-plugins` (`dealerdirect/phpcodesniffer-composer-installer` + `automattic/jetpack-autoloader`) are preserved unchanged (FR-006).

---

## `wpb-access-control` VCS repository (FR-003 NEW finding)

`wpboilerplate/wpb-access-control` is **NOT on Packagist**. `composer.json` requires a `repositories` VCS entry:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/WPBoilerplate/wpb-access-control"
    }
]
```

Verified at implementation time (T007 `composer update`) — resolver successfully fetches from GitHub via VCS entry, installs v2.0.0.

---

## Transitive dependency audit (SEC-010-003)

`composer show <pkg> --tree` for each new package on 2026-07-02:

- **`acrossai-co/main-menu 0.0.10`** → `automattic/jetpack-autoloader ^5.0` (already ours), `freemius/wordpress-sdk ^2.0` (trusted — Freemius powers 15K+ WP plugins), `php >=8.1`
- **`berlindb/core 3.0.0`** → `php >=8.1` only (clean)
- **`wpboilerplate/wpb-access-control v2.0.0`** → `automattic/jetpack-autoloader ^5.0`, `berlindb/core ^3.0` (already ours), `php >=8.1`

`composer audit` reported: **No security vulnerability advisories found** (0 CVEs across the whole tree).

Trust posture: all top-level publishers are trusted (AcrossAI, WPBoilerplate, BerlinDB/Sandhills, Automattic). One new transitive publisher: Freemius (via main-menu). Trusted.

---

## TASK-8 — BerlinDB Real-Package Integration Point (Feature 011 scope)

Feature 010 adds `berlindb/core ^3.0.0` to `vendor/` but does NOT consume the namespace. Custom `BerlinDB-style` Query classes remain in place per CONSTRAINT 2 / FR-026. Feature 011 (non-blocking post-cutover polish per Q2 clarification) will refactor.

### Files that would migrate

| File | Approx LOC | Migration cost |
|---|---|---|
| `includes/Database/CliAuthLog/Query.php` | ~150 LOC | Extend `\BerlinDB\Base\Query`; define `$table_name`, `$columns`; rewrite `add_item` / `update_item` / `delete_item` / `query` signatures |
| `includes/Database/OAuthToken/Query.php` | ~150 LOC | Same shape |
| `includes/Database/OAuthAudit/Query.php` | ~150 LOC | Same shape |
| `includes/Database/MCPServer/Query.php` | ~150 LOC | Same shape |

Per-file PHPUnit fixture rewrite: ~200 LOC each. Estimated total Feature 011 effort: ~1.5–2 dev-days.

### Feature 011 sequencing

Per 2026-07-01 Q2 clarification, Feature 011 is **NON-BLOCKING** for the `feature/issue-3 → main` cutover. `main` can merge with custom Query classes in place. Feature 011 tracked as a follow-up epic in a future branch (e.g. `specs/011-berlindb-query-migration/`) without a blocking date.

### Rationale for deferral

Custom Query classes work correctly (Phase 2 test coverage + PR #6 + PR #11 consumers exercise all 4 classes). Real-BerlinDB adoption is a maintenance improvement, not a functional requirement. Adopting mid-feature would inflate Feature 010's scope beyond its dependency-management + admin-menu-migration intent.

---

## References

- `/wp-content/plugins/acrossai-abilities-manager/composer.json` — canonical example of the composer.json shape
- `/wp-content/plugins/acrossai-abilities-manager/acrossai-abilities-manager.php` lines 82–154 — canonical example of the pre-activation vendor guard + shared parent bootstrap patterns
- `/wp-content/plugins/acrossai-abilities-manager/admin/Partials/Menu.php` lines 69–79 — canonical example of `add_submenu_page( 'acrossai', ... )` consumer registration
- `vendor/acrossai-co/main-menu/README.md` — package documentation
- `docs/memory/DECISIONS.md` D15 — Shared Package Bootstrap in Plugin Entry File (accepted A1 deviation) captured 2026-07-02
- `docs/memory/INDEX.md` DEV4 — accepted A1 deviation registration
- `docs/security-reviews/2026-07-02-010-composer-dependencies-plan.md` — standalone security review (LOW overall risk, 5 findings, 0 blocking)
