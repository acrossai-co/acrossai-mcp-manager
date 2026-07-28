<?php
/**
 * BerlinDB Row for a single MCPServerMeta record.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerMeta
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta;

defined( 'ABSPATH' ) || exit;

class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $meta_id    = 0;
	/** @var int */    public $server_id  = 0;
	/** @var string */ public $meta_key   = '';
	/** @var string */ public $meta_value = '';

	/**
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->meta_id   = (int) $this->meta_id;
		$this->server_id = (int) $this->server_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'meta_id'    => $this->meta_id,
			'server_id'  => $this->server_id,
			'meta_key'   => $this->meta_key,
			'meta_value' => $this->meta_value,
		);
	}
}
