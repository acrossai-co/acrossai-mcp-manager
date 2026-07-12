<?php
/**
 * US3 / SEC-021-T05 — SC-011 no-header short-circuit.
 *
 * When no Authorization header is present, TokenValidator MUST return
 * $user_id unchanged WITHOUT touching the DB. Regression guard against a
 * future refactor moving header-read behind a query.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\TokenValidator;

/**
 * @coversNothing
 */
class TokenValidatorNoHeaderShortCircuitTest extends OAuthTestCase {

	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	public function test_no_header_short_circuits_before_db(): void {
		global $wpdb;

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		// Capture query count BEFORE the call.
		$before = $wpdb->num_queries;

		$result = TokenValidator::instance()->authenticate( 42 );

		// Assert unchanged pass-through.
		$this->assertSame( 42, $result );

		// Zero DB queries were issued.
		$this->assertSame(
			$before,
			$wpdb->num_queries,
			'SC-011 violated: TokenValidator hit the DB with no bearer header present.'
		);
	}

	public function test_already_authenticated_also_short_circuits(): void {
		global $wpdb;

		// Simulate cookie-authenticated user upstream.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer any-nonzero-string';

		$before = $wpdb->num_queries;

		// Prior filter already returned real user_id → validator MUST NOT touch DB.
		$result = TokenValidator::instance()->authenticate( 7 );

		$this->assertSame( 7, $result );
		$this->assertSame(
			$before,
			$wpdb->num_queries,
			'SC-011 violated: TokenValidator hit the DB despite prior auth.'
		);
	}
}
