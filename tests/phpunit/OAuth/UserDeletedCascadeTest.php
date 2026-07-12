<?php
/**
 * US3 — FR-042 / Q4 WordPress user deletion cascade.
 *
 * Deleting a WP user MUST: (a) bulk-revoke all their tokens; (b) delete
 * all their pending auth codes; (c) fire
 * `acrossai_mcp_manager_oauth_token_revoked` per revoked token with
 * reason `'user_deleted'`.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AuthCodeRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\RefreshTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\UserLifecycle;

/**
 * @coversNothing
 */
class UserDeletedCascadeTest extends OAuthTestCase {

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		// Wire the cascade — the test doesn't rely on Main.php's Loader
		// registration, so add the action directly.
		add_action(
			'deleted_user',
			array( UserLifecycle::instance(), 'on_user_deleted' ),
			10
		);
	}

	public function test_deleted_user_revokes_tokens_and_fires_action_per_row(): void {
		$victim_user = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_user  = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		// Issue 2 access + 1 refresh for the victim, 1 access for the other.
		$family = wp_generate_uuid4();
		AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $victim_user,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => $family,
		) );
		AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $victim_user,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => wp_generate_uuid4(),
		) );
		RefreshTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $victim_user,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => $family,
		) );
		AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $other_user,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => wp_generate_uuid4(),
		) );

		// Issue a pending auth code for the victim.
		AuthCodeRepository::create( array(
			'client_id'      => 'test-client',
			'user_id'        => $victim_user,
			'redirect_uri'   => 'https://client.example.com/cb',
			'code_challenge' => str_repeat( 'a', 43 ),
			'resource'       => home_url( '/wp-json/mcp/v1/s' ),
		) );

		$captured = $this->capture_action( 'acrossai_mcp_manager_oauth_token_revoked' );

		wp_delete_user( $victim_user );

		global $wpdb;

		// (a) All 3 victim tokens flipped to revoked=1.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$victim_still_active = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$victim_user
			)
		);
		$this->assertSame( 0, $victim_still_active );

		// Other user's token untouched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$other_still_active = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$other_user
			)
		);
		$this->assertSame( 1, $other_still_active );

		// (b) Pending auth code deleted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$victim_codes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d',
				$wpdb->prefix . 'acrossai_mcp_oauth_auth_codes',
				$victim_user
			)
		);
		$this->assertSame( 0, $victim_codes );

		// (c) Action fired 3× with reason 'user_deleted'.
		$this->assertCount( 3, $captured['calls'] );
		foreach ( $captured['calls'] as $call ) {
			$this->assertSame( 'user_deleted', $call[1] );
			$this->assertIsInt( $call[0] );
			$this->assertGreaterThan( 0, $call[0] );
		}
	}

	public function test_deleting_user_with_no_tokens_is_no_op(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$captured = $this->capture_action( 'acrossai_mcp_manager_oauth_token_revoked' );

		wp_delete_user( $user_id );

		$this->assertCount( 0, $captured['calls'] );
	}
}
