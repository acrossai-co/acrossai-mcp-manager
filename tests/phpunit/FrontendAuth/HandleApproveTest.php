<?php
/**
 * FrontendAuth::handle_approve() — exercise the state-mutating Approve path.
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Includes\REST\CliController;
use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

class HandleApproveTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
	}

	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	public function test_missing_nonce_dies(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_GET = array(
			'action' => 'cli_auth_approve',
			'code'   => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
			'server' => 'srv',
			// no _wpnonce intentionally.
		);

		$this->expectException( \WPDieException::class );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $msg ) {
					throw new \WPDieException( (string) $msg );
				};
			}
		);

		FrontendAuth::instance()->maybe_render_page();
	}

	public function test_valid_approve_calls_controller_static_method(): void {
		// Seed the pending E1 transient — `approve_auth_code` reads it.
		$code = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
		set_transient(
			CliController::AUTH_TRANSIENT_PREFIX . $code,
			array(
				'server_id'     => 'srv-test',
				'status'        => 'pending',
				'user_id'       => null,
				'session_token' => null,
				'created_at'    => time(),
			),
			CliController::AUTH_CODE_TTL
		);

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$nonce = wp_create_nonce( 'cli_auth_approve_' . $code );
		$_GET  = array(
			'action'   => 'cli_auth_approve',
			'code'     => $code,
			'server'   => 'srv-test',
			'_wpnonce' => $nonce,
		);

		// The Approve path performs wp_safe_redirect + exit; intercept via output buffer.
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Throwable $e ) {
			// exit cannot be intercepted; rely on transient mutation as evidence.
		}
		ob_end_clean();

		// After approval, E1 transient MUST be flipped to 'approved' + carry user_id.
		$payload = get_transient( CliController::AUTH_TRANSIENT_PREFIX . $code );
		$this->assertIsArray( $payload );
		$this->assertSame( 'approved', $payload['status'] );
		$this->assertSame( $admin, (int) $payload['user_id'] );
		$this->assertNotEmpty( $payload['session_token'] );
	}
}

if ( ! class_exists( '\\WPDieException' ) ) {
	class_alias( '\\Exception', '\\WPDieException' );
}
