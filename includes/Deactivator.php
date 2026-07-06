<?php
namespace AcrossAI_MCP_Manager\Includes;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin deactivation
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      0.0.1
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 * @author     WPBoilerplate <contact@wpboilerplate.com>
 */
class Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    0.0.1
	 */
	public static function deactivate() {
		// Feature 016: no plugin-side deactivation cleanup. Retired Connectors
		// cron events are unscheduled by the operator via `wp cron event
		// unschedule` per spec §User Story 2.
	}
}
