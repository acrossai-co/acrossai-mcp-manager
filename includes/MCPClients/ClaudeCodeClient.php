<?php
/**
 * Claude Code CLI MCP client.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a `~/.claude.json`-shaped snippet.
 *
 * Target file: `~/.claude.json`
 * Top-level key: `mcpServers`
 *
 * OAUTH_ENABLED is pinned to "false" — the WordPress MCP server the user
 * targets from Claude Code authenticates via Application Passwords (HTTP
 * Basic), and the `@automattic/mcp-wordpress-remote` client's OAuth path
 * expects a token endpoint we don't expose. Setting it explicitly here
 * keeps the client from falling into its OAuth branch when a future
 * default flip happens upstream.
 */
final class ClaudeCodeClient extends AbstractMCPClient {

	/**
	 * {@inheritDoc}
	 */
	public function get_client_slug(): string {
		return 'claude-code';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_client_name(): string {
		return 'Claude Code';
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
						'OAUTH_ENABLED'   => 'false',
						'WP_API_URL'      => $server_url,
						'WP_API_USERNAME' => $this->current_username(),
						'WP_API_PASSWORD' => $this->safe_token( $auth_token ),
					),
				),
			),
		);
	}
}
