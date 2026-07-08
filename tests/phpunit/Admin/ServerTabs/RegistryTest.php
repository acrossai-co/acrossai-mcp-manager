<?php
/**
 * Tests for Registry — per-server-edit tab dispatch.
 *
 * Feature 013. PHPUnit 13+ note (per BUGS.md B9): use `#[DataProvider]` PHP
 * attribute instead of `@dataProvider` annotation — the annotation is
 * silently ignored.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\FilteredServerTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry;
use WP_UnitTestCase;

final class RegistryTest extends WP_UnitTestCase {

	/**
	 * Removes any filter callback the current test registered on
	 * Registry::FILTER_NAME so subsequent tests see a clean baseline.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		remove_all_filters( Registry::FILTER_NAME );
		parent::tear_down();
	}

	/**
	 * Verifies Registry::instance() returns a singleton.
	 */
	public function test_instance_returns_singleton(): void {
		$one = Registry::instance();
		$two = Registry::instance();
		$this->assertSame( $one, $two );
	}

	/**
	 * Verifies all_tabs() returns the 10 registered tabs in canonical order.
	 */
	public function test_slug_ordering_final(): void {
		$slugs = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			Registry::instance()->all_tabs()
		);
		$this->assertContains( 'overview', $slugs );
		$this->assertContains( 'npm', $slugs );
		$this->assertContains( 'clients', $slugs );
		$this->assertContains( 'wp-cli', $slugs );
		$this->assertContains( 'tools', $slugs );
		$this->assertContains( 'abilities', $slugs );
		$this->assertContains( 'access-control', $slugs );
		$this->assertContains( 'mcp-tracker', $slugs );
		$this->assertContains( 'update-server', $slugs );
		$this->assertContains( 'danger-zone', $slugs );
		$this->assertCount( 10, $slugs );
	}

	/**
	 * Slug uniqueness — no two tabs share the same slug.
	 */
	public function test_slug_uniqueness(): void {
		$slugs = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			Registry::instance()->all_tabs()
		);
		$this->assertSame( count( $slugs ), count( array_unique( $slugs ) ) );
	}

	/**
	 * DB-only tabs (UpdateServer, DangerZone) are visible ONLY when
	 * $server['registered_from'] === 'database'.
	 */
	public function test_visible_tabs_gates_db_only_on_plugin_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 1,
				'registered_from' => 'plugin',
			)
		);
		$slugs = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			$visible
		);
		$this->assertNotContains( 'update-server', $slugs );
		$this->assertNotContains( 'danger-zone', $slugs );
	}

	/**
	 * DB-only tabs visible when registered_from = database.
	 */
	public function test_visible_tabs_includes_db_only_on_database_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 2,
				'registered_from' => 'database',
			)
		);
		$slugs = array_map(
			static function ( $tab ) {
				return $tab->slug();
			},
			$visible
		);
		$this->assertContains( 'update-server', $slugs );
		$this->assertContains( 'danger-zone', $slugs );
	}

	/**
	 * Verifies render() with an unknown slug does NOT fatal (silent no-op
	 * when all_tabs() is empty; fallback to first tab post-T012).
	 */
	public function test_render_unknown_slug_falls_back_gracefully(): void {
		$this->expectNotToPerformAssertions();
		Registry::instance()->render( 'unknown-slug', array( 'id' => 1 ) );
	}

	/**
	 * Plugin-source servers see 9 tabs (11 total minus UpdateServer + DangerZone).
	 */
	public function test_visible_tabs_returns_9_when_plugin_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 1,
				'registered_from' => 'plugin',
			)
		);
		$this->assertCount( 9, $visible );
	}

	/**
	 * Database-source servers see 11 tabs (full canonical set).
	 */
	public function test_visible_tabs_returns_11_when_database_source(): void {
		$visible = Registry::instance()->visible_tabs(
			array(
				'id'              => 2,
				'registered_from' => 'database',
			)
		);
		$this->assertCount( 11, $visible );
	}

	// =========================================================================
	// Feature 019 — third-party filter tests.
	// =========================================================================

	/**
	 * The filter fires with the seeded built-in list AND the current
	 * $server row as the two arguments.
	 */
	public function test_for_server_fires_filter_with_server_context(): void {
		$captured_server = null;
		$captured_count  = 0;

		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs, array $server ) use ( &$captured_server, &$captured_count ): array {
				$captured_server = $server;
				$captured_count  = count( $tabs );
				return $tabs;
			},
			10,
			2
		);

		$server = array(
			'id'              => 42,
			'registered_from' => 'database',
			'server_name'     => 'FeatureTest Server',
		);
		Registry::instance()->for_server( $server );

		$this->assertSame( $server, $captured_server, 'Filter must receive the exact $server argument.' );
		$this->assertSame( 10, $captured_count, 'Filter must be seeded with the 10 built-in entries.' );
	}

	/**
	 * With no callback registered, `for_server()` returns the 10 built-ins
	 * in canonical priority order.
	 */
	public function test_for_server_returns_builtins_when_no_callback(): void {
		$tabs = Registry::instance()->for_server(
			array(
				'id'              => 1,
				'registered_from' => 'database',
			)
		);

		$slugs = array_map( static fn ( $t ) => $t->slug(), $tabs );

		$this->assertSame(
			array(
				'overview',
				'npm',
				'clients',
				'wp-cli',
				'tools',
				'abilities',
				'access-control',
				'mcp-tracker',
				'update-server',
				'danger-zone',
			),
			$slugs,
			'Canonical priority order MUST be preserved when no callback runs.'
		);
	}

	/**
	 * A callback can append a third-party entry; it appears in
	 * for_server(), sits at the priority-determined position, and dispatches
	 * via render() to its callback.
	 */
	public function test_filter_can_add_a_tab(): void {
		$callback_invoked = false;

		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ) use ( &$callback_invoked ): array {
				$tabs[] = array(
					'slug'            => 'notes',
					'label'           => 'Notes',
					'priority'        => 45,
					'render_callback' => static function () use ( &$callback_invoked ): void {
						$callback_invoked = true;
						echo 'notes tab body';
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$server = array(
			'id'              => 1,
			'registered_from' => 'database',
		);
		$tabs   = Registry::instance()->for_server( $server );
		$slugs  = array_map( static fn ( $t ) => $t->slug(), $tabs );

		$this->assertContains( 'notes', $slugs, 'Third-party slug MUST appear in for_server() output.' );

		// Priority 45 slots between WpCliTab (40) and ToolsTab (50).
		$notes_index = array_search( 'notes', $slugs, true );
		$wpcli_index = array_search( 'wp-cli', $slugs, true );
		$tools_index = array_search( 'tools', $slugs, true );
		$this->assertGreaterThan( $wpcli_index, $notes_index );
		$this->assertLessThan( $tools_index, $notes_index );

		ob_start();
		Registry::instance()->render( 'notes', $server );
		$body = ob_get_clean();

		$this->assertTrue( $callback_invoked, 'render() MUST dispatch to the third-party callback.' );
		$this->assertStringContainsString( 'notes tab body', $body );
	}

	/**
	 * A callback can unset a built-in entry; it disappears from
	 * for_server() and render() falls through to the first tab when
	 * the removed slug is requested.
	 */
	public function test_filter_can_remove_a_builtin(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				return array_values(
					array_filter(
						$tabs,
						static fn ( array $entry ): bool => 'mcp-tracker' !== ( $entry['slug'] ?? '' )
					)
				);
			},
			10,
			2
		);

		$server = array(
			'id'              => 1,
			'registered_from' => 'database',
		);
		$slugs  = array_map( static fn ( $t ) => $t->slug(), Registry::instance()->for_server( $server ) );

		$this->assertNotContains( 'mcp-tracker', $slugs );
		$this->assertCount( 9, $slugs );

		// Unknown-slug render() falls back to the first surviving tab.
		ob_start();
		Registry::instance()->render( 'mcp-tracker', $server );
		$body = ob_get_clean();
		$this->assertIsString( $body );
	}

	/**
	 * A third-party priority below any built-in makes the tab render leftmost.
	 */
	public function test_filter_can_reorder_via_priority(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'            => 'leftmost',
					'label'           => 'Leftmost',
					'priority'        => 5,
					'render_callback' => static function (): void {
						echo 'leftmost body';
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$slugs = array_map(
			static fn ( $t ) => $t->slug(),
			Registry::instance()->for_server( array( 'id' => 1, 'registered_from' => 'database' ) )
		);

		$this->assertSame( 'leftmost', $slugs[0], 'Priority 5 MUST sort before all built-ins.' );
	}

	/**
	 * A malformed entry (missing render_callback) is dropped without a
	 * fatal — `_doing_it_wrong` fires under WP_DEBUG but the outer request
	 * continues.
	 */
	public function test_malformed_entry_dropped_without_fatal(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'  => 'bad',
					'label' => 'Bad',
					// intentionally omit render_callback
				);
				return $tabs;
			},
			10,
			2
		);

		$slugs = array_map(
			static fn ( $t ) => $t->slug(),
			Registry::instance()->for_server( array( 'id' => 1, 'registered_from' => 'database' ) )
		);

		$this->assertNotContains( 'bad', $slugs );
	}

	/**
	 * A third-party entry cannot clobber a built-in slug — first-registration
	 * wins.
	 */
	public function test_duplicate_slug_first_registration_wins(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'            => 'overview',
					'label'           => 'HIJACKED',
					'render_callback' => static function (): void {
						echo 'hijacked';
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$tabs = Registry::instance()->for_server( array( 'id' => 1, 'registered_from' => 'database' ) );

		$overview = null;
		foreach ( $tabs as $t ) {
			if ( 'overview' === $t->slug() ) {
				$overview = $t;
				break;
			}
		}

		$this->assertNotNull( $overview );
		$this->assertNotInstanceOf( FilteredServerTab::class, $overview, 'Built-in Overview instance MUST be preserved.' );
		$this->assertSame( 'Overview', $overview->label(), 'Third-party label MUST NOT clobber built-in label.' );
	}

	/**
	 * A third-party render_callback throwing MUST NOT propagate to
	 * Registry::render(); the outer request continues with an inline
	 * error notice rendered in-place.
	 */
	public function test_throwing_render_callback_is_isolated(): void {
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ): array {
				$tabs[] = array(
					'slug'            => 'broken',
					'label'           => 'Broken',
					'priority'        => 999,
					'render_callback' => static function (): void {
						throw new \RuntimeException( 'boom' );
					},
				);
				return $tabs;
			},
			10,
			2
		);

		$server = array(
			'id'              => 1,
			'registered_from' => 'database',
		);

		ob_start();
		try {
			Registry::instance()->render( 'broken', $server );
			$body     = ob_get_clean();
			$fataled  = false;
		} catch ( \Throwable $t ) {
			ob_end_clean();
			$body    = '';
			$fataled = true;
		}

		$this->assertFalse( $fataled, 'A throwing render_callback MUST NOT propagate to Registry::render().' );
		$this->assertStringContainsString( 'notice-error', $body, 'Inline error notice MUST be rendered.' );
	}
}
