<?php
/**
 * OAuth access tokens table lifecycle (dbDelta create/upgrade).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthToken
 */

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthToken;

defined( 'ABSPATH' ) || exit;

class Table {

	const TABLE_NAME        = 'acrossai_mcp_oauth_tokens';
	const DB_VERSION        = '0.0.1';
	const DB_VERSION_OPTION = 'acrossai_mcp_oauth_tokens_db_version';

	const CACHE_GROUP = 'acrossai_mcp';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Fully-qualified table name (with $wpdb->prefix).
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the table on first activation; no-op once DB_VERSION matches.
	 */
	public function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		$this->create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run dbDelta with the canonical schema.
	 */
	private function create_table(): void {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			access_token_hash CHAR(64) NOT NULL,
			server_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			issued_from_code_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			scope VARCHAR(64) NOT NULL DEFAULT 'mcp',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY access_token_hash (access_token_hash),
			KEY server_expires (server_id, expires_at),
			KEY user_created (user_id, created_at),
			KEY issued_from_code (issued_from_code_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
