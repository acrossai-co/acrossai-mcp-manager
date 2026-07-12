<?php
/**
 * US4 — RFC 8414 + RFC 9728 discovery metadata shape.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryController;

/**
 * @coversNothing
 */
class DiscoveryMetadataTest extends OAuthTestCase {

	public function test_authorization_server_metadata_shape(): void {
		$body = $this->capture_json_output( fn () => DiscoveryController::instance()->render_authorization_server_metadata() );

		$this->assertIsArray( $body );

		// Required fields per FR-001.
		$this->assertSame( untrailingslashit( home_url() ), $body['issuer'] );
		$this->assertStringEndsWith( '/authorize', $body['authorization_endpoint'] );
		$this->assertStringEndsWith( '/token', $body['token_endpoint'] );
		$this->assertStringContainsString( 'acrossai-mcp-manager/v1/oauth/register', $body['registration_endpoint'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $body['grant_types_supported'] );
		$this->assertSame( array( 'code' ), $body['response_types_supported'] );
		$this->assertSame( array( 'none', 'client_secret_post' ), $body['token_endpoint_auth_methods_supported'] );

		// Anthropic MCP spec — S256 ONLY, no plain.
		$this->assertSame( array( 'S256' ), $body['code_challenge_methods_supported'] );

		// RFC 9207 iss parameter advertised.
		$this->assertTrue( $body['authorization_response_iss_parameter_supported'] );

		// Single scope.
		$this->assertSame( array( 'mcp' ), $body['scopes_supported'] );
	}

	public function test_protected_resource_metadata_shape(): void {
		$body = $this->capture_json_output( fn () => DiscoveryController::instance()->render_protected_resource_metadata() );

		$this->assertIsArray( $body );

		$this->assertNotEmpty( $body['resource'] );
		$this->assertSame( array( untrailingslashit( home_url() ) ), $body['authorization_servers'] );
		$this->assertSame( array( 'header' ), $body['bearer_methods_supported'] );
		$this->assertSame( array( 'mcp' ), $body['scopes_supported'] );
	}

	public function test_protected_resource_echoes_requested_resource(): void {
		$_GET['resource'] = 'https://this-site.example.com/wp-json/mcp/v1/server-42';

		$body = $this->capture_json_output( fn () => DiscoveryController::instance()->render_protected_resource_metadata() );

		$this->assertSame( 'https://this-site.example.com/wp-json/mcp/v1/server-42', $body['resource'] );

		unset( $_GET['resource'] );
	}

	/**
	 * Capture JSON body echoed by a controller callback that would normally
	 * `wp_send_json` + `exit`. Uses output buffering + an early-exit guard.
	 *
	 * @param callable $callback
	 * @return array<string, mixed>|null
	 */
	private function capture_json_output( callable $callback ) {
		ob_start();
		try {
			// wp_send_json calls die() internally in production; under WP_UnitTestCase
			// die is caught via the WPDieException filter.
			add_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );
			try {
				$callback();
			} catch ( \WPDieException $e ) {
				// Expected — wp_send_json → wp_die().
			}
		} finally {
			remove_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );
		}
		$output = ob_get_clean();
		$decoded = json_decode( (string) $output, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @return callable
	 */
	public function get_wp_die_handler(): callable {
		return static function ( $message ) {
			throw new \WPDieException( is_string( $message ) ? $message : '' );
		};
	}
}
