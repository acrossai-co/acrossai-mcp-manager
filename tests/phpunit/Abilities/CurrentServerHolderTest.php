<?php
/**
 * CurrentServerHolder — unit coverage for request-scoped server tracking.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP\MCP\Core\McpServer;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class CurrentServerHolderTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		CurrentServerHolder::instance()->clear();
	}

	public function tearDown(): void {
		CurrentServerHolder::instance()->clear();
		$this->truncate_tables();
		parent::tearDown();
	}

	public function test_set_and_get_round_trip(): void {
		$server = $this->fake_server( 'roundtrip-slug' );
		CurrentServerHolder::instance()->set( $server );
		$this->assertSame( $server, CurrentServerHolder::instance()->get() );

		CurrentServerHolder::instance()->clear();
		$this->assertNull( CurrentServerHolder::instance()->get() );
	}

	public function test_get_server_id_resolves_slug_to_pk(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'CurrentServerHolderTest',
				'server_slug'            => 'holder-test-server',
				'description'            => '',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'holder-test-server',
				'server_version'         => 'v1.0.0',
			)
		);

		CurrentServerHolder::instance()->set( $this->fake_server( 'holder-test-server' ) );
		$this->assertSame( $server_id, CurrentServerHolder::instance()->get_server_id() );
	}

	public function test_get_server_id_returns_null_when_row_missing(): void {
		CurrentServerHolder::instance()->set( $this->fake_server( 'unknown-slug-nothing-here' ) );
		$this->assertNull( CurrentServerHolder::instance()->get_server_id() );
	}

	public function test_get_server_id_returns_null_when_holder_empty(): void {
		$this->assertNull( CurrentServerHolder::instance()->get_server_id() );
	}

	public function test_capture_from_request_no_match_leaves_holder_null(): void {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$result  = CurrentServerHolder::instance()->capture_from_request( 'passthrough', null, $request );

		$this->assertSame( 'passthrough', $result );
		$this->assertNull( CurrentServerHolder::instance()->get() );
	}

	public function test_capture_from_request_passes_through_when_request_invalid(): void {
		// Not a WP_REST_Request — no get_route() method.
		$result = CurrentServerHolder::instance()->capture_from_request( 'passthrough', null, 'not-a-request' );
		$this->assertSame( 'passthrough', $result );
		$this->assertNull( CurrentServerHolder::instance()->get() );
	}

	public function test_clear_resets_holder_and_cache(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'ClearTest',
				'server_slug'            => 'clear-test-server',
				'description'            => '',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'clear-test-server',
				'server_version'         => 'v1.0.0',
			)
		);

		CurrentServerHolder::instance()->set( $this->fake_server( 'clear-test-server' ) );
		$this->assertSame( $server_id, CurrentServerHolder::instance()->get_server_id() );

		CurrentServerHolder::instance()->clear();
		$this->assertNull( CurrentServerHolder::instance()->get() );
		$this->assertNull( CurrentServerHolder::instance()->get_server_id() );
	}

	public function test_clear_returns_its_passthrough_argument_verbatim(): void {
		// `rest_post_dispatch` passes a WP_HTTP_Response — clear() must not mutate it.
		$response = 'response-payload';
		$this->assertSame( $response, CurrentServerHolder::instance()->clear( $response ) );

		// `shutdown` action fires clear() with no args — must not throw / must not return an error.
		$this->assertNull( CurrentServerHolder::instance()->clear() );
	}

	/**
	 * Return a PHPUnit mock of `McpServer` whose `get_server_id()` returns `$slug`.
	 *
	 * @param string $slug Slug to return from the mock's `get_server_id()`.
	 */
	private function fake_server( string $slug ): McpServer {
		$mock = $this->createMock( McpServer::class );
		$mock->method( 'get_server_id' )->willReturn( $slug );
		return $mock;
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
	}
}
