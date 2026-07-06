# Feature Specification: Remove Claude Connectors

**Feature Branch**: `016-remove-claude-connectors`
**Created**: 2026-07-06
**Status**: Draft
**Input**: User description: "Remove all Claude Connector code — the OAuth 2.1 authorization server, the admin tab, the per-server audit log, the settings toggle, the client-block shortcode, the CSS bundle, the three `claude_connector_*` columns on `wp_acrossai_mcp_servers`, and the two dedicated OAuth database tables (`wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_audit`). The shared OAuth infrastructure (`Storage`, `TokenController`, `BearerAuth`, `PKCE`, `AuditLog`, `CliCommand`) is used only by Connectors — full teardown includes those. The CLI auth stack (`FrontendAuth`, `CliController`, App Passwords, `wp_acrossai_mcp_cli_auth_logs`) is a separate flow and stays untouched. Reference: `docs/planings-tasks/016-remove-claude-connectors.md`."

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site admin sees a leaner MCP Manager UI (Priority: P1)

A site administrator managing MCP servers no longer sees any Claude Connectors surface: no per-server "Claude Connector" tab, no "Claude Connectors" section on the Settings → MCP page, no `[acrossai_mcp_claude_connector_block]` shortcode output on the public site. The 10 surviving per-server tabs (Overview, npm, Clients, WP-CLI, Tools, Abilities, Access Control, MCP Tracker, Update Server, Danger Zone) continue to render and function.

**Why this priority**: This is the visible-to-admin surface of the retirement. Users interacting with the plugin day-to-day must see the leaner UI immediately after the update; a lingering broken tab or empty section is a defect.

**Independent Test**: On an install with the previous plugin version, run the plugin update, navigate to any MCP server's edit page, and confirm exactly 10 tabs render with no "Claude Connector" entry. Navigate to Settings → MCP and confirm no "Claude Connectors" section exists. Insert `[acrossai_mcp_claude_connector_block server=1]` on a test page and confirm it renders as literal shortcode text (not registered).

**Acceptance Scenarios**:

1. **Given** the plugin is active on a WordPress site with existing MCP servers, **When** the site admin navigates to a server-edit page, **Then** exactly 10 tabs render and none is titled "Claude Connector".
2. **Given** the plugin is active, **When** the site admin navigates to Settings → MCP, **Then** no "Claude Connectors" section, toggle, or description appears on the page.
3. **Given** the shortcode `[acrossai_mcp_claude_connector_block server=1]` is placed on a public page, **When** a visitor loads that page, **Then** the shortcode does not render as a registered block; the raw shortcode text is returned unchanged.
4. **Given** an admin submits `POST admin.php?action=save_claude_connector` on a server page, **When** the request is processed, **Then** the action is not in the allow-list and no OAuth-related data is written.

---

### User Story 2 — Existing install self-heals on reactivation (Priority: P1)

A site running the previous plugin version has the three `claude_connector_*` columns on `wp_acrossai_mcp_servers` and two OAuth tables populated with historical data. After the plugin is updated and reactivated, both OAuth tables and their two `db_version` options are removed, the three connector columns are dropped from the MCP Servers table, and the surviving MCP server data (server_name, server_slug, is_enabled, etc.) is preserved intact.

**Why this priority**: A retirement that leaves stale schema behind is worse than the feature being present — it clutters the database, breaks fresh backups/migrations, and misleads future maintainers. Users must trust reactivation to be a clean self-healing step.

**Independent Test**: Take a WordPress install with the previous plugin version and populated MCP server data. Note `SELECT COUNT(*) FROM wp_acrossai_mcp_servers`. Deactivate → update → reactivate the plugin. Confirm the OAuth tables are gone, the three connector columns are gone, `SELECT COUNT(*)` on MCP Servers returns the same number, and `SHOW WARNINGS` after reactivation is empty.

**Acceptance Scenarios**:

1. **Given** an install with `wp_acrossai_mcp_oauth_tokens` and `wp_acrossai_mcp_oauth_audit` tables present, **When** the plugin is reactivated on the new version, **Then** both tables are dropped and the two matching `db_version` WordPress options (`acrossai_mcp_oauth_tokens_db_version`, `acrossai_mcp_oauth_audit_db_version`) are deleted.
2. **Given** an install where `wp_acrossai_mcp_servers` has 13 columns, **When** the plugin is reactivated on the new version, **Then** `DESCRIBE wp_acrossai_mcp_servers` returns 10 rows and the three `claude_connector_*` columns are absent.
3. **Given** the MCPServer table had N rows before reactivation, **When** the plugin is reactivated, **Then** `SELECT COUNT(*) FROM wp_acrossai_mcp_servers` still returns N and the surviving columns retain their pre-migration values.
4. **Given** the `acrossai_mcp_oauth_cleanup` daily cron event is scheduled and 3 OAuth rewrite rules are registered on the install, **When** the plugin is reactivated, **Then** `wp cron event list` shows no `acrossai_mcp_oauth_cleanup` event and the OAuth well-known URLs (`/.well-known/oauth-authorization-server/*`, `/.well-known/oauth-protected-resource/*`) return 404.
5. **Given** the reactivation runs, **When** `wp option get acrossai_mcp_claude_connectors_enabled` is checked, **Then** the option is not set.

---

### User Story 3 — Fresh install ships lean by default (Priority: P1)

A new WordPress install activating the updated plugin for the first time creates only two MCP-owned database tables (`wp_acrossai_mcp_servers`, `wp_acrossai_mcp_cli_auth_logs`), the MCP Servers table has 10 columns, and no OAuth options / rewrite rules / cron events are registered. The plugin exposes no Connectors surface at any point.

**Why this priority**: The fresh-install path is what determines the plugin's ongoing footprint. Any leftover OAuth artifact on a clean install is a bug even if it doesn't break existing installs.

**Independent Test**: On a WordPress site that has never had the plugin installed, activate the updated plugin. Run `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` — expected 2 rows. Run `DESCRIBE wp_acrossai_mcp_servers` — expected 10 rows. Run `wp cron event list` — expected no `acrossai_mcp_oauth_cleanup`. Curl the two OAuth well-known URLs — expected 404.

**Acceptance Scenarios**:

1. **Given** a WordPress install with no prior AcrossAI MCP plugin, **When** the updated plugin is activated for the first time, **Then** `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` returns exactly 2 rows (`wp_acrossai_mcp_servers`, `wp_acrossai_mcp_cli_auth_logs`).
2. **Given** a fresh activation, **When** `DESCRIBE wp_acrossai_mcp_servers` is run, **Then** 10 columns are returned and none has a `claude_connector_` prefix.
3. **Given** a fresh activation, **When** the default MCP server row is seeded, **Then** the insert contains only the 10 surviving column values (no connector fields, no matching format specifiers).
4. **Given** a fresh activation, **When** `wp cron event list` is run, **Then** no event named `acrossai_mcp_oauth_cleanup` is present.
5. **Given** a fresh activation, **When** the OAuth REST endpoint `POST /wp-json/acrossai-mcp/v1/token` is requested, **Then** the response is a 404 (route not registered).

---

### User Story 4 — CLI auth flow continues to function (Priority: P1)

The existing CLI auth flow (browser approval page + WordPress App Password issuance) is a separate stack from Connectors OAuth and continues to work unchanged: `FrontendAuth`, `CliController`, `wp_acrossai_mcp_cli_auth_logs`, and the `acrossai-mcp-frontend` stylesheet are all preserved.

**Why this priority**: This retirement targets Connectors only; regressing the CLI auth flow would break a live feature. The plan explicitly protects the CLI stack, so the spec must guard against accidental removal.

**Independent Test**: On any install (fresh or existing), initiate a WP-CLI-driven authentication flow. Confirm the browser approval page loads with `acrossai-mcp-frontend` CSS enqueued; complete the approval; verify an App Password is issued and stored in `wp_usermeta`; verify a row lands in `wp_acrossai_mcp_cli_auth_logs` with status `approved`.

**Acceptance Scenarios**:

1. **Given** an admin initiates the CLI auth handshake, **When** the browser approval page loads, **Then** the `acrossai-mcp-frontend` stylesheet is enqueued and the approval form renders.
2. **Given** the admin approves the request, **When** the flow completes, **Then** a new App Password is issued in `wp_usermeta` and a corresponding row is written to `wp_acrossai_mcp_cli_auth_logs`.
3. **Given** an unauthenticated REST request carries an `Authorization: Bearer XXXXX` header, **When** the request is dispatched, **Then** the request is NOT elevated to a logged-in user (the OAuth bearer resolver is gone; CLI auth does not use bearer tokens).

---

### Edge Cases

- **Reactivation on an install where the plugin was uninstalled cleanly by the previous version's `uninstall.php`**: OAuth tables are already gone; `DROP TABLE IF EXISTS` in the Activator is a no-op. No warning is emitted.
- **Reactivation on an install where the OAuth tables were manually deleted but the `db_version` options are still stamped**: the option-delete step in the Activator's upgrade path removes the stale option keys.
- **BerlinDB's `maybe_upgrade()` does not execute `DROP COLUMN` on a `$version` bump alone**: the Activator falls back to an idempotent, gated `ALTER TABLE … DROP COLUMN` triple-statement so the migration completes.
- **A page still contains the retired `[acrossai_mcp_claude_connector_block]` shortcode**: WordPress renders the raw shortcode text instead of a fatal — no visible error surfaces on the frontend.
- **A stale reverse-proxy still probes `POST /wp-json/acrossai-mcp/v1/token`**: the endpoint returns a 404 REST error, not a 500, and does not fatal.
- **Uninstall on an install that never ran the Feature 016 activation upgrade** (e.g., customer deletes the plugin without ever reactivating): `uninstall.php` still drops both OAuth tables and deletes the two `db_version` options + the `acrossai_mcp_claude_connectors_enabled` option, so nothing is orphaned.
- **A test fixture file references a now-deleted class** (e.g., a helper still `use`s `ClaudeConnectorBlock`): PHPUnit fails with an autoload error, the grep audit surfaces the reference, and it is fixed as a defect.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The plugin MUST no longer register a per-server "Claude Connector" admin tab; `Registry::all_tabs()` MUST return exactly 10 tab instances.
- **FR-002**: The plugin MUST no longer register the `acrossai_mcp_claude_connectors_enabled` option; the "Claude Connectors" section on the Settings → MCP page MUST NOT be rendered.
- **FR-003**: The plugin MUST no longer register the `acrossai_mcp_claude_connector_block` shortcode.
- **FR-004**: The plugin MUST no longer dispatch `'claude-connector'` from the `acrossai_mcp_render_client_block` action-hook map; the remaining entries (`'npm'`, `'clients'`) MUST continue to work.
- **FR-005**: The plugin MUST no longer register the `POST /wp-json/acrossai-mcp/v1/token` REST route; a request to that route MUST return 404.
- **FR-006**: The plugin MUST no longer register a bearer-token resolver on the `determine_current_user` filter; an `Authorization: Bearer <token>` header MUST NOT elevate the current user.
- **FR-007**: The plugin MUST no longer register the three OAuth rewrite rules or the `acrossai_mcp_oauth_cleanup` daily cron event; `wp_next_scheduled('acrossai_mcp_oauth_cleanup')` MUST return false after activation.
- **FR-008**: On reactivation, the plugin MUST idempotently drop `wp_acrossai_mcp_oauth_tokens` and `wp_acrossai_mcp_oauth_audit` if they exist, delete the two matching `db_version` options, and delete the `acrossai_mcp_claude_connectors_enabled` option.
- **FR-009**: On reactivation, the plugin MUST drop the three columns `claude_connector_client_id`, `claude_connector_client_secret`, `claude_connector_redirect_uri` from `wp_acrossai_mcp_servers`, either via BerlinDB's `maybe_upgrade()` diff engine on a `$version` bump from `0.0.1` to `0.0.2`, or via an idempotent gated `ALTER TABLE … DROP COLUMN` fallback in the Activator.
- **FR-010**: On reactivation, the plugin MUST preserve every non-connector row and column value in `wp_acrossai_mcp_servers`; `SELECT COUNT(*)` and the surviving 10 columns' `CREATE TABLE` DDL MUST match the pre-migration state byte-for-byte (except for the three deletions).
- **FR-011**: The plugin's uninstall path MUST include `wp_acrossai_mcp_oauth_tokens`, `wp_acrossai_mcp_oauth_audit`, and the options `acrossai_mcp_oauth_tokens_db_version`, `acrossai_mcp_oauth_audit_db_version`, `acrossai_mcp_claude_connectors_enabled` in its drop/delete lists, so installs that skip the Feature 016 upgrade path are still cleaned up.
- **FR-012**: The plugin build output MUST NOT contain `build/css/frontend-oauth.css`, `build/css/frontend-oauth-rtl.css`, or `build/css/frontend-oauth.asset.php`; no page load MUST enqueue the `acrossai-mcp-frontend-oauth` stylesheet handle.
- **FR-013**: The plugin MUST preserve the CLI auth stack in its entirety: `FrontendAuth`, `CliController`, `wp_acrossai_mcp_cli_auth_logs`, `includes/Database/CliAuthLog/`, and the `acrossai-mcp-frontend` stylesheet handle MUST all continue to function.
- **FR-014**: The plugin MUST preserve `NpmClientBlock` and `MCPClientsBlock` renderers, their two shortcodes (`acrossai_mcp_npm_block`, `acrossai_mcp_clients_block`), and their base class `AbstractClientRenderer`.
- **FR-015**: A repository-wide grep for the retired symbols (`claude_connector`, `ClaudeConnector`, `acrossai_mcp_claude_connectors_enabled`, `acrossai_mcp_oauth_cleanup`, `frontend-oauth`, `OAuthToken`, `OAuthAudit`, and the seven deleted `Includes\OAuth\*` class names) MUST return zero matches under `includes/`, `admin/`, `public/`, `src/`, `tests/`, `webpack.config.js`, `uninstall.php`, and `acrossai-mcp-manager.php`. References in `docs/` are permitted (historical archaeology).
- **FR-016**: The plugin MUST NOT rename any surviving table or option key.
- **FR-017**: The plugin MUST NOT add data-migration steps that copy retired OAuth data into other tables; retired OAuth data is discarded.
- **FR-018**: On reactivation, the plugin MUST call `flush_rewrite_rules()` after the OAuth-column drop so the rewrite table is rebuilt without the three retired connector rewrite rules.
- **FR-019**: The plugin MUST update memory hygiene documents: any `DEC-CLAUDE-CONNECTOR-*`, `DEC-OAUTH-*` (connector-flavored), or `DEC-FRONTEND-OAUTH-STYLESHEET-*` entries in `docs/memory/DECISIONS.md` MUST be marked "Superseded (Feature 016)" with the original body preserved; CLI-auth-flavored entries (`DEC-CLI-AUTH-*`, `DEC-FRONTEND-AUTH-*`) MUST NOT be superseded.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin supports 7.4 minimum; constitution target is 8.0).
**WordPress Version**: 6.9+.
**Multisite**: Same as current plugin scope (single-site primary; multisite untouched by this feature).
**Required Plugins / Packages**: `berlindb/core: ^3.0.0` (already installed via Feature 010).
**Optional Integrations**: N/A — this feature removes an integration; it adds none.

### Module Placement

**PHP Class(es)** — this is a teardown feature, so most work is deletion. The only potential new class in scope is a fallback migration helper (optional, only if BerlinDB's diff engine cannot drop columns automatically):

- `includes/Database/MCPServer/ConnectorColumnMigration.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer` — OPTIONAL; only if a `Table::maybe_upgrade()` fallback proves necessary. Contains a single static `run()` method invoked from `Activator::activate()` after the BerlinDB upgrade call. Confirms columns exist via `$wpdb->get_col('DESCRIBE …', 0)`, then issues idempotent `ALTER TABLE … DROP COLUMN` statements. Skip creating this file if BerlinDB's diff engine handles the drop natively.

**Hook Registration**: All hook removals are surgical edits to the existing `includes/Main.php::define_public_hooks()` method. No new hooks are added by this feature.

### Admin UI Requirements

**No new admin screen**. This feature removes existing admin surfaces (one tab + one settings section + one action handler) — it does not add any UI. The `WP_List_Table`-based server list on the MCP Manager parent menu is untouched.

### REST API Contract

This feature removes one REST route:

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `POST` | `/wp-json/acrossai-mcp/v1/token` | (removed) | Retired — was the Claude Connectors token-redemption endpoint. Post-Feature-016 this route returns 404. |

**`permission_callback` rule**: N/A — no new routes are added.

### Database / Storage

**Schema changes to `{wpdb->prefix}acrossai_mcp_servers`**:
- DROP COLUMN `claude_connector_client_id` (varchar 255)
- DROP COLUMN `claude_connector_client_secret` (varchar 255)
- DROP COLUMN `claude_connector_redirect_uri` (varchar 500)
- Bump `db_version_key` (`acrossai_mcp_manager_db_version`) from `0.0.1` to `0.0.2`.

**Tables dropped**:
- `{wpdb->prefix}acrossai_mcp_oauth_tokens`
- `{wpdb->prefix}acrossai_mcp_oauth_audit`

**WordPress options deleted**:
- `acrossai_mcp_claude_connectors_enabled`
- `acrossai_mcp_oauth_tokens_db_version`
- `acrossai_mcp_oauth_audit_db_version`

**WordPress cron event unscheduled**: `acrossai_mcp_oauth_cleanup`.

**Rewrite rules dropped**: The three registered by `ClaudeConnectors::register_rewrite_rules()`. Cleared by `flush_rewrite_rules()` on reactivation.

**No new storage** is introduced.

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] All form/AJAX handlers verify nonce via `wp_verify_nonce()` or `check_ajax_referer()` — N/A; no new form handlers are added. The retired `save_claude_connector` handler (which did verify a nonce) is being deleted.
- [ ] All admin page renders check `current_user_can('manage_options')` (or more granular capability) — N/A; no new admin pages added.
- [ ] All REST routes have explicit `permission_callback` — N/A; no new routes added. The retired `TokenController` route (which did check permission) is being deleted.
- [ ] All user input sanitized at system boundary with most-specific function — N/A; no new input surfaces added.
- [ ] All output escaped at point of rendering with most-specific function — N/A; no new output surfaces added.
- [ ] All DB queries use `$wpdb->prepare()` — the optional `ConnectorColumnMigration::run()` fallback MUST use `$wpdb->prepare()` (with `%i` for the table identifier if the platform's `wpdb` supports it, otherwise a hard-coded prefix + validated table name).
- [ ] OAuth tokens / Application Passwords stored hashed — N/A; OAuth token storage is being removed. App Passwords (CLI auth) storage is unchanged.
- [ ] File uploads validated — N/A.

Security posture net effect: this feature REDUCES the plugin's attack surface (one fewer REST route, one fewer `determine_current_user` filter, no more bearer-token trust path, no more discovery endpoints).

### Key Entities *(include if feature involves data)*

- **MCPServer row** (`wp_acrossai_mcp_servers`): After Feature 016, has 10 columns (was 13). The removed columns are `claude_connector_client_id`, `claude_connector_client_secret`, `claude_connector_redirect_uri`.
- **OAuth Token row** (`wp_acrossai_mcp_oauth_tokens`): RETIRED. Table dropped; no rows preserved.
- **OAuth Audit row** (`wp_acrossai_mcp_oauth_audit`): RETIRED. Table dropped; no rows preserved.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`).
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`).
- [ ] ESLint: N/A — no JS changes.
- [ ] PHPUnit remaining tests written and passing (the `tests/phpunit/OAuth/` directory and `tests/phpunit/Public/MainEnqueueTest.php` are removed; connector assertions in `SettingsMenuTest.php`, `RegistryTest.php`, and `PublicApiTest.php` are pruned).
- [ ] Security checklist above: all applicable items verified.
- [ ] All remaining hooks wired in `Main.php` — none in class constructors. The three retired hook-registration blocks (ClaudeConnectors, TokenController, BearerAuth) are removed from `define_public_hooks()`.
- [ ] All new admin UI uses DataForm/DataViews — N/A; no new UI added.
- [ ] No code duplication — no new code is duplicated; the fallback migration (if needed) is a single-purpose helper.
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_` — N/A; no new symbols introduced.
- [ ] `npm run validate-packages` passes.
- [ ] `npm run build` succeeds and does NOT produce `build/css/frontend-oauth*`.

### Measurable Outcomes

- **SC-001**: A site admin on any install (fresh or existing) sees zero references to "Claude Connector" in the plugin's admin UI (0 tabs matching, 0 settings sections matching, 0 registered shortcodes matching).
- **SC-002**: On a fresh install, `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` returns exactly 2 rows and `DESCRIBE wp_acrossai_mcp_servers` returns exactly 10 columns.
- **SC-003**: On an install with pre-Feature-016 data, reactivation results in the two OAuth tables being dropped, the three MCPServer columns being dropped, and the surviving MCP server row count being unchanged (verified by pre/post `SELECT COUNT(*)`).
- **SC-004**: `wp_next_scheduled('acrossai_mcp_oauth_cleanup')` returns `false` after any activation (fresh or existing).
- **SC-005**: A repository-wide grep for the retired symbols (patterns defined in FR-015) returns zero matches under `includes/`, `admin/`, `public/`, `src/`, `tests/`, `webpack.config.js`, `uninstall.php`, `acrossai-mcp-manager.php`.
- **SC-006**: The CLI auth end-to-end flow (WP-CLI-initiated request → browser approval page → App Password issuance → row in `wp_acrossai_mcp_cli_auth_logs`) succeeds on any install after Feature 016 is applied.
- **SC-007**: `POST /wp-json/acrossai-mcp/v1/token` returns HTTP 404 (route not registered); the two OAuth well-known URLs (`/.well-known/oauth-authorization-server/*`, `/.well-known/oauth-protected-resource/*`) return HTTP 404.
- **SC-008**: On an install with the `Authorization: Bearer <token>` header attached to an unauthenticated REST request, the response is treated as anonymous (bearer resolver is gone).

---

## Assumptions

- **Attestation of no live connector data**: No install outside `~/local-sites/` runs this plugin with populated Claude Connector OAuth tokens, audit rows, or client credentials on the three `claude_connector_*` MCPServer columns. Captured in `docs/planings-tasks/016-remove-claude-connectors.md` "Pre-flight Attestation (SEC-016-001)" and confirmed by user email `raftaar1191@gmail.com` on 2026-07-06. Basis for the retirement being compat-breaking without a data-preservation step.
- **CLI auth is a separate stack from Connectors OAuth**: Verified by a repository-wide grep pass captured in the planning doc. `FrontendAuth`, `CliController`, and `wp_acrossai_mcp_cli_auth_logs` do not depend on any of the deleted `includes/OAuth/**` classes or the deleted BerlinDB modules. If this assumption proves wrong during implementation, the feature is stopped and re-scoped.
- **BerlinDB v3 `maybe_upgrade()` diff engine handles `DROP COLUMN` on a `$version` bump**: This is the preferred path per the planning doc. The fallback `ConnectorColumnMigration::run()` helper is only created if implementation verifies the diff engine does not execute the drop natively (verify by reading `vendor/berlindb/core/src/Database/Kern/Table.php::maybe_upgrade()`).
- **`docs/` references to Claude Connectors are intentionally preserved**: Historical planning docs (`005-oauth-connectors.md`, `phase-6-oauth.md`, `013-per-server-tabs-refactor.md`) reference the retired feature. They are archaeology and MUST NOT be rewritten. `ARCHITECTURE.md` is the exception — if it describes Connectors as current architecture, the section is replaced with a one-line retirement note.
- **Memory hygiene follows PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION**: DECISIONS.md rows are marked Superseded with the original body preserved; they are NOT deleted. Auto-memory entries follow the same rule.
- **The plugin is single-site primary**: Multisite behavior is out of scope for this teardown; if the pre-Feature-016 install ran multisite, the network-wide activation on each site follows the same reactivation path.
- **No customer-facing communication is required**: This retirement is documented in `README.txt` under Unreleased, but does not require a customer email/notice because no live install has real connector data (see attestation above).
