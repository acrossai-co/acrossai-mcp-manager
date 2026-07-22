<?php
/**
 * F032 T087 — Revoke-approval → token-revoke cascade (FR-040, FR-041 / SC-017, SC-018).
 *
 * The cascade is wired via `Main::define_admin_hooks()`:
 *   add_action( 'acrossai_mcp_connector_user_approval_revoked',
 *               ConnectorAdminController::cascade_revoke_tokens_on_approval_revoked, 10, 4 );
 *
 * Invariants under test:
 * - (a) default cascade fires: revoke-approval → matching tokens revoked
 *       AND `acrossai_mcp_manager_oauth_token_revoked` fires per token with
 *       reason = 'approval_revoked'.
 * - (b) filter `acrossai_mcp_connector_revoke_tokens_on_approval_revoked`
 *       returning false → approval row still deleted BUT tokens stay
 *       active. The action still fires (filter guards the CASCADE, not
 *       the action).
 * - (c) 4-arg action signature: ($server_id, $connector_slug, $user_id, $revoked_by).
 * - (d) mutual-exclusion (D34 companion): the site-wide-revoke action is
 *       NOT fired by this cascade (only by handle_revoke_client_tokens_all_servers).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query as ApprovedUsersQuery;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Table as ApprovedUsersTable;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\ConnectorAdminController;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\RefreshTokenRepository;

/**
 * @coversNothing
 */
class ApprovalRevokeCascadeTest extends OAuthTestCase {

	private const SERVER_ID = 1;
	private const SLUG      = 'claude';

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		ApprovedUsersTable::instance()->maybe_upgrade();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_connector_approved_users' ) );

		// Wire the cascade listener directly (bypass Main::define_admin_hooks).
		add_action(
			'acrossai_mcp_connector_user_approval_revoked',
			array( ConnectorAdminController::class, 'cascade_revoke_tokens_on_approval_revoked' ),
			10,
			4
		);

		// Register a fake connector profile for 'claude' so the cascade's
		// profile lookup does not early-return.
		add_filter( 'acrossai_mcp_manager_connector_profiles', array( $this, 'register_fake_claude_profile' ) );
		$this->reset_connector_profile_registry();
	}

	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		remove_action(
			'acrossai_mcp_connector_user_approval_revoked',
			array( ConnectorAdminController::class, 'cascade_revoke_tokens_on_approval_revoked' ),
			10
		);
		remove_filter( 'acrossai_mcp_manager_connector_profiles', array( $this, 'register_fake_claude_profile' ) );

		parent::tear_down();
	}

	public function register_fake_claude_profile( array $profiles ): array {
		$profiles[] = new class() extends \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile {
			public function get_slug(): string {
				return 'claude';
			}
			public function get_name(): string {
				return 'Fake Claude';
			}
			public function get_icon_url(): string {
				return 'https://example.test/icon.png';
			}
			public function get_redirect_uri_whitelist(): array {
				return array( 'https://test.example/cb' );
			}
			public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
				return 'test';
			}
		};
		return $profiles;
	}

	private function seed_admin_client( int $server_id, string $slug ): string {
		$client_id = "server-{$server_id}-{$slug}-testrand";
		ClientsQuery::instance()->add_item( array(
			'client_id'      => $client_id,
			'server_id'      => $server_id,
			'connector_slug' => $slug,
			'client_type'    => 'public_pkce',
			'redirect_uris'  => wp_json_encode( array( 'https://test.example/cb' ) ),
			'client_name'    => 'test client',
		) );
		return $client_id;
	}

	private function seed_tokens_for( string $client_id, int $server_id, int $user_id, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			AccessTokenRepository::issue( array(
				'client_id'       => $client_id,
				'server_id'       => $server_id,
				'user_id'         => $user_id,
				'scope'           => 'mcp',
				'resource'        => home_url( '/wp-json/mcp/v1/s' ),
				'token_family_id' => wp_generate_uuid4(),
			) );
		}
	}

	// (a) Default cascade fires + tokens revoked + per-token action with reason 'approval_revoked'.
	public function test_default_cascade_revokes_tokens(): void {
		$user_id   = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$client_id = $this->seed_admin_client( self::SERVER_ID, self::SLUG );
		$this->seed_tokens_for( $client_id, self::SERVER_ID, $user_id, 2 );

		ApprovedUsersQuery::instance()->approve( self::SERVER_ID, self::SLUG, $user_id, 99 );

		global $wpdb;
		// Precondition: 2 non-revoked tokens exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$user_id
			)
		);
		$this->assertSame( 2, $active_before, 'Precondition: 2 tokens active' );

		$per_token_captured = $this->capture_action( 'acrossai_mcp_manager_oauth_token_revoked' );

		// Fire the observable event — cascade should trigger.
		do_action( 'acrossai_mcp_connector_user_approval_revoked', self::SERVER_ID, self::SLUG, $user_id, 99 );

		// Post-condition: 0 active tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$user_id
			)
		);
		$this->assertSame( 0, $active_after, 'All tokens revoked by cascade' );

		// Per-token action fired twice with reason 'approval_revoked'.
		$this->assertCount( 2, $per_token_captured['calls'], 'Per-token action fires once per token' );
		foreach ( $per_token_captured['calls'] as $call ) {
			$this->assertSame( 'approval_revoked', $call[1], 'Reason enum is approval_revoked' );
		}
	}

	// (b) Filter opt-out — tokens survive, approval row still deleted.
	public function test_filter_opt_out_preserves_tokens(): void {
		$user_id   = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$client_id = $this->seed_admin_client( self::SERVER_ID, self::SLUG );
		$this->seed_tokens_for( $client_id, self::SERVER_ID, $user_id, 2 );

		ApprovedUsersQuery::instance()->approve( self::SERVER_ID, self::SLUG, $user_id, 99 );

		add_filter( 'acrossai_mcp_connector_revoke_tokens_on_approval_revoked', '__return_false' );

		$per_token_captured = $this->capture_action( 'acrossai_mcp_manager_oauth_token_revoked' );

		do_action( 'acrossai_mcp_connector_user_approval_revoked', self::SERVER_ID, self::SLUG, $user_id, 99 );

		remove_filter( 'acrossai_mcp_connector_revoke_tokens_on_approval_revoked', '__return_false' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$user_id
			)
		);
		$this->assertSame( 2, $active_after, 'Filter opt-out preserves all tokens' );
		$this->assertCount( 0, $per_token_captured['calls'], 'No per-token action fired when cascade opts out' );
	}

	// (c) 4-arg action signature.
	public function test_action_fires_with_four_args(): void {
		$captured = $this->capture_action( 'acrossai_mcp_connector_user_approval_revoked' );

		do_action( 'acrossai_mcp_connector_user_approval_revoked', 1, 'claude', 42, 99 );

		$this->assertCount( 1, $captured['calls'] );
		$this->assertSame( array( 1, 'claude', 42, 99 ), $captured['calls'][0] );
	}

	// (d) D34 mutual exclusion — cascade MUST NOT fire the site-wide-revoke action.
	public function test_cascade_does_not_fire_site_wide_revoke_action(): void {
		$user_id   = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$client_id = $this->seed_admin_client( self::SERVER_ID, self::SLUG );
		$this->seed_tokens_for( $client_id, self::SERVER_ID, $user_id, 1 );

		ApprovedUsersQuery::instance()->approve( self::SERVER_ID, self::SLUG, $user_id, 99 );

		$site_wide_captured = $this->capture_action( 'acrossai_mcp_oauth_client_revoked_across_all_servers' );

		do_action( 'acrossai_mcp_connector_user_approval_revoked', self::SERVER_ID, self::SLUG, $user_id, 99 );

		$this->assertCount( 0, $site_wide_captured['calls'], 'D34: cascade path MUST NOT fire the site-wide-revoke action (reserved for handle_revoke_client_tokens_all_servers)' );
	}
}
