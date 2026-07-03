<?php
/**
 * OpenAI Codex CLI MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a Codex CLI `config.json` snippet.
 *
 * Target file: `~/.codex/config.json`
 * Top-level key: `mcpServers`
 */
final class CodexClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'codex';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Codex';
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
