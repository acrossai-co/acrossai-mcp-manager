=== AcrossAI MCP Manager ===
Contributors: raftaar1191
Tags: mcp, ai, copilot, vscode, claude
Requires at least: 7.0
Requires PHP: 8.1
Tested up to: 7.0
Stable tag: 0.1.0
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

= Unreleased =
* **Feature 022 — Shared AcrossAI Add-ons submenu.** The plugin now registers the shared "Add-ons" nav entry under the AcrossAI top-level menu, powered by Freemius for product id 34418. The page requires `install_plugins`; when a companion AcrossAI plugin is active simultaneously only one plugin contributes the nav entry (the shared package coordinates this so operators never see duplicate submenu rows). Bumps `acrossai-co/main-menu` from `0.0.14` to `0.0.16`. `0.0.15` enabled the Freemius **Account**, **Contact Us**, and **wp.org Support Forum** submenus at package level; `0.0.16` promotes those defaults to `FreemiusInitializer::DEFAULT_MENU` and introduces a new `fs_menu` key on `AddonsPage`'s `$args` array so each consumer plugin explicitly decides which auto-submenus surface. The plugin passes an explicit `fs_menu` array in `includes/Main.php` with `support => false` (the wp.org Support Forum row is redundant with the shared AcrossAI support surface for this product) and every other key matching the vendor defaults — so the full menu policy is visible at the call site. Adds an explicit VCS repositories entry for `acrossai-co/main-menu` in `composer.json` so consumers resolve deterministically from GitHub without waiting on Packagist sync.
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
