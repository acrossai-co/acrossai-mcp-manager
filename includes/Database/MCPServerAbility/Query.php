<?php
/**
 * BerlinDB Query for the MCPServerAbility module.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerAbility
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility;

use BerlinDB\Database\Kern\Query as BerlinDB_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * BerlinDB Query subclass for the MCP server abilities table.
 *
 * Adds one bespoke helper `upsert()` on top of the inherited public API.
 *
 * @since 0.1.0
 */
class Query extends BerlinDB_Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_mcp_server_abilities';

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'mcpsa';

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
	protected $item_name = 'mcp_server_ability';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'mcp_server_abilities';

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

	/**
	 * Insert-or-update a per-(server, ability) exposure row.
	 *
	 * If a row already exists for the given `(server_id, ability_slug)` pair,
	 * update `is_exposed`. Otherwise insert a new row.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $server_id    The MCP server id.
	 * @param string $ability_slug The ability name (\WP_Ability::get_name()).
	 * @param bool   $is_exposed   Whether the ability is exposed on this server.
	 * @return bool True on success, false on failure.
	 */
	public function upsert( int $server_id, string $ability_slug, bool $is_exposed ): bool {
		$existing = $this->query(
			array(
				'server_id'    => $server_id,
				'ability_slug' => $ability_slug,
				'number'       => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			return (bool) $this->update_item(
				$existing[0]->id,
				array( 'is_exposed' => (int) $is_exposed )
			);
		}

		return (bool) $this->add_item(
			array(
				'server_id'    => $server_id,
				'ability_slug' => $ability_slug,
				'is_exposed'   => (int) $is_exposed,
			)
		);
	}
}
