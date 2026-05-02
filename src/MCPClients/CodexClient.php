<?php
/**
 * Codex client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CodexClient extends AbstractMCPClient {

	public function get_id() {
		return 'codex';
	}

	public function get_label() {
		return 'Codex';
	}

	public function get_description() {
		return 'OpenAI Codex CLI';
	}

	public function get_icon() {
		return '🐙';
	}

	public function get_top_level_key() {
		return 'mcp';
	}

	public function get_config_file() {
		return '~/.codex/config.toml';
	}
}
