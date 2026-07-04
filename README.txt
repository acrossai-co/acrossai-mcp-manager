=== AcrossAI MCP Manager ===
Contributors: raftaar1191
Donate link: https://github.com/WPBoilerplate/acrossai-mcp-manager
Tags: mcp, ai, claude, anthropic, oauth
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.0.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Manage MCP (Model Context Protocol) servers in WordPress — register, configure, and expose them to AI clients like Claude.

== Description ==

This is the long description.  No limit, and you can use Markdown (as well as in the following sections).

For backwards compatibility, if this section is missing, the full length of the short description will be used, and
Markdown parsed.

A few notes about the sections above:

*   "Contributors" is a comma separated list of wp.org/wp-plugins.org usernames
*   "Tags" is a comma separated list of tags that apply to the plugin
*   "Requires at least" is the lowest version that the plugin will work on
*   "Tested up to" is the highest version that you've *successfully used to test the plugin*. Note that it might work on
higher versions... this is just the highest one you've verified.
*   Stable tag should indicate the Subversion "tag" of the latest stable version, or "trunk," if you use `/trunk/` for
stable.

    Note that the `readme.txt` of the stable tag is the one that is considered the defining one for the plugin, so
if the `/trunk/readme.txt` file says that the stable tag is `4.3`, then it is `/tags/4.3/readme.txt` that'll be used
for displaying information about the plugin.  In this situation, the only thing considered from the trunk `readme.txt`
is the stable tag pointer.  Thus, if you develop in trunk, you can update the trunk `readme.txt` to reflect changes in
your in-development version, without having that information incorrectly disclosed about the current stable version
that lacks those changes -- as long as the trunk's `readme.txt` points to the correct stable tag.

    If no stable tag is provided, it is assumed that trunk is stable, but you should specify "trunk" if that's where
you put the stable version, in order to eliminate any doubt.

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload `acrossai-mcp-manager.php` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Place `<?php do_action('plugin_name_hook'); ?>` in your templates

== Frequently Asked Questions ==

= A question that someone might have =

An answer to that question.

= What about foo bar? =

Answer to foo bar dilemma.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.(png|jpg|jpeg|gif). Note that the screenshot is taken from
the /assets directory or the directory that contains the stable readme.txt (tags or trunk). Screenshots in the /assets
directory take precedence. For example, `/assets/screenshot-1.png` would win over `/tags/4.3/screenshot-1.png`
(or jpg, jpeg, gif).
2. This is the second screen shot

== Changelog ==

= Unreleased =
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

== Arbitrary section ==

You may provide arbitrary sections, in the same format as the ones above.  This may be of use for extremely complicated
plugins where more information needs to be conveyed that doesn't fit into the categories of "description" or
"installation."  Arbitrary sections will be shown below the built-in sections outlined above.

== A brief Markdown Example ==

Ordered list:

1. Some feature
1. Another feature
1. Something else about the plugin

Unordered list:

* something
* something else
* third thing

Here's a link to [WordPress](http://wordpress.org/ "Your favorite software") and one to [Markdown's Syntax Documentation][markdown syntax].
Titles are optional, naturally.

[markdown syntax]: http://daringfireball.net/projects/markdown/syntax
            "Markdown is what the parser uses to process much of the readme file"

Markdown uses email style notation for blockquotes and I've been told:
> Asterisks for *emphasis*. Double it up  for **strong**.

`<?php code(); // goes in backticks ?>`
