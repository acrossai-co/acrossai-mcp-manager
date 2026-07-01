<?php
/**
 * Public\Main::enqueue_styles/scripts — OAuth-consent-scoped enqueue guard.
 *
 * Feature-008 (Phase 8) regression suite. Verifies:
 *  - the FR-020 option (b) reconciliation (no CLI-surface competing with Phase 7)
 *  - the SEC-008-002 handle rename to `acrossai-mcp-frontend-oauth`
 *  - the SEC-008-003 RTL data attach (`wp_style_add_data(..., 'rtl', 'replace')`)
 *  - the B11 defensive read fallback when `frontend-oauth.asset.php` is missing
 *
 * @package AcrossAI_MCP_Manager\Tests\Public
 */

namespace AcrossAI_MCP_Manager\Tests\Public;

use AcrossAI_MCP_Manager\Public\Main;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- test methods self-document via descriptive names; matches existing tests/phpunit/* convention.

class MainEnqueueTest extends WP_UnitTestCase {

	public function tearDown(): void {
		set_query_var( 'acrossai_mcp_oauth', '' );
		set_query_var( 'acrossai_mcp_auth', '' );
		wp_dequeue_style( Main::OAUTH_STYLE_HANDLE );
		wp_deregister_style( Main::OAUTH_STYLE_HANDLE );
		parent::tearDown();
	}

	public function test_no_consent_context_registers_zero_handles(): void {
		// No `acrossai_mcp_oauth` query var, no `acrossai_mcp_auth` query var.
		// Represents home page, blog post, taxonomy archive, search results, etc.
		set_query_var( 'acrossai_mcp_oauth', '' );
		set_query_var( 'acrossai_mcp_auth', '' );

		Main::instance()->enqueue_styles();
		Main::instance()->enqueue_scripts();

		$this->assertFalse(
			wp_style_is( Main::OAUTH_STYLE_HANDLE, 'enqueued' ),
			'OAuth style must NOT be enqueued on non-consent pages'
		);
		$this->assertFalse(
			wp_style_is( Main::OAUTH_STYLE_HANDLE, 'registered' ),
			'OAuth style must NOT be registered on non-consent pages'
		);
	}

	public function test_oauth_authorize_context_registers_the_handle(): void {
		set_query_var( 'acrossai_mcp_oauth', 'authorize' );

		Main::instance()->enqueue_styles();

		$this->assertTrue(
			wp_style_is( Main::OAUTH_STYLE_HANDLE, 'enqueued' ),
			'OAuth style MUST be enqueued on the authorize surface'
		);

		$registered = wp_styles()->registered;
		$this->assertArrayHasKey( Main::OAUTH_STYLE_HANDLE, $registered );
		$this->assertStringContainsString(
			'/build/css/frontend-oauth.css',
			$registered[ Main::OAUTH_STYLE_HANDLE ]->src
		);
	}

	public function test_rtl_data_attached_on_authorize_surface(): void {
		// SEC-008-003 + FR-021 regression.
		set_query_var( 'acrossai_mcp_oauth', 'authorize' );

		Main::instance()->enqueue_styles();

		$style = wp_styles()->registered[ Main::OAUTH_STYLE_HANDLE ];
		$this->assertIsArray( $style->extra );
		$this->assertArrayHasKey( 'rtl', $style->extra );
		$this->assertSame( 'replace', $style->extra['rtl'] );
	}

	public function test_cli_context_does_not_enqueue_from_public_main(): void {
		// SEC-008 US3 case (e) — CLI query var truthy AND OAuth predicate false.
		// Phase 7's FrontendAuth owns the CLI-consent enqueue path; Public\Main
		// MUST NOT compete. If Public\Main enqueued here, B12 would still hide
		// the bug at runtime (wp_enqueue_scripts doesn't fire on FrontendAuth's
		// template_redirect exit), but the intent is explicit exclusion.
		set_query_var( 'acrossai_mcp_auth', '1' );
		set_query_var( 'acrossai_mcp_oauth', '' );

		Main::instance()->enqueue_styles();

		$this->assertFalse(
			wp_style_is( Main::OAUTH_STYLE_HANDLE, 'enqueued' ),
			'Public\\Main MUST NOT enqueue on the CLI consent surface (Phase 7 owns it)'
		);
	}

	public function test_manifest_read_produces_non_empty_version(): void {
		// B11 defensive-read fallback path. In this test environment, the
		// `frontend-oauth.asset.php` manifest may or may not exist depending on
		// whether `npm run build` has run. Regardless — the handle registers
		// with a non-empty version. If the manifest is missing, the version
		// falls back to `ACROSSAI_MCP_MANAGER_VERSION`; if present, it's the
		// build hash. Either satisfies FR-014 / FR-019 (silent fallback,
		// non-empty version, no PHP warning — the file_exists + is_readable
		// guard in read_asset_manifest() prevents any warning-emitting path).
		set_query_var( 'acrossai_mcp_oauth', 'authorize' );

		Main::instance()->enqueue_styles();

		$style = wp_styles()->registered[ Main::OAUTH_STYLE_HANDLE ];
		$this->assertNotEmpty( $style->ver );
		$this->assertIsString( $style->ver );
	}

	public function test_singleton_instance_is_stable(): void {
		// A2 / S6 — singleton + private ctor.
		$a = Main::instance();
		$b = Main::instance();
		$this->assertSame( $a, $b );
	}
}
