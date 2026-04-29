<?php
/**
 * OAuth token validator — validates Bearer tokens on incoming MCP requests.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

use ACROSSAI_MCP_MANAGER\Database\OAuthTokensTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates Bearer access tokens and extracts the authenticated user.
 *
 * @since 1.6.0
 */
class TokenValidator {

	/**
	 * Extract the Bearer token string from a REST request's Authorization header.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 *
	 * @return string|null Plaintext token or null if absent / not Bearer format.
	 */
	public static function extract_bearer( \WP_REST_Request $request ) {
		$header = $request->get_header( 'Authorization' );
		if ( ! $header ) {
			// Also check the server variable directly — some server configs strip headers.
			$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		if ( ! $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return null;
		}

		return trim( substr( $header, 7 ) );
	}

	/**
	 * Validate a Bearer token against the database.
	 *
	 * Checks: exists, not expired, audience matches the MCP server URI.
	 *
	 * @param string $token_plaintext Plaintext token value.
	 * @param string $audience        Canonical MCP server URI this route represents.
	 *
	 * @return int|false WP user ID on success, false on any validation failure.
	 */
	public static function validate( $token_plaintext, $audience ) {
		if ( empty( $token_plaintext ) ) {
			return false;
		}

		$hash = hash( 'sha256', $token_plaintext );
		$row  = OAuthTokensTable::get( $hash, 'access' );

		if ( ! $row ) {
			return false;
		}

		// Audience binding — token must be issued for this exact server URI.
		if ( $row['audience'] !== $audience ) {
			return false;
		}

		$user = get_user_by( 'id', (int) $row['user_id'] );
		if ( ! $user ) {
			return false;
		}

		return (int) $row['user_id'];
	}

	/**
	 * Generate a cryptographically random token string with the given prefix.
	 *
	 * @param string $prefix E.g. 'mcpat_' for access tokens, 'mcprt_' for refresh.
	 *
	 * @return string Plaintext token (32 random bytes → 64 hex chars after prefix).
	 */
	public static function generate( $prefix = 'mcpat_' ) {
		return $prefix . bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Return the SHA-256 hash used for storage.
	 *
	 * @param string $token_plaintext Plaintext token.
	 *
	 * @return string 64-char hex string.
	 */
	public static function hash( $token_plaintext ) {
		return hash( 'sha256', $token_plaintext );
	}
}
