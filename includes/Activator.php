<?php
namespace AcrossAI_MCP_Manager\Includes;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLog\Query as ConnectorAuditLogQuery;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin activation
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      0.0.1
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 * @author     WPBoilerplate <contact@wpboilerplate.com>
 */
class Activator {

	/**
	 * Runs on plugin activation: create DB tables and register rewrite rules.
	 *
	 * @since    0.0.1
	 */
	public static function activate() {

		// Create the MCP servers custom table if the class is available.
		if ( class_exists( MCPServerQuery::class ) ) {
			MCPServerQuery::maybe_create_table();
		}

		// Create the CLI auth log custom table if the class is available.
		if ( class_exists( CliAuthLogQuery::class ) ) {
			CliAuthLogQuery::maybe_create_table();
		}

		// Create the connector audit log custom table if the class is available.
		if ( class_exists( ConnectorAuditLogQuery::class ) ) {
			ConnectorAuditLogQuery::maybe_create_table();
		}

		// Register rewrite rules for the plugin's frontend routes.
		add_rewrite_rule( '^acrossai-mcp-manager/?$', 'index.php?mcp_frontend_auth=1', 'top' );
		add_rewrite_rule( '^acrossai-mcp-connectors/oauth/authorize/?$', 'index.php?mcp_oauth_authorize=1', 'top' );
		add_rewrite_rule( '^\\.well-known/oauth-authorization-server/?$', 'index.php?mcp_oauth_metadata=1', 'top' );
		add_rewrite_rule( '^\\.well-known/oauth-protected-resource/?$', 'index.php?mcp_oauth_metadata_resource=1', 'top' );

		flush_rewrite_rules();
	}
}
