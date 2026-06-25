<?php
/**
 * Consent form POST handling — Approve issues code + audit, Deny issues
 * audit + redirects with error=access_denied.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\AuditLog;
use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;
use WP_UnitTestCase;

class ConsentSubmitTest extends WP_UnitTestCase {

	private int $server_id = 0;
	private int $admin_id  = 0;

	public function setUp(): void {
		parent::setUp();
		MCPServerQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();
		CliAuthLogQuery::maybe_create_table();

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
		$this->admin_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	public function tearDown(): void {
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	public function test_approve_path_issues_code_and_audits(): void {
		$nonce_action = 'acrossai_mcp_oauth_consent_' . $this->server_id;
		$_POST = $this->base_post( 'approve' );
		$_POST['_wpnonce'] = wp_create_nonce( $nonce_action );

		ob_start();
		try {
			ClaudeConnectors::instance()->handle_consent_submit();
		} catch ( \Throwable $e ) {
			// redirect+exit can't be intercepted; assertions below are persistence-only.
		}
		ob_end_clean();

		// Audit row written.
		$rows = ( new OAuthAuditQuery() )->query(
			array( 'event_type' => AuditLog::EVENT_CODE_ISSUED, 'number' => 1 )
		);
		$this->assertNotEmpty( $rows );

		// Code row written (status = 'oauth_code_issued').
		$codes = ( new CliAuthLogQuery() )->query(
			array( 'status' => 'oauth_code_issued', 'number' => 1 )
		);
		$this->assertNotEmpty( $codes );
		$this->assertSame( 'https://app.example/cb', $codes[0]->redirect_uri );
		$this->assertSame( 'mcp', $codes[0]->scope );
	}

	public function test_deny_path_audits_consent_denied(): void {
		$nonce_action = 'acrossai_mcp_oauth_consent_' . $this->server_id;
		$_POST = $this->base_post( 'deny' );
		$_POST['_wpnonce'] = wp_create_nonce( $nonce_action );

		ob_start();
		try {
			ClaudeConnectors::instance()->handle_consent_submit();
		} catch ( \Throwable $e ) {
			// redirect.
		}
		ob_end_clean();

		$rows = ( new OAuthAuditQuery() )->query(
			array( 'event_type' => AuditLog::EVENT_CONSENT_DENIED, 'number' => 1 )
		);
		$this->assertNotEmpty( $rows );
	}

	public function test_missing_nonce_dies(): void {
		$_POST = $this->base_post( 'approve' ); // no _wpnonce.

		$this->expectException( \WPDieException::class );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $msg, $title, $args ) {
					throw new \WPDieException( (string) $msg );
				};
			}
		);

		ClaudeConnectors::instance()->handle_consent_submit();
	}

	/**
	 * @return array<string, string>
	 */
	private function base_post( string $decision ): array {
		return array(
			'oauth_decision'              => $decision,
			'client_id'                   => 'client-A',
			'redirect_uri'                => 'https://app.example/cb',
			'scope'                       => 'mcp',
			'state'                       => 's1',
			'code_challenge'              => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			'code_challenge_method'       => 'S256',
			'acrossai_mcp_oauth_server_id' => (string) $this->server_id,
		);
	}
}

if ( ! class_exists( '\\WPDieException' ) ) {
	class_alias( '\\Exception', '\\WPDieException' );
}
