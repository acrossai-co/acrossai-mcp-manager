<?php
/**
 * OAuth access token row value object.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthToken
 */

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthToken;

defined( 'ABSPATH' ) || exit;

/**
 * Typed value object for one access-token row.
 */
class Row {

	/** @var int Primary key. */
	public int $id = 0;
	/** @var string SHA-256 hex of the raw access token. */
	public string $access_token_hash = '';
	/** @var int Server id the token authorises. */
	public int $server_id = 0;
	/** @var int Granting user id. */
	public int $user_id = 0;
	/** @var int CliAuthLog row id of the auth code this token was issued from. */
	public int $issued_from_code_id = 0;
	/** @var string Scope — always `mcp` in this phase. */
	public string $scope = 'mcp';
	/** @var string MySQL datetime issued at. */
	public string $created_at = '';
	/** @var string MySQL datetime — token expiry. */
	public string $expires_at = '';
	/** @var string|null MySQL datetime when revoked (FR-014 anti-replay), or null. */
	public ?string $revoked_at = null;

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
			if ( in_array( $key, array( 'id', 'server_id', 'user_id', 'issued_from_code_id' ), true ) ) {
				$this->{$key} = (int) $value;
			} elseif ( 'revoked_at' === $key ) {
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
