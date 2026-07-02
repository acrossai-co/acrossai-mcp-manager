<?php
/**
 * BerlinDB Schema for the CliAuthLog module.
 *
 * 15 columns per Feature 011 plan §Concrete column decisions CliAuthLog.
 * FR-010 SHA-256 invariant: auth_code_hash char(64). PKCE invariant: code_challenge char(43).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

/**
 * Schema class defining the 15 columns of the acrossai_mcp_cli_auth_logs table.
 *
 * FR-010 cryptographic width invariants:
 * - auth_code_hash: char(64) — SHA-256 hex length (DO NOT narrow).
 * - code_challenge: char(43) — PKCE S256 exact challenge length (DO NOT narrow).
 */
class Schema extends \BerlinDB\Database\Kern\Schema {

	/**
	 * Array of column definitions.
	 *
	 * @var array
	 */
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),
		array(
			'name'     => 'server_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
			'sortable' => true,
		),
		array(
			'name'    => 'server_slug',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),
		array(
			'name'     => 'user_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'status',
			'type'     => 'varchar',
			'length'   => '32',
			'default'  => 'pending',
			'sortable' => true,
		),
		array(
			'name'    => 'failure_code',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// FR-010 SHA-256 invariant — DO NOT narrow.
		array(
			'name'   => 'auth_code_hash',
			'type'   => 'char',
			'length' => '64',
		),
		array(
			'name'    => 'app_password_uuid',
			'type'    => 'varchar',
			'length'  => '36',
			'default' => '',
		),
		array(
			'name'    => 'redirect_uri',
			'type'    => 'varchar',
			'length'  => '500',
			'default' => '',
		),
		// FR-010 PKCE invariant — DO NOT narrow.
		array(
			'name'    => 'code_challenge',
			'type'    => 'char',
			'length'  => '43',
			'default' => '',
		),
		array(
			'name'    => 'code_challenge_method',
			'type'    => 'varchar',
			'length'  => '16',
			'default' => 'S256',
		),
		array(
			'name'    => 'scope',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => 'mcp',
		),
		array(
			'name'       => 'approved_at',
			'type'       => 'datetime',
			'allow_null' => true,
			'default'    => null,
		),
		array(
			'name'       => 'completed_at',
			'type'       => 'datetime',
			'allow_null' => true,
			'default'    => null,
		),
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
	 * @var array
	 */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'auth_code_hash',
			'type'    => 'unique',
			'columns' => array( 'auth_code_hash' ),
		),
		array(
			'name'    => 'server_created',
			'type'    => 'key',
			'columns' => array( 'server_id', 'created_at' ),
		),
		array(
			'name'    => 'server_status_created',
			'type'    => 'key',
			'columns' => array( 'server_id', 'status', 'created_at' ),
		),
	);
}
