<?php
/**
 * Bearer token resolver for the `determine_current_user` filter.
 *
 * Singleton + private ctor (A2). Wired via Loader on
 * `determine_current_user` priority 20 (AFTER WordPress's default auth
 * methods at priority 10) — never short-circuits other auth, never
 * throws, never elevates if $user_id is already truthy.
 *
 * @package AcrossAI_MCP_Manager\Includes\OAuth
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;

defined( 'ABSPATH' ) || exit;

final class BearerAuth {

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
	 * Filter callback for `determine_current_user`.
	 *
	 * @param mixed $user_id The current resolved user (int 0 if anonymous,
	 *                       or int user-ID if a prior filter resolved it).
	 *
	 * @return mixed The resolved user_id (int) or the input unchanged.
	 */
	public function resolve_bearer_token( $user_id ) {
		// Never elevate / override an already-resolved user.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$token = $this->get_bearer_token_from_request();
		if ( null === $token ) {
			return $user_id;
		}

		$endpoint  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$server_id = $this->resolve_server_id_from_endpoint( $endpoint );
		if ( 0 === $server_id ) {
			return $user_id; // Not an MCP server endpoint we own; leave auth untouched.
		}

		$hash = hash( 'sha256', $token );

		// Lookup constrained to (hash, server_id, active) — cross-server defense.
		$rows = OAuthTokenQuery::instance()->query(
			array(
				'access_token_hash' => $hash,
				'server_id'         => $server_id,
				'active_only'       => true,
				'number'            => 1,
			)
		);

		if ( ! empty( $rows ) ) {
			// Constant-time confirmation of the hash (DB lookup is the primary filter
			// but hash_equals defends against future timing-side-channel changes).
			if ( hash_equals( $rows[0]->access_token_hash, $hash ) ) {
				AuditLog::instance()->write(
					AuditLog::EVENT_BEARER_AUTH_SUCCESS,
					array(
						'server_id'         => $server_id,
						'user_id'           => (int) $rows[0]->user_id,
						'token_hash_prefix' => $hash,
						'endpoint'          => $endpoint,
					)
				);
				return (int) $rows[0]->user_id;
			}
		}

		// Token might exist for a different server (cross-server attempt).
		$any = OAuthTokenQuery::instance()->query(
			array(
				'access_token_hash' => $hash,
				'active_only'       => true,
				'number'            => 1,
			)
		);
		if ( ! empty( $any ) ) {
			AuditLog::instance()->write(
				AuditLog::EVENT_FAILED_CROSS_SERVER_TOKEN,
				array(
					'server_id'         => $server_id,
					'token_hash_prefix' => $hash,
					'endpoint'          => $endpoint,
				)
			);
		}
		// No matching active token (or expired / revoked / unknown) — silently leave
		// $user_id unchanged. No audit row for unknown tokens (no oracle).
		return $user_id;
	}

	/**
	 * Extract `Bearer <token>` from the request, honouring the Apache+CGI
	 * `REDIRECT_HTTP_AUTHORIZATION` fallback. Returns null when no token
	 * is present or the value is malformed.
	 */
	private function get_bearer_token_from_request(): ?string {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} else {
			return null;
		}

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return null;
		}

		$token = trim( substr( $header, 7 ) );
		if ( '' === $token || strlen( $token ) > 256 ) {
			return null;
		}

		return $token;
	}

	/**
	 * Resolve the MCP server id for a request path like `/wp-json/mcp/<route>/...`.
	 * Returns 0 when the path isn't an MCP server endpoint or the route doesn't match.
	 *
	 * @param string $endpoint Full REQUEST_URI value.
	 */
	private function resolve_server_id_from_endpoint( string $endpoint ): int {
		// Strip query string + REST root. parse_url is OK here — input is the request URI
		// (server-controlled), not user-attributable URL.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$path = (string) parse_url( $endpoint, PHP_URL_PATH );
		if ( '' === $path ) {
			return 0;
		}

		// Only consider /wp-json/mcp/<route>/* — not /wp-json/acrossai-mcp/*
		// (the OAuth endpoints themselves are NOT Bearer-resolved).
		$rest_prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/mcp/';
		$pos         = strpos( $path, $rest_prefix );
		if ( false === $pos ) {
			return 0;
		}
		$tail = substr( $path, $pos + strlen( $rest_prefix ) );
		$tail = trim( $tail, '/' );
		if ( '' === $tail ) {
			return 0;
		}
		$segments = explode( '/', $tail );
		$route    = $segments[0] ?? '';
		if ( '' === $route ) {
			return 0;
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_route' => $route,
				'number'       => 1,
			)
		);
		return isset( $rows[0] ) ? (int) $rows[0]->id : 0;
	}
}
