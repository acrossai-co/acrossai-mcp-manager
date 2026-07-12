<?php
/**
 * US1 — Admin generate-client happy-path tests.
 *
 * Verifies structured client_id format (Q2), raw client_secret returned
 * once, row persistence with hashed secret, sanitized setup instructions.
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
class AdminGenerateClientTest extends OAuthTestCase {

	private int $admin_user_id = 0;

	private int $server_id = 0;

	/**
	 * Set up admin user + seed one server + register a stub connector profile.
	 */
	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();

		$this->admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->server_id = $this->seed_mcp_server();

		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge( $p, array( new AdminGenTestProfile() ) )
		);

		ClientRegistrationController::instance()->register_routes();
	}

	public function test_happy_path_returns_structured_client_id_and_raw_secret(): void {
		$response = $this->dispatch_generate();

		$this->assertSame( 200, $response->get_status() );

		$body = $response->get_data();
		$this->assertIsArray( $body );

		$this->assertMatchesRegularExpression(
			'/\Aserver-\d+-claude-desktop-[a-f0-9]{8}\z/',
			(string) $body['client_id'],
			'Q2 client_id format violated'
		);
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', (string) $body['client_secret'] );
		$this->assertFalse( (bool) $body['regenerated'] );
		$this->assertNotEmpty( $body['setup_instructions_html'] );
	}

	public function test_row_persisted_with_hashed_secret_not_raw(): void {
		global $wpdb;

		$response = $this->dispatch_generate();
		$body     = $response->get_data();

		$raw_secret = (string) $body['client_secret'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT client_secret_hash FROM %i WHERE client_id = %s',
				$wpdb->prefix . 'acrossai_mcp_oauth_clients',
				$body['client_id']
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertNotSame( $raw_secret, $row['client_secret_hash'] );
		$this->assertSame( hash( 'sha256', $raw_secret ), $row['client_secret_hash'] );
	}

	private function dispatch_generate(): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/generate-client' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body_params( array(
			'server_id'      => $this->server_id,
			'connector_slug' => 'claude-desktop',
		) );

		return rest_do_request( $request );
	}

	private function seed_mcp_server(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_servers',
			array(
				'server_slug' => 'test-mcp-server',
				'server_name' => 'Test MCP Server',
				'is_enabled'  => 1,
				'created_at'  => current_time( 'mysql', 1 ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}

/**
 * Minimal test profile for AdminGenerateClientTest.
 */
final class AdminGenTestProfile extends AbstractConnectorProfile {

	public function get_slug(): string {
		return 'claude-desktop';
	}

	public function get_name(): string {
		return 'Claude Desktop';
	}

	public function get_icon_url(): string {
		return 'https://example.com/claude.svg';
	}

	public function get_redirect_uri_whitelist(): array {
		return array( 'https://claude.ai/api/mcp/auth_callback' );
	}

	public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
		return '<p><strong>Setup:</strong> paste ' . esc_html( $client_id ) . '</p>';
	}

	public function render_tab_section( array $server ): void {
		echo '<p>configured</p>';
	}
}
