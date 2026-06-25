<?php
/**
 * TASK-SEC-001 — Concurrent redeem MUST result in exactly one success
 * and exactly one failure, AND the winner's token MUST be revoked.
 *
 * This is the load-bearing test from `security-review-plan.md` SEC-001.
 * The fix is the atomic single-statement CAS in
 * `Storage::redeem_authorization_code_cas()`:
 *
 *   UPDATE codes SET completed_at = NOW()
 *   WHERE  id = :id AND completed_at IS NULL
 *
 * MySQL guarantees only one of N concurrent UPDATEs matches the row when
 * the predicate flips during the statement. Loser of the race sees
 * `rows_affected = 0` → REPLAY path (revoke + 400).
 *
 * In a unit-test environment we cannot fork the PHPUnit process; we
 * instead exercise the CAS directly and assert the win-once semantics —
 * which is the contract the controller depends on. We then drive both
 * arms of the controller (winner + loser) sequentially with the SAME
 * pre-CAS state to validate the REPLAY branch in TokenController.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\AuditLog;
use AcrossAI_MCP_Manager\Includes\OAuth\PKCE;
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
use AcrossAI_MCP_Manager\Includes\OAuth\TokenController;
use WP_REST_Request;
use WP_UnitTestCase;

class ConcurrentRedeemRaceTest extends WP_UnitTestCase {

	private int $server_id = 0;
	private string $verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private string $challenge = '';

	public function setUp(): void {
		parent::setUp();
		MCPServerQuery::maybe_create_table();
		CliAuthLogQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();
		$this->challenge = ( new PKCE() )->compute_challenge( $this->verifier );

		$this->server_id = (int) ( new MCPServerQuery() )->add_item(
			array(
				'server_name'                    => 'Srv',
				'server_slug'                    => 'srv',
				'is_enabled'                     => 1,
				'claude_connector_client_id'     => 'client-A',
				'claude_connector_client_secret' => 'secret-A',
				'claude_connector_redirect_uri'  => 'https://app.example/cb',
			)
		);
	}

	public function test_atomic_cas_only_one_caller_wins(): void {
		$raw  = Storage::instance()->issue_authorization_code(
			'client-A', $this->server_id, 1, 'https://app.example/cb', $this->challenge, 'S256', 'mcp'
		);
		$row  = Storage::instance()->lookup_authorization_code( $raw );
		$this->assertNotNull( $row );

		$wins = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			if ( Storage::instance()->redeem_authorization_code_cas( (int) $row->id ) ) {
				$wins++;
			}
		}
		$this->assertSame( 1, $wins, 'Atomic CAS MUST allow exactly one winner across 5 sequential attempts on the same code row.' );
	}

	public function test_concurrent_controller_path_revokes_winner_token(): void {
		// Issue a code and complete the happy path through TokenController.
		$raw  = Storage::instance()->issue_authorization_code(
			'client-A', $this->server_id, 1, 'https://app.example/cb', $this->challenge, 'S256', 'mcp'
		);
		$first = $this->call( $raw );
		$this->assertSame( 200, $first->get_status() );

		// Token row exists, not revoked yet.
		$token_rows_before = ( new OAuthTokenQuery() )->query( array( 'server_id' => $this->server_id ) );
		$this->assertNotEmpty( $token_rows_before );
		$this->assertNull( $token_rows_before[0]->revoked_at );

		// Replay the SAME code — controller MUST take the REPLAY branch and
		// revoke the previously-issued token (FR-014 anti-replay).
		$second = $this->call( $raw );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'invalid_grant', $second->get_data()['error'] );

		$token_rows_after = ( new OAuthTokenQuery() )->query( array( 'server_id' => $this->server_id ) );
		$this->assertCount( 1, $token_rows_after );
		$this->assertNotNull( $token_rows_after[0]->revoked_at, 'Winner token MUST be revoked when the same code is replayed.' );

		// Audit must record the replay attempt + the token_revoked event.
		$replay = ( new OAuthAuditQuery() )->query( array( 'event_type' => AuditLog::EVENT_FAILED_REPLAY_ATTEMPT ) );
		$this->assertNotEmpty( $replay );
		$revoked = ( new OAuthAuditQuery() )->query( array( 'event_type' => AuditLog::EVENT_TOKEN_REVOKED ) );
		$this->assertNotEmpty( $revoked );
	}

	private function call( string $raw_code ) {
		$req = new WP_REST_Request( 'POST', '/' . TokenController::REST_NAMESPACE . TokenController::REST_ROUTE );
		$req->set_param( 'grant_type', 'authorization_code' );
		$req->set_param( 'code', $raw_code );
		$req->set_param( 'client_id', 'client-A' );
		$req->set_param( 'client_secret', 'secret-A' );
		$req->set_param( 'redirect_uri', 'https://app.example/cb' );
		$req->set_param( 'code_verifier', $this->verifier );
		return TokenController::instance()->handle_request( $req );
	}
}
