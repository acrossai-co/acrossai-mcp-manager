---
description: "Task list for Feature 012 — MCP Settings Tab on Shared AcrossAI Settings Page + CLI Auth Log Admin Page Removal"
---

# Tasks: MCP Settings Tab on Shared AcrossAI Settings Page + CLI Auth Log Admin Page Removal (Feature 012)

**Input**: Design documents from `specs/012-mcp-settings-tab/`
**Prerequisites**: `spec.md`, `plan.md`, `memory-synthesis.md`, `security-constraints.md`, `architecture-violations.md`, `docs/security-reviews/2026-07-03-012-mcp-settings-tab-plan.md`, `docs/planings-tasks/012-mcp-settings-tab.md`

**Tests**: The plan mandates PHPUnit coverage (spec TASK-4, plan §Task Groups T4). Security review SEC-012-005 (optional additional test for uninstall preserve-by-default) is folded into US2 as an optional task. Manual smoke tests are required by spec TASK-5 DoD + spec User Story acceptance scenarios.

**Organization**: Tasks are grouped by user story (US1, US2, US3, US4) per spec.md. Setup precedes all stories; Polish follows.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (`US1`, `US2`, `US3`, or `US4`); Setup/Polish tasks have no story label

## Path Conventions

- Plugin root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`
- All paths below are relative to the plugin root unless otherwise noted
- PHP source under `admin/`, `includes/`, `public/`, `acrossai-mcp-manager.php`, `uninstall.php`
- PHPUnit tests under `tests/phpunit/`

## Constitution §VII per-task gate (applies to EVERY task below)

Before marking any task complete, run:
- `vendor/bin/phpcs` — zero errors, zero warnings on touched files
- `vendor/bin/phpstan analyse --level=8` — zero errors on touched files
- Any grep gate explicitly named in the task description

A task is not "done" until its DoD line is green.

---

## Phase 1: Setup (Pre-flight snapshot & test-harness readiness)

**Purpose**: Capture the pre-migration reference state + verify the PHPUnit test harness covers the new subdir.

- [ ] T001 [P] Capture the pre-flight CLI Auth Log symbol snapshot to `specs/012-mcp-settings-tab/pre-flight-cli-auth-log.txt` by running:
  ```
  grep -rEn "acrossai_mcp_manager_cli_auth_log|CliAuthLogListTable|CLI_AUTH_LOG|render_cli_auth_log_page" \
      --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php > specs/012-mcp-settings-tab/pre-flight-cli-auth-log.txt
  ```
  Also capture the companion CliAuthLog DB-layer snapshot to `specs/012-mcp-settings-tab/pre-flight-cli-auth-log-db-layer.txt`:
  ```
  grep -rEn "CliAuthLogQuery|CliAuthLogRow|CliAuthLog\\\\Table|CliAuthLog\\\\Recorder|use .*CliAuthLog\\\\" \
      --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php > specs/012-mcp-settings-tab/pre-flight-cli-auth-log-db-layer.txt
  ```
  **DoD**: both files exist, are non-empty, and the DB-layer snapshot shows hits in each of `Storage.php`, `BearerAuth.php`, `CliController.php`, `Recorder.php`, `Activator.php`, `Main.php`.

- [ ] T002 [P] Verify `tests/bootstrap.php` + `tests/bootstrap-wp.php` autoload cover the new `tests/phpunit/Admin/` subdirectory. If not covered, extend the bootstrap to include the new subdir under PSR-4 autoload (or WP-native PHPUnit test discovery — whichever this plugin uses). **DoD**: create a throwaway `tests/phpunit/Admin/BootstrapProbeTest.php` with a single `test_probe(): void` asserting `true`; run `vendor/bin/phpunit tests/phpunit/Admin/BootstrapProbeTest.php`; delete the probe file after verification.

---

## Phase 2: User Story 1 — MCP Settings Tab on Shared Page (Priority: P1) 🎯 MVP

**Goal**: A site administrator navigates to `?page=acrossai-settings`, sees an "MCP" tab, clicks it to reveal three sections (npm / CLI Settings, Claude Connectors Screen (Experimental), Uninstall Settings), each with a single checkbox. Save Changes persists all three toggles in one round-trip.

**Independent Test**: On a WordPress install with both `acrossai-mcp-manager` and `acrossai-main-menu` active, navigate to `/wp-admin/admin.php?page=acrossai-settings`. Verify (a) MCP tab visible; (b) three sections render with the correct titles + warning banners + URLs; (c) toggle each checkbox + click Save Changes → reload → state persists; (d) `wp option get acrossai_mcp_npm_login_enabled` returns the value shown in the UI.

### Implementation for User Story 1

**Class creation + wiring (T003–T007)** — sequential; each depends on the prior.

- [ ] T003 [US1] Full write: `admin/Partials/SettingsMenu.php` (NEW file). Namespace `AcrossAI_MCP_Manager\Admin\Partials`. Non-final `class SettingsMenu {`. Sibling-style member ordering: `protected static $instance = null;` → `public static function instance(): self` → `private function __construct() {}` → `public const TAB_SLUG = 'mcp';` → `public function register_tab( $tabs ): array` (with `is_array()` normalization guard verbatim from sibling lines 84-86) → `public function register_settings(): void` (unconditional `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG )` call — NO `class_exists()` guard per spec CONSTRAINTS + FR-013 + SEC-012-004 re-evaluation trigger: if the vendor package is ever demoted from hard-require to optional integration, DEC-VENDOR-SETTINGS-TAB-INTEGRATION must be re-evaluated and the guard added) → 4 render methods (`render_npm_section_description`, `render_npm_login_field`, `render_claude_connectors_section_description`, `render_claude_connectors_enabled_field`) → `sanitize_uninstall_flag( $value ): int` (returns `empty( $value ) ? 0 : 1` per sibling lines 202-204) → `render_uninstall_field(): void` (`⚠ Warning:` text + `#d63638` red per sibling lines 212-220). `register_settings()` calls three `register_setting( 'acrossai-settings', ..., [ 'sanitize_callback' => ..., 'default' => ... ] )` (2 booleans with `rest_sanitize_boolean` + 1 int with `sanitize_uninstall_flag`), three `add_settings_section( ..., $page_slug )`, three `add_settings_field( ..., $page_slug, ... )` where `$page_slug = \AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG );`. Render methods use `printf( wp_kses_post( __( '<html>%s</code>', ... ) ), esc_url( $val ) )` for URL-containing HTML notices per SEC-012-008 (**prefer `esc_url()` over `esc_html()` for URL substitutions**). URL sources: `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url()` for npm section + inline `home_url()` / `rest_url()` fallbacks for Claude Connectors section per spec FR-011 with `// TODO(follow-up): promote to static helpers on ClaudeConnectors` comment. File encoding MUST be UTF-8 without BOM (verifies `⚠` character renders correctly per spec DoD). **DoD**: `php -l admin/Partials/SettingsMenu.php` clean; PHPStan L8 + PHPCS zero errors/warnings; class parses.

- [ ] T004 [US1] Delta edit: `admin/Partials/Settings.php`. Delete the stub `register_settings(): void` method + its section comment (~lines 406-413 per plan). Grep-verify after: `grep -n 'register_settings' admin/Partials/Settings.php` returns zero lines. **DoD**: PHPStan L8 + PHPCS green on `Settings.php`.

- [ ] T005 [US1] Delta edit: `includes/Main.php`. Inside `define_admin_hooks()`: (a) **DELETE** the dead Loader line `$this->loader->add_action( 'admin_init', $settings, 'register_settings' );`; (b) **ADD** three new Loader lines immediately AFTER the remaining `$this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );` line — but **BEFORE** the end of `define_admin_hooks()` — using the exact FQN pattern:
  ```php
  $settings_menu = \AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu::instance();
  $this->loader->add_filter( 'acrossai_settings_tabs', $settings_menu, 'register_tab' );
  $this->loader->add_action( 'admin_init', $settings_menu, 'register_settings' );
  ```
  **CRITICAL Loader-order gate** (per DEC-BERLINDB-TABLE-REQUEST-BOOT compat, architecture-violations.md T2 gate line): verify that `Main::load_hooks()`'s `bootstrap_database_tables()` call from Feature 011 is called BEFORE `define_admin_hooks()` — since both happen in `Main::__construct()` flow, this ordering is naturally preserved by the existing `load_dependencies()` → `load_hooks()` sequence in the constructor. Also extend the docblock above the Settings wiring to explain the Settings-vs-SettingsMenu split; remove the "US3 T020" TODO reference from the existing docblock. **DoD**: PHPStan L8 + PHPCS green on `Main.php`; `grep -n 'register_tab\|SettingsMenu' includes/Main.php` shows the 3 new lines.

- [ ] T006 [US1] Delta edit: `includes/Utilities/AdminPageSlugs.php`. (a) **ADD** a new class constant after the existing `CLI_AUTH_LOG` const (~line 31 in the current file — note: this const is later deleted in T014, but adds land before deletes to preserve grep-verifiable state): `/** Shared settings page tab slug (kept in sync with SettingsMenu::TAB_SLUG). */ public const SETTINGS_TAB = 'mcp';`. (b) **EXTEND** `plugin_screen_ids()` return array with one new entry: `'acrossai_page_acrossai-settings',` — matching the `acrossai_page_` prefix pattern from Feature 010 (WordPress derives it from the parent menu title `AcrossAI`). Do NOT touch any other constant or whitelist entry in this task (T014 handles the subtractive removals for CLI Auth Log). **DoD**: `grep 'SETTINGS_TAB' includes/Utilities/AdminPageSlugs.php` returns 1 hit; `grep 'acrossai_page_acrossai-settings' includes/Utilities/AdminPageSlugs.php` returns 1 hit; PHPStan L8 + PHPCS green.

- [ ] T007 [P] [US1] Create NEW file: `tests/phpunit/Admin/SettingsMenuTest.php`. Extend `WP_UnitTestCase`. Three test methods per spec TASK-4:
  1. `test_register_tab_appends_expected_shape`: `$result = SettingsMenu::instance()->register_tab( array() );` → assert count 1 + slug `'mcp'` + label `'MCP'` + priority `20`.
  2. `test_register_tab_normalizes_non_array_input`: pass `null`, `false`, `'string'` in turn (may use `#[DataProvider]` PHP attribute per BUGS.md B9) — each MUST return a 1-element array with the expected tab entry.
  3. `test_register_settings_registers_expected_option_keys`: invoke `SettingsMenu::instance()->register_settings()`, then assert `$wp_registered_settings['acrossai_mcp_npm_login_enabled']['sanitize_callback'] === 'rest_sanitize_boolean'` AND `default === false`. Repeat for `acrossai_mcp_claude_connectors_enabled` (rest_sanitize_boolean, false) AND `acrossai_mcp_uninstall_delete_data` (sanitize_uninstall_flag closure/array, default 0). Cite BUGS.md B9 in the test-file docblock. **DoD**: PHPStan L8 + PHPCS green; `vendor/bin/phpunit tests/phpunit/Admin/SettingsMenuTest.php` returns zero failures.

- [ ] T008 [US1] Manual smoke test on live WP: activate `acrossai-mcp-manager` + `acrossai-main-menu`; navigate to `/wp-admin/admin.php?page=acrossai-settings`; verify (a) MCP tab visible in nav bar (at position 2 alongside Abilities tab at position 1 if sibling plugin is also active); (b) clicking MCP renders the three sections in order (npm / CLI Settings → Claude Connectors Screen (Experimental) → Uninstall Settings); (c) each section shows correct title + description + warning banner (with correctly rendered URLs from `FrontendAuth::get_base_url()` and the three OAuth URLs); (d) toggle each checkbox in turn + click Save Changes → reload page → all three states persist; (e) `wp option get acrossai_mcp_npm_login_enabled` + `wp option get acrossai_mcp_claude_connectors_enabled` + `wp option get acrossai_mcp_uninstall_delete_data` return the values shown in the UI; (f) `⚠` character on Uninstall field renders as the warning emoji (not mojibake). Record evidence (screenshot + WP-CLI output) in `docs/planings-tasks/012-mcp-settings-tab.md` under a "Manual Smoke Evidence" section. **DoD**: all 6 checks pass; evidence recorded.

**Checkpoint**: User Story 1 is functionally complete. MCP settings tab renders + persists on live WP. Feature 012's core MVP is deliverable at this point.

---

## Phase 3: User Story 2 — Uninstall Preserves User Data by Default (Priority: P1)

**Goal**: A site administrator uninstalls the plugin WITHOUT ticking the "Delete all data on uninstall" checkbox. All four `wp_acrossai_mcp_*` tables + every `acrossai_mcp_*` option + the `acrossai_mcp_oauth_cleanup` cron remain intact.

**Independent Test**: On a populated WP install with the uninstall checkbox UNCHECKED, deactivate + uninstall the plugin via `wp plugin uninstall acrossai-mcp-manager`. Verify (a) `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` returns 4 rows; (b) `wp option list --search='acrossai_mcp_*'` returns pre-uninstall options; (c) `wp cron event list --search='acrossai_mcp_oauth_cleanup'` still shows the hook (unless WP-Cron itself cleared it).

### Implementation for User Story 2

- [ ] T009 [US2] Full rewrite: `uninstall.php`. Read the current file BEFORE editing to preserve the docblock/note structure. New shape (per spec TASK-5 + FR-019..023):
  1. `<?php` opener + updated docblock explaining the opt-in flag behavior (`0` → preserve; `1` → destructive).
  2. `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }` — unchanged.
  3. **CRITICAL — FR-019 gate at the TOP**: `if ( 1 !== (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) ) { return; }` — placed IMMEDIATELY after the WP_UNINSTALL_PLUGIN check + BEFORE any `global $wpdb`.
  4. `global $wpdb;`
  5. DROP TABLE loop over the 4 hardcoded stems (`acrossai_mcp_servers`, `acrossai_mcp_cli_auth_logs`, `acrossai_mcp_oauth_tokens`, `acrossai_mcp_oauth_audit`) with `$wpdb->prefix` prefix. **CRITICAL — SEC-012-003 phpcs:ignore scoping**: use INLINE `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange` on the specific `$wpdb->query()` line — NOT `phpcs:disable`/`phpcs:enable` pair. Add an inline comment above: `// $table is derived from $wpdb->prefix + hardcoded stems; no user input reaches SQL.`. **ALTERNATIVE per SEC-012-003 recommendation**: consider using `$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) )` (WordPress 6.2+ `%i` placeholder) — eliminates the phpcs:ignore entirely. Choose whichever is cleaner at implementation time; document choice in the code comment.
  6. Options LIKE-sweep: `$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", 'acrossai_mcp_%' ) );` then `foreach ( $options as $option_name ) { delete_option( $option_name ); }`. `$wpdb->prepare()` is REQUIRED per FR-020 + S4 + SEC-012-003 constraint.
  7. `wp_clear_scheduled_hook( 'acrossai_mcp_oauth_cleanup' );`
  8. Full-file docblock notes the behavior change vs pre-Feature-012 for future readers.
  **DoD**: `php -l uninstall.php` clean; PHPCS zero errors, zero warnings (verify the phpcs:ignore is line-scoped, not file-scoped); PHPStan L8 zero errors (may need `phpstan-ignore-line` on the `WP_UNINSTALL_PLUGIN` guard for undefined-constant warnings).

- [ ] T010 [P] [US2] **OPTIONAL per SEC-012-005** — Create NEW file: `tests/phpunit/UninstallTest.php` (or extend `SettingsMenuTest.php` if bootstrap constraints require). PHPUnit test that locks the preserve-by-default gate as a runtime invariant. Approach: use `runInSeparateProcess` PHP attribute + reflection-set `define( 'WP_UNINSTALL_PLUGIN', true )`; call `require ABSPATH . 'wp-content/plugins/acrossai-mcp-manager/uninstall.php'`; with `get_option( 'acrossai_mcp_uninstall_delete_data', 0 )` returning `0` (default) → assert `$wpdb->queries` is empty (no destructive queries fired) AND at least one `acrossai_mcp_*` option seeded before the test call is STILL present after. Cite SEC-012-005 in the test-file docblock. **DoD**: `vendor/bin/phpunit tests/phpunit/UninstallTest.php` returns zero failures; test locks the preserve-by-default gate.

- [ ] T011 [US2] Manual smoke test on live WP — preserve-by-default. Preconditions: plugin active + populated (at least 1 MCP server + 1 CLI auth log row + 1 OAuth token). Verify the "Delete all data on uninstall" checkbox on MCP settings tab is UNCHECKED (default). Deactivate + uninstall via `wp plugin uninstall acrossai-mcp-manager`. Verify:
  - `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` returns 4 rows;
  - Row counts in each table match pre-uninstall counts;
  - `wp option list --search='acrossai_mcp_'` returns pre-uninstall options with unchanged values.
  Reactivate plugin. Verify no phantom-version drift (per Feature 011 FR-018 guard) — all four tables intact, no duplicate rows, no fatals. Record evidence in `docs/planings-tasks/012-mcp-settings-tab.md`. **DoD**: all 4 verifications pass; evidence recorded with `wp` command outputs pasted verbatim.

**Checkpoint**: User Story 2 verified. Preserve-by-default is the load-bearing safety invariant for the uninstall behavior change.

---

## Phase 4: User Story 3 — Destructive Uninstall Wipes Everything When Opted In (Priority: P1)

**Goal**: With the "Delete all data on uninstall" checkbox CHECKED and saved, uninstalling the plugin drops all four tables + deletes every `acrossai_mcp_*` option + clears the OAuth cleanup cron. Reactivation triggers a clean-install lifecycle.

**Independent Test**: See spec User Story 3 Independent Test.

### Implementation for User Story 3

- [ ] T012 [US3] Manual smoke test on live WP — destructive opt-in. Preconditions: plugin active + populated. Navigate to MCP settings tab → tick "Delete all data on uninstall" checkbox → click Save Changes → verify `wp option get acrossai_mcp_uninstall_delete_data` returns `1`. Deactivate + uninstall via `wp plugin uninstall acrossai-mcp-manager`. Verify:
  - `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` returns EMPTY (0 rows);
  - `wp option list --search='acrossai_mcp_'` returns EMPTY;
  - `wp cron event list --search='acrossai_mcp_oauth_cleanup'` returns EMPTY.
  Reactivate the plugin — Feature 011's activation path recreates all four tables + seeds the default MCP server row + stamps all four `db_version_key` options with no fatals. Record evidence in `docs/planings-tasks/012-mcp-settings-tab.md`. **DoD**: all 3 pre-reactivation verifications pass + reactivation succeeds cleanly; evidence recorded.

**Checkpoint**: User Story 3 verified. Both branches of the uninstall.php gate (preserve + destroy) work correctly.

---

## Phase 5: User Story 4 — CLI Auth Log Admin Submenu Removal (Priority: P2)

**Goal**: The standalone "CLI Auth Log" admin submenu at `?page=acrossai_mcp_manager_cli_auth_log` no longer exists. Navigating to the old URL returns WP's "not allowed" screen. OAuth flow continues to work — DB-layer preservation is the load-bearing invariant.

**Independent Test**: See spec User Story 4 Independent Test.

### Implementation for User Story 4

**File-level removals (T013–T016)** — mostly independent; each file is a distinct edit surface.

- [ ] T013 [P] [US4] Delete entirely: `admin/Partials/CliAuthLogListTable.php` (175 lines, WP_List_Table subclass). Only consumer is `Settings::render_cli_auth_log_page()` which is deleted in T016. **DoD**: `ls admin/Partials/CliAuthLogListTable.php` returns "No such file"; PHPStan L8 + PHPCS green plugin-wide (the class is not referenced anywhere else — verified by pre-flight grep in T001).

- [ ] T014 [P] [US4] Delta edit: `admin/Partials/Menu.php`. Delete the `add_submenu_page( ..., AdminPageSlugs::CLI_AUTH_LOG, ..., 3 );` block for position 3 (~lines 87-96 in the current file — verify exact line range before editing). Update the docblock at ~lines 59-64 to remove any mention of "Position 3 — CLI Auth Log"; the remaining Positions 2 (MCP main) and 4 (Access Control, conditional) stay unchanged. **DoD**: `grep -c 'CLI_AUTH_LOG\|CLI Auth Log' admin/Partials/Menu.php` returns 0; `grep -c 'AccessControl' admin/Partials/Menu.php` still returns non-zero (position 4 preserved); PHPStan L8 + PHPCS green.

- [ ] T015 [US4] Delta edit: `includes/Utilities/AdminPageSlugs.php`. Delete `public const CLI_AUTH_LOG = 'acrossai_mcp_manager_cli_auth_log';` (~line 31) + its docblock comment. Delete both `plugin_screen_ids()` entries that reference the const: `'acrossai_page_' . self::CLI_AUTH_LOG,` (~line 55) AND `'mcp-manager_page_' . self::CLI_AUTH_LOG,` (~line 58). **Preserve every other constant + every other screen-ID whitelist entry** (A9 canonical-whitelist rule; T006's earlier addition of `SETTINGS_TAB` const + `acrossai_page_acrossai-settings` screen ID entry survives this delta unchanged). **DoD**: `grep -c 'CLI_AUTH_LOG' includes/Utilities/AdminPageSlugs.php` returns 0; `grep -c 'SETTINGS_TAB' includes/Utilities/AdminPageSlugs.php` returns 1 (T006 addition preserved); `grep -c 'acrossai_page_acrossai-settings' includes/Utilities/AdminPageSlugs.php` returns 1; PHPStan L8 + PHPCS green.

- [ ] T016 [US4] Delta edit: `admin/Partials/Settings.php`. (a) Delete the `use AcrossAI_MCP_Manager\Admin\Partials\CliAuthLogListTable;` import at the top of the file. (b) Delete the `render_cli_auth_log_page(): void` method + its surrounding docblock (~lines 717-742). Every other method + every other import stays intact. **DoD**: `grep -c 'CliAuthLogListTable\|render_cli_auth_log_page' admin/Partials/Settings.php` returns 0; PHPStan L8 + PHPCS green.

**Verification gates (T017–T019)** — sequential; run after T013–T016 all land.

- [ ] T017 [US4] Run the removal grep from spec's "Second pre-flight grep" against the current codebase:
  ```
  grep -rEn "acrossai_mcp_manager_cli_auth_log|CliAuthLogListTable|CLI_AUTH_LOG|render_cli_auth_log_page" \
      --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php
  ```
  Expected: **zero hits**. Any hit is either a missed deletion in T013–T016 or a stale reference elsewhere. Diff against `specs/012-mcp-settings-tab/pre-flight-cli-auth-log.txt` from T001 to confirm every pre-flight hit is resolved. **DoD**: grep returns zero; diff shows all T001 pre-flight hits are now absent.

- [ ] T018 [US4] Run the companion DB-layer grep (per FR-028 preservation + SEC-012-002):
  ```
  grep -rEn "CliAuthLogQuery|CliAuthLogRow|CliAuthLog\\\\Table|CliAuthLog\\\\Recorder|use .*CliAuthLog\\\\" \
      --include='*.php' includes/ admin/ public/ acrossai-mcp-manager.php
  ```
  Expected: **same non-zero hit count as `specs/012-mcp-settings-tab/pre-flight-cli-auth-log-db-layer.txt` from T001**. Any DROP in the hit count indicates accidental DB-layer damage (e.g., a file under `includes/Database/CliAuthLog/**` was deleted or a caller import was removed). Diff outputs. **DoD**: hit count matches; diff shows zero changes to DB-layer callers.

- [ ] T019 [US4] Manual smoke test on live WP: navigate to `/wp-admin/admin.php?page=acrossai_mcp_manager_cli_auth_log`. Verify WP returns "You do not have sufficient permissions" or "Sorry, you are not allowed to access this page". Verify the parent AcrossAI menu no longer shows a "CLI Auth Log" entry. Trigger a fresh OAuth CLI auth flow (approve auth code via `/acrossai-mcp-manager/` frontend page + redeem via REST `/wp-json/acrossai-mcp/v1/token`) — verify a new row is written to `wp_acrossai_mcp_cli_auth_logs` AND the row's `completed_at` column is stamped (SEC-001 atomic-CAS still functional). Also verify inspection via `wp db query "SELECT id, user_id, status, created_at FROM wp_acrossai_mcp_cli_auth_logs ORDER BY created_at DESC LIMIT 5"` returns the newly-written row. Record evidence in `docs/planings-tasks/012-mcp-settings-tab.md`. **DoD**: 4 checks pass; OAuth flow round-trip succeeds; evidence recorded.

**Checkpoint**: User Story 4 complete. CLI Auth Log admin surface removed; DB layer preserved; OAuth invariants intact.

---

## Phase 6: Polish — Memory Hygiene + Changelog + Docs

**Purpose**: Capture the three planned durable decisions (per spec FR-029), update the changelog + memory index, and land the security review index row.

- [ ] T020 [P] Update `README.txt` Unreleased changelog per spec FR-032. Add three bullets:
  1. New MCP tab on shared settings page with 3 toggles (per US1 outcome).
  2. BEHAVIOR CHANGE: uninstall default flips from destructive-OAuth-only to preserve-everything; sites that expected OAuth-table wipe must tick the new checkbox (per US2/US3 outcome + SEC-012-001).
  3. Removed CLI Auth Log admin submenu (per US4 outcome).
  **DoD**: bullets present in the Unreleased section; changelog convention preserved.

- [ ] T021 [P] Append to `docs/memory/DECISIONS.md` (per FR-029): **DEC-VENDOR-SETTINGS-TAB-INTEGRATION (Active — Feature 012)** entry per spec TASK-7 body + memory-synthesis Retrieval Notes. Include the (a)-(d) rules (filter hook shape, `tab_page_slug` helper, shared `'acrossai-settings'` option group, class member ordering) + the defense-in-depth note about the intentional omission of `class_exists()` guard + the §IV DataForm carve-out justification. **DoD**: entry present; markdown valid.

- [ ] T022 [P] Append to `docs/memory/DECISIONS.md`: **DEC-UNINSTALL-OPT-IN-GATE (Active — Feature 012)** entry per spec TASK-7 body. Rule: uninstall.php MUST preserve all data by default; destructive teardown gated on `acrossai_mcp_uninstall_delete_data === 1`. Rationale: WP.org guideline 5 + sibling pattern. Future features MUST NOT bypass the gate. **DoD**: entry present; markdown valid.

- [ ] T023 [P] Append to `docs/memory/DECISIONS.md`: **DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG (Active — Feature 012)** entry per spec TASK-7 body. Rule: standalone admin submenus for read-only DB-inspection views SHOULD be pruned when they duplicate an existing inspection path (WP-CLI, per-server tab). Underlying DB layer stays. First A9 subtractive-edit precedent codified: subtractive edits to `plugin_screen_ids()` allowed ONLY when the corresponding submenu page is removed in the same feature (addresses SEC-012-006). **DoD**: entry present; markdown valid.

- [ ] T024 [P] Append Feature 012 milestone entry to `docs/memory/WORKLOG.md` per spec FR-031. Sections: (Why durable / Future mistake prevented / Evidence / Where to look). Highlight the durable lesson: **when consuming a vendor package's shared settings surface, `register_setting()`'s `option_group` MUST match the vendor's own `settings_fields()` call — not the per-tab page slug — or Save silently no-ops with no operator-visible error**. **DoD**: entry present with all four sections filled.

- [ ] T025 [P] Update `docs/memory/INDEX.md` per FR-030: (a) append 3 new rows under Active Decisions for DEC-VENDOR-SETTINGS-TAB-INTEGRATION, DEC-UNINSTALL-OPT-IN-GATE, DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG; (b) append a WORKLOG row for Feature 012 pointing at T024 entry; (c) append a security-reviews row for `docs/security-reviews/2026-07-03-012-mcp-settings-tab-plan.md` matching the row from the security review's Memory Hub INDEX.md Row section (`| ... | plan | 2026-07-03 | LOW | C:0 H:0 M:0 L:3 | A02,A05,A08,A09 |`). **DoD**: `grep -c 'DEC-VENDOR-SETTINGS-TAB-INTEGRATION\|DEC-UNINSTALL-OPT-IN-GATE\|DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG' docs/memory/INDEX.md` returns 3; `grep -c '2026-07-03-012-mcp-settings-tab-plan' docs/memory/INDEX.md` returns 1.

- [ ] T026 [P] Update `docs/planings-tasks/README.md` per FR-033: append a row for `012-mcp-settings-tab.md` alongside the existing Feature 011 row. **DoD**: `grep -c '012-mcp-settings-tab' docs/planings-tasks/README.md` returns at least 1.

- [ ] T027 Final whole-plugin gate. Run:
  - `vendor/bin/phpcs` on the whole plugin (not just touched files) — 0 errors, 0 warnings on Feature 012 files; whole-plugin baseline unchanged (may still have pre-existing errors from other files);
  - `vendor/bin/phpstan analyse --level=8` on the whole plugin — 0 errors on Feature 012 files;
  - `vendor/bin/phpunit tests/phpunit/Admin/SettingsMenuTest.php` — green;
  - `vendor/bin/phpunit tests/phpunit/UninstallTest.php` (if T010 was implemented) — green;
  - re-run the removal grep from T017 and companion grep from T018 — both still green;
  - `find includes admin public *.php uninstall.php -name '*.php' -type f | xargs -I{} php -l {}` — zero syntax errors across the whole plugin.
  Diff outputs recorded in `docs/planings-tasks/012-mcp-settings-tab.md` as `post-merge-verification.txt`. **DoD**: all 6 checks green; verification file present.

**Checkpoint**: Feature 012 is complete. Every §VII DoD gate has passed; memory is coherent; changelog reflects the ship; three new DECs are captured with matching INDEX rows.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup (T001–T002)**: T001 blocks T017/T018 (need pre-flight snapshots to diff against). T002 blocks T007/T010 (need bootstrap coverage for new subdir).
- **Phase 2 US1 (T003–T008)**: T003 blocks T007 (test file requires SettingsMenu class exists); T003 blocks T005 (Main.php references SettingsMenu::instance); T004 blocks T005 (delete stub before removing its Loader wire); T005 blocks T007 (test file may require class to be Loader-instantiated); T006 blocks T015 (T015 does further edits to AdminPageSlugs — separated to preserve grep-verifiable state); T008 depends on T003–T007 (manual smoke needs full stack).
- **Phase 3 US2 (T009–T011)**: T009 blocks T010 + T011 (test + smoke depend on rewritten uninstall.php).
- **Phase 4 US3 (T012)**: depends on Phase 3 (T009 uninstall.php rewrite needed for opt-in path to work).
- **Phase 5 US4 (T013–T019)**: T013–T016 mostly parallel; T017/T018 sequential after T013–T016; T019 sequential after T017/T018.
- **Phase 6 Polish (T020–T027)**: T020–T026 mostly parallel; T027 depends on all prior tasks landing.

### User Story Dependencies

- **US1 (P1) 🎯 MVP**: Independent; delivers the MCP settings tab. No dependency on US2/US3/US4.
- **US2 (P1)**: Depends on US1's SettingsMenu class ONLY to the extent that `uninstall.php` reads the option key that US1 registers; but the option key default `0` means US2 works even without US1 (uninstall would just always take the preserve-by-default branch).
- **US3 (P1)**: Depends on US1 (the checkbox is what an admin uses to opt into destructive uninstall) AND US2 (uninstall.php rewrite must land for destructive branch to work).
- **US4 (P2)**: Independent from US1/US2/US3. Can ship in isolation; bundled with Feature 012 for scope-batching efficiency (same PR).

### Within Each User Story

- Tests (T007, T010) written concurrently with implementation but MUST FAIL before implementation lands (TDD-lite).
- Class + wiring before smoke tests.
- Grep gates run last within each phase.
- Story complete before moving to next.

### Parallel Opportunities

- **Within US1**: T007 is `[P]` — can be authored in parallel with T003–T006 (the test file exists once the class file exists; harness assertions run against the class).
- **Within US4**: T013–T016 are 4 file-level removals across different files — all `[P]` parallel-safe.
- **Within Polish**: T020–T026 are 7 file-level edits across different files — all `[P]` parallel-safe.

---

## Parallel Example: User Story 4

```bash
# After T001+T002 (setup) + T003–T008 (US1) + T009–T011 (US2) + T012 (US3) all land,
# launch 4 parallel-safe deletions for the CLI Auth Log surface:
Task: "Delete admin/Partials/CliAuthLogListTable.php entirely (T013)"
Task: "Delta edit admin/Partials/Menu.php — delete position-3 add_submenu_page block + update docblock (T014)"
Task: "Delta edit includes/Utilities/AdminPageSlugs.php — delete CLI_AUTH_LOG const + 2 whitelist entries (T015)"
Task: "Delta edit admin/Partials/Settings.php — delete render_cli_auth_log_page + use import (T016)"

# Then run the 3 verification gates sequentially:
Task: "Removal grep must return zero (T017)"
Task: "Companion DB-layer grep must match pre-flight count (T018)"
Task: "Manual smoke — old URL 404s + OAuth flow works (T019)"
```

---

## Implementation Strategy

### MVP First (US1 alone)

**IMPORTANT**: Unlike Feature 011 (where US1+US3 were both required for a shippable plugin because of the compat drop), Feature 012's stories are more independently shippable. US1 alone is the MVP — it delivers the visible operator surface (the MCP tab with three toggles). US2+US3 fix the pre-existing uninstall behavior + make the opt-in usable. US4 is a footprint-reduction chore.

1. Complete Phase 1: Setup (T001–T002).
2. Complete Phase 2: US1 (T003–T008).
3. **STOP and VALIDATE**: T008 manual smoke test on live WP → MVP ready.
4. Continue to Phases 3–4: US2+US3 (T009–T012) for full uninstall behavior migration.
5. Continue to Phase 5: US4 (T013–T019) for the CLI Auth Log removal.
6. Continue to Phase 6: Polish (T020–T027).

### Incremental Delivery (single-PR shape)

Feature 012 is a single-PR feature (matching Feature 011's shape). Every task can be a separate commit; total commit count ~27. Constitution §VII per-task gate ensures every commit is PHPStan L8 + PHPCS green.

1. Setup commits — 2 commits (T001, T002).
2. US1 commits — 6 commits (T003, T004, T005, T006, T007, T008).
3. US2 commits — 3 commits (T009, T010 optional, T011).
4. US3 commits — 1 commit (T012).
5. US4 commits — 7 commits (T013–T019).
6. Polish commits — 8 commits (T020–T027).

### Parallel Team Strategy

With 2+ developers, after T001+T002 + T003 land:

- Developer A: US1 wire-up (T004, T005, T006) + smoke (T008).
- Developer B: US1 test (T007) + US2 uninstall.php rewrite (T009) + US2 test (T010).
- Developer C: US4 removals (T013–T016 all parallel) + US4 verification (T017–T019).
- Team converges for Polish (T020–T027) with each dev owning a subset of the parallel-safe docs edits.

---

## Notes

- **[P] tasks = different files, no dependencies**. Every [P]-marked task in this file was verified to touch a distinct file path.
- **[Story] label maps task to spec.md user story**. `US1` = MCP Settings Tab; `US2` = Preserve-by-default uninstall; `US3` = Destructive opt-in uninstall; `US4` = CLI Auth Log admin surface removal.
- **Every task's DoD includes PHPStan L8 + PHPCS on the touched surface**. Constitution §VII per-task gate is non-negotiable. If a task's DoD is not green, do NOT mark the task complete.
- **Commit after each task** (or logical parallel batch within a story). Do not batch across stories.
- **Stop at any checkpoint** (end of US1, end of US2, end of US3, end of US4, end of Polish) to validate the story-so-far.
- **Avoid**: adding `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )` guard in `register_settings()` (spec CONSTRAINTS); broadening the phpcs:ignore in uninstall.php to file scope (SEC-012-003); inverting the uninstall gate default from `0` to `1` (spec CONSTRAINTS + WP.org guideline 5); deleting any file under `includes/Database/CliAuthLog/**` (spec FR-028 + SEC-012-002); removing any pre-existing `AdminPageSlugs::plugin_screen_ids()` entry other than the two CLI_AUTH_LOG ones in T015 (spec A9 rule); renaming `admin/Partials/Settings.php` (spec CONSTRAINTS).
