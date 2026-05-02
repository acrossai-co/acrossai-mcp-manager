<?php
/**
 * VS Code client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSCodeClient extends AbstractMCPClient {

	public function get_id() {
		return 'vscode';
	}

	public function get_label() {
		return 'VS Code';
	}

	public function get_description() {
		return 'Visual Studio Code';
	}

	public function get_icon() {
		return '󰨞';
	}

	public function get_top_level_key() {
		return 'servers';
	}

	public function get_config_file() {
		return '~/.vscode/mcp.json';
	}
}
