<?php
/**
 * OAuth Token Endpoint — issues access and refresh tokens.
 *
 * POST /wp-json/acrossai-mcp-manager/v1/oauth/token
 *
 * Supports:
 *   grant_type=authorization_code  — exchange auth code for tokens (PKCE)
 *   grant_type=refresh_token       — rotate refresh token, issue new access token
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

use ACROSSAI_MCP_MANAGER\Database\OAuthClientsTable;
use ACROSSAI_MCP_MANAGER\Database\OAuthAuthCodesTable;
use ACROSSAI_MCP_MANAGER\Database\OAuthTokensTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles OAuth 2.1 token requests.
 *
 * @since 1.6.0
 */
class TokenEndpoint {

	/**
	 * REST callback — issue tokens.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( \WP_REST_Request $request ) {
		if ( ! (bool) get_option( 'acrossai_mcp_oauth_enabled', false ) ) {
			return new \WP_Error( 'feature_disabled', 'Claude.ai connector support is disabled.', array( 'status' => 404 ) );
		}

		$client = self::authenticate_client( $request );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$grant_type = $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			return self::handle_authorization_code( $request, $client );
		}

		if ( 'refresh_token' === $grant_type ) {
			return self::handle_refresh_token( $request, $client );
		}

		return self::token_error( 'unsupported_grant_type', 'Unsupported grant_type.' );
	}

	// -------------------------------------------------------------------------
	// Client authentication
	// -------------------------------------------------------------------------

	/**
	 * Authenticate the client via HTTP Basic auth header or POST body params.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return array|\WP_Error Client row on success, WP_Error on failure.
	 */
	private static function authenticate_client( \WP_REST_Request $request ) {
		$client_id     = '';
		$client_secret = '';

		// Try HTTP Basic auth: Authorization: Basic base64(client_id:client_secret)
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && 0 === strpos( $auth_header, 'Basic ' ) ) {
			$decoded = base64_decode( substr( $auth_header, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( $decoded && strpos( $decoded, ':' ) !== false ) {
				list( $client_id, $client_secret ) = explode( ':', $decoded, 2 );
			}
		}

		// Fall back to POST body params.
		if ( empty( $client_id ) ) {
			$client_id     = (string) $request->get_param( 'client_id' );
			$client_secret = (string) $request->get_param( 'client_secret' );
		}

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return self::token_error( 'invalid_client', 'Client credentials are required.' );
		}

		if ( ! OAuthClientsTable::verify_secret( $client_id, $client_secret ) ) {
			return self::token_error( 'invalid_client', 'Invalid client credentials.' );
		}

		return OAuthClientsTable::get( $client_id );
	}

	// -------------------------------------------------------------------------
	// Authorization code grant
	// -------------------------------------------------------------------------

	/**
	 * Exchange an authorization code for access + refresh tokens.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @param array            $client  Pre-authenticated client row.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function handle_authorization_code( \WP_REST_Request $request, array $client ) {
		$code          = $request->get_param( 'code' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );

		if ( empty( $code ) || empty( $redirect_uri ) ) {
			return self::token_error( 'invalid_request', 'Missing required parameters.' );
		}

		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			return self::token_error( 'invalid_grant', 'redirect_uri mismatch.' );
		}

		$code_hash = hash( 'sha256', $code );
		$code_row  = OAuthAuthCodesTable::get( $code_hash );

		if ( ! $code_row ) {
			return self::token_error( 'invalid_grant', 'Authorization code not found or expired.' );
		}

		if ( $code_row['client_id'] !== $client['client_id'] ) {
			return self::token_error( 'invalid_grant', 'Code was not issued to this client.' );
		}

		if ( $code_row['redirect_uri'] !== $redirect_uri ) {
			return self::token_error( 'invalid_grant', 'redirect_uri does not match.' );
		}

		// PKCE verification — only enforce when a challenge was stored (public clients / optional).
		if ( ! empty( $code_row['code_challenge'] ) ) {
			if ( empty( $code_verifier ) || ! self::verify_pkce( $code_verifier, $code_row['code_challenge'], $code_row['code_challenge_method'] ) ) {
				return self::token_error( 'invalid_grant', 'PKCE verification failed.' );
			}
		}

		// Consume the code immediately (single-use).
		OAuthAuthCodesTable::delete( $code_hash );

		return self::issue_token_pair(
			$client['client_id'],
			(int) $code_row['user_id'],
			$code_row['scope'],
			$code_row['resource']
		);
	}

	// -------------------------------------------------------------------------
	// Refresh token grant
	// -------------------------------------------------------------------------

	/**
	 * Rotate a refresh token and issue a new access token.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @param array            $client  Pre-authenticated client row.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function handle_refresh_token( \WP_REST_Request $request, array $client ) {
		$refresh_token = $request->get_param( 'refresh_token' );

		if ( empty( $refresh_token ) ) {
			return self::token_error( 'invalid_request', 'Missing refresh_token.' );
		}

		$hash = hash( 'sha256', $refresh_token );
		$row  = OAuthTokensTable::get( $hash, 'refresh' );

		if ( ! $row ) {
			return self::token_error( 'invalid_grant', 'Refresh token not found or expired.' );
		}

		if ( $row['client_id'] !== $client['client_id'] ) {
			return self::token_error( 'invalid_grant', 'Refresh token was not issued to this client.' );
		}

		// Rotate: delete existing tokens for this client+user, issue new pair.
		OAuthTokensTable::delete_by_client_user( $client['client_id'], (int) $row['user_id'] );

		return self::issue_token_pair(
			$client['client_id'],
			(int) $row['user_id'],
			$row['scope'],
			$row['audience']
		);
	}

	// -------------------------------------------------------------------------
	// Token issuance
	// -------------------------------------------------------------------------

	/**
	 * Issue an access + refresh token pair and return the token response.
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id   WP user ID.
	 * @param string $scope     Approved scope.
	 * @param string $audience  Canonical MCP server URI.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function issue_token_pair( $client_id, $user_id, $scope, $audience ) {
		$access_token  = TokenValidator::generate( 'mcpat_' );
		$refresh_token = TokenValidator::generate( 'mcprt_' );

		$access_ttl  = 3600;    // 1 hour
		$refresh_ttl = 2592000; // 30 days

		$ok_access = OAuthTokensTable::insert(
			TokenValidator::hash( $access_token ),
			'access',
			$client_id,
			$user_id,
			$scope,
			$audience,
			$access_ttl
		);

		$ok_refresh = OAuthTokensTable::insert(
			TokenValidator::hash( $refresh_token ),
			'refresh',
			$client_id,
			$user_id,
			$scope,
			$audience,
			$refresh_ttl
		);

		if ( ! $ok_access || ! $ok_refresh ) {
			return self::token_error( 'server_error', 'Could not store tokens.' );
		}

		// Housekeeping: purge stale rows.
		OAuthTokensTable::purge_expired();
		OAuthAuthCodesTable::purge_expired();

		return new \WP_REST_Response(
			array(
				'access_token'  => $access_token,
				'token_type'    => 'Bearer',
				'expires_in'    => $access_ttl,
				'refresh_token' => $refresh_token,
				'scope'         => $scope,
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// PKCE
	// -------------------------------------------------------------------------

	/**
	 * Verify PKCE code_verifier against the stored code_challenge.
	 *
	 * @param string $verifier  Plaintext code_verifier from the client.
	 * @param string $challenge Stored code_challenge (base64url of SHA-256 of verifier).
	 * @param string $method    'S256' or 'plain'.
	 *
	 * @return bool
	 */
	private static function verify_pkce( $verifier, $challenge, $method ) {
		if ( 'S256' === $method ) {
			$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			return hash_equals( $computed, $challenge );
		}
		// 'plain' (fallback, not recommended but per spec)
		return hash_equals( $verifier, $challenge );
	}

	// -------------------------------------------------------------------------
	// Error helper
	// -------------------------------------------------------------------------

	/**
	 * Return a RFC 6749-compliant token error response.
	 *
	 * @param string $error             Machine-readable error code.
	 * @param string $error_description Human-readable description.
	 *
	 * @return \WP_Error
	 */
	private static function token_error( $error, $error_description ) {
		return new \WP_Error(
			$error,
			$error_description,
			array(
				'status'            => 400,
				'error'             => $error,
				'error_description' => $error_description,
			)
		);
	}
}
