<?php
/**
 * CLI auth log row value object.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight typed view of one row from `{prefix}acrossai_mcp_cli_auth_logs`.
 */
class Row {

	public int $id              = 0;
	public int $server_id       = 0;
	public string $server_slug  = '';
	public int $user_id         = 0;
	public string $status       = '';
	public string $failure_code = '';
	public string $auth_code_hash    = '';
	public string $app_password_uuid = '';
	public ?string $approved_at  = null;
	public ?string $completed_at = null;
	public string $created_at    = '';

	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				continue;
			}
			if ( in_array( $key, array( 'id', 'server_id', 'user_id' ), true ) ) {
				$this->{$key} = (int) $value;
			} elseif ( in_array( $key, array( 'approved_at', 'completed_at' ), true ) ) {
				$this->{$key} = ( null === $value ) ? null : (string) $value;
			} else {
				$this->{$key} = (string) $value;
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'server_id'         => $this->server_id,
			'server_slug'       => $this->server_slug,
			'user_id'           => $this->user_id,
			'status'            => $this->status,
			'failure_code'      => $this->failure_code,
			'auth_code_hash'    => $this->auth_code_hash,
			'app_password_uuid' => $this->app_password_uuid,
			'approved_at'       => $this->approved_at,
			'completed_at'      => $this->completed_at,
			'created_at'        => $this->created_at,
		);
	}
}
