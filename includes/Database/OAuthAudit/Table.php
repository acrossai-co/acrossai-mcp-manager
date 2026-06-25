<?php
/**
 * OAuth audit log table lifecycle (dbDelta create/upgrade).
 *
 * Append-only — no application path updates rows.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAudit
 */

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAudit;

defined( 'ABSPATH' ) || exit;

class Table {

	const TABLE_NAME        = 'acrossai_mcp_oauth_audit';
	const DB_VERSION        = '0.0.1';
	const DB_VERSION_OPTION = 'acrossai_mcp_oauth_audit_db_version';

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
			event_type VARCHAR(64) NOT NULL DEFAULT '',
			server_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			client_id VARCHAR(255) NOT NULL DEFAULT '',
			token_hash_prefix CHAR(8) NOT NULL DEFAULT '',
			endpoint VARCHAR(255) NOT NULL DEFAULT '',
			details_json TEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_created (event_type, created_at),
			KEY server_created (server_id, created_at),
			KEY user_created (user_id, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
