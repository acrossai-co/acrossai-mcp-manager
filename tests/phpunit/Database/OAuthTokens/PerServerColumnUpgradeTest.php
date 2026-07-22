<?php
/**
 * Feature 032 (US3, T014) — regression coverage for the D28 3-part contract on
 * the `server_id` column added to `wp_acrossai_mcp_oauth_tokens` at Table
 * version 1.0.0 → 1.0.1.
 *
 * Mirrors T013 (OAuthClients) shape. Differences: KEY(server_id, client_id)
 * instead of composite UNIQUE.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\OAuthTokens
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\OAuthTokens;

use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table as OAuthTokensTable;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class PerServerColumnUpgradeTest extends WP_UnitTestCase {

	public function test_server_id_column_exists_after_upgrade_with_not_null(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'server_id'" );

		$this->assertCount( 1, $rows, 'F032 must ship the server_id column on oauth_tokens.' );
		$this->assertSame( 'bigint(20) unsigned', strtolower( (string) $rows[0]->Type ) );
		$this->assertSame( 'NO', (string) $rows[0]->Null, 'server_id must be NOT NULL post-migration.' );
	}

	public function test_insert_with_server_id_succeeds_and_without_fails(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$table,
			array(
				'token_hash'      => hash( 'sha256', 't014-with-' . random_bytes( 8 ) ),
				'token_type'      => 'access',
				'client_id'       => 'server-1-t014-with',
				'server_id'       => 1,
				'user_id'         => 1,
				'scope'           => 'mcp',
				'resource'        => '',
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'revoked'         => 0,
				'token_family_id' => wp_generate_uuid4(),
				'created_at'      => current_time( 'mysql', 1 ),
			)
		);
		$this->assertNotFalse( $ok, 'INSERT with server_id present must succeed.' );

		$prior_suppression = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$fail = $wpdb->insert(
			$table,
			array(
				'token_hash'      => hash( 'sha256', 't014-without-' . random_bytes( 8 ) ),
				'token_type'      => 'access',
				'client_id'       => 'server-1-t014-without',
				'user_id'         => 1,
				'scope'           => 'mcp',
				'resource'        => '',
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'revoked'         => 0,
				'token_family_id' => wp_generate_uuid4(),
				'created_at'      => current_time( 'mysql', 1 ),
			)
		);
		$wpdb->suppress_errors( $prior_suppression );

		$this->assertFalse( ( false === $fail ) ? false : true );
		$this->assertNotEmpty( $wpdb->last_error, 'INSERT without server_id must fail with constraint violation.' );
	}

	public function test_upgrade_to_1_0_1_is_idempotent_when_column_already_exists(): void {
		update_option( 'acrossai_mcp_oauth_tokens_db_version', '1.0.0' );

		$this->expectNotToPerformAssertions();
		OAuthTokensTable::instance()->maybe_upgrade();
		OAuthTokensTable::instance()->maybe_upgrade();
	}

	public function test_upgrade_to_1_0_1_recreates_dropped_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `server_id_client_id`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `server_id`" );
		update_option( 'acrossai_mcp_oauth_tokens_db_version', '1.0.0' );

		OAuthTokensTable::instance()->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'server_id'" );
		$this->assertCount( 1, $cols );
		$this->assertSame( 'NO', (string) $cols[0]->Null );

		$this->assertSame( '1.0.1', (string) get_option( 'acrossai_mcp_oauth_tokens_db_version' ) );
	}

	public function test_composite_key_server_id_client_id_present(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$idx = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'server_id_client_id'" );
		$this->assertCount( 2, $idx, 'Composite KEY(server_id, client_id) must be present.' );
	}
}
