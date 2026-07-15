<?php
/**
 * US2 — RFC 7591 DCR fresh registration.
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
class DCRRegisterFreshTest extends OAuthTestCase {

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();
		ClientRegistrationController::instance()->register_routes();
	}

	public function test_valid_body_returns_201_with_opaque_client_id(): void {
		$response = $this->dispatch( array(
			'redirect_uris'              => array( 'https://client.example.com/callback' ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'client_secret_post',
			'client_name'                => 'Example MCP Client',
		) );

		$this->assertSame( 201, $response->get_status() );

		$body = $response->get_data();

		// FR-023 opaque 32-hex client_id (NOT `server-` prefix).
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', (string) $body['client_id'] );
		$this->assertStringStartsNotWith( 'server-', (string) $body['client_id'] );

		// Confidential client → secret returned ONCE.
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', (string) $body['client_secret'] );

		$this->assertGreaterThan( 0, (int) $body['client_id_issued_at'] );
		$this->assertSame( 0, (int) $body['client_secret_expires_at'] );
		$this->assertSame( array( 'https://client.example.com/callback' ), $body['redirect_uris'] );
	}

	public function test_public_client_none_auth_method_omits_secret(): void {
		$response = $this->dispatch( array(
			'redirect_uris'              => array( 'https://client.example.com/callback' ),
			'grant_types'                => array( 'authorization_code' ),
			'token_endpoint_auth_method' => 'none',
		) );

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertArrayNotHasKey( 'client_secret', $body );
	}

	public function test_omitted_auth_method_defaults_to_none_public_client(): void {
		// Claude.ai / ChatGPT shape — public+PKCE client that omits the field.
		// Must default to 'none' so the token exchange doesn't demand a secret
		// the client never carries.
		$response = $this->dispatch( array(
			'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ),
			'grant_types'   => array( 'authorization_code', 'refresh_token' ),
			'client_name'   => 'Claude',
		) );

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'none', $body['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'client_secret', $body );
	}

	public function test_missing_redirect_uris_returns_400(): void {
		$response = $this->dispatch( array(
			'grant_types' => array( 'authorization_code' ),
		) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_unsupported_auth_method_returns_400(): void {
		$response = $this->dispatch( array(
			'redirect_uris'              => array( 'https://client.example.com/callback' ),
			'token_endpoint_auth_method' => 'client_secret_basic',
		) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * @param array<string, mixed> $body
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}
}
