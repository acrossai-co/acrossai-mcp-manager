<?php
/**
 * Default MCP server seeder (extracted from MCPServer\Table in Feature 011).
 *
 * Stateless pure service helper (A11/A15) — no singleton, no ctor.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent seeder for the default MCP server row.
 *
 * Called by Activator::activate() immediately after MCPServer\Table::instance()->maybe_upgrade().
 */
final class DefaultServerSeeder {

	/**
	 * Default MCP server slug — relocated here from MCPServer\Table in Feature 011 (FR-022).
	 */
	public const SLUG = 'mcp-adapter-default-server';

	/**
	 * Idempotently seed the default MCP server row.
	 *
	 * @return void
	 */
	public static function seed(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE server_slug = %s', $table, self::SLUG )
		);
		if ( $existing > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'server_name'            => 'Default MCP Server',
				'server_slug'            => self::SLUG,
				'description'            => __( 'Default MCP server registered by the plugin.', 'acrossai-mcp-manager' ),
				'is_enabled'             => 0,
				'registered_from'        => 'plugin',
				'server_route_namespace' => 'mcp',
				'server_route'           => self::SLUG,
				'server_version'         => 'v1.0.0',
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		wp_cache_delete( 'all_servers', 'acrossai_mcp' );
	}
}
