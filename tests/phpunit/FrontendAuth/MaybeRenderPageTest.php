<?php
/**
 * FrontendAuth::maybe_render_page() — global guard + login redirect + kill switch.
 *
 * Replaces the Phase 6.0 test file. Aligns with the re-spec'd FR-007
 * (no manage_options check; new QUERY_VAR; SEC-005 503 hardening).
 *
 * @package AcrossAI_MCP_Manager\Tests\FrontendAuth
 */

namespace AcrossAI_MCP_Manager\Tests\FrontendAuth;

use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Squiz.Commenting.EmptyCatchComment.Missing -- test methods self-document; empty catches deliberately swallow the wp_die / redirect-intercept exception so the assertion path can continue.

class MaybeRenderPageTest extends WP_UnitTestCase {

	public function tearDown(): void {
		$_GET = array();
		set_query_var( FrontendAuth::QUERY_VAR, '' );
		delete_option( 'acrossai_mcp_npm_login_enabled' );
		parent::tearDown();
	}

	public function test_query_var_absent_short_circuits_with_no_output(): void {
		// FR-007.1 — global guard.
		set_query_var( FrontendAuth::QUERY_VAR, '' );
		ob_start();
		FrontendAuth::instance()->maybe_render_page();
		$out = (string) ob_get_clean();
		$this->assertSame( '', $out );
	}

	public function test_logged_out_redirects_to_wp_login_with_base_url_only(): void {
		// FR-007.3 + research §R3 — base URL only, no query preservation.
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		wp_set_current_user( 0 );
		$_GET = array(
			'action'   => 'cli_auth_approve',
			'code'     => 'leaky-code',
			'server'   => 'leaky-server',
			'_wpnonce' => 'leaky-nonce',
		);

		$redirect_target = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirect_target ) {
				$redirect_target = $location;
				// Throw so the test never reaches the exit; the
				// throw-from-filter pattern matches the repo's
				// existing convention (see OAuth tests).
				throw new \RuntimeException( 'redirect_intercepted' );
			},
			10,
			1
		);

		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \RuntimeException $e ) {
			// expected — redirect was intercepted before exit.
		}
		ob_end_clean();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'wp-login.php', $redirect_target );
		// redirect_to must carry the BASE URL only, NOT the leaky GET params.
		$this->assertStringNotContainsString( 'leaky-code', $redirect_target );
		$this->assertStringNotContainsString( 'leaky-server', $redirect_target );
		$this->assertStringNotContainsString( 'leaky-nonce', $redirect_target );
		$this->assertStringNotContainsString( 'cli_auth_approve', $redirect_target );
	}

	public function test_kill_switch_off_renders_503_disabled_notice(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		delete_option( 'acrossai_mcp_npm_login_enabled' );

		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
		}
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'CLI Login Not Enabled', $out );
		$this->assertStringContainsString( 'noindex,nofollow', $out, 'SEC-005: noindex meta missing on 503' );
	}

	public function test_kill_switch_off_does_not_dispatch_to_handlers(): void {
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		delete_option( 'acrossai_mcp_npm_login_enabled' );
		$_GET['action'] = 'cli_auth';
		$_GET['code']   = 'whatever';

		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
		}
		$out = (string) ob_get_clean();

		// The disabled-notice page, not the consent form.
		$this->assertStringContainsString( 'CLI Login Not Enabled', $out );
		$this->assertStringNotContainsString( 'Authorize CLI Access', $out );
	}

	public function test_unknown_action_falls_through_to_cli_auth_default(): void {
		// FR-008 dispatch — unknown actions render the default cli_auth path
		// which (with empty code) emits the missing-params message.
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );
		$_GET['action'] = 'totally-unknown-action';
		$_GET['code']   = '';

		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
		}
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Missing Authentication Parameters', $out );
	}

	public function test_subscriber_role_can_reach_dispatch(): void {
		// FR-007.4 — NO manage_options check. Any logged-in user proceeds.
		// SC-002 regression.
		set_query_var( FrontendAuth::QUERY_VAR, '1' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		update_option( 'acrossai_mcp_npm_login_enabled', 1 );
		$_GET['action'] = 'cli_auth';
		$_GET['code']   = '';

		ob_start();
		try {
			FrontendAuth::instance()->maybe_render_page();
		} catch ( \Exception $e ) {
		}
		$out = (string) ob_get_clean();

		// We should hit handle_cli_auth (missing-params path because code='').
		// We should NOT see a 403 / "You do not have permission" message.
		$this->assertStringContainsString( 'Missing Authentication Parameters', $out );
		$this->assertStringNotContainsString( 'do not have permission', $out );
	}

	public function test_singleton_instance_is_stable(): void {
		// A2 / S6 — singleton + private ctor.
		$a = FrontendAuth::instance();
		$b = FrontendAuth::instance();
		$this->assertSame( $a, $b );
	}
}
