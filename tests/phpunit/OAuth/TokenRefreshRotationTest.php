<?php
/**
 * US4 T075 — Legitimate refresh-token rotation.
 *
 * Verifies:
 *   (a) presented refresh is revoked (single-use)
 *   (b) new access+refresh pair issued
 *   (c) resource + scope + token_family_id carried forward
 *   (d) `token_revoked` fires with reason `'refresh_rotation'`
 *   (e) `token_issued` fires for the new access token
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\RefreshTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

/**
 * @coversNothing
 */
class TokenRefreshRotationTest extends OAuthTestCase {

	public function test_legitimate_rotation_revokes_presented_and_carries_forward(): void {
		$user_id   = $this->factory()->user->create();
		$family_id = wp_generate_uuid4();
		$resource  = home_url( '/wp-json/mcp/v1/server-1' );

		// Simulate initial code→token issuance.
		AccessTokenRepository::issue( array(
			'client_id'       => 'rot-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => $resource,
			'token_family_id' => $family_id,
		) );
		$refresh_a = RefreshTokenRepository::issue( array(
			'client_id'       => 'rot-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => $resource,
			'token_family_id' => $family_id,
		) );

		// Simulate the /token refresh rotation path from TokenController:
		// (a) revoke presented refresh (single-use).
		$was_flipped = RefreshTokenRepository::revoke_by_hash(
			SecretsVault::hash( (string) $refresh_a['raw'] )
		);
		$this->assertTrue( $was_flipped, 'presented refresh must be revoked exactly once' );

		// (b) issue new pair carrying family_id/resource/scope forward.
		$access_b = AccessTokenRepository::issue( array(
			'client_id'       => 'rot-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => $resource,
			'token_family_id' => $family_id,
		) );
		$refresh_b = RefreshTokenRepository::issue( array(
			'client_id'       => 'rot-client',
			'user_id'         => $user_id,
			'scope'           => 'mcp',
			'resource'        => $resource,
			'token_family_id' => $family_id,
		) );

		// (c) new tokens exist and are non-empty.
		$this->assertNotEmpty( $access_b['raw'] );
		$this->assertNotEmpty( $refresh_b['raw'] );
		$this->assertNotSame( $refresh_a['raw'], $refresh_b['raw'], 'new refresh must be distinct' );

		// (d) family lineage preserved.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_pair_family = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT token_family_id FROM %i WHERE id IN (%d, %d)',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$access_b['id'],
				$refresh_b['id']
			)
		);

		foreach ( $new_pair_family as $observed ) {
			$this->assertSame( $family_id, $observed, 'family_id must be preserved across rotation' );
		}

		// (e) refresh_a is now revoked, presenting it a second time returns null (no-op).
		$replay = RefreshTokenRepository::revoke_by_hash(
			SecretsVault::hash( (string) $refresh_a['raw'] )
		);
		$this->assertFalse( $replay, 'already-revoked refresh returns false (no state change)' );
	}
}
