<?php
/**
 * /token endpoint — authorization_code + refresh_token grants.
 *
 * FR-013..FR-019 + SEC-021-001 family revocation (RFC 9700 §2.2.2) +
 * atomic single-use auth codes (B10) + refresh rotation.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AuthCodeRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\RefreshTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\RateLimiter;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class TokenController {

	/** @var TokenController|null */
	private static $instance = null;

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * FR-013 dispatcher.
	 *
	 * @return void
	 */
	public function handle(): void {
		self::apply_rate_limit();

		$body = self::read_body();

		$grant_type = isset( $body['grant_type'] ) ? (string) $body['grant_type'] : '';

		switch ( $grant_type ) {
			case 'authorization_code':
				$this->handle_authorization_code( $body );
				break;
			case 'refresh_token':
				$this->handle_refresh_token( $body );
				break;
			case '':
				self::respond_error( 'invalid_request', 'Missing grant_type', 400 );
				break;
			default:
				self::respond_error( 'unsupported_grant_type', 'Unsupported grant_type', 400 );
		}
	}

	/**
	 * FR-014..FR-016 authorization_code grant.
	 *
	 * @param array<string, string> $body Parsed request body.
	 * @return void
	 */
	private function handle_authorization_code( array $body ): void {
		$required = array( 'code', 'client_id', 'code_verifier', 'redirect_uri' );
		foreach ( $required as $field ) {
			if ( ! isset( $body[ $field ] ) || '' === $body[ $field ] ) {
				self::respond_error( 'invalid_request', 'Missing required field: ' . $field, 400 );
			}
		}

		$row = AuthCodeRepository::consume_atomic( $body['code'] );
		if ( null === $row ) {
			self::respond_error( 'invalid_grant', 'Auth code is invalid or has already been used.', 400 );
		}

		if ( ! hash_equals( (string) $row->client_id, (string) $body['client_id'] ) ) {
			self::respond_error( 'invalid_grant', 'client_id mismatch', 400 );
		}
		if ( ! hash_equals( (string) $row->redirect_uri, (string) $body['redirect_uri'] ) ) {
			self::respond_error( 'invalid_grant', 'redirect_uri mismatch', 400 );
		}

		$client = ClientRepository::find_by_id( (string) $body['client_id'] );
		if ( null === $client ) {
			self::respond_error( 'invalid_client', 'Client not registered', 401 );
		}

		if ( 'client_secret_post' === $client->token_endpoint_auth_method ) {
			$submitted_secret = isset( $body['client_secret'] ) ? (string) $body['client_secret'] : '';
			if ( '' === $submitted_secret || ! ClientRepository::verify_secret( $client, $submitted_secret ) ) {
				self::respond_error( 'invalid_client', 'client_secret verification failed', 401 );
			}
		}

		if ( ! PKCE::verify_s256( (string) $body['code_verifier'], (string) $row->code_challenge ) ) {
			self::respond_error( 'invalid_grant', 'PKCE verification failed', 400 );
		}

		// SEC-021-001 — mint a fresh family_id for this auth-code chain.
		$family_id = wp_generate_uuid4();
		$user_id   = (int) $row->user_id;
		$resource  = (string) $row->resource;
		$scope     = '' !== $row->scope ? (string) $row->scope : 'mcp';

		$access  = AccessTokenRepository::issue(
			array(
				'client_id'       => (string) $body['client_id'],
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);
		$refresh = RefreshTokenRepository::issue(
			array(
				'client_id'       => (string) $body['client_id'],
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);

		/**
		 * Action: acrossai_mcp_manager_oauth_token_issued
		 */
		do_action( 'acrossai_mcp_manager_oauth_token_issued', (int) $access['id'], (string) $body['client_id'], $user_id, (string) $client->connector_slug );

		self::respond_token_success(
			array(
				'access_token'  => (string) $access['raw'],
				'token_type'    => 'Bearer',
				'expires_in'    => AccessTokenRepository::ttl(),
				'refresh_token' => (string) $refresh['raw'],
				'scope'         => $scope,
				'resource'      => $resource,
			)
		);
	}

	/**
	 * FR-017 refresh_token grant with SEC-021-001 family revocation.
	 *
	 * @param array<string, string> $body Parsed request body.
	 * @return void
	 */
	private function handle_refresh_token( array $body ): void {
		if ( ! isset( $body['refresh_token'], $body['client_id'] ) || '' === $body['refresh_token'] || '' === $body['client_id'] ) {
			self::respond_error( 'invalid_request', 'Missing refresh_token or client_id', 400 );
		}

		$row = RefreshTokenRepository::find_by_hash( SecretsVault::hash( (string) $body['refresh_token'] ) );
		if ( null === $row || 'refresh' !== $row->token_type ) {
			self::respond_error( 'invalid_grant', 'Unknown refresh token', 400 );
		}

		// SEC-021-001 — reuse detection. A refresh token whose row is already
		// revoked implies compromise: bulk-revoke the entire family so the
		// attacker's rotated pair also dies.
		if ( 1 === (int) $row->revoked ) {
			$family_id = (string) $row->token_family_id;
			if ( '' !== $family_id ) {
				$revoked_ids = RefreshTokenRepository::revoke_by_family_id( $family_id );
				foreach ( $revoked_ids as $token_id ) {
					do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'family_reuse_detected' );
				}
			}
			self::respond_error( 'invalid_grant', 'Refresh token has been revoked.', 400 );
		}

		if ( ! hash_equals( (string) $row->client_id, (string) $body['client_id'] ) ) {
			self::respond_error( 'invalid_grant', 'client_id mismatch', 400 );
		}

		$now_ts = time();
		if ( strtotime( (string) $row->expires_at . ' GMT' ) < $now_ts ) {
			self::respond_error( 'invalid_grant', 'Refresh token expired', 400 );
		}

		$client = ClientRepository::find_by_id( (string) $body['client_id'] );
		if ( null === $client ) {
			self::respond_error( 'invalid_client', 'Client not registered', 401 );
		}

		if ( 'client_secret_post' === $client->token_endpoint_auth_method ) {
			$submitted_secret = isset( $body['client_secret'] ) ? (string) $body['client_secret'] : '';
			if ( '' === $submitted_secret || ! ClientRepository::verify_secret( $client, $submitted_secret ) ) {
				self::respond_error( 'invalid_client', 'client_secret verification failed', 401 );
			}
		}

		// Rotate — revoke the presented refresh, issue a fresh pair carrying
		// resource/scope/family_id forward.
		RefreshTokenRepository::revoke_by_hash( SecretsVault::hash( (string) $body['refresh_token'] ) );
		do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $row->id, 'refresh_rotation' );

		$family_id = (string) $row->token_family_id;
		if ( '' === $family_id ) {
			// Legacy row from before SEC-021-001 — mint a fresh family_id.
			$family_id = wp_generate_uuid4();
		}

		$user_id  = (int) $row->user_id;
		$resource = (string) $row->resource;
		$scope    = '' !== $row->scope ? (string) $row->scope : 'mcp';

		$access      = AccessTokenRepository::issue(
			array(
				'client_id'       => (string) $body['client_id'],
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);
		$new_refresh = RefreshTokenRepository::issue(
			array(
				'client_id'       => (string) $body['client_id'],
				'user_id'         => $user_id,
				'scope'           => $scope,
				'resource'        => $resource,
				'token_family_id' => $family_id,
			)
		);

		do_action( 'acrossai_mcp_manager_oauth_token_issued', (int) $access['id'], (string) $body['client_id'], $user_id, (string) $client->connector_slug );

		self::respond_token_success(
			array(
				'access_token'  => (string) $access['raw'],
				'token_type'    => 'Bearer',
				'expires_in'    => AccessTokenRepository::ttl(),
				'refresh_token' => (string) $new_refresh['raw'],
				'scope'         => $scope,
				'resource'      => $resource,
			)
		);
	}

	/**
	 * Parse the request body — accepts application/json or form-urlencoded.
	 *
	 * @return array<string, string>
	 */
	private static function read_body(): array {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : '';

		if ( 0 === strpos( $content_type, 'application/json' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Streaming request body from php://input.
			$raw = file_get_contents( 'php://input' );
			if ( false === $raw ) {
				$raw = '';
			}
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Token endpoint uses body-authenticated flow (RFC 6749 §2.3.1).
		return array_map( 'strval', wp_unslash( $_POST ) );
	}

	/**
	 * Send an RFC 6749 shaped success response.
	 *
	 * @param array<string, mixed> $payload
	 * @return void
	 */
	private static function respond_token_success( array $payload ): void {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
		echo wp_json_encode( $payload );
		exit;
	}

	/**
	 * Send an RFC 6749 shaped error response.
	 *
	 * @param string $error
	 * @param string $description
	 * @param int    $status
	 * @return void
	 */
	private static function respond_error( string $error, string $description, int $status ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
		echo wp_json_encode(
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);
		exit;
	}

	/**
	 * FR-028 — 60/IP/60s for /token, shared bucket key with /authorize.
	 *
	 * @return void
	 */
	private static function apply_rate_limit(): void {
		$check = RateLimiter::check( 'token', RateLimiter::client_ip(), 60, 60 );
		if ( $check instanceof \WP_Error ) {
			status_header( 429 );
			nocache_headers();
			header( 'Retry-After: 60' );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			echo wp_json_encode(
				array(
					'error'             => 'slow_down',
					'error_description' => 'Rate limit exceeded; retry in 60 seconds.',
				)
			);
			exit;
		}
	}
}
