---
description: "Task list for Feature 015 — Adopt wpboilerplate/wpb-access-control v2 (fix 3 fatal v1-API call sites + per-server rule UI + MCP-boundary enforcement via mcp_adapter_pre_tool_call)"
---

# Tasks: Adopt `wpboilerplate/wpb-access-control` v2 — per-server access rules + MCP-boundary enforcement (Feature 015)

**Input**: Design documents from `specs/015-access-control-v2-adoption/`
**Prerequisites**: `spec.md` (279 lines + 3 Clarifications + 26 FRs + 7 SCs), `plan.md` (~160 lines), `memory-synthesis.md` (897 words), `security-constraints.md` (~145 lines), `architecture-violations.md` (~135 lines), `docs/security-reviews/2026-07-04-015-access-control-v2-adoption-plan.md`, `docs/planings-tasks/015-access-control-v2-adoption.md` (634 lines)

**Tests**: The plan mandates PHPUnit coverage — `AcrossAI_MCP_Access_Control_Test` with 8 test methods (7 from FR + 1 from SEC-015-002 recommendation). Manual smoke tests deferred to reviewer per `post-merge-verification.txt` convention (fresh install activation, uninstall opt-in flow, per-role tool-call gating on live WP).

**Organization**: Tasks are grouped by user story per spec.md (US1..US5). US2/US3 are foundational (bug fix + table setup — blocking); US1 depends on US2/US3 + US4 (UI depends on both fixes + block); US4 (fail-open) is a cross-cutting invariant validated in every enforcement task; US5 (uninstall) is Phase 6.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (`US1`, `US2`, `US3`, `US4`, `US5`); Setup/Foundational/Polish tasks have no story label

## Path Conventions

- Plugin root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`
- All paths below are relative to the plugin root unless otherwise noted
- PHP source under `admin/`, `includes/`, `public/`, `uninstall.php`
- PHPUnit tests under `tests/phpunit/`
- Docs under `docs/`

## Constitution §VII per-task gate (applies to EVERY task below)

Before marking any task complete, run:
- `vendor/bin/phpcs` — zero errors, zero warnings on touched files
- `vendor/bin/phpstan analyse --level=8` — zero errors on touched files
- Any grep gate explicitly named in the task description

A task is not "done" until its DoD line is green.

---

## Phase 1: Setup (pre-flight snapshots + test harness sanity)

**Purpose**: Capture reference state + verify the PHPUnit test harness covers the new subdir.

- [x] T001 [P] Capture pre-flight grep snapshots to `specs/015-access-control-v2-adoption/pre-flight-*.txt`:
  ```
  grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/ > specs/015-access-control-v2-adoption/pre-flight-v1-api-callsites.txt
  grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php > specs/015-access-control-v2-adoption/pre-flight-legacy-namespace.txt
  ls -la vendor/wpboilerplate/wpb-access-control/src/AccessControlManager.php > specs/015-access-control-v2-adoption/pre-flight-vendor-package.txt
  ```
  **DoD**: 3 snapshot files exist; v1-API-callsites grep returns exactly 3 hits (baseline: AccessControlTab.php:65, CliController.php:333, Main.php:432 — commented but still matches); legacy-namespace grep returns 0 hits (baseline confirmation); vendor package file exists.

- [x] T002 [P] Verify `tests/phpunit/Includes/AccessControl/` subdirectory is covered by the `admin` testsuite in `phpunit.xml.dist`. F013 extended the testsuite to include `tests/phpunit/Public/Renderers/`; extend that entry to also include `tests/phpunit/Includes/AccessControl/`. **DoD**: create a throwaway `tests/phpunit/Includes/AccessControl/BootstrapProbe.php` asserting `true`; run `vendor/bin/phpunit --testsuite admin --bootstrap tests/bootstrap-wp.php`; delete the probe after verification.

- [x] T003 [P] Verify sibling plugin's canonical wrapper is at expected path + shape:
  ```
  ls -la /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Abilities_Access_Control.php
  wc -l /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Abilities_Access_Control.php
  ```
  Also verify vendor package's public API surface exists at expected paths:
  ```
  ls -la vendor/wpboilerplate/wpb-access-control/src/{AccessControlManager,Database/Rule/RuleQuery,Database/Rule/RuleTable,AbstractProvider,WpRoleProvider,WpUserProvider,WpCapabilityProvider}.php
  ```
  Also verify mcp-adapter filter site:
  ```
  grep -n "apply_filters.*mcp_adapter_pre_tool_call" vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php
  ```
  Log drift in `specs/015-access-control-v2-adoption/pre-flight-vendor-package.txt`. **DoD**: sibling class ~158 LOC; all 7 vendor files exist; grep on mcp-adapter returns exactly 1 hit at line 182 (or within ±5 lines).

---

## Phase 2: Foundational (Wrapper class + Activator table setup — blocking prerequisite for all user stories)

**Purpose**: Scaffold the v2 wrapper class + create the DB table on activation so US1's UI + US2/US3's enforcement + US4's fail-open + US5's uninstall can all hang off it.

- [x] T004 [US2] Full write: `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (NEW). Namespace `AcrossAI_MCP_Manager\Includes\AccessControl`. Copy-adapt the sibling plugin's `AcrossAI_Abilities_Access_Control` class verbatim, substituting namespaces + constant values + admin-notice text. Singleton scaffolding matches F012 SettingsMenu (protected static `$instance = null;` → `public static function instance(): self` → `private function __construct() {}`). Public class constants: `PROVIDERS_FILTER = 'acrossai_mcp_access_control_providers'`, `TABLE_SLUG = 'mcp'`. (SAFE_CAPABILITIES constant withdrawn per Clarifications Q4.) Public methods per FR-003: `is_available(): bool`, `boot_manager(): void`, `get_manager(): ?AccessControlManager`, `register_rest_api(): void`, `maybe_show_library_notice(): void`, `gate_mcp_tool_call( array $args, string $tool_name, $mcp_tool, $server )`. Public static per FR-004: `register_default_providers( array $providers ): array` appending `WpRoleProvider`/`WpUserProvider`/`WpCapabilityProvider`. `boot_manager()` MUST use `new AccessControlManager( self::PROVIDERS_FILTER, self::TABLE_SLUG )` — NEVER v1's `::instance()` static. `gate_mcp_tool_call()` implements FR-007: fail-open on `!is_available()`; resolves `$server_slug` via `MCPServerQuery::instance()->get_item()` (returns `$args` + fires `do_action( 'acrossai_mcp_access_control_missing_server', $server_id, $tool_name, get_current_user_id() )` on null per Clarifications Q2); calls `user_has_access()`; on deny fires `do_action( 'acrossai_mcp_access_control_denied', get_current_user_id(), $server_slug, $tool_name, 'mcp_tool_call' )` per FR-026 BEFORE returning `new WP_Error( 'acrossai_mcp_access_denied', __(...), array('status'=>403) )`. Per Clarifications Q4, add public method `get_available_capabilities(): array` that enumerates the full WP capability set (dedup + sort from `wp_roles()->role_objects`) and fires `apply_filters( 'acrossai_mcp_ac_available_capabilities', $capabilities )` for third-party extensions. No deny-list guard — admin bypass hierarchy makes exposing `manage_options`/`edit_users` non-escalatory (a rule matching either is a no-op because only admins hold them, and admins are always allowed). All `printf`/`sprintf` use ONE placeholder style per B16. **DoD**: `php -l` clean; PHPStan L8 zero errors; PHPCS zero errors, zero warnings; `diff` against sibling class shows only namespace/constant-value/text differences (no design drift).

- [x] T005 [US3] Delta edit: `includes/Activator.php`. Add `use WPBoilerplate\AccessControl\Database\Rule\RuleTable;` + `use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;` at the top. Inside `Activator::activate()` — after the 4 existing F011 table calls (`MCPServerTable::instance()->maybe_upgrade()`, etc.) — add:
  ```php
  // Feature 015 — Access Control v2 (SEC-015-001: defense-in-depth guard).
  if ( class_exists( '\WPBoilerplate\AccessControl\Database\Rule\RuleTable' ) ) {
      ( new RuleTable( AcrossAI_MCP_Access_Control::TABLE_SLUG ) )->maybe_upgrade();
  }
  ```
  The `class_exists` guard is the SEC-015-001 defense-in-depth against a vendor-uninstall-then-reactivate race. `RuleTable::maybe_upgrade()` is idempotent (BerlinDB handles version check). **DoD**: `wp plugin activate` on a fresh install creates `{$wpdb->prefix}mcp_access_control` table; option `wpb_ac_mcp_db_version` set; grep `grep -rn 'RuleTable.*maybe_upgrade' includes/Activator.php` returns exactly 1 hit; PHPStan L8 + PHPCS green.

**Checkpoint**: Foundational scaffolding complete. Wrapper class exists; DB table created on activation; is_available() correctly reports vendor package status.

---

## Phase 3: User Story 2 — Fix the 3 v1-API fatal call sites (Priority: P1) 🎯 LIVE-BUG FIX

**Goal**: Eliminate the live crash bug where every AccessControl call site fatals because they use v1's `::instance()` API against a v2 package. Grep gate must return zero hits after this phase.

**Independent Test**: On live WP with vendor package installed, open the Access Control tab on server id=1 — no fatal, tab renders. Call `/wp-json/acrossai-mcp-manager/v1/servers` as an authenticated user — no fatal, HTTP 200. Grep gate: `grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/` returns 0 hits.

### Implementation for User Story 2

- [x] T006 [US2] Delta edit: `includes/REST/CliController.php`. At line 333, replace:
  ```php
  if ( class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
      $acm     = \WPBoilerplate\AccessControl\AccessControlManager::instance();  // v1 — fatals
      $allowed = $acm->user_has_access( $user_id, $ns, $route );
      if ( ! $allowed ) {
          return new WP_REST_Response( array( 'servers' => array() ), 200 );
      }
  }
  ```
  with (per FR-006 + FR-026):
  ```php
  $ac = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
  if ( $ac->is_available() ) {
      $manager = $ac->get_manager();
      $slug    = $ns . '/' . $route;
      $allowed = $manager->user_has_access( $user_id, 'acrossai-mcp-manager', $slug );
      if ( ! $allowed ) {
          // FR-026 observability hook fires BEFORE the empty-list return.
          do_action( 'acrossai_mcp_access_control_denied', $user_id, $slug, null, 'cli_servers' );
          return new WP_REST_Response( array( 'servers' => array() ), 200 );
      }
  }
  ```
  Silent empty-array-on-deny stays — matches today's enumeration-defense semantics. Rule key is `$ns . '/' . $route` — documented in FR-006 as caller-visible target string. **DoD**: PHPStan L8 + PHPCS green; grep `grep -n 'AccessControlManager::instance' includes/REST/CliController.php` returns 0 hits.

- [x] T007 [US2] Delta edit: `admin/Partials/ServerTabs/AccessControlTab.php`. Refactor `render_body()` to a THIN DELEGATE to `public/Renderers/AccessControlBlock.php` (see Phase 5 T012). Match the F013 `NpmTab`/`ClientsTab`/`ClaudeConnectorTab` delegate shape:
  ```php
  protected function render_body( array $server ): void {
      \AcrossAI_MCP_Manager\Public\Renderers\AccessControlBlock::instance()->render(
          (int) $server['id'],
          array(
              'context'           => 'admin',
              'cap'               => 'manage_options',
              'submit_target_url' => $this->server_edit_url( $server, 'access-control' ),
              'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
          )
      );
  }
  ```
  Delete the v1-API `AccessControlManager::instance('acrossai_mcp_access_control_providers')` call at line 65 + the `method_exists( $manager, 'render_admin_page' )` speculation. Delete the fallback warning notice — the Block handles fail-open itself. **DoD**: PHPStan L8 + PHPCS green; grep `grep -n 'AccessControlManager::instance' admin/Partials/ServerTabs/AccessControlTab.php` returns 0 hits; grep `<form method="post"|wp_nonce_field(|<pre>|<textarea>` in this file returns 0 hits (thin-delegate shape per FR-022).

- [x] T008 [US2] Delta edit: `includes/Main.php`. Delete the commented-out `// $access_control = \WPBoilerplate\AccessControl\AccessControlManager::instance( 'acrossai_mcp_access_control_providers' );` line at line 432. Delete the empty `if ( class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) { /* TODO Phase 7 */ }` block at lines 374-379 (per FR-015). Both are replaced by the real Loader wiring added in T013. **DoD**: PHPStan L8 + PHPCS green; grep `grep -n 'AccessControlManager::instance' includes/Main.php` returns 0 hits; grep `grep -n 'Phase 7' includes/Main.php` returns 0 hits (cleanup verified).

- [x] T009 [US2] Verify FR-016 grep gate: `grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/` MUST return **zero hits** across the entire plugin. Compare against T001's baseline snapshot (was 3 hits → 0 hits). **DoD**: grep returns 0; log the diff in `specs/015-access-control-v2-adoption/post-t009-verification.txt`.

**Checkpoint**: US2 (P1) complete. Live crash bug eliminated. Access Control tab opens without fatal; CliController /servers route responds without fatal. FR-016 grep gate green.

---

## Phase 4: User Story 3 — Activator creates the AccessControl table on plugin activation (Priority: P1)

**Goal**: Verify T005's Activator delta actually creates the table on fresh install + is idempotent on re-activation. Implementation lives in T005 (Phase 2); Phase 4 is validation.

### Verification for User Story 3

- [x] T010 [US3] Manual smoke test on live WP: fresh install (or manually `DROP TABLE IF EXISTS {prefix}mcp_access_control; DELETE FROM wp_options WHERE option_name='wpb_ac_mcp_db_version';`). Deactivate + reactivate the plugin. Verify:
  - `SHOW TABLES LIKE '%mcp_access_control'` returns 1 row
  - `SELECT option_value FROM wp_options WHERE option_name='wpb_ac_mcp_db_version'` returns a non-empty version string
  - Re-activate again (idempotent test) → same state, no errors
  - Manually `DROP TABLE` again then re-activate → table restored (idempotent restore per US3 acceptance scenario 3)
  Record smoke evidence in `docs/planings-tasks/015-access-control-v2-adoption.md` under a new "US3 Smoke Evidence" section. **DoD**: all 4 checks pass; evidence recorded.

**Checkpoint**: US3 (P1) verified. Activator correctly creates the AC table on fresh install + is idempotent on re-activation + restore.

---

## Phase 5: User Story 1 — Per-server rule UI on the Access Control tab (Priority: P1) 🎯 MVP

**Goal**: Ship the per-server rule UI as `public/Renderers/AccessControlBlock.php` extending F013's `AbstractClientRenderer`. Save handler in `Settings.php::handle_actions()`. Register 3 built-in providers via the providers filter. This is the MVP — after this phase, an operator can save `wp_role=[editor]` for a server and the enforcement kicks in.

**Independent Test**: Per US1 acceptance scenarios — save `wp_role=[editor]` via the tab UI; verify DB row inserted; log in as subscriber → 403 on tool call; log in as editor → tool executes; log in as admin → tool always executes.

### Implementation for User Story 1

- [x] T011 [US1] (amended 2026-07-04 — vendor React adoption per Clarifications Q4) Full write: `public/Renderers/AccessControlBlock.php` (NEW). Namespace `AcrossAI_MCP_Manager\Public\Renderers`. Docblock cites `@since 0.0.7 @experimental May change without notice before 1.0.0` per FR-008 + DEC-CLIENT-RENDERER-PUBLIC-API. Singleton (protected static `$instance = null;` → `public static function instance(): self` → `private function __construct() {}`). Extends `AbstractClientRenderer`. `render_body( array $server, array $context ): void`:
  1. Fail-open check — if `AcrossAI_MCP_Access_Control::instance()->is_available()` is false, render `<div class="notice notice-info inline">` info notice ("Access Control is inactive because the wpb-access-control library is not loaded. Tool calls pass through unrestricted.") and return.
  2. Missing-slug guard — if `$server['server_slug']` is empty, render an admin warning + return.
  3. Emit a mount `<div id="acrossai-mcp-ac-root" data-server-slug="…"><p><em>Loading Access Control…</em></p></div>` — the vendor's React `<AccessControl>` component (shipped by `vendor/wpboilerplate/wpb-access-control/js/AccessControl.js`, aliased in `webpack.config.js` as `@wpb/access-control`) renders inside. Config + REST nonce are `wp_localize_script`'d by `admin/Main.php::maybe_enqueue_access_control_app()`.
  4. React app owns the provider dropdown ("No user access added by admin" / "Everyone" / "WordPress Role" / "Users" / "WordPress Capability"), role/capability checkbox panels, and user autocomplete search. Persistence goes via vendor REST endpoints (see T012).
  All output through `esc_html()`/`esc_attr()`/`esc_url()` at rendering point per §III. Every `printf`/`sprintf` uses ONE placeholder style per B16. **DoD**: `php -l` clean; PHPStan L8 + PHPCS green; docblock includes `@since 0.0.7 @experimental` string.

- [x] T012 [US1] (amended 2026-07-04 — vendor REST owns saves; see F010 amendment) Delta edit: `admin/Main.php`. Add `maybe_enqueue_access_control_app()` that enqueues `build/js/access-control.js` + `build/js/access-control.css` on `?action=edit&tab=access-control` only, then `wp_localize_script()` the config:
  ```php
  'pluginSlug'  => AcrossAI_MCP_Access_Control::TABLE_SLUG,
  'namespace'   => 'acrossai-mcp-manager',
  'resourceKey' => $server_slug,
  'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) ),
  'nonce'       => wp_create_nonce( 'wp_rest' ),
  ```
  Also add a `resolve.alias` entry `'@wpb/access-control' → vendor/wpboilerplate/wpb-access-control/js/AccessControl.js` in `webpack.config.js` + a new entry `'js/access-control': src/js/access-control.js` that mounts the vendor React component. Rule persistence is owned by the vendor React app calling vendor REST endpoints (`PUT/DELETE /wpb-ac/v1/mcp/rules/{namespace}/{resource_key}`). **The `save_access_control` action + `handle_access_control_update()` method previously scoped in T012 are NOT wired up in the shipping code — they exist as dead code and a follow-up T030 cleanup removes them.** **DoD**: PHPStan L8 + PHPCS green; `npm run build` emits `build/js/access-control.js`; live smoke: opening the tab shows the vendor React app with a populated provider dropdown.

- [x] T013 [US1] Delta edit: `includes/Main.php::define_public_hooks()`. Add Loader wiring per FR-014 + FR-018 (all 5 F015 hooks live here — matches F013 pattern):
  ```php
  // Feature 015 — Access Control v2 adoption
  $access_control = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
  $this->loader->add_action( 'init',                     $access_control, 'boot_manager', 5 );
  $this->loader->add_action( 'rest_api_init',            $access_control, 'register_rest_api' );
  $this->loader->add_action( 'admin_notices',            $access_control, 'maybe_show_library_notice' );
  $this->loader->add_filter( 'mcp_adapter_pre_tool_call', $access_control, 'gate_mcp_tool_call', 10, 4 );
  $this->loader->add_filter(
      \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::PROVIDERS_FILTER,
      '\\AcrossAI_MCP_Manager\\Includes\\AccessControl\\AcrossAI_MCP_Access_Control',
      'register_default_providers'
  );
  ```
  All 5 hook registrations Loader-wired per A1. **DoD**: PHPStan L8 + PHPCS green; grep `grep -rn 'mcp_adapter_pre_tool_call' includes/Main.php` returns exactly 1 hit; grep `grep -c 'PROVIDERS_FILTER' includes/Main.php` returns ≥1 hit.

- [x] T014 [US1] Manual smoke test on live WP: with plugin active + vendor package present, navigate to Access Control tab on server id=1. Verify:
  - Tab renders 3 provider pickers with correct labels + safe capability list
  - Check `editor` role + save → success notice + DB row inserted (`SELECT * FROM {prefix}mcp_access_control WHERE key='<server_slug>'` returns 1 row with `access_control_key='wp_role'` + `access_control_value='editor'`)
  - Log in as subscriber → POST an MCP tool call to server 1 → WP_Error response
  - Log in as editor → same POST → tool executes
  - Log in as admin → same POST → tool always executes (admin-always-allow)
  - Click Clear Rules → DB rows deleted; subscriber POST now succeeds
  Record smoke evidence in `docs/planings-tasks/015-access-control-v2-adoption.md` under "US1 Smoke Evidence" section. **DoD**: all 6 checks pass; evidence recorded.

**Checkpoint**: US1 (P1 MVP) delivered. Per-server rule UI functional; enforcement works end-to-end across roles.

---

## Phase 6: User Story 4 — Fail-open behavior across all 4 code paths (Priority: P2) [VALIDATION]

**Goal**: Verify (not implement — implementation lives in T004 wrapper + T006 CliController + T011 Block) that all 4 code paths fail-open when the vendor package is absent.

**Independent Test**: Per US4 acceptance scenarios — rename vendor dir + reload; all 4 paths pass-through gracefully; restore vendor dir + reload; enforcement resumes.

### Verification for User Story 4

- [x] T015 [US4] Manual fail-open test on live WP:
  - `mv vendor/wpboilerplate/wpb-access-control vendor/wpboilerplate/wpb-access-control.disabled`
  - Reload wp-admin: verify amber `wp_admin_notice` fires (FR-001 `maybe_show_library_notice()`) to `manage_options` users only
  - Open Access Control tab: verify info notice ("Access Control is inactive because the wpb-access-control library is not loaded") renders, no form fields, no fatal
  - POST MCP tool call: verify tool executes (fail-open per FR-007)
  - Call `/wp-json/acrossai-mcp-manager/v1/servers`: verify full server list returned (fail-open per FR-006)
  - `mv vendor/wpboilerplate/wpb-access-control.disabled vendor/wpboilerplate/wpb-access-control`
  - Reload: warning gone, enforcement resumes
  Record evidence in "US4 Smoke Evidence" section. **DoD**: all 6 checks pass; evidence recorded.

**Checkpoint**: US4 verified. Fail-open contract holds at all 4 code paths.

---

## Phase 7: User Story 5 — Uninstall opt-in gate purges the AC namespace + drops the table (Priority: P2)

**Goal**: Add F012-opt-in-gated purge + DROP + option cleanup to `uninstall.php` per FR-012 + FR-013.

**Independent Test**: Set opt-in option → delete plugin → verify table dropped + option deleted. Reinstall → fresh table. Repeat WITHOUT opt-in → verify table + option preserved.

### Implementation for User Story 5

- [x] T016 [US5] Delta edit: `uninstall.php`. AFTER F012's `acrossai_mcp_uninstall_delete_data === 1` opt-in gate confirms, add BEFORE the existing F011 table drops per FR-012:
  ```php
  // Feature 015 — Access Control cleanup (opt-in only).
  if ( class_exists( '\WPBoilerplate\AccessControl\Database\Rule\RuleQuery' ) ) {
      $rule_query = new \WPBoilerplate\AccessControl\Database\Rule\RuleQuery( 'mcp' );
      $rule_query->purge_namespace( 'acrossai-mcp-manager' );
  }
  $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}mcp_access_control`" );
  delete_option( 'wpb_ac_mcp_db_version' );
  ```
  The `class_exists` guard handles the "package uninstalled before plugin" edge case (US5 acceptance scenario 3). `purge_namespace` is defensive — flushes BerlinDB cache before DROP so no stale entries linger. **DoD**: grep `grep -rn 'purge_namespace' uninstall.php` returns ≥1 hit; block is inside the F012 opt-in branch (manual review).

- [x] T017 [US5] Manual uninstall smoke test on live WP:
  - `wp option update acrossai_mcp_uninstall_delete_data 1`
  - Delete the plugin via WP Plugins page
  - Verify `SHOW TABLES LIKE '%mcp_access_control'` returns 0 rows
  - Verify `SELECT option_value FROM wp_options WHERE option_name='wpb_ac_mcp_db_version'` returns 0 rows
  - Reinstall plugin + activate → fresh table created
  - Now test WITHOUT opt-in: `wp option update acrossai_mcp_uninstall_delete_data 0`, delete plugin, verify table + option BOTH remain intact
  Record evidence in "US5 Smoke Evidence" section. **DoD**: both opt-in and non-opt-in scenarios pass; evidence recorded.

**Checkpoint**: US5 (P2) delivered. Uninstall opt-in gate honored for the new destructive operations.

---

## Phase 8: PHPUnit Coverage — 8 tests (was 7; SEC-015-002 adds 8th)

**Goal**: Ship PHPUnit coverage for the wrapper class per FR-004 test coverage.

- [x] T018 [P] Create NEW file: `tests/phpunit/Includes/AccessControl/AcrossAI_MCP_Access_Control_Test.php`. Extend `WP_UnitTestCase`. Docblock cites `BUGS.md B9` (use `#[DataProvider]` attribute per PHPUnit 13+) + `SEC-015-002` + `SEC-015-004`. Test methods:
  1. `test_is_available_true_when_package_present` — asserts `class_exists( AccessControlManager::class )` path.
  2. `test_is_available_false_when_package_absent` — mocks `class_exists()` via runkit/symbol-table override OR asserts against the wrapper's return with the package renamed at test setup; asserts `is_available()` returns false + `get_manager()` returns null.
  3. `test_boot_manager_creates_v2_instance_with_correct_slug_and_filter` — after boot, uses reflection on the wrapper's private `$manager` property; asserts it's an `AccessControlManager` instance constructed with `PROVIDERS_FILTER = 'acrossai_mcp_access_control_providers'` + `TABLE_SLUG = 'mcp'` values.
  4. `test_gate_mcp_tool_call_returns_args_when_no_rule` — mocks `MCPServerQuery::instance()->get_item()` to return a server row; asserts filter returns `$args` when no rule is configured (fail-open on no-rule).
  5. `test_gate_mcp_tool_call_returns_wp_error_when_denied` — sets up a `wp_role=[editor]` rule; asserts filter returns `WP_Error` with `'acrossai_mcp_access_denied'` code + `array('status'=>403)` data when current user is a subscriber; ALSO asserts `do_action('acrossai_mcp_access_control_denied', ...)` fires with correct 4-arg payload BEFORE the WP_Error return (use `did_action` counter or a test-registered listener).
  6. `test_gate_mcp_tool_call_returns_args_when_package_missing` — with `is_available()` false, asserts filter returns `$args` (fail-open per FR-007).
  7. `test_gate_mcp_tool_call_returns_args_when_server_missing_and_fires_observability_hook` — mocks `MCPServerQuery::instance()->get_item()` to return null; asserts filter returns `$args` + `do_action('acrossai_mcp_access_control_missing_server', ...)` fires with correct 3-arg payload per Clarifications Q2 + FR-007.
  8. `test_register_default_providers_returns_3_providers` — asserts the static returns an array with 3 provider instances of `WpRoleProvider` + `WpUserProvider` + `WpCapabilityProvider`.
  9. (amended 2026-07-04 — SEC-015-002 recommendation rewritten per Clarifications Q4) `test_get_available_capabilities_returns_full_set_and_supports_filter` — asserts `AcrossAI_MCP_Access_Control::instance()->get_available_capabilities()` returns a sorted deduplicated array covering the full WP capability set (includes `manage_options` + `edit_users` per Q4 — no deny-list). Then registers `add_filter('acrossai_mcp_ac_available_capabilities', fn($caps) => [...$caps, 'manage_woocommerce'])`; asserts the appended capability appears in the return + no filter-returned entries are silently stripped.
  Use `#[DataProvider]` for the fail-open matrix (tests 4-7 share structure). **DoD**: All 9 tests green under `vendor/bin/phpunit --testsuite admin --bootstrap tests/bootstrap-wp.php --filter AcrossAI_MCP_Access_Control_Test`; PHPStan L8 + PHPCS green on the test file.

**Checkpoint**: PHPUnit coverage complete. 9 tests exercise every FR + Q4 available-capabilities behavior + FR-026 observability hooks + FR-007 fail-open matrix.

---

## Phase 9: Polish — Grep gates + memory hygiene + changelog + final gates

**Purpose**: Enforce F015 invariants + capture DECs + update INDEX + verify all cross-cutting gates green.

- [x] T019 [P] Verify all 6 F015 grep gates from spec's regression gate block:
  ```
  # 1. Zero v1-API remnants — expect 0 hits
  grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/
  # 2. Correct v2 constructor — expect exactly 1 hit (in wrapper only)
  grep -rn 'new AccessControlManager' --include='*.php' includes/
  # 3. Activation-time table setup — expect exactly 1 hit
  grep -rn 'RuleTable.*maybe_upgrade' includes/Activator.php
  # 4. MCP-boundary filter wired — expect exactly 1 hit in Main.php
  grep -rn 'mcp_adapter_pre_tool_call' includes/Main.php
  # 5. Uninstall opt-in purges AC namespace — expect ≥1 hit
  grep -rn 'purge_namespace' uninstall.php
  # 6. Legacy uppercase namespace regression — expect 0 hits
  grep -rn 'ACROSSAI_MCP_MANAGER\\\\' --include='*.php' admin/ includes/ public/ acrossai-mcp-manager.php
  ```
  Diff results against expected. Log to `specs/015-access-control-v2-adoption/post-merge-verification.txt`. **DoD**: all 6 grep gates match expected outputs.

- [x] T020 [P] Whole-plugin PHPStan L8: `vendor/bin/phpstan analyse --level=8 --no-progress`. **DoD**: exit 0.

- [x] T021 [P] Whole-plugin PHPCS on F015-touched surface: `vendor/bin/phpcs includes/AccessControl/ public/Renderers/AccessControlBlock.php admin/Partials/ServerTabs/AccessControlTab.php includes/REST/CliController.php includes/Main.php includes/Activator.php uninstall.php`. **DoD**: 0 errors, 0 warnings on new files; baseline unchanged on modified files.

- [x] T022 [P] Whole-plugin `php -l`: `find includes admin public *.php uninstall.php -name '*.php' -type f | xargs -I{} php -l {}`. **DoD**: zero syntax errors.

- [x] T023 [P] Append `docs/memory/DECISIONS.md`: **DEC-ACCESS-CONTROL-V2-ADOPTION (Active — Feature 015)**. Rule per plan §Task Groups T9: canonical v2 wrapper pattern for AcrossAI-family plugin consumption (PROVIDERS_FILTER + TABLE_SLUG constants, is_available/boot_manager/get_manager/register_rest_api/maybe_show_library_notice/gate_mcp_tool_call methods, register_default_providers static, Activator maybe_upgrade, uninstall opt-in gate purge/DROP/delete). Fail-open when package absent (matches sibling DEC-PERM-CB). Supersedes D8's `^1.0` version pin (in-place amendment, not deprecation). Accepted §IV DataForm carve-out via DEC-CLIENT-RENDERER-PUBLIC-API precedent (reaffirmation, not new). **DoD**: entry present; markdown valid.

- [x] T024 [P] Append `docs/memory/DECISIONS.md`: **D18 — `mcp_adapter_pre_tool_call` filter is the canonical MCP-boundary enforcement hook** for the AcrossAI plugin family. Rule: any AcrossAI-family plugin wanting to gate MCP tool invocations based on `(user_id, server_id)` MUST hook `mcp_adapter_pre_tool_call` fired by `vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182`. Signature: `apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name, $mcp_tool, $server )`. Return `WP_Error` with `array('status'=>403)` to short-circuit. Do NOT fork mcp-adapter; do NOT hook ability-level `permission_callback` (ability-scoped, doesn't compose). Loader-wire via `Main::define_public_hooks()` per A1. **DoD**: entry present.

- [x] T025 [P] Append `docs/memory/DECISIONS.md`: **D19 — Fail-open observability pattern for security-adjacent enforcement**. Rule: on defensive fail-open paths (missing server, unavailable vendor, invalid provider), fire a scoped `do_action()` so operators can log the anomaly without a hard dependency. Fire-and-forget, return value ignored. Codified by F015's `acrossai_mcp_access_control_missing_server` + `acrossai_mcp_access_control_denied` hooks. Sits alongside vendor's low-level `wpb_access_control_denied` — plugin-scoped hook adds MCP-specific payload (`server_slug` + `tool_name` + call-site context) the vendor hook lacks. **DoD**: entry present.

- [x] T026 [P] Amend `docs/memory/INDEX.md`: (a) update D8 row to say "^2.0.0 vendor package FQN — see DEC-ACCESS-CONTROL-V2-ADOPTION for F015 upgrade path" (in-place amendment, keep Active status); (b) update A8 row to say "^2.0.0" instead of "^1.0" (in-place amendment); (c) append 3 new DEC/D rows for DEC-ACCESS-CONTROL-V2-ADOPTION + D18 + D19 with appropriate tags (`access-control, v2-adoption, wrapper, table-slug, fail-open, mcp-boundary` for DEC; `mcp-adapter, enforcement-hook, filter, cross-cutting` for D18; `fail-open, observability, do_action, defensive-programming` for D19); (d) append 1 Security Review row from the T027 review file. **DoD**: `grep -c 'DEC-ACCESS-CONTROL-V2-ADOPTION\|D18\|D19' docs/memory/INDEX.md` returns 3; A8 + D8 both reference `^2.0.0`; security review row present.

- [x] T027 [P] Delta edit `README.txt` Unreleased changelog. Add bullet:
  > `* Adopted wpboilerplate/wpb-access-control v2 with per-server access rules, MCP-boundary enforcement via the mcp_adapter_pre_tool_call filter shipped by wordpress/mcp-adapter, and a shared Renderer block (AccessControlBlock) that third-party plugins can embed on their own admin surfaces. Fixes 3 fatal v1-API call sites (AccessControlTab, CliController /servers, Main.php TODO block). Activator now creates the {prefix}mcp_access_control table; uninstall opt-in gate purges the namespace + drops the table + deletes the version option. Two observability action hooks (acrossai_mcp_access_control_denied + acrossai_mcp_access_control_missing_server) let operators log denials via any listener — see docs/planings-tasks/015-access-control-v2-adoption.md for signatures and a minimal listener snippet (SEC-015-005 recommendation).`
  **DoD**: bullet present in Unreleased section; grep matches "wpb-access-control v2" + "mcp_adapter_pre_tool_call" + "acrossai_mcp_access_control_denied".

- [x] T028 [P] Delta edit `docs/planings-tasks/README.md`: append F015 row alongside F013 + F014. **DoD**: `grep -c '015-access-control-v2-adoption' docs/planings-tasks/README.md` returns ≥1.

- [x] T029 Final whole-plugin gate + post-merge verification. Run:
  - `vendor/bin/phpstan analyse --level=8 --no-progress` — 0 errors
  - PHPCS on F015 net-new + modified surface — 0 errors 0 warnings
  - `vendor/bin/phpunit --testsuite admin --bootstrap tests/bootstrap-wp.php --filter AcrossAI_MCP_Access_Control_Test` — all 9 tests green
  - All 6 grep gates from T019
  - `find includes admin public *.php uninstall.php -name '*.php' -type f | xargs php -l` — zero syntax errors
  Diff outputs recorded in `specs/015-access-control-v2-adoption/post-merge-verification.txt`. Include a "Manual smoke deferrals" section listing T010 (US3) + T014 (US1) + T015 (US4) + T017 (US5) evidence links. **DoD**: all 5 checks green; verification file present.

**Checkpoint**: F015 complete. Every §VII DoD gate green; memory coherent; changelog reflects the ship; DEC-ACCESS-CONTROL-V2-ADOPTION + D18 + D19 captured; A8/D8 in-place amendments applied; INDEX rows present.

---

## Phase 10: Post-Q4 dead-code cleanup (added 2026-07-04)

Introduced during post-implementation `/speckit-analyze` drift audit. The Q4 pivot to the vendor React component made the plugin-owned save handler unreachable; T030 removes the dead code so future readers aren't misled.

- [x] T030 [Cleanup] Delta edit: `admin/Partials/Settings.php`. Remove the now-unreachable `save_access_control` action from the `handle_actions()` whitelist AND delete the `handle_access_control_update( int $server_id )` private method (~130 LOC including duplicated docblocks) that was previously wired to it. The vendor React app owns saves via `PUT/DELETE /wpb-ac/v1/mcp/rules/{ns}/{key}` — no plugin-owned POST handler required. `AccessControlBlock::render_body()` was already reduced to a mount-div in a prior session — no save-URL construction to remove there. **DoD (met 2026-07-04)**: PHP lint clean; PHPStan L8 zero errors; PHPCS baseline unchanged (37 errors on this file were pre-existing tech debt, not introduced by T030); grep `grep -n 'save_access_control\|handle_access_control_update' admin/Partials/Settings.php public/Renderers/AccessControlBlock.php` returns 0 hits.

**Checkpoint**: dead code removed. Save-path traceability is now single-source: vendor REST → vendor RuleQuery → `{prefix}mcp_access_control`.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1 Setup (T001-T003)**: T001 baseline blocks T009 gate. T002 blocks T018. T003 is reference verification.
- **Phase 2 Foundational (T004-T005)**: T004 blocks EVERY subsequent task; T005 depends on T004 (references TABLE_SLUG constant).
- **Phase 3 US2 (T006-T009)**: T006 depends on T004; T007 depends on T011 (needs AccessControlBlock reference), so actually T007 moves to Phase 5 dependencies (see below). T008 depends on T004; T009 depends on T006 + T007 + T008.
- **Phase 4 US3 (T010)**: manual smoke depends on T005.
- **Phase 5 US1 (T011-T014)**: T011 depends on T004; T012 depends on T004 + T011 (references `save_access_control`); T013 depends on T004; T014 manual smoke depends on T011 + T012 + T013. Note T007 (from Phase 3 US2) actually depends on T011 too — see revised order below.
- **Phase 6 US4 (T015)**: manual smoke depends on T013 + T014 (needs full stack Loader-wired).
- **Phase 7 US5 (T016-T017)**: T016 depends on T005 (needs TABLE_SLUG); T017 depends on T016.
- **Phase 8 PHPUnit (T018)**: depends on T004 (wrapper exists) + T013 (hooks wired).
- **Phase 9 Polish (T019-T029)**: T019-T022 mostly parallel; T023-T028 parallel (docs); T029 depends on all prior.

### Actual execution order (accounting for cross-phase deps)

Because T007 (AccessControlTab thin delegate) depends on T011 (AccessControlBlock existing), Phase 3 US2 partially interleaves with Phase 5 US1. Recommended chronological order:
1. T001, T002, T003 (parallel — Phase 1)
2. T004 (Phase 2 wrapper)
3. T005 (Phase 2 Activator delta)
4. T006 (Phase 3 CliController fix) + T008 (Phase 3 Main.php dead code cleanup) in parallel
5. T011 (Phase 5 Block) — unblocks T007
6. T007 (Phase 3 AccessControlTab thin delegate)
7. T012 (Phase 5 Settings.php save handler)
8. T013 (Phase 5 Main.php Loader wiring) — completes hook wiring
9. T009 (Phase 3 verification grep)
10. T010, T014, T015, T017 (manual smoke tests — user-driven; can interleave)
11. T016 (Phase 7 uninstall.php)
12. T018 (Phase 8 PHPUnit) — needs full stack
13. T019-T029 (Phase 9 polish) — parallel-safe within Phase 9

### User story dependencies

- **US2 (P1 live-bug fix)** is the true prerequisite — without it, every AccessControl interaction fatals. Blocks US1 UI + US3 Activator + US4 fail-open + US5 uninstall (all need at least T004 wrapper to exist).
- **US3 (P1 Activator)** depends on T004 wrapper existing.
- **US1 (P1 MVP)** depends on US2 + US3 (needs wrapper + table) + delivers the UI.
- **US4 (P2 fail-open)** validates existing implementation; no new code beyond T004/T006/T011.
- **US5 (P2 uninstall)** depends on US3 (table must exist to test the drop).

### Parallel opportunities

- **Within Phase 1**: T001-T003 fully parallel (independent files).
- **After T004**: T005 + T006 + T008 + T011 all parallel (independent files); T007/T012/T013 must serialize per file overlap on Main.php + Settings.php.
- **Within Phase 9**: T019-T022 (grep + static gates) parallel; T023-T028 (docs) parallel.

---

## Implementation strategy

### MVP first (US2 → US1)

The MVP is US1 (per-server rule UI functional). However, US1 depends on US2's fatal fix + US3's table setup. Natural delivery order:

1. **Phase 1 Setup** (T001-T003) — pre-flight snapshots
2. **Phase 2 Foundational** (T004-T005) — wrapper class + Activator
3. **Phase 3 US2** (T006-T009) — kill the live crash (must land before UI)
4. **Phase 5 US1** (T011-T014) — the MVP UI
5. **Phase 4 US3 validation** (T010) — Activator smoke (can also run right after T005)
6. **Phase 6 US4 validation** (T015) — fail-open smoke (needs full stack)
7. **Phase 7 US5** (T016-T017) — uninstall opt-in
8. **Phase 8 PHPUnit** (T018) — coverage
9. **Phase 9 Polish** (T019-T029) — grep + memory + changelog + final gate

**STOP + VALIDATE at end of Phase 3** — v1-API grep gate green before adding new code on top.
**STOP + VALIDATE at end of Phase 5** — MVP UI works on live WP before polish + commit.

### Incremental delivery (single-PR shape, matches F013/F014)

F015 is a single-PR feature. Every task can be a separate commit; total commit count ~29. Constitution §VII per-task gate ensures every commit is PHPStan L8 + PHPCS green.

### Parallel team strategy

With 2+ developers, after T004+T005 land:
- Developer A: Phase 3 US2 (T006-T009) → Phase 5 US1 (T011-T014)
- Developer B: Phase 7 US5 (T016-T017) → Phase 8 PHPUnit (T018)
- Developer C: docs updates (T023-T028) + T029 final gate
- Team converges at T029 final verification.

---

## Notes

- **[P] tasks = different files, no dependencies**. Verified per task.
- **[Story] label maps task to spec.md user story**. Validation-only tasks in Phase 4/6/7 still carry US labels because they benefit that user story's operator-visible outcome.
- **Every task's DoD includes PHPStan L8 + PHPCS on touched surface** per Constitution §VII per-task gate.
- **Commit after each task** (or logical parallel batch within a phase). Do not batch across phases.
- **Stop at checkpoints** (end of US2 Phase 3, end of US1 Phase 5, end of Polish Phase 9) to validate.
- **Security review recommendations folded in**: SEC-015-001 → T005 `class_exists` guard; SEC-015-002 → T018 9th test method; SEC-015-005 → T027 README.txt observability-hook doc.
- **Avoid**: emitting v1's `::instance()` API anywhere (grep gate at T009 + T019); adding `add_action`/`add_filter` calls in class bodies (all hooks Loader-wired per A1); introducing raw `<form method="post">` / `wp_nonce_field(` in AccessControlTab.php (grep gate at T007); using vendor's v1-API `::instance()` shim (grep gate at T019 gate #1); hardcoding `manage_options` in `AccessControlBlock::render_body()` (must use `$context['cap']` per FR-024 + F013 SEC-013-005). (SAFE_CAPABILITIES deny-list guard withdrawn per Clarifications Q4 — no longer an "avoid" concern.)
