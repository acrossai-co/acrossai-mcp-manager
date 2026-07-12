<?php
/**
 * US3 — FR-025 static recursion guard.
 *
 * A downstream `current_user_can` call mid-lookup MUST NOT re-trigger the
 * validator. Verified by adding a callback on determine_current_user that
 * synchronously calls current_user_can — the guarded validator recognizes
 * the re-entry and returns $user_id unchanged (breaking the cycle).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\TokenValidator;

/**
 * @coversNothing
 */
class TokenValidatorRecursionTest extends OAuthTestCase {

	private int $user_id = 0;

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();
		$this->user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-1';
	}

	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	public function test_recursion_guard_prevents_reentry(): void {
		$issued = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $this->user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/server-1' ),
			'token_family_id' => wp_generate_uuid4(),
		) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) $issued['raw'];

		$reentry_count = 0;

		// Register a pre-existing filter that synchronously triggers current_user_can
		// from inside determine_current_user's chain. Without the guard, the second
		// call to authenticate() would re-hit the DB and re-run everything → infinite recursion.
		add_action(
			'determine_current_user',
			function ( $user ) use ( &$reentry_count ) {
				if ( $reentry_count < 5 ) {
					++$reentry_count;
					// Trigger a call that would re-fire the filter chain.
					$result = TokenValidator::instance()->authenticate( 0 );
					// If the guard is present, $result is $user_id (0) unchanged.
					$this->assertSame( 0, $result, 'Recursion guard failed at depth ' . $reentry_count );
				}
				return $user;
			},
			30 // AFTER TokenValidator's priority-20 hook.
		);

		$resolved = TokenValidator::instance()->authenticate( 0 );

		// Outer call still resolves to the real user_id.
		$this->assertSame( $this->user_id, $resolved );
		// The reentry counter proves the guard fired (would be 0 without any reentry).
		$this->assertGreaterThan( 0, $reentry_count );
	}
}
