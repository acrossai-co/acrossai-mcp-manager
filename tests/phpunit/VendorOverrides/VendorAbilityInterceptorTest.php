<?php
/**
 * VendorAbilityInterceptor — unit coverage for both filter callbacks.
 *
 * Covers the two vendor hooks the interceptor rides on:
 *   - `mcp_adapter_pre_tool_call`    → maybe_block_execute()
 *   - `mcp_adapter_tool_call_result` → filter_result_by_server()
 *
 * Fixture pattern mirrors F026's AbilityDiscoveryTest — server row via
 * MCPServerQuery + scratch abilities via wp_register_ability() with explicit
 * `mcp.type = 'tool'` and `mcp.public` values.
 *
 * @sunset-when https://github.com/WordPress/mcp-adapter/issues/243 lands upstream
 * @sunset-grep ACROSSAI_MCP_MANAGER_VENDOR_OVERRIDE_243
 *
 * @package AcrossAI_MCP_Manager\Tests\VendorOverrides
 */

namespace AcrossAI_MCP_Manager\Tests\VendorOverrides;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\VendorOverrides\VendorAbilityInterceptor;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class VendorAbilityInterceptorTest extends WP_UnitTestCase {

	private int $server_id;
	private string $server_slug = 'vendor-override-test';

	public function setUp(): void {
		parent::setUp();
		$this->truncate_tables();
		ExposureResolver::_reset_cache_for_tests();
		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'VendorAbilityInterceptor test',
				'server_slug'            => $this->server_slug,
				'description'            => 'Seeded by VendorAbilityInterceptorTest',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $this->server_slug,
				'server_version'         => 'v1.0.0',
			)
		);
	}

	public function tearDown(): void {
		remove_all_filters( 'acrossai_mcp_manager_vendor_override_effective_slugs' );
		$this->truncate_tables();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// maybe_block_execute()
	// -----------------------------------------------------------------

	public function test_maybe_block_execute_passes_through_non_execute_ability_calls(): void {
		$args   = array( 'name' => 'anything' );
		$result = VendorAbilityInterceptor::instance()->maybe_block_execute(
			$args,
			'mcp-adapter/discover-abilities',
			null,
			$this->mcp_server()
		);
		$this->assertSame( $args, $result );
	}

	public function test_maybe_block_execute_passes_through_when_args_already_wp_error(): void {
		$err    = new \WP_Error( 'earlier_deny', 'earlier gate said no' );
		$result = VendorAbilityInterceptor::instance()->maybe_block_execute(
			$err,
			'mcp-adapter/execute-ability',
			null,
			$this->mcp_server()
		);
		$this->assertSame( $err, $result );
	}

	public function test_maybe_block_execute_passes_through_when_server_slug_unresolvable(): void {
		$args   = array( 'name' => 'anything/at-all' );
		$result = VendorAbilityInterceptor::instance()->maybe_block_execute(
			$args,
			'mcp-adapter/execute-ability',
			null,
			$this->mcp_server( 'slug-that-does-not-exist' )
		);
		$this->assertSame( $args, $result, 'Fail-open: unknown server slug means the interceptor must pass through.' );
	}

	public function test_maybe_block_execute_blocks_when_target_not_in_effective_set(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/hidden', false ); // Non-public, no override → not effective.

		$args   = array( 'name' => 'vendor-test/hidden' );
		$result = VendorAbilityInterceptor::instance()->maybe_block_execute(
			$args,
			'mcp-adapter/execute-ability',
			null,
			$this->mcp_server()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'acrossai_mcp_ability_not_exposed_for_server', $result->get_error_code() );
	}

	public function test_maybe_block_execute_allows_when_target_in_effective_set(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/visible', true ); // Public → effective.

		$args   = array( 'name' => 'vendor-test/visible' );
		$result = VendorAbilityInterceptor::instance()->maybe_block_execute(
			$args,
			'mcp-adapter/execute-ability',
			null,
			$this->mcp_server()
		);

		$this->assertSame( $args, $result );
	}

	public function test_maybe_block_execute_honors_filter_added_slugs(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/added-via-filter', false ); // Non-public.

		// Companion filter widens the set to include the hidden ability.
		add_filter(
			'acrossai_mcp_manager_vendor_override_effective_slugs',
			static function ( $slugs ) {
				$slugs[] = 'vendor-test/added-via-filter';
				return $slugs;
			}
		);

		$args   = array( 'name' => 'vendor-test/added-via-filter' );
		$result = VendorAbilityInterceptor::instance()->maybe_block_execute(
			$args,
			'mcp-adapter/execute-ability',
			null,
			$this->mcp_server()
		);

		$this->assertSame( $args, $result, 'Filter must be able to widen the effective set at execute-time.' );
	}

	public function test_maybe_block_execute_honors_filter_removed_slugs(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/removed-via-filter', true ); // Public → effective by default.

		// Companion filter narrows the set to exclude the public ability.
		add_filter(
			'acrossai_mcp_manager_vendor_override_effective_slugs',
			static function ( $slugs ) {
				return array_values( array_diff( $slugs, array( 'vendor-test/removed-via-filter' ) ) );
			}
		);

		$args   = array( 'name' => 'vendor-test/removed-via-filter' );
		$result = VendorAbilityInterceptor::instance()->maybe_block_execute(
			$args,
			'mcp-adapter/execute-ability',
			null,
			$this->mcp_server()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// -----------------------------------------------------------------
	// filter_result_by_server()
	// -----------------------------------------------------------------

	public function test_filter_result_passes_through_wp_error(): void {
		$err    = new \WP_Error( 'upstream_failure', 'ability threw' );
		$result = VendorAbilityInterceptor::instance()->filter_result_by_server(
			$err,
			array(),
			'mcp-adapter/discover-abilities',
			null,
			$this->mcp_server()
		);
		$this->assertSame( $err, $result );
	}

	public function test_filter_result_narrows_discover_abilities_response_to_effective_set(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/visible-1', true );
		$this->register_scratch_ability( 'vendor-test/hidden-1', false );

		// Simulate vendor discover-abilities output — global public set.
		$raw = array(
			'abilities' => array(
				array( 'name' => 'vendor-test/visible-1', 'label' => 'V1', 'description' => 'v' ),
				array( 'name' => 'vendor-test/hidden-1',  'label' => 'H1', 'description' => 'h' ),
				array( 'name' => 'vendor-test/unknown',   'label' => '',   'description' => '' ),
			),
		);

		$result = VendorAbilityInterceptor::instance()->filter_result_by_server(
			$raw,
			array(),
			'mcp-adapter/discover-abilities',
			null,
			$this->mcp_server()
		);

		$names = array_column( $result['abilities'], 'name' );
		$this->assertContains( 'vendor-test/visible-1', $names );
		$this->assertNotContains( 'vendor-test/hidden-1', $names );
		$this->assertNotContains( 'vendor-test/unknown', $names );
	}

	public function test_filter_result_replaces_get_ability_info_with_wp_error_for_hidden_slug(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/private', false );

		$raw = array(
			'name'        => 'vendor-test/private',
			'label'       => 'Private',
			'description' => 'should-not-leak',
		);

		$result = VendorAbilityInterceptor::instance()->filter_result_by_server(
			$raw,
			array( 'name' => 'vendor-test/private' ),
			'mcp-adapter/get-ability-info',
			null,
			$this->mcp_server()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'acrossai_mcp_ability_not_exposed_for_server', $result->get_error_code() );
	}

	public function test_filter_result_returns_get_ability_info_unchanged_for_visible_slug(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/public-info', true );

		$raw = array( 'name' => 'vendor-test/public-info', 'label' => 'P', 'description' => 'p' );

		$result = VendorAbilityInterceptor::instance()->filter_result_by_server(
			$raw,
			array( 'name' => 'vendor-test/public-info' ),
			'mcp-adapter/get-ability-info',
			null,
			$this->mcp_server()
		);

		$this->assertSame( $raw, $result );
	}

	public function test_filter_result_leaves_other_tool_names_alone(): void {
		$raw    = array( 'anything' => 'passes-through' );
		$result = VendorAbilityInterceptor::instance()->filter_result_by_server(
			$raw,
			array(),
			'some-plugin/unrelated-tool',
			null,
			$this->mcp_server()
		);
		$this->assertSame( $raw, $result );
	}

	public function test_filter_receives_context_string_and_target_ability_arguments(): void {
		$this->maybe_skip_abilities_api();
		$this->register_scratch_ability( 'vendor-test/probe', true );

		$captured = new \stdClass();
		$captured->calls = array();
		add_filter(
			'acrossai_mcp_manager_vendor_override_effective_slugs',
			static function ( $slugs, $server_id, $context, $target ) use ( $captured ) {
				$captured->calls[] = array(
					'server_id' => $server_id,
					'context'   => $context,
					'target_is_null' => null === $target,
				);
				return $slugs;
			},
			10,
			4
		);

		// Discover pass — target must be null.
		VendorAbilityInterceptor::instance()->filter_result_by_server(
			array( 'abilities' => array() ),
			array(),
			'mcp-adapter/discover-abilities',
			null,
			$this->mcp_server()
		);

		// Get-info pass — target must be non-null (we registered vendor-test/probe).
		VendorAbilityInterceptor::instance()->filter_result_by_server(
			array( 'name' => 'vendor-test/probe' ),
			array( 'name' => 'vendor-test/probe' ),
			'mcp-adapter/get-ability-info',
			null,
			$this->mcp_server()
		);

		// Execute pass — target must be non-null.
		VendorAbilityInterceptor::instance()->maybe_block_execute(
			array( 'name' => 'vendor-test/probe' ),
			'mcp-adapter/execute-ability',
			null,
			$this->mcp_server()
		);

		$this->assertCount( 3, $captured->calls );
		$this->assertSame( 'discover', $captured->calls[0]['context'] );
		$this->assertTrue( $captured->calls[0]['target_is_null'] );
		$this->assertSame( 'get_info', $captured->calls[1]['context'] );
		$this->assertFalse( $captured->calls[1]['target_is_null'] );
		$this->assertSame( 'execute', $captured->calls[2]['context'] );
		$this->assertFalse( $captured->calls[2]['target_is_null'] );

		foreach ( $captured->calls as $call ) {
			$this->assertSame( $this->server_id, $call['server_id'] );
		}
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Duck-typed McpServer stand-in — the interceptor only needs `get_server_id()`.
	 *
	 * @param string|null $slug_override When set, overrides the seeded slug (used for
	 *                                    the unresolvable-slug fail-open case).
	 */
	private function mcp_server( ?string $slug_override = null ): object {
		$slug = $slug_override ?? $this->server_slug;
		return new class( $slug ) {
			public function __construct( private string $slug ) {}
			public function get_server_id(): string {
				return $this->slug;
			}
		};
	}

	private function maybe_skip_abilities_api(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not bootstrapped in this test harness.' );
		}
	}

	private function register_scratch_ability( string $slug, bool $mcp_public ): void {
		\wp_register_ability(
			$slug,
			array(
				'label'       => ucfirst( basename( $slug ) ),
				'description' => 'VendorAbilityInterceptor scratch',
				'category'    => 'test',
				'meta'        => array(
					'mcp' => array(
						'public' => $mcp_public,
						'type'   => 'tool',
					),
				),
				'input_schema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'execute_callback' => static fn () => array(),
			)
		);
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_servers`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->prefix . 'acrossai_mcp_server_abilities`' );
	}
}
