<?php
/**
 * US3 — GET /auth/status endpoint coverage including TASK-Q4 oracle defense.
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\REST\CliController;
use WP_REST_Request;
use WP_UnitTestCase;

class AuthStatusEndpointTest extends WP_UnitTestCase {

	private function call( string $code, string $server ) {
		$req = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/auth/status' );
		$req->set_param( 'code', $code );
		$req->set_param( 'server', $server );
		return CliController::instance()->handle_auth_status( $req );
	}

	private function seed_pending( string $code, string $server ): void {
		set_transient(
			CliController::AUTH_TRANSIENT_PREFIX . $code,
			array(
				'server_id'     => $server,
				'status'        => 'pending',
				'user_id'       => null,
				'session_token' => null,
				'created_at'    => time(),
			),
			CliController::AUTH_CODE_TTL
		);
	}

	private function seed_approved( string $code, string $server, string $token, int $user_id = 1 ): void {
		set_transient(
			CliController::AUTH_TRANSIENT_PREFIX . $code,
			array(
				'server_id'     => $server,
				'status'        => 'approved',
				'user_id'       => $user_id,
				'session_token' => $token,
				'created_at'    => time(),
			),
			CliController::AUTH_CODE_TTL
		);
	}

	public function test_pending_returns_not_approved(): void {
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
		$this->seed_pending( $code, 'srv-a' );

		$resp = $this->call( $code, 'srv-a' );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( 'approved' => false ), $resp->get_data() );
	}

	public function test_approved_returns_token(): void {
		$code  = 'a1b2c3d4e5f60718293a4b5c6d7e8f91';
		$token = 'feedfacefeedfacefeedfacefeedface';
		$this->seed_approved( $code, 'srv-b', $token, 42 );

		$resp = $this->call( $code, 'srv-b' );
		$this->assertSame( 200, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertTrue( $data['approved'] );
		$this->assertSame( $token, $data['token'] );
	}

	public function test_unknown_code_returns_404(): void {
		$resp = $this->call( 'never-issued-code', 'srv-x' );
		$this->assertSame( 404, $resp->get_error_data()['status'] ?? 0 );
		$this->assertSame( 'auth_code_not_found', $resp->get_error_code() );
	}

	public function test_server_mismatch_returns_pending_no_oracle(): void {
		// TASK-Q4 — wrong server in the query MUST return {approved:false}, NOT 404.
		$code  = 'a1b2c3d4e5f60718293a4b5c6d7e8f92';
		$token = 'deadbeefdeadbeefdeadbeefdeadbeef';
		$this->seed_approved( $code, 'srv-real', $token, 7 );

		$resp = $this->call( $code, 'srv-WRONG' );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( 'approved' => false ), $resp->get_data() );
	}
}
