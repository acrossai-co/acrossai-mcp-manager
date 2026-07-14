<?php
/**
 * Discover — unit coverage for the plugin-owned discover-abilities callback.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Abilities\Discover;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP\MCP\Core\McpServer;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class DiscoverTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		CurrentServerHolder::instance()->clear();
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_discover_abilities_capability' );
	}

	public function tearDown(): void {
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		remove_all_filters( 'mcp_adapter_discover_abilities_capability' );
		CurrentServerHolder::instance()->clear();
		$this->truncate_tables();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// execute()
	// -----------------------------------------------------------------

	public function test_execute_returns_public_abilities_by_default(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/public-a', true, 'tool' );

		$result = Discover::execute();
		$names  = array_column( $result['abilities'], 'name' );

		$this->assertContains( 'discover-test/public-a', $names );
	}

	public function test_execute_filter_narrows_public_ability(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/to-hide', true, 'tool' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'discover-test/to-hide' === $ability->get_name() ? false : $exposed;
			},
			10,
			4
		);

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertNotContains( 'discover-test/to-hide', $names );
	}

	public function test_execute_filter_widens_private_ability(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/normally-hidden', false, 'tool' );

		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability ) {
				return 'discover-test/normally-hidden' === $ability->get_name() ? true : $exposed;
			},
			10,
			4
		);

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertContains( 'discover-test/normally-hidden', $names );
	}

	public function test_execute_filter_receives_server_id_when_holder_set(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/probe', true, 'tool' );

		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Probe',
				'server_slug'            => 'discover-probe-server',
				'description'            => '',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'discover-probe-server',
				'server_version'         => 'v1.0.0',
			)
		);
		CurrentServerHolder::instance()->set( $this->fake_server( 'discover-probe-server' ) );

		$captured = null;
		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability, $sid, $ctx ) use ( &$captured ) {
				if ( 'discover-test/probe' === $ability->get_name() ) {
					$captured = $sid;
				}
				return $exposed;
			},
			10,
			4
		);

		Discover::execute();
		$this->assertSame( $server_id, $captured );
	}

	public function test_execute_filter_receives_null_server_id_when_holder_empty(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/no-holder', true, 'tool' );

		$captured = 'unset';
		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability, $sid, $ctx ) use ( &$captured ) {
				if ( 'discover-test/no-holder' === $ability->get_name() ) {
					$captured = $sid;
				}
				return $exposed;
			},
			10,
			4
		);

		Discover::execute();
		$this->assertNull( $captured );
	}

	public function test_execute_filter_context_is_discover(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/ctx', true, 'tool' );

		$captured = null;
		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability, $sid, $ctx ) use ( &$captured ) {
				if ( 'discover-test/ctx' === $ability->get_name() ) {
					$captured = $ctx;
				}
				return $exposed;
			},
			10,
			4
		);

		Discover::execute();
		$this->assertSame( 'discover', $captured );
	}

	public function test_execute_skips_non_tool_types(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'discover-test/a-resource', true, 'resource' );

		$names = array_column( Discover::execute()['abilities'], 'name' );
		$this->assertNotContains( 'discover-test/a-resource', $names );
	}

	// -----------------------------------------------------------------
	// check_permission()
	// -----------------------------------------------------------------

	public function test_check_permission_rejects_unauthenticated(): void {
		wp_set_current_user( 0 );
		$result = Discover::check_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authentication_required', $result->get_error_code() );
	}

	public function test_check_permission_rejects_missing_capability(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		add_filter( 'mcp_adapter_discover_abilities_capability', static fn () => 'manage_options' );

		$result = Discover::check_permission();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'insufficient_capability', $result->get_error_code() );
	}

	public function test_check_permission_allows_authenticated_read_capable_user(): void {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( Discover::check_permission() );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function maybe_skip_abilities_api(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
	}

	private function register_scratch_ability( string $slug, bool $mcp_public, string $type ): void {
		\wp_register_ability(
			$slug,
			array(
				'label'            => ucfirst( basename( $slug ) ),
				'description'      => 'Discover test scratch',
				'category'         => 'test',
				'meta'             => array(
					'mcp' => array(
						'public' => $mcp_public,
						'type'   => $type,
					),
				),
				'input_schema'     => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'    => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'execute_callback' => static fn () => array(),
			)
		);
	}

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
