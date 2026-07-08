<?php
/**
 * Adapter-level tests for FilteredServerTab.
 *
 * Feature 019.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\FilteredServerTab;
use WP_UnitTestCase;

final class FilteredServerTabTest extends WP_UnitTestCase {

	/**
	 * A user without the required capability MUST short-circuit
	 * visible_for() to false — the visible_callback MUST NOT run.
	 */
	public function test_capability_short_circuits_visible_for(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$callback_invoked = false;
		$tab              = new FilteredServerTab(
			array(
				'slug'             => 'gated',
				'label'            => 'Gated',
				'priority'         => 100,
				'capability'       => 'manage_options',
				'render_callback'  => static function (): void {},
				'visible_callback' => static function () use ( &$callback_invoked ): bool {
					$callback_invoked = true;
					return true;
				},
			)
		);

		$this->assertFalse( $tab->visible_for( array( 'id' => 1 ) ) );
		$this->assertFalse( $callback_invoked, 'visible_callback MUST NOT run when capability check fails.' );
	}

	/**
	 * Capability passes AND visible_callback returns false → overall false.
	 */
	public function test_visible_callback_composed_with_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tab = new FilteredServerTab(
			array(
				'slug'             => 'gated',
				'label'            => 'Gated',
				'priority'         => 100,
				'capability'       => 'manage_options',
				'render_callback'  => static function (): void {},
				'visible_callback' => static fn (): bool => false,
			)
		);

		$this->assertFalse( $tab->visible_for( array( 'id' => 1 ) ) );
	}

	/**
	 * Capability passes AND visible_callback returns true → overall true.
	 */
	public function test_visible_returns_true_when_both_pass(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tab = new FilteredServerTab(
			array(
				'slug'             => 'gated',
				'label'            => 'Gated',
				'priority'         => 100,
				'capability'       => 'manage_options',
				'render_callback'  => static function (): void {},
				'visible_callback' => static fn (): bool => true,
			)
		);

		$this->assertTrue( $tab->visible_for( array( 'id' => 1 ) ) );
	}

	/**
	 * No visible_callback set + capability passes → overall true.
	 */
	public function test_visible_returns_true_when_no_callback_set(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tab = new FilteredServerTab(
			array(
				'slug'             => 'plain',
				'label'            => 'Plain',
				'priority'         => 100,
				'capability'       => 'manage_options',
				'render_callback'  => static function (): void {},
				'visible_callback' => null,
			)
		);

		$this->assertTrue( $tab->visible_for( array( 'id' => 1 ) ) );
	}

	/**
	 * render_body() catches a `\Throwable` from render_callback, echoes an
	 * inline error notice, and does NOT propagate the throw.
	 */
	public function test_render_body_catches_throwable(): void {
		$tab = new FilteredServerTab(
			array(
				'slug'             => 'broken',
				'label'            => 'Broken',
				'priority'         => 100,
				'capability'       => 'manage_options',
				'render_callback'  => static function (): void {
					throw new \RuntimeException( 'boom' );
				},
				'visible_callback' => null,
			)
		);

		ob_start();
		$propagated = false;
		try {
			$tab->render( array( 'id' => 1 ) );
		} catch ( \Throwable $t ) {
			$propagated = true;
		}
		$body = ob_get_clean();

		$this->assertFalse( $propagated, 'Throwable MUST NOT propagate.' );
		$this->assertStringContainsString( 'notice-error', $body );
		$this->assertStringContainsString( 'broken', $body, 'Notice MUST reference the tab slug.' );
	}

	/**
	 * A throw from visible_callback is treated as "not visible" and does
	 * NOT propagate.
	 */
	public function test_visible_callback_throwable_treated_as_hidden(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$tab = new FilteredServerTab(
			array(
				'slug'             => 'broken-vis',
				'label'            => 'Broken Vis',
				'priority'         => 100,
				'capability'       => 'manage_options',
				'render_callback'  => static function (): void {},
				'visible_callback' => static function (): bool {
					throw new \RuntimeException( 'vis boom' );
				},
			)
		);

		$propagated = false;
		try {
			$result = $tab->visible_for( array( 'id' => 1 ) );
		} catch ( \Throwable $t ) {
			$propagated = true;
			$result     = null;
		}

		$this->assertFalse( $propagated, 'Throwable from visible_callback MUST NOT propagate.' );
		$this->assertFalse( $result );
	}

	/**
	 * A non-callable render_callback (should never happen in practice —
	 * Registry::normalize_entries() drops such entries — but the adapter
	 * defends against direct instantiation with a bad entry).
	 */
	public function test_non_callable_render_callback_emits_notice(): void {
		$tab = new FilteredServerTab(
			array(
				'slug'             => 'noop',
				'label'            => 'Noop',
				'priority'         => 100,
				'capability'       => 'manage_options',
				'render_callback'  => null,
				'visible_callback' => null,
			)
		);

		ob_start();
		$tab->render( array( 'id' => 1 ) );
		$body = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $body );
	}
}
