<?php
/**
 * Per-RFC §5.2 error envelope tests — one method per error path, one
 * golden assertion each. Matches the spec DoD: "PHPUnit: full RFC-
 * conformance test suite (per-RFC-section coverage)".
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

class TokenControllerErrorPathsTest extends WP_UnitTestCase {

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

	public function test_missing_field_returns_invalid_request(): void {
		$params = $this->base_params();
		unset( $params['redirect_uri'] );
		$resp = $this->call( $params );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_request', $resp->get_data()['error'] );
	}

	public function test_wrong_grant_type_returns_unsupported(): void {
		$params               = $this->base_params();
		$params['grant_type'] = 'password';
		$resp                 = $this->call( $params );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'unsupported_grant_type', $resp->get_data()['error'] );
	}

	public function test_unknown_client_returns_invalid_client(): void {
		$params              = $this->base_params();
		$params['client_id'] = 'totally-unknown';
		$resp                = $this->call( $params );
		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'invalid_client', $resp->get_data()['error'] );
	}

	public function test_wrong_client_secret_returns_invalid_client(): void {
		$params                  = $this->base_params();
		$params['client_secret'] = 'WRONG';
		$resp                    = $this->call( $params );
		$this->assertSame( 401, $resp->get_status() );
		$this->assertSame( 'invalid_client', $resp->get_data()['error'] );
	}

	public function test_unknown_code_returns_invalid_grant(): void {
		$params         = $this->base_params();
		$params['code'] = 'never-issued-code';
		$resp           = $this->call( $params );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_grant', $resp->get_data()['error'] );
	}

	public function test_expired_code_returns_invalid_grant(): void {
		global $wpdb;
		$params           = $this->base_params();
		$params['code']   = $this->issue_code();
		// Backdate the code row to >10 min ago.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}acrossai_mcp_cli_auth_logs SET created_at = %s WHERE status = 'oauth_code_issued'",
				gmdate( 'Y-m-d H:i:s', time() - 1200 )
			)
		);
		$resp = $this->call( $params );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_grant', $resp->get_data()['error'] );
	}

	public function test_wrong_redirect_uri_returns_invalid_grant(): void {
		$params                 = $this->base_params();
		$params['code']         = $this->issue_code();
		$params['redirect_uri'] = 'https://attacker.example/cb';
		$resp                   = $this->call( $params );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_grant', $resp->get_data()['error'] );
	}

	public function test_pkce_verifier_mismatch_returns_invalid_grant(): void {
		$params                 = $this->base_params();
		$params['code']         = $this->issue_code();
		$params['code_verifier'] = 'WRONGwBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFW';
		$resp                   = $this->call( $params );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'invalid_grant', $resp->get_data()['error'] );
		$this->assertStringContainsString( 'PKCE', $resp->get_data()['error_description'] );
	}

	/**
	 * @return array<string, string>
	 */
	private function base_params(): array {
		return array(
			'grant_type'    => 'authorization_code',
			'code'          => 'placeholder',
			'client_id'     => 'client-A',
			'client_secret' => 'secret-A',
			'redirect_uri'  => 'https://app.example/cb',
			'code_verifier' => $this->verifier,
		);
	}

	private function issue_code(): string {
		return Storage::instance()->issue_authorization_code(
			'client-A',
			$this->server_id,
			1,
			'https://app.example/cb',
			$this->challenge,
			'S256',
			'mcp'
		);
	}

	private function call( array $params ) {
		$req = new WP_REST_Request( 'POST', '/' . TokenController::REST_NAMESPACE . TokenController::REST_ROUTE );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return TokenController::instance()->handle_request( $req );
	}
}
