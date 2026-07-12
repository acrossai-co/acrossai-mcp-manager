<?php
/**
 * RFC 8414 authorization server metadata + RFC 9728 protected resource
 * metadata (Feature 021).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class DiscoveryController {

	/** @var DiscoveryController|null */
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
	 * FR-001 — GET `/.well-known/oauth-authorization-server`.
	 *
	 * @return void
	 */
	public function render_authorization_server_metadata(): void {
		self::send_metadata_headers();

		$issuer = self::issuer();

		wp_send_json(
			array(
				'issuer'                                => $issuer,
				'authorization_endpoint'                => $issuer . '/authorize',
				'token_endpoint'                        => $issuer . '/token',
				'registration_endpoint'                 => rest_url( 'acrossai-mcp-manager/v1/oauth/register' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'response_types_supported'              => array( 'code' ),
				'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'scopes_supported'                      => array( 'mcp' ),
				'authorization_response_iss_parameter_supported' => true,
				'service_documentation'                 => admin_url( 'admin.php?page=acrossai_mcp_manager' ),
			)
		);
	}

	/**
	 * FR-002 — GET `/.well-known/oauth-protected-resource`.
	 *
	 * @return void
	 */
	public function render_protected_resource_metadata(): void {
		self::send_metadata_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public discovery endpoint, no state mutation.
		$requested_resource = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( (string) $_GET['resource'] ) ) : '';

		wp_send_json(
			array(
				'resource'                 => '' !== $requested_resource ? $requested_resource : rest_url( 'mcp/v1' ),
				'authorization_servers'    => array( self::issuer() ),
				'bearer_methods_supported' => array( 'header' ),
				'scopes_supported'         => array( 'mcp' ),
			)
		);
	}

	/**
	 * Standard cache + CORS headers for both metadata endpoints.
	 */
	private static function send_metadata_headers(): void {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );
	}

	/**
	 * Bare issuer URL. FR-001 requires no trailing slash.
	 *
	 * @return string
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}
}
