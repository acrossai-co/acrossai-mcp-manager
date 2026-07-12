<?php
/**
 * SEC-021-T02 — `setup_instructions_html` MUST pass through wp_kses_post.
 *
 * Companion plugin's `get_setup_instructions()` returns raw HTML. The
 * ClientRegistrationController MUST strip <script> and javascript: URLs
 * before returning the string to the admin browser. Regression guard.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController;

/**
 * @coversNothing
 */
class AdminGenerateClientHtmlSanitizationTest extends OAuthTestCase {

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge( $p, array( new MaliciousProfile() ) )
		);

		ClientRegistrationController::instance()->register_routes();
	}

	public function test_script_tag_stripped_from_setup_instructions(): void {
		$server_id = $this->seed_mcp_server();

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/generate-client' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body_params( array(
			'server_id'      => $server_id,
			'connector_slug' => 'malicious-connector',
		) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$html = (string) $response->get_data()['setup_instructions_html'];

		// <pre> content survives — good.
		$this->assertStringContainsString( '<pre>ok</pre>', $html );

		// <script> tags stripped.
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html );

		// javascript: URL rewritten by kses.
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	private function seed_mcp_server(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_servers',
			array(
				'server_slug' => 'test-mcp-server-xss',
				'server_name' => 'Test MCP Server (XSS)',
				'is_enabled'  => 1,
				'created_at'  => current_time( 'mysql', 1 ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}

/**
 * Deliberately malicious profile — get_setup_instructions returns XSS payloads.
 */
final class MaliciousProfile extends AbstractConnectorProfile {

	public function get_slug(): string {
		return 'malicious-connector';
	}

	public function get_name(): string {
		return 'Malicious Connector';
	}

	public function get_icon_url(): string {
		return 'https://example.com/mal.svg';
	}

	public function get_redirect_uri_whitelist(): array {
		return array( 'https://malicious.example.com/callback' );
	}

	public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
		return '<script>alert(1)</script><pre>ok</pre><a href="javascript:evil()">click</a>';
	}

	public function render_tab_section( array $server ): void {
		echo 'malicious';
	}
}
