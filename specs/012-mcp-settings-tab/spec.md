# Feature Specification: MCP Settings Tab on Shared AcrossAI Settings Page + CLI Auth Log Admin Page Removal

**Feature Branch**: `012-mcp-settings-tab`
**Created**: 2026-07-03
**Status**: Draft
**Input**: User description: "Register a new 'MCP' tab on the shared `?page=acrossai-settings` admin page owned by the `acrossai-co/main-menu` vendor package. The tab persists THREE toggles via the WordPress Settings API: `acrossai_mcp_npm_login_enabled` (boolean, default false, gates the front-end CLI login flow), `acrossai_mcp_claude_connectors_enabled` (boolean, default false, gates the experimental direct Claude Connectors mode), and `acrossai_mcp_uninstall_delete_data` (int 0/1, default 0, opts into destructive uninstall). Migrate the current `uninstall.php`'s unconditional OAuth-table drops behind this new opt-in gate so preserve-everything becomes the default on uninstall, matching the sibling `acrossai-abilities-manager` pattern verbatim. Follow the sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php` (Feature 038-onward) pattern verbatim for the class shape, filter hook, and Settings API call shape. Wire the class via `includes/Main.php` Loader per constitution A1. Delete the existing dead-stub `Settings::register_settings()` (an empty method with a TODO comment) plus its Loader wiring line — that stub IS this feature. Add `AdminPageSlugs::SETTINGS_TAB = 'mcp'` constant and extend `plugin_screen_ids()` with the shared page's screen ID for future asset enqueue per A9. Add PHPUnit tests locking the register_tab shape + the register_settings option-group binding. Also remove the CLI Auth Log admin submenu at `?page=acrossai_mcp_manager_cli_auth_log`: delete `admin/Partials/CliAuthLogListTable.php` entirely, delete the `add_submenu_page` block in `Menu.php`, delete the `AdminPageSlugs::CLI_AUTH_LOG` const + its 2 `plugin_screen_ids()` entries, and delete `Settings::render_cli_auth_log_page()` (with its `use ...\CliAuthLogListTable;` import). Preserve every file under `includes/Database/CliAuthLog/**` — the OAuth flow still uses the table + Query/Row/Recorder classes at runtime (Storage.php, BearerAuth.php, CliController.php, Recorder.php)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Site admin sees and configures MCP settings on the shared AcrossAI settings page (Priority: P1)

A site administrator navigates to the shared AcrossAI Settings page (`/wp-admin/admin.php?page=acrossai-settings`) — which is owned by the `acrossai-co/main-menu` vendor package. They see an "MCP" tab alongside any tabs registered by sibling AcrossAI plugins (e.g. the "Abilities" tab from `acrossai-abilities-manager`). Clicking the MCP tab reveals THREE sections — "npm / CLI Settings", "Claude Connectors Screen (Experimental)", and "Uninstall Settings" — each with a single checkbox toggle. The admin can enable or disable each toggle independently and click the shared "Save Changes" button once to persist all three states in a single round-trip. Reloading the page shows every checkbox in the state the admin last saved.

**Why this priority**: This is the entire user-visible surface of the feature. Without it, the plugin has no way for a site admin to control the CLI login flow, the direct Claude Connectors mode, or the uninstall-data-deletion opt-in. Every other user story depends on this baseline.

**Independent Test**: On a WordPress install with both `acrossai-mcp-manager` and `acrossai-main-menu` active, navigate to `?page=acrossai-settings`. Verify (a) an "MCP" tab is visible in the nav bar; (b) clicking it renders three sections with the exact titles above; (c) each section shows exactly one checkbox with the labeled toggle text; (d) toggling any one checkbox and clicking Save Changes persists it — reloading the page shows the checkbox still checked (or unchecked); (e) `wp option get acrossai_mcp_npm_login_enabled` (etc.) returns the value shown in the UI.

**Acceptance Scenarios**:

1. **Given** the plugin is active on a fresh install (no options set yet), **When** the admin navigates to `?page=acrossai-settings&tab=mcp`, **Then** all three checkboxes render UNCHECKED (defaults: `false`, `false`, `0`).
2. **Given** the admin checks "Allow CLI connections via npm / npx" and clicks Save Changes, **When** the page reloads, **Then** the checkbox is still checked AND `wp option get acrossai_mcp_npm_login_enabled` returns `1`.
3. **Given** two AcrossAI plugins are active (this one plus `acrossai-abilities-manager`), **When** the admin visits `?page=acrossai-settings`, **Then** both tabs are visible in priority order (Abilities at priority 10, MCP at priority 20 → Abilities left, MCP right).
4. **Given** the admin is on the MCP tab with unsaved changes to the CLI toggle, **When** they click the Abilities tab (without saving first), **Then** the CLI-toggle change is discarded (standard WP admin behavior — no auto-save).

---

### User Story 2 - Uninstall preserves user data by default (Priority: P1)

A site administrator uninstalls the plugin via the WordPress admin plugins screen WITHOUT having ticked the "Delete all data on uninstall" checkbox. After WordPress runs `uninstall.php`, all four plugin tables (`wp_acrossai_mcp_servers`, `wp_acrossai_mcp_cli_auth_logs`, `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_audit`) remain intact, every `acrossai_mcp_*` option value is unchanged in `wp_options`, and the daily `acrossai_mcp_oauth_cleanup` scheduled hook remains scheduled (until WordPress itself times it out via a separate cleanup path). Reactivating the plugin later restores full functionality against the preserved data — no phantom-version drift, no data loss, no duplicate rows.

**Why this priority**: WordPress best practice + WP.org Plugin Directory guideline 5 (never delete user data without explicit consent). The pre-Feature-012 `uninstall.php` unconditionally drops the two OAuth tables + their `db_version` options; that behavior is unsafe by modern WP standards and must move behind an opt-in gate before this feature ships. This story is the safety belt every uninstall path needs by default.

**Independent Test**: On a WordPress install with the plugin active + populated (at least one MCP server + one CLI auth log + one OAuth token in the four tables), verify the "Delete all data on uninstall" checkbox on the MCP settings tab is UNCHECKED (default). Deactivate + uninstall the plugin via `wp plugin uninstall acrossai-mcp-manager` (or the WP admin plugins screen). Verify (a) all four tables still exist via `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` — returns 4 rows; (b) `wp option list --search='acrossai_mcp_*'` returns the pre-uninstall options with unchanged values; (c) row counts in each table are unchanged from before uninstall.

**Acceptance Scenarios**:

1. **Given** the plugin is active + populated + "Delete all data on uninstall" is UNCHECKED, **When** the plugin is uninstalled, **Then** all four `wp_acrossai_mcp_*` tables remain intact with row counts unchanged.
2. **Given** the plugin is active + populated + "Delete all data on uninstall" is UNCHECKED, **When** the plugin is uninstalled, **Then** every `acrossai_mcp_*` option is preserved in `wp_options` with its original value.
3. **Given** the plugin is active + populated + "Delete all data on uninstall" is UNCHECKED, **When** the plugin is uninstalled, **Then** reactivating the plugin restores full functionality against the preserved data (no phantom-version drift per Feature 011 FR-018 guard).

---

### User Story 3 - Destructive uninstall wipes everything when explicitly opted in (Priority: P1)

A site administrator ticks the "Delete all data on uninstall" checkbox on the MCP settings tab, clicks Save Changes, then deactivates and uninstalls the plugin via the WP admin plugins screen. After `uninstall.php` runs, all four plugin tables are dropped, every `acrossai_mcp_*` option is deleted from `wp_options`, and the `acrossai_mcp_oauth_cleanup` scheduled hook is cleared. Reactivating the plugin from scratch produces a clean-install lifecycle — all four tables are recreated via Feature 011's `Table::instance()->maybe_upgrade()` calls, the default MCP server row is re-seeded, and all `db_version_key` options are re-stamped.

**Why this priority**: The opt-in path must actually do what the checkbox promises. Any partial teardown (drops some tables but not others; deletes some options but leaves orphans) breaks trust in the toggle. This story locks the destructive-teardown contract.

**Independent Test**: On a WordPress install with the plugin active + populated, tick the "Delete all data on uninstall" checkbox, click Save Changes, verify `wp option get acrossai_mcp_uninstall_delete_data` returns `1`. Deactivate + uninstall the plugin. Verify (a) `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` returns EMPTY; (b) `wp option list --search='acrossai_mcp_*'` returns EMPTY; (c) `wp cron event list --search='acrossai_mcp_oauth_cleanup'` returns EMPTY. Reactivate the plugin — Feature 011's activation path recreates all four tables + seeds the default row + stamps the four `db_version_key` options with no fatals.

**Acceptance Scenarios**:

1. **Given** the plugin is active + populated + "Delete all data on uninstall" is CHECKED (option value `1`), **When** the plugin is uninstalled, **Then** all four `wp_acrossai_mcp_*` tables are dropped.
2. **Given** the same preconditions, **When** the plugin is uninstalled, **Then** every option matching the pattern `acrossai_mcp_%` is deleted from `wp_options` — including the `acrossai_mcp_uninstall_delete_data` flag itself.
3. **Given** the same preconditions, **When** the plugin is uninstalled, **Then** the `acrossai_mcp_oauth_cleanup` scheduled hook is cleared from WP-Cron.
4. **Given** a destructive uninstall has completed, **When** the plugin is reactivated from scratch, **Then** Feature 011's activation path runs cleanly (all four tables created, default MCP server row seeded, all `db_version_key` options stamped) with no fatal errors.

---

### User Story 4 - CLI Auth Log admin submenu is removed (Priority: P2)

A site administrator who previously used the standalone "CLI Auth Log" admin submenu (at `?page=acrossai_mcp_manager_cli_auth_log`) finds that submenu no longer exists after this feature ships. Navigating directly to the old URL renders the standard WordPress "You do not have sufficient permissions" or "Sorry, you are not allowed to access this page" screen. The admin can still inspect the CLI auth log via `wp db query "SELECT * FROM wp_acrossai_mcp_cli_auth_logs ORDER BY created_at DESC LIMIT 20"` or (in a future feature) a per-server tab. All OAuth authentication flows continue to work — the underlying `wp_acrossai_mcp_cli_auth_logs` table + Query/Row/Recorder classes are still consumed at runtime by `includes/OAuth/Storage.php`, `includes/OAuth/BearerAuth.php`, `includes/REST/CliController.php`, and `includes/Database/CliAuthLog/Recorder.php`.

**Why this priority**: The CLI Auth Log page was a read-only WP_List_Table view over data that is inspectable via WP-CLI. Post-Feature-011, that standalone admin submenu duplicates an existing inspection path and adds no interactive/mutating capability. Removing it shrinks the admin menu footprint at the same time this feature expands it. This is P2 (not P1) because the primary user-facing surface (US1) does not depend on the removal — US4 can ship after US1..US3 if scheduling requires, but the current feature bundles them because both edits touch the same three files (`Menu.php`, `AdminPageSlugs.php`, `Settings.php`).

**Independent Test**: On a WordPress install with the plugin active, navigate to `/wp-admin/admin.php?page=acrossai_mcp_manager_cli_auth_log`. Verify (a) WordPress returns the "not allowed" / "not found" screen (page slug no longer registered); (b) the parent AcrossAI menu no longer has a "CLI Auth Log" entry; (c) `wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_cli_auth_logs"` still returns a count (table is preserved). Trigger a fresh OAuth CLI auth flow and verify a new row is written to the table + the `redeem_atomic` predicate succeeds.

**Acceptance Scenarios**:

1. **Given** the plugin is active after this feature ships, **When** a `manage_options` user navigates to `?page=acrossai_mcp_manager_cli_auth_log`, **Then** WordPress returns its standard "page not found" or "not allowed" screen.
2. **Given** the plugin is active, **When** an OAuth CLI auth flow completes (approve auth code via the frontend page, redeem via the REST token endpoint), **Then** the `wp_acrossai_mcp_cli_auth_logs` table receives a new row and its `completed_at` column is stamped via `CliAuthLog\Query::redeem_atomic()` (SEC-001 atomic-CAS from Feature 011 FR-006 still functional).
3. **Given** the plugin is active, **When** a developer runs `wp db query "SELECT id, user_id, status, created_at FROM wp_acrossai_mcp_cli_auth_logs ORDER BY created_at DESC LIMIT 20"`, **Then** the query succeeds (table still exists) — auth-log inspection remains available via WP-CLI.

---

### Edge Cases

- **Vendor package present but no other consumer registered a tab**: the MCP tab is the ONLY tab on the shared page. Save button + option persistence still works — the vendor's `PageRenderer` handles single-tab mode.
- **Vendor package absent** (should not happen because `acrossai-co/main-menu` is a hard require in composer.json per Feature 010 FR-030): if somehow the package is missing at admin_init, `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug()` will fatal. This is intentional — the sibling `acrossai-abilities-manager` calls the helper unconditionally at line 109 too, and the priority-1 activation guard already `wp_die`s if the vendor autoloader is missing, so the runtime path is unreachable.
- **Option key already set with a non-boolean value** (e.g., someone `wp option update acrossai_mcp_npm_login_enabled "hello"`): `rest_sanitize_boolean` coerces on save; on read, `(bool) get_option( ..., false )` in the render method coerces to `false`. No fatal, no silent data corruption.
- **Uninstall flag persists across deactivate + reactivate**: if the admin ticks the box, then deactivates the plugin (without uninstalling), the option remains stamped. Reactivating and immediately uninstalling triggers the destructive teardown as expected. Standard WP behavior.
- **Uninstall with the flag stamped `1` but tables already dropped** (e.g., the admin ran manual `DROP TABLE` first): `DROP TABLE IF EXISTS` handles the missing tables silently. The LIKE-sweep still deletes every remaining `acrossai_mcp_*` option.
- **A future feature adds new `acrossai_mcp_*` options**: they are covered by the destructive-uninstall LIKE-sweep automatically — no manual allow-list maintenance required.
- **A future feature adds new `wp_acrossai_mcp_*` tables**: the destructive-uninstall table list is HARDCODED to the four Feature-011 tables. A new table MUST be added to that list in the same PR that adds the table (constraint captured in this feature's CONSTRAINTS block).
- **CLI Auth Log page URL bookmarked by an operator**: the old URL returns the WP "not allowed" screen. Operators are expected to update bookmarks; the removal is announced in the changelog + memory hygiene decisions.
- **A rogue caller-side edit tries to `use CliAuthLogListTable` after this feature ships**: PHPStan L8 catches the undefined class reference; PHPCS catches the unresolved use import. The pre-flight-grep merge gate catches any resurrection of the removed page-slug string.

---

## Requirements *(mandatory)*

### Functional Requirements

**Tab registration (MCP Settings surface)**

- **FR-001**: A new class `AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu` MUST register a tab entry `['slug' => 'mcp', 'label' => __('MCP', 'acrossai-mcp-manager'), 'priority' => 20]` via the `acrossai_settings_tabs` filter provided by the `acrossai-co/main-menu` vendor package.
- **FR-002**: The `register_tab` callback MUST normalize non-array input (`null`, `false`, `'string'`, etc.) by resetting to `array()` before appending the tab entry — matching the sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php:84-86` guard verbatim.
- **FR-003**: The tab priority `20` MUST place the MCP tab AFTER any sibling `acrossai-abilities-manager` "Abilities" tab (priority 10), so multi-plugin installs show a stable, family-consistent tab order.

**Option persistence**

- **FR-004**: The class MUST register three options under the shared `'acrossai-settings'` option group via `register_setting()`, so the vendor's `PageRenderer::render()` (which emits `settings_fields('acrossai-settings')`) routes all three through `options.php` on a single Save round-trip:
  - `acrossai_mcp_npm_login_enabled` (boolean, default `false`, sanitize `'rest_sanitize_boolean'`)
  - `acrossai_mcp_claude_connectors_enabled` (boolean, default `false`, sanitize `'rest_sanitize_boolean'`)
  - `acrossai_mcp_uninstall_delete_data` (int 0/1, default `0`, sanitize `array( $this, 'sanitize_uninstall_flag' )` returning `empty( $value ) ? 0 : 1`)
- **FR-005**: The `sanitize_uninstall_flag` method MUST return `0` for any empty/falsy input (unchecked checkbox → not present in `$_POST` → empty) and `1` for any truthy input, matching the sibling pattern at `acrossai-abilities-manager/admin/Partials/SettingsMenu.php:202-204`.
- **FR-006**: Every `add_settings_section()` and `add_settings_field()` call MUST target `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug( SettingsMenu::TAB_SLUG )` as the `$page` argument, so the sections render under the MCP tab (not the flat page) per the vendor's tabbed-mode contract.

**Section + field rendering**

- **FR-007**: The MCP tab MUST render three sections in this order: "npm / CLI Settings", "Claude Connectors Screen (Experimental)", "Uninstall Settings". Each section title MUST be internationalized using text domain `'acrossai-mcp-manager'`.
- **FR-008**: The "npm / CLI Settings" section MUST display: (a) a description paragraph explaining the toggle purpose; (b) a warning-notice banner containing the frontend CLI auth URL (obtained from `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()`) and a "do not cache" advisory; (c) a single checkbox field labelled "Allow CLI connections via npm / npx" for the `acrossai_mcp_npm_login_enabled` option.
- **FR-009**: The "Claude Connectors Screen (Experimental)" section MUST display: (a) a description paragraph explaining the experimental mode; (b) an info-notice banner reminding operators to save per-server credentials on the Claude Connector tab; (c) a warning-notice banner listing three OAuth URLs (Authorization server metadata / Authorize URL / Token endpoint) with a "do not cache" advisory; (d) a single checkbox field labelled "Enable direct Claude Connectors mode" for the `acrossai_mcp_claude_connectors_enabled` option.
- **FR-010**: The "Uninstall Settings" section MUST display a single checkbox field labelled "Delete all data on uninstall" for the `acrossai_mcp_uninstall_delete_data` option, with a `⚠ Warning:` description that reads "When checked, uninstalling this plugin will permanently delete all custom database tables and plugin options. This cannot be undone." The warning MUST be rendered in the `#d63638` red color used by the sibling plugin at line 215.
- **FR-011**: The three OAuth URLs displayed in the Claude Connectors section MUST be derived inline via `home_url( '/.well-known/oauth-authorization-server' )` (authorization-server metadata), `home_url( '/acrossai-mcp-connectors/oauth/authorize/' )` (authorize URL), and `rest_url( 'acrossai-mcp-manager/v1/connector/oauth/token' )` (token endpoint). These URL shapes describe the future Claude Connectors OAuth surface — distinct from the CLI-focused OAuth flow served by `includes/OAuth/ClaudeConnectors.php:132-134` (which uses `home_url('/acrossai-mcp-oauth/')` + `rest_url('acrossai-mcp/v1/token')` for the CLI/npm path). No new helper methods on `ClaudeConnectors` are added in this feature; the marker `TODO(follow-up)` in the render method calls out that a future Connector OAuth surface feature will register the routes and own its URL helpers.
- **FR-012**: Every output value in every render method MUST pass through the most-specific WordPress escape function at the point of rendering: `esc_html()` / `esc_url()` for plain strings; `wp_kses_post()` for translated strings containing HTML tags with `esc_html()` on the substituted values.

**Hook wiring (Constitution A1)**

- **FR-013**: `SettingsMenu` MUST NOT call `add_action()` or `add_filter()` inside its class body. Hook registration MUST live in `includes/Main.php::define_admin_hooks()` via the plugin's Loader, per Constitution A1.
- **FR-014**: `Main::define_admin_hooks()` MUST wire `SettingsMenu::register_tab` to the `acrossai_settings_tabs` filter and `SettingsMenu::register_settings` to the `admin_init` action.
- **FR-015**: The dead-stub `AcrossAI_MCP_Manager\Admin\Partials\Settings::register_settings()` method (currently empty with a "US3 T020 ports the full register_setting..." TODO comment) MUST be deleted along with its Loader wiring line in `Main.php`. That stub IS the target of this feature; leaving it would create two conflicting entry points for settings registration.

**Constants + screen-ID whitelist**

- **FR-016**: `AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs` MUST expose a new constant `public const SETTINGS_TAB = 'mcp';` kept in sync with `SettingsMenu::TAB_SLUG`.
- **FR-017**: `AdminPageSlugs::plugin_screen_ids()` MUST additively include the shared settings page's WordPress screen ID `'acrossai_page_acrossai-settings'` (derived from the "AcrossAI" parent menu title per Feature 010 A9 whitelist pattern), so future features can enqueue admin assets on the MCP settings tab.
- **FR-018**: `AdminPageSlugs::plugin_screen_ids()` MUST NOT remove any existing screen-ID entry EXCEPT the two entries that reference the removed `CLI_AUTH_LOG` constant (per FR-024 below). A9 canonical-whitelist rule remains additive-only for every OTHER entry.

**Uninstall.php gate**

- **FR-019**: `uninstall.php` MUST early-return without side-effects when `(int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) !== 1`. The gate MUST appear at the top of the file, immediately after the `WP_UNINSTALL_PLUGIN` constant check. Every destructive operation in the file MUST be after this gate.
- **FR-020**: When the gate passes (option value is `1`), `uninstall.php` MUST execute in this order: (a) drop all four `wp_acrossai_mcp_*` tables via `$wpdb->query( "DROP TABLE IF EXISTS ..." )`; (b) LIKE-sweep delete every `acrossai_mcp_%` option via a single `$wpdb->prepare()` on `wp_options` followed by `delete_option()` in a loop; (c) clear the `acrossai_mcp_oauth_cleanup` scheduled hook via `wp_clear_scheduled_hook()`.
- **FR-021**: The four `wp_acrossai_mcp_*` tables MUST be enumerated in a hardcoded PHP array (not derived from `SHOW TABLES LIKE`) so any table not in the array survives an accidental introduction. The four table stems are: `acrossai_mcp_servers`, `acrossai_mcp_cli_auth_logs`, `acrossai_mcp_oauth_tokens`, `acrossai_mcp_oauth_audit` (per Feature 011).
- **FR-022**: The LIKE-sweep on options MUST use `$wpdb->prepare()` with the exact pattern `'acrossai_mcp_%'` — future features that introduce options under this prefix (including the four `db_version_key` options, the three Settings-API options this feature registers, and any future flags) are covered automatically without maintaining a hand-rolled allow-list.
- **FR-023**: The behavior change vs the pre-Feature-012 `uninstall.php` (which unconditionally dropped the two OAuth tables + their `db_version` options + the cron hook per Feature 006 "destructive-by-nature" scope) MUST be documented in the Unreleased changelog. Sites that expected the OAuth-table wipe must tick the new checkbox before uninstalling to restore the old behavior.

**CLI Auth Log admin surface removal**

- **FR-024**: `admin/Partials/CliAuthLogListTable.php` MUST be deleted in full. The class is only consumed by the deleted render method in `Settings.php` and has no other callers.
- **FR-025**: The `add_submenu_page(...)` block that registers the CLI Auth Log page at position 3 in `admin/Partials/Menu.php` MUST be deleted. The associated docblock reference to "Position 3 — CLI Auth Log" MUST be removed. The two remaining positions (2 = MCP main; 4 = Access Control, conditional) stay unchanged.
- **FR-026**: `AdminPageSlugs::CLI_AUTH_LOG` constant + its docblock MUST be deleted. Both `plugin_screen_ids()` entries that reference the constant (`'acrossai_page_' . self::CLI_AUTH_LOG` and `'mcp-manager_page_' . self::CLI_AUTH_LOG`) MUST be deleted from the return array.
- **FR-027**: `Settings::render_cli_auth_log_page()` method + its surrounding docblock MUST be deleted. The `use AcrossAI_MCP_Manager\Admin\Partials\CliAuthLogListTable;` import at the top of `Settings.php` MUST be deleted. Every other method + every other import in `Settings.php` stays intact.
- **FR-028**: Every file under `includes/Database/CliAuthLog/**` (Table.php, Schema.php, Query.php, Row.php, Recorder.php — 5 files) MUST BE PRESERVED VERBATIM. These are consumed at runtime by `includes/OAuth/Storage.php`, `includes/OAuth/BearerAuth.php`, `includes/REST/CliController.php`, and `includes/Database/CliAuthLog/Recorder.php`. Deletion would break OAuth token exchange (`redeem_atomic` SEC-001 atomic-CAS) and the auth-log audit trail.

**Memory hygiene**

- **FR-029**: `docs/memory/DECISIONS.md` MUST gain three new Active decisions: `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` (canonical pattern for consuming the vendor tab API), `DEC-UNINSTALL-OPT-IN-GATE` (preserve-by-default with opt-in destructive teardown), and `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` (rule for pruning read-only inspection admin surfaces).
- **FR-030**: `docs/memory/INDEX.md` MUST gain matching rows for the three new decisions plus a WORKLOG row for Feature 012.
- **FR-031**: `docs/memory/WORKLOG.md` MUST gain a Feature 012 milestone entry capturing the durable lesson: **when consuming a vendor package's shared settings surface, the `option_group` argument to `register_setting()` MUST match the vendor's own `settings_fields()` call — not the per-tab page slug — or Save silently no-ops with no operator-visible error**.
- **FR-032**: `README.txt` MUST gain three Unreleased changelog bullets covering: (a) the new MCP tab + its three toggles, (b) the uninstall-default behavior change, (c) the CLI Auth Log admin submenu removal.
- **FR-033**: `docs/planings-tasks/README.md` MUST list `012-mcp-settings-tab.md` alongside the existing Feature 011 row.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (unchanged from Feature 011).
**WordPress Version**: 6.9+ (unchanged).
**Multisite**: Single-site only. No new multisite scope introduced.
**Required Plugins / Packages**: `acrossai-co/main-menu 0.0.10+` (already installed via Feature 010). No new composer dependencies.
**Optional Integrations**: None introduced by this feature.

### Module Placement

**PHP Class(es)**:
- `admin/Partials/SettingsMenu.php` → **NEW**; namespace `AcrossAI_MCP_Manager\Admin\Partials`. Singleton with `$instance` var + `instance()` method + private `__construct()`. Contains `register_tab()`, `register_settings()`, `sanitize_uninstall_flag()`, and 6 render methods.
- `admin/Partials/Settings.php` → delta edits only: delete stub `register_settings()` method + `render_cli_auth_log_page()` method + `use ...CliAuthLogListTable` import.
- `admin/Partials/CliAuthLogListTable.php` → **DELETE**.
- `admin/Partials/Menu.php` → delta edit: delete the `add_submenu_page` block for position 3 + update docblock.
- `includes/Utilities/AdminPageSlugs.php` → delta edits: add `SETTINGS_TAB` const; delete `CLI_AUTH_LOG` const; extend `plugin_screen_ids()` with the new shared-page screen ID; delete the 2 whitelist entries that referenced `CLI_AUTH_LOG`.
- `includes/Main.php` → delta edits inside `define_admin_hooks()`: delete the Loader line wiring `Settings::register_settings`; add 3 Loader lines wiring `SettingsMenu::register_tab` + `SettingsMenu::register_settings`.
- `uninstall.php` → full rewrite behind the new opt-in gate.

**Hook Registration**: All hook wiring lives in `includes/Main.php` via the Loader per constitution A1. `SettingsMenu` itself contains ZERO `add_action` / `add_filter` calls in its class body.

### Admin UI Requirements

**MCP tab on the shared AcrossAI Settings page** (`?page=acrossai-settings&tab=mcp`):
- Rendered by the vendor `acrossai-co/main-menu` package's `PageRenderer::render()` method against the WordPress Settings API — NOT via DataForm/DataViews.
- This is an accepted DEV carve-out from constitution §IV DataForm mandate because the shared settings surface is owned by the vendor package's PageRenderer, not by this plugin. If the vendor package migrates to DataForm in a future release, all consumer plugins (including this one) migrate with it.
- Fields render via `printf('<html>%s%s', esc_*(...))` inline — matching the sibling `acrossai-abilities-manager` idiom at lines 183-190 and 212-220.

**No other admin UI surface** is added or modified.

### REST API Contract

This feature has no REST route surface. Existing REST routes under `includes/REST/CliController.php` are unchanged (they consume `CliAuthLog\Query::instance()` at runtime — a DB-layer dependency preserved by FR-028).

### Database / Storage

**No new tables**. The three new options land in `wp_options` under the shared `'acrossai-settings'` option group.

| Option name | Type | Default | Sanitize | Source of truth |
|---|---|---|---|---|
| `acrossai_mcp_npm_login_enabled` | boolean | `false` | `rest_sanitize_boolean` | `SettingsMenu::render_npm_login_field()` write path; consumed by `FrontendAuth` gate |
| `acrossai_mcp_claude_connectors_enabled` | boolean | `false` | `rest_sanitize_boolean` | `SettingsMenu::render_claude_connectors_enabled_field()` write path; consumed by `ClaudeConnectors` gate |
| `acrossai_mcp_uninstall_delete_data` | int 0/1 | `0` | `SettingsMenu::sanitize_uninstall_flag()` (`empty($value) ? 0 : 1`) | `SettingsMenu::render_uninstall_field()` write path; consumed by `uninstall.php` gate (FR-019) |

### Security Checklist

*(Verifies this feature does not regress the plugin's security posture.)*

- [ ] Every `__()` / `_e()` / `esc_html__()` call uses text domain `'acrossai-mcp-manager'` — constitution §II
- [ ] Every dynamic output value passes through the most-specific WP escape function at rendering point — constitution §III + FR-012
- [ ] All three `register_setting()` calls use `sanitize_callback` — FR-004, FR-005
- [ ] No new REST route, no new nonce surface, no new capability check needed — the vendor `PageRenderer` handles nonce + `options.php` handoff
- [ ] `uninstall.php` uses `$wpdb->prepare()` for the options LIKE-sweep — constitution §III S4
- [ ] `uninstall.php`'s `DROP TABLE IF EXISTS` loop scopes the `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` PHPCS ignore only to that loop — `$table` is derived from `$wpdb->prefix` + hardcoded strings, no user input
- [ ] `uninstall.php` gate blocks EVERY destructive operation — including any future additions per constraint

### Key Entities

- **MCP Settings Tab**: The user-facing surface rendered at `?page=acrossai-settings&tab=mcp`. Composed of three sections + three checkboxes bound to three options.
- **Uninstall Opt-In Flag**: The `acrossai_mcp_uninstall_delete_data` option. Value `1` = destructive teardown on uninstall; value `0` (default) = preserve everything.
- **CliAuthLog DB Layer** (preserved, not part of this feature's surface): Five files under `includes/Database/CliAuthLog/` (Table, Schema, Query, Row, Recorder) that continue to persist auth-log rows for OAuth token exchange.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`) on all touched files
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan analyse --level=8`) on all touched files
- [ ] PHPUnit tests written and passing for `SettingsMenu` (`tests/phpunit/Admin/SettingsMenuTest.php`): at minimum (a) `register_tab` shape assertion, (b) `register_tab` non-array normalization, (c) `register_settings` option-group binding assertion
- [ ] Constitution §VII per-task DoD gates pass on every task before the next task begins
- [ ] Pre-flight grep for the three option keys returns hits ONLY in `SettingsMenu.php` (writer), `uninstall.php` (reader/gate for the uninstall flag), and downstream consumer files (`FrontendAuth` / `ClaudeConnectors` gates) — no orphans
- [ ] Post-TASK-6 grep for `acrossai_mcp_manager_cli_auth_log|CliAuthLogListTable|CLI_AUTH_LOG|render_cli_auth_log_page` returns **zero hits** in the plugin PHP surface
- [ ] Companion grep for the CliAuthLog DB layer (`CliAuthLogQuery`, `CliAuthLogRow`, `CliAuthLog\Table`, `CliAuthLog\Recorder`, `use ...CliAuthLog\`) returns the **same non-zero hit count** as before TASK-6 (proves DB-layer preservation)
- [ ] All existing PHPUnit tests continue to pass — no test file is deleted, no test invariant is regressed
- [ ] `admin/Partials/SettingsMenu.php` file is saved as UTF-8 without BOM (verifies the `⚠` character in the uninstall warning renders correctly)

### Measurable Outcomes

- **SC-001**: A site admin on a fresh install can navigate to `?page=acrossai-settings`, click the MCP tab, tick any of the three checkboxes, click Save Changes once, and see the checked state persist across a page reload. Verifiable in under 60 seconds on a local WP install.
- **SC-002**: With both `acrossai-mcp-manager` and `acrossai-abilities-manager` active, the shared settings page renders BOTH the "Abilities" tab (priority 10) and the "MCP" tab (priority 20) in stable priority order — Abilities left, MCP right.
- **SC-003**: On a populated install with the uninstall opt-in UNCHECKED, uninstalling the plugin leaves all four `wp_acrossai_mcp_*` tables + every `acrossai_mcp_*` option intact — verified via `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` (returns 4 rows) and `wp option list --search='acrossai_mcp_*'` (returns pre-uninstall options).
- **SC-004**: On a populated install with the uninstall opt-in CHECKED, uninstalling the plugin drops all four tables + deletes every `acrossai_mcp_*` option + clears the `acrossai_mcp_oauth_cleanup` cron hook — verified via the same three WP-CLI commands returning EMPTY.
- **SC-005**: After this feature ships, `?page=acrossai_mcp_manager_cli_auth_log` returns the WP "not allowed" / "not found" screen for `manage_options` users — the standalone CLI Auth Log admin submenu is gone.
- **SC-006**: After this feature ships, an OAuth CLI auth flow round-trip (approve auth code via frontend + redeem via REST token endpoint) still succeeds and stamps `completed_at` on the CliAuthLog row via `redeem_atomic()` — SEC-001 atomic-CAS from Feature 011 FR-006 unbroken.
- **SC-007**: PHPUnit test `SettingsMenuTest::test_register_tab_appends_expected_shape` passes on a first-time run against the completed implementation, proving the tab is registered with `['slug' => 'mcp', 'label' => 'MCP', 'priority' => 20]`.

---

## Assumptions

- The composer package `acrossai-co/main-menu` v0.0.10+ is installed and its `\AcrossAI_Main_Menu\SettingsPage` class is loadable at `admin_init` (Feature 010 established this as a hard require + priority-1 pre-activation vendor autoload guard).
- The vendor package's `PageRenderer::render()` continues to emit `settings_fields( 'acrossai-settings' )` — this shared option group is what makes cross-tab Save persistence work. If a future vendor release changes this, `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` must be re-evaluated.
- The sibling plugin `acrossai-abilities-manager` at `../acrossai-abilities-manager/admin/Partials/SettingsMenu.php:1-221` is the canonical reference implementation for the class shape + member ordering + Settings API call idiom.
- `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()` exists at `public/Partials/FrontendAuth.php:76` — verified present.
- The three OAuth URL helpers on `ClaudeConnectors` (`get_authorization_server_metadata_url`, `get_authorize_url`, `get_token_endpoint_url`) DO NOT exist in this plugin's post-Feature-011 namespace. Inline `home_url()` / `rest_url()` fallbacks are used in TASK-1, marked with `TODO(follow-up)` — extraction is deferred to the future Claude Connectors OAuth surface feature that will register the `acrossai-mcp-connectors/oauth/authorize/` rewrite + `acrossai-mcp-manager/v1/connector/oauth/token` REST route and own the matching URL helpers.
- No live production install currently depends on the pre-Feature-012 unconditional OAuth-table drop on uninstall. Sites that expected the wipe learn about the new checkbox via the Unreleased changelog (FR-023, FR-032).
- No live production install currently depends on the standalone "CLI Auth Log" admin submenu. Auth-log inspection was always available via `wp db query` on the underlying table; the removal is a footprint-reduction rather than a functional loss.
- Auto-log inspection via WP-CLI or a future per-server tab is a follow-up feature — this feature ships the removal only.
