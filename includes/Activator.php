<?php
namespace AcrossAI_MCP_Manager\Includes;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table as CliAuthLogTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table as MCPServerToolTable;
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
	 * MCP server row, and register rewrite rules.
	 *
	 * Per FR-015: NO class_exists() defensive guards and NO try/catch — after
	 * FR-011 the autoloader is live and any exception should propagate to
	 * fail activation loudly (per Clarification Q2).
	 *
	 * @since 0.0.1
	 */
	public static function activate() {

		// Feature 011: BerlinDB Table subclasses handle create/upgrade lifecycle.
		// Order matters — MCPServer table must exist before DefaultServerSeeder::seed()
		// can insert the default row (FR-018). Feature 016 retired the two
		// dedicated Connectors BerlinDB modules; operator drops pre-016 physical
		// tables manually per spec.md §User Story 2.
		MCPServerTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();
		CliAuthLogTable::instance()->maybe_upgrade();
		// Feature 017 — per-server ability exposure overrides. No seeder call —
		// the empty-table state IS the correct backwards-compatible initial state.
		MCPServerAbilityTable::instance()->maybe_upgrade();
		// Feature 020 — per-server tool selection. Presence-based storage; no
		// seeder — the empty-table state is the correct initial state (UI
		// renders the zero-added warning banner until the operator saves a
		// non-empty set). Co-commit invariant with the Main.php request-time
		// boot below (DEC-BERLINDB-TABLE-REQUEST-BOOT).
		MCPServerToolTable::instance()->maybe_upgrade();

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

		flush_rewrite_rules();
	}
}
