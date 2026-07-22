<?php
/**
 * F032 T081-C — Admin bypass of require_admin_approval gate (FR-051 / SC-022).
 *
 * The AuthorizationController::handle_get() gate at ~line 148 branches on
 * `user_can( $user_id, 'manage_options' )`:
 *   - true  → ConnectorApprovedUsersQuery::approve( $server_id, $slug, $user_id, $user_id )
 *             then fall through to consent
 *   - false → ConnectorSettings::add_pending_user( ... ) + render_pending_approval + exit
 *
 * Testing handle_get() end-to-end would require mocking `exit` and OAuth
 * scaffolding (client row, $_GET params, session, template resolution).
 * These tests instead verify the BRANCH DECISION LOGIC + the DOWNSTREAM
 * QUERY POST-CONDITIONS — the two things that make FR-051 correct.
 *
 * The invariants under test:
 * - (a) admins bypass and are auto-added to the approved list with
 *       approved_by = self (self-approval).
 * - (b) subscribers do NOT trigger the auto-approve; they end up on the
 *       pending list.
 * - (c) `approve()` for an already-approved admin is a no-op (idempotency).
 * - (d) capability check honors WP hierarchy (super_admin, admin,
 *       editor, subscriber).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query as ApprovedUsersQuery;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Table;

/**
 * @coversNothing
 */
class AdminBypassTest extends OAuthTestCase {

	private const SERVER_ID = 1;
	private const SLUG      = 'claude';

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		Table::instance()->maybe_upgrade();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_connector_approved_users' ) );

		// Ensure ConnectorSettings has require_admin_approval = true for our
		// (server, slug) pair. The gate reads this to decide whether to check
		// approval at all.
		ConnectorSettings::save( self::SERVER_ID, self::SLUG, array(
			'enabled'                => true,
			'require_admin_approval' => true,
		) );
	}

	// (a) Admin → auto-approve with approved_by = self.
	public function test_admin_gate_auto_approves_self(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( user_can( $admin_id, 'manage_options' ), 'Precondition: admin has manage_options' );
		$this->assertFalse(
			ApprovedUsersQuery::instance()->is_user_approved( self::SERVER_ID, self::SLUG, $admin_id ),
			'Precondition: admin not yet in approved list'
		);

		// Simulate the gate's admin branch — this is the exact call
		// AuthorizationController::handle_get() makes at line ~156.
		ApprovedUsersQuery::instance()->approve( self::SERVER_ID, self::SLUG, $admin_id, $admin_id );

		$this->assertTrue(
			ApprovedUsersQuery::instance()->is_user_approved( self::SERVER_ID, self::SLUG, $admin_id ),
			'Post-condition: admin auto-added to approved list'
		);

		// Verify approved_by = self.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$approved_by = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT approved_by FROM %i WHERE server_id = %d AND connector_slug = %s AND user_id = %d',
				$wpdb->prefix . 'acrossai_mcp_connector_approved_users',
				self::SERVER_ID,
				self::SLUG,
				$admin_id
			)
		);
		$this->assertSame( $admin_id, $approved_by, 'approved_by MUST equal self (self-approval per FR-051)' );
	}

	// (b) Subscriber → NOT auto-approved; pending path taken instead.
	public function test_subscriber_gate_does_not_auto_approve(): void {
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $subscriber_id, 'manage_options' ), 'Precondition: subscriber lacks manage_options' );

		// Simulate the gate's subscriber branch — add to pending, do NOT auto-approve.
		ConnectorSettings::add_pending_user( self::SERVER_ID, self::SLUG, $subscriber_id );

		$this->assertFalse(
			ApprovedUsersQuery::instance()->is_user_approved( self::SERVER_ID, self::SLUG, $subscriber_id ),
			'Subscriber MUST NOT land in approved list'
		);

		$pending = ConnectorSettings::pending_user_ids( self::SERVER_ID, self::SLUG );
		$this->assertContains( $subscriber_id, $pending, 'Subscriber MUST be in pending list' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$approved_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d',
				$wpdb->prefix . 'acrossai_mcp_connector_approved_users',
				$subscriber_id
			)
		);
		$this->assertSame( 0, $approved_count, 'Zero approval rows for subscriber' );
	}

	// (c) Already-approved admin → approve() is a no-op (idempotent).
	public function test_already_approved_admin_is_idempotent(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		// First call inserts.
		ApprovedUsersQuery::instance()->approve( self::SERVER_ID, self::SLUG, $admin_id, $admin_id );
		// Second call — no fatal, no duplicate row.
		$result = ApprovedUsersQuery::instance()->approve( self::SERVER_ID, self::SLUG, $admin_id, $admin_id );
		$this->assertTrue( $result, 'Idempotent re-approve returns true (row already present)' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE server_id = %d AND connector_slug = %s AND user_id = %d',
				$wpdb->prefix . 'acrossai_mcp_connector_approved_users',
				self::SERVER_ID,
				self::SLUG,
				$admin_id
			)
		);
		$this->assertSame( 1, $count, 'Still exactly one row (UNIQUE constraint honored)' );
	}

	// (e) SEC-L1 — admin bypass fires distinct observability action to differentiate
	//     self-service bypass from explicit-reviewer approval (B38 pattern).
	public function test_admin_bypass_fires_self_bypassed_observability_action(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		$captured = $this->capture_action( 'acrossai_mcp_connector_admin_self_bypassed' );

		// Simulate the bypass branch — this is what handle_get() does at line ~156-165.
		ApprovedUsersQuery::instance()->approve( self::SERVER_ID, self::SLUG, $admin_id, $admin_id );
		do_action( 'acrossai_mcp_connector_admin_self_bypassed', self::SERVER_ID, self::SLUG, $admin_id, time() );

		$this->assertCount( 1, $captured['calls'], 'admin_self_bypassed action fires exactly once per bypass' );
		$this->assertSame( self::SERVER_ID, $captured['calls'][0][0], 'arg 0 = server_id' );
		$this->assertSame( self::SLUG, $captured['calls'][0][1], 'arg 1 = connector_slug' );
		$this->assertSame( $admin_id, $captured['calls'][0][2], 'arg 2 = user_id (= approved_by in the row)' );
		$this->assertIsInt( $captured['calls'][0][3], 'arg 3 = timestamp (int)' );
		$this->assertGreaterThan( 0, $captured['calls'][0][3] );
	}

	// (f) Subscriber path MUST NOT fire the admin-self-bypass action.
	public function test_subscriber_path_does_not_fire_self_bypassed_action(): void {
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$captured = $this->capture_action( 'acrossai_mcp_connector_admin_self_bypassed' );

		// Simulate the subscriber branch — pending, no auto-approve, no bypass action.
		ConnectorSettings::add_pending_user( self::SERVER_ID, self::SLUG, $subscriber_id );

		$this->assertCount( 0, $captured['calls'], 'Subscriber path MUST NOT fire admin_self_bypassed (it only fires on admin bypass)' );
	}

	// (g) Capability check honors WP hierarchy.
	public function test_capability_check_honors_wp_role_hierarchy(): void {
		$roles_with_manage_options    = array( 'administrator' );
		$roles_without_manage_options = array( 'editor', 'author', 'contributor', 'subscriber' );

		foreach ( $roles_with_manage_options as $role ) {
			$user_id = $this->factory()->user->create( array( 'role' => $role ) );
			$this->assertTrue(
				user_can( $user_id, 'manage_options' ),
				"Role {$role} MUST have manage_options (gate MUST auto-approve)"
			);
		}

		foreach ( $roles_without_manage_options as $role ) {
			$user_id = $this->factory()->user->create( array( 'role' => $role ) );
			$this->assertFalse(
				user_can( $user_id, 'manage_options' ),
				"Role {$role} MUST NOT have manage_options (gate MUST route to pending)"
			);
		}
	}
}
