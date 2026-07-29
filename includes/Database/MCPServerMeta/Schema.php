<?php
/**
 * BerlinDB Schema for the MCPServerMeta module.
 *
 * Standard WordPress meta-table shape (mirrors wp_postmeta / wp_usermeta),
 * but keyed by `server_id` (the plugin's own MCP server PK) instead of
 * `post_id`. Backs any per-server key-value setting — F037 uses it for
 * `_embeds_enabled` (master toggle) + `_embeds_clients` (JSON blob:
 * { npm: 0|1, mcp-client: [slugs], connectors: [slugs] }).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerMeta
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta;

defined( 'ABSPATH' ) || exit;

class Schema extends \BerlinDB\Database\Kern\Schema {

	/** @var array */
	public $columns = array(
		array(
			'name'     => 'meta_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),
		array(
			'name'       => 'server_id',
			'type'       => 'bigint',
			'length'     => '20',
			'unsigned'   => true,
			'default'    => 0,
			'allow_null' => false,
		),
		array(
			'name'       => 'meta_key',
			'type'       => 'varchar',
			'length'     => '255',
			'allow_null' => true,
		),
		array(
			'name'       => 'meta_value',
			'type'       => 'longtext',
			'allow_null' => true,
		),
	);

	/**
	 * Indexes.
	 *
	 * `server_id_meta_key` (key, not unique) — mirrors wp_postmeta's
	 * `post_id` index shape; accelerates per-server key lookups.
	 * meta_key partial-length (191) to fit within MySQL's index-key-length
	 * limit under utf8mb4.
	 *
	 * @var array
	 */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'meta_id' ),
		),
		array(
			'name'    => 'server_id',
			'type'    => 'key',
			'columns' => array( 'server_id' ),
		),
		array(
			'name'    => 'meta_key',
			'type'    => 'key',
			'columns' => array( 'meta_key' ),
			'length'  => array( 'meta_key' => 191 ),
		),
	);
}
