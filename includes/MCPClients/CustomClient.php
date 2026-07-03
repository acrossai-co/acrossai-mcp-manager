<?php
/**
 * Custom / generic MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a generic `mcpServers`-shaped snippet the user adapts to any
 * MCP-compatible tool the plugin does not natively recognise.
 *
 * Includes an inline comment field (`_comment`) explaining that the
 * user should rename the top-level key to match their tool's expected
 * envelope.
 */
final class CustomClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'custom';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Custom Client';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $server_url Already-sanitised server URL.
	 * @param string $auth_token Already-issued Application Password (may be empty).
	 *
	 * @return array<string, mixed>
	 */
	public function get_config_snippet( string $server_url, string $auth_token ): array {
		return array(
			'_comment'   => 'Adapt the top-level key to match your MCP tool (e.g. mcp.servers, tools, etc.).',
			'mcpServers' => array(
				$this->derive_server_key( $server_url ) => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'env'     => array(
						'WP_API_URL'      => $server_url,
						'WP_API_USERNAME' => $this->current_username(),
						'WP_API_PASSWORD' => $this->safe_token( $auth_token ),
					),
				),
			),
		);
	}
}
