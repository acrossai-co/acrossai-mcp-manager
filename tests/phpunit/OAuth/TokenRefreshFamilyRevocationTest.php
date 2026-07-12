<?php
/**
 * SEC-021-001 — RFC 9700 §2.2.2 refresh token family revocation.
 *
 * Threat model: attacker exfiltrates a refresh token. On presenting the
 * stolen refresh, plugin issues fresh access + refresh (attacker now has
 * new pair). When the LEGITIMATE client's next refresh call arrives with
 * the old (now-revoked) token, the plugin detects reuse and bulk-revokes
 * every token in the family — including the attacker's fresh pair. Attacker
 * window collapses from refresh-TTL (30d) to access-TTL (3600s).
 *
 * This test asserts the reuse-detection path directly against the
 * Repository layer (equivalent behaviour to what TokenController triggers
 * on a refresh reuse).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\RefreshTokenRepository;

/**
 * @coversNothing
 */
class TokenRefreshFamilyRevocationTest extends OAuthTestCase {

	public function test_reuse_detection_revokes_entire_family(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		// Simulate an original code→token exchange establishing family F1.
		$family_id = wp_generate_uuid4();

		$access_a = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => $family_id,
		) );
		$refresh_a = RefreshTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => $family_id,
		) );

		// Legitimate rotation → refresh_a is revoked, refresh_b + access_b issued (same family).
		RefreshTokenRepository::revoke_by_hash( hash( 'sha256', (string) $refresh_a['raw'] ) );

		$access_b = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => $family_id,
		) );
		$refresh_b = RefreshTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => $family_id,
		) );

		// Now: attacker presents refresh_a (already revoked). Detection path triggers
		// the family bulk-revoke — every non-revoked token in F1 dies.
		$revoked_ids = RefreshTokenRepository::revoke_by_family_id( $family_id );

		// access_b + refresh_b should have been in the revoked list (access_a and
		// refresh_a were already revoked before the family sweep).
		$this->assertContains( (int) $access_b['id'], $revoked_ids );
		$this->assertContains( (int) $refresh_b['id'], $revoked_ids );

		// Verify DB state: NOTHING in family F1 is still active.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$still_active = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE token_family_id = %s AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$family_id
			)
		);
		$this->assertSame( 0, $still_active, 'SEC-021-001 family revocation failed to close the window' );

		// Also verify: OTHER families are untouched.
		$other_family = wp_generate_uuid4();
		AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => $other_family,
		) );

		$this->assertGreaterThan( 0, $this->count_active_in_family( $other_family ) );
	}

	public function test_family_revoke_guards_against_empty_family_id(): void {
		// Empty string MUST NOT wipe every legacy row.
		$ids = RefreshTokenRepository::revoke_by_family_id( '' );
		$this->assertSame( array(), $ids );
	}

	public function test_family_revoke_rejects_wrong_length_family_id(): void {
		// Non-UUIDv4 length also rejected as defense-in-depth.
		$ids = RefreshTokenRepository::revoke_by_family_id( 'not-a-uuid' );
		$this->assertSame( array(), $ids );
	}

	private function count_active_in_family( string $family_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE token_family_id = %s AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$family_id
			)
		);
	}
}
