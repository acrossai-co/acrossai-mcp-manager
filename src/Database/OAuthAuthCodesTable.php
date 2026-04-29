<?php
/**
 * OAuth authorization codes table — short-lived, single-use codes.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the {prefix}acrossai_mcp_oauth_codes table.
 *
 * Codes are hashed on storage (SHA-256) and deleted immediately after exchange.
 *
 * @since 1.6.0
 */
class OAuthAuthCodesTable {

	const TABLE_NAME        = 'acrossai_mcp_oauth_codes';
	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'acrossai_mcp_oauth_codes_db_version';

	/**
	 * Return the full table name including the WP prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or upgrade the table (idempotent).
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			code_hash VARCHAR(64) NOT NULL,
			client_id VARCHAR(80) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			redirect_uri VARCHAR(500) NOT NULL DEFAULT '',
			scope VARCHAR(255) NOT NULL DEFAULT '',
			resource VARCHAR(500) NOT NULL DEFAULT '',
			code_challenge VARCHAR(128) NOT NULL DEFAULT '',
			code_challenge_method VARCHAR(10) NOT NULL DEFAULT 'S256',
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY code_hash (code_hash)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run create_table() only when the stored schema version is outdated.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * Insert a new authorization code.
	 *
	 * @param string $code_hash             SHA-256 hash of the plaintext code.
	 * @param string $client_id             Client that requested it.
	 * @param int    $user_id               WP user who approved.
	 * @param string $redirect_uri          Exact redirect URI from the request.
	 * @param string $scope                 Approved scope string.
	 * @param string $resource              Canonical MCP server URI (audience).
	 * @param string $code_challenge        PKCE code challenge.
	 * @param string $code_challenge_method 'S256'.
	 * @param int    $ttl_seconds           Code lifetime in seconds (default 60).
	 *
	 * @return bool True on success.
	 */
	public static function insert(
		$code_hash,
		$client_id,
		$user_id,
		$redirect_uri,
		$scope,
		$resource,
		$code_challenge,
		$code_challenge_method,
		$ttl_seconds = 60
	) {
		global $wpdb;

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) $ttl_seconds );

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array(
				'code_hash'             => $code_hash,
				'client_id'             => $client_id,
				'user_id'               => (int) $user_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $scope,
				'resource'              => $resource,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'expires_at'            => $expires_at,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Return a non-expired auth code row by its SHA-256 hash, or null.
	 *
	 * @param string $code_hash SHA-256 hash.
	 *
	 * @return array|null
	 */
	public static function get( $code_hash ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE code_hash = %s AND expires_at > UTC_TIMESTAMP()', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$code_hash
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete an auth code by hash (consume single-use code).
	 *
	 * @param string $code_hash SHA-256 hash.
	 *
	 * @return bool
	 */
	public static function delete( $code_hash ) {
		global $wpdb;

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array( 'code_hash' => $code_hash ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Purge expired codes (housekeeping).
	 *
	 * @return void
	 */
	public static function purge_expired() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'DELETE FROM ' . self::get_table_name() . ' WHERE expires_at <= UTC_TIMESTAMP()' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
