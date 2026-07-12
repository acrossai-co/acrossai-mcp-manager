<?php
/**
 * US1 — Admin generate-client permission tests.
 *
 * Subscriber → 403; missing nonce → 403; unknown server_id → 404;
 * unknown connector_slug → 404.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController;

/**
 * @coversNothing
 */
class AdminGenerateClientPermissionsTest extends OAuthTestCase {

	private int $server_id = 0;

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();
		$this->server_id = $this->seed_mcp_server();

		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge( $p, array( new AdminGenTestProfile() ) )
		);

		ClientRegistrationController::instance()->register_routes();
	}

	public function test_subscriber_receives_403(): void {
		$subscriber = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$response = $this->dispatch( 'claude-desktop', $this->server_id );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_missing_nonce_returns_403(): void {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/generate-client' );
		$request->set_header( 'Content-Type', 'application/json' );
		// No X-WP-Nonce.
		$request->set_body_params( array(
			'server_id'      => $this->server_id,
			'connector_slug' => 'claude-desktop',
		) );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_unknown_server_id_returns_404(): void {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = $this->dispatch( 'claude-desktop', 999999 );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_unknown_connector_slug_returns_404(): void {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$response = $this->dispatch( 'nonexistent-connector', $this->server_id );

		$this->assertSame( 404, $response->get_status() );
	}

	private function dispatch( string $slug, int $server_id ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/generate-client' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body_params( array(
			'server_id'      => $server_id,
			'connector_slug' => $slug,
		) );

		return rest_do_request( $request );
	}

	private function seed_mcp_server(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_servers',
			array(
				'server_slug' => 'test-mcp-server-perm',
				'server_name' => 'Test MCP Server (Permissions)',
				'is_enabled'  => 1,
				'created_at'  => current_time( 'mysql', 1 ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}
