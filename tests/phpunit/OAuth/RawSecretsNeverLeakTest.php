<?php
/**
 * SEC-021-T06 — Runtime observability guard: raw secrets NEVER leak into
 * logs, transients, or error response bodies.
 *
 * Static grep (T120) catches every current leak vector at code-review time;
 * this test catches leaks introduced by refactors that pass PHPCS/PHPStan
 * but silently break the invariant.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\RefreshTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

/**
 * @coversNothing
 */
class RawSecretsNeverLeakTest extends OAuthTestCase {

	public function test_issued_access_token_never_appears_in_wp_options(): void {
		$issued = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $this->factory()->user->create(),
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => wp_generate_uuid4(),
		) );
		$raw_token = (string) $issued['raw'];

		$this->assert_no_hit_in_options( $raw_token );
	}

	public function test_issued_refresh_token_never_appears_in_wp_options(): void {
		$issued = RefreshTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $this->factory()->user->create(),
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => wp_generate_uuid4(),
		) );
		$raw_token = (string) $issued['raw'];

		$this->assert_no_hit_in_options( $raw_token );
	}

	public function test_secrets_vault_never_writes_options_or_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options_before = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options}" )
		);

		// Generate 10 raw tokens through the vault boundary.
		$tokens = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$tokens[] = SecretsVault::random_token();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options_after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options}" )
		);

		$this->assertSame(
			$options_before,
			$options_after,
			'SecretsVault::random_token() must NOT touch wp_options'
		);

		// Ensure the tokens themselves never landed there either.
		foreach ( $tokens as $token ) {
			$this->assert_no_hit_in_options( $token );
		}
	}

	public function test_hash_output_is_deterministic_and_distinct_from_input(): void {
		$raw    = SecretsVault::random_token();
		$hash_1 = SecretsVault::hash( $raw );
		$hash_2 = SecretsVault::hash( $raw );

		$this->assertSame( $hash_1, $hash_2, 'hash() must be deterministic' );
		$this->assertNotSame( $raw, $hash_1, 'hash MUST differ from raw' );
		$this->assertSame( 64, strlen( $hash_1 ), 'hash MUST be 64 hex chars (SHA-256)' );
	}

	public function test_stored_token_row_contains_hash_not_raw(): void {
		global $wpdb;

		$issued = AccessTokenRepository::issue( array(
			'client_id'       => 'test-client',
			'user_id'         => $this->factory()->user->create(),
			'scope'           => 'mcp',
			'resource'        => home_url( '/wp-json/mcp/v1/s' ),
			'token_family_id' => wp_generate_uuid4(),
		) );
		$raw_token = (string) $issued['raw'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT token_hash FROM %i WHERE id = %d',
				$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
				$issued['id']
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertNotSame( $raw_token, $row['token_hash'], 'DB row MUST NOT store raw token' );
		$this->assertSame( SecretsVault::hash( $raw_token ), $row['token_hash'] );
	}

	/**
	 * Assert the given secret string does NOT appear anywhere in wp_options.
	 * Covers transients, autoload options, and cache-backed transients.
	 *
	 * @param string $secret Raw secret to search for.
	 */
	private function assert_no_hit_in_options( string $secret ): void {
		global $wpdb;

		if ( '' === $secret ) {
			$this->fail( 'Cannot assert against empty secret.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( $secret ) . '%'
			)
		);

		$this->assertSame(
			0,
			$hits,
			'Raw secret appeared in wp_options — S3 no-raw-at-rest violated'
		);
	}
}
