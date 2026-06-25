<?php
/**
 * US2 — POST /auth/start endpoint coverage including TASK-Q2 Content-Type gates.
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\REST\CliController;
use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_REST_Request;
use WP_UnitTestCase;

class AuthStartEndpointTest extends WP_UnitTestCase {

	private function call_with( string $content_type, array $params ) {
		$req = new WP_REST_Request( 'POST', '/' . CliController::REST_NAMESPACE . '/auth/start' );
		if ( '' !== $content_type ) {
			$req->set_header( 'Content-Type', $content_type );
		}
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return CliController::instance()->handle_auth_start( $req );
	}

	public function test_happy_path_returns_envelope(): void {
		$resp = $this->call_with( 'application/json', array( 'server_id' => 'wp-default-server' ) );
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $data['auth_code'] );
		$this->assertSame( 300, $data['expires_in'] );
		$this->assertStringStartsWith( FrontendAuth::get_base_url(), $data['auth_url'] );
		$this->assertStringContainsString( 'action=cli_auth', $data['auth_url'] );
		$this->assertStringContainsString( 'code=' . $data['auth_code'], $data['auth_url'] );
		$this->assertStringContainsString( 'server=wp-default-server', $data['auth_url'] );
	}

	public function test_transient_shape(): void {
		$resp = $this->call_with( 'application/json', array( 'server_id' => 'srv-x' ) );
		$auth_code = $resp->get_data()['auth_code'];

		$payload = get_transient( CliController::AUTH_TRANSIENT_PREFIX . $auth_code );
		$this->assertIsArray( $payload );
		$this->assertSame( 'srv-x', $payload['server_id'] );
		$this->assertSame( 'pending', $payload['status'] );
		$this->assertNull( $payload['user_id'] );
		$this->assertNull( $payload['session_token'] );
		$this->assertGreaterThan( time() - 5, (int) $payload['created_at'] );
	}

	public function test_two_calls_produce_different_codes(): void {
		$a = $this->call_with( 'application/json', array( 'server_id' => 'srv-a' ) )->get_data()['auth_code'];
		$b = $this->call_with( 'application/json', array( 'server_id' => 'srv-a' ) )->get_data()['auth_code'];
		$this->assertNotSame( $a, $b );
	}

	public function test_empty_server_id_rejected(): void {
		$resp = $this->call_with( 'application/json', array( 'server_id' => '' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_request', $resp->get_data()['error'] );
	}

	public function test_content_type_form_urlencoded_accepted(): void {
		$resp = $this->call_with( 'application/x-www-form-urlencoded', array( 'server_id' => 'srv-y' ) );
		$this->assertSame( 200, $resp->get_status() );
	}

	public function test_content_type_text_plain_rejected(): void {
		$resp = $this->call_with( 'text/plain', array( 'server_id' => 'srv-z' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_request', $resp->get_data()['error'] );
	}

	public function test_missing_content_type_rejected(): void {
		$resp = $this->call_with( '', array( 'server_id' => 'srv-q' ) );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_request', $resp->get_data()['error'] );
	}
}
