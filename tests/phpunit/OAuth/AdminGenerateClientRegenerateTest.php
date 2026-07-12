<?php
/**
 * US1 — Regenerate flow: FR-036 tokens revoked, `token_revoked` fires
 * per row with reason `'client_regenerated'`, new client_id issued.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;

/**
 * @coversNothing
 */
class AdminGenerateClientRegenerateTest extends OAuthTestCase {

	private int $server_id      = 0;
	private int $admin_user_id  = 0;

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

	public function test_regenerate_revokes_prior_tokens_and_fires_action_per_row(): void {
		// (a) First generate — gets client A.
		$first_body = $this->dispatch()->get_data();
		$client_a   = (string) $first_body['client_id'];
		$this->assertFalse( (bool) $first_body['regenerated'] );

		// (b) Issue two tokens for client A directly via the Repository (bypasses /token flow).
		AccessTokenRepository::issue( array(
			'client_id'       => $client_a,
			'user_id'         => $this->admin_user_id,
			'scope'           => 'mcp',
			'resource'        => 'https://this-site.example.com/wp-json/mcp/v1/server-1',
			'token_family_id' => wp_generate_uuid4(),
		) );
		AccessTokenRepository::issue( array(
			'client_id'       => $client_a,
			'user_id'         => $this->admin_user_id,
			'scope'           => 'mcp',
			'resource'        => 'https://this-site.example.com/wp-json/mcp/v1/server-1',
			'token_family_id' => wp_generate_uuid4(),
		) );

		// (c) Capture observability action.
		$captured = $this->capture_action( 'acrossai_mcp_manager_oauth_token_revoked' );

		// (d) Second generate — regenerate branch.
		$second_body = $this->dispatch()->get_data();
		$client_b    = (string) $second_body['client_id'];
		$this->assertTrue( (bool) $second_body['regenerated'] );

		// New client_id issued.
		$this->assertNotSame( $client_a, $client_b );

		// Action fired twice with reason 'client_regenerated'.
		$this->assertCount( 2, $captured['calls'] );
		foreach ( $captured['calls'] as $call ) {
			$this->assertSame( 'client_regenerated', $call[1] );
			$this->assertIsInt( $call[0] );
			$this->assertGreaterThan( 0, $call[0] );
		}

		// Client A tokens flipped to revoked=1.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$still_active = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$client_a
			)
		);
		$this->assertSame( 0, $still_active );
	}

	private function dispatch(): \WP_REST_Response {
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
				'server_slug' => 'test-mcp-server-regen',
				'server_name' => 'Test MCP Server (Regenerate)',
				'is_enabled'  => 1,
				'created_at'  => current_time( 'mysql', 1 ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}
