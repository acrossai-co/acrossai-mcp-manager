<?php
/**
 * Phantom-version guard preservation across all three F021 tables.
 *
 * F011 SEC-011-002 fix: when the version option exists but the physical
 * table was dropped, `maybe_upgrade()` must delete the option so the
 * fresh install fires. All three F021 Table subclasses inherit this guard.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table as ClientsTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table as TokensTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Table as AuthCodesTable;

/**
 * @coversNothing
 */
class PhantomVersionGuardTest extends OAuthTestCase {

	/**
	 * @return array<string, array{class-string, string, string}>
	 */
	public function tableProvider(): array {
		return array(
			'clients'    => array( ClientsTable::class, 'acrossai_mcp_oauth_clients', 'acrossai_mcp_oauth_clients_db_version' ),
			'tokens'     => array( TokensTable::class, 'acrossai_mcp_oauth_tokens', 'acrossai_mcp_oauth_tokens_db_version' ),
			'auth_codes' => array( AuthCodesTable::class, 'acrossai_mcp_oauth_auth_codes', 'acrossai_mcp_oauth_auth_codes_db_version' ),
		);
	}

	/**
	 * @dataProvider tableProvider
	 *
	 * @param class-string $table_class
	 * @param string       $table_name
	 * @param string       $version_key
	 */
	public function test_maybe_upgrade_reinstalls_when_table_missing_but_option_present( string $table_class, string $table_name, string $version_key ): void {
		global $wpdb;

		$table_class::instance()->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table_name ) );

		update_option( $version_key, '1.0.0' );

		$table_class::instance()->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table_name ) );

		$this->assertNotSame(
			'',
			$exists,
			sprintf( 'Phantom-version guard failed for %s — the CREATE was skipped despite table absence.', $table_name )
		);
	}
}
