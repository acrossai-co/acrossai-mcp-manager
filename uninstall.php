<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * This file may be updated more in future version of the Boilerplate; however, this is the
 * general skeleton and outline for how the file should work.
 *
 * For more information, see the following discussion:
 * https://github.com/wpboilerplate/acrossai-mcp-manager
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

global $wpdb;

// Drop Phase 5 OAuth tables — uninstall is destructive by nature
// (spec.md Edge Cases: outstanding tokens become invalid silently).
$oauth_tables = array(
	$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
	$wpdb->prefix . 'acrossai_mcp_oauth_audit',
);
foreach ( $oauth_tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Clean up OAuth-related options + scheduled events.
delete_option( 'acrossai_mcp_oauth_tokens_db_version' );
delete_option( 'acrossai_mcp_oauth_audit_db_version' );
wp_clear_scheduled_hook( 'acrossai_mcp_oauth_cleanup' );

// NOTE: We deliberately do NOT drop `acrossai_mcp_cli_auth_logs` or
// `acrossai_mcp_servers` — both predate Phase 5 and are owned by Phase 1/2.
// Their teardown belongs to their respective uninstall paths.
