<?php
/**
 * BerlinDB Row for a single OAuthToken record.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthToken
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthToken;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the OAuthToken module's table.
 *
 * @property array $properties
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */         public $id                  = 0;
	/** @var string */      public $access_token_hash   = '';
	/** @var int */         public $server_id           = 0;
	/** @var int */         public $user_id             = 0;
	/** @var int */         public $issued_from_code_id = 0;
	/** @var string */      public $scope               = 'mcp';
	/** @var string */      public $created_at          = '';
	/** @var string */      public $expires_at          = '';
	/** @var string|null */ public $revoked_at          = null;

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
			'id'                  => $this->id,
			'access_token_hash'   => $this->access_token_hash,
			'server_id'           => $this->server_id,
			'user_id'             => $this->user_id,
			'issued_from_code_id' => $this->issued_from_code_id,
			'scope'               => $this->scope,
			'created_at'          => $this->created_at,
			'expires_at'          => $this->expires_at,
			'revoked_at'          => $this->revoked_at,
		);
	}
}
