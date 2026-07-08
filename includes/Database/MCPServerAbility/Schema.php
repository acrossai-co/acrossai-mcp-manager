<?php
/**
 * BerlinDB Schema for the MCPServerAbility module.
 *
 * Six columns per Feature 017 §Data Model. `ability_slug varchar(191)` is
 * the deliberate InnoDB utf8mb4 767-byte key-length ceiling for the
 * `UNIQUE(server_id, ability_slug)` composite index.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerAbility
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Schema class defining the six columns of the acrossai_mcp_server_abilities table.
 *
 * @since 0.1.0
 */
class Schema extends \BerlinDB\Database\Kern\Schema {

	/**
	 * Array of column definitions.
	 *
	 * @var array
	 */
	public $columns = array(

		// Primary key.
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),

		// Foreign reference to wp_acrossai_mcp_servers.id (no physical FK).
		array(
			'name'       => 'server_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'searchable' => true,
		),

		// Ability name (\WP_Ability::get_name()). 191 chars fits UNIQUE
		// composite key under InnoDB utf8mb4 767-byte key limit.
		array(
			'name'       => 'ability_slug',
			'type'       => 'varchar',
			'length'     => '191',
			'default'    => '',
			'searchable' => true,
		),

		// 1 = expose, 0 = hide.
		// $wpdb returns TINYINT as string — consumers MUST cast (bug B18).
		array(
			'name'    => 'is_exposed',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 0,
		),

		// Set by BerlinDB `created` timestamping on INSERT.
		array(
			'name'       => 'created_at',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
		),

		// Set by BerlinDB `modified` timestamping on every UPDATE.
		// NB: the flag is `modified`, NOT `date_updated` — an unrecognized
		// name silently becomes a dynamic property and trips PHP 8.2
		// "Creation of dynamic property" deprecations.
		array(
			'name'     => 'updated_at',
			'type'     => 'datetime',
			'modified' => true,
		),
	);

	/**
	 * Array of index definitions.
	 *
	 * BerlinDB v3 requires the PRIMARY KEY to be declared as an explicit Index
	 * entry — the `primary` column flag is query-layer only, not DDL.
	 *
	 * @var array
	 */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'server_ability',
			'type'    => 'unique',
			'columns' => array( 'server_id', 'ability_slug' ),
		),
		array(
			'name'    => 'server_id',
			'type'    => 'key',
			'columns' => array( 'server_id' ),
		),
	);
}
