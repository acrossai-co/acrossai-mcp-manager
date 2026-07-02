<?php
/**
 * BerlinDB Query for the OAuthToken module.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthToken
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthToken;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth access-tokens Query subclass.
 *
 * FR-008 / Clarification Q3 / SEC-011-005: active_only PHP-side filter.
 * MUST be implemented as post-query array_filter() on the returned Row set.
 * A BerlinDB Where-operator push-down is explicitly out of scope for Feature 011.
 *
 * WARNING: Do NOT combine active_only with pagination-bearing args (per_page, paged, number)
 * — the PHP filter runs AFTER the SQL LIMIT so the effective result count can be arbitrarily
 * smaller than the pagination boundary. A future paginated active_only caller would need
 * a Where-operator push-down migration (follow-up feature).
 */
class Query extends \BerlinDB\Database\Kern\Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_mcp_oauth_tokens';

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'oat';

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
	protected $item_name = 'oauth_token';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'oauth_tokens';

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
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Visibility override (public → private) enforces the singleton pattern per A2/S6; PHPCS misses the visibility semantics.
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
	 * Query OAuth token rows, honoring the custom active_only filter.
	 *
	 * @param array|string $query  BerlinDB query args plus optional 'active_only' bool.
	 * @param bool         $filter Whether to filter with WHERE clauses (BerlinDB base).
	 * @return array Array of Row instances (empty array on no match).
	 */
	public function query( $query = array(), $filter = true ) {
		$active_only = false;
		if ( is_array( $query ) ) {
			$active_only = ! empty( $query['active_only'] );
			unset( $query['active_only'] );
		}

		$items = parent::query( $query, $filter );

		if ( $active_only && is_array( $items ) ) {
			$now   = current_time( 'mysql', 1 );
			$items = array_values(
				array_filter(
					$items,
					static function ( $row ) use ( $now ) {
						return null === $row->revoked_at && $row->expires_at > $now;
					}
				)
			);
		}

		return $items;
	}
}
