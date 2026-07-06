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

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry;
use WP_UnitTestCase;

final class RegistryTest extends WP_UnitTestCase {

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
}
