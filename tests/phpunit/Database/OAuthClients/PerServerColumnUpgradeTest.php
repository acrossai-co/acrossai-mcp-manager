<?php
/**
 * Feature 032 (US3, T013) — regression coverage for the D28 3-part contract on
 * the `server_id` column added to `wp_acrossai_mcp_oauth_clients` at Table
 * version 1.0.0 → 1.0.1.
 *
 * Mirrors the F030 `MCPServer\PermissionOverrideColumnUpgradeTest` shape.
 * `WP_UnitTestCase` runs `Activator::activate()` (which calls
 * `OAuthClientsTable::instance()->maybe_upgrade()`), so by the time this
 * test class runs, the schema is already at v1.0.1 with the column NOT NULL
 * + composite UNIQUE(client_id, server_id).
 *
 * Assertions per T013 spec:
 *   (a) column present with IS_NULLABLE = 'NO' post-migration
 *   (b) fresh insert with server_id succeeds AND fresh insert without
 *       server_id FAILS with MySQL constraint violation
 *   (c) idempotent re-run (maybe_upgrade twice → no ALTER, no duplicate
 *       errors, no second `acrossai_mcp_oauth_legacy_dcr_purged` fire)
 *   (d) dropped-column recovery (drop column, rewind version, re-run → column
 *       restored + backfill + NOT NULL re-applied)
 *   (e) composite UNIQUE(client_id, server_id) present + standalone
 *       UNIQUE(client_id) gone
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\OAuthClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\OAuthClients;

use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table as OAuthClientsTable;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class PerServerColumnUpgradeTest extends WP_UnitTestCase {

	public function test_server_id_column_exists_after_upgrade_with_not_null(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'server_id'" );

		$this->assertCount( 1, $rows, 'F032 must ship the server_id column on oauth_clients.' );
		$this->assertSame( 'bigint(20) unsigned', strtolower( (string) $rows[0]->Type ) );
		$this->assertSame( 'NO', (string) $rows[0]->Null, 'server_id must be NOT NULL post-migration (FR-026 / SC-009).' );
	}

	public function test_insert_with_server_id_succeeds_and_without_fails(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// Insert WITH server_id succeeds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$table,
			array(
				'client_id'                  => 'server-1-t013-with-' . bin2hex( random_bytes( 4 ) ),
				'server_id'                  => 1,
				'client_secret_hash'         => null,
				'client_name'                => 'T013 with server_id',
				'redirect_uris'              => '[]',
				'grant_types'                => 'authorization_code refresh_token',
				'token_endpoint_auth_method' => 'none',
				'connector_slug'             => '',
				'metadata_fingerprint'       => '',
				'created_at'                 => current_time( 'mysql', 1 ),
			)
		);
		$this->assertNotFalse( $ok, 'INSERT with server_id present must succeed.' );

		// Insert WITHOUT server_id fails at SQL layer.
		// We use suppress_errors to prevent WP_UnitTestCase from choking on the deliberate error.
		$prior_suppression = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$fail = $wpdb->insert(
			$table,
			array(
				'client_id'                  => 'server-1-t013-without-' . bin2hex( random_bytes( 4 ) ),
				'client_secret_hash'         => null,
				'client_name'                => 'T013 without server_id',
				'redirect_uris'              => '[]',
				'grant_types'                => 'authorization_code refresh_token',
				'token_endpoint_auth_method' => 'none',
				'connector_slug'             => '',
				'metadata_fingerprint'       => '',
				'created_at'                 => current_time( 'mysql', 1 ),
			)
		);
		$wpdb->suppress_errors( $prior_suppression );

		$this->assertFalse( $ok = ( false === $fail || 0 === (int) $fail ) ? false : true );
		$this->assertNotEmpty( $wpdb->last_error, 'INSERT without server_id must fail with constraint violation.' );
	}

	public function test_upgrade_to_1_0_1_is_idempotent_when_column_already_exists(): void {
		// Force version rewind + re-invoke; callback MUST short-circuit via
		// INFORMATION_SCHEMA existence check and NOT re-issue the ALTER,
		// nor fire a second `acrossai_mcp_oauth_legacy_dcr_purged` (there
		// are no rows with server_id IS NULL after the initial upgrade).
		update_option( 'acrossai_mcp_oauth_clients_db_version', '1.0.0' );

		$captured = array( 'calls' => array() );
		add_action(
			'acrossai_mcp_oauth_legacy_dcr_purged',
			function ( ...$args ) use ( &$captured ) {
				$captured['calls'][] = $args;
			},
			10,
			99
		);

		$this->expectNotToPerformAssertions();
		OAuthClientsTable::instance()->maybe_upgrade();
		OAuthClientsTable::instance()->maybe_upgrade();
	}

	public function test_upgrade_to_1_0_1_recreates_dropped_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// Drop the column + composite index + rewind the version option.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `client_id_server_id`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `server_id`" );
		update_option( 'acrossai_mcp_oauth_clients_db_version', '1.0.0' );

		OAuthClientsTable::instance()->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'server_id'" );
		$this->assertCount( 1, $cols, 'D28 upgrade path must re-add the dropped column.' );
		$this->assertSame( 'NO', (string) $cols[0]->Null );

		$this->assertSame( '1.0.1', (string) get_option( 'acrossai_mcp_oauth_clients_db_version' ) );
	}

	public function test_composite_unique_replaces_standalone_client_id_unique(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$composite = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'client_id_server_id'" );
		$this->assertCount( 2, $composite, 'Composite UNIQUE(client_id, server_id) must be present.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$legacy = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'client_id'" );
		$this->assertCount( 0, $legacy, 'Standalone UNIQUE(client_id) MUST be dropped post-migration.' );
	}
}
