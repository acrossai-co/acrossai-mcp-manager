<?php
/**
 * BerlinDB Query for the MCPServer module.
 *
 * Self-contained BerlinDB Query subclass owning all DB interactions with the
 * `{prefix}acrossai_mcp_servers` table. Provides the standard BerlinDB public
 * API (query, add_item, update_item, delete_item) via the base class — no
 * bespoke overrides required for this module.
 *
 * Architecture contract:
 *   $table_name   = 'acrossai_mcp_servers'
 *   $table_schema = Schema::class
 *   $item_shape   = Row::class
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use BerlinDB\Database\Kern\Query as BerlinDB_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * BerlinDB Query subclass for the MCP servers table.
 *
 * @since 0.1.0
 */
class Query extends BerlinDB_Query {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_mcp_servers';

	/**
	 * SQL alias used in JOIN expressions.
	 *
	 * @var string
	 */
	protected $table_alias = 'mcps';

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
	protected $item_name = 'mcp_server';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'mcp_servers';

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

	// No bespoke methods — BerlinDB base class provides the full public API.
}
