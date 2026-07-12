<?php
/**
 * US3 — TokenValidator core behavior.
 *
 * Verifies FR-024/FR-026 pass-through invariants: valid token resolves;
 * every failure path returns `$user_id` unchanged.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;
use AcrossAI_MCP_Manager\Includes\OAuth\TokenValidator;

/**
 * @coversNothing
 */
class TokenValidatorTest extends OAuthTestCase {

	private int $user_id = 0;

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();
		$this->user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		// Route the request through home_url() so audience_matches_request has a target.
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/v1/server-1/tools/call';
	}

	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	public function test_valid_token_resolves_to_user_id(): void {
		$raw = $this->issue_active_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw;

		$this->assertSame( $this->user_id, TokenValidator::instance()->authenticate( 0 ) );
	}

	public function test_missing_header_returns_original_user_id(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertSame( 42, TokenValidator::instance()->authenticate( 42 ) );
	}

	public function test_malformed_header_returns_original_user_id(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'NotBearer xxx';

		$this->assertSame( 42, TokenValidator::instance()->authenticate( 42 ) );
	}

	public function test_unknown_token_returns_original(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . SecretsVault::random_token();

		$this->assertSame( 42, TokenValidator::instance()->authenticate( 42 ) );
	}

	public function test_revoked_token_returns_original(): void {
		$raw = $this->issue_active_token();
		$this->revoke_hash( SecretsVault::hash( $raw ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw;

		$this->assertSame( 42, TokenValidator::instance()->authenticate( 42 ) );
	}

	public function test_expired_token_returns_original(): void {
		$raw = $this->issue_active_token();
		$this->expire_hash( SecretsVault::hash( $raw ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw;

		$this->assertSame( 42, TokenValidator::instance()->authenticate( 42 ) );
	}

	public function test_refresh_token_type_rejected_as_bearer(): void {
		$raw = SecretsVault::random_token();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			array(
				'token_hash'      => SecretsVault::hash( $raw ),
				'token_type'      => 'refresh', // NOT access.
				'client_id'       => 'test-client',
				'user_id'         => $this->user_id,
				'scope'           => 'mcp',
				'resource'        => home_url( '/wp-json/mcp/v1/server-1' ),
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'revoked'         => 0,
				'token_family_id' => wp_generate_uuid4(),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw;

		$this->assertSame( 42, TokenValidator::instance()->authenticate( 42 ) );
	}

	public function test_already_authenticated_short_circuits(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . SecretsVault::random_token();

		// A prior filter already returned a real user_id → validator MUST NOT touch DB / override.
		$this->assertSame( 7, TokenValidator::instance()->authenticate( 7 ) );
	}

	private function issue_active_token(): string {
		$issued = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $this->user_id,
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/server-1' ),
			'token_family_id' => wp_generate_uuid4(),
		) );

		return (string) $issued['raw'];
	}

	private function revoke_hash( string $hash ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			array( 'revoked' => 1 ),
			array( 'token_hash' => $hash )
		);
	}

	private function expire_hash( string $hash ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'token_hash' => $hash )
		);
	}
}
