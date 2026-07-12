<?php
/**
 * US5 — Uninstall opt-in flag = 0 (default): everything preserved.
 *
 * Preserve-by-default is a WP.org guideline #5 requirement.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

/**
 * @coversNothing
 */
class UninstallDefaultPreservationTest extends OAuthTestCase {

	public function test_uninstall_without_flag_preserves_tables_options_and_cron(): void {
		global $wpdb;

		\AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table::instance()->maybe_upgrade();
		\AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table::instance()->maybe_upgrade();
		\AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Table::instance()->maybe_upgrade();

		update_option( 'acrossai_mcp_oauth_clients_db_version', '1.0.0' );
		update_option( 'acrossai_mcp_oauth_tokens_db_version', '1.0.0' );
		update_option( 'acrossai_mcp_oauth_auth_codes_db_version', '1.0.0' );

		if ( ! wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'acrossai_mcp_manager_oauth_cleanup' );
		}

		// Default = 0. Explicitly ensure it's NOT set to 1.
		delete_option( 'acrossai_mcp_uninstall_delete_data' );
		$this->assertSame( 0, (int) get_option( 'acrossai_mcp_uninstall_delete_data', 0 ) );

		self::execute_uninstall_script();

		// Everything still present.
		$this->assert_table_exists( $wpdb->prefix . 'acrossai_mcp_oauth_clients' );
		$this->assert_table_exists( $wpdb->prefix . 'acrossai_mcp_oauth_tokens' );
		$this->assert_table_exists( $wpdb->prefix . 'acrossai_mcp_oauth_auth_codes' );

		$this->assertSame( '1.0.0', get_option( 'acrossai_mcp_oauth_clients_db_version' ) );
		$this->assertSame( '1.0.0', get_option( 'acrossai_mcp_oauth_tokens_db_version' ) );
		$this->assertSame( '1.0.0', get_option( 'acrossai_mcp_oauth_auth_codes_db_version' ) );

		$this->assertNotFalse( wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' ) );
	}

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
		$this->assertNotSame( '', $exists, $table . ' should be preserved on default uninstall' );
	}
}
