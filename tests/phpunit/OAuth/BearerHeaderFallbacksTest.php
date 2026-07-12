<?php
/**
 * US3 — Bearer header 4-source fallback chain.
 *
 * FR-024: reads Authorization from `HTTP_AUTHORIZATION`, then
 * `REDIRECT_HTTP_AUTHORIZATION`, then `apache_request_headers()`, then
 * `getallheaders()`. First-hit-wins.
 *
 * Only $_SERVER-based sources are directly testable — the apache and
 * getallheaders fallbacks depend on the SAPI. Their code paths are guarded
 * by `function_exists` at runtime.
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
class BearerHeaderFallbacksTest extends OAuthTestCase {

	private int $user_id   = 0;
	private string $raw_token = '';

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();
		$this->user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$issued = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $this->user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => wp_generate_uuid4(),
		) );

		$this->raw_token = (string) $issued['raw'];
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/s';
	}

	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	public function test_http_authorization_wins_when_both_present(): void {
		// HTTP_AUTHORIZATION has the valid token; REDIRECT_HTTP_AUTHORIZATION has garbage.
		$_SERVER['HTTP_AUTHORIZATION']          = 'Bearer ' . $this->raw_token;
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer invalid-garbage';

		$this->assertSame( $this->user_id, TokenValidator::instance()->authenticate( 0 ) );
	}

	public function test_falls_back_to_redirect_http_authorization(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $this->raw_token;

		$this->assertSame( $this->user_id, TokenValidator::instance()->authenticate( 0 ) );
	}

	public function test_case_insensitive_bearer_prefix(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'bearer ' . $this->raw_token;

		$this->assertSame( $this->user_id, TokenValidator::instance()->authenticate( 0 ) );
	}

	public function test_uppercase_bearer_accepted(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'BEARER ' . $this->raw_token;

		$this->assertSame( $this->user_id, TokenValidator::instance()->authenticate( 0 ) );
	}

	public function test_extra_whitespace_around_token_stripped(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer   ' . $this->raw_token . '  ';

		$this->assertSame( $this->user_id, TokenValidator::instance()->authenticate( 0 ) );
	}
}
