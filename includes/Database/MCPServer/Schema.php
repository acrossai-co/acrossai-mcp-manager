<?php
/**
 * BerlinDB Schema for the MCPServer module.
 *
 * 13 columns per Feature 011 plan §Concrete column decisions MCPServer.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Schema class defining all 13 columns of the acrossai_mcp_servers table.
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

		// Primary key — 'primary' flag omitted; PRIMARY KEY DDL comes from $indexes.
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),

		// Server display name.
		array(
			'name'   => 'server_name',
			'type'   => 'varchar',
			'length' => '255',
		),

		// Server slug (route path segment). Indexed for lookup.
		array(
			'name'       => 'server_slug',
			'type'       => 'varchar',
			'length'     => '255',
			'default'    => '',
			'sortable'   => true,
			'searchable' => true,
		),

		// Human-readable description.
		array(
			'name'    => 'description',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		),

		// Enabled toggle (0/1).
		array(
			'name'    => 'is_enabled',
			'type'    => 'tinyint',
			'length'  => '1',
			'default' => 0,
		),

		// Origin: 'plugin' (self-managed) or third-party plugin slug.
		array(
			'name'    => 'registered_from',
			'type'    => 'varchar',
			'length'  => '50',
			'default' => 'plugin',
		),

		// REST route namespace segment.
		array(
			'name'    => 'server_route_namespace',
			'type'    => 'varchar',
			'length'  => '100',
			'default' => 'mcp',
		),

		// REST route path segment.
		array(
			'name'    => 'server_route',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),

		// Server version string.
		array(
			'name'    => 'server_version',
			'type'    => 'varchar',
			'length'  => '50',
			'default' => 'v1.0.0',
		),

		// Claude connector OAuth client ID.
		array(
			'name'    => 'claude_connector_client_id',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),

		// Claude connector OAuth client secret.
		array(
			'name'    => 'claude_connector_client_secret',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),

		// Claude connector OAuth redirect URI.
		array(
			'name'    => 'claude_connector_redirect_uri',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		),

		// Audit timestamp — no explicit default; BerlinDB uses '0000-00-00 00:00:00'
		// for datetime columns. 'created' flag handles auto-timestamping at the
		// application layer (CURRENT_TIMESTAMP quoted by BerlinDB is invalid DDL).
		array(
			'name'       => 'created_at',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
		),
	);

	/**
	 * Array of index definitions.
	 *
	 * BerlinDB v3 requires the PRIMARY KEY to be declared as an explicit Index
	 * entry — the 'primary' column flag is query-layer only, not DDL.
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
			'name'    => 'server_slug',
			'type'    => 'key',
			'columns' => array( 'server_slug' ),
		),
	);
}
