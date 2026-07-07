<?php
/**
 * BerlinDB Row for a single MCPServer record.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the MCPServer module's table.
 *
 * @property array $properties
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $id                     = 0;
	/** @var string */ public $server_name            = '';
	/** @var string */ public $server_slug            = '';
	/** @var string */ public $description            = '';
	/** @var int */    public $is_enabled             = 0;
	/** @var string */ public $registered_from        = 'plugin';
	/** @var string */ public $server_route_namespace = 'mcp';
	/** @var string */ public $server_route           = '';
	/** @var string */ public $server_version         = 'v1.0.0';
	/** @var string */ public $created_at             = '';

	/**
	 * Constructor — casts primitive types.
	 *
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id = (int) $this->id;
	}

	/**
	 * Return this row as an associative array (external consumers depend on this).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                     => $this->id,
			'server_name'            => $this->server_name,
			'server_slug'            => $this->server_slug,
			'description'            => $this->description,
			'is_enabled'             => $this->is_enabled,
			'registered_from'        => $this->registered_from,
			'server_route_namespace' => $this->server_route_namespace,
			'server_route'           => $this->server_route,
			'server_version'         => $this->server_version,
			'created_at'             => $this->created_at,
		);
	}
}
