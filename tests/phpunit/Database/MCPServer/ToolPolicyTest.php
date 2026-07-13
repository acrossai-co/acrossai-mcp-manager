<?php
/**
 * ToolPolicy — unit coverage for compose_for_row() and split_payload().
 *
 * Feature 025 (T006). Covers all 8 canonical cases from data-model.md plus
 * one hardening case per SEC-TASKS-025-1 (defense-in-depth dedup on curated
 * pick that happens to match a protocol slug).
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ToolPolicyTest extends WP_UnitTestCase {

	private int $server_id;

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'ToolPolicyTest server',
				'server_slug'            => 'toolpolicy-test',
				'description'            => 'Seeded by ToolPolicyTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'toolpolicy-test',
				'server_version'         => 'v1.0.0',
			)
		);
	}

	public function tearDown(): void {
		$this->truncate_tables();
		parent::tearDown();
	}

	public function test_compose_all_columns_enabled_no_curated_returns_three_protocol_slugs_in_column_map_order(): void {
		$row = $this->fetch_row( array(
			'tool_discover_abilities' => 1,
			'tool_get_ability_info'   => 1,
			'tool_execute_ability'    => 1,
		) );

		$this->assertSame(
			array(
				'mcp-adapter/discover-abilities',
				'mcp-adapter/get-ability-info',
				'mcp-adapter/execute-ability',
			),
			ToolPolicy::compose_for_row( $row )
		);
	}

	public function test_compose_with_one_column_disabled_omits_that_slug(): void {
		$row = $this->fetch_row( array( 'tool_execute_ability' => 0 ) );

		$this->assertSame(
			array( 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info' ),
			ToolPolicy::compose_for_row( $row )
		);
	}

	public function test_compose_all_columns_disabled_no_curated_returns_empty(): void {
		$row = $this->fetch_row( array(
			'tool_discover_abilities' => 0,
			'tool_get_ability_info'   => 0,
			'tool_execute_ability'    => 0,
		) );

		$this->assertSame( array(), ToolPolicy::compose_for_row( $row ) );
	}

	public function test_compose_appends_curated_slugs_after_protocol_slugs(): void {
		MCPServerToolQuery::instance()->replace_set( $this->server_id, array( 'my-plugin/notes', 'my-plugin/tasks' ) );
		$row = $this->fetch_row();

		$composed = ToolPolicy::compose_for_row( $row );

		$this->assertContains( 'mcp-adapter/discover-abilities', $composed );
		$this->assertContains( 'my-plugin/notes', $composed );
		$this->assertContains( 'my-plugin/tasks', $composed );
		// Protocol first.
		$this->assertLessThan(
			array_search( 'my-plugin/notes', $composed, true ),
			array_search( 'mcp-adapter/discover-abilities', $composed, true )
		);
	}

	public function test_compose_dedupes_curated_pick_matching_protocol_slug(): void {
		// Defense-in-depth: split_payload should prevent this at the write
		// boundary, but if direct DB writers (or a legacy row) leave a
		// protocol slug in the curated table, compose_for_row must still
		// return exactly one occurrence.
		MCPServerToolQuery::instance()->replace_set(
			$this->server_id,
			array( 'mcp-adapter/discover-abilities' )
		);
		$row = $this->fetch_row();

		$composed = ToolPolicy::compose_for_row( $row );

		$this->assertSame(
			count( array_unique( $composed ) ),
			count( $composed ),
			'Composed list must be duplicate-free even when curated echoes a protocol slug.'
		);
	}

	public function test_split_payload_three_protocol_plus_two_curated_sets_all_columns_and_extracts_curated(): void {
		$result = ToolPolicy::split_payload( array(
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
			'my-plugin/notes',
			'my-plugin/tasks',
		) );

		$this->assertSame( 1, $result['columns']['tool_discover_abilities'] );
		$this->assertSame( 1, $result['columns']['tool_get_ability_info'] );
		$this->assertSame( 1, $result['columns']['tool_execute_ability'] );
		$this->assertEqualsCanonicalizing(
			array( 'my-plugin/notes', 'my-plugin/tasks' ),
			$result['curated']
		);
	}

	public function test_split_payload_zero_protocol_three_curated_flips_all_columns_to_zero(): void {
		$result = ToolPolicy::split_payload( array( 'a/one', 'b/two', 'c/three' ) );

		$this->assertSame( 0, $result['columns']['tool_discover_abilities'] );
		$this->assertSame( 0, $result['columns']['tool_get_ability_info'] );
		$this->assertSame( 0, $result['columns']['tool_execute_ability'] );
		$this->assertEqualsCanonicalizing(
			array( 'a/one', 'b/two', 'c/three' ),
			$result['curated']
		);
	}

	public function test_split_payload_empty_input_flips_all_columns_to_zero_and_empty_curated(): void {
		$result = ToolPolicy::split_payload( array() );

		$this->assertSame( 0, $result['columns']['tool_discover_abilities'] );
		$this->assertSame( 0, $result['columns']['tool_get_ability_info'] );
		$this->assertSame( 0, $result['columns']['tool_execute_ability'] );
		$this->assertSame( array(), $result['curated'] );
	}

	private function fetch_row( array $column_overrides = array() ): Row {
		if ( ! empty( $column_overrides ) ) {
			MCPServerQuery::instance()->update_item( $this->server_id, $column_overrides );
		}
		$rows = MCPServerQuery::instance()->query( array( 'id' => $this->server_id, 'number' => 1 ) );
		return $rows[0];
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_tools`' );
	}
}
