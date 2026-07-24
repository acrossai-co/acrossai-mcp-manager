=== AcrossAI MCP Manager ===
Contributors: raftaar1191
Tags: mcp, ai, copilot, vscode, claude
Requires at least: 7.0
Requires PHP: 8.1
Tested up to: 7.0
Stable tag: 0.1.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to MCP clients like VS Code, Claude, and Copilot using secure application passwords.

== Description ==

MCP Manager uses the standard `@automattic/mcp-wordpress-remote@latest` package with WordPress Application Passwords for the default remote flow. It also includes an optional experimental direct Claude Connectors mode backed by a WordPress-hosted OAuth approval flow.

MCP Manager is a WordPress plugin that enables seamless integration with Model Context Protocol (MCP) servers, allowing AI assistants and code editors to safely access your WordPress instance through secure application passwords.

= Key Features =

* **Multi-Client Support**: Configure MCP for:
  - VS Code with Copilot
  - Claude Desktop App
  - GitHub Copilot & Codex
  - OpenAI ChatGPT Codex
  - Custom MCP Clients

* **Secure Authentication**: Uses WordPress native Application Passwords system
  - One-click password generation
  - Secure credential management
  - Password revocation support
  - Per-server Access Control still enforced after authentication

* **Easy Configuration**:
  - Copy-paste ready JSON configurations
  - Per-provider configuration file paths
  - Automatic top-level key detection

* **Format #1 Standard**: Uses the Automattic-recommended MCP configuration format
  - npx command execution
  - @automattic/mcp-wordpress-remote@latest package
  - Full environment variable support

= How It Works =

1. Navigate to Settings → MCP Manager
2. Select your MCP client (VS Code, Claude, GitHub Copilot, ChatGPT, or Custom)
3. Click "Generate New Application Password"
4. Copy the ready-to-use JSON configuration
5. Paste into your client's configuration file
6. Restart your MCP client

All application passwords are managed through WordPress's native Application Passwords system and appear in your profile under Account Management.

= CLI Connection and Authorization Flow =

MCP Manager also supports a browser-assisted CLI connection flow for local MCP clients.

Typical command:

`npx -y @acrossai/mcp-manager --siteurl=https://example.com --server=default-mcp-server`

Flow summary:

1. The CLI checks `/wp-json/acrossai-mcp-manager/v1/health`
2. The CLI starts auth with `/wp-json/acrossai-mcp-manager/v1/auth/start`
3. WordPress returns an `auth_code` and frontend `auth_url`
4. The CLI opens the frontend approval page at `/acrossai-mcp-manager/`
5. If needed, the user signs in through normal WordPress login
6. The signed-in user approves access in the browser
7. The CLI polls `/auth/status` until the request is approved
8. The CLI fetches the approved user's accessible servers from `/servers`
9. The CLI exchanges the approved code at `/auth/exchange`
10. WordPress creates a one-time Application Password and the CLI writes the MCP client config

Terminology:

* **Sign in / Log in** = WordPress account authentication
* **Connect** = starting the CLI-to-site linking flow
* **Authorize / Approve access** = granting the CLI permission in the browser

Important notes:

* The frontend authorization page must never be cached
* Auth codes are single-use
* `/servers` and `/auth/exchange` respect per-server access control
* User-facing copy should say **CLI Connections** rather than **npm Login**
* Generated remote MCP configs use Application Passwords and explicitly disable OAuth discovery in `@automattic/mcp-wordpress-remote`

= Experimental Direct Claude Connectors =

An optional **Claude Connectors Screen (Experimental)** setting can enable a direct OAuth flow for Claude's hosted connectors.

When the global feature toggle is enabled and a specific server is configured in its **Claude Connector** tab, the plugin exposes:

* `/.well-known/oauth-authorization-server`
* `/.well-known/oauth-protected-resource?resource=<mcp-url>`
* `/acrossai-mcp-connectors/oauth/authorize/`
* `/wp-json/acrossai-mcp-manager/v1/connector/oauth/token`

Important notes:

* Disabled by default
* The Application Password flow remains available and supported
* The master experimental toggle is global, but OAuth client settings are stored per server
* Direct connector approval signs Claude in as a WordPress user
* Per-server Access Control still applies to every MCP request after OAuth
* Public HTTPS is recommended for hosted connector usage

= Provider Configuration Paths =

* **VS Code**: ~/.config/Code/User/globalStorage/Copilot.copilot-chat/mcp.json (top-level key: "servers")
* **Claude**: ~/Library/Application Support/Claude/claude_desktop_config.json (top-level key: "mcpServers")
* **GitHub Copilot**: ~/.gh-copilot/config.json (top-level key: "servers")
* **OpenAI ChatGPT**: ~/.config/chatgpt/config.json (top-level key: "servers")
* **Custom**: ./your-project/.mcp/config.json (top-level key: configurable)

= Requirements =

* WordPress 5.9 or higher
* PHP 7.4 or higher
* WordPress Application Passwords support (built-in since WP 5.6)

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Settings → MCP Manager to configure

Or:

1. Go to Admin → Plugins → Add New
2. Search for "MCP Manager"
3. Click "Install Now" then "Activate"

== Frequently Asked Questions ==

= Is my password secure? =

Yes! MCP Manager uses WordPress's native Application Passwords system. Each password is:
- Generated using WordPress's secure methods
- Associated with your user account
- Visible in your profile for management
- Revocable at any time

= Can I use this with multiple MCP clients? =

Yes! You can generate separate passwords for each client (VS Code, Claude, GitHub Copilot, ChatGPT, and any custom client).

= Where are my application passwords saved? =

All application passwords are managed through WordPress's native Application Passwords system. View and manage them at:
User Profile → Account Management → Application Passwords

= What MCP clients are supported? =

- Visual Studio Code (with Copilot)
- Anthropic Claude Desktop App
- GitHub Copilot
- OpenAI ChatGPT Codex
- Any custom MCP client supporting the standard format

= Can I revoke a password? =

Yes! You can revoke any application password from your profile page under Account Management → Application Passwords.

= Is this compatible with multisite? =

Yes! MCP Manager works with WordPress multisite installations. Each site can be configured independently.

= Do I need to install additional software? =

No additional software is needed on the WordPress side. Your MCP clients (VS Code extension, Claude app, etc.) handle the integration.

== Screenshots ==

1. Settings page with client tabs for easy configuration
2. Copy-paste ready JSON configuration
3. One-click password generation
4. Per-provider configuration file locations and top-level keys

== Changelog ==

= 0.1.7 =
* **Feature 033 — Security fix: F030 permission_callback wrapper dropped args and coerced `WP_Error` to `true`.** Two bugs in `PermissionOverrideProcessor::inject_override` combined into a plugin-wide `permission_callback` bypass. The wrapper closure was declared `static function () use ( ... )` — zero parameters — so every arg the caller passed was silently discarded. Downstream callbacks that read their input (notably `Execute::check_permission` looking up `$input['ability_name']`) saw an empty array and returned `WP_Error( 'missing_ability_name', ... )`. The wrapper's `call_original` helper then did `return (bool) call_user_func( $original );` — casting the `WP_Error` object to boolean `true` (PHP casts every object to true). The vendor's `if ( true !== $permission )` check in `ToolsHandler::call_tool` (`vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:148`) read that as "permission granted" and proceeded to `execute()`. **Impact**: any authenticated user with any role (including `subscriber`) could invoke any registered ability via `mcp-adapter/execute-ability` on the default MCP server, even when the Abilities tab had explicitly disabled the ability (`is_exposed=0` row in `wp_acrossai_mcp_server_abilities`) and the ability itself declared `meta.mcp.public = false`. **Fix**: closure is now `static function ( ...$callback_args ) use ( ... )` and forwards `$callback_args` on every fall-through path; `call_original` returns `bool|WP_Error` — `WP_Error` results propagate unchanged, only scalar returns are coerced. The six-layer allow-path semantics (`DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS`) are unchanged — only the fall-through path is affected. **Test coverage**: three new regression tests in `PermissionOverrideProcessorTest.php` — args forwarding, `WP_Error` preservation, and a `@dataProvider`-parameterised role sweep across `subscriber` / `contributor` / `author` / `editor` / `administrator` proving low-privilege roles are correctly denied post-fix. **Durable memory captured as `B40 / B-WRAPPER-CLOSURE-MUST-FORWARD-ARGS-AND-PRESERVE-WP-ERROR`** — generalizable pattern for any closure wrapping a user callback (permission_callback, execute_callback, filter/action decorators, plugin bridges). **Follow-up tracked as issue #46**: filter-time eligibility gate refactor to skip installing the wrapper entirely for abilities that could never satisfy F030's six defensive layers — eliminates the wrapper-bug class for the vast majority of abilities.
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant bumped to `0.1.7` matching the plugin header.**

= 0.1.5 =
* **Feature 031 — Add Google Gemini CLI as a supported MCP client.** The server-edit Clients tab now surfaces a Gemini card (💎 pill) alongside the existing 7. Uses the same npx `@automattic/mcp-wordpress-remote@latest` bridge + WP Application Password Basic auth as Claude Desktop / Cursor / other stdio-based clients; config paste target is `~/.gemini/settings.json` under the standard `mcpServers` key. `GeminiClient` is a near-verbatim mirror of `ClaudeDesktopClient` (slug `gemini`, name `Gemini CLI`, byte-for-byte identical `get_config_snippet` shape) — no new auth mechanism, no new abstraction, no new render path. Registered in `MCPClientsBlock::$default_classes` + `CLIENT_META['gemini']`. Test suite canary bumped from 7 → 8 concrete clients; `mcpclients` PHPUnit suite now runs 74 tests / 124 assertions (up from 67/111 pre-F031).
* **Assets — Refreshed WordPress.org plugin-directory banners** (1544×500 + 772×250 PNGs in `.wordpress-org/`). No code change; visual update only.
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant bumped to `0.1.5` matching the plugin header.**

= 0.1.4 =
* **Feature 030 — Per-server ability permission_callback override.** The MCP-server-edit "Access Control" tab now hosts a second section (below the existing wpb-access-control React panel, separated by `<hr>`) with a single toggle: when enabled, every ability exposed to this MCP server via the Abilities tab bypasses its own `permission_callback` for MCP requests routed to this server. Site-wide ability callers (WP admin, non-MCP REST namespaces, WP-CLI) see the original `permission_callback` unchanged — the closure short-circuits when `CurrentServerHolder` is empty. Runtime filter registers on `wp_register_ability_args` at priority `999999`, strictly higher than sibling `acrossai-abilities-manager`'s P100000 injector and this plugin's own `CallbackReplacer` P10, so the operator toggle wins deterministically. Gated by six defensive layers documented in `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS`: `manage_options` capability + per-server nonce (`acrossai_mcp_manager_permission_override_{server_id}`) + persistent warning banner when ON + native `confirm()` prompt on submit-to-ON + `CurrentServerHolder` scope + `ExposureResolver::resolve()` gate. Adds one column via D28 3-part BerlinDB contract (`MCPServer\Table` `1.1.1 → 1.1.2` + `upgrade_to_1_1_2` callback, idempotent per `INFORMATION_SCHEMA.COLUMNS`). Fires new observability action `acrossai_mcp_permission_override_toggled( $server_id, $value, $user_id, $timestamp )` on every save — operators can attach any logger (Query Monitor, custom audit table, syslog) without a hard dependency. Also adds a promotional card for the sibling `acrossai-abilities-manager` plugin (already in `acrossai-co/main-menu`'s baseline addon list — no double-register) with links to install/activate via the shared Add-ons page or edit abilities when active, plus a `<details>` "Prefer to use code?" fallback documenting the filter name + priority for developers who prefer not to install another plugin.
* **Feature 030 (bonus) — Test-infrastructure fix.** `tests/phpunit/{Abilities,Database,MCP}/` were orphaned in `phpunit.xml.dist` — no suite covered them, so CI never ran F011/F017/F026 legacy tests OR any F030 new tests. Fixed by adding 3 new PHPUnit suites (`abilities`, `database`, `mcp`) + 3 matching CI workflow steps in `.github/workflows/phpunit.yml`. All previously-orphaned tests + all F030 new tests now execute in CI on every push.
* **Feature 030 — Durable memory captured.** Five new entries in `docs/memory/`: `D29` (six-layer defensive gating framework for any future `permission_callback` bypass — scoped carve-out from `D24`), `D30` (F030 intentionally passes empty `$meta` to `ExposureResolver::resolve()` — scoped carve-out from `DEC-ABILITY-OVERRIDE-RESOLUTION`), `B35` (`wp_register_ability_args` filter-priority slot map: P10 CallbackReplacer, P100000 sibling, P999999 F030), `B36` (inline `<script>` string-interpolation requires `wp_json_encode()`, not `esc_html`/`esc_attr` — generalizable JS-context escaping rule), `DEV5` (per-server-edit tab hand-rolled admin form exception to §IV DataForm mandate per D13 escalation ≥ 2 features).
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant bumped to `0.1.4` matching the plugin header.**

= 0.1.3 =
* **Feature 029 (OAuth) — RFC 6749 §2.3.1 HTTP Basic auth accepted on `/token` + `client_secret_post` softening.** `TokenController` now parses `Authorization: Basic base64(client_id:client_secret)` with CGI fallback (`REDIRECT_HTTP_AUTHORIZATION`) and applies header-first-then-body credential resolution to both `authorization_code` and `refresh_token` grants. When a client registered as `client_secret_post` submits NO secret at exchange (header AND body both empty), the endpoint now falls through to PKCE-only verification instead of hard-rejecting with `invalid_client`. Modern MCP hosts (Claude.ai, ChatGPT, Cursor, Cline) frequently register as `client_secret_post` but behave as public+PKCE at exchange; the softening keeps them interoperable. Confidential clients that DO send a secret are still verified via constant-time `ClientRepository::verify_secret` (unchanged). Codified as durable decision `D27`. Residual risk bounded by mandatory PKCE S256 + RFC 8707 audience binding + single-use auth codes + refresh-family revocation.
* **Feature 029 (OAuth) — DCR-registered clients now attributed to their connector profile at registration time.** `ClientRegistrationController::handle_register()` walks `ConnectorProfileRegistry::get_profiles()` and calls `matches_dcr_client( $client_name, $redirect_uris )` on each — first matching profile's slug is persisted as `connector_slug` (previously always empty). Fixes F024's per-connector settings gate: DCR-registered clients (Claude.ai etc.) that previously bypassed the operator's enable/disable toggle now honor it correctly. Bug pattern captured as `B33` (admin-gate silent-bypass on data-field left empty).
* **Feature 029 (DB) — BerlinDB schema-drift reconciliation for `wp_acrossai_mcp_cli_auth_logs` + `wp_acrossai_mcp_servers`.** Two live tables had drifted from their `Schema.php` while stored `db_version` still matched code — `parent::maybe_upgrade()` short-circuited forever, and any INSERT referencing a Schema column missing from the actual table returned `false`, which callers cast to `int(0)` and treated as success (silent write-loss; identical shape took down Claude OAuth on `procureco.uk` before F029). Bumps `CliAuthLog\Table` `1.0.0` → `1.0.1` (adds `upgrade_to_1_0_1` callback that ALTER MODIFYs `status` `varchar(20)` → `varchar(32)`, `failure_code` `varchar(100)` → `varchar(64)`, `app_password_uuid` `varchar(64)` → `varchar(36)`) and `MCPServer\Table` `1.1.0` → `1.1.1` (adds `upgrade_to_1_1_1` callback that ALTER ADD COLUMN for the three F025 protocol flags `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`). Both callbacks idempotent per-column via `INFORMATION_SCHEMA` existence/width check. Codified as `D28` (BerlinDB `$upgrades` reconciliation pattern) + `B34` (silent write-loss bug pattern).
* **Feature 029 (Boot) — `Main::reconcile_database_schemas()` on `admin_init@3`.** New Loader-wired admin hook fires `maybe_upgrade()` on all 7 BerlinDB Tables on every admin request. Before F029, `maybe_upgrade()` only ran from `Activator::activate()` — activation runs once, so version bumps on in-place upgrades (composer / wp-cli plugin update / manual file replace) stayed inert until deactivate + reactivate. Priority 3 fires BEFORE `Settings::maybe_seed_default_server` (4) and `Settings::handle_actions` (5) so schema is reconciled before any handler reads from these tables. Per-admin-request cost: 7 option reads (needs_upgrade short-circuits when versions match).
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant bumped to `0.1.3` matching the plugin header.**

= 0.1.2 =
* **Feature 027 — Fix: DCR `token_endpoint_auth_method` default flipped to `none` for public+PKCE clients.** `ClientRegistrationController::handle_register()` (`includes/OAuth/ClientRegistrationController.php:310`) previously defaulted the RFC 7591 Dynamic Client Registration `token_endpoint_auth_method` field to `client_secret_post` when the caller omitted it. Modern MCP hosts (Claude.ai, ChatGPT, Cursor, Cline) register as public+PKCE clients — they omit the field in DCR and never carry a `client_secret` through the `/token` exchange. The old default silently stored these clients as confidential; the follow-up authorization-code exchange then failed at `TokenController::handle_authorization_code()` (`includes/OAuth/TokenController.php:106-111`) with `invalid_client` HTTP 401 *after* the auth code had already been consumed atomically at `AuthCodeRepository::consume_atomic` line 89 — so the client saw a generic "Authorization failed" page with no ability to retry. Default now flips to `none`, matching RFC 8252 §8.4 for public+PKCE clients. Confidential-client callers can still pass `token_endpoint_auth_method=client_secret_post` explicitly in the DCR body; admin-generated clients at `handle_admin_generate` are unaffected (they continue to hardcode `client_secret_post`). New phpunit case `test_omitted_auth_method_defaults_to_none_public_client` in `tests/phpunit/OAuth/DCRRegisterFreshTest.php` locks the invariant.

= 0.1.1 =
* **Feature 028 — Retire Freemius integration; consume `acrossai-co/main-menu` 0.0.22+ filter-driven Add-ons page.** The bundled `freemius/wordpress-sdk` transitive dependency is dropped entirely (vendor removed it from `acrossai-co/main-menu` 0.0.22's `require` block along with the `AcrossAI_Addon\` PSR-4 namespace). This plugin's Freemius integration in `Main::define_admin_hooks()` — the `\AcrossAI_Addon\AddonsPage` instantiation with `fs_product_id => '34418'` / `fs_public_key` / `fs_slug => 'acrossai-add-ons'` / `fs_menu` / `fs_has_addons` config, its `class_exists`+`try/catch` guards, and its admin-notice fallback closure — is removed in full (94 lines). No opt-in card, no `api.freemius.com` outbound requests, no umbrella-product license state. Bumps `acrossai-co/main-menu` `0.0.18` → `0.0.23`.
* **New consumer self-exclusion filter.** A new singleton `admin/Partials/AddonsFilter` hooks the vendor's `acrossai_addons` filter and drops the entry with `slug === 'acrossai-mcp-manager'` from the array — an already-active plugin should not advertise itself as an installable add-on on the shared Add-ons page. Codified as `D26 / DEC-CONSUMER-SELF-EXCLUSION-VIA-VENDOR-FILTER` (paired with `D20` on the subtractive side). Every future AcrossAI plugin whose slug appears in `AddonsPageRenderer::ADDONS` MUST ship the same pattern.
* **User-visible behavior change: the AcrossAI → Add-ons submenu no longer renders from this plugin.** The Add-ons submenu is now rendered by whichever consumer of `acrossai-co/main-menu` 0.0.22+ activates first. If this plugin is the only AcrossAI plugin active on an install, the Add-ons submenu disappears until a companion plugin activates.
* **Related durable memory flipped to Superseded (F028):** `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` (external-package self-registering-in-constructor exception to A1 — obsolete because `\AcrossAI_Addon\AddonsPage` no longer exists), `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` (opt-in state-machine diagnosis — no live surface here anymore), and `B28` (Freemius two-level `menu.<key>` + `has_<key>` enablement pattern — SDK is gone). Entry bodies retained per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION.
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant resynced.** Drifted at `0.0.9` across the 0.1.0 release; now correctly reads `0.1.1` matching the plugin header.
* **Operator recipe for prior installs (optional cleanup):** Freemius' SDK previously wrote `fs_accounts`, `fs_active_plugins`, `fs_api_cache`, `fs_cache_*`, and `fs_debug_mode` rows to `wp_options`. Nothing this plugin loads reads or writes them post-F028. To purge: `DELETE FROM wp_options WHERE option_name LIKE 'fs_%';`. The plugin does NOT ship this as an automatic migration (per `D21` fresh-install-only retirement pattern established by F016).

= 0.1.6 =
* **Feature 032 — OAuth per-server scoping (SECURITY FIX + BREAKING CHANGE for legacy DCR sessions).**

    ⚠️ **BEFORE UPGRADE — READ THIS**: this release deletes any pre-F032 DCR-registered OAuth client rows (those without a `server-{id}-` prefix — e.g., legacy Claude.ai / ChatGPT / Cursor / Cline connections) and their associated tokens + auth codes as part of the D28 upgrade migration. Any live AI-host session bound to a legacy DCR row will disconnect on the next request; affected users must re-run the OAuth authorize flow from their AI host to reconnect. All post-F032 DCR registrations are per-server and unaffected. Consider (a) snapshotting `wp_acrossai_mcp_oauth_{clients,tokens,auth_codes}` before upgrade, and (b) notifying users with active AI-host connections that they will need to re-authorize once after upgrade.

    **Security fix**: closes a cross-server privilege-escalation gap where an admin on Server A's Connectors tab could revoke or delete Server B's clients + tokens by modifying the `client_id` in the outbound REST body. Also closes a read-side display leak in the "authorized users" listing on the AI Connectors tab.

    **What ships**: adds `server_id BIGINT UNSIGNED NOT NULL` column (final state) to `wp_acrossai_mcp_oauth_clients`, `wp_acrossai_mcp_oauth_tokens`, and `wp_acrossai_mcp_oauth_auth_codes` via the D28 3-part BerlinDB `$upgrades` contract (each Table bumps `$version` 1.0.0 → 1.0.1 with matching `upgrade_to_1_0_1()` callback). Replaces standalone `UNIQUE(client_id)` on `oauth_clients` with composite `UNIQUE(client_id, server_id)` so the same DCR connector can be registered on multiple MCP servers as independent rows. Every mutating REST endpoint (`revoke-client-tokens`, `delete-client`, `revoke-connector-tokens`) now requires + validates `server_id` in the body — mismatch returns 403 `acrossai_mcp_oauth_cross_server` AND fires `do_action( 'acrossai_mcp_oauth_cross_server_attempted', $client_id, $server_id_requested, $user_id, $timestamp )` (4-arg signature — intentionally does NOT disclose the actual owning server_id to listeners, per SEC-032-001 remediation). DCR endpoint now requires resolvable RFC 8707 `resource` parameter with mandatory origin verification against `home_url()` (rejects attacker-origin URLs with 400 `invalid_target` + fires `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch`). DCR endpoint also gates against a rare deploy→migration race window with 503 `service_unavailable` when `server_id` column is absent (prevents silent destruction of legitimate registrations by the auto-purge step). Backfill of admin clients from `server-{id}-` prefix includes an orphan-server guard (parsed server_id must exist in `wp_acrossai_mcp_servers`; otherwise row left NULL and purged alongside legacy DCR rows). Fires `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $clients_deleted, $tokens_deleted, $auth_codes_deleted )` exactly once per upgrade run for operator observability. `UserLifecycle::on_user_deleted()` cascade preserved unchanged (site-wide per FR-042 — regression-tested).

    **F032 extended scope (folded in-branch)**: new BerlinDB module `wp_acrossai_mcp_connector_approved_users` promotes admin-approval state from serialized wp_options to a first-class relational table (FR-029); new "Approved Users" admin panel (FR-046..FR-048) surfaces between Connections and Settings when `require_admin_approval` is enabled; new revoke-approval → token-revoke cascade wired via `acrossai_mcp_connector_user_approval_revoked` action with opt-out filter `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` (FR-040/FR-041); new "Revoke from all servers" nuclear button (FR-043 — deliberate D31 carve-out, fires `acrossai_mcp_oauth_client_revoked_across_all_servers` NEVER `acrossai_mcp_oauth_cross_server_attempted`); Access Control connection-time gate now enforces at OAuth authorize + CLI device-grant + Application Password generation (FR-049) so denied users see immediate `access_denied` instead of confusing "connected then silent 403" behavior; annotated token counts (`2 (1 access · 1 refresh)`) replace opaque totals in Connections panel (FR-045); enriched AC 403 with `server_slug` + `user_roles` (FR-050).

    **Admins bypass `require_admin_approval` (FR-051)**: users with `manage_options` capability skip the pending-approval queue entirely and are auto-added to the Approved Users list on first connection with `approved_by = $user_id` (self-approval). Rationale: admins can approve themselves in one click from the Approved Users panel anyway; the pending detour is UX friction, not a security boundary. Effective threat model unchanged (admins already have `manage_options`, a strict superset of any approval decision they could make on themselves). The Settings-panel description under "Require admin approval for new connections" includes an inline note explaining this so operators are not confused when they connect as admin and skip the queue.

* **Feature 022 — Shared AcrossAI Add-ons submenu.** The plugin now registers the shared "Add-ons" nav entry under the AcrossAI top-level menu, powered by Freemius for product id 34418. The page requires `install_plugins`; when a companion AcrossAI plugin is active simultaneously only one plugin contributes the nav entry (the shared package coordinates this so operators never see duplicate submenu rows). Bumps `acrossai-co/main-menu` from `0.0.14` to `0.0.18`. `0.0.15` enabled the Freemius **Account**, **Contact Us**, and **wp.org Support Forum** submenus at package level; `0.0.16` promotes those defaults to `FreemiusInitializer::DEFAULT_MENU` and introduces a new `fs_menu` key on `AddonsPage`'s `$args` array so each consumer plugin explicitly decides which auto-submenus surface. `0.0.17` disables the vendor's own `MenuRegistrar::register()` — Freemius's `menu.addons` submenu (enabled here via `fs_menu.addons = true`) is now the sole source of the Add-ons row, per the AcrossAI "umbrella product" model where Freemius product `34418` (`acrossai-add-ons`) owns the single ecosystem-wide Add-ons page. `0.0.18` adds an `fs_has_addons` key on `AddonsPage`'s `$args` — Freemius' SDK gates the Add-ons row on `if ( $this->has_addons() )` (class-freemius.php:18964), so `menu.addons => true` alone was insufficient. The plugin now passes `fs_has_addons => true` explicitly, which forwards to `fs_dynamic_init()` and unblocks the Add-ons row. The plugin passes an explicit `fs_menu` array in `includes/Main.php` declaring every key so the full menu policy is visible at the call site — flip any boolean there to change what operators see without a vendor release. Adds an explicit VCS repositories entry for `acrossai-co/main-menu` in `composer.json` so consumers resolve deterministically from GitHub without waiting on Packagist sync.
* **Feature 021 — OAuth 2.1 + PKCE authorization server.** Provider-agnostic OAuth 2.1 authorization server exposed at four domain-root endpoints (`/.well-known/oauth-authorization-server` per RFC 8414, `/.well-known/oauth-protected-resource` per RFC 9728, `/authorize`, `/token`) plus RFC 7591 Dynamic Client Registration at `/wp-json/acrossai-mcp-manager/v1/oauth/register` and an admin-only credential generator at `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client`. PKCE S256 mandatory (plain rejected regardless of client claim). RFC 8707 `resource` parameter mandatory and enforced at call time — a token issued for one MCP server rejects when presented against a different server on the same site. RFC 9207 `iss` parameter emitted on authorization callbacks. Refresh-token rotation with **family revocation on reuse detection** (RFC 9700 §2.2.2). Bearer authentication via a new `TokenValidator` on `determine_current_user @ 20` with 4-fallback header extraction and static recursion guard. New built-in per-server "AI Connectors" tab (priority 35) renders one card per registered `AbstractConnectorProfile` — companion plugins contribute Claude / ChatGPT / Gemini / Copilot profiles via the new `acrossai_mcp_manager_connector_profiles` filter; base plugin ships zero profiles. Four new observability actions: `_oauth_token_issued`, `_oauth_authorization_denied`, `_oauth_token_revoked`, `_oauth_cleanup`. Three new BerlinDB tables (`OAuthClients`, `OAuthTokens`, `OAuthAuthCodes`) — all with F011 phantom-version guard, SHA-256 hashes at rest, atomic single-use auth codes via `consume_atomic` (B10 pattern). Daily `acrossai_mcp_manager_oauth_cleanup` cron purges expired codes + expired-and-revoked tokens. WordPress user deletion cascades to token revocation + code deletion via `deleted_user @ 10`. Uninstall respects the existing `acrossai_mcp_uninstall_delete_data` opt-in gate. Zero new composer runtime dependencies.
* **Feature 020 — Per-server Tools tab.** Pick which registered abilities each MCP server exposes as callable tools via a two-column shuttle picker on the Tools tab. Per-row Add / Remove, bulk Add all / Remove all, search, a running counter, an empty-state warning banner, and explicit Save changes / Cancel — pending edits stay local until the operator commits. Selection is stored in a new BerlinDB table `{prefix}acrossai_mcp_server_tools` (phantom-version self-heal). Enforcement is call-time via a new `mcp_adapter_pre_tool_call` callback at priority 30 that returns `403 acrossai_mcp_tool_not_added` for abilities not in the curated set; stacks after F015 access control (10) and F017 ability exposure (20) with deny-precedence. Server deletion cascades tool selections cleanly (hooks BerlinDB's native `mcp_server_deleted`). The three `mcp-adapter/*` protocol tools remain built-in and are not configurable per server. Uninstall drops the table under the same opt-in gate as F011/F017.
* **Feature 017 — Per-server ability selection.** The Abilities tab on each MCP server is now interactive. Site administrators pick which registered WordPress abilities the server exposes to connected AI clients, with search, category + type filters, sortable columns, per-row toggle, and bulk Expose / Hide actions. Backed by a new `{prefix}acrossai_mcp_server_abilities` BerlinDB table (phantom-version self-heal guard). Backwards-compatible — servers with no explicit selection continue to expose abilities whose `meta[mcp][public]` is true. Enforcement is call-time (via a new `mcp_adapter_pre_tool_call` callback at priority 20 that returns `403 acrossai_mcp_ability_not_exposed` on hidden abilities); list-time hiding of hidden abilities from `mcp/tools/list` is a documented follow-up. The tab is extensible — companion plugins can add columns and per-row actions via three `@wordpress/hooks` filters (`acrossaiMcpManager.abilities.{fields,actions,row}`) plus one PHP filter (`acrossai_mcp_ability_row`) — see `docs/extending-abilities-tab.md`. All new hooks are marked `@experimental May change without notice before 1.0.0` per the F013 public-API precedent.
* **Feature 016 — Retired the Claude Connectors integration in full.** The OAuth 2.1 authorization-server surface (well-known discovery URLs, `/wp-json/acrossai-mcp/v1/token` REST route, bearer-token acceptance on the `determine_current_user` filter, daily `acrossai_mcp_oauth_cleanup` cron, per-server Claude Connector admin tab, Settings → MCP toggle, `[acrossai_mcp_claude_connector_block]` shortcode, and the `frontend-oauth` CSS bundle) is fully removed. The feature never worked with claude.ai's hosted Connectors UI on local installs and has been retired to reduce attack surface. This release is compat-breaking on Claude-Connector-owned data only; every other MCP server row and setting is preserved. Net effect: ~4,000 lines of security-sensitive code removed, one fewer REST route, one fewer `determine_current_user` filter, no more OAuth discovery endpoints.
* **Operator action required for pre-016 installs.** The plugin ships fresh-install-only — no in-plugin schema migration. Before reactivating the updated plugin on an install that had populated Claude Connector data, run this manual retirement recipe (SEC-016-001 defense-in-depth: the pre-DROP `UPDATE` forces the InnoDB tablespace to overwrite the plaintext `client_secret` bytes before the column is dropped):

    UPDATE wp_acrossai_mcp_servers SET
        claude_connector_client_secret = '',
        claude_connector_redirect_uri  = '';
    DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_tokens;
    DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_audit;
    ALTER TABLE wp_acrossai_mcp_servers
        DROP COLUMN claude_connector_client_id,
        DROP COLUMN claude_connector_client_secret,
        DROP COLUMN claude_connector_redirect_uri;
    DELETE FROM wp_options WHERE option_name IN (
        'acrossai_mcp_oauth_tokens_db_version',
        'acrossai_mcp_oauth_audit_db_version',
        'acrossai_mcp_claude_connectors_enabled'
    );

  And one companion WP-CLI step to clear the retired daily cron: `wp cron event unschedule acrossai_mcp_oauth_cleanup`. If your install has any active claude.ai Connector tokens, revoke them from claude.ai's Connectors UI BEFORE running the retirement SQL — the retirement drops the audit log with no recovery.
* **Behavior change: `Authorization: Bearer` headers no longer elevate users.** The Bearer resolver on the `determine_current_user` filter has been removed. Integrators relying on the retired path should migrate to WordPress Application Passwords via the CLI auth flow (`public/Partials/FrontendAuth`), which is untouched by this release.

= 0.0.9 =
* **Fix: Claude Code MCP Clients tab now shows a JSON config block instead of a `claude mcp add` shell command.** The `~/.claude.json` config file path is displayed correctly (was incorrectly listed as `~/.claude/mcp_servers.json`), the snippet renders as a copy-pasteable `mcpServers` block with `command`/`args`/`env`, and the env now pins `OAUTH_ENABLED: "false"` alongside `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD` to keep the `@automattic/mcp-wordpress-remote` client from falling into an OAuth branch it can't complete against an Application Password server. Instructions on the tab updated to match ("paste under the top-level key" — no more `claude mcp add-json`).
* **Internal: `ACROSSAI_MCP_MANAGER_VERSION` constant now tracks the plugin header.** It had drifted at `0.0.6` across the 0.0.7 and 0.0.8 releases; this release resyncs it to `0.0.9`. Consumers reading the constant to key cache entries or telemetry will see a version bump even though there are no functional changes since 0.0.8 beyond the Claude Code tab fix above.
* **Tests: repair 14 stale JSON fixtures.** The `ConcreteClientsTest` golden fixtures for `claude-desktop`, `vscode`, `github-copilot`, `codex`, `cursor`, and `custom` were missing the `WP_API_USERNAME` env field that all 6 clients have emitted since Feature 004. Adding the field brings fixtures back in sync with the code — 49/49 tests now pass (was 35/49).

= 0.0.8 =
* Dependencies: bump `acrossai-co/main-menu` to `0.0.11`.

= 0.0.7 =
* Docs: rewrite README.txt from the canonical baseline (proper Description, FAQ, Screenshots, install steps).
* Docs: fix wp.org import warnings — real Contributors (`raftaar1191`), plugin-relevant Tags (`mcp, ai, copilot, vscode, claude`), and a Short Description tagline.
* Header: refresh plugin header — Plugin URI `https://acrossai.co/`, Author `raftaar1191`, wp.org profile Author URI, License normalized to `GPL-2.0-or-later`.
* Build: expand `.distignore` to exclude tooling / config / docs / tests / editor dirs from the wp.org build. `.gitignore` cleaned up.
* CI: add GitHub Actions workflows for PHPStan, PHPCompatibility, PHPUnit (mcpclients suite), PHPCS, build-zip, and wp.org deploy.
* Requirements: bump minimums to WordPress 7.0 / PHP 8.1.

= 0.0.6 =
* Migrated the four internal DB modules (MCP Servers, CLI Auth Log, OAuth Tokens, OAuth Audit) to BerlinDB Core 3.0. Fresh installs create tables with BerlinDB-derived schemas; the phantom-version guard on every Table subclass silently self-heals a stamped-but-missing table on the next activation. This release ships to zero live installs — no data migration path is provided; sites with pre-migration schema must be recreated from scratch.
* Added an "MCP" tab to the shared AcrossAI Settings page (?page=acrossai-settings) with three operator toggles: enable CLI connections (acrossai_mcp_npm_login_enabled), enable direct Claude Connectors mode (acrossai_mcp_claude_connectors_enabled), and Delete all data on uninstall (acrossai_mcp_uninstall_delete_data). Sibling to acrossai-abilities-manager's Abilities tab.
* BEHAVIOR CHANGE: uninstall.php now preserves ALL plugin data by default. The pre-Feature-012 build dropped acrossai_mcp_oauth_tokens + acrossai_mcp_oauth_audit unconditionally; this build preserves every wp_acrossai_mcp_* table and every acrossai_mcp_* option unless the operator explicitly ticks the "Delete all data on uninstall" checkbox on the MCP settings tab and saves. Sites that expected the pre-Feature-012 OAuth-table wipe on uninstall must tick the new checkbox before uninstall.
* Removed the standalone "CLI Auth Log" admin submenu at ?page=acrossai_mcp_manager_cli_auth_log. The underlying wp_acrossai_mcp_cli_auth_logs table + Query/Row classes remain — they continue to power the OAuth authentication flow. Auth-log inspection is now available via WP-CLI (wp db query "SELECT ... FROM wp_acrossai_mcp_cli_auth_logs"); the standalone submenu was redundant post-Feature-011.
* Refactored the per-server-edit page (?page=acrossai_mcp_manager&action=edit) into a per-tab class hierarchy under admin/Partials/ServerTabs/. Ported 7 additional tabs from the reference plugin (Overview, npm, MCP Clients, WP-CLI, Tools, Abilities, MCP Tracker) plus 2 database-registered-only tabs (Update Server, Danger Zone). The full 11-tab UI is now available for database-registered servers; plugin-registered servers see 9 tabs.
* NEW: Public Renderer layer under public/Renderers/ exposes 3 client-configuration blocks (npm, MCP Clients, Claude Connector) as a reusable API so third-party plugins (BuddyBoss, WooCommerce, other AcrossAI-family plugins) can embed the same UI on their own admin or frontend surfaces with zero code duplication. Public API surface: static Renderer::render() method + acrossai_mcp_render_client_block action hook + acrossai_mcp_client_block_context filter + acrossai_mcp_client_classes filter + shortcodes ([acrossai_mcp_npm_block], [acrossai_mcp_clients_block], [acrossai_mcp_claude_connector_block]) + REST endpoint (/wp-json/acrossai-mcp-manager/v1/generate-app-password) with defense-in-depth Application Password lockdown to get_current_user_id(). API is @experimental May change without notice before 1.0.0 (per DEC-CLIENT-RENDERER-PUBLIC-API). Restored CliAuthLogListTable + added ConnectorAuditLogListTable as per-server tab inspectors under DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG's blessed reintroduction path. See docs/integrations/buddyboss-example.md and docs/integrations/woocommerce-example.md for third-party integrator onboarding.
* Adopted wpboilerplate/wpb-access-control v2 with per-server access rules, MCP-boundary enforcement via the mcp_adapter_pre_tool_call filter shipped by wordpress/mcp-adapter, and a shared Renderer block (AccessControlBlock) that third-party plugins can embed on their own admin surfaces. Fixes 3 fatal v1-API call sites (AccessControlTab.php, CliController.php /servers route, Main.php TODO block). Activator now creates the {prefix}mcp_manager_access_control table; uninstall opt-in gate purges the namespace + drops the table + deletes the version option. Two observability action hooks let operators log denials via any listener: `acrossai_mcp_access_control_denied` fires immediately before returning WP_Error / empty server list on deny (args: user_id, server_slug, tool_name-or-null, context_slug where context_slug is `'cli_servers'` at CliController or `'mcp_tool_call'` at MCP boundary); `acrossai_mcp_access_control_missing_server` fires when a server was DELETEd mid-flight (args: server_slug, tool_name, user_id). Minimal listener example: `add_action('acrossai_mcp_access_control_denied', function($u,$s,$t,$c){ error_log("[AC deny] user=$u server=$s tool=$t via=$c"); }, 10, 4);`. See DEC-ACCESS-CONTROL-V2-ADOPTION + D18 + D19 for the wrapper pattern, canonical MCP-boundary hook, and fail-open observability pattern.

= 0.0.5 =
* Changed: access-control admin UI now loads assets from the wpb-access-control vendor package's own compiled React bundle; removed plugin-bundled copies at assets/access-control/
* Changed: replace AccessControlUI AJAX bootstrap with REST API registration via AccessControlManager::register_rest_api(); rules are now served and saved via dedicated REST endpoints
* Changed: access-control tab renders a React component hydrated by the vendor webpack bundle instead of legacy plain-JS markup
* Added: graceful degradation notice when vendor assets are unavailable — enforcement remains active
* Updated: wpb-access-control to v1.0.0 (stable baseline); automattic/jetpack-autoloader to latest minor

= 0.0.4 =
* Improved: bundle access-control UI assets (CSS + JS) directly in the plugin at assets/access-control/ so the admin panel works regardless of whether the wpb-access-control vendor package ships them

= 0.0.3 =
* Dependencies: update wpb-access-control to BerlinDB-backed version; add berlindb/core; update bshaffer/oauth2-server-httpfoundation-bridge and symfony/deprecation-contracts
* Fixed: remove removed AccessControlTable references; fixes fatal error on plugin activation
* Fixed: access-control table is now auto-bootstrapped by RuleQuery — no manual maybe_create_table() needed
* Fixed: remove dead save_access_control POST handler; access-control saves now handled by library AJAX
* Fixed: update v1.5.0 legacy migration to use RuleQuery::set_rule() instead of removed AccessControlTable::update()

= 0.0.2 =
* Security: sanitize and validate all $_GET/$_POST inputs with sanitize_key(), sanitize_text_field(), absint(), and wp_unslash()
* Paths: replace hardcoded ABSPATH with get_home_path() for correct subdirectory-install support
* Enqueue: remove all inline <style>/<script> blocks; move to external CSS/JS files loaded via wp_enqueue_style() and wp_enqueue_script()

= 0.0.1 =
* Initial release
* Support for VS Code, Claude, GitHub Copilot, ChatGPT Codex, and custom clients
* Format #1 (Automattic-recommended) MCP configuration
* Native WordPress Application Passwords integration
* Dynamic configuration generation per provider
* Full REST API support
* Admin UI with client tabs
* Copy-to-clipboard functionality

== Support & Contribution ==

For issues, feature requests, or contributions, visit the plugin repository.

Questions? Check the FAQ section or look for documentation in the plugin settings page.

== Development ==

This plugin follows WordPress coding standards and best practices:
- PHP 7.4+ compatible
- Full object-oriented architecture
- Secure nonce verification
- Proper capability checks
- Sanitized input validation
- Escaped output

== License ==

This plugin is licensed under the GPL-2.0-or-later license. See LICENSE file for details.

== Credits ==

MCP Manager is built with:
- WordPress native APIs
- Automattic's MCP WordPress Remote package
- WordPress Application Passwords system

Developed with ❤️ for the WordPress community.
