<?php
/**
 * OAuth discovery endpoints (.well-known).
 *
 * Serves:
 *   /.well-known/oauth-authorization-server          — RFC 8414 AS metadata
 *   /.well-known/oauth-protected-resource/{slug}     — RFC 9728 per-server PR metadata
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles OAuth discovery document responses.
 *
 * @since 1.6.0
 */
class Discovery {

	/**
	 * Query var that signals a request for the AS metadata document.
	 */
	const QV_AS = 'acrossai_mcp_oauth_as';

	/**
	 * Query var that carries the server slug for protected-resource metadata.
	 */
	const QV_PR = 'acrossai_mcp_oauth_pr';

	/**
	 * Return the OAuth issuer / AS base URL (home_url, no trailing slash).
	 *
	 * @return string
	 */
	public static function get_issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Return the authorization server metadata document (RFC 8414).
	 *
	 * @return array
	 */
	public static function get_as_metadata() {
		$issuer   = self::get_issuer();
		$rest_base = untrailingslashit( rest_url( 'acrossai-mcp-manager/v1/oauth' ) );

		return array(
			'issuer'                                => $issuer,
			'authorization_endpoint'               => home_url( 'acrossai-mcp-manager/oauth/authorize/' ),
			'token_endpoint'                       => $rest_base . '/token',
			'revocation_endpoint'                  => $rest_base . '/revoke',
			'response_types_supported'             => array( 'code' ),
			'grant_types_supported'                => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'     => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'client_secret_basic', 'client_secret_post' ),
			'scopes_supported'                     => array( 'mcp' ),
		);
	}

	/**
	 * Return the protected-resource metadata document for a specific server (RFC 9728).
	 *
	 * @param array $server Server DB row.
	 *
	 * @return array
	 */
	public static function get_pr_metadata( array $server ) {
		$ns    = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route = ! empty( $server['server_route'] ) ? $server['server_route'] : $server['server_slug'];

		return array(
			'resource'             => rest_url( $ns . '/' . $route ),
			'authorization_servers' => array( self::get_issuer() ),
			'scopes_supported'     => array( 'mcp' ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * Serve the AS metadata document and exit.
	 *
	 * @return void
	 */
	public static function serve_as_metadata() {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( self::get_as_metadata() );
		exit;
	}

	/**
	 * Serve the protected-resource metadata document for the given server slug.
	 *
	 * Returns a 404 JSON response if the slug is not found or connector not enabled.
	 *
	 * @param string $slug Server slug from the URL.
	 *
	 * @return void
	 */
	public static function serve_pr_metadata( $slug ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );

		$server = self::find_server_by_slug( $slug );

		if ( ! $server || empty( $server['connector_enabled'] ) ) {
			status_header( 404 );
			echo wp_json_encode( array( 'error' => 'not_found' ) );
			exit;
		}

		echo wp_json_encode( self::get_pr_metadata( $server ) );
		exit;
	}

	/**
	 * Find a server row by its server_slug, regardless of is_enabled status.
	 *
	 * @param string $slug Server slug.
	 *
	 * @return array|null
	 */
	public static function find_server_by_slug( $slug ) {
		foreach ( MCPServerTable::get_all() as $row ) {
			if ( ( $row['server_slug'] ?? '' ) === $slug ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Return the canonical MCP URL for a server row.
	 *
	 * @param array $server Server DB row.
	 *
	 * @return string
	 */
	public static function get_mcp_url( array $server ) {
		$ns    = ! empty( $server['server_route_namespace'] ) ? $server['server_route_namespace'] : 'mcp';
		$route = ! empty( $server['server_route'] ) ? $server['server_route'] : $server['server_slug'];
		return rest_url( $ns . '/' . $route );
	}
}
