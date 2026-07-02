<?php
/**
 * BerlinDB Schema for the OAuthAudit module.
 *
 * 9 columns per Feature 011 plan §Concrete column decisions OAuthAudit.
 * token_hash_prefix char(8) is a debug/search field, not a credential.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAudit
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAudit;

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
		array(
			'name'    => 'event_type',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		array(
			'name'     => 'server_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'     => 'user_id',
			'type'     => 'bigint',
			'length'   => '20',
			'unsigned' => true,
			'default'  => 0,
		),
		array(
			'name'    => 'client_id',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),
		array(
			'name'    => 'token_hash_prefix',
			'type'    => 'char',
			'length'  => '8',
			'default' => '',
		),
		array(
			'name'    => 'endpoint',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),
		array(
			'name'       => 'details_json',
			'type'       => 'text',
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

	/** @var array<int, array<string, mixed>> */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'event_created',
			'type'    => 'key',
			'columns' => array( 'event_type', 'created_at' ),
		),
		array(
			'name'    => 'server_created',
			'type'    => 'key',
			'columns' => array( 'server_id', 'created_at' ),
		),
		array(
			'name'    => 'user_created',
			'type'    => 'key',
			'columns' => array( 'user_id', 'created_at' ),
		),
	);
}
