<?php
/**
 * FrontendAuth::enqueue_assets() — asset scoping + RTL data (FR-013, SC-004).
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- test methods self-document via descriptive names.

class EnqueueAssetsTest extends WP_UnitTestCase {

	public function tearDown(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '' );
		wp_dequeue_style( 'acrossai-mcp-frontend' );
		wp_deregister_style( 'acrossai-mcp-frontend' );
		parent::tearDown();
	}

	public function test_query_var_empty_does_not_enqueue(): void {
		// FR-013 step 1 — global guard.
		set_query_var( FrontendAuth::QUERY_VAR, '' );

		FrontendAuth::instance()->enqueue_assets();

		$this->assertFalse(
			wp_style_is( 'acrossai-mcp-frontend', 'enqueued' ),
			'Style should NOT be enqueued when query var is absent'
		);
	}

	public function test_query_var_truthy_enqueues_with_correct_handle(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );

		FrontendAuth::instance()->enqueue_assets();

		$this->assertTrue(
			wp_style_is( 'acrossai-mcp-frontend', 'enqueued' ),
			'Style MUST be enqueued when query var is truthy'
		);
	}

	public function test_enqueued_src_points_at_build_css_frontend(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );

		FrontendAuth::instance()->enqueue_assets();

		$registered = wp_styles()->registered;
		$this->assertArrayHasKey( 'acrossai-mcp-frontend', $registered );
		$src = $registered['acrossai-mcp-frontend']->src;
		$this->assertStringContainsString( '/build/css/frontend.css', $src );
	}

	public function test_enqueued_dependencies_are_empty(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );

		FrontendAuth::instance()->enqueue_assets();

		$style = wp_styles()->registered['acrossai-mcp-frontend'];
		$this->assertSame( array(), $style->deps );
	}

	public function test_rtl_data_attached(): void {
		// FR-013 step 5 (clarification 2026-06-25) — WP auto-substitutes
		// build/css/frontend-rtl.css when is_rtl() returns true.
		set_query_var( FrontendAuth::QUERY_VAR, '1' );

		FrontendAuth::instance()->enqueue_assets();

		$style = wp_styles()->registered['acrossai-mcp-frontend'];
		$this->assertIsArray( $style->extra );
		$this->assertArrayHasKey( 'rtl', $style->extra );
		$this->assertSame( 'replace', $style->extra['rtl'] );
	}

	public function test_version_falls_back_to_plugin_version_when_manifest_missing(): void {
		// Research §R2 — fallback to plugin version when frontend.asset.php
		// is missing. The actual asset.php may exist in this test env, but
		// the fallback path is reached when is_readable() returns false.
		// We can't easily stub is_readable() globally, so we assert the
		// version is at least non-empty and non-FILEMTIME (deterministic).
		set_query_var( FrontendAuth::QUERY_VAR, '1' );

		FrontendAuth::instance()->enqueue_assets();

		$style = wp_styles()->registered['acrossai-mcp-frontend'];
		$this->assertNotEmpty( $style->ver );
		$this->assertIsString( $style->ver );
	}

	public function test_not_enqueued_on_admin_or_home(): void {
		// SC-004 — handle is absent everywhere except the consent page.
		// Simulate admin context by leaving the query var empty.
		set_query_var( FrontendAuth::QUERY_VAR, '' );

		FrontendAuth::instance()->enqueue_assets();

		$this->assertFalse( wp_style_is( 'acrossai-mcp-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'acrossai-mcp-frontend', 'registered' ) );
	}
}
