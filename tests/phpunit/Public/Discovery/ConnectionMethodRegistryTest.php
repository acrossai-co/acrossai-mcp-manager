<?php
/**
 * F035 — ConnectionMethodRegistry public discovery API tests.
 *
 * Covers all three user stories: US1 unified enumeration, US2 NPM
 * extensibility filter (+ SEC-035-001 type validation), US3 cross-category
 * curation filter + malformed-return fallback.
 *
 * Runs under the `discovery` suite with `tests/bootstrap-wp.php` — F035
 * delegates transitively into ConnectorProfileRegistry (_doing_it_wrong +
 * abilities-API touch) and NpmClientBlock (home_url / get_option), so
 * stubbing would exceed the ~10-symbol A18 ceiling.
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Discovery
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Public\Discovery;

use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;
use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_UnitTestCase;

/**
 * Minimal AbstractConnectorProfile subclass — exercises F021 delegation path
 * without instantiating a real connector (Claude.ai / ChatGPT are companion-
 * plugin territory; the base plugin ships zero).
 */
final class DiscoveryStubProfile extends AbstractConnectorProfile {

	public function __construct( private string $slug, private string $name ) {
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_icon_url(): string {
		return 'https://example.com/icon.svg';
	}

	public function get_redirect_uri_whitelist(): array {
		return array( 'https://example.com/callback' );
	}

	public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
		return '<p>setup</p>';
	}

	public function render_tab_section( array $server ): void {
		echo '<div>test</div>';
	}
}

final class ConnectionMethodRegistryTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// R2 memoization reset — every test starts from a cold cache.
		ConnectionMethodRegistry::instance()->flush_cache();

		// F021 registry memoization reset — see tests/phpunit/OAuth/OAuthTestCase.
		// We need this because F035 delegates into it.
		$reflection = new \ReflectionClass( \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry::class );
		$profiles   = $reflection->getProperty( 'profiles' );
		$profiles->setAccessible( true );
		$profiles->setValue( \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry::instance(), null );
	}

	// -----------------------------------------------------------------
	// US1 — Unified enumeration + shape invariants
	// -----------------------------------------------------------------

	/** FR-002. */
	public function test_singleton_returns_same_instance(): void {
		$this->assertSame(
			ConnectionMethodRegistry::instance(),
			ConnectionMethodRegistry::instance()
		);
	}

	/** FR-004. */
	public function test_get_all_returns_three_keyed_array(): void {
		$all = ConnectionMethodRegistry::instance()->get_all();
		$this->assertIsArray( $all );
		$this->assertSame(
			array( 'npm', 'clients', 'ai_connectors' ),
			array_keys( $all ),
			'get_all() MUST return exactly three category keys in fixed order.'
		);
	}

	/** FR-004 / FR-010. */
	public function test_get_all_default_counts_on_fresh_install(): void {
		$all = ConnectionMethodRegistry::instance()->get_all();
		$this->assertCount( 1, $all['npm'], 'Default: one built-in NPM method.' );
		$this->assertCount( 8, $all['clients'], 'Default: 8 F034 built-in clients.' );
		$this->assertCount( 0, $all['ai_connectors'], 'Default: zero AI connectors (companion-plugin territory).' );
	}

	/** SC-002 — unified DTO shape stable across all three categories. */
	public function test_every_dto_has_six_top_level_keys(): void {
		$all = ConnectionMethodRegistry::instance()->get_all();
		$required = array( 'category', 'slug', 'name', 'description', 'icon', 'meta' );
		foreach ( $all as $category => $dtos ) {
			foreach ( $dtos as $index => $dto ) {
				foreach ( $required as $key ) {
					$this->assertArrayHasKey(
						$key,
						$dto,
						"Category {$category} DTO[{$index}] missing key {$key}"
					);
				}
			}
		}
	}

	/** SC-001 — JSON-serializable round-trip. */
	public function test_dto_is_json_round_trip_safe(): void {
		$all     = ConnectionMethodRegistry::instance()->get_all();
		$encoded = wp_json_encode( $all );
		$this->assertNotFalse( $encoded, 'wp_json_encode must succeed.' );
		$decoded = json_decode( $encoded, true );
		$this->assertSame( $all, $decoded, 'DTO structure MUST survive JSON round-trip losslessly.' );
	}

	/** FR-013. */
	public function test_find_returns_dto_on_match(): void {
		$dto = ConnectionMethodRegistry::instance()->find( 'client', 'claude-desktop' );
		$this->assertIsArray( $dto );
		$this->assertSame( 'claude-desktop', $dto['slug'] );
		$this->assertSame( 'client', $dto['category'] );
	}

	/** FR-013 — unknown category returns null. */
	public function test_find_returns_null_on_unknown_category(): void {
		$this->assertNull(
			ConnectionMethodRegistry::instance()->find( 'bogus-category', 'anything' )
		);
	}

	/** FR-013 — unknown slug returns null. */
	public function test_find_returns_null_on_unknown_slug(): void {
		$this->assertNull(
			ConnectionMethodRegistry::instance()->find( 'client', 'nonexistent-slug' )
		);
	}

	/** FR-010 — clients DTOs carry class + config_file + top_level_key in meta. */
	public function test_get_clients_dto_shape(): void {
		$clients = ConnectionMethodRegistry::instance()->get_clients();
		$this->assertNotEmpty( $clients );
		foreach ( $clients as $dto ) {
			$this->assertSame( 'client', $dto['category'] );
			$this->assertArrayHasKey( 'class', $dto['meta'] );
			$this->assertArrayHasKey( 'config_file', $dto['meta'] );
			$this->assertArrayHasKey( 'top_level_key', $dto['meta'] );
			$this->assertTrue(
				class_exists( $dto['meta']['class'] ),
				'client meta.class MUST be a real FQN.'
			);
		}
	}

	/** FR-011 — empty by default. */
	public function test_get_ai_connectors_empty_by_default(): void {
		$this->assertSame( array(), ConnectionMethodRegistry::instance()->get_ai_connectors() );
	}

	/** FR-011 — populated when a companion plugin registers a profile. */
	public function test_get_ai_connectors_populated_with_registered_profile(): void {
		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static function ( array $profiles ): array {
				$profiles[] = new DiscoveryStubProfile( 'stub-connector', 'Stub Connector' );
				return $profiles;
			}
		);
		ConnectionMethodRegistry::instance()->flush_cache();

		$connectors = ConnectionMethodRegistry::instance()->get_ai_connectors();
		$this->assertCount( 1, $connectors );
		$this->assertSame( 'stub-connector', $connectors[0]['slug'] );
		$this->assertSame( 'ai_connector', $connectors[0]['category'] );
		$this->assertSame( DiscoveryStubProfile::class, $connectors[0]['meta']['class'] );
		$this->assertTrue( $connectors[0]['meta']['has_redirect_whitelist'] );
		$this->assertSame( $connectors[0]['icon'], $connectors[0]['meta']['icon_url'] );
	}

	/** FR-005 / R2. */
	public function test_flush_cache_forces_reassembly(): void {
		$registry = ConnectionMethodRegistry::instance();
		$first    = $registry->get_all();
		$this->assertCount( 1, $first['npm'] );

		add_filter(
			'acrossai_mcp_connection_methods',
			static function ( array $assembled ): array {
				$assembled['npm'] = array();
				return $assembled;
			}
		);

		// Same request, filter added AFTER first call — cached result unchanged.
		$second = $registry->get_all();
		$this->assertCount( 1, $second['npm'], 'Cached result MUST ignore later-registered filters.' );

		// After flush, filter applies.
		$registry->flush_cache();
		$third = $registry->get_all();
		$this->assertCount( 0, $third['npm'], 'Post-flush call MUST reflect the filter.' );
	}

	// -----------------------------------------------------------------
	// US2 — NPM filter extensibility + SEC-035-001 validation
	// -----------------------------------------------------------------

	/** FR-009 / SC-003. */
	public function test_npm_filter_fires_with_default_seed(): void {
		$captured = null;
		add_filter(
			'acrossai_mcp_npm_methods',
			static function ( array $methods ) use ( &$captured ): array {
				$captured = $methods;
				return $methods;
			}
		);

		ConnectionMethodRegistry::instance()->get_npm_methods();
		$this->assertIsArray( $captured );
		$this->assertCount( 1, $captured, 'Seed MUST have exactly one entry (built-in npx bridge).' );
		$this->assertSame( 'npx-acrossai-mcp-manager', $captured[0]['slug'] );
	}

	/** FR-009 — filter contribution surfaces in output. */
	public function test_npm_filter_contribution_appears_in_output(): void {
		add_filter(
			'acrossai_mcp_npm_methods',
			static function ( array $methods ): array {
				$methods[] = array(
					'category'    => 'npm',
					'slug'        => 'yarn-mcp-bridge',
					'name'        => 'Yarn MCP Bridge',
					'description' => 'Alternative bridge using yarn dlx.',
					'icon'        => '',
					'meta'        => array(
						'command_template' => 'yarn dlx @myco/mcp-bridge --site=%s --server=%s',
						'enabled_option'   => 'my_plugin_yarn_bridge_enabled',
					),
				);
				return $methods;
			}
		);

		$methods = ConnectionMethodRegistry::instance()->get_npm_methods();
		$this->assertCount( 2, $methods );
		$slugs = array_column( $methods, 'slug' );
		$this->assertContains( 'yarn-mcp-bridge', $slugs );
		$this->assertContains( 'npx-acrossai-mcp-manager', $slugs );
	}

	/** FR-009a — Q1 later-wins dedup by slug. */
	public function test_npm_filter_duplicate_slug_later_wins(): void {
		add_filter(
			'acrossai_mcp_npm_methods',
			static function ( array $methods ): array {
				$methods[] = array(
					'category'    => 'npm',
					'slug'        => 'npx-acrossai-mcp-manager', // Collides with built-in.
					'name'        => 'OVERRIDE',
					'description' => 'Companion-plugin override.',
					'icon'        => '',
					'meta'        => array(
						'command_template' => 'override-command %s %s',
						'enabled_option'   => 'override_option',
					),
				);
				return $methods;
			}
		);

		$methods = ConnectionMethodRegistry::instance()->get_npm_methods();
		$this->assertCount( 1, $methods, 'Later-wins dedup MUST collapse collision to a single entry.' );
		$this->assertSame( 'OVERRIDE', $methods[0]['name'], 'Later contribution MUST win.' );
	}

	/** FR-009b — missing-key contribution silently dropped. */
	public function test_npm_malformed_dto_missing_key_dropped(): void {
		add_filter(
			'acrossai_mcp_npm_methods',
			static function ( array $methods ): array {
				$methods[] = array( 'slug' => 'incomplete' ); // Missing 5 keys.
				return $methods;
			}
		);

		$methods = ConnectionMethodRegistry::instance()->get_npm_methods();
		$slugs   = array_column( $methods, 'slug' );
		$this->assertNotContains( 'incomplete', $slugs, 'Malformed entry MUST be dropped.' );
		$this->assertContains( 'npx-acrossai-mcp-manager', $slugs, 'Valid seed MUST survive.' );
	}

	/** SEC-035-001 / FR-009b — type-drift contributions dropped. */
	public static function type_drift_provider(): array {
		return array(
			'slug is array'    => array(
				array(
					'category'    => 'npm',
					'slug'        => array( 'not', 'string' ),
					'name'        => 'X',
					'description' => 'Y',
					'icon'        => '',
					'meta'        => array(),
				),
			),
			'meta is string'   => array(
				array(
					'category'    => 'npm',
					'slug'        => 'meta-string',
					'name'        => 'X',
					'description' => 'Y',
					'icon'        => '',
					'meta'        => 'not-array',
				),
			),
			'name is object'   => array(
				array(
					'category'    => 'npm',
					'slug'        => 'name-object',
					'name'        => new \stdClass(),
					'description' => 'Y',
					'icon'        => '',
					'meta'        => array(),
				),
			),
			'icon is int'      => array(
				array(
					'category'    => 'npm',
					'slug'        => 'icon-int',
					'name'        => 'X',
					'description' => 'Y',
					'icon'        => 42,
					'meta'        => array(),
				),
			),
		);
	}

	#[DataProvider( 'type_drift_provider' )]
	public function test_npm_malformed_dto_type_mismatch_dropped( array $bad_dto ): void {
		add_filter(
			'acrossai_mcp_npm_methods',
			static function ( array $methods ) use ( $bad_dto ): array {
				$methods[] = $bad_dto;
				return $methods;
			}
		);

		$methods = ConnectionMethodRegistry::instance()->get_npm_methods();
		$slugs   = array_column( $methods, 'slug' );

		// Bad DTO MUST NOT reach output — its slug key (whatever shape) MUST NOT
		// be present as a string in the slug column.
		if ( is_string( $bad_dto['slug'] ) ) {
			$this->assertNotContains(
				$bad_dto['slug'],
				$slugs,
				'Type-drift DTO MUST be dropped, not surfaced (SEC-035-001).'
			);
		}
		// The built-in seed MUST always remain — proves the filter didn't crash.
		$this->assertContains( 'npx-acrossai-mcp-manager', $slugs );
	}

	/** SC-004 — per-category getter does NOT fire cross-category filter. */
	public function test_get_npm_methods_does_not_fire_cross_category_filter(): void {
		$counter = 0;
		add_filter(
			'acrossai_mcp_connection_methods',
			static function ( array $assembled ) use ( &$counter ): array {
				++$counter;
				return $assembled;
			}
		);

		ConnectionMethodRegistry::instance()->get_npm_methods();
		ConnectionMethodRegistry::instance()->get_clients();
		ConnectionMethodRegistry::instance()->get_ai_connectors();

		$this->assertSame(
			0,
			$counter,
			'Cross-category filter MUST NOT fire on per-category getters (SC-004).'
		);
	}

	// -----------------------------------------------------------------
	// US3 — Cross-category filter + malformed fallback
	// -----------------------------------------------------------------

	/** SC-004 — fires exactly once inside get_all(), memoization prevents re-fire. */
	public function test_cross_category_filter_fires_once_in_get_all(): void {
		$counter = 0;
		add_filter(
			'acrossai_mcp_connection_methods',
			static function ( array $assembled ) use ( &$counter ): array {
				++$counter;
				return $assembled;
			}
		);

		ConnectionMethodRegistry::instance()->get_all();
		ConnectionMethodRegistry::instance()->get_all(); // cached
		ConnectionMethodRegistry::instance()->get_all(); // cached

		$this->assertSame( 1, $counter, 'Filter fires ONCE per request; memoization gates subsequent calls.' );
	}

	/** US3 acceptance — callback can remove a category. */
	public function test_cross_category_filter_can_remove_category(): void {
		add_filter(
			'acrossai_mcp_connection_methods',
			static function ( array $assembled ): array {
				$assembled['npm'] = array();
				return $assembled;
			}
		);

		$all = ConnectionMethodRegistry::instance()->get_all();
		$this->assertSame( array(), $all['npm'] );
		$this->assertNotEmpty( $all['clients'], 'Other categories MUST be unchanged.' );
	}

	/** FR-012a — non-array return falls back to pre-filter result. */
	public function test_cross_category_filter_malformed_returns_prefilter(): void {
		add_filter(
			'acrossai_mcp_connection_methods',
			static fn () => null // Callback returns non-array garbage.
		);

		$all = ConnectionMethodRegistry::instance()->get_all();
		$this->assertIsArray( $all );
		$this->assertArrayHasKey( 'npm', $all );
		$this->assertArrayHasKey( 'clients', $all );
		$this->assertArrayHasKey( 'ai_connectors', $all );
		// Pre-filter shape survives: default seeds still present.
		$this->assertCount( 1, $all['npm'] );
	}

	/** FR-012a — missing category key falls back to pre-filter result. */
	public function test_cross_category_filter_missing_category_key_returns_prefilter(): void {
		add_filter(
			'acrossai_mcp_connection_methods',
			static function (): array {
				// Missing 'ai_connectors' key.
				return array(
					'npm'     => array(),
					'clients' => array(),
				);
			}
		);

		$all = ConnectionMethodRegistry::instance()->get_all();
		// Pre-filter fallback: all three categories present, defaults intact.
		$this->assertArrayHasKey( 'ai_connectors', $all );
		$this->assertCount( 1, $all['npm'] );
	}
}
