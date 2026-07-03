=== AcrossAI MCP Manager ===
Contributors: (this should be a list of wordpress.org userid's)
Donate link: https://github.com/WPBoilerplate/acrossai-mcp-manager
Tags: comments, spam
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

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

= 1.0 =
* A change since the previous version.
* Another change.

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
