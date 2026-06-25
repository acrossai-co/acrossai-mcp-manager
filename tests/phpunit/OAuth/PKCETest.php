<?php
/**
 * PKCE S256 conformance — RFC 7636 §B golden vectors + verifier length.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\PKCE;
use PHPUnit\Framework\TestCase;

class PKCETest extends TestCase {

	public function test_rfc7636_golden_vector(): void {
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
		$this->assertSame( $challenge, ( new PKCE() )->compute_challenge( $verifier ) );
	}

	public function test_verify_returns_true_for_matching_pair(): void {
		$pkce      = new PKCE();
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = $pkce->compute_challenge( $verifier );
		$this->assertTrue( $pkce->verify( $verifier, $challenge ) );
	}

	public function test_verify_returns_false_for_wrong_verifier(): void {
		$pkce      = new PKCE();
		$challenge = $pkce->compute_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' );
		// Different verifier (still 43 chars to pass length validation).
		$other = 'eBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$this->assertFalse( $pkce->verify( $other, $challenge ) );
	}

	public function test_short_verifier_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new PKCE() )->validate_verifier_length( str_repeat( 'a', 42 ) );
	}

	public function test_long_verifier_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new PKCE() )->validate_verifier_length( str_repeat( 'a', 129 ) );
	}

	public function test_boundary_lengths_accepted(): void {
		$pkce = new PKCE();
		$pkce->validate_verifier_length( str_repeat( 'a', 43 ) );
		$pkce->validate_verifier_length( str_repeat( 'a', 128 ) );
		$this->assertTrue( true );
	}
}
