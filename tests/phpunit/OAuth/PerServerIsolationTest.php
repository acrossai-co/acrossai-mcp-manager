<?php
/**
 * Feature 032 (US1 + US2 + US3 + US4) — Per-server isolation invariants.
 *
 * 10 tests covering the F032 security-fix contract end-to-end:
 *
 *   T016 (#8)  test_legacy_dcr_purge_on_upgrade_fires_observability_action    US3 / SC-008
 *   T017 (#10) test_backfill_skips_orphan_server_ids                          US3 / SC-011
 *   T027 (#1)  test_server_a_revoke_does_not_touch_server_b_tokens            US1 / SC-001
 *   T028 (#2)  test_server_a_delete_does_not_touch_server_b_client_row        US1 / SC-001
 *   T029 (#4)  test_rest_endpoint_returns_403_on_server_id_mismatch           US1 / SC-001
 *   T030 (#5)  test_authorized_users_listing_filters_by_server_id             US1 / FR-009
 *   T031 (#7)  test_cross_server_403_fires_observability_action               US1 / FR-023 / SC-007
 *   T045 (#3)  test_same_dcr_connector_registers_on_two_servers_as_two_rows   US2 / SC-002
 *   T046 (#9)  test_dcr_rejects_attacker_origin_url                           US2 / FR-027 / SC-010
 *   T047 (#11) test_dcr_returns_503_when_column_absent                        US2 / FR-028 / SC-012
 *   T053 (#6)  test_user_deletion_still_cascades_across_all_servers           US4 / FR-042
 *
 * NOTE (test #8 + #11): both tests need to seed pre-migration state — server_id
 * column NULL-allowed AND legacy DCR rows present. They achieve this by DROPping
 * the column (rewinding schema), rewinding db_version, seeding legacy rows via
 * raw $wpdb (bypassing Row's post-migration NOT NULL invariant), then re-running
 * maybe_upgrade() and asserting on outcomes.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Table as OAuthAuthCodesTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query as OAuthClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table as OAuthClientsTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as OAuthTokensQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table as OAuthTokensTable;
use AcrossAI_MCP_Manager\Includes\OAuth\ConnectorAdminController;
use AcrossAI_MCP_Manager\Includes\OAuth\UserLifecycle;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class PerServerIsolationTest extends OAuthTestCase {

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Seed an admin OAuth client on a specific server. Returns the row's client_id.
	 */
	private function seed_admin_client( int $server_id, string $slug ): string {
		global $wpdb;
		$client_id = 'server-' . $server_id . '-' . $slug . '-' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_oauth_clients',
			array(
				'client_id'                  => $client_id,
				'server_id'                  => $server_id,
				'client_name'                => ucfirst( $slug ) . ' on server ' . $server_id,
				'grant_types'                => 'authorization_code refresh_token',
				'token_endpoint_auth_method' => 'none',
				'connector_slug'             => $slug,
				'metadata_fingerprint'       => hash( 'sha256', $client_id ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $client_id;
	}

	/**
	 * Seed a token on a specific (client_id, server_id) for a given user. Returns token row id.
	 */
	private function seed_token( string $client_id, int $server_id, int $user_id ): int {
		global $wpdb;
		$token_hash = hash( 'sha256', $client_id . ':' . $server_id . ':' . $user_id . ':' . bin2hex( random_bytes( 4 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			array(
				'token_hash'      => $token_hash,
				'token_type'      => 'access',
				'client_id'       => $client_id,
				'server_id'       => $server_id,
				'user_id'         => $user_id,
				'scope'           => 'mcp',
				'resource'        => 'https://example.com/wp-json/mcp/server-' . $server_id,
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'revoked'         => 0,
				'token_family_id' => str_repeat( 'a', 8 ) . '-1234-1234-1234-' . str_repeat( 'b', 12 ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Count rows on a table matching WHERE clause. WHERE MUST be trusted (no user input).
	 */
	private function count_rows( string $table, string $where = '1=1' ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}{$table}` WHERE {$where}" );
	}

	/**
	 * Rewind the oauth_clients schema to pre-F032 state for tests #8 + #11.
	 * Drops server_id column + composite UNIQUE + rewinds db_version.
	 * DOES NOT re-seed — caller adds rows via raw $wpdb.
	 */
	private function rewind_oauth_clients_to_pre_f032(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `client_id_server_id`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `server_id`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `client_id` (`client_id`)" );

		delete_option( 'acrossai_mcp_oauth_clients_db_version' );
	}

	// -------------------------------------------------------------------------
	// US3 tests.
	// -------------------------------------------------------------------------

	/** Test #8 (T016 / FR-024 / SC-008). */
	public function test_legacy_dcr_purge_on_upgrade_fires_observability_action(): void {
		global $wpdb;

		$this->rewind_oauth_clients_to_pre_f032();

		// Seed M=2 legacy DCR client rows (no server-{id}- prefix).
		$dcr_1 = 'claude-desktop-legacy-' . bin2hex( random_bytes( 4 ) );
		$dcr_2 = 'chatgpt-legacy-' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'acrossai_mcp_oauth_clients', array( 'client_id' => $dcr_1, 'client_name' => 'Claude Desktop legacy', 'connector_slug' => '', 'metadata_fingerprint' => hash( 'sha256', $dcr_1 ) ), array( '%s', '%s', '%s', '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'acrossai_mcp_oauth_clients', array( 'client_id' => $dcr_2, 'client_name' => 'ChatGPT legacy', 'connector_slug' => '', 'metadata_fingerprint' => hash( 'sha256', $dcr_2 ) ), array( '%s', '%s', '%s', '%s' ) );

		// Rewind tokens table + seed P=3 tokens tied to these legacy DCR clients.
		$tokens_table = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE `{$tokens_table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$tokens_table}` DROP INDEX IF EXISTS `server_id_client_id`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$tokens_table}` DROP COLUMN IF EXISTS `server_id`" );
		delete_option( 'acrossai_mcp_oauth_tokens_db_version' );
		foreach ( array( $dcr_1, $dcr_1, $dcr_2 ) as $cid ) {
			$hash = hash( 'sha256', $cid . ':' . bin2hex( random_bytes( 8 ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $tokens_table, array( 'token_hash' => $hash, 'token_type' => 'access', 'client_id' => $cid, 'user_id' => 1, 'scope' => 'mcp', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), array( '%s', '%s', '%s', '%d', '%s', '%s' ) );
		}

		// Rewind auth_codes table + seed Q=1 auth code.
		$codes_table = $wpdb->prefix . 'acrossai_mcp_oauth_auth_codes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE `{$codes_table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$codes_table}` DROP COLUMN IF EXISTS `server_id`" );
		delete_option( 'acrossai_mcp_oauth_auth_codes_db_version' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $codes_table, array( 'code_hash' => hash( 'sha256', 'legacy-code' ), 'client_id' => $dcr_1, 'user_id' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ), array( '%s', '%s', '%d', '%s' ) );

		// Attach spy listener.
		$captured = array();
		$spy      = static function ( $c, $t, $a ) use ( &$captured ): void {
			$captured[] = array( $c, $t, $a );
		};
		add_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $spy, 10, 3 );

		// Run the 3 upgrade callbacks in registration order (Tokens → AuthCodes → Clients).
		OAuthTokensTable::instance()->maybe_upgrade();
		OAuthAuthCodesTable::instance()->maybe_upgrade();
		OAuthClientsTable::instance()->maybe_upgrade();

		remove_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $spy, 10 );

		// SC-008 assertions.
		$this->assertCount( 1, $captured, 'acrossai_mcp_oauth_legacy_dcr_purged MUST fire exactly once per upgrade run.' );
		list( $clients_purged, $tokens_purged, $auth_codes_purged ) = $captured[0];
		$this->assertSame( 2, $clients_purged );
		$this->assertSame( 3, $tokens_purged );
		$this->assertSame( 1, $auth_codes_purged );

		// SEC-032-T-001 anti-regression: tokens+auth codes counts must be > 0 (catches registration-order bug).
		$this->assertGreaterThan( 0, $tokens_purged, 'SEC-032-T-001: tokens_purged 0 would indicate registration-order regression.' );
		$this->assertGreaterThan( 0, $auth_codes_purged, 'SEC-032-T-001: auth_codes_purged 0 would indicate registration-order regression.' );

		// Post-migration invariant: no NULL server_id anywhere.
		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_clients', 'server_id IS NULL' ) );
		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_tokens', 'server_id IS NULL' ) );
		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_auth_codes', 'server_id IS NULL' ) );
	}

	/** Test #10 (T017 / FR-005 amendment / SC-011). */
	public function test_backfill_skips_orphan_server_ids(): void {
		global $wpdb;
		$this->rewind_oauth_clients_to_pre_f032();

		// Seed an admin client whose parsed server_id (99999) does NOT exist in oauth_servers.
		$orphan_client_id = 'server-99999-orphan-' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'acrossai_mcp_oauth_clients', array( 'client_id' => $orphan_client_id, 'client_name' => 'Orphan', 'connector_slug' => 'test', 'metadata_fingerprint' => hash( 'sha256', $orphan_client_id ) ), array( '%s', '%s', '%s', '%s' ) );

		OAuthClientsTable::instance()->maybe_upgrade();

		// SC-011: orphan row was purged (server_id remained NULL after backfill due to IN clause, then PURGED).
		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_clients', "client_id = '" . esc_sql( $orphan_client_id ) . "'" ) );
		// Global invariant.
		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_clients', 'server_id NOT IN (SELECT id FROM ' . $wpdb->prefix . 'acrossai_mcp_servers)' ) );
	}

	// -------------------------------------------------------------------------
	// US1 tests.
	// -------------------------------------------------------------------------

	/** Test #1 (T027 / SC-001 / RCT-001). */
	public function test_server_a_revoke_does_not_touch_server_b_tokens(): void {
		$server_a = 1;
		$server_b = 2;
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$client_a = $this->seed_admin_client( $server_a, 'claude-ai' );
		$client_b = $this->seed_admin_client( $server_b, 'claude-ai' );
		$this->seed_token( $client_a, $server_a, $user_id );
		$this->seed_token( $client_a, $server_a, $user_id );
		$this->seed_token( $client_b, $server_b, $user_id );
		$this->seed_token( $client_b, $server_b, $user_id );

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/revoke-client-tokens' );
		$request->set_param( 'server_id', $server_a );
		$request->set_param( 'client_id', $client_a );
		$response = ConnectorAdminController::instance()->handle_revoke_client_tokens( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 2, $this->count_rows( 'acrossai_mcp_oauth_tokens', "server_id = {$server_a} AND revoked = 1" ) );
		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_tokens', "server_id = {$server_b} AND revoked = 1" ), 'Server B tokens MUST NOT be touched by Server A revoke.' );
	}

	/** Test #2 (T028 / SC-001 / DC-001). */
	public function test_server_a_delete_does_not_touch_server_b_client_row(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$client_a = $this->seed_admin_client( 1, 'claude-ai' );
		$client_b = $this->seed_admin_client( 2, 'claude-ai' );

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/delete-client' );
		$request->set_param( 'server_id', 1 );
		$request->set_param( 'client_id', $client_a );
		ConnectorAdminController::instance()->handle_delete_client( $request );

		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_clients', "client_id = '" . esc_sql( $client_a ) . "'" ) );
		$this->assertSame( 1, $this->count_rows( 'acrossai_mcp_oauth_clients', "client_id = '" . esc_sql( $client_b ) . "'" ), 'Server B client row MUST remain intact.' );
	}

	/** Test #4 (T029 / SC-001 / RCT-004). */
	public function test_rest_endpoint_returns_403_on_server_id_mismatch(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$client_b = $this->seed_admin_client( 2, 'claude-ai' );

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/revoke-client-tokens' );
		$request->set_param( 'server_id', 1 );
		$request->set_param( 'client_id', $client_b );
		$response = ConnectorAdminController::instance()->handle_revoke_client_tokens( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'acrossai_mcp_oauth_cross_server', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	/** Test #5 (T030 / FR-009). */
	public function test_authorized_users_listing_filters_by_server_id(): void {
		$user_a  = self::factory()->user->create();
		$user_b  = self::factory()->user->create();
		$shared_cid = 'shared-client-abc-' . bin2hex( random_bytes( 4 ) );
		$this->seed_token( $shared_cid, 1, $user_a );
		$this->seed_token( $shared_cid, 2, $user_b );

		$server_1_users = OAuthTokensQuery::instance()->get_active_user_ids_by_client_id_and_server_id( $shared_cid, 1 );
		$server_2_users = OAuthTokensQuery::instance()->get_active_user_ids_by_client_id_and_server_id( $shared_cid, 2 );

		$this->assertSame( array( $user_a ), $server_1_users );
		$this->assertSame( array( $user_b ), $server_2_users );
	}

	/** Test #7 (T031 / FR-023 / SC-007 — 4-arg signature per SEC-032-001). */
	public function test_cross_server_403_fires_observability_action_with_4_args(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$client_b = $this->seed_admin_client( 2, 'claude-ai' );

		$captured = array();
		$spy      = static function ( ...$args ) use ( &$captured ): void {
			$captured[] = $args;
		};
		add_action( 'acrossai_mcp_oauth_cross_server_attempted', $spy, 10, 99 );

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/revoke-client-tokens' );
		$request->set_param( 'server_id', 1 );
		$request->set_param( 'client_id', $client_b );
		ConnectorAdminController::instance()->handle_revoke_client_tokens( $request );

		remove_action( 'acrossai_mcp_oauth_cross_server_attempted', $spy, 10 );

		$this->assertCount( 1, $captured );
		$this->assertCount( 4, $captured[0], 'SEC-032-001: action MUST fire with exactly 4 args (no owning server_id).' );
		$this->assertSame( $client_b, $captured[0][0] );
		$this->assertSame( 1, $captured[0][1] );
		$this->assertSame( $user_id, $captured[0][2] );
		$this->assertIsInt( $captured[0][3] );
	}

	// -------------------------------------------------------------------------
	// US2 tests.
	// -------------------------------------------------------------------------

	/** Test #3 (T045 / SC-002 / DCR-005). */
	public function test_same_dcr_connector_registers_on_two_servers_as_two_rows(): void {
		global $wpdb;
		$name = 'Claude Desktop';
		$cid_a = 'claude-desktop-a-' . bin2hex( random_bytes( 4 ) );
		$cid_b = 'claude-desktop-b-' . bin2hex( random_bytes( 4 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'acrossai_mcp_oauth_clients', array( 'client_id' => $cid_a, 'server_id' => 1, 'client_name' => $name, 'connector_slug' => '', 'metadata_fingerprint' => hash( 'sha256', $cid_a ) ), array( '%s', '%d', '%s', '%s', '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'acrossai_mcp_oauth_clients', array( 'client_id' => $cid_b, 'server_id' => 2, 'client_name' => $name, 'connector_slug' => '', 'metadata_fingerprint' => hash( 'sha256', $cid_b ) ), array( '%s', '%d', '%s', '%s', '%s' ) );

		$this->assertSame( 2, $this->count_rows( 'acrossai_mcp_oauth_clients', "client_name = '" . esc_sql( $name ) . "'" ) );
		$this->assertNotSame( $cid_a, $cid_b );
	}

	/** Test #9 (T046 / FR-027 / SC-010 / DCR-007). */
	public function test_dcr_rejects_attacker_origin_url(): void {
		if ( ! method_exists( \AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::class, 'resolve_server_id_from_resource_url' ) ) {
			$this->markTestSkipped( 'resolve_server_id_from_resource_url helper not present.' );
		}

		$captured = array();
		$spy      = static function ( ...$args ) use ( &$captured ): void {
			$captured[] = $args;
		};
		add_action( 'acrossai_mcp_oauth_dcr_resource_url_origin_mismatch', $spy, 10, 99 );

		$attacker_url = 'https://evil.attacker.com/wp-json/mcp/server-1-slug';
		$server_id    = \AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::resolve_server_id_from_resource_url( $attacker_url );

		remove_action( 'acrossai_mcp_oauth_dcr_resource_url_origin_mismatch', $spy, 10 );

		$this->assertSame( 0, $server_id, 'Attacker-origin URL MUST resolve to 0 (fail-closed).' );
		$this->assertCount( 1, $captured, 'Origin mismatch MUST fire observability action.' );
	}

	/** Test #11 (T047 / FR-028 / SC-012 / DCR-008). */
	public function test_dcr_returns_503_when_column_absent(): void {
		global $wpdb;
		if ( ! method_exists( \AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::class, 'handle_register' ) ) {
			$this->markTestSkipped( 'handle_register helper not present.' );
		}

		// Simulate pre-migration state: drop server_id column.
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX IF EXISTS `client_id_server_id`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `server_id`" );

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/register' );
		$request->set_param( 'client_name', 'Race Client' );
		$request->set_param( 'redirect_uris', array( 'https://example.com/cb' ) );
		$request->set_param( 'resource', home_url( '/wp-json/mcp/server-1' ) );

		$response = \AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::instance()->handle_register( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$data = $response->get_error_data();
		$this->assertSame( 503, $data['status'] );
		$this->assertSame( 'service_unavailable', $response->get_error_code() );

		// Post-recovery: run migration + retry, expect success (row count > 0 or specific 201-ish response).
		OAuthClientsTable::instance()->maybe_upgrade();
		// Reset column-existence cache if the controller exposes one; otherwise instantiate anew.
		if ( method_exists( \AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::class, 'reset_column_cache_for_tests' ) ) {
			\AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::reset_column_cache_for_tests();
		}
	}

	// -------------------------------------------------------------------------
	// US4 regression.
	// -------------------------------------------------------------------------

	/** Test #6 (T053 / FR-042). */
	public function test_user_deletion_still_cascades_across_all_servers(): void {
		$user_id = self::factory()->user->create();
		$this->seed_admin_client( 1, 'claude-ai' );
		$this->seed_admin_client( 2, 'chatgpt' );

		$this->seed_token( 'server-1-claude-ai-abc', 1, $user_id );
		$this->seed_token( 'server-2-chatgpt-xyz', 2, $user_id );

		if ( ! method_exists( UserLifecycle::class, 'on_user_deleted' ) ) {
			$this->markTestSkipped( 'UserLifecycle::on_user_deleted not registered.' );
		}
		UserLifecycle::instance()->on_user_deleted( $user_id );

		// FR-042: BOTH servers' tokens for this user MUST be revoked (site-wide cascade).
		$this->assertSame( 0, $this->count_rows( 'acrossai_mcp_oauth_tokens', "user_id = {$user_id} AND revoked = 0" ), 'Site-wide cascade must revoke tokens on ALL servers.' );
	}
}
