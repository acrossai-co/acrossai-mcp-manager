<?php
/**
 * GitHub Copilot client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubCopilotClient extends AbstractMCPClient {

	public function get_id() {
		return 'copilot';
	}

	public function get_label() {
		return 'GitHub Copilot';
	}

	public function get_description() {
		return 'GitHub Copilot in VS Code (user-level MCP config)';
	}

	public function get_icon() {
		return '🐱';
	}

	public function get_top_level_key() {
		return 'servers';
	}

	public function get_config_file() {
		return '~/.vscode/mcp.json';
	}
}
