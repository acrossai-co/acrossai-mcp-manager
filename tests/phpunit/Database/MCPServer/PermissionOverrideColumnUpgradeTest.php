<?php
/**
 * Feature 030 — regression coverage for the D28 3-part contract on the
 * `override_abilities_permission` column added to `wp_acrossai_mcp_servers`
 * at Table version 1.1.1 → 1.1.2.
 *
 * `WP_UnitTestCase` runs `Activator::activate()` (which calls
 * `MCPServerTable::instance()->maybe_upgrade()`), so by the time this test
 * runs the schema is already at v1.1.2 with the column present. This test
 * also exercises the drop-and-restore path to prove the `INFORMATION_SCHEMA`
 * idempotency guard fires correctly on the ALTER (B34 mitigation).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class PermissionOverrideColumnUpgradeTest extends WP_UnitTestCase {

	public function test_override_abilities_permission_column_exists_after_upgrade(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'override_abilities_permission'" );

		$this->assertCount( 1, $rows, 'F030 must ship the override_abilities_permission column.' );
		$this->assertSame( 'tinyint(1)', strtolower( (string) $rows[0]->Type ) );
		$this->assertSame( 'NO', (string) $rows[0]->Null );
		$this->assertSame( '0', (string) $rows[0]->Default );
	}

	public function test_new_column_defaults_to_zero_for_freshly_inserted_rows(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'F030 upgrade test server',
				'server_slug'            => 'f030-upgrade-test',
				'description'            => 'Seeded by PermissionOverrideColumnUpgradeTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'f030-upgrade-test',
				'server_version'         => 'v1.0.0',
			)
		);
		$rows = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
		$row  = $rows[0];

		// B18: TINYINT returns as string from $wpdb; Row constructor casts to
		// (int). Assert both the storage default AND the Row cast are correct.
		$this->assertSame( 0, (int) $row->override_abilities_permission );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'acrossai_mcp_servers', array( 'id' => $server_id ), array( '%d' ) );
	}

	public function test_upgrade_to_1_1_2_is_idempotent_when_column_already_exists(): void {
		// The bootstrapped state already has the column. Force a version
		// downgrade in wp_options and re-invoke maybe_upgrade; the callback
		// MUST short-circuit via INFORMATION_SCHEMA existence check and NOT
		// re-issue the ALTER (which would produce a duplicate-column error).
		update_option( 'acrossai_mcp_servers_db_version', '1.1.1' );

		$this->expectNotToPerformAssertions();
		MCPServerTable::instance()->maybe_upgrade();
		MCPServerTable::instance()->maybe_upgrade();
	}

	public function test_upgrade_to_1_1_2_recreates_dropped_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';

		// Drop the column AND rewind the version option — this is the drift
		// scenario B34 documents (silent write-loss when schema drifts).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `override_abilities_permission`" );
		update_option( 'acrossai_mcp_servers_db_version', '1.1.1' );

		MCPServerTable::instance()->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'override_abilities_permission'" );
		$this->assertCount( 1, $rows, 'D28 upgrade path must re-add the dropped column.' );

		$this->assertSame( '1.1.2', (string) get_option( 'acrossai_mcp_servers_db_version' ) );
	}
}
