<?php
/**
 * OAuth tokens table — stores hashed access and refresh tokens.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the {prefix}acrossai_mcp_oauth_tokens table.
 *
 * Tokens are stored as SHA-256 hashes; the plaintext value is only known at
 * issuance time and never stored.
 *
 * @since 1.6.0
 */
class OAuthTokensTable {

	const TABLE_NAME        = 'acrossai_mcp_oauth_tokens';
	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'acrossai_mcp_oauth_tokens_db_version';

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
			token_hash VARCHAR(64) NOT NULL,
			token_type VARCHAR(10) NOT NULL DEFAULT 'access',
			client_id VARCHAR(80) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			scope VARCHAR(255) NOT NULL DEFAULT '',
			audience VARCHAR(500) NOT NULL DEFAULT '',
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY client_id (client_id)
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
	 * Insert a new token row.
	 *
	 * @param string $token_hash SHA-256 hash of the plaintext token.
	 * @param string $token_type 'access' or 'refresh'.
	 * @param string $client_id  Client ID that requested the token.
	 * @param int    $user_id    WP user ID the token is issued for.
	 * @param string $scope      Space-separated scopes.
	 * @param string $audience   Canonical MCP server URI this token is bound to.
	 * @param int    $ttl_seconds Seconds until expiry (default 3600 for access tokens).
	 *
	 * @return bool True on success.
	 */
	public static function insert( $token_hash, $token_type, $client_id, $user_id, $scope, $audience, $ttl_seconds = 3600 ) {
		global $wpdb;

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + (int) $ttl_seconds );

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array(
				'token_hash' => $token_hash,
				'token_type' => $token_type,
				'client_id'  => $client_id,
				'user_id'    => (int) $user_id,
				'scope'      => $scope,
				'audience'   => $audience,
				'expires_at' => $expires_at,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Return a non-expired token row by its SHA-256 hash, or null.
	 *
	 * @param string $token_hash SHA-256 hash of the plaintext token.
	 * @param string $token_type 'access' or 'refresh'.
	 *
	 * @return array|null
	 */
	public static function get( $token_hash, $token_type = 'access' ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE token_hash = %s AND token_type = %s AND expires_at > UTC_TIMESTAMP()', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$token_hash,
				$token_type
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete a token by its hash (revoke).
	 *
	 * @param string $token_hash SHA-256 hash.
	 *
	 * @return bool
	 */
	public static function delete( $token_hash ) {
		global $wpdb;

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array( 'token_hash' => $token_hash ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Delete all tokens for a given user (e.g. on logout or user deletion).
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return void
	 */
	public static function delete_by_user( $user_id ) {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array( 'user_id' => (int) $user_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete all tokens for a given client (e.g. when the admin revokes a credential).
	 *
	 * @param string $client_id Client ID.
	 *
	 * @return void
	 */
	public static function delete_by_client( $client_id ) {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array( 'client_id' => $client_id ),
			array( '%s' )
		);
	}

	/**
	 * Delete all tokens for a given client + user pair.
	 *
	 * Called when an existing refresh token is rotated.
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id   WP user ID.
	 *
	 * @return void
	 */
	public static function delete_by_client_user( $client_id, $user_id ) {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array(
				'client_id' => $client_id,
				'user_id'   => (int) $user_id,
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Return all active access tokens for a given user (for the admin UI).
	 *
	 * @param int $user_id WP user ID.
	 *
	 * @return array[]
	 */
	public static function get_active_by_user( $user_id ) {
		global $wpdb;

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE user_id = %d AND token_type = %s AND expires_at > UTC_TIMESTAMP() ORDER BY created_at DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $user_id,
				'access'
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Return all active access tokens for a given audience (for the admin UI).
	 *
	 * @param string $audience Canonical MCP server URI.
	 *
	 * @return array[]
	 */
	public static function get_active_by_audience( $audience ) {
		global $wpdb;

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT t.*, u.user_login FROM ' . self::get_table_name() . ' t LEFT JOIN ' . $wpdb->users . ' u ON t.user_id = u.ID WHERE t.audience = %s AND t.token_type = %s AND t.expires_at > UTC_TIMESTAMP() ORDER BY t.created_at DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$audience,
				'access'
			),
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Purge expired tokens (housekeeping).
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
