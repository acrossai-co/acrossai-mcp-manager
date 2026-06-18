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
 * Produces a shell-command STRING (not an array) — Claude Code uses
 * its CLI's `claude mcp add` command to register servers, idiomatic
 * per Anthropic's CLI MCP docs.
 *
 * Shape:
 *   claude mcp add '<server-key>' \
 *     --env WP_API_URL='<url>' \
 *     --env WP_API_PASSWORD='<token>' \
 *     -- npx -y '@automattic/mcp-wordpress-remote@latest'
 *
 * Shell metacharacters in URL/token are escape-safe via escapeshellarg().
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
	 * @return string Shell-command snippet for `claude mcp add`.
	 */
	public function get_config_snippet( string $server_url, string $auth_token ): string {
		$key   = $this->derive_server_key( $server_url );
		$token = $this->safe_token( $auth_token );

		return sprintf(
			'claude mcp add %s --env WP_API_URL=%s --env WP_API_PASSWORD=%s -- npx -y %s',
			escapeshellarg( $key ),
			escapeshellarg( $server_url ),
			escapeshellarg( $token ),
			escapeshellarg( '@automattic/mcp-wordpress-remote@latest' )
		);
	}
}
