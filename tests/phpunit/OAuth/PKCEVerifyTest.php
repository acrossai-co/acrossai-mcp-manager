<?php
/**
 * PKCE::verify_s256 correctness — RFC 7636 Appendix B vectors.
 *
 * The Appendix B canonical example:
 *   code_verifier  = "dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"
 *   code_challenge = "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM"
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\PKCE;

/**
 * @coversNothing
 */
class PKCEVerifyTest extends OAuthTestCase {

	private const RFC_VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	public function test_rfc_7636_appendix_b_vector_passes(): void {
		$this->assertTrue( PKCE::verify_s256( self::RFC_VERIFIER, self::RFC_CHALLENGE ) );
	}

	public function test_single_byte_tampering_fails(): void {
		$tampered = self::RFC_CHALLENGE;
		$tampered = ( 'M' === $tampered[0] ) ? 'X' . substr( $tampered, 1 ) : 'X' . substr( $tampered, 1 );
		$this->assertFalse( PKCE::verify_s256( self::RFC_VERIFIER, $tampered ) );
	}

	public function test_wrong_verifier_fails(): void {
		$this->assertFalse( PKCE::verify_s256( str_repeat( 'a', 43 ), self::RFC_CHALLENGE ) );
	}

	public function test_verifier_shorter_than_43_rejected(): void {
		$this->assertFalse( PKCE::verify_s256( str_repeat( 'a', 42 ), self::RFC_CHALLENGE ) );
	}

	public function test_verifier_longer_than_128_rejected(): void {
		$this->assertFalse( PKCE::verify_s256( str_repeat( 'a', 129 ), self::RFC_CHALLENGE ) );
	}

	public function test_challenge_not_43_chars_rejected(): void {
		$this->assertFalse( PKCE::verify_s256( self::RFC_VERIFIER, substr( self::RFC_CHALLENGE, 0, 42 ) ) );
	}

	public function test_is_s256_helper(): void {
		$this->assertTrue( PKCE::is_s256( 'S256' ) );
		$this->assertFalse( PKCE::is_s256( 'plain' ) );
		$this->assertFalse( PKCE::is_s256( 's256' ) );
		$this->assertFalse( PKCE::is_s256( '' ) );
	}
}
