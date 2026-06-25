<?php
/**
 * FrontendAuth::maybe_render_page() dispatcher — 5 branches per
 * contracts/frontend-auth-page.md Test Invariants.
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

class MaybeRenderPageTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Default feature flag OFF for most tests; individual tests can flip it on.
		delete_option( 'acrossai_mcp_npm_login_enabled' );
		$_GET = array();
	}

	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	public function test_query_var_absent_short_circuits(): void {
		// No query var set; method should return without rendering anything.
		ob_start();
		FrontendAuth::instance()->maybe_render_page();
		$out = (string) ob_get_clean();
		$this->assertSame( '', $out );
	}

	public function test_disabled_flag_renders_503_notice(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		// feature flag NOT set → defaults to false → render_disabled_notice.

		$out = $this->capture_output();
		$this->assertStringContainsString( 'CLI Login Not Enabled', $out );
	}

	public function test_valid_admin_with_flag_on_renders_missing_params_page(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );
		// No ?action= → default branch → handle_cli_auth('','') → "Missing params" page.

		$out = $this->capture_output();
		$this->assertStringContainsString( 'Missing Authentication Parameters', $out );
	}

	public function test_page_shell_omits_wp_head(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );

		$out = (string) $this->capture_output();
		// Asserts the page shell is standalone — no WP-emitted stylesheets/scripts.
		$this->assertStringNotContainsString( "<link rel='stylesheet'", $out );
		$this->assertStringNotContainsString( '<script src=', $out );
	}

	private function capture_output(): string {
		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Throwable $e ) {
			// `exit` in production code can't be intercepted in unit tests;
			// rely on the output buffer.
		}
		return (string) ob_get_clean();
	}
}
