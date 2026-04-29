<?php
/**
 * OAuth 2.1 server boot module.
 *
 * Registers all hooks, rewrite rules, REST routes, and CORS headers needed
 * to make each connector-enabled MCP server work as a Claude.ai custom connector.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;
use ACROSSAI_MCP_MANAGER\Database\OAuthTokensTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the OAuth 2.1 authorization + resource server.
 *
 * @since 1.6.0
 */
class Server {

	/**
	 * Register all hooks.
	 *
	 * Called from Plugin::__construct() on every request.
	 *
	 * @return void
	 */
	public function boot() {
		// Rewrite rules for .well-known and the authorize page.
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 5 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 25 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		// Template redirect: serve discovery docs and consent page.
		add_action( 'template_redirect', array( $this, 'handle_template_redirect' ), 1 );

		// REST routes for register/token/revoke.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// CORS headers for discovery docs, REST OAuth routes, and MCP routes.
		add_action( 'rest_pre_serve_request', array( $this, 'add_cors_headers' ), 1, 4 );

		// Bearer token validation — priority 5 so it runs before access control (priority 10).
		add_filter( 'rest_pre_dispatch', array( $this, 'validate_bearer_token' ), 5, 3 );

		// Revoke tokens when a user is deleted.
		add_action( 'delete_user', array( $this, 'revoke_user_tokens' ) );
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	/**
	 * Register rewrite rules for .well-known endpoints and the authorize page.
	 *
	 * @return void
	 */
	public function register_rewrite_rules() {
		// AS metadata: /.well-known/oauth-authorization-server
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?' . Discovery::QV_AS . '=1',
			'top'
		);

		// Per-server protected-resource metadata: /.well-known/oauth-protected-resource/{slug}
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/([^/]+)/?$',
			'index.php?' . Discovery::QV_PR . '=$matches[1]',
			'top'
		);

		// OAuth authorize page: /acrossai-mcp-manager/oauth/authorize/
		add_rewrite_rule(
			'^acrossai-mcp-manager/oauth/authorize/?$',
			'index.php?' . AuthorizationEndpoint::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Flush rewrite rules once if our rules are not yet stored.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		$rules = get_option( 'rewrite_rules' );
		if ( empty( $rules ) || ! isset( $rules['^\.well-known/oauth-authorization-server/?$'] ) ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Register custom query vars so WordPress passes them to the template.
	 *
	 * @param string[] $vars Existing vars.
	 *
	 * @return string[]
	 */
	public function add_query_vars( array $vars ) {
		$vars[] = Discovery::QV_AS;
		$vars[] = Discovery::QV_PR;
		$vars[] = AuthorizationEndpoint::QUERY_VAR;
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Template redirect handler
	// -------------------------------------------------------------------------

	/**
	 * Detect .well-known and authorize requests and serve them directly.
	 *
	 * @return void
	 */
	public function handle_template_redirect() {
		// AS metadata document.
		if ( get_query_var( Discovery::QV_AS ) ) {
			if ( (bool) get_option( 'acrossai_mcp_oauth_enabled', false ) ) {
				Discovery::serve_as_metadata();
			} else {
				status_header( 404 );
				exit;
			}
		}

		// Per-server protected-resource metadata.
		$pr_slug = get_query_var( Discovery::QV_PR );
		if ( $pr_slug ) {
			Discovery::serve_pr_metadata( sanitize_text_field( $pr_slug ) );
		}

		// OAuth authorize consent page.
		if ( get_query_var( AuthorizationEndpoint::QUERY_VAR ) ) {
			AuthorizationEndpoint::handle_request();
		}
	}

	// -------------------------------------------------------------------------
	// REST routes
	// -------------------------------------------------------------------------

	/**
	 * Register the OAuth REST endpoints (DCR, token, revoke).
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'acrossai-mcp-manager/v1',
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'ACROSSAI_MCP_MANAGER\OAuth\TokenEndpoint', 'handle' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'acrossai-mcp-manager/v1',
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke' ),
				'permission_callback' => '__return_true',
			)
		);

		// Admin-only endpoint: revoke a token by its DB row ID.
		register_rest_route(
			'acrossai-mcp-manager/v1',
			'/oauth/tokens/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_admin_revoke' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	/**
	 * REST callback — revoke a token (RFC 7009). Expects the plaintext token value.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_revoke( \WP_REST_Request $request ) {
		$token = $request->get_param( 'token' );
		if ( $token ) {
			OAuthTokensTable::delete( hash( 'sha256', $token ) );
		}
		// RFC 7009: always return 200 regardless of whether the token existed.
		return new \WP_REST_Response( array(), 200 );
	}

	/**
	 * Admin REST callback — revoke a token by its DB row ID.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_admin_revoke( \WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT token_hash FROM ' . OAuthTokensTable::get_table_name() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$id
			),
			ARRAY_A
		);

		if ( $row ) {
			OAuthTokensTable::delete( $row['token_hash'] );
		}

		return new \WP_REST_Response( array( 'revoked' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// CORS
	// -------------------------------------------------------------------------

	/**
	 * Add permissive CORS headers to OAuth REST routes and MCP routes.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param \WP_HTTP_Response $result  Response object.
	 * @param \WP_REST_Request  $request Incoming request.
	 * @param \WP_REST_Server   $server  REST server.
	 *
	 * @return bool Original $served value.
	 */
	public function add_cors_headers( $served, $result, $request, $server ) {
		$route = $request->get_route();

		$is_oauth_route = 0 === strpos( $route, '/acrossai-mcp-manager/v1/oauth/' );
		$is_mcp_route   = $this->is_connector_enabled_mcp_route( $route );

		if ( ! $is_oauth_route && ! $is_mcp_route ) {
			return $served;
		}

		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version' );
		header( 'Access-Control-Max-Age: 86400' );

		// Handle preflight directly.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'OPTIONS' === $method ) {
			status_header( 204 );
			exit;
		}

		return $served;
	}

	// -------------------------------------------------------------------------
	// Bearer token validation (rest_pre_dispatch priority 5)
	// -------------------------------------------------------------------------

	/**
	 * Validate OAuth Bearer tokens on MCP REST requests that have connector enabled.
	 *
	 * For connector-enabled servers:
	 *  - If a valid Bearer token is present: set the WP current user.
	 *  - If an invalid Bearer token is present: return 401.
	 *  - If no Bearer token AND WP has not authenticated the user via other means: return 401 + WWW-Authenticate.
	 *  - If no Bearer token AND WP already authenticated the user (Basic/cookie): pass through.
	 *
	 * @param mixed            $result  Current short-circuit value.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Incoming request.
	 *
	 * @return mixed Original $result or WP_Error.
	 */
	public function validate_bearer_token( $result, $server, $request ) {
		// Already short-circuited by another filter.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! (bool) get_option( 'acrossai_mcp_oauth_enabled', false ) ) {
			return $result;
		}

		$route     = $request->get_route();
		$server_row = $this->find_connector_server_for_route( $route );

		if ( ! $server_row ) {
			return $result; // Not a connector-enabled MCP route.
		}

		$ns      = ! empty( $server_row['server_route_namespace'] ) ? $server_row['server_route_namespace'] : 'mcp';
		$r       = ! empty( $server_row['server_route'] ) ? $server_row['server_route'] : $server_row['server_slug'];
		$mcp_url = rest_url( $ns . '/' . $r );
		$slug    = $server_row['server_slug'];

		$bearer = TokenValidator::extract_bearer( $request );

		if ( $bearer ) {
			// Validate the presented Bearer token.
			$user_id = TokenValidator::validate( $bearer, $mcp_url );

			if ( false === $user_id ) {
				return new \WP_Error(
					'invalid_token',
					__( 'The Bearer token is invalid or has expired.', 'acrossai-mcp-manager' ),
					array( 'status' => 401 )
				);
			}

			// Set the WP current user so access control and server code use it.
			wp_set_current_user( $user_id );
			return $result;
		}

		// No Bearer token — check if WP core already authenticated the user.
		if ( is_user_logged_in() ) {
			return $result; // Existing auth (Basic / cookie) → pass through.
		}

		// Unauthenticated — return 401 with WWW-Authenticate discovery hint.
		// We use WP_REST_Response (not WP_Error) so we can set the response header.
		$pr_url   = home_url( '.well-known/oauth-protected-resource/' . $slug );
		$response = new \WP_REST_Response(
			array(
				'code'    => 'unauthorized',
				'message' => __( 'Authentication required. Use OAuth 2.1 Bearer token.', 'acrossai-mcp-manager' ),
				'data'    => array( 'status' => 401 ),
			),
			401
		);
		$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . esc_url_raw( $pr_url ) . '"' );

		return $response;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the server row if the given REST route belongs to a connector-enabled server.
	 *
	 * @param string $route REST route (e.g. "/mcp/default-mcp-server").
	 *
	 * @return array|null
	 */
	private function find_connector_server_for_route( $route ) {
		foreach ( MCPServerTable::get_all() as $row ) {
			if ( empty( $row['is_enabled'] ) || empty( $row['connector_enabled'] ) ) {
				continue;
			}

			$ns     = ! empty( $row['server_route_namespace'] ) ? $row['server_route_namespace'] : 'mcp';
			$r      = ! empty( $row['server_route'] ) ? $row['server_route'] : $row['server_slug'];
			$prefix = '/' . trim( $ns, '/' ) . '/' . ltrim( $r, '/' );

			if ( 0 === strpos( $route, $prefix ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Check whether a REST route corresponds to any connector-enabled MCP server.
	 *
	 * Used for CORS decisions.
	 *
	 * @param string $route REST route.
	 *
	 * @return bool
	 */
	private function is_connector_enabled_mcp_route( $route ) {
		return null !== $this->find_connector_server_for_route( $route );
	}

	/**
	 * Revoke all tokens for a user being deleted.
	 *
	 * @param int $user_id WP user ID being deleted.
	 *
	 * @return void
	 */
	public function revoke_user_tokens( $user_id ) {
		OAuthTokensTable::delete_by_user( (int) $user_id );
	}
}
