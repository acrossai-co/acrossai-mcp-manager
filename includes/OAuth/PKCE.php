<?php
/**
 * PKCE S256 — RFC 7636 §4.6 + §4.2.
 *
 * Pure utility class. A11 carve-out applies: NO singleton — call sites
 * use `new PKCE()` because the class is state-free, hook-free, and side-
 * effect-free.
 *
 * @package AcrossAI_MCP_Manager\Includes\OAuth
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class PKCE {

	/**
	 * Constant-time PKCE verifier comparison.
	 *
	 * Compute `base64url(sha256($code_verifier))` and compare against the
	 * stored S256 challenge with hash_equals.
	 *
	 * @param string $code_verifier    Raw verifier from client.
	 * @param string $stored_challenge `code_challenge` persisted at authorize-time.
	 */
	public function verify( string $code_verifier, string $stored_challenge ): bool {
		$this->validate_verifier_length( $code_verifier );
		return hash_equals( $stored_challenge, $this->compute_challenge( $code_verifier ) );
	}

	/**
	 * Compute the S256 challenge for a verifier — exposed for tests
	 * (RFC 7636 §B golden vectors).
	 *
	 * @param string $code_verifier 43-128 char raw verifier.
	 */
	public function compute_challenge( string $code_verifier ): string {
		// base64_encode is the canonical RFC 7636 §4.2 challenge encoding —
		// this is not obfuscation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return strtr( rtrim( base64_encode( hash( 'sha256', $code_verifier, true ) ), '=' ), '+/', '-_' );
	}

	/**
	 * Reject verifiers outside the RFC 7636 §4.1 length window.
	 *
	 * @param string $code_verifier Verifier to length-check.
	 *
	 * @throws \InvalidArgumentException When the verifier is outside 43-128 chars.
	 */
	public function validate_verifier_length( string $code_verifier ): void {
		$len = strlen( $code_verifier );
		if ( $len < 43 || $len > 128 ) {
			throw new \InvalidArgumentException( 'code_verifier MUST be 43-128 chars (RFC 7636 §4.1).' );
		}
	}
}
