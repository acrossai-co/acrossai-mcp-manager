<?php
/**
 * Feature 030 — isolation invariant coverage (US2).
 *
 * Proves the three US2 acceptance scenarios:
 *   1. Two-server scoping — server A override ON, server B override OFF;
 *      the closure verdict tracks whichever server is current in
 *      CurrentServerHolder, not "any server has override ON".
 *   2. Non-MCP context isolation — when CurrentServerHolder is empty
 *      (WP admin, non-MCP REST route, WP-CLI), the closure ALWAYS falls
 *      through to the original callback regardless of the flag state
 *      on any server.
 *   3. Priority footrace — F030's P999999 registration wins over a fake
 *      P100000 filter (mimicking sibling acrossai-abilities-manager's
 *      per-slug injector) even when the earlier filter installs a deny
 *      callback.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use WP\MCP\Core\McpServer;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class PermissionOverrideIsolationTest extends WP_UnitTestCase {

	private int $server_a_id;
	private int $server_b_id;

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		CurrentServerHolder::instance()->clear();
		PermissionOverrideProcessor::_reset_cache_for_tests();
		ExposureResolver::_reset_cache_for_tests();

		// Server A — override ON.
		$this->server_a_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'                   => 'F030 Server A',
				'server_slug'                   => 'f030-server-a',
				'is_enabled'                    => 1,
				'registered_from'               => 'database',
				'server_route_namespace'        => 'mcp',
				'server_route'                  => 'f030-server-a',
				'server_version'                => 'v1.0.0',
				'override_abilities_permission' => 1,
			)
		);

		// Server B — override OFF.
		$this->server_b_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'                   => 'F030 Server B',
				'server_slug'                   => 'f030-server-b',
				'is_enabled'                    => 1,
				'registered_from'               => 'database',
				'server_route_namespace'        => 'mcp',
				'server_route'                  => 'f030-server-b',
				'server_version'                => 'v1.0.0',
				'override_abilities_permission' => 0,
			)
		);

		// Expose the same ability slug to BOTH servers via the junction table.
		MCPServerAbilityQuery::instance()->upsert( $this->server_a_id, 'foo/bar', true );
		MCPServerAbilityQuery::instance()->upsert( $this->server_b_id, 'foo/bar', true );
	}

	public function tearDown(): void {
		CurrentServerHolder::instance()->clear();
		PermissionOverrideProcessor::_reset_cache_for_tests();
		ExposureResolver::_reset_cache_for_tests();
		$this->truncate_tables();
		parent::tearDown();
	}

	public function test_override_verdict_tracks_current_server_not_any_server(): void {
		// Original callback denies — F030 must bypass ONLY when current server
		// = A (override ON), NOT when current server = B (override OFF).
		$args = array(
			'permission_callback' => static function (): bool {
				return false;
			},
		);
		$wrapped = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );

		// Current server = A → override wins → true.
		CurrentServerHolder::instance()->set( $this->fake_server( 'f030-server-a' ) );
		PermissionOverrideProcessor::_reset_cache_for_tests();
		ExposureResolver::_reset_cache_for_tests();
		$this->assertTrue( call_user_func( $wrapped['permission_callback'] ), 'Server A override ON → allow.' );

		// Current server = B → override off → original callback runs → deny.
		CurrentServerHolder::instance()->set( $this->fake_server( 'f030-server-b' ) );
		PermissionOverrideProcessor::_reset_cache_for_tests();
		ExposureResolver::_reset_cache_for_tests();
		$this->assertFalse( call_user_func( $wrapped['permission_callback'] ), 'Server B override OFF → deny per original.' );
	}

	public function test_non_mcp_context_always_defers_to_original_callback(): void {
		// CurrentServerHolder empty — closure MUST fall through regardless of
		// any server's override state.
		CurrentServerHolder::instance()->clear();

		$original_verdicts = array( true, false );
		foreach ( $original_verdicts as $verdict ) {
			$args = array(
				'permission_callback' => static function () use ( $verdict ): bool {
					return $verdict;
				},
			);
			$wrapped = PermissionOverrideProcessor::instance()->inject_override( $args, 'foo/bar' );

			$this->assertSame( $verdict, call_user_func( $wrapped['permission_callback'] ), 'Non-MCP context MUST always propagate original verdict.' );
		}
	}

	public function test_p999999_beats_p100000_denying_filter(): void {
		// Simulate the sibling acrossai-abilities-manager plugin's P100000
		// filter injecting a deny callback. F030 registers at P999999, which
		// runs AFTER — wrapping the deny callback in the F030 closure.
		//
		// This test does NOT rely on Main.php wiring — it manually applies
		// filters in priority order to prove the ordering invariant holds.
		//
		// Set up server context so all layers hold.
		CurrentServerHolder::instance()->set( $this->fake_server( 'f030-server-a' ) );

		$sibling_p100000 = static function ( array $args, string $slug ): array {
			// Sibling would inject a callback that denies for this user.
			$args['permission_callback'] = static function (): bool {
				return false;
			};
			return $args;
		};

		add_filter( 'wp_register_ability_args', $sibling_p100000, 100000, 2 );
		add_filter( 'wp_register_ability_args', array( PermissionOverrideProcessor::instance(), 'inject_override' ), PermissionOverrideProcessor::PRIORITY, 2 );

		// Simulate WP core running the filter chain.
		$final_args = apply_filters( 'wp_register_ability_args', array(), 'foo/bar' );

		remove_filter( 'wp_register_ability_args', $sibling_p100000, 100000 );
		remove_filter( 'wp_register_ability_args', array( PermissionOverrideProcessor::instance(), 'inject_override' ), PermissionOverrideProcessor::PRIORITY );

		$this->assertIsCallable( $final_args['permission_callback'] );
		$this->assertTrue( call_user_func( $final_args['permission_callback'] ), 'F030 P999999 wrap MUST override sibling P100000 deny.' );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_abilities`' );
	}
}
