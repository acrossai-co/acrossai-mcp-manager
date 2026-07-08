# Feature Specification: Bump `acrossai-co/main-menu` to 0.0.13 and adopt tab-scoped Settings API

**Feature Branch**: `017-per-server-ability-selection` (piggy-backed — no separate branch)
**Created**: 2026-07-08
**Status**: Implemented (hotfix)
**Input**: User description: "Fatal `Call to undefined method AcrossAI_Main_Menu\SettingsPage::tab_page_slug()` on `admin_init`, then `The acrossai-settings-mcp options page is not in the allowed options list.` on Save. Ship the fix into the open 017 PR. Use the Spec Kit."

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site admin no longer sees a fatal on `admin_init` (Priority: P1)

A site administrator with **both** `acrossai-mcp-manager` and `acrossai-abilities-manager` active navigates to any wp-admin URL. Every admin page renders normally — no `Call to undefined method AcrossAI_Main_Menu\SettingsPage::tab_page_slug()` in `wp-content/debug.log`, no white-screen, no PHP fatal in `admin.php`'s error output.

**Why this priority**: The pre-fix state is a hard-fatal that breaks `admin_init` for the entire wp-admin. Every other AcrossAI feature is unreachable until this is patched. There is no P2 story.

**Independent Test**: On an install with both plugins active and the pre-fix build of `acrossai-mcp-manager`, reproduce the fatal by loading `/wp-admin/`. Apply the Feature 018 fix, reload the same URL, confirm the fatal is gone and the dashboard renders.

**Acceptance Scenarios**:

1. **Given** both `acrossai-mcp-manager` (Feature 018 build) and `acrossai-abilities-manager` are active, **When** an admin loads any wp-admin URL, **Then** the page renders with no PHP fatal and `debug.log` contains no `Call to undefined method` entry mentioning `SettingsPage::tab_page_slug`.
2. **Given** only `acrossai-mcp-manager` (Feature 018 build) is active, **When** an admin loads any wp-admin URL, **Then** the page renders with no PHP fatal — the fallback branch in `register_settings()` fires and reconstructs the tab-scoped slug directly.
3. **Given** the Feature 018 build is deployed, **When** grep is run against `admin/Partials/SettingsMenu.php` for the pattern `SettingsPage::tab_page_slug`, **Then** zero results are returned (the removed static call is gone).

---

### User Story 2 — Site admin can save the MCP tab settings (Priority: P1)

A site administrator navigates to Settings → AcrossAI → **MCP** tab, toggles either the "Enable CLI Connections" checkbox or the "Delete all data on uninstall" checkbox, clicks **Save Changes**, and sees the standard WordPress green success notice. The persisted option value round-trips correctly through `get_option()`.

**Why this priority**: The pre-fix save path returned WordPress's whitelist rejection error ("The acrossai-settings-mcp options page is not in the allowed options list.") for every attempted save on the MCP tab. Both settings on that tab were unreachable until the `register_setting()` `$option_group` argument aligned with the tab-scoped slug the tab form emits.

**Independent Test**: On the Feature 018 build, navigate to Settings → AcrossAI → MCP. Toggle "Enable CLI Connections" ON. Click Save Changes. Confirm the green success notice. Run `wp option get acrossai_mcp_npm_login_enabled` — expected `1`. Toggle OFF. Save. Confirm `wp option get acrossai_mcp_npm_login_enabled` returns `0` (or `false` / empty). Repeat with the uninstall toggle against `acrossai_mcp_uninstall_delete_data`.

**Acceptance Scenarios**:

1. **Given** an admin has just navigated to the MCP tab, **When** they toggle "Enable CLI Connections" and click Save Changes, **Then** the page reloads with the WordPress "Settings saved." success notice and `get_option('acrossai_mcp_npm_login_enabled')` returns the new value.
2. **Given** an admin toggles "Delete all data on uninstall" and clicks Save Changes, **When** the page reloads, **Then** the success notice appears and `get_option('acrossai_mcp_uninstall_delete_data')` returns `1` or `0` according to the checkbox state.
3. **Given** the Feature 018 build is deployed, **When** the MCP tab's form is submitted, **Then** the `option_page` hidden field POSTed to `options.php` equals `acrossai-settings-mcp`, and `options.php` accepts the submission (not "not in the allowed options list").
4. **Given** the MCP tab's Save flow, **When** invalid values are submitted, **Then** the existing sanitize callbacks (`rest_sanitize_boolean` for the npm toggle, `sanitize_uninstall_flag()` for the uninstall toggle) execute unchanged.

---

### User Story 3 — Sibling AcrossAI plugin's Settings tab continues to work (Priority: P1)

A site administrator navigates to any other tab on Settings → AcrossAI contributed by another AcrossAI plugin (e.g. the Abilities tab from `acrossai-abilities-manager`). That tab renders and saves exactly as it did before Feature 018 — this hotfix is confined to the MCP tab's registration site and does not affect any other tab's Settings API bindings.

**Why this priority**: The vendor bump also touches the shared class the sibling plugin uses. The spec must guard against accidental regression of an out-of-scope tab.

**Independent Test**: With both plugins active, navigate to each non-MCP tab on Settings → AcrossAI, toggle any setting, click Save Changes, confirm the success notice.

**Acceptance Scenarios**:

1. **Given** both plugins are active on the Feature 018 build, **When** an admin loads Settings → AcrossAI, **Then** every tab contributed by `acrossai_settings_tabs` (including MCP) appears in the nav bar with no PHP notice.
2. **Given** the admin saves a non-MCP tab's form, **When** the page reloads, **Then** the success notice appears and no whitelist error is emitted.

---

### Edge Cases

- **`SettingsPage::get_settings_renderer()` returns null.** Happens when another AcrossAI plugin has explicitly skipped the vendor bootstrap for this request (unusual — the renderer is populated by `SettingsPage::__construct()`, called during `admin_menu`). The fallback branch reconstructs the same tab-scoped slug via `SettingsPage::SETTINGS_SLUG . '-' . sanitize_key( self::TAB_SLUG )` — matches what `SettingsPageRenderer::tab_page_slug()` would have returned. `admin_init` stays non-fatal; save still works.
- **Only `acrossai-mcp-manager` active (not the sibling).** The `0.0.13` copy from this plugin's own `vendor/` is loaded. Behaviour identical to the both-active case for the MCP tab.
- **Legacy site with `acrossai_mcp_npm_login_enabled = "1"` persisted from the `0.0.11` era.** The option keys are unchanged; the persisted value round-trips normally through the new registration.
- **`option_page` tampered in the POST body.** WordPress's own `options.php` whitelist check rejects any `option_page` value not registered via `register_setting()`. Feature 018 does not weaken that check.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `composer.json` MUST pin `acrossai-co/main-menu` to `0.0.13` (exact version, not a caret range). `composer.lock` MUST record `0.0.13` for the package. `vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php` MUST exist after `composer update`.
- **FR-002**: `admin/Partials/SettingsMenu.php::register_settings()` MUST obtain the tab-scoped page slug via `\AcrossAI_Main_Menu\SettingsPage::get_settings_renderer()->tab_page_slug( self::TAB_SLUG )`.
- **FR-003**: When `get_settings_renderer()` returns null, `register_settings()` MUST fall back to `\AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG . '-' . sanitize_key( self::TAB_SLUG )`. The fallback keeps `admin_init` non-fatal and produces the identical slug string.
- **FR-004**: Both `register_setting()` calls in `register_settings()` MUST use the tab-scoped page slug (`$option_group = $page_slug`) as the first argument. No `register_setting()` call in this file may use the literal `'acrossai-settings'`.
- **FR-005**: The two `add_settings_section()` + two `add_settings_field()` calls MUST continue to use the same tab-scoped page slug as their `$page` argument (already correct pre-fix; Feature 018 does not regress it).
- **FR-006**: The class-level docblock (lines 1–14) and the `register_settings()` docblock MUST describe the `0.0.13` tab-scoped-`option_group` behaviour, not the removed shared-`'acrossai-settings'` behaviour.
- **FR-007**: Option keys registered — `acrossai_mcp_npm_login_enabled`, `acrossai_mcp_uninstall_delete_data` — MUST NOT be renamed. Default values MUST NOT change. Sanitize callbacks MUST NOT change.
- **FR-008**: The `TAB_SLUG` constant, `register_tab()` method, `render_npm_section_description()`, `render_npm_login_field()`, `sanitize_uninstall_flag()`, and `render_uninstall_field()` methods MUST NOT be edited except through the docblock touch-ups above.
- **FR-009**: No `class_exists()` guard MUST be introduced around `\AcrossAI_Main_Menu\SettingsPage`. The package is a hard-require via `composer.json`; a guard would silently mask future dependency breakage.
- **FR-010**: No `method_exists()` guard MUST be introduced around `get_settings_renderer()` or `tab_page_slug()`. Pinning `0.0.13` guarantees both are present; the fallback handles the null-return case only.
- **FR-011**: Post-patch grep `grep -n "'acrossai-settings'" admin/Partials/SettingsMenu.php` MUST return zero results.
- **FR-012**: Post-patch grep `grep -n "SettingsPage::tab_page_slug" admin/Partials/SettingsMenu.php` MUST return zero results.
- **FR-013**: The plugin's own version in `acrossai-mcp-manager.php` MUST NOT be bumped by this feature. No changelog entry is required — Feature 018 rides the 017 PR.
- **FR-014**: `composer phpcs` MUST report zero errors after the patch. `composer phpstan` MUST report zero errors at level 8 and MUST NOT add new baseline entries.

### Key Entities *(mandatory)*

Feature 018 is a compatibility patch — no new domain entities, tables, options, or REST routes are introduced. The two existing wp_options rows continue as-is:

| Option key | Type | Default | Sanitizer |
|---|---|---|---|
| `acrossai_mcp_npm_login_enabled` | bool | `false` | `rest_sanitize_boolean` |
| `acrossai_mcp_uninstall_delete_data` | int (0/1) | `0` | `SettingsMenu::sanitize_uninstall_flag()` |

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: `wp-content/debug.log` contains zero `Call to undefined method AcrossAI_Main_Menu\SettingsPage::tab_page_slug` entries during a 24-hour post-deploy soak on the developer's local install.
- **SC-002**: The MCP tab Save Changes flow returns the WordPress success notice on 5/5 attempts across both toggles.
- **SC-003**: The developer console + PHP error log during the MCP tab Save flow is clean (no PHP notice, no whitelist rejection).
- **SC-004**: Post-patch grep audits — `'acrossai-settings'` literal AND `SettingsPage::tab_page_slug` symbol — both return zero results under `admin/Partials/SettingsMenu.php`.
- **SC-005**: `composer phpcs` and `composer phpstan` gates PASS from a clean run against the working tree.
- **SC-006**: `composer.lock` records `acrossai-co/main-menu` at `0.0.13`.
- **SC-007**: `php -l admin/Partials/SettingsMenu.php` reports no syntax errors.
- **SC-008**: The sibling `acrossai-abilities-manager` Settings tab renders and saves on the same install with no regression introduced by this patch.
