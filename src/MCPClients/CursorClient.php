<?php
/**
 * Cursor client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CursorClient extends AbstractMCPClient {

	public function get_id() {
		return 'cursor';
	}

	public function get_label() {
		return 'Cursor';
	}

	public function get_description() {
		return 'Cursor AI Code Editor';
	}

	public function get_icon() {
		return '⚡';
	}

	public function get_top_level_key() {
		return 'mcpServers';
	}

	public function get_config_file() {
		return '~/.cursor/mcp.json';
	}
}
