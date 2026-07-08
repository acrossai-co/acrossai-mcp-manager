<?php
/**
 * BerlinDB Row for a single MCPServerTool record.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerTool
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerTool;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the MCPServerTool module's table.
 *
 * Presence-based storage — the row's existence IS the "added" flag. No
 * `is_exposed` column. No B18 tinyint-as-string concern for this table.
 *
 * @since 0.1.0
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $id           = 0;
	/** @var int */    public $server_id    = 0;
	/** @var string */ public $ability_slug = '';
	/** @var string */ public $created_at   = '';
	/** @var string */ public $updated_at   = '';

	/**
	 * Constructor — casts primitive types.
	 *
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id        = (int) $this->id;
		$this->server_id = (int) $this->server_id;
	}

	/**
	 * Return this row as an associative array (for tests and future consumers).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'server_id'    => $this->server_id,
			'ability_slug' => $this->ability_slug,
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
		);
	}
}
