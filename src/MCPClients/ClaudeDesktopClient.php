<?php
/**
 * Claude Desktop client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClaudeDesktopClient extends AbstractMCPClient {

	public function get_id() {
		return 'claude';
	}

	public function get_label() {
		return 'Claude Desktop';
	}

	public function get_description() {
		return 'Anthropic Claude Desktop App';
	}

	public function get_icon() {
		return '🤖';
	}

	public function get_top_level_key() {
		return 'mcpServers';
	}

	public function get_config_file() {
		return '~/Library/Application Support/Claude/claude_desktop_config.json';
	}
}
