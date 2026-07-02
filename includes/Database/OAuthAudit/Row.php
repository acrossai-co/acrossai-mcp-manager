<?php
/**
 * BerlinDB Row for a single OAuthAudit record.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAudit
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAudit;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the OAuthAudit module's table.
 *
 * @property array $properties
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */         public $id                = 0;
	/** @var string */      public $event_type        = '';
	/** @var int */         public $server_id         = 0;
	/** @var int */         public $user_id           = 0;
	/** @var string */      public $client_id         = '';
	/** @var string */      public $token_hash_prefix = '';
	/** @var string */      public $endpoint          = '';
	/** @var string|null */ public $details_json      = null;
	/** @var string */      public $created_at        = '';

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
			'id'                => $this->id,
			'event_type'        => $this->event_type,
			'server_id'         => $this->server_id,
			'user_id'           => $this->user_id,
			'client_id'         => $this->client_id,
			'token_hash_prefix' => $this->token_hash_prefix,
			'endpoint'          => $this->endpoint,
			'details_json'      => $this->details_json,
			'created_at'        => $this->created_at,
		);
	}
}
