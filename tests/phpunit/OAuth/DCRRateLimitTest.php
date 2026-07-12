<?php
/**
 * US2 / FR-027 — DCR rate limit: 10 requests per IP per 60s.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController;

/**
 * @coversNothing
 */
class DCRRateLimitTest extends OAuthTestCase {

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();
		ClientRegistrationController::instance()->register_routes();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
	}

	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	public function test_eleventh_request_returns_429(): void {
		// First 10 succeed. Each has a unique client_name to avoid dedup.
		for ( $i = 1; $i <= 10; $i++ ) {
			$response = $this->dispatch( 'Client ' . $i );
			$this->assertNotSame( 429, $response->get_status(), 'Request #' . $i . ' MUST NOT be rate-limited' );
		}

		// 11th → 429 with slow_down.
		$eleventh = $this->dispatch( 'Client 11' );
		$this->assertSame( 429, $eleventh->get_status() );

		$data = $eleventh->get_data();
		if ( $data instanceof \WP_Error ) {
			$this->assertSame( 'slow_down', $data->get_error_code() );
		} elseif ( is_array( $data ) ) {
			$this->assertSame( 'slow_down', $data['code'] ?? '' );
		}
	}

	public function test_different_ip_gets_fresh_budget(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->dispatch( 'Client ' . $i );
		}

		// Same 11th call from IP #42 → 429.
		$blocked = $this->dispatch( 'Client 11' );
		$this->assertSame( 429, $blocked->get_status() );

		// Different IP → succeeds.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$fresh = $this->dispatch( 'Different IP Client' );
		$this->assertNotSame( 429, $fresh->get_status() );
	}

	private function dispatch( string $client_name ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array(
			'redirect_uris'              => array( 'https://client-' . md5( $client_name ) . '.example.com/cb' ),
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'client_secret_post',
			'client_name'                => $client_name,
		) ) );

		return rest_do_request( $request );
	}
}
