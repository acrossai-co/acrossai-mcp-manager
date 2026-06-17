<?php
/**
 * MCP Server table lifecycle (dbDelta create/upgrade).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

class Table {

	const TABLE_NAME        = 'acrossai_mcp_servers';
	const DB_VERSION        = '0.0.1';
	const DB_VERSION_OPTION = 'acrossai_mcp_manager_db_version';

	const DEFAULT_SERVER_SLUG = 'mcp-adapter-default-server';
	const CACHE_GROUP         = 'acrossai_mcp';

	protected static $_instance = null;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {}

	/**
	 * Return the full table name including the WP DB prefix.
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Idempotent table create / upgrade. Calls dbDelta.
	 */
	public function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			$this->insert_default_server();
			return;
		}

		$this->create_table();
		$this->insert_default_server();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	private function create_table(): void {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires exactly two spaces before PRIMARY KEY.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			server_name VARCHAR(255) NOT NULL,
			server_slug VARCHAR(255) NOT NULL DEFAULT '',
			description VARCHAR(500) NOT NULL DEFAULT '',
			is_enabled TINYINT(1) NOT NULL DEFAULT 0,
			registered_from VARCHAR(50) NOT NULL DEFAULT 'plugin',
			server_route_namespace VARCHAR(100) NOT NULL DEFAULT 'mcp',
			server_route VARCHAR(255) NOT NULL DEFAULT '',
			server_version VARCHAR(50) NOT NULL DEFAULT 'v1.0.0',
			claude_connector_client_id VARCHAR(255) NOT NULL DEFAULT '',
			claude_connector_client_secret VARCHAR(255) NOT NULL DEFAULT '',
			claude_connector_redirect_uri VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY server_slug (server_slug)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Seed the default server row when the table is empty.
	 * No-op when at least one row exists.
	 */
	private function insert_default_server(): void {
		global $wpdb;

		$table_name = $this->get_table_name();

		// SEC-S1 (2026-06-17): use the %i identifier placeholder rather than
		// interpolating the table name. The value is server-trusted ($wpdb->prefix
		// + a constant), so no injection path exists either way, but the %i form
		// is the modern WordPress pattern and removes the phpcs:ignore.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name )
		);

		if ( 0 !== $count ) {
			return;
		}

		$wpdb->insert(
			$table_name,
			array(
				'server_name'                    => 'Default MCP Server',
				'server_slug'                    => self::DEFAULT_SERVER_SLUG,
				'description'                    => 'WordPress MCP Adapter integration for AI clients (VS Code, Claude, GitHub Codex, ChatGPT).',
				'is_enabled'                     => 0,
				'registered_from'                => 'plugin',
				'server_route_namespace'         => 'mcp',
				'server_route'                   => self::DEFAULT_SERVER_SLUG,
				'server_version'                 => 'v1.0.0',
				'claude_connector_client_id'     => '',
				'claude_connector_client_secret' => '',
				'claude_connector_redirect_uri'  => '',
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		wp_cache_delete( 'all_servers', self::CACHE_GROUP );
	}
}
