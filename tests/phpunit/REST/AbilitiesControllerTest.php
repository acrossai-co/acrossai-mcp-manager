<?php
/**
 * Feature 017 — AbilitiesController REST test.
 *
 * Covers auth, 404, 400, and the "Abilities API absent" branches.
 * Happy-path (populated abilities list) tests skip gracefully when the
 * WordPress Abilities API is not bootstrapped in the test harness — the
 * routes' behavior when the API IS present is exercised via `quickstart.md`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\REST
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use AcrossAI_MCP_Manager\Includes\REST\AbilitiesController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class AbilitiesControllerTest extends WP_UnitTestCase {

	private int $admin_id   = 0;
	private int $editor_id  = 0;
	private int $server_id  = 0;

	public function set_up(): void {
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();
		MCPServerAbilityTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_server_abilities' ) );

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$row = $wpdb->get_row( "SELECT id FROM {$wpdb->prefix}acrossai_mcp_servers LIMIT 1" ); // phpcs:ignore
		$this->server_id = $row ? (int) $row->id : 0;

		// Register REST routes for the request-dispatch tests.
		do_action( 'rest_api_init' );
		AbilitiesController::instance()->register_routes();
	}

	public function test_get_returns_403_for_unauthenticated_user(): void {
		wp_set_current_user( 0 );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/abilities' );
		$res = rest_do_request( $req );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_get_returns_403_for_editor(): void {
		wp_set_current_user( $this->editor_id );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/abilities' );
		$res = rest_do_request( $req );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_get_returns_404_for_missing_server(): void {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/servers/999999/abilities' );
		$res = rest_do_request( $req );
		$this->assertSame( 404, $res->get_status() );
		$body = $res->get_data();
		$this->assertSame( 'acrossai_mcp_server_not_found', is_array( $body ) ? $body['code'] : $body->get_error_code() );
	}

	public function test_get_returns_overrides_envelope(): void {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'GET', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/abilities' );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$body = $res->get_data();
		$this->assertArrayHasKey( 'overrides', $body );
		$this->assertIsArray( $body['overrides'] );
		// Empty on a fresh install — no rows in wp_acrossai_mcp_server_abilities yet.
		$this->assertSame( array(), $body['overrides'] );
	}

	public function test_post_returns_400_on_empty_abilities_array(): void {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/abilities' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'abilities' => array() ) ) );
		$res = rest_do_request( $req );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_post_returns_400_on_malformed_entry(): void {
		wp_set_current_user( $this->admin_id );
		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/abilities' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'abilities' => array( array( 'slug' => 42, 'is_exposed' => true ) ),
				)
			)
		);
		$res = rest_do_request( $req );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_post_returns_403_for_editor(): void {
		wp_set_current_user( $this->editor_id );
		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $this->server_id . '/abilities' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'abilities' => array( array( 'slug' => 'x', 'is_exposed' => true ) ) ) ) );
		$res = rest_do_request( $req );
		$this->assertSame( 403, $res->get_status() );
	}
}
