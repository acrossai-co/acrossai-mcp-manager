<?php
/**
 * FrontendAuth::get_base_url() — FR-006 invariant.
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- test methods self-document; ad-hoc variable alignment kept for readability.

class GetBaseUrlTest extends WP_UnitTestCase {

	public function test_returns_home_url_with_page_slug(): void {
		$expected = home_url( '/' . FrontendAuth::PAGE_SLUG . '/' );
		$this->assertSame( $expected, FrontendAuth::get_base_url() );
	}

	public function test_byte_equal_across_multiple_calls(): void {
		$a = FrontendAuth::get_base_url();
		$b = FrontendAuth::get_base_url();
		$c = FrontendAuth::get_base_url();
		$this->assertSame( $a, $b );
		$this->assertSame( $b, $c );
	}

	public function test_uses_home_url_not_admin_url(): void {
		// FR-006: MUST resolve on the front-end where the user is logged in.
		// admin_url() would resolve under /wp-admin/ where this page is not
		// registered — Phase 6 auth_start composition would break.
		$got       = FrontendAuth::get_base_url();
		$home      = home_url( '/' );
		$admin     = admin_url( '/' );
		$this->assertStringStartsWith( rtrim( $home, '/' ) . '/', rtrim( $got, '/' ) . '/' );
		$this->assertStringNotContainsString( rtrim( $admin, '/' ), rtrim( $got, '/' ) );
	}

	public function test_page_slug_constant_value(): void {
		$this->assertSame( 'acrossai-mcp-manager', FrontendAuth::PAGE_SLUG );
	}

	public function test_query_var_constant_value(): void {
		// 2026-06-25 spec change: renamed from acrossai_mcp_frontend_auth.
		$this->assertSame( 'acrossai_mcp_auth', FrontendAuth::QUERY_VAR );
	}
}
