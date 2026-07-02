<?php
/**
 * BerlinDB Query for the CliAuthLog module.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

/**
 * BerlinDB Query subclass for the `{prefix}acrossai_mcp_cli_auth_logs` table.
 *
 * Owns all DB interactions for the CLI auth log / OAuth code table. Extends the
 * BerlinDB base Query which provides query(), add_item(), update_item(), and
 * delete_item(). This subclass adds two bespoke methods that MUST be preserved:
 *
 *   - redeem_atomic()             — FR-006 / SEC-001 atomic one-shot redemption
 *   - delete_expired_oauth_codes() — FR-019c retention cron bulk delete
 *
 * @since 0.1.0
 */
class Query extends \BerlinDB\Database\Kern\Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_mcp_cli_auth_logs';

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'cal';

	/**
	 * Schema class for this query.
	 *
	 * @var string
	 */
	protected $table_schema = Schema::class;

	/**
	 * Singular item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name = 'cli_auth_log';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'cli_auth_logs';

	/**
	 * Row class for query results.
	 *
	 * @var string
	 */
	protected $item_shape = Row::class;

	/**
	 * Singleton instance.
	 *
	 * @var Query|null
	 */
	protected static $instance = null;

	/**
	 * Private constructor — enforces singleton pattern (A2/S6).
	 *
	 * @since 0.1.0
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Visibility override (public → private) enforces the singleton pattern per A2/S6; PHPCS misses the visibility semantics.
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @since  0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Bespoke methods (must be preserved — SEC-001 / FR-019c)
	// -------------------------------------------------------------------------

	// FR-006 / SEC-001 / BUGS.md B10: atomic-CAS one-shot redemption.
	// The WHERE id = %d AND completed_at IS NULL predicate is non-negotiable — it is the sole
	// defense against concurrent duplicate redemption. The 1 === (int) $wpdb->rows_affected
	// return contract is verified by AtomicCasTest.php.
	/**
	 * Atomically mark an auth-code row as completed IF it is not already completed.
	 *
	 * @since  0.1.0
	 * @param  int    $id  Auth-log row primary key.
	 * @param  string $now Current timestamp (mysql format, GMT).
	 * @return bool True iff exactly this call redeemed the row (rows_affected = 1).
	 */
	public function redeem_atomic( int $id, string $now ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET completed_at = %s, status = %s WHERE id = %d AND completed_at IS NULL',
				$wpdb->prefix . 'acrossai_mcp_cli_auth_logs',
				$now,
				'completed',
				$id
			)
		);

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Bulk delete expired OAuth auth-code rows for the FR-019c retention cron.
	 *
	 * @since  0.1.0
	 * @param  string $cutoff Datetime string (mysql format, GMT). Rows created before this are deleted.
	 * @return int Number of rows deleted.
	 */
	public function delete_expired_oauth_codes( string $cutoff ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s AND completed_at IS NULL',
				$wpdb->prefix . 'acrossai_mcp_cli_auth_logs',
				$cutoff
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
