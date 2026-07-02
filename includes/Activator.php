<?php
namespace AcrossAI_MCP_Manager\Includes;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Table as OAuthTokenTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Table as OAuthAuditTable;
use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;
use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin activation.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 */
class Activator {

	/**
	 * Runs on plugin activation: create DB tables via BerlinDB, seed the default
	 * MCP server row, register rewrite rules, and schedule the daily OAuth cleanup cron.
	 *
	 * Per FR-015: NO class_exists() defensive guards and NO try/catch — after
	 * FR-011 the autoloader is live and any exception should propagate to
	 * fail activation loudly (per Clarification Q2).
	 *
	 * @since 0.0.1
	 */
	public static function activate() {

		// Feature 011: four BerlinDB Table subclasses handle create/upgrade lifecycle.
		// Order matters — MCPServer table must exist before DefaultServerSeeder::seed()
		// can insert the default row (FR-018).
		MCPServerTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();
		CliAuthLogTable::instance()->maybe_upgrade();
		OAuthTokenTable::instance()->maybe_upgrade();
		OAuthAuditTable::instance()->maybe_upgrade();

		// FrontendAuth — Phase 6.0 absorbs the full class. Activator delegates
		// rewrite-rule registration so the pattern definition lives in
		// exactly one place (loader contract).
		if ( class_exists( FrontendAuth::class ) ) {
			FrontendAuth::instance()->register_rewrite_rule();
		}

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
