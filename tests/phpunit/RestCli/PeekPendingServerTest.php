<?php
/**
 * CliController::peek_pending_server() — pure-read transient peek for SEC-001.
 *
 * Covers contracts/cli-controller-peek-pending-server.md.
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\REST\CliController;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- test methods self-document via descriptive names; matches existing tests/phpunit/* convention.

class PeekPendingServerTest extends WP_UnitTestCase {

	const TRANSIENT_PREFIX = 'acrossai_cli_auth_';

	public function tearDown(): void {
		// Clean up transients touched by tests.
		foreach ( array( 'codeA', 'codeB', 'codeC', 'codeMalformed', 'codeNotPending', 'codeEmptyServer', 'codeNonStringServer', 'codeIdempotent' ) as $c ) {
			delete_transient( self::TRANSIENT_PREFIX . $c );
		}
		parent::tearDown();
	}

	public function test_empty_code_returns_null(): void {
		$this->assertNull( CliController::peek_pending_server( '' ) );
	}

	public function test_unknown_code_returns_null(): void {
		$this->assertNull( CliController::peek_pending_server( 'codeA' ) );
	}

	public function test_malformed_payload_non_array_returns_null(): void {
		set_transient( self::TRANSIENT_PREFIX . 'codeMalformed', 'not-an-array', 300 );
		$this->assertNull( CliController::peek_pending_server( 'codeMalformed' ) );
	}

	public function test_missing_status_key_returns_null(): void {
		set_transient(
			self::TRANSIENT_PREFIX . 'codeA',
			array( 'server_id' => 'real-server' ), // no 'status' key
			300
		);
		$this->assertNull( CliController::peek_pending_server( 'codeA' ) );
	}

	public function test_missing_server_id_key_returns_null(): void {
		set_transient(
			self::TRANSIENT_PREFIX . 'codeA',
			array( 'status' => 'pending' ), // no 'server_id' key
			300
		);
		$this->assertNull( CliController::peek_pending_server( 'codeA' ) );
	}

	public function test_status_approved_returns_null(): void {
		set_transient(
			self::TRANSIENT_PREFIX . 'codeNotPending',
			array(
				'status'    => 'approved',
				'server_id' => 'real-server',
				'user_id'   => 1,
			),
			300
		);
		$this->assertNull( CliController::peek_pending_server( 'codeNotPending' ) );
	}

	public function test_empty_server_id_returns_null(): void {
		set_transient(
			self::TRANSIENT_PREFIX . 'codeEmptyServer',
			array(
				'status'    => 'pending',
				'server_id' => '',
			),
			300
		);
		$this->assertNull( CliController::peek_pending_server( 'codeEmptyServer' ) );
	}

	public function test_non_string_server_id_returns_null(): void {
		set_transient(
			self::TRANSIENT_PREFIX . 'codeNonStringServer',
			array(
				'status'    => 'pending',
				'server_id' => 12345, // int, not string
			),
			300
		);
		$this->assertNull( CliController::peek_pending_server( 'codeNonStringServer' ) );
	}

	public function test_valid_pending_returns_server_id_verbatim(): void {
		set_transient(
			self::TRANSIENT_PREFIX . 'codeA',
			array(
				'status'     => 'pending',
				'server_id'  => 'wordpress-default-server',
				'created_at' => time(),
			),
			300
		);
		$this->assertSame(
			'wordpress-default-server',
			CliController::peek_pending_server( 'codeA' )
		);
	}

	public function test_idempotent_no_state_change(): void {
		set_transient(
			self::TRANSIENT_PREFIX . 'codeIdempotent',
			array(
				'status'    => 'pending',
				'server_id' => 'srv',
			),
			300
		);

		$first  = CliController::peek_pending_server( 'codeIdempotent' );
		$second = CliController::peek_pending_server( 'codeIdempotent' );
		$third  = CliController::peek_pending_server( 'codeIdempotent' );

		$this->assertSame( 'srv', $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $second, $third );

		// State unchanged — re-read raw payload and confirm status still pending.
		$payload = get_transient( self::TRANSIENT_PREFIX . 'codeIdempotent' );
		$this->assertIsArray( $payload );
		$this->assertSame( 'pending', $payload['status'] );
		$this->assertSame( 'srv', $payload['server_id'] );
	}

	public function test_server_id_with_special_chars_returns_verbatim(): void {
		// peek_pending_server is a value-passthrough; escaping is the caller's
		// responsibility (handle_cli_auth uses esc_html() at render).
		set_transient(
			self::TRANSIENT_PREFIX . 'codeB',
			array(
				'status'    => 'pending',
				'server_id' => '<script>alert(1)</script>',
			),
			300
		);
		$this->assertSame(
			'<script>alert(1)</script>',
			CliController::peek_pending_server( 'codeB' )
		);
	}
}
