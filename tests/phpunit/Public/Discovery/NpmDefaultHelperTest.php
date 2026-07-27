<?php
/**
 * F035 — NpmClientBlock::get_default_npm_method() shape guard.
 *
 * Automated SC-007 template-drift regression: if the built-in NPM DTO's
 * command template OR its enabled_option WP option name drifts, this test
 * fails immediately rather than surfacing only during T030 manual DOM diff.
 *
 * Closes the coverage gap identified by /speckit-analyze C1 on 2026-07-26.
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Discovery
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Public\Discovery;

use AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock;
use WP_UnitTestCase;

final class NpmDefaultHelperTest extends WP_UnitTestCase {

	public function test_returns_six_top_level_keys(): void {
		$dto = NpmClientBlock::get_default_npm_method();
		foreach ( array( 'category', 'slug', 'name', 'description', 'icon', 'meta' ) as $key ) {
			$this->assertArrayHasKey( $key, $dto, "Default NPM DTO missing key: {$key}" );
		}
	}

	public function test_category_is_npm(): void {
		$this->assertSame( 'npm', NpmClientBlock::get_default_npm_method()['category'] );
	}

	public function test_slug_matches_built_in(): void {
		$this->assertSame(
			'npx-acrossai-mcp-manager',
			NpmClientBlock::get_default_npm_method()['slug']
		);
	}

	public function test_meta_has_command_template_and_enabled_option(): void {
		$meta = NpmClientBlock::get_default_npm_method()['meta'];
		$this->assertSame(
			'npx -y @acrossai/mcp-manager --siteurl=%s --server=%s',
			$meta['command_template'],
			'SC-007 template-drift guard: command template MUST match built-in verbatim.'
		);
		$this->assertSame(
			'acrossai_mcp_npm_login_enabled',
			$meta['enabled_option'],
			'SC-007 template-drift guard: enabled_option name MUST match built-in verbatim.'
		);
	}

	public function test_dto_is_json_serializable(): void {
		$dto     = NpmClientBlock::get_default_npm_method();
		$encoded = wp_json_encode( $dto );
		$this->assertNotFalse( $encoded );
		$decoded = json_decode( $encoded, true );
		$this->assertSame( $dto, $decoded );
	}
}
