<?php
/**
 * BerlinDB Query for the OAuthClients module (Feature 021).
 *
 * Bespoke methods that MUST be preserved:
 *   - find_by_id()         — client_id lookup for authorize/token flows
 *   - find_by_fingerprint() — DCR idempotent dedup (FR-022)
 *   - find_by_id_like()    — AIConnectorsTab existence check (Q2)
 *   - revoke_by_client_id() — client-scoped bulk revoke (fires via Repository)
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthClients;

defined( 'ABSPATH' ) || exit;

class Query extends \BerlinDB\Database\Kern\Query {

	/** @var string */
	protected $table_name = 'acrossai_mcp_oauth_clients';

	/** @var string */
	protected $table_alias = 'oac';

	/** @var string */
	protected $table_schema = Schema::class;

	/** @var string */
	protected $item_name = 'oauth_client';

	/** @var string */
	protected $item_name_plural = 'oauth_clients';

	/** @var string */
	protected $item_shape = Row::class;

	/** @var Query|null */
	protected static $instance = null;

	/**
	 * Private constructor enforces singleton (A2/S6).
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
	 * Look up a single client by its `client_id` value.
	 *
	 * @param string $client_id Opaque client identifier.
	 * @return Row|null
	 */
	public function find_by_id( string $client_id ): ?Row {
		if ( '' === $client_id ) {
			return null;
		}

		$rows = $this->query(
			array(
				'client_id' => $client_id,
				'number'    => 1,
			)
		);

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * FR-022 — DCR idempotent dedup lookup by canonical metadata fingerprint.
	 *
	 * @param string $fingerprint SHA-256 hex.
	 * @return Row|null
	 */
	public function find_by_fingerprint( string $fingerprint ): ?Row {
		if ( '' === $fingerprint ) {
			return null;
		}

		$rows = $this->query(
			array(
				'metadata_fingerprint' => $fingerprint,
				'number'               => 1,
			)
		);

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * F024 — List every client whose client_id is prefixed for this
	 * (server, connector) pair — includes admin-generated clients only.
	 * DCR-registered clients (connector_slug = '') are matched separately
	 * via AbstractConnectorProfile::matches_dcr_client in the caller.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return array<int, Row>
	 */
	public function find_admin_clients_for_server_connector( int $server_id, string $connector_slug ): array {
		global $wpdb;

		if ( $server_id <= 0 || '' === $connector_slug ) {
			return array();
		}

		$prefix = 'server-' . $server_id . '-' . $connector_slug . '-';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connector_slug = %s AND client_id LIKE %s ORDER BY id DESC',
				$wpdb->prefix . $this->table_name,
				$connector_slug,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( $r ): Row => new Row( $r ), $rows );
	}

	/**
	 * F024 — List every DCR-registered client (connector_slug empty).
	 * Caller filters by profile via `matches_dcr_client`.
	 *
	 * @return array<int, Row>
	 */
	public function find_dcr_clients(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connector_slug = %s ORDER BY id DESC',
				$wpdb->prefix . $this->table_name,
				''
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( $r ): Row => new Row( $r ), $rows );
	}

	/**
	 * F024 — Delete a client row by id. Caller is responsible for having
	 * revoked its tokens first.
	 *
	 * @param int $client_pk_id Client row primary key id.
	 * @return bool True iff a row was deleted.
	 */
	public function delete_by_id( int $client_pk_id ): bool {
		global $wpdb;

		if ( $client_pk_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$wpdb->prefix . $this->table_name,
			array( 'id' => $client_pk_id ),
			array( '%d' )
		);

		return 1 === (int) $result;
	}

	/**
	 * Q2 — Look up an admin-generated client by structured prefix.
	 *
	 * Used by AIConnectorsTab to check whether a `(server_id, connector_slug)`
	 * pair already has an issued client. Prefix format is
	 * `server-{server_id}-{connector_slug}-`; the KEY(connector_slug) index
	 * makes this O(k) where k = number of clients for the connector.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return Row|null
	 */
	public function find_admin_client( int $server_id, string $connector_slug ): ?Row {
		global $wpdb;

		if ( $server_id <= 0 || '' === $connector_slug ) {
			return null;
		}

		$prefix = 'server-' . $server_id . '-' . $connector_slug . '-';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connector_slug = %s AND client_id LIKE %s ORDER BY id DESC LIMIT 1',
				$wpdb->prefix . $this->table_name,
				$connector_slug,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return $row ? new Row( $row ) : null;
	}
}
