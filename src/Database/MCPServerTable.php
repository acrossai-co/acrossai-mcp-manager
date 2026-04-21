<?php
/**
 * MCP Server database table manager.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the {prefix}acrossai_mcp_servers custom table.
 *
 * Schema
 * ------
 *   id          BIGINT UNSIGNED  PK auto-increment
 *   server_name VARCHAR(255)     human-readable name
 *   description VARCHAR(500)     optional description
 *   is_enabled  TINYINT(1)       1 = running, 0 = stopped
 *   created_at  DATETIME         row creation timestamp
 *
 * Bump DB_VERSION whenever the schema changes — maybe_create_table() will
 * detect the mismatch and run dbDelta automatically.
 *
 * Caching
 * -------
 * All read methods use the 'acrossai_mcp' cache group. Write methods
 * (toggle_status, update_server, insert_default_server) delete the
 * affected keys so stale data is never served.
 *
 * @since 1.0.0
 */
class MCPServerTable {

	const TABLE_NAME        = 'acrossai_mcp_servers';
	const DB_VERSION        = '1.1.0';
	const DB_VERSION_OPTION = 'acrossai_mcp_manager_db_version';

	/**
	 * Object-cache group used for all keys in this class.
	 */
	const CACHE_GROUP = 'acrossai_mcp';

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the full table name including the WordPress DB prefix.
	 *
	 * The returned value is always derived from $wpdb->prefix (sanitised by
	 * WP core) + a hard-coded constant, so it is safe to interpolate into SQL
	 * after being passed through esc_sql().
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	// -------------------------------------------------------------------------
	// Table lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Create or upgrade the table using dbDelta (idempotent).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires exactly two spaces before PRIMARY KEY.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			server_name VARCHAR(255) NOT NULL,
			description VARCHAR(500) NOT NULL DEFAULT '',
			is_enabled TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run create_table() only when the stored schema version is outdated.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}

		// Always seed — insert_default_server() is a no-op when rows exist.
		self::insert_default_server();
	}

	/**
	 * Seed the default server row when the table is empty.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function insert_default_server() {
		global $wpdb;

		// $table_name is derived from $wpdb->prefix + a constant — safe to interpolate.
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		if ( 0 === $count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				self::get_table_name(),
				array(
					'server_name' => 'Default MCP Server',
					'description' => 'WordPress MCP Adapter integration for AI clients (VS Code, Claude, GitHub Codex, ChatGPT).',
					'is_enabled'  => 0,
				),
				array( '%s', '%s', '%d' )
			);

			// Bust caches so the new row is immediately visible.
			wp_cache_delete( 'all_servers', self::CACHE_GROUP );
			wp_cache_delete( 'has_enabled', self::CACHE_GROUP );
		}
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Return all server rows ordered by id ASC.
	 *
	 * @since 1.0.0
	 *
	 * @return array[] Array of associative-array rows.
	 */
	public static function get_all() {
		$cached = wp_cache_get( 'all_servers', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// $table_name is derived from $wpdb->prefix + a constant — safe to interpolate.
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY id ASC", ARRAY_A );
		$results = $results ?: array();

		wp_cache_set( 'all_servers', $results, self::CACHE_GROUP );

		return $results;
	}

	/**
	 * Return all server rows where is_enabled = 1.
	 *
	 * @since 1.0.0
	 *
	 * @return array[] Array of associative-array rows.
	 */
	public static function get_enabled_servers() {
		$cached = wp_cache_get( 'enabled_servers', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// $table_name is derived from $wpdb->prefix + a constant — safe to interpolate.
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( "SELECT * FROM {$table_name} WHERE is_enabled = 1 ORDER BY id ASC", ARRAY_A );
		$results = $results ?: array();

		wp_cache_set( 'enabled_servers', $results, self::CACHE_GROUP );

		return $results;
	}

	/**
	 * Return a single server row by primary key.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Server row ID.
	 *
	 * @return array|null Row as associative array, or null if not found.
	 */
	public static function get_by_id( $id ) {
		$id        = absint( $id );
		$cache_key = 'server_' . $id;

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// $table_name is derived from $wpdb->prefix + a constant — safe to interpolate.
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		// Cache null results too so repeated misses don't hit the DB.
		wp_cache_set( $cache_key, $row, self::CACHE_GROUP );

		return $row;
	}

	/**
	 * Toggle the is_enabled flag for a server row (0 → 1 or 1 → 0).
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Server row ID.
	 *
	 * @return bool True on success, false if not found or update failed.
	 */
	public static function toggle_status( $id ) {
		$id     = absint( $id );
		$server = self::get_by_id( $id );

		if ( ! $server ) {
			return false;
		}

		global $wpdb;

		$new_status = $server['is_enabled'] ? 0 : 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::get_table_name(),
			array( 'is_enabled' => $new_status ),
			array( 'id'         => $id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'server_' . $id, self::CACHE_GROUP );
			wp_cache_delete( 'all_servers', self::CACHE_GROUP );
			wp_cache_delete( 'enabled_servers', self::CACHE_GROUP );
			wp_cache_delete( 'has_enabled', self::CACHE_GROUP );
		}

		return false !== $result;
	}

	/**
	 * Update editable fields for a server row.
	 *
	 * Only whitelisted keys (server_name, description) are persisted.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $id   Server row ID.
	 * @param array $data Associative array of fields to update.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function update_server( $id, array $data ) {
		$id      = absint( $id );
		$allowed = array( 'server_name', 'description' );
		$fields  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $fields ) ) {
			return false;
		}

		global $wpdb;

		$formats = array_fill( 0, count( $fields ), '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			self::get_table_name(),
			$fields,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'server_' . $id, self::CACHE_GROUP );
			wp_cache_delete( 'all_servers', self::CACHE_GROUP );
		}

		return false !== $result;
	}

	/**
	 * Return true if at least one server row has is_enabled = 1.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function has_any_enabled() {
		$cached = wp_cache_get( 'has_enabled', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		global $wpdb;

		// $table_name is derived from $wpdb->prefix + a constant — safe to interpolate.
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE is_enabled = 1" );

		// Cache as int (0/1) so false !== 0 and false !== 1 both hold.
		wp_cache_set( 'has_enabled', $count, self::CACHE_GROUP );

		return $count > 0;
	}
}
