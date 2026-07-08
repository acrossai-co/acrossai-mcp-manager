<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Behavior (Feature 012 — preserve-by-default):
 *
 *   Reads the `acrossai_mcp_uninstall_delete_data` option (int 0/1, default 0).
 *   - 0 (default): preserves ALL plugin data on uninstall — no tables dropped,
 *     no options deleted, no scheduled hooks cleared. This matches the WP.org
 *     plugin guideline #5 (uninstall must not destroy data unless the operator
 *     explicitly opts in). This is a BEHAVIOR CHANGE from pre-Feature-012, where
 *     `acrossai_mcp_oauth_tokens` + `acrossai_mcp_oauth_audit` were dropped
 *     unconditionally.
 *   - 1 (destructive): drops all four wp_acrossai_mcp_* tables, deletes every
 *     `acrossai_mcp_*` option via LIKE-sweep, and clears the OAuth cleanup cron.
 *     Operators opt in via the "Delete all data on uninstall" checkbox on the
 *     MCP tab of the shared AcrossAI Settings page (see
 *     admin/Partials/SettingsMenu.php).
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Preserve-by-default gate. Operators opt into destructive teardown by
// ticking the "Delete all data on uninstall" checkbox on the MCP tab.
if ( 1 !== (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) ) {
	return;
}

global $wpdb;

// Drop all four plugin tables. Table names are derived from $wpdb->prefix +
// hardcoded stems (no user input reaches SQL). Uses the `%i` identifier
// placeholder (WordPress 6.2+) so $wpdb->prepare() escapes the table name
// safely and no phpcs:ignore is needed.
// Feature 015 — Access Control v2 (FR-012 / FR-013). Purge the plugin's
// namespace via the vendor RuleQuery BEFORE the raw DROP so BerlinDB
// invalidates its cache for the namespace. class_exists guards against the
// "vendor package uninstalled before this plugin" edge case (US5 scenario 3).
if ( class_exists( '\WPBoilerplate\AccessControl\Database\Rule\RuleQuery' ) ) {
	$rule_query = new \WPBoilerplate\AccessControl\Database\Rule\RuleQuery( 'mcp' );
	if ( method_exists( $rule_query, 'purge_namespace' ) ) {
		$rule_query->purge_namespace( 'acrossai-mcp-manager' );
	}
}

$tables = array(
	$wpdb->prefix . 'acrossai_mcp_servers',
	$wpdb->prefix . 'acrossai_mcp_cli_auth_logs',
	$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
	$wpdb->prefix . 'acrossai_mcp_oauth_audit',
	$wpdb->prefix . 'mcp_access_control', // F015 AC rule table (TABLE_SLUG = 'mcp').
	$wpdb->prefix . 'acrossai_mcp_server_abilities', // F017 per-server ability overrides.
	$wpdb->prefix . 'acrossai_mcp_server_tools',     // F020 per-server tool selection.
);
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}

// Feature 015 — delete the vendor-owned schema version option. The
// `acrossai_mcp_*` LIKE-sweep below does NOT match `wpb_ac_mcp_*`, so
// the vendor's version tracking option must be cleaned up explicitly.
delete_option( 'wpb_ac_mcp_db_version' );

// Delete every `acrossai_mcp_*` option via LIKE-sweep on wp_options.
$options = $wpdb->get_col(
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", 'acrossai_mcp_%' )
);
if ( is_array( $options ) ) {
	foreach ( $options as $option_name ) {
		delete_option( $option_name );
	}
}
