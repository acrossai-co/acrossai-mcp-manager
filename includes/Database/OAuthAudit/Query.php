<?php
/**
 * BerlinDB Query for the OAuthAudit module.
 *
 * Self-contained BerlinDB Query subclass owning all DB interactions with the
 * acrossai_mcp_oauth_audit table. Append-only at the application level;
 * update_item/delete_item are inherited from the BerlinDB base for retention
 * cleanup only.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAudit
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAudit;

use BerlinDB\Database\Kern\Query as BerlinDB_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * BerlinDB Query subclass for the OAuth audit log table.
 *
 * @since 0.1.0
 */
class Query extends BerlinDB_Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_mcp_oauth_audit';

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'oaa';

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
	protected $item_name = 'oauth_audit_event';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'oauth_audit_events';

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
	// Bespoke retention helper (FR-007 / FR-019b cleanup cron)
	// -------------------------------------------------------------------------

	/**
	 * Delete OAuth audit rows older than the given cutoff datetime.
	 *
	 * Used by the FR-019b retention cron to purge stale audit events.
	 * Uses $wpdb->prepare() with %i for the (safely-derived) table identifier
	 * and %s for the datetime bind (S4 compliant).
	 *
	 * @since  0.1.0
	 * @param  string $datetime Cutoff (MySQL datetime, GMT). Rows with created_at < $datetime are deleted.
	 * @return int Number of rows deleted.
	 */
	public function delete_older_than( string $datetime ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				$wpdb->prefix . 'acrossai_mcp_oauth_audit',
				$datetime
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
