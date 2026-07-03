<?php
namespace AcrossAI_MCP_Manager\Includes;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Table as OAuthTokenTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Table as OAuthAuditTable;
use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;
use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WPBoilerplate\AccessControl\Database\Rule\RuleTable as WPB_AccessControl_RuleTable;

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

		// Feature 015 — Access Control v2 adoption. Create the
		// {$wpdb->prefix}mcp_access_control table via the vendor-owned
		// RuleTable BerlinDB subclass. SEC-015-001 defense-in-depth: the
		// class_exists guard protects against a vendor-uninstall-then-reactivate
		// race where the Composer package was removed since the last activation.
		if ( class_exists( WPB_AccessControl_RuleTable::class ) ) {
			global $wpdb;
			$ac_table   = $wpdb->prefix . AcrossAI_MCP_Access_Control::TABLE_SLUG . '_access_control';
			$version_op = 'wpb_ac_' . AcrossAI_MCP_Access_Control::TABLE_SLUG . '_db_version';

			// Phantom-version guard (WORKLOG 2026-07-02 lesson). The vendor's
			// RuleTable does NOT ship this guard (unlike our F011 subclasses),
			// so if the operator manually dropped the table, BerlinDB's
			// needs_upgrade() would return false (version option still stamped)
			// and silently skip the CREATE. Wiping the option first forces a
			// fresh install. SILENT — no error_log, no admin notice.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ac_table ) );
			if ( '' === $exists ) {
				delete_option( $version_op );
			}

			( new WPB_AccessControl_RuleTable( AcrossAI_MCP_Access_Control::TABLE_SLUG ) )->maybe_upgrade();
		}

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
