<?php
/**
 * ClientRegistrationController — DCR (RFC 7591) + admin credential generator.
 *
 * Phase 3 (US1) ships `handle_admin_generate` — the admin-only route
 * called from AIConnectorsTab. Phase 6 (US2) adds `handle_register` for
 * public DCR.
 *
 * SEC-021-T02: `setup_instructions_html` returned to the admin passes
 * through `wp_kses_post` at this boundary so companion-plugin XSS in a
 * profile's `get_setup_instructions()` cannot fire in the admin.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\RateLimiter;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class ClientRegistrationController {

	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';

	/** @var ClientRegistrationController|null */
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
	 * Register REST routes. Wired by Main.php on `rest_api_init`.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/generate-client',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_admin_generate' ),
				'permission_callback' => array( $this, 'admin_generate_permission' ),
				'args'                => array(
					'server_id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
					'connector_slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => static function ( $value ): string {
							return preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $value ) ) ?? '';
						},
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && (bool) preg_match( '/\A[a-z0-9-]{1,64}\z/', $value );
						},
					),
				),
			)
		);

		// Feature 021 Phase 6 (US2) — RFC 7591 Dynamic Client Registration.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => array( $this, 'dcr_permission' ),
			)
		);
	}

	/**
	 * Permission callback for `/oauth/generate-client`.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool|\WP_Error
	 */
	public function admin_generate_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'acrossai_mcp_oauth_forbidden',
				__( 'You do not have permission to generate connector credentials.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'acrossai_mcp_oauth_bad_nonce',
				__( 'Invalid or missing nonce.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Admin credential generator — validates server + profile, revokes any
	 * prior client's tokens, issues a fresh `server-{id}-{slug}-{rand8}`
	 * client_id + 256-bit client_secret, returns the raw pair ONCE plus
	 * sanitized setup instructions.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_admin_generate( \WP_REST_Request $request ) {
		$server_id      = (int) $request->get_param( 'server_id' );
		$connector_slug = (string) $request->get_param( 'connector_slug' );

		$server_item = MCPServerQuery::instance()->get_item( $server_id );
		if ( ! $server_item ) {
			return new \WP_Error(
				'acrossai_mcp_oauth_server_not_found',
				__( 'MCP server not found.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}

		$server = is_array( $server_item ) ? $server_item : (array) $server_item;

		$profile = ConnectorProfileRegistry::instance()->get_profile( $connector_slug );
		if ( null === $profile ) {
			return new \WP_Error(
				'acrossai_mcp_oauth_profile_not_found',
				__( 'Connector profile is not registered.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}

		try {
			$existing    = ClientRepository::find_admin_client( $server_id, $connector_slug );
			$regenerated = false;

			if ( null !== $existing ) {
				$revoked_ids = TokensQuery::instance()->revoke_by_client_id( $existing->client_id );
				foreach ( $revoked_ids as $token_id ) {
					/**
					 * Action: acrossai_mcp_manager_oauth_token_revoked
					 * Fires once per row transitioned to `revoked=1`.
					 */
					do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'client_regenerated' );
				}
				$regenerated = true;
			}

			$new_client_id     = self::admin_client_id( $server_id, $connector_slug );
			$new_client_secret = SecretsVault::random_token();

			$row_id = ClientRepository::create(
				array(
					'client_id'                  => $new_client_id,
					'client_secret'              => $new_client_secret,
					'client_name'                => $profile->get_name(),
					'redirect_uris'              => $profile->get_redirect_uri_whitelist(),
					'grant_types'                => 'authorization_code refresh_token',
					'token_endpoint_auth_method' => 'client_secret_post',
					'connector_slug'             => $connector_slug,
					'metadata_fingerprint'       => '', // Admin-issued clients are never DCR-deduped.
				)
			);

			if ( $row_id <= 0 ) {
				return new \WP_Error(
					'acrossai_mcp_oauth_generate_client_failed',
					__( 'Could not persist the connector client.', 'acrossai-mcp-manager' ),
					array( 'status' => 500 )
				);
			}

			// SEC-021-T02 — sanitize third-party HTML before returning.
			$setup_html_raw  = $profile->get_setup_instructions( $server, $new_client_id, $new_client_secret );
			$setup_html_safe = wp_kses_post( $setup_html_raw );

			return new \WP_REST_Response(
				array(
					'client_id'               => $new_client_id,
					'client_secret'           => $new_client_secret,
					'setup_instructions_html' => $setup_html_safe,
					'regenerated'             => $regenerated,
				),
				200
			);
		} catch ( \Throwable $e ) {
			// SEC-021-T06 / F020 SEC-020-010 mirror — do NOT leak $e->getMessage() into the wire body.
			return new \WP_Error(
				'acrossai_mcp_oauth_generate_client_failed',
				__( 'Could not generate connector credentials.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Q2 — Build a structured admin `client_id`:
	 * `server-{server_id}-{connector_slug}-{bin2hex(random_bytes(4))}`.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector profile slug.
	 * @return string
	 */
	private static function admin_client_id( int $server_id, string $slug ): string {
		return 'server-' . $server_id . '-' . $slug . '-' . SecretsVault::random_hex( 4 );
	}

	/**
	 * FR-027 rate-limiter permission callback for DCR (10/IP/60s).
	 *
	 * DCR is intentionally unauthenticated per RFC 7591 §2. The S8
	 * body-authenticated exception applies — rate-limit is the sole gate.
	 *
	 * @return true|\WP_Error
	 */
	public function dcr_permission() {
		$check = RateLimiter::check( 'dcr', RateLimiter::client_ip(), 10, 60 );
		if ( $check instanceof \WP_Error ) {
			return $check;
		}
		return true;
	}

	/**
	 * FR-020..FR-023 — RFC 7591 Dynamic Client Registration.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_register( \WP_REST_Request $request ) {
		$content_type = strtolower( (string) $request->get_content_type()['value'] ?? '' );
		if ( 'application/json' !== $content_type ) {
			return new \WP_Error(
				'invalid_request',
				__( 'Content-Type must be application/json.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'invalid_request',
				__( 'Malformed JSON body.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] )
			? array_values( array_filter( $body['redirect_uris'], 'is_string' ) )
			: array();

		if ( empty( $redirect_uris ) ) {
			return new \WP_Error(
				'invalid_redirect_uri',
				__( 'At least one redirect_uri is required.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		// SEC-021-004 strict scheme validation on every entry.
		foreach ( $redirect_uris as $uri ) {
			if ( ! self::is_valid_redirect_uri( $uri ) ) {
				return new \WP_Error(
					'invalid_redirect_uri',
					__( 'redirect_uri must be HTTPS or loopback; other schemes rejected.', 'acrossai-mcp-manager' ),
					array( 'status' => 400 )
				);
			}
		}

		$grant_types = isset( $body['grant_types'] ) && is_array( $body['grant_types'] )
			? array_values( array_filter( $body['grant_types'], 'is_string' ) )
			: array( 'authorization_code', 'refresh_token' );

		$response_types = isset( $body['response_types'] ) && is_array( $body['response_types'] )
			? array_values( array_filter( $body['response_types'], 'is_string' ) )
			: array( 'code' );

		// Default to 'none' — modern MCP hosts (Claude.ai, ChatGPT) register as
		// public+PKCE clients and omit this field; they never carry a
		// client_secret through the token exchange. Callers that want a
		// confidential client pass 'client_secret_post' explicitly.
		$token_endpoint_auth_method = isset( $body['token_endpoint_auth_method'] ) && is_string( $body['token_endpoint_auth_method'] )
			? $body['token_endpoint_auth_method']
			: 'none';
		if ( ! in_array( $token_endpoint_auth_method, array( 'none', 'client_secret_post' ), true ) ) {
			return new \WP_Error(
				'invalid_client_metadata',
				__( 'Unsupported token_endpoint_auth_method.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$client_name = isset( $body['client_name'] ) && is_string( $body['client_name'] )
			? sanitize_text_field( $body['client_name'] )
			: '';

		// FR-022 — idempotent dedup by canonical metadata fingerprint.
		$fingerprint = self::compute_fingerprint(
			array(
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => $grant_types,
				'response_types'             => $response_types,
				'token_endpoint_auth_method' => $token_endpoint_auth_method,
			)
		);

		$existing = ClientRepository::find_by_fingerprint( $fingerprint );
		if ( null !== $existing ) {
			// SC-005 — return same client_id, NO new secret, NO token_issued action fires.
			$issued_at = strtotime( (string) $existing->created_at . ' GMT' );
			if ( false === $issued_at ) {
				$issued_at = 0;
			}
			return new \WP_REST_Response(
				array(
					'client_id'                  => $existing->client_id,
					'client_id_issued_at'        => $issued_at,
					'client_secret_expires_at'   => 0,
					'redirect_uris'              => $existing->decoded_redirect_uris(),
					'grant_types'                => explode( ' ', $existing->grant_types ),
					'response_types'             => array( 'code' ),
					'token_endpoint_auth_method' => $existing->token_endpoint_auth_method,
					'client_name'                => $existing->client_name,
				),
				200
			);
		}

		try {
			// FR-023 — fresh registration issues opaque 32-char client_id + 256-bit secret.
			$new_client_id     = SecretsVault::random_hex( 16 ); // 32 hex chars.
			$new_client_secret = 'none' === $token_endpoint_auth_method ? null : SecretsVault::random_token();

			// Guard against the (statistically impossible) `server-` prefix collision with admin format.
			if ( 0 === strpos( $new_client_id, 'server-' ) ) {
				$new_client_id = SecretsVault::random_hex( 16 );
			}

			$row_id = ClientRepository::create(
				array(
					'client_id'                  => $new_client_id,
					'client_secret'              => $new_client_secret,
					'client_name'                => $client_name,
					'redirect_uris'              => $redirect_uris,
					'grant_types'                => implode( ' ', $grant_types ),
					'token_endpoint_auth_method' => $token_endpoint_auth_method,
					'connector_slug'             => '',
					'metadata_fingerprint'       => $fingerprint,
				)
			);

			if ( $row_id <= 0 ) {
				return new \WP_Error(
					'server_error',
					__( 'Could not persist the client registration.', 'acrossai-mcp-manager' ),
					array( 'status' => 500 )
				);
			}

			$response_body = array(
				'client_id'                  => $new_client_id,
				'client_id_issued_at'        => time(),
				'client_secret_expires_at'   => 0,
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => $grant_types,
				'response_types'             => $response_types,
				'token_endpoint_auth_method' => $token_endpoint_auth_method,
				'client_name'                => $client_name,
			);

			if ( null !== $new_client_secret ) {
				$response_body['client_secret'] = $new_client_secret;
			}

			return new \WP_REST_Response( $response_body, 201 );
		} catch ( \Throwable $e ) {
			// SEC-020-010 mirror — do NOT leak exception details.
			return new \WP_Error(
				'server_error',
				__( 'Could not persist the client registration.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * SEC-021-004 — Strict redirect URI scheme validation.
	 *
	 * Accept: `https:` OR loopback (`127.0.0.1`, `localhost`, `::1`) on any
	 * port with any scheme (http common in dev). Reject `javascript:`,
	 * `data:`, `file:`, `ftp:`, `gopher:`, `mailto:`, `about:`, `chrome:`,
	 * `chrome-extension:` explicitly (case-insensitive).
	 *
	 * @param string $uri Redirect URI to validate.
	 * @return bool
	 */
	private static function is_valid_redirect_uri( string $uri ): bool {
		if ( '' === $uri ) {
			return false;
		}

		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		// Explicit block-list. Even if a downstream path allowed them, these MUST NEVER pass.
		$blocked = array( 'javascript', 'data', 'file', 'ftp', 'gopher', 'mailto', 'about', 'chrome', 'chrome-extension' );
		if ( in_array( $scheme, $blocked, true ) ) {
			return false;
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		// Loopback — any scheme allowed on any port.
		if ( in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
			return in_array( $scheme, array( 'http', 'https' ), true );
		}

		// Non-loopback MUST be https.
		return 'https' === $scheme && '' !== $host;
	}

	/**
	 * FR-022 — Canonical metadata fingerprint for DCR idempotent dedup.
	 *
	 * @param array{redirect_uris: array<int, string>, grant_types: array<int, string>, response_types: array<int, string>, token_endpoint_auth_method: string} $meta
	 * @return string SHA-256 hex.
	 */
	private static function compute_fingerprint( array $meta ): string {
		$canonical = array(
			'redirect_uris'              => array_values( $meta['redirect_uris'] ),
			'grant_types'                => array_values( array_unique( $meta['grant_types'] ) ),
			'response_types'             => array_values( array_unique( $meta['response_types'] ) ),
			'token_endpoint_auth_method' => $meta['token_endpoint_auth_method'],
		);

		sort( $canonical['redirect_uris'] );
		sort( $canonical['grant_types'] );
		sort( $canonical['response_types'] );

		return hash( 'sha256', (string) wp_json_encode( $canonical ) );
	}
}
