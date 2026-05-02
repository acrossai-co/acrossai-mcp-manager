<?php
/**
 * Custom client definition.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage MCPClients
 */

namespace ACROSSAI_MCP_MANAGER\MCPClients;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CustomClient extends AbstractMCPClient {

	public function get_id() {
		return 'custom';
	}

	public function get_label() {
		return 'Custom Client';
	}

	public function get_description() {
		return 'Custom MCP Client Implementation';
	}

	public function get_icon() {
		return '⚙️';
	}

	public function get_top_level_key() {
		return 'mcpServers';
	}

	public function get_config_file() {
		return './your-project/.mcp/config.json';
	}
}
