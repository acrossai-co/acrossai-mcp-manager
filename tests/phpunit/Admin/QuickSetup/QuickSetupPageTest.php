<?php
/**
 * F069 T043 — QuickSetupPage render test.
 *
 * Locks the render invariants:
 *   1. Singleton returns the same instance across calls.
 *   2. render() emits the WP-native wrap div + mount point + noscript fallback.
 *   3. render() emits ZERO inline <script> tags (Constitution: React bootstrap
 *      comes from wp_localize_script only; the page must not smuggle inline JS).
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\Admin\QuickSetup
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Admin\QuickSetup;

use AcrossAI_MCP_Manager\Admin\Partials\QuickSetup\QuickSetupPage;
use WP_UnitTestCase;

final class QuickSetupPageTest extends WP_UnitTestCase {

	public function test_singleton_returns_same_instance(): void {
		$a = QuickSetupPage::instance();
		$b = QuickSetupPage::instance();
		$this->assertSame( $a, $b );
	}

	public function test_render_emits_wrap_and_mount_point(): void {
		ob_start();
		QuickSetupPage::instance()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'class="wrap acrossai-mcp-quick-setup-wrap"', $html );
		$this->assertStringContainsString( 'id="acrossai-mcp-quick-setup-root"', $html );
	}

	public function test_render_emits_noscript_fallback(): void {
		ob_start();
		QuickSetupPage::instance()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'requires JavaScript', $html );
		$this->assertStringContainsString( '</noscript>', $html );
	}

	public function test_render_emits_no_inline_scripts(): void {
		ob_start();
		QuickSetupPage::instance()->render();
		$html = ob_get_clean();

		// Constitution: React bootstrap is delivered via wp_localize_script from
		// admin/Main::enqueue_scripts() only. Zero inline JS in the render path.
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_render_output_is_deterministic(): void {
		ob_start();
		QuickSetupPage::instance()->render();
		$first = ob_get_clean();

		ob_start();
		QuickSetupPage::instance()->render();
		$second = ob_get_clean();

		$this->assertSame( $first, $second, 'render() must be pure — no state carried between calls.' );
	}
}
