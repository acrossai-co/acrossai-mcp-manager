<?php
/**
 * OAuth audit event row value object.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAudit
 */

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAudit;

defined( 'ABSPATH' ) || exit;

/**
 * Typed value object for one audit-event row.
 */
class Row {

	/** @var int Primary key. */
	public int $id = 0;
	/** @var string Canonical event_type slug from AuditLog::EVENT_*. */
	public string $event_type = '';
	/** @var int Server id (0 = no server context). */
	public int $server_id = 0;
	/** @var int User id (0 = pre-user-resolution event). */
	public int $user_id = 0;
	/** @var string Raw client_id from request (useful when unresolved). */
	public string $client_id = '';
	/** @var string First 8 hex of token SHA-256 (log correlation, not the full hash). */
	public string $token_hash_prefix = '';
	/** @var string Request path for Bearer-related events. */
	public string $endpoint = '';
	/** @var string|null JSON-encoded structured detail; NEVER raw secrets. */
	public ?string $details_json = null;
	/** @var string MySQL datetime. */
	public string $created_at = '';

	/**
	 * Hydrate from a wpdb row array (string-keyed).
	 *
	 * @param array<string, mixed> $data
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				continue;
			}
			if ( in_array( $key, array( 'id', 'server_id', 'user_id' ), true ) ) {
				$this->{$key} = (int) $value;
			} elseif ( 'details_json' === $key ) {
				$this->{$key} = ( null === $value ) ? null : (string) $value;
			} else {
				$this->{$key} = (string) $value;
			}
		}
	}

	/**
	 * Convenience: convert back to associative array.
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
