<?php
/**
 * US3 — SC-007 / Q1 RFC 8707 audience-binding.
 *
 * A token issued for server-A MUST be rejected when presented against
 * server-B on the same site. Verified by binding the token's `resource`
 * column to server-A's URL, then simulating a request to server-B.
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
class TokenValidatorAudienceTest extends OAuthTestCase {

	private int $user_id = 0;
	private string $raw_token = '';

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();

		$this->user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		// Token issued for server-A.
		$issued = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $this->user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/server-A' ),
			'token_family_id' => wp_generate_uuid4(),
		) );

		$this->raw_token = (string) $issued['raw'];
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->raw_token;
	}

	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'] );
		parent::tear_down();
	}

	public function test_server_a_call_succeeds(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-A/tools/call';

		$this->assertSame(
			$this->user_id,
			TokenValidator::instance()->authenticate( 0 )
		);
	}

	public function test_server_b_call_rejected(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-B/tools/call';

		// Cross-server → anonymous (returns original $user_id unchanged).
		$this->assertSame(
			42,
			TokenValidator::instance()->authenticate( 42 )
		);
	}

	public function test_exact_path_match_succeeds(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-A';

		$this->assertSame(
			$this->user_id,
			TokenValidator::instance()->authenticate( 0 )
		);
	}

	public function test_query_string_ignored(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-A?foo=bar';

		$this->assertSame(
			$this->user_id,
			TokenValidator::instance()->authenticate( 0 )
		);
	}

	public function test_prefix_substring_but_not_path_segment_rejected(): void {
		// server-A2 starts with the same prefix but is NOT a sub-path of server-A.
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-A2/tools/call';

		$this->assertSame(
			42,
			TokenValidator::instance()->authenticate( 42 )
		);
	}

	public function test_empty_resource_column_rejected(): void {
		// Simulate a legacy row with resource='' — MUST NOT authenticate.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			array( 'resource' => '' ),
			array( 'token_hash' => hash( 'sha256', $this->raw_token ) )
		);

		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-A';

		$this->assertSame(
			42,
			TokenValidator::instance()->authenticate( 42 )
		);
	}
}
