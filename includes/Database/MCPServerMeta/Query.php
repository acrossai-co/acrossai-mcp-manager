<?php
/**
 * BerlinDB Query for the MCPServerMeta module.
 *
 * WP-style per-server meta accessor. Bespoke methods:
 *   - get_meta( server_id, meta_key ): ?string   — single value or null if missing
 *   - update_meta( server_id, meta_key, value ): bool — INSERT ... ON DUPLICATE KEY UPDATE
 *   - delete_meta( server_id, meta_key ): int    — count deleted
 *   - get_all_meta_for_server( server_id ): array<string, string>  — keyed map
 *   - get_meta_by_key_prefix( server_id, prefix ): array<string, string>  — keyed map filtered by prefix
 *   - delete_by_server_id( server_id ): int      — cascade cleanup on server deletion
 *   - delete_by_meta_key_prefix( server_id, prefix ): int — targeted bulk delete
 *
 * Storage convention (F037):
 *   meta_key = '_embeds_enabled'   → master toggle ('1' or absent)
 *   meta_key = '_embeds_clients'   → JSON blob: { npm: 0|1, mcp-client: [slugs], connectors: [slugs] }
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerMeta
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta;

defined( 'ABSPATH' ) || exit;

class Query extends \BerlinDB\Database\Kern\Query {

	/** @var string */
	protected $table_name = 'acrossai_mcp_servers_meta';

	/** @var string */
	protected $table_alias = 'asm';

	/** @var string */
	protected $table_schema = Schema::class;

	/** @var string */
	protected $item_name = 'server_meta';

	/** @var string */
	protected $item_name_plural = 'server_metas';

	/** @var string */
	protected $item_shape = Row::class;

	/** @var Query|null */
	protected static $instance = null;

	/**
	 * Private constructor — enforces singleton (S6).
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- visibility override enforces singleton.
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Read a single meta value. Returns null when the row does not exist
	 * (presence-model consumers treat null as "not set").
	 *
	 * @param int    $server_id Server PK.
	 * @param string $meta_key  Meta key.
	 * @return string|null
	 */
	public static function get_meta( int $server_id, string $meta_key ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE server_id = %d AND meta_key = %s LIMIT 1',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				$server_id,
				$meta_key
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * UPSERT a meta value. INSERT if the (server_id, meta_key) pair does
	 * not exist, else UPDATE the meta_value.
	 *
	 * Note: schema does not enforce UNIQUE(server_id, meta_key) because
	 * WP's postmeta/usermeta convention allows multiple rows per (id, key).
	 * F037 semantic is single-value per key, so we manually SELECT + write
	 * to preserve WP-idiomatic table shape while behaving as UPSERT.
	 *
	 * @param int    $server_id Server PK.
	 * @param string $meta_key  Meta key.
	 * @param string $value     Meta value.
	 * @return bool
	 */
	public static function update_meta( int $server_id, string $meta_key, string $value ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_servers_meta';

		// Check if the row exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_id FROM %i WHERE server_id = %d AND meta_key = %s LIMIT 1',
				$table,
				$server_id,
				$meta_key
			)
		);

		if ( null === $meta_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$table,
				array(
					'server_id'  => $server_id,
					'meta_key'   => $meta_key,
					'meta_value' => $value,
				),
				array( '%d', '%s', '%s' )
			);
			return false !== $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array( 'meta_value' => $value ),
			array( 'meta_id' => (int) $meta_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Delete a single meta row. Returns count deleted (0 or 1).
	 *
	 * @param int    $server_id Server PK.
	 * @param string $meta_key  Meta key.
	 * @return int
	 */
	public static function delete_meta( int $server_id, string $meta_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE server_id = %d AND meta_key = %s',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				$server_id,
				$meta_key
			)
		);

		return (int) $count;
	}

	/**
	 * Return [meta_key => meta_value] for all rows belonging to the server.
	 *
	 * @param int $server_id Server PK.
	 * @return array<string, string>
	 */
	public static function get_all_meta_for_server( int $server_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE server_id = %d',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				$server_id
			)
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (string) $row->meta_key ] = (string) $row->meta_value;
			}
		}
		return $map;
	}

	/**
	 * Return [meta_key => meta_value] for all rows with meta_key starting
	 * with the given prefix. Generic namespace scan (post-F037 the tab
	 * uses a single `_embeds_clients` key so does not exercise this
	 * helper, but it remains available for other per-server meta-key
	 * namespaces).
	 *
	 * @param int    $server_id Server PK.
	 * @param string $prefix    Prefix to LIKE-match (SQL % appended internally).
	 * @return array<string, string>
	 */
	public static function get_meta_by_key_prefix( int $server_id, string $prefix ): array {
		global $wpdb;

		// $wpdb->esc_like escapes the prefix for LIKE; append '%' after.
		$like = $wpdb->esc_like( $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE server_id = %d AND meta_key LIKE %s',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				$server_id,
				$like
			)
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (string) $row->meta_key ] = (string) $row->meta_value;
			}
		}
		return $map;
	}

	/**
	 * Delete every meta row for a server. Called on server deletion per
	 * F037 FR-017.
	 *
	 * @param int $server_id Server PK.
	 * @return int
	 */
	public static function delete_by_server_id( int $server_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE server_id = %d',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				$server_id
			)
		);
		return (int) $count;
	}

	/**
	 * Delete every meta row for a server whose meta_key starts with the
	 * given prefix. Bulk cleanup of a whole meta-key namespace.
	 *
	 * @param int    $server_id Server PK.
	 * @param string $prefix    Prefix to LIKE-match.
	 * @return int
	 */
	public static function delete_by_meta_key_prefix( int $server_id, string $prefix ): int {
		global $wpdb;

		$like = $wpdb->esc_like( $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE server_id = %d AND meta_key LIKE %s',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				$server_id,
				$like
			)
		);
		return (int) $count;
	}
}
