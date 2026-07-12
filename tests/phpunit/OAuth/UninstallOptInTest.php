<?php
/**
 * US5 — Uninstall opt-in flag = 1: destructive teardown.
 *
 * Verifies the F012 opt-in gate + F021 additions:
 * - Three F021 tables dropped
 * - Three `_db_version` options deleted (via LIKE-sweep)
 * - `acrossai_mcp_manager_oauth_cleanup` cron cleared
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

/**
 * @coversNothing
 */
class UninstallOptInTest extends OAuthTestCase {

	public function test_uninstall_with_flag_drops_all_f021_tables_and_clears_cron(): void {
		global $wpdb;

		// Ensure the tables exist (Phase 2 bootstrap should have created them).
		\AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table::instance()->maybe_upgrade();
		\AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table::instance()->maybe_upgrade();
		\AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Table::instance()->maybe_upgrade();

		$this->assert_table_exists( $wpdb->prefix . 'acrossai_mcp_oauth_clients' );
		$this->assert_table_exists( $wpdb->prefix . 'acrossai_mcp_oauth_tokens' );
		$this->assert_table_exists( $wpdb->prefix . 'acrossai_mcp_oauth_auth_codes' );

		update_option( 'acrossai_mcp_oauth_clients_db_version', '1.0.0' );
		update_option( 'acrossai_mcp_oauth_tokens_db_version', '1.0.0' );
		update_option( 'acrossai_mcp_oauth_auth_codes_db_version', '1.0.0' );

		// Set the opt-in flag.
		update_option( 'acrossai_mcp_uninstall_delete_data', 1 );

		// Schedule the cron so we can prove it's cleared.
		if ( ! wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'acrossai_mcp_manager_oauth_cleanup' );
		}
		$this->assertNotFalse( wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' ) );

		// Execute uninstall.php in-process.
		self::execute_uninstall_script();

		// Tables gone.
		$this->assert_table_absent( $wpdb->prefix . 'acrossai_mcp_oauth_clients' );
		$this->assert_table_absent( $wpdb->prefix . 'acrossai_mcp_oauth_tokens' );
		$this->assert_table_absent( $wpdb->prefix . 'acrossai_mcp_oauth_auth_codes' );

		// Options gone (via LIKE-sweep).
		$this->assertFalse( get_option( 'acrossai_mcp_oauth_clients_db_version' ) );
		$this->assertFalse( get_option( 'acrossai_mcp_oauth_tokens_db_version' ) );
		$this->assertFalse( get_option( 'acrossai_mcp_oauth_auth_codes_db_version' ) );

		// Cron cleared.
		$this->assertFalse( wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' ) );
	}

	/**
	 * Execute uninstall.php as if WordPress had triggered it. Defines
	 * WP_UNINSTALL_PLUGIN if not already defined.
	 */
	private static function execute_uninstall_script(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		include dirname( __DIR__, 3 ) . '/uninstall.php';
	}

	private function assert_table_exists( string $table ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertNotSame( '', $exists, $table . ' should exist' );
	}

	private function assert_table_absent( string $table ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( '', $exists, $table . ' should have been dropped' );
	}
}
