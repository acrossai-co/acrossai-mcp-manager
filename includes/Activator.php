<?php
namespace AcrossAI_MCP_Manager\Includes;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin activation.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 */
class Activator {

	/**
	 * Runs on plugin activation: create DB tables, register rewrite rules,
	 * and schedule the daily OAuth cleanup cron.
	 *
	 * @since 0.0.1
	 */
	public static function activate() {

		// Create the MCP servers custom table if the class is available.
		if ( class_exists( MCPServerQuery::class ) ) {
			MCPServerQuery::maybe_create_table();
		}

		// Create / upgrade the CLI auth log table (Phase 5 bumps DB_VERSION to 0.0.2
		// to add the 4 OAuth columns: redirect_uri, code_challenge,
		// code_challenge_method, scope).
		if ( class_exists( CliAuthLogQuery::class ) ) {
			CliAuthLogQuery::maybe_create_table();
		}

		// Create the OAuth tokens table (Phase 5).
		if ( class_exists( OAuthTokenQuery::class ) ) {
			OAuthTokenQuery::maybe_create_table();
		}

		// Create the OAuth audit log table (Phase 5).
		if ( class_exists( OAuthAuditQuery::class ) ) {
			OAuthAuditQuery::maybe_create_table();
		}

		// FrontendAuth (Phase 3 placeholder) rewrite rule.
		add_rewrite_rule( '^acrossai-mcp-manager/?$', 'index.php?mcp_frontend_auth=1', 'top' );

		// OAuth rewrite rules — Phase 5 delegates to ClaudeConnectors so the
		// pattern definitions live in exactly one place (loader contract).
		if ( class_exists( ClaudeConnectors::class ) ) {
			ClaudeConnectors::instance()->register_rewrite_rules();
		}

		flush_rewrite_rules();

		// Schedule daily OAuth cleanup (FR-019c).
		if ( ! wp_next_scheduled( 'acrossai_mcp_oauth_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'acrossai_mcp_oauth_cleanup' );
		}
	}
}
