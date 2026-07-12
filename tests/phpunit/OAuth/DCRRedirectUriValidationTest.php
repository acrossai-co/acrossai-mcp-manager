<?php
/**
 * US2 / SEC-021-004 — Strict redirect URI scheme validation.
 *
 * FR-021 explicit rejection list: `javascript:`, `data:`, `file:`, `ftp:`,
 * `gopher:`, `mailto:`, `about:`, `chrome:`, `chrome-extension:` MUST be
 * rejected even if downstream logic would otherwise accept them.
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
class DCRRedirectUriValidationTest extends OAuthTestCase {

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();
		ClientRegistrationController::instance()->register_routes();
	}

	public function test_https_accepted(): void {
		$response = $this->dispatch_with_uri( 'https://client.example.com/callback' );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_http_non_loopback_rejected(): void {
		$response = $this->dispatch_with_uri( 'http://client.example.com/callback' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_loopback_http_accepted(): void {
		$response = $this->dispatch_with_uri( 'http://127.0.0.1:33333/callback' );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_loopback_localhost_accepted(): void {
		$response = $this->dispatch_with_uri( 'http://localhost:33333/callback' );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_javascript_scheme_rejected(): void {
		$response = $this->dispatch_with_uri( 'javascript:alert(1)' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_data_scheme_rejected(): void {
		$response = $this->dispatch_with_uri( 'data:text/html,<script>alert(1)</script>' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_file_scheme_rejected(): void {
		$response = $this->dispatch_with_uri( 'file:///etc/passwd' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_ftp_scheme_rejected(): void {
		$response = $this->dispatch_with_uri( 'ftp://client.example.com/cb' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_case_insensitive_javascript_rejected(): void {
		$response = $this->dispatch_with_uri( 'JavaScript:alert(1)' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_one_bad_uri_rejects_whole_registration(): void {
		$response = $this->dispatch( array(
			'redirect_uris' => array(
				'https://client.example.com/cb',
				'javascript:alert(1)', // one bad apple
			),
		) );
		$this->assertSame( 400, $response->get_status() );
	}

	private function dispatch_with_uri( string $uri ): \WP_REST_Response {
		return $this->dispatch( array( 'redirect_uris' => array( $uri ) ) );
	}

	/**
	 * @param array<string, mixed> $body
	 * @return \WP_REST_Response
	 */
	private function dispatch( array $body ): \WP_REST_Response {
		$defaults = array(
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'client_secret_post',
		);

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array_merge( $defaults, $body ) ) );

		return rest_do_request( $request );
	}
}
