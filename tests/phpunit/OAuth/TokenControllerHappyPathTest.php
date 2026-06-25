<?php
/**
 * Token endpoint happy path — issue code, exchange via TokenController,
 * verify RFC 6749 §5.1 success envelope.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\PKCE;
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
use AcrossAI_MCP_Manager\Includes\OAuth\TokenController;
use WP_REST_Request;
use WP_UnitTestCase;

class TokenControllerHappyPathTest extends WP_UnitTestCase {

	private int $server_id = 0;

	public function setUp(): void {
		parent::setUp();
		MCPServerQuery::maybe_create_table();
		CliAuthLogQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();

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

	public function test_full_exchange_returns_rfc_5_1_envelope(): void {
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = ( new PKCE() )->compute_challenge( $verifier );
		$raw_code  = Storage::instance()->issue_authorization_code(
			'client-A',
			$this->server_id,
			1,
			'https://app.example/cb',
			$challenge,
			'S256',
			'mcp'
		);
		$this->assertNotSame( '', $raw_code );

		$resp = $this->call(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $raw_code,
				'client_id'     => 'client-A',
				'client_secret' => 'secret-A',
				'redirect_uri'  => 'https://app.example/cb',
				'code_verifier' => $verifier,
			)
		);
		$this->assertSame( 200, $resp->get_status() );

		$data = $resp->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertSame( 'Bearer', $data['token_type'] );
		$this->assertSame( Storage::ACCESS_TOKEN_TTL_SECONDS, $data['expires_in'] );
		$this->assertSame( 'mcp', $data['scope'] );
		$this->assertSame( 'no-store', $resp->get_headers()['Cache-Control'] );

		$token_rows = ( new OAuthTokenQuery() )->query( array( 'server_id' => $this->server_id, 'number' => 1 ) );
		$this->assertNotEmpty( $token_rows );
	}

	private function call( array $params ) {
		$req = new WP_REST_Request( 'POST', '/' . TokenController::REST_NAMESPACE . TokenController::REST_ROUTE );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return TokenController::instance()->handle_request( $req );
	}
}
