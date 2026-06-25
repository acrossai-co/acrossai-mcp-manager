<?php
/**
 * Rate-limit thresholds A (5/min) and B (50/hr) — FR-014a.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
use AcrossAI_MCP_Manager\Includes\OAuth\TokenController;
use WP_REST_Request;
use WP_UnitTestCase;

class RateLimitTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		MCPServerQuery::maybe_create_table();
		CliAuthLogQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	public function test_minute_bucket_returns_429_with_retry_after(): void {
		( new MCPServerQuery() )->add_item(
			array(
				'server_name'                    => 'srv',
				'server_slug'                    => 'srv-rate',
				'claude_connector_client_id'     => 'client-RL',
				'claude_connector_client_secret' => 'secret-RL',
				'claude_connector_redirect_uri'  => 'https://x/cb',
			)
		);

		// Saturate the minute bucket with failing requests.
		for ( $i = 0; $i < Storage::RATE_LIMIT_MINUTE_THRESHOLD; $i++ ) {
			$resp = $this->call_invalid();
			$this->assertContains( $resp->get_status(), array( 400, 401 ) );
		}

		$blocked = $this->call_invalid();
		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'slow_down', $blocked->get_data()['error'] );
		$this->assertSame( '60', $blocked->get_headers()['Retry-After'] );
	}

	private function call_invalid() {
		$req = new WP_REST_Request( 'POST', '/' . TokenController::REST_NAMESPACE . TokenController::REST_ROUTE );
		$req->set_param( 'grant_type', 'authorization_code' );
		$req->set_param( 'code', 'totally-bogus' );
		$req->set_param( 'client_id', 'client-RL' );
		$req->set_param( 'client_secret', 'wrong' );
		$req->set_param( 'redirect_uri', 'https://x/cb' );
		$req->set_param( 'code_verifier', str_repeat( 'a', 43 ) );
		return TokenController::instance()->handle_request( $req );
	}
}
