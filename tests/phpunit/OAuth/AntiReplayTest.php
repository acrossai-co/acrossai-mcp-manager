<?php
/**
 * Sequential second-redemption test (vs ConcurrentRedeemRaceTest which
 * exercises the same code path under simulated concurrency).
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

class AntiReplayTest extends WP_UnitTestCase {

	public function test_sequential_replay_writes_failed_replay_audit(): void {
		MCPServerQuery::maybe_create_table();
		CliAuthLogQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();

		$server_id = (int) ( new MCPServerQuery() )->add_item(
			array(
				'server_name'                    => 'Srv',
				'server_slug'                    => 'srv-replay',
				'is_enabled'                     => 1,
				'claude_connector_client_id'     => 'client-Z',
				'claude_connector_client_secret' => 'secret-Z',
				'claude_connector_redirect_uri'  => 'https://app.example/cb',
			)
		);
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = ( new PKCE() )->compute_challenge( $verifier );
		$raw       = Storage::instance()->issue_authorization_code(
			'client-Z', $server_id, 1, 'https://app.example/cb', $challenge, 'S256', 'mcp'
		);

		$first  = $this->call( $raw, 'client-Z', 'secret-Z', $verifier );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->call( $raw, 'client-Z', 'secret-Z', $verifier );
		$this->assertSame( 400, $second->get_status() );

		$rows = ( new OAuthAuditQuery() )->query( array( 'event_type' => AuditLog::EVENT_FAILED_REPLAY_ATTEMPT ) );
		$this->assertNotEmpty( $rows );
	}

	private function call( string $code, string $cid, string $sec, string $verifier ) {
		$req = new WP_REST_Request( 'POST', '/' . TokenController::REST_NAMESPACE . TokenController::REST_ROUTE );
		$req->set_param( 'grant_type', 'authorization_code' );
		$req->set_param( 'code', $code );
		$req->set_param( 'client_id', $cid );
		$req->set_param( 'client_secret', $sec );
		$req->set_param( 'redirect_uri', 'https://app.example/cb' );
		$req->set_param( 'code_verifier', $verifier );
		return TokenController::instance()->handle_request( $req );
	}
}
