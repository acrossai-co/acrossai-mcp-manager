<?php
/**
 * BerlinDB Schema for the OAuthToken module.
 *
 * 9 columns per Feature 011 plan §Concrete column decisions OAuthToken.
 * FR-010 SHA-256 invariant: access_token_hash char(64).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthToken
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthToken;

defined( 'ABSPATH' ) || exit;

class Schema extends \BerlinDB\Database\Kern\Schema {

	/** @var array<int, array<string, mixed>> */
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),
		// FR-010 SHA-256 invariant — DO NOT narrow.
		array(
			'name'   => 'access_token_hash',
			'type'   => 'char',
			'length' => '64',
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
			'name'     => 'user_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'issued_from_code_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'    => 'scope',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => 'mcp',
		),
		array(
			'name'       => 'created_at',
			'type'       => 'datetime',
			'created'    => true,
			'date_query' => true,
			'sortable'   => true,
		),
		array(
			'name' => 'expires_at',
			'type' => 'datetime',
		),
		array(
			'name'       => 'revoked_at',
			'type'       => 'datetime',
			'allow_null' => true,
			'default'    => null,
		),
	);

	/** @var array<int, array<string, mixed>> */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'access_token_hash',
			'type'    => 'unique',
			'columns' => array( 'access_token_hash' ),
		),
		array(
			'name'    => 'server_expires',
			'type'    => 'key',
			'columns' => array( 'server_id', 'expires_at' ),
		),
		array(
			'name'    => 'user_created',
			'type'    => 'key',
			'columns' => array( 'user_id', 'created_at' ),
		),
		array(
			'name'    => 'issued_from_code',
			'type'    => 'key',
			'columns' => array( 'issued_from_code_id' ),
		),
	);
}
