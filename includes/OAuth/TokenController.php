<?php
/**
 * Token endpoint controller — POST /wp-json/acrossai-mcp/v1/token.
 *
 * Implements the FR-012 validation chain (8 steps) + SEC-001 atomic CAS
 * + FR-014 anti-replay revocation.
 *
 * Singleton + private ctor (A2). The route is registered through the
 * Loader on `rest_api_init` — no hooks in this class.
 *
 * S2 documented exemption: the route's permission gate is intentionally
 * open because RFC 6749 §2.3.1 specifies that authentication happens via
 * POST body (`client_id` + `client_secret`). All security gates live
 * inside the callback below; the exemption is the only valid pattern for
 * OAuth's body-authenticated token endpoint.
 *
 * @package AcrossAI_MCP_Manager\Includes\OAuth
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class TokenController {

	const REST_NAMESPACE = 'acrossai-mcp/v1';
	const REST_ROUTE     = '/token';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Register the token endpoint with the REST API. Wired via Loader.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => '__return_true', // S2 exemption — see class docblock.
			)
		);
	}

	/**
	 * REST callback for the token endpoint — implements the FR-012 8-step
	 * validation chain + FR-013 atomic CAS issuance + FR-014 anti-replay.
	 *
	 * @param WP_REST_Request $request Inbound POST request.
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		// Security Checklist item 11: strict Content-Type rejection. RFC 6749 §3.2
		// specifies application/x-www-form-urlencoded; reject anything else (including
		// missing header — no JSON-body parsing, narrower attack surface).
		$ct_info = $request->get_content_type();
		$ct      = strtolower( (string) ( $ct_info['value'] ?? '' ) );
		if ( 'application/x-www-form-urlencoded' !== $ct ) {
			return $this->error_response( 'invalid_request', 'Content-Type MUST be application/x-www-form-urlencoded.', 400 );
		}

		$storage   = Storage::instance();
		$audit     = AuditLog::instance();
		$client_id = (string) $request->get_param( 'client_id' );
		$ip        = $this->request_ip();

		// Step 0 — Rate limit precheck (FR-014a, BEFORE all other validation).
		list( $rate_status, $retry_after ) = $storage->rate_limit_check_and_increment( $client_id, $ip );
		if ( 'ok' !== $rate_status ) {
			$audit->write(
				AuditLog::EVENT_FAILED_RATE_LIMIT,
				array(
					'client_id' => $client_id,
					'details'   => array( 'bucket' => $rate_status ),
				)
			);
			$resp = new WP_REST_Response( array( 'error' => 'slow_down' ), 429 );
			$resp->header( 'Retry-After', (string) $retry_after );
			return $resp;
		}

		// Step 1 — Required fields.
		$required = array( 'grant_type', 'code', 'client_id', 'client_secret', 'redirect_uri', 'code_verifier' );
		foreach ( $required as $field ) {
			if ( '' === (string) $request->get_param( $field ) ) {
				return $this->error_response( 'invalid_request', 'Missing required parameter: ' . $field, 400 );
			}
		}

		// Step 2 — grant_type.
		if ( 'authorization_code' !== (string) $request->get_param( 'grant_type' ) ) {
			return $this->error_response( 'unsupported_grant_type', 'Only authorization_code is supported.', 400 );
		}

		// Step 3 — client_id resolves to a server row.
		$server = $this->resolve_server_by_client_id( $client_id );
		if ( null === $server ) {
			return $this->error_response( 'invalid_client', 'Unknown client_id.', 401 );
		}

		// Step 4 — client_secret constant-time check.
		if ( ! hash_equals( (string) $server->claude_connector_client_secret, (string) $request->get_param( 'client_secret' ) ) ) {
			return $this->error_response( 'invalid_client', 'Client authentication failed.', 401 );
		}

		// Step 5 — code lookup.
		$raw_code = (string) $request->get_param( 'code' );
		$row      = $storage->lookup_authorization_code( $raw_code );
		if ( null === $row || (int) $row->server_id !== (int) $server->id ) {
			// SEC-001 (spec Security Checklist item 9): codes are scoped to ONE
			// client_id. Reject if the resolved row belongs to a different server
			// than the one the submitted client_id resolves to.
			return $this->error_response( 'invalid_grant', 'Authorization code is invalid.', 400 );
		}
		$row_id = (int) $row->id;

		// Step 5a — expiry check (10-minute window).
		$issued_ts = strtotime( (string) $row->created_at );
		if ( $issued_ts && ( time() - $issued_ts ) > Storage::AUTH_CODE_TTL_SECONDS ) {
			return $this->error_response( 'invalid_grant', 'Authorization code expired.', 400 );
		}

		// Step 5b — already redeemed → REPLAY path.
		if ( null !== $row->completed_at ) {
			return $this->replay_path( $row_id, $client_id, $audit, $storage );
		}

		// Step 6 — redirect_uri byte-for-byte match.
		if ( ! hash_equals( (string) $row->redirect_uri, (string) $request->get_param( 'redirect_uri' ) ) ) {
			return $this->error_response( 'invalid_grant', 'redirect_uri does not match.', 400 );
		}

		// Step 7 — PKCE S256 verify.
		$pkce = new PKCE();
		try {
			$pkce_ok = $pkce->verify( (string) $request->get_param( 'code_verifier' ), (string) $row->code_challenge );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error_response( 'invalid_grant', 'PKCE verifier malformed.', 400 );
		}
		if ( ! $pkce_ok ) {
			return $this->error_response( 'invalid_grant', 'PKCE verifier mismatch.', 400 );
		}

		// Step 8 — atomic CAS redeem (SEC-001).
		if ( ! $storage->redeem_authorization_code_cas( $row_id ) ) {
			return $this->replay_path( $row_id, $client_id, $audit, $storage );
		}

		// Step 8a — issue token. C4: persistence failure surfaces HTTP 503 per spec edge case.
		list( $raw_token, $token_id ) = $storage->issue_access_token( (int) $row->server_id, (int) $row->user_id, $row_id, (string) $row->scope );
		if ( '' === $raw_token ) {
			return $this->error_response( 'server_error', 'Failed to issue access token.', 503 );
		}

		$audit->write(
			AuditLog::EVENT_CODE_REDEEMED,
			array(
				'client_id' => $client_id,
				'server_id' => (int) $row->server_id,
				'user_id'   => (int) $row->user_id,
				'details'   => array(
					'code_row_id' => $row_id,
					'token_id'    => $token_id,
				),
			)
		);

		$storage->rate_limit_reset( $client_id, $ip );

		$ttl  = (int) apply_filters(
			'acrossai_mcp_oauth_access_token_lifetime',
			Storage::ACCESS_TOKEN_TTL_SECONDS,
			(int) $row->server_id,
			(int) $row->user_id
		);
		$resp = new WP_REST_Response(
			array(
				'access_token' => $raw_token,
				'token_type'   => 'Bearer',
				'expires_in'   => $ttl > 0 ? $ttl : Storage::ACCESS_TOKEN_TTL_SECONDS,
				'scope'        => (string) $row->scope,
			),
			200
		);
		$resp->header( 'Cache-Control', 'no-store' );
		$resp->header( 'Pragma', 'no-cache' );
		return $resp;
	}

	/**
	 * Step 8b / Step 5b REPLAY path — revoke all child tokens + audit + 400.
	 *
	 * @param int      $code_row_id CliAuthLog row id of the replayed code.
	 * @param string   $client_id   Client identifier from the replayed request.
	 * @param AuditLog $audit       Audit log singleton.
	 * @param Storage  $storage     Storage singleton.
	 */
	private function replay_path( int $code_row_id, string $client_id, AuditLog $audit, Storage $storage ): WP_REST_Response {
		$revoked_ids = $storage->revoke_all_tokens_for_code( $code_row_id );
		$audit->write(
			AuditLog::EVENT_FAILED_REPLAY_ATTEMPT,
			array(
				'client_id' => $client_id,
				'details'   => array( 'code_row_id' => $code_row_id ),
			)
		);
		foreach ( $revoked_ids as $token_id ) {
			$audit->write(
				AuditLog::EVENT_TOKEN_REVOKED,
				array(
					'client_id' => $client_id,
					'details'   => array( 'token_id' => (int) $token_id ),
				)
			);
		}
		return $this->error_response( 'invalid_grant', 'Authorization code is invalid.', 400 );
	}

	/**
	 * Compose a RFC 6749 §5.2 error envelope with the right cache headers.
	 *
	 * @param string $error_code  RFC error code.
	 * @param string $description Human-readable description.
	 * @param int    $status      HTTP status to return.
	 */
	private function error_response( string $error_code, string $description, int $status ): WP_REST_Response {
		$resp = new WP_REST_Response(
			array(
				'error'             => $error_code,
				'error_description' => $description,
			),
			$status
		);
		$resp->header( 'Cache-Control', 'no-store' );
		$resp->header( 'Pragma', 'no-cache' );
		return $resp;
	}

	/**
	 * Resolve an MCP server row by its `claude_connector_client_id` value.
	 *
	 * @param string $client_id Client identifier from the request.
	 */
	private function resolve_server_by_client_id( string $client_id ): ?\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row {
		if ( '' === $client_id ) {
			return null;
		}
		$rows = MCPServerQuery::instance()->query(
			array(
				'claude_connector_client_id' => $client_id,
				'number'                     => 1,
			)
		);
		return $rows[0] ?? null;
	}

	/**
	 * Return the request IP — REMOTE_ADDR only per R4 (no XFF trust by default).
	 */
	private function request_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	}
}
