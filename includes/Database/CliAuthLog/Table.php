<?php
/**
 * CLI auth log table lifecycle (dbDelta create/upgrade).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

class Table {

	const TABLE_NAME        = 'acrossai_mcp_cli_auth_logs';
	const DB_VERSION        = '0.0.2';
	const DB_VERSION_OPTION = 'acrossai_mcp_cli_auth_log_db_version';

	const CACHE_GROUP = 'acrossai_mcp';

	protected static $_instance = null;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {}

	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	public function maybe_create_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		$this->create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	private function create_table(): void {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			server_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			server_slug VARCHAR(255) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT '',
			failure_code VARCHAR(100) NOT NULL DEFAULT '',
			auth_code_hash CHAR(64) NOT NULL DEFAULT '',
			app_password_uuid VARCHAR(64) NOT NULL DEFAULT '',
			redirect_uri VARCHAR(500) NOT NULL DEFAULT '',
			code_challenge CHAR(43) NOT NULL DEFAULT '',
			code_challenge_method VARCHAR(16) NOT NULL DEFAULT '',
			scope VARCHAR(64) NOT NULL DEFAULT '',
			approved_at DATETIME NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY auth_code_hash (auth_code_hash),
			KEY server_created (server_id, created_at),
			KEY server_status_created (server_id, status, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
