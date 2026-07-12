<?php
/**
 * BerlinDB Schema for the OAuthClients module (Feature 021).
 *
 * 10 columns. FR-040 SHA-256 invariants: client_secret_hash char(64) NULL,
 * metadata_fingerprint char(64). Q2 admin `client_id` format
 * `server-{id}-{slug}-{rand8}` — indexed by connector_slug for O(1) lookup.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthClients;

defined( 'ABSPATH' ) || exit;

class Schema extends \BerlinDB\Database\Kern\Schema {

	/**
	 * Column definitions. Order matches data-model.md §Entity 1.
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
			'name'   => 'client_id',
			'type'   => 'varchar',
			'length' => '64',
		),
		// FR-040 SHA-256 invariant — DO NOT narrow. NULLABLE for public clients (token_endpoint_auth_method='none').
		array(
			'name'       => 'client_secret_hash',
			'type'       => 'char',
			'length'     => '64',
			'allow_null' => true,
			'default'    => null,
		),
		array(
			'name'    => 'client_name',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => '',
		),
		array(
			'name'    => 'redirect_uris',
			'type'    => 'text',
			'default' => '',
		),
		array(
			'name'    => 'grant_types',
			'type'    => 'varchar',
			'length'  => '255',
			'default' => 'authorization_code refresh_token',
		),
		array(
			'name'    => 'token_endpoint_auth_method',
			'type'    => 'varchar',
			'length'  => '32',
			'default' => 'none',
		),
		array(
			'name'    => 'connector_slug',
			'type'    => 'varchar',
			'length'  => '64',
			'default' => '',
		),
		// FR-040 SHA-256 invariant — DO NOT narrow.
		array(
			'name'    => 'metadata_fingerprint',
			'type'    => 'char',
			'length'  => '64',
			'default' => '',
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
	 * Index definitions.
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
			'name'    => 'client_id',
			'type'    => 'unique',
			'columns' => array( 'client_id' ),
		),
		array(
			'name'    => 'connector_slug',
			'type'    => 'key',
			'columns' => array( 'connector_slug' ),
		),
		array(
			'name'    => 'metadata_fingerprint',
			'type'    => 'key',
			'columns' => array( 'metadata_fingerprint' ),
		),
	);
}
