<?php
/**
 * Claude Code client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClaudeCodeClient extends AbstractMCPClient {

	public function get_id() {
		return 'claude-code';
	}

	public function get_label() {
		return 'Claude Code';
	}

	public function get_description() {
		return 'Anthropic Claude Code CLI';
	}

	public function get_icon() {
		return '⌨️';
	}

	public function get_top_level_key() {
		return 'mcpServers';
	}

	public function get_config_file() {
		return '~/.claude.json';
	}
}
