<?php
/**
 * BerlinDB Query for the OAuthTokens module (Feature 021).
 *
 * Bespoke methods that MUST be preserved:
 *   - find_by_hash()          — TokenValidator bearer lookup
 *   - revoke_by_hash()        — single-token revoke
 *   - revoke_by_user_id()     — FR-042 user-deletion cascade
 *   - revoke_by_client_id()   — FR-036 admin regenerate
 *   - revoke_by_family_id()   — SEC-021-001 RFC 9700 family revocation
 *   - delete_expired()        — daily cron cleanup
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthTokens
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthTokens;

defined( 'ABSPATH' ) || exit;

class Query extends \BerlinDB\Database\Kern\Query {

	/** @var string */
	protected $table_name = 'acrossai_mcp_oauth_tokens';

	/** @var string */
	protected $table_alias = 'oat';

	/** @var string */
	protected $table_schema = Schema::class;

	/** @var string */
	protected $item_name = 'oauth_token';

	/** @var string */
	protected $item_name_plural = 'oauth_tokens';

	/** @var string */
	protected $item_shape = Row::class;

	/** @var Query|null */
	protected static $instance = null;

	/**
	 * Private constructor enforces singleton pattern (A2/S6).
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
	 * TokenValidator lookup by SHA-256 hex hash.
	 *
	 * @param string $token_hash SHA-256 hex, char(64).
	 * @return Row|null
	 */
	public function find_by_hash( string $token_hash ): ?Row {
		if ( '' === $token_hash ) {
			return null;
		}

		$rows = $this->query(
			array(
				'token_hash' => $token_hash,
				'number'     => 1,
			)
		);

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * Atomically flip revoked 0 → 1 for a single token by hash. Returns true
	 * iff the row transitioned this call (rows_affected === 1).
	 *
	 * @param string $token_hash SHA-256 hex.
	 * @return bool
	 */
	public function revoke_by_hash( string $token_hash ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE token_hash = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$token_hash
			)
		);

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Bulk revoke every non-revoked token for a user (FR-042 cascade).
	 *
	 * Returns caller-usable list of token ids so `token_revoked` can fire per
	 * row (F020 pattern — observers get per-row events, not silent bulk).
	 *
	 * @param int $user_id WordPress user id.
	 * @return array<int, int> List of token row ids that were revoked this call.
	 */
	public function revoke_by_user_id( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$user_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$user_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Bulk revoke every non-revoked token for a client (FR-036 admin regenerate).
	 *
	 * @param string $client_id Client identifier.
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_client_id( string $client_id ): array {
		global $wpdb;

		if ( '' === $client_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * SEC-021-001 — Bulk revoke every non-revoked token in a family.
	 *
	 * Called by TokenController on refresh-reuse detection per RFC 9700 §2.2.2.
	 * Returns the list of revoked row ids so `token_revoked` can fire per row
	 * with the caller-supplied reason (typically 'family_reuse_detected').
	 *
	 * @param string $family_id UUIDv4 (char(36)).
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_family_id( string $family_id ): array {
		global $wpdb;

		// Guard against '' — an empty family_id would revoke every legacy row.
		if ( '' === $family_id || 36 !== strlen( $family_id ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE token_family_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$family_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE token_family_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$family_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Count non-revoked tokens for a client (F024 Connections panel).
	 *
	 * @param string $client_id Client identifier.
	 * @return int
	 */
	public function count_active_by_client_id( string $client_id ): int {
		global $wpdb;

		if ( '' === $client_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id
			)
		);
	}

	/**
	 * List distinct user_ids with a non-revoked token for a client
	 * (F024 Connections panel — "Users" column).
	 *
	 * @param string $client_id Client identifier.
	 * @return array<int, int>
	 */
	public function get_active_user_ids_by_client_id( string $client_id ): array {
		global $wpdb;

		if ( '' === $client_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT user_id FROM %i WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Daily cron cleanup: delete rows that are (expired AND revoked) OR older
	 * than 30 days past their expiry (defensive: covers case where an admin
	 * unset revoked=1 to reactivate a token — the row still ages out).
	 *
	 * @param string $now Current GMT time in mysql format.
	 * @return int Rows deleted.
	 */
	public function delete_expired( string $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE ( expires_at < %s AND revoked = 1 ) OR expires_at < ( %s - INTERVAL 30 DAY )',
				$wpdb->prefix . $this->table_name,
				$now,
				$now
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
