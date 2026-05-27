<?php
namespace AcrossAI_MCP_Manager\Includes;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Define the internationalization functionality
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 */
class I18n {

	/**
	 * Actually load the plugin textdomain on `init`
	 */
	public function do_load_textdomain() {
		load_plugin_textdomain(
			'acrossai-mcp-manager',
			false,
			plugin_basename( dirname( \ACROSSAI_MCP_MANAGER_PLUGIN_FILE ) ) . '/languages/'
		);
	}
}
