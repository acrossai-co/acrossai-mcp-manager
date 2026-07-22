<?php
/**
 * F032 T079 — ConnectorApprovedUsers BerlinDB module coverage.
 *
 * Verifies SC-013 (schema shape), SC-014 (approve idempotency + UNIQUE
 * enforcement), SC-015 (site-wide delete-by-user cascade), plus every
 * bespoke Query method (`find_by_server_and_connector`, `is_user_approved`,
 * `approve`, `revoke`, `delete_by_user_id`).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database\ConnectorApprovedUsers
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\ConnectorApprovedUsers;

use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Table;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class ConnectorApprovedUsersTest extends WP_UnitTestCase {

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		// Ensure Table exists (fresh-install path idempotently re-fires).
		Table::instance()->maybe_upgrade();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_connector_approved_users' ) );
	}

	// SC-013 — schema shape.
	public function test_table_created_with_all_four_indexes(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_connector_approved_users';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
				DB_NAME,
				$table
			)
		);
		$this->assertSame( 1, $exists, 'Table must exist post-maybe_upgrade' );

		$expected_indexes = array(
			'PRIMARY',
			'server_connector_user',
			'server_connector',
			'user_id',
		);
		foreach ( $expected_indexes as $index_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
					DB_NAME,
					$table,
					$index_name
				)
			);
			$this->assertGreaterThan( 0, $count, "Index {$index_name} must exist" );
		}
	}

	// SC-014 — approve idempotency + short-circuit-on-existing.
	public function test_approve_is_idempotent(): void {
		$result_a = Query::instance()->approve( 1, 'claude', 42, 99 );
		$this->assertTrue( $result_a, 'First approve returns true (new row inserted)' );

		$result_b = Query::instance()->approve( 1, 'claude', 42, 99 );
		$this->assertTrue( $result_b, 'Second approve returns true (short-circuit on existing row)' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE server_id = %d AND connector_slug = %s AND user_id = %d',
				$wpdb->prefix . 'acrossai_mcp_connector_approved_users',
				1,
				'claude',
				42
			)
		);
		$this->assertSame( 1, $count, 'Only ONE row exists after duplicate approve() calls' );
	}

	// SC-014 — UNIQUE constraint enforced at SQL layer.
	public function test_direct_duplicate_insert_fails_at_sql_layer(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_connector_approved_users';

		// First insert via BerlinDB Query.
		$this->assertTrue( Query::instance()->approve( 1, 'claude', 42, 99 ) );

		// Attempt second insert directly via wpdb — must fail on UNIQUE(server_id, connector_slug, user_id).
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (server_id, connector_slug, user_id, approved_by) VALUES (%d, %s, %d, %d)',
				$table,
				1,
				'claude',
				42,
				99
			)
		);
		$wpdb->suppress_errors( false );

		$this->assertFalse( (bool) $result, 'Duplicate insert must fail on UNIQUE constraint' );
		$this->assertNotEmpty( $wpdb->last_error, 'wpdb->last_error must contain the constraint violation' );
	}

	// Coverage: is_user_approved returns bool correctly.
	public function test_is_user_approved_returns_bool(): void {
		$this->assertFalse( Query::instance()->is_user_approved( 1, 'claude', 42 ), 'Unapproved user returns false' );

		Query::instance()->approve( 1, 'claude', 42, 99 );

		$this->assertTrue( Query::instance()->is_user_approved( 1, 'claude', 42 ), 'Approved user returns true' );
		$this->assertFalse( Query::instance()->is_user_approved( 1, 'claude', 43 ), 'Different user still returns false' );
		$this->assertFalse( Query::instance()->is_user_approved( 2, 'claude', 42 ), 'Different server still returns false' );
		$this->assertFalse( Query::instance()->is_user_approved( 1, 'chatgpt', 42 ), 'Different connector still returns false' );
	}

	// Coverage: revoke returns bool + deletes row.
	public function test_revoke_deletes_and_returns_true_on_hit(): void {
		Query::instance()->approve( 1, 'claude', 42, 99 );

		$deleted = Query::instance()->revoke( 1, 'claude', 42 );
		$this->assertTrue( $deleted, 'revoke() returns true when a row is deleted' );

		$this->assertFalse( Query::instance()->is_user_approved( 1, 'claude', 42 ), 'Row is gone post-revoke' );
	}

	public function test_revoke_returns_false_on_miss(): void {
		$deleted = Query::instance()->revoke( 1, 'claude', 42 );
		$this->assertFalse( $deleted, 'revoke() returns false when no row matched' );
	}

	// Coverage: find_by_server_and_connector enumerates all users for (server, connector).
	public function test_find_by_server_and_connector_returns_matching_rows(): void {
		Query::instance()->approve( 1, 'claude', 42, 99 );
		Query::instance()->approve( 1, 'claude', 43, 99 );
		Query::instance()->approve( 1, 'chatgpt', 44, 99 );  // Different connector.
		Query::instance()->approve( 2, 'claude', 45, 99 );   // Different server.

		$rows = Query::instance()->find_by_server_and_connector( 1, 'claude' );
		$this->assertCount( 2, $rows, 'Only 2 rows match (server_id=1, connector_slug=claude)' );

		$user_ids = array_map( fn( $row ) => $row->user_id, $rows );
		sort( $user_ids );
		$this->assertSame( array( 42, 43 ), $user_ids );
	}

	// SC-015 — delete_by_user_id cascades site-wide.
	public function test_delete_by_user_id_cascades_across_all_scopes(): void {
		// Seed same user across 3 (server, connector) pairs.
		Query::instance()->approve( 1, 'claude', 42, 99 );
		Query::instance()->approve( 1, 'chatgpt', 42, 99 );
		Query::instance()->approve( 2, 'claude', 42, 99 );
		// Plus another user (must be untouched).
		Query::instance()->approve( 1, 'claude', 43, 99 );

		$deleted_count = Query::instance()->delete_by_user_id( 42 );
		$this->assertSame( 3, $deleted_count, 'All 3 approval rows for user 42 deleted' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_42_remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d',
				$wpdb->prefix . 'acrossai_mcp_connector_approved_users',
				42
			)
		);
		$this->assertSame( 0, $user_42_remaining );

		// User 43 untouched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_43_remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d',
				$wpdb->prefix . 'acrossai_mcp_connector_approved_users',
				43
			)
		);
		$this->assertSame( 1, $user_43_remaining );
	}

	// Input-validation guards.
	public function test_approve_rejects_invalid_input(): void {
		$this->assertFalse( Query::instance()->approve( 0, 'claude', 42, 99 ) );
		$this->assertFalse( Query::instance()->approve( 1, '', 42, 99 ) );
		$this->assertFalse( Query::instance()->approve( 1, 'claude', 0, 99 ) );
	}

	public function test_delete_by_user_id_rejects_invalid_input(): void {
		$this->assertSame( 0, Query::instance()->delete_by_user_id( 0 ) );
		$this->assertSame( 0, Query::instance()->delete_by_user_id( -1 ) );
	}
}
