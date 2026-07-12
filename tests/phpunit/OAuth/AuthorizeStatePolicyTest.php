<?php
/**
 * AuthorizeStatePolicyTest — FR-045 RFC 9700 state parameter policy.
 *
 * Structural test suite mirroring `ConsentAlwaysRendersTest` shape. Verifies:
 *   (a) `_doing_it_wrong` is invoked in `handle_get` when state is empty and WP_DEBUG is on.
 *   (b) `redirect_error` conditionally injects `state` into the query args when non-empty.
 *   (c) Approve path emits `'state' => rawurlencode( $params['state'] )` on success redirect.
 *
 * These structural assertions catch regressions where a future refactor
 * accidentally drops the state-echo (breaking RFC 9700 §2.1 conformance) or
 * removes the WP_DEBUG advisory. End-to-end HTTP-level testing of the
 * redirect is deferred to Phase 8's quickstart walkthrough.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

/**
 * @coversNothing
 */
final class AuthorizeStatePolicyTest extends OAuthTestCase {

	/**
	 * Cached AuthorizationController source, loaded once per test method.
	 *
	 * @var string
	 */
	private $source = '';

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps — WP convention.
		parent::set_up();

		$path   = dirname( __DIR__, 3 ) . '/includes/OAuth/AuthorizationController.php';
		$source = file_get_contents( $path );

		if ( false === $source ) {
			$this->fail( 'Could not read AuthorizationController.php' );
		}

		$this->source = $source;
	}

	public function test_handle_get_warns_on_missing_state_under_wp_debug(): void {
		// The FR-045 advisory MUST appear in handle_get and be gated on WP_DEBUG.
		$this->assertMatchesRegularExpression(
			'/handle_get.*?WP_DEBUG.*?_doing_it_wrong/s',
			$this->source,
			'FR-045: handle_get must call _doing_it_wrong when state is empty and WP_DEBUG is on.'
		);

		$this->assertStringContainsString(
			"'' === \$params['state']",
			$this->source,
			'FR-045: the WP_DEBUG advisory must trigger on empty state, not present state.'
		);
	}

	public function test_redirect_error_echoes_state_when_present(): void {
		// The state echo must exist in redirect_error, gated on non-empty state.
		$this->assertStringContainsString(
			"if ( '' !== \$state ) {",
			$this->source,
			'FR-045: redirect_error must gate state echo on non-empty state.'
		);
		$this->assertStringContainsString(
			"\$args['state'] = rawurlencode( \$state );",
			$this->source,
			'FR-045: redirect_error must inject state via rawurlencode into the query args.'
		);
	}

	public function test_success_redirect_echoes_state(): void {
		// Approve branch must include 'state' in the success redirect args.
		$this->assertStringContainsString(
			"'state' => rawurlencode( \$params['state'] )",
			$this->source,
			'FR-045: Approve success redirect must echo state via rawurlencode( $params[\'state\'] ).'
		);
	}

	public function test_fr_045_advisory_comment_present(): void {
		// Anchor comment MUST be preserved — future refactors that remove it are
		// signaling an intent-change on the RECOMMENDED-vs-REQUIRED policy and
		// should require an explicit spec update, not a silent drift.
		$this->assertStringContainsString(
			'FR-045',
			$this->source,
			'FR-045: an FR-045 anchor comment must remain in AuthorizationController so state-policy intent is discoverable.'
		);
	}

	public function test_state_never_hard_required(): void {
		// Guard against future PRs that make state MANDATORY (spec says RECOMMENDED, not REQUIRED).
		// A hard requirement would show up as an explicit "state required" error message.
		$this->assertDoesNotMatchRegularExpression(
			'/state\s+(is\s+)?required|Missing\s+state\s+parameter[^-]*required|require.*state.*parameter/i',
			$this->source,
			'FR-045: state is RECOMMENDED (not REQUIRED). No code path may reject a request solely on missing state.'
		);
	}
}
