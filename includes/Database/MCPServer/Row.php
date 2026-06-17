<?php
/**
 * MCP Server row value object.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight typed view of one row from `{prefix}acrossai_mcp_servers`.
 *
 * Instances are constructed by Query; consumers read public properties.
 */
class Row {

	public int $id              = 0;
	public string $server_name  = '';
	public string $server_slug  = '';
	public string $description  = '';
	public int $is_enabled      = 0;
	public string $registered_from        = 'plugin';
	public string $server_route_namespace = 'mcp';
	public string $server_route   = '';
	public string $server_version = 'v1.0.0';
	public string $claude_connector_client_id     = '';
	public string $claude_connector_client_secret = '';
	public string $claude_connector_redirect_uri  = '';
	public string $created_at = '';

	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				continue;
			}
			if ( 'id' === $key || 'is_enabled' === $key ) {
				$this->{$key} = (int) $value;
			} else {
				$this->{$key} = (string) $value;
			}
		}
	}

	/**
	 * Convenience: convert back to associative array for legacy callers.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                             => $this->id,
			'server_name'                    => $this->server_name,
			'server_slug'                    => $this->server_slug,
			'description'                    => $this->description,
			'is_enabled'                     => $this->is_enabled,
			'registered_from'                => $this->registered_from,
			'server_route_namespace'         => $this->server_route_namespace,
			'server_route'                   => $this->server_route,
			'server_version'                 => $this->server_version,
			'claude_connector_client_id'     => $this->claude_connector_client_id,
			'claude_connector_client_secret' => $this->claude_connector_client_secret,
			'claude_connector_redirect_uri'  => $this->claude_connector_redirect_uri,
			'created_at'                     => $this->created_at,
		);
	}
}
