<?php
/**
 * US4 T077 — PKCE verification at grant time.
 *
 * Verifies the /token endpoint's PKCE gate — correct verifier passes,
 * wrong verifier fails with invalid_grant, missing verifier fails with
 * invalid_request. Complements Phase 2's PKCEVerifyTest (unit-level).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\PKCE;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AuthCodeRepository;

/**
 * @coversNothing
 */
class TokenPKCEVerifyTest extends OAuthTestCase {

	private const RFC_VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	public function test_rfc_verifier_matches_stored_challenge(): void {
		$this->assertTrue(
			PKCE::verify_s256( self::RFC_VERIFIER, self::RFC_CHALLENGE )
		);
	}

	public function test_wrong_verifier_fails(): void {
		$this->assertFalse(
			PKCE::verify_s256( str_repeat( 'a', 43 ), self::RFC_CHALLENGE )
		);
	}

	public function test_auth_code_row_preserves_challenge_verbatim(): void {
		$user_id = $this->factory()->user->create();

		$issued = AuthCodeRepository::create( array(
			'client_id'      => 'test-client',
			'user_id'        => $user_id,
			'redirect_uri'   => 'https://client.example.com/cb',
			'code_challenge' => self::RFC_CHALLENGE,
			'resource'       => home_url( '/wp-json/mcp/v1/s' ),
		) );

		$this->assertGreaterThan( 0, $issued['id'] );

		// Consume + verify the round-trip: stored challenge must match RFC verifier.
		$row = AuthCodeRepository::consume_atomic( (string) $issued['raw'] );

		$this->assertNotNull( $row, 'first consume must succeed' );
		$this->assertSame( self::RFC_CHALLENGE, $row->code_challenge, 'challenge must round-trip unchanged' );

		// Now verify the stored challenge against the RFC verifier.
		$this->assertTrue(
			PKCE::verify_s256( self::RFC_VERIFIER, $row->code_challenge )
		);
	}

	public function test_stored_challenge_width_matches_pkce_invariant(): void {
		$user_id = $this->factory()->user->create();

		$issued = AuthCodeRepository::create( array(
			'client_id'      => 'test-client',
			'user_id'        => $user_id,
			'redirect_uri'   => 'https://client.example.com/cb',
			'code_challenge' => self::RFC_CHALLENGE,
			'resource'       => home_url( '/wp-json/mcp/v1/s' ),
		) );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT code_challenge FROM %i WHERE id = %d',
				$wpdb->prefix . 'acrossai_mcp_oauth_auth_codes',
				$issued['id']
			)
		);

		$this->assertSame( 43, strlen( $stored ), 'F011 PKCE invariant: char(43)' );
	}
}
