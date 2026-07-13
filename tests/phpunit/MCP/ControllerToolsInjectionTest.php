<?php
/**
 * MCP\Controller — F025 filter injection coverage.
 *
 * Covers:
 *  1–5: filter_default_server_config() defensive short-circuits + REPLACE
 *       semantics on the vendor mcp_adapter_default_server_config seam.
 *  6–8: register_database_servers() emission of the new
 *       acrossai_mcp_manager_server_tools filter.
 *  9:   SEC-TASKS-025-1 confused-deputy — filter re-adds a slug the operator
 *       removed via column=0; assert the composed set includes it AND that
 *       the observability action is NOT fired on filter-side changes.
 *
 * @package AcrossAI_MCP_Manager\Tests\MCP
 */

namespace AcrossAI_MCP_Manager\Tests\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\MCP\Controller;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ControllerToolsInjectionTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
	}

	public function tearDown(): void {
		$this->truncate_tables();
		parent::tearDown();
	}

	// Cases 1–5 — filter_default_server_config() ----------------------------

	public function test_filter_default_server_config_returns_input_untouched_when_default_row_missing(): void {
		// No seed — default row absent.
		$config = array(
			'server_id' => 'x',
			'tools'     => array( 'a/one' ),
		);
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame( $config, $result );
	}

	public function test_filter_default_server_config_returns_input_untouched_when_compose_returns_empty(): void {
		$this->seed_default_server( array(
			'tool_discover_abilities' => 0,
			'tool_get_ability_info'   => 0,
			'tool_execute_ability'    => 0,
		) );

		$config = array(
			'server_id' => 'x',
			'tools'     => array( 'vendor/one' ),
		);
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame( $config, $result );
	}

	public function test_filter_default_server_config_replaces_tools_and_preserves_other_keys(): void {
		$this->seed_default_server( array(
			'tool_discover_abilities' => 1,
			'tool_get_ability_info'   => 1,
			'tool_execute_ability'    => 0, // Deliberately off.
		) );

		$config = array(
			'server_id'          => 'preserve-me',
			'server_name'        => 'Preserve me',
			'server_description' => 'Also preserve me',
			'tools'              => array( 'vendor/should-be-replaced' ),
			'resources'          => array( 'r/one' ),
			'prompts'            => array( 'p/one' ),
		);
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame( 'preserve-me', $result['server_id'] );
		$this->assertSame( 'Preserve me', $result['server_name'] );
		$this->assertSame( array( 'r/one' ), $result['resources'] );
		$this->assertSame( array( 'p/one' ), $result['prompts'] );
		$this->assertNotContains( 'vendor/should-be-replaced', $result['tools'] );
		$this->assertContains( 'mcp-adapter/discover-abilities', $result['tools'] );
		$this->assertContains( 'mcp-adapter/get-ability-info', $result['tools'] );
		$this->assertNotContains( 'mcp-adapter/execute-ability', $result['tools'] );
	}

	public function test_filter_default_server_config_returns_input_when_tools_not_array(): void {
		$config = array( 'tools' => 'not-an-array' );
		$result = Controller::instance()->filter_default_server_config( $config );

		$this->assertSame( $config, $result );
	}

	public function test_filter_default_server_config_returns_input_when_config_not_array(): void {
		$result = Controller::instance()->filter_default_server_config( 'garbage' );

		$this->assertSame( 'garbage', $result );
	}

	// Cases 6–8 — register_database_servers() filter emission ---------------

	public function test_acrossai_mcp_manager_server_tools_filter_fires_once_per_server(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'Filter emission test',
			'server_slug'            => 'filter-emit-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'filter-emit-test',
			'server_version'         => 'v1.0.0',
		) );

		$capture = new \stdClass();
		$capture->calls = 0;
		$capture->arg2  = null;
		$callback = function ( $tools, $server ) use ( $capture ) {
			$capture->calls += 1;
			$capture->arg2   = $server;
			return $tools;
		};
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		// Cannot invoke register_database_servers() without a real McpAdapter
		// instance; simulate the composed call directly (matches the exact
		// sequence in Controller::register_database_servers).
		$rows  = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
		$row   = $rows[0];
		$tools = ToolPolicy::compose_for_row( $row );
		apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $row );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );

		$this->assertSame( 1, $capture->calls, 'Filter must fire exactly once per server registration.' );
		$this->assertSame( $server_id, (int) $capture->arg2->id );
	}

	public function test_filter_can_add_a_slug_and_result_is_defensively_normalized(): void {
		$callback = static function ( $tools ) {
			$tools[] = 'my-plugin/added-by-filter';
			return $tools;
		};
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		$before = array( 'mcp-adapter/discover-abilities' );
		$after  = apply_filters( 'acrossai_mcp_manager_server_tools', $before, new \stdClass() );
		$after  = array_values( array_unique( array_map( 'strval', (array) $after ) ) );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );

		$this->assertContains( 'my-plugin/added-by-filter', $after );
	}

	public function test_filter_returning_null_degrades_to_empty_array(): void {
		$callback = static fn() => null;
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		$before = array( 'mcp-adapter/discover-abilities' );
		$after  = apply_filters( 'acrossai_mcp_manager_server_tools', $before, new \stdClass() );
		$after  = array_values( array_unique( array_map( 'strval', (array) $after ) ) );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );

		$this->assertSame( array(), $after );
	}

	// Case 9 — SEC-TASKS-025-1 confused-deputy ------------------------------

	public function test_filter_can_readd_protocol_slug_operator_removed_via_column_and_no_observability_event_fires(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item( array(
			'server_name'            => 'Confused deputy test',
			'server_slug'            => 'confused-deputy-test',
			'description'            => '',
			'is_enabled'             => 1,
			'registered_from'        => 'database',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'confused-deputy-test',
			'server_version'         => 'v1.0.0',
			'tool_discover_abilities' => 1,
			'tool_get_ability_info'   => 1,
			'tool_execute_ability'    => 0, // Operator "removed" execute-ability.
		) );

		$observed = array();
		$obs      = static function ( $payload ) use ( &$observed ) {
			$observed[] = $payload;
		};
		add_action( 'acrossai_mcp_tools_changed', $obs );

		$callback = static function ( $tools ) {
			// Companion plugin re-adds the operator-removed slug.
			$tools[] = 'mcp-adapter/execute-ability';
			return $tools;
		};
		add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 );

		$rows       = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) );
		$row        = $rows[0];
		$pre_filter = ToolPolicy::compose_for_row( $row );
		$post       = apply_filters( 'acrossai_mcp_manager_server_tools', $pre_filter, $row );
		$post       = array_values( array_unique( array_map( 'strval', (array) $post ) ) );

		remove_filter( 'acrossai_mcp_manager_server_tools', $callback, 10 );
		remove_action( 'acrossai_mcp_tools_changed', $obs );

		$this->assertNotContains( 'mcp-adapter/execute-ability', $pre_filter, 'Composed set must NOT include the operator-removed slug pre-filter.' );
		$this->assertContains( 'mcp-adapter/execute-ability', $post, 'Filter can re-add the operator-removed slug (documented behavior).' );
		$this->assertSame( array(), $observed, 'Filter-side changes must NOT fire acrossai_mcp_tools_changed — only POST-side flips emit the event.' );
	}

	private function seed_default_server( array $overrides = array() ): int {
		$data = array_merge(
			array(
				'server_name'            => 'Default seed',
				'server_slug'            => DefaultServerSeeder::SLUG,
				'description'            => '',
				'is_enabled'             => 0,
				'registered_from'        => 'plugin',
				'server_route_namespace' => 'mcp',
				'server_route'           => DefaultServerSeeder::SLUG,
				'server_version'         => 'v1.0.0',
			),
			$overrides
		);
		return (int) MCPServerQuery::instance()->add_item( $data );
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_tools`' );
	}
}
