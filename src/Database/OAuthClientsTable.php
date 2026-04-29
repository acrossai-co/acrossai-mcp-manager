<?php
/**
 * OAuth clients table — stores DCR-registered OAuth clients.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Database
 */

namespace ACROSSAI_MCP_MANAGER\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the {prefix}acrossai_mcp_oauth_clients table.
 *
 * @since 1.6.0
 */
class OAuthClientsTable {

	const TABLE_NAME        = 'acrossai_mcp_oauth_clients';
	const DB_VERSION        = '1.1.0';
	const DB_VERSION_OPTION = 'acrossai_mcp_oauth_clients_db_version';

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
			client_id VARCHAR(80) NOT NULL,
			client_secret_hash VARCHAR(255) NOT NULL DEFAULT '',
			client_name VARCHAR(255) NOT NULL DEFAULT '',
			redirect_uris TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id)
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
	 * Insert a new pre-registered OAuth client (admin-created confidential client).
	 *
	 * @param string $client_id          Unique opaque client ID (e.g. mcpc_ prefixed).
	 * @param string $client_secret_hash wp_hash_password() of the plaintext secret.
	 * @param string $client_name        Human-readable name.
	 * @param array  $redirect_uris      Allowed redirect URIs.
	 *
	 * @return bool True on success.
	 */
	public static function insert( $client_id, $client_secret_hash, $client_name, array $redirect_uris ) {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array(
				'client_id'          => $client_id,
				'client_secret_hash' => $client_secret_hash,
				'client_name'        => sanitize_text_field( $client_name ),
				'redirect_uris'      => wp_json_encode( $redirect_uris ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Return a client row by client_id, or null if not found.
	 *
	 * @param string $client_id Client ID.
	 *
	 * @return array|null
	 */
	public static function get( $client_id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . self::get_table_name() . ' WHERE client_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$client_id
			),
			ARRAY_A
		);

		if ( $row && ! empty( $row['redirect_uris'] ) ) {
			$row['redirect_uris'] = json_decode( $row['redirect_uris'], true ) ?: array();
		}

		return $row ?: null;
	}

	/**
	 * Return all registered clients (for the admin credentials list).
	 *
	 * @return array[]
	 */
	public static function get_all() {
		global $wpdb;

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'SELECT id, client_id, client_name, redirect_uris, created_at FROM ' . self::get_table_name() . ' ORDER BY created_at DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		if ( ! $results ) {
			return array();
		}

		foreach ( $results as &$row ) {
			if ( ! empty( $row['redirect_uris'] ) ) {
				$row['redirect_uris'] = json_decode( $row['redirect_uris'], true ) ?: array();
			}
		}
		unset( $row );

		return $results;
	}

	/**
	 * Verify a plaintext client secret against the stored hash.
	 *
	 * @param string $client_id  Client ID.
	 * @param string $plaintext  Plaintext secret from the request.
	 *
	 * @return bool True if the secret matches.
	 */
	public static function verify_secret( $client_id, $plaintext ) {
		$row = self::get( $client_id );
		if ( ! $row || empty( $row['client_secret_hash'] ) ) {
			return false;
		}
		return wp_check_password( $plaintext, $row['client_secret_hash'] );
	}

	/**
	 * Delete a client by client_id.
	 *
	 * @param string $client_id Client ID.
	 *
	 * @return bool
	 */
	public static function delete( $client_id ) {
		global $wpdb;

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::get_table_name(),
			array( 'client_id' => $client_id ),
			array( '%s' )
		);

		return false !== $result;
	}
}
