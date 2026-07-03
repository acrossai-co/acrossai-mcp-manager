# Feature Specification: Adopt `wpboilerplate/wpb-access-control` v2 — per-server access rules + MCP-boundary enforcement

**Feature Branch**: `015-access-control-v2-adoption`
**Created**: 2026-07-04
**Status**: Draft
**Input**: User description: See `docs/planings-tasks/015-access-control-v2-adoption.md` for the full detailed input; the summary is: fix a live v1→v2 API migration bug across 3 fatal call sites (`AccessControlTab.php:65`, `CliController.php:333`, `Main.php:432` commented-out block), add activation-time `RuleTable( 'mcp' )->maybe_upgrade()` in `Activator.php`, wire runtime enforcement at both the `/servers` REST endpoint AND the MCP tool-call boundary via the `mcp_adapter_pre_tool_call` filter shipped by `vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182`, and build a per-server AccessControl rule UI (WP role + WP user + WP capability pickers using v2's built-in providers) that saves via `RuleQuery::set_rule()` with `namespace='acrossai-mcp-manager'` + `key=$server_slug`. Introduce an `AcrossAI_MCP_Access_Control` wrapper class under `includes/AccessControl/` following the sibling `acrossai-abilities-manager` plugin's proven pattern verbatim. Preserve F013 AccessControlTab shape as a thin delegate — actual UI lives in a new `AccessControlBlock` under `public/Renderers/` extending `AbstractClientRenderer` (matches F013 DEC-CLIENT-RENDERER-PUBLIC-API precedent). Fail-open when the package class is missing. Uninstall opt-in gate (F012) MUST purge the new namespace + drop the table + delete the version option.

## Clarifications

### Session 2026-07-04

- Q: Should the `SAFE_CAPABILITIES` allow-list be locked to a fixed constant, extended via a filter, or freeform? → A: **Locked constant + filter with deny-list guard.** `SAFE_CAPABILITIES` is a `public const` array on `AcrossAI_MCP_Access_Control`; a filter `acrossai_mcp_ac_safe_capabilities` allows third-party additions; the wrapper enforces `array_diff($filtered, ['manage_options', 'edit_users'])` before use — the two forbidden capabilities are stripped even if a poorly-written filter callback tries to add them (defense-in-depth).
- Q: When the `mcp_adapter_pre_tool_call` filter callback receives a `$server` whose `get_server_id()` doesn't resolve via `MCPServerQuery::get_item()`, what should the callback return? → A: **Fail-open + observability action hook.** Return `$args` unchanged (mcp-adapter's routing layer already rejects unregistered server IDs upstream, so a null get_item() is almost certainly a race with concurrent DELETE — not a spoof). Also emit `do_action( 'acrossai_mcp_access_control_missing_server', $server_id, $tool_name, $user_id )` so operators can log the anomaly via any observability tool (Query Monitor, custom logger) without a hard dependency.
- Q: Should F015 add a plugin-scoped observability hook for AccessControl denials, or rely on the vendor package's low-level hook / silent? → A: **Single plugin-scoped action hook fired at both enforcement sites.** Both CliController `/servers` and the `mcp_adapter_pre_tool_call` filter callback call `do_action( 'acrossai_mcp_access_control_denied', $user_id, $server_slug, $tool_name_or_null, $context_slug )` immediately BEFORE returning the WP_Error / empty list. `$tool_name_or_null` is null at the `/servers` site (no tool involved), populated at the MCP boundary. `$context_slug` distinguishes `'cli_servers'` from `'mcp_tool_call'`. The vendor package's lower-level `wpb_access_control_denied` still fires as well; F015's hook adds the crucial MCP-scoped payload (`server_slug` + `tool_name` + call-site context) that the vendor hook lacks.
- Q: Should the AccessControlBlock UI match the sibling `acrossai-abilities-manager` plugin's single-provider dropdown shape (exposing the full WP capability list), or keep the curated `SAFE_CAPABILITIES` allow-list with deny-list guard from Q1? → A: **Match sibling — single-provider dropdown with full WP capability list.** The block renders a single "Who can access" `<select>` with 4 options (`everyone` / `wp_role` / `wp_user` / `wp_capability`). A conditional row below shows the values for the chosen provider. Capabilities panel enumerates every registered role capability via `wp_roles()->role_objects` (deduplicated + sorted), including `manage_options` and `edit_users`. Rationale: administrators bypass every rule per v2 access-hierarchy step 2, so exposing high-privilege capabilities is not a privilege-escalation vector — a rule allowing `manage_options` is a no-op (only admins hold it, and admins are always allowed). Supersedes Q1's SAFE_CAPABILITIES + deny-list guard (FR-025 rescinded); the new `acrossai_mcp_ac_available_capabilities` filter lets third-party plugins append site-specific capabilities (e.g., `manage_woocommerce`).

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site admin restricts an MCP server to specific WordPress roles (Priority: P1) 🎯 MVP

A site administrator navigates to `?page=acrossai_mcp_manager&action=edit&server=1&tab=access-control`. The tab renders a per-server rule UI with three provider pickers: **Allowed WP Roles** (multi-checkbox list from `get_editable_roles()`), **Allowed WP Users** (autocomplete input listing user IDs already saved as removable pills), and **Allowed WP Capabilities** (multi-checkbox list from a curated safe list — never `manage_options` or `edit_users`). The admin checks `editor` + `author`, clicks **Save Access Rules**, and sees a success notice. From this moment on, ANY subscriber attempting to call an MCP tool on server 1 receives a `403 access_denied` MCP protocol error; an editor or author succeeds; an admin (holding `manage_options`) always succeeds per the v2 access-hierarchy.

**Why this priority**: This is the whole feature. Today the AccessControlTab renders a warning notice because the v1-API-fatal short-circuits it; even if the fatal were fixed, there's no UI to save rules. Without this, the operator has no way to configure per-server access — the AccessControlTab is decorative.

**Independent Test**: Save `wp_role=[editor,author]` via the tab UI. Query the DB: `SELECT * FROM {$wpdb->prefix}mcp_access_control WHERE namespace='acrossai-mcp-manager' AND key='<server_slug>'` — assert 2 rows (`access_control_key='wp_role'`, `access_control_value` in `[editor,author]`). Log in as a subscriber, POST an MCP tool call to that server → assert response contains `acrossai_mcp_access_denied`. Log in as an editor, same POST → assert tool executes normally. Log in as an admin, same POST → assert tool executes (admin always allowed per v2 hierarchy).

**Acceptance Scenarios**:

1. **Given** the plugin is active + a plugin-registered server (id=1, `server_slug='mcp-adapter-default-server'`), **When** the admin opens the Access Control tab, **Then** the tab body renders 3 provider pickers pre-populated from any existing rules for this server (empty state on fresh install).
2. **Given** the admin has saved `wp_role=[editor]` for server 1, **When** a subscriber POSTs an MCP tool call to server 1, **Then** the response is a WP_Error with code `acrossai_mcp_access_denied` + HTTP status 403.
3. **Given** the same rule, **When** an editor POSTs the same tool call, **Then** the tool executes normally.
4. **Given** the admin has saved `wp_capability=[edit_posts]` for server 1, **When** a user without `edit_posts` POSTs, **Then** 403; a user with `edit_posts` succeeds.
5. **Given** the admin clicks **Clear Rules**, **When** the form saves, **Then** all rows for `(namespace='acrossai-mcp-manager', key='<server_slug>')` are deleted; subsequent POSTs succeed regardless of user role (no-rule = allow-all per v2 hierarchy).

---

### User Story 2 — Fix the 3 v1-API fatal call sites (Priority: P1)

A site administrator opens the Access Control tab on ANY MCP server. Today, this triggers a fatal PHP error (`Uncaught Error: Call to undefined method WPBoilerplate\AccessControl\AccessControlManager::instance()`) at `AccessControlTab.php:65`. The same fatal fires when a CLI-authenticated MCP client calls `/wp-json/acrossai-mcp-manager/v1/servers` (at `CliController.php:333`). This user story eliminates all v1-API call sites by routing through the new `AcrossAI_MCP_Access_Control` wrapper.

**Why this priority**: This is a **live crash bug**. The plugin ships v2 in composer.json but the code targets v1's `::instance()` singleton API — which does not exist in v2. Every operator interaction with AccessControl fatals. Even without US1's new UI, US2 alone stops the crash.

**Independent Test**: `grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/` MUST return **zero hits** after this story completes. Open the Access Control tab on live WP — no fatal, tab renders (either the new UI from US1 or a fallback notice per US4 if package absent).

**Acceptance Scenarios**:

1. **Given** the plugin is active with `wpb-access-control ^2.0.0` in vendor/, **When** the admin opens the Access Control tab, **Then** the page renders without a fatal error.
2. **Given** the same state, **When** a CLI-authenticated MCP client calls `/wp-json/acrossai-mcp-manager/v1/servers`, **Then** the endpoint responds 200 (either with the server list, or with an empty list if AccessControl denied the current user — but never a fatal 500).
3. **Given** the plugin's `includes/Main.php`, **When** a code reviewer greps for `AccessControlManager::instance`, **Then** zero hits are returned.

---

### User Story 3 — Activator creates the AccessControl table on plugin activation (Priority: P1)

A site administrator activates the plugin for the first time on a fresh install. `Activator::activate()` runs; the F011 tables are created; the **new `{$wpdb->prefix}mcp_access_control` table is also created**, and its BerlinDB version option `wpb_ac_mcp_db_version` is stamped. Without this, calling `RuleQuery::set_rule()` from US1's Save handler would silently no-op (BerlinDB returns false when the table doesn't exist).

**Why this priority**: The v2 package requires the CONSUMER plugin to create its own AC table via `RuleTable( $slug )->maybe_upgrade()`. If we don't call this on activation, the UI would save nothing, the runtime enforcement would find no rules, and the operator would have no error to see. This is the "table exists" invariant that gates every other story.

**Independent Test**: Fresh install → activate plugin → `SHOW TABLES LIKE '%mcp_access_control'` returns 1 row; `SELECT option_value FROM wp_options WHERE option_name='wpb_ac_mcp_db_version'` returns a version string.

**Acceptance Scenarios**:

1. **Given** a fresh WordPress install with the plugin uploaded, **When** the admin activates the plugin via `wp plugin activate` or the Plugins page, **Then** `{$wpdb->prefix}mcp_access_control` table is created with the v2 schema (columns: `id`, `namespace`, `key`, `access_control_key`, `access_control_value`, `created_at`, `updated_at`).
2. **Given** an existing install already on v015+, **When** the admin re-activates the plugin, **Then** the table is preserved (idempotent — `maybe_upgrade()` no-ops when the version option matches).
3. **Given** an existing install where the operator manually dropped the table, **When** the admin re-activates the plugin, **Then** the table is recreated (idempotent restore).

---

### User Story 4 — Graceful degradation when the wpb-access-control package is absent (Priority: P2)

An operator running an unusual composer setup (missing `vendor/wpboilerplate/wpb-access-control/`) or a fresh clone that hasn't yet run `composer install` opens the plugin. Instead of fataling, the plugin:
1. Shows an admin_notices warning ("wpb-access-control library is not loaded — MCP AccessControl rules are inactive and all tool calls will pass").
2. `AcrossAI_MCP_Access_Control::is_available()` returns false.
3. All AccessControl code paths fail-open: the tab renders an info notice, `CliController /servers` returns full server list, `mcp_adapter_pre_tool_call` filter returns `$args` unchanged so tool calls execute normally.

**Why this priority**: Matches sibling plugin's DEC-PERM-CB pattern — package absence is treated as "no rules configured," which is the WordPress-core-style graceful degradation. Never fail-closed silently. This is the safety net that prevents "operator upgrades composer, forgets to run install, entire plugin becomes unusable."

**Independent Test**: Manually rename `vendor/wpboilerplate/wpb-access-control/` to `vendor/wpboilerplate/wpb-access-control.disabled/`. Reload wp-admin — an amber warning notice fires. Open the Access Control tab — an info notice ("Access Control is inactive because the wpb-access-control library is not loaded. Tool calls pass through unrestricted.") renders instead of the form. POST an MCP tool call — succeeds. Restore the vendor dir → warning gone, tab UI functional again.

**Acceptance Scenarios**:

1. **Given** `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` returns false, **When** any admin page loads, **Then** an amber `admin_notices` warning fires (only to users with `manage_options`).
2. **Given** the same state, **When** the admin opens the Access Control tab, **Then** the tab renders an info notice + does NOT emit the form fields or the Save button.
3. **Given** the same state, **When** an MCP tool call is POSTed, **Then** the `mcp_adapter_pre_tool_call` filter returns `$args` unchanged and the tool executes normally.
4. **Given** the same state, **When** the CLI `/servers` REST endpoint fires, **Then** the endpoint returns the full server list without invoking `user_has_access()` (the `is_available()` guard short-circuits before the manager call).

---

### User Story 5 — Uninstall opt-in gate purges the AccessControl namespace + drops the table (Priority: P2)

A site administrator running WordPress > Plugins > Delete on this plugin has previously opted-in to full data deletion by setting `acrossai_mcp_uninstall_delete_data = 1` (per F012's opt-in gate). `uninstall.php` fires and, in addition to the F011 tables it already drops, ALSO:
1. Calls `RuleQuery::purge_namespace('acrossai-mcp-manager')` to delete all AC rules (defensive — flushes any BerlinDB cache before the DROP).
2. Runs `DROP TABLE IF EXISTS {$wpdb->prefix}mcp_access_control`.
3. Deletes `wpb_ac_mcp_db_version` option.

Without opt-in, ALL of the above is skipped — the table + rules survive uninstall. Preserve-by-default is the F012 invariant.

**Why this priority**: F012 established a preserve-by-default uninstall contract to satisfy WP.org guideline #5. Any new persistent storage F015 introduces MUST honor the same contract, or the plugin becomes non-compliant.

**Independent Test**: Set `update_option('acrossai_mcp_uninstall_delete_data', 1)`. Delete the plugin via Plugins page. Verify: `SHOW TABLES LIKE '%mcp_access_control'` returns 0 rows; `SELECT option_name FROM wp_options WHERE option_name='wpb_ac_mcp_db_version'` returns 0 rows. Reinstall + reactivate → fresh table created. THEN test without opt-in: set option to `0`, delete plugin → table + option BOTH preserved.

**Acceptance Scenarios**:

1. **Given** `acrossai_mcp_uninstall_delete_data === 1`, **When** the plugin is uninstalled, **Then** the AC table is dropped + version option deleted + purge_namespace fires.
2. **Given** the option is 0 (default), **When** the plugin is uninstalled, **Then** the AC table + version option BOTH remain intact.
3. **Given** the wpb-access-control package has been uninstalled from vendor/ BEFORE the plugin's uninstall runs, **When** `uninstall.php` fires with opt-in, **Then** the `purge_namespace` call is skipped (guarded by `class_exists`) but the raw `DROP TABLE IF EXISTS` still succeeds + version option still deleted.

---

### Edge Cases

- What if `MCPServerQuery::instance()->get_item($server_id)` returns null inside the `mcp_adapter_pre_tool_call` filter (e.g., a stale server_id from a race with a concurrent DELETE)? Per FR-007 + Clarifications Q2: the filter fires `do_action( 'acrossai_mcp_access_control_missing_server', $server_id, $tool_name, $user_id )` for observability, then returns `$args` unchanged (fail-open). The MCP tool call executes rather than fataling — mcp-adapter's routing layer already rejected the request if the target server ID isn't registered upstream. The v2 access hierarchy's step 2 (admin-always-allow) still applies, so `manage_options` users never lose access via this path.
- What if a third-party plugin appends an invalid FQN via `add_filter('acrossai_mcp_access_control_providers', ...)` (e.g., a class that doesn't extend `AbstractProvider`)? The v2 package's provider validation catches it at `AccessControlManager::load_providers()` — invalid providers are silently skipped, no fatal.
- What if the `save_access_control` POST arrives with no roles/users/capabilities selected AND no Clear-Rules button clicked? The save handler treats empty selections as "clear this specific provider" — calls `clear_rule($ns, $key)` for each provider that's absent from the submission. Operator can leave all providers unchecked to remove restrictions on this server without hitting Clear Rules.
- What if the operator's `wp-config.php` has `DB_CHARSET != utf8mb4`? BerlinDB `RuleTable::maybe_upgrade()` runs `dbDelta()` which respects the site's charset; the schema uses `varchar(100)` and `varchar(255)` columns which fit both charsets. No custom migration needed.
- What if the mcp-adapter package is updated and the `mcp_adapter_pre_tool_call` filter signature changes? A PHPUnit test (TASK-8 case 4/5/6) covers the current 4-arg signature. If the signature changes, the test fails at CI. Filter wiring in `Main.php` must be updated to match — no silent regression.
- What if a subscriber is granted a rule via `wp_user=[42]` but their user_id 42 is later deleted from WordPress? The v2 provider `WpUserProvider::user_has_access()` compares `$user_id === (int) $rule_value`. A deleted user's id no longer maps to a session, so `get_current_user_id()` never returns 42 — the rule effectively becomes dead. The operator can clean it up via the tab UI (the pill for deleted user 42 shows `<user #42 (deleted)>`).

---

## Requirements *(mandatory)*

### Functional Requirements

**AcrossAI_MCP_Access_Control wrapper class:**

- **FR-001**: The plugin MUST introduce `includes/AccessControl/AcrossAI_MCP_Access_Control.php` — a singleton wrapper class in namespace `AcrossAI_MCP_Manager\Includes\AccessControl` matching the sibling plugin's `AcrossAI_Abilities_Access_Control` shape verbatim (protected static `$instance = null;` → `public static function instance(): self` → `private function __construct() {}` per A2 + S6 + F012 SettingsMenu member ordering).
- **FR-002**: The wrapper MUST define two public class constants: `PROVIDERS_FILTER = 'acrossai_mcp_access_control_providers'` and `TABLE_SLUG = 'mcp'`. Both are referenced from Activator, Main.php, uninstall.php, and the AccessControlBlock — never inlined as magic strings elsewhere.
- **FR-003**: The wrapper MUST expose these public methods: `is_available(): bool` (returns `class_exists( AccessControlManager::class )`); `boot_manager(): void` (lazy-instantiates `new AccessControlManager( self::PROVIDERS_FILTER, self::TABLE_SLUG )` — NEVER uses v1's `::instance()`); `get_manager(): ?AccessControlManager` (returns null when unavailable); `register_rest_api(): void` (delegates to `$this->get_manager()->register_rest_api()`); `maybe_show_library_notice(): void` (fires an amber `wp_admin_notice` when package absent — only to `manage_options`-holders); `gate_mcp_tool_call( array $args, string $tool_name, $mcp_tool, $server )` (the mcp_adapter_pre_tool_call filter callback).
- **FR-004**: The wrapper MUST expose a public static method `register_default_providers( array $providers ): array` that appends `new WpRoleProvider()` + `new WpUserProvider()` + `new WpCapabilityProvider()` to the array and returns it.

**Activation-time table setup:**

- **FR-005**: `includes/Activator.php::activate()` MUST call `(new RuleTable( AcrossAI_MCP_Access_Control::TABLE_SLUG ))->maybe_upgrade()` alongside the existing 4 F011 table calls. `RuleTable::maybe_upgrade()` is idempotent — safe on fresh install AND on re-activation.

**Runtime enforcement at 2 sites:**

- **FR-006**: `includes/REST/CliController.php:333` MUST use `AcrossAI_MCP_Access_Control::instance()->get_manager()->user_has_access( $user_id, 'acrossai-mcp-manager', $ns . '/' . $route )` for the `/servers` REST enforcement. It MUST fail-open when `is_available()` returns false (skip the `user_has_access` call, return full server list). On deny, it MUST fire `do_action( 'acrossai_mcp_access_control_denied', $user_id, $ns . '/' . $route, null, 'cli_servers' )` (per FR-026 + Clarifications Q3) immediately BEFORE returning HTTP 200 with `array('servers'=>array())`. The silent-empty-list-on-deny stays — matches today's enumeration-defense semantics; the hook adds observability without changing the wire response.
- **FR-007**: `includes/Main.php::define_public_hooks()` MUST Loader-wire an `add_filter( 'mcp_adapter_pre_tool_call', $access_control, 'gate_mcp_tool_call', 10, 4 )` callback. The callback resolves `$server->get_server_id()` → `$server_slug` via `MCPServerQuery::instance()->get_item()`, then calls `user_has_access( get_current_user_id(), 'acrossai-mcp-manager', $server_slug )`. On deny, MUST fire `do_action( 'acrossai_mcp_access_control_denied', get_current_user_id(), $server_slug, $tool_name, 'mcp_tool_call' )` (per FR-026 + Clarifications Q3) immediately BEFORE returning `new WP_Error( 'acrossai_mcp_access_denied', __( 'You do not have permission to invoke tools on this MCP server.', 'acrossai-mcp-manager' ), array( 'status' => 403 ) )`. On `is_available()` false, returns `$args` unchanged (fail-open per FR-024 principle). On `MCPServerQuery::get_item()` returning null (race with concurrent DELETE per Clarifications Q2), MUST both: (a) fire `do_action( 'acrossai_mcp_access_control_missing_server', $server_id, $tool_name, get_current_user_id() )` for operator observability, and (b) return `$args` unchanged (fail-open — mcp-adapter's routing layer already rejects unregistered server IDs upstream).

**Per-server rule UI:**

- **FR-008**: The plugin MUST introduce `public/Renderers/AccessControlBlock.php` — a singleton extending `AbstractClientRenderer` (matches F013 DEC-CLIENT-RENDERER-PUBLIC-API). Docblock includes `@since 0.0.7 @experimental May change without notice before 1.0.0`.
- **FR-009** (amended 2026-07-04 — vendor React adoption): `AccessControlBlock::render_body()` MUST short-circuit with an info notice when `AcrossAI_MCP_Access_Control::instance()->is_available()` returns false. When available, it MUST emit a mount `<div id="acrossai-mcp-ac-root" data-server-slug="…">` for the vendor's React `<AccessControl>` component (shipped by `vendor/wpboilerplate/wpb-access-control/js/AccessControl.js`, aliased in `webpack.config.js` as `@wpb/access-control`). The React app owns the provider dropdown ("No user access added by admin" / "Everyone" / "WordPress Role" / "Users" / "WordPress Capability"), the role/capability checkbox panels, and the user autocomplete search — this PHP block only emits the mount div + defers to the vendor. Configuration (`pluginSlug`, `resourceKey`, `restApiRoot`, `nonce`) is `wp_localize_script`'d by `admin/Main.php::maybe_enqueue_access_control_app()`. The available capabilities panel comes from vendor's REST route `GET /wpb-ac/v1/mcp/capabilities` which returns the full WP capability set from `wp_roles()->role_objects` (Q4 rationale: admin bypass hierarchy makes exposing `manage_options`/`edit_users` non-escalatory). Third-party plugins may append site-specific capabilities via `add_filter('acrossai_mcp_ac_available_capabilities', ...)`.
- **FR-010** (amended 2026-07-04 — vendor React adoption): Rule persistence is owned by the vendor React app calling vendor REST endpoints (`PUT /wpb-ac/v1/mcp/rules/{namespace}/{resource_key}`, `DELETE /wpb-ac/v1/mcp/rules/{namespace}/{resource_key}`), NOT a plugin-owned PHP save handler. Vendor REST auth is `manage_options` cap check via the vendor's own `permission_callback`. The `save_access_control` action + `handle_access_control_update()` method in `admin/Partials/Settings.php` shipped in the initial F015 draft are now DEAD CODE and MAY be removed in a follow-up cleanup task (T030).
- **FR-011**: `admin/Partials/ServerTabs/AccessControlTab.php` MUST become a THIN DELEGATE to `AccessControlBlock` (matches F013 NpmTab/ClientsTab/ClaudeConnectorTab shape). The `v1-API AccessControlManager::instance(...)` call at line 65 MUST be deleted. The tab's `render_body()` calls `AccessControlBlock::instance()->render( (int) $server['id'], [ ... ] )` with `'context' => 'admin'` + `'cap' => 'manage_options'` + `'submit_target_url' => $this->server_edit_url($server, 'access-control')` + `'nonce_action' => 'acrossai_mcp_manager_server_' . (int) $server['id']`.

**Uninstall opt-in gate:**

- **FR-012**: `uninstall.php` MUST, after F012's `acrossai_mcp_uninstall_delete_data === 1` opt-in check passes, add:
  - `class_exists('\WPBoilerplate\AccessControl\Database\Rule\RuleQuery')` guard around `(new RuleQuery( 'mcp' ))->purge_namespace('acrossai-mcp-manager')`
  - `$wpdb->query('DROP TABLE IF EXISTS `{$wpdb->prefix}mcp_access_control`')`
  - `delete_option('wpb_ac_mcp_db_version')`
- **FR-013**: When the opt-in gate is 0 (default), the entire F015 cleanup block MUST NOT fire — table + option + rules ALL survive uninstall. When the opt-in gate is 1 AND the wpb-access-control package is present (the happy path), all three cleanup steps fire in sequence: `purge_namespace` → `DROP TABLE IF EXISTS` → `delete_option`. When the opt-in gate is 1 but the wpb-access-control package is absent at uninstall time (race with vendor removal), only `purge_namespace` skips (guarded by `class_exists`); the raw `DROP TABLE IF EXISTS` + `delete_option` MUST still fire so the destructive intent from opt-in is honored. Preserve-by-default is the invariant inherited from F012.

**Loader wiring in Main.php:**

- **FR-014**: `includes/Main.php::define_public_hooks()` MUST Loader-wire (per A1): `add_action('init', $access_control, 'boot_manager', 5)`; `add_action('rest_api_init', $access_control, 'register_rest_api')`; `add_action('admin_notices', $access_control, 'maybe_show_library_notice')`; `add_filter('mcp_adapter_pre_tool_call', $access_control, 'gate_mcp_tool_call', 10, 4)`; `add_filter( PROVIDERS_FILTER, [ClassName, 'register_default_providers'] )`. All 5 hooks live in Main.php, none in the wrapper class body.
- **FR-015**: `includes/Main.php:432` commented-out `AccessControlManager::instance(...)` line + the empty `if (class_exists(...)) { /* TODO Phase 7 */ }` block above line 374 MUST both be deleted. They are replaced by the FR-014 wiring.

**Regression + hygiene:**

- **FR-016**: The plugin MUST NOT contain any use of v1's `::instance()` API. Grep gate: `grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/` MUST return **zero hits** after TASK-3.
- **FR-017**: The `new AccessControlManager(...)` constructor call MUST appear EXACTLY once in the plugin — inside `AcrossAI_MCP_Access_Control::boot_manager()`. Grep gate: `grep -rn 'new AccessControlManager' --include='*.php' includes/` returns exactly 1 hit.
- **FR-018**: The `mcp_adapter_pre_tool_call` filter MUST be wired exactly once — in `Main.php::define_public_hooks()`. Grep gate: `grep -rn 'mcp_adapter_pre_tool_call' includes/Main.php` returns exactly 1 hit.
- **FR-019**: The `RuleTable(...)->maybe_upgrade()` call MUST appear exactly once — in `Activator.php`. Grep gate: `grep -rn 'RuleTable.*maybe_upgrade' includes/Activator.php` returns exactly 1 hit.
- **FR-020**: The uninstall.php purge/drop code MUST live behind the F012 opt-in gate. Grep gate: `grep -rn 'purge_namespace' uninstall.php` returns ≥1 hit; manual review confirms it's inside the opt-in branch.
- **FR-021**: The plugin MUST NOT introduce any file that uses the legacy uppercase namespace `ACROSSAI_MCP_MANAGER\`. Regression grep gate inherited from F013 — must return zero both before AND after every task.
- **FR-022**: The `AccessControlTab.php` content MUST be a thin delegate — no raw `<form method="post">`, no `wp_nonce_field(`, no `<pre>`/`<textarea>`/rule-field HTML. Matches F013 FR-026 pattern. Grep gate: those strings must return 0 hits in `AccessControlTab.php`.

**Security:**

- **FR-023**: The AccessControlBlock save handler MUST verify a nonce bound to `'acrossai_mcp_manager_server_' . (int) $server['id']` (matches F013 `AbstractServerTab::nonce_field()` convention) AND `current_user_can('manage_options')` before writing rules.
- **FR-024**: The AccessControlBlock MUST NOT hardcode `'manage_options'` in its render body — cap check uses `$context['cap']` per F013 SEC-013-005 pattern (BuddyBoss embed may legitimately need `cap='read'` for a read-only view).
- **FR-025** (SUPERSEDED by Clarifications Q4): the `SAFE_CAPABILITIES` allow-list + `acrossai_mcp_ac_safe_capabilities` filter + `array_diff` deny-list guard shipped in the initial F015 draft are **withdrawn**. The AccessControlBlock now exposes the FULL WP capability set via `AcrossAI_MCP_Access_Control::get_available_capabilities()` (matches the sibling plugin's User Access UX). Rationale: administrators bypass every rule per v2 access-hierarchy step 2, so exposing `manage_options` or `edit_users` in the picker is not a privilege-escalation vector — a rule matching either capability is a no-op because only admins hold them. The `acrossai_mcp_ac_available_capabilities` filter provides the extension point third-party plugins use to append site-specific capabilities. The PHPUnit test previously asserting the deny-list guard is rewritten to assert `get_available_capabilities()` returns the sorted deduplicated full set + honors the filter.

**Observability:**

- **FR-026** (per Clarifications Q3): Both enforcement sites (CliController `/servers` per FR-006, `mcp_adapter_pre_tool_call` filter callback per FR-007) MUST fire `do_action( 'acrossai_mcp_access_control_denied', int $user_id, string $server_slug_or_route, ?string $tool_name, string $context_slug )` immediately BEFORE returning the WP_Error / empty list on deny. Contract: `$tool_name` is null at the `/servers` site (no tool involved), non-null at the MCP boundary. `$context_slug` is one of `'cli_servers'` or `'mcp_tool_call'` — new context slugs added by future features MUST be documented in this FR. The hook is fire-and-forget (return value ignored). The vendor package's lower-level `wpb_access_control_denied` still fires; F015's hook adds the MCP-scoped payload (`server_slug` + `tool_name` + call-site context) the vendor hook lacks. The `missing_server` observability hook per FR-007 fires with `do_action( 'acrossai_mcp_access_control_missing_server', int $server_id, string $tool_name, int $user_id )` — separate action name, separate concern.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (matches plugin's Feature 010 baseline).
**WordPress Version**: 6.9+.
**Multisite**: Single-site only (matches prior features).
**Required Plugins / Packages**: `wpboilerplate/wpb-access-control ^2.0.0` (Composer hard-require, already pinned at composer.json:18); `wordpress/mcp-adapter ^0.5.0` (Composer hard-require, provides the `mcp_adapter_pre_tool_call` filter). No new required plugins.
**Optional Integrations**: The wpb-access-control package is a Composer hard-require but the code MUST still fail-open gracefully if the vendor autoload is somehow missing (unusual composer setup, mid-upgrade race, or manual vendor/ removal) — matches sibling plugin's DEC-PERM-CB pattern.

### Module Placement

**PHP Classes**:
- `includes/AccessControl/AcrossAI_MCP_Access_Control.php` → namespace `AcrossAI_MCP_Manager\Includes\AccessControl` — singleton wrapper for v2 AccessControlManager.
- `public/Renderers/AccessControlBlock.php` → namespace `AcrossAI_MCP_Manager\Public\Renderers` — per-server rule UI (extends F013 `AbstractClientRenderer`).

**Hook Registration**: All `add_action`/`add_filter` calls MUST live in `includes/Main.php::define_public_hooks()` — the 5 F015 hooks (boot_manager on init, register_rest_api on rest_api_init, maybe_show_library_notice on admin_notices, gate_mcp_tool_call on mcp_adapter_pre_tool_call, register_default_providers on the providers filter) are all Loader-wired per A1.

### Admin UI Requirements

**Pre-approved DataForm carve-out** (this feature's scope):
- The AccessControlBlock's per-server rule form emits WP core `<input type="checkbox">` + `<input type="text">` + `submit_button()` — NOT `@wordpress/dataviews` DataForm — matching the F013 DEC-CLIENT-RENDERER-PUBLIC-API + DEC-VENDOR-SETTINGS-TAB-INTEGRATION carve-out. Per-server admin surfaces on the pre-existing `?page=acrossai_mcp_manager` screen are exempted from §IV.
- No new WP_List_Table classes are introduced. No new admin submenu is introduced.

### REST API Contract

F015 does NOT add any new REST routes owned by our plugin. It:
1. **Modifies** the existing `/wp-json/acrossai-mcp-manager/v1/servers` route at `CliController.php:333` — the permission gate now routes through the wrapper (fixes the v1→v2 fatal). No signature change; the endpoint contract is unchanged (empty list on deny stays).
2. **Consumes** the vendor package's built-in REST route registration via `$manager->register_rest_api()` — this exposes `/wpb-ac/v1/mcp/rules/...` for programmatic rule management (optional — not required for the F015 UI which uses direct `set_rule()` calls). The vendor route's permission check MUST honor `current_user_can('manage_options')` by default; F015 does NOT override that.

**`permission_callback` rule**: No new mutating routes introduced. The existing `/servers` route already has an explicit `permission_callback` (unchanged by F015).

### Database / Storage

**New custom DB table** (owned by vendor package):
- Table: `{wpdb->prefix}mcp_access_control` — schema owned by `RuleTable::get_columns()` in the vendor package (columns: `id`, `namespace`, `key`, `access_control_key`, `access_control_value`, `created_at`, `updated_at`; unique key `(namespace, key, access_control_value)`).
- Justification: `wp_options` cannot model the per-(server, provider, value) many-to-one relationship the v2 provider architecture requires. The v2 package deliberately owns this schema — our plugin only supplies the slug.
- Activation hook: `Activator::activate()` calls `(new RuleTable( 'mcp' ))->maybe_upgrade()` (BerlinDB idempotent migration).
- Uninstall hook: `uninstall.php` gated behind F012 opt-in.

**New WordPress option** (owned by vendor package):
- Option name: `wpb_ac_mcp_db_version` — BerlinDB version tracking. Set by `maybe_upgrade()`, deleted by uninstall opt-in.

**No other persistent storage introduced by F015.**

### Security Checklist

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` (AccessControlBlock save via the F013 `'acrossai_mcp_manager_server_' . $id` action per FR-023).
- [ ] All admin page renders check capability via `$context['cap']` — admin tab passes `manage_options`; third-party embedders can override per F013 SEC-013-005.
- [ ] REST route `/servers` retains its existing explicit `permission_callback` (unchanged by F015).
- [ ] All user input (submitted role/user_id/capability lists) sanitized via `sanitize_key()` (for roles/capabilities) + `absint()` (for user IDs) BEFORE passing to `RuleQuery::set_rule()`.
- [ ] All output escaped at point of rendering — `esc_html()` for role/user display names, `esc_attr()` for form field values, `esc_url()` for form action URLs.
- [ ] No new DB queries introduced by F015 that bypass BerlinDB — all reads/writes go through `RuleQuery` which uses `$wpdb->prepare()` internally.
- [ ] Available-capabilities panel exposes full WP capability set via `get_available_capabilities()` — Q4 rationale (admin bypass hierarchy) explicitly makes high-privilege capabilities safe to expose; FR-025's SAFE_CAPABILITIES deny-list guard is withdrawn.
- [ ] No file uploads.

### Key Entities

- **Rule**: A per-server (namespace='acrossai-mcp-manager', key=`$server_slug`, access_control_key ∈ {`wp_role`, `wp_user`, `wp_capability`}, access_control_value ∈ string) tuple stored in `{prefix}mcp_access_control`. Multiple values per (namespace, key, ac_key) are allowed — one row per value. Unique key `(namespace, key, access_control_value)` prevents duplicate rows.
- **Provider**: A concrete `AbstractProvider` subclass registered via `apply_filters('acrossai_mcp_access_control_providers', [])`. The 3 built-in providers (`WpRoleProvider`, `WpUserProvider`, `WpCapabilityProvider`) are registered by F015; third-party plugins can append their own (BuddyBoss profile-type, MemberPress membership, custom) via the same filter.
- **Wrapper**: The `AcrossAI_MCP_Access_Control` singleton owns the plugin-scoped v2 `AccessControlManager` instance. It exposes `get_manager()` to consumers (AccessControlBlock, CliController, filter callback). Never exposes the raw v2 class outside the wrapper — every consumer routes through `AcrossAI_MCP_Access_Control`.
- **Server-slug context**: The rule's `key` is `$server_slug` from the MCPServer row (F011). At the enforcement site (filter callback), `$server->get_server_id()` from the mcp-adapter provides the id → we resolve to `$server_slug` via `MCPServerQuery::instance()->get_item()`.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings on all F015-touched surfaces (`vendor/bin/phpcs includes/AccessControl/ public/Renderers/AccessControlBlock.php admin/Partials/ServerTabs/AccessControlTab.php includes/REST/CliController.php includes/Main.php includes/Activator.php uninstall.php`)
- [ ] PHPStan level 8: zero errors plugin-wide (`vendor/bin/phpstan analyse --level=8 --no-progress`)
- [ ] PHPUnit tests written and passing — `AcrossAI_MCP_Access_Control_Test` with 7 cases (is_available true/false, boot_manager construction, gate_mcp_tool_call fail-open × 2, gate_mcp_tool_call deny, register_default_providers returns 3)
- [ ] Security checklist above: all applicable items verified
- [ ] All F015 hooks wired in `Main.php::define_public_hooks()` — no `add_action`/`add_filter` in class bodies
- [ ] Zero code duplication vs. sibling plugin — the `AcrossAI_MCP_Access_Control` wrapper is a diff-and-namespace-swap of `AcrossAI_Abilities_Access_Control`
- [ ] All F015 files use PascalCase namespace `AcrossAI_MCP_Manager\`; legacy uppercase namespace grep returns 0
- [ ] `npm run validate-packages` passes (no new npm dependencies added)

### Measurable Outcomes

- **SC-001**: A site admin can open the Access Control tab on any MCP server without triggering a fatal PHP error. Grep gate `grep -rn 'AccessControlManager::instance' --include='*.php' admin/ includes/ public/` returns 0 hits.
- **SC-002**: Saving `wp_role=[editor]` for server X via the tab UI results in exactly 1 row in `{prefix}mcp_access_control` with `(namespace='acrossai-mcp-manager', key='<server_slug>', access_control_key='wp_role', access_control_value='editor')`. Subsequent MCP tool calls on server X from a subscriber return `WP_Error` with code `acrossai_mcp_access_denied` AND fire `do_action('acrossai_mcp_access_control_denied', ...)` per FR-026 — the action hook is the CI-testable signal (PHPUnit hooks the action + asserts arguments); from an editor the tool executes and the hook does NOT fire; from an admin always succeeds (admin-always-allow per v2 access hierarchy).
- **SC-003**: When `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` returns false, ALL AccessControl code paths fail-open (tab renders info notice, `/servers` returns full list, `mcp_adapter_pre_tool_call` filter returns `$args` unchanged) and an amber admin_notice fires exactly once per page load to `manage_options` users.
- **SC-004**: On fresh install, `wp plugin activate` creates the `{prefix}mcp_access_control` table + stamps `wpb_ac_mcp_db_version`. On existing install re-activation, both are idempotent no-ops.
- **SC-005**: Uninstall with `acrossai_mcp_uninstall_delete_data === 1` drops the table + deletes the version option + purges the namespace. Uninstall without opt-in preserves ALL of the above intact.
- **SC-006**: All 5 F015 grep gates from FR-016..FR-020 return the expected counts. Combined with the F013 legacy-namespace regression gate (FR-021), the F015 branch produces zero drift from prior contracts.
- **SC-007**: `AccessControlTab.php` content grep for `<form method="post"|wp_nonce_field(|AccessControlManager::instance` returns 0 hits — thin-delegate shape preserved (matches F013 FR-026 pattern).

---

## Assumptions

- The reference sibling plugin's `AcrossAI_Abilities_Access_Control` wrapper class at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Abilities_Access_Control.php` is authoritative for the v2-consumer pattern — the F015 wrapper is a diff-and-namespace-swap. No design innovation, no experimental structure.
- The `wpb-access-control` v2 package's `AccessControlManager` constructor signature (`new AccessControlManager( $providers_filter, $table_slug )`) is stable within the `^2.0.0` semver range — no breaking changes expected. If a future 3.0.0 releases with a signature change, this plan's grep gate at FR-017 catches the regression at CI.
- The `wordpress/mcp-adapter` `^0.5.0` package's `mcp_adapter_pre_tool_call` filter signature (`apply_filters( $filter, $args, $tool_name, $mcp_tool, $server )`) is stable. If a future release changes the args, the PHPUnit tests at TASK-8 catch it at CI.
- All support layers (F011 MCPServerQuery, F012 uninstall opt-in gate, F013 AbstractClientRenderer + AccessControlTab shape) are already in place — F015 only consumes them, never re-implements.
- The `acrossai-co/main-menu` vendor package is present (D15/DEV4 hard-require).
- Multisite support is out of scope. Single-site only.
- Manual smoke tests on live WP will be performed by the human reviewer before merge; automated PHPUnit + PHPStan + PHPCS gates run in CI.
- Per Clarifications Q4 the initial SAFE_CAPABILITIES allow-list is withdrawn; the available-capabilities panel exposes the full WP capability set including `manage_options` / `edit_users`. Admin bypass hierarchy (v2 step 2) is what makes this non-escalatory — a rule matching either high-privilege capability is a no-op because only admins hold them and admins are always allowed.
