<?php
/**
 * B10 CAS single-use pattern regression test for auth codes.
 *
 * The UPDATE ... WHERE used=0 AND expires_at > %s predicate is the sole
 * defense against replay of an authorization code. Mirrors the F011
 * CliAuthLog AtomicCasTest.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Query as AuthCodesQuery;

/**
 * @coversNothing
 */
class AuthCodeConsumeAtomicTest extends OAuthTestCase {

	public function test_first_consume_returns_row_and_flips_used(): void {
		global $wpdb;

		$hash = hash( 'sha256', 'test-code-happy-path' );
		$this->seed_code( $hash, gmdate( 'Y-m-d H:i:s', time() + 600 ) );

		$row = AuthCodesQuery::instance()->consume_atomic( $hash, gmdate( 'Y-m-d H:i:s' ) );

		$this->assertNotNull( $row, 'consume_atomic must return the row on first call' );
		$this->assertSame( 1, (int) $row->used );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$used_db = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT used FROM %i WHERE code_hash = %s',
				$wpdb->prefix . 'acrossai_mcp_oauth_auth_codes',
				$hash
			)
		);
		$this->assertSame( 1, $used_db );
	}

	public function test_second_consume_returns_null(): void {
		$hash = hash( 'sha256', 'test-code-replay' );
		$this->seed_code( $hash, gmdate( 'Y-m-d H:i:s', time() + 600 ) );

		$first  = AuthCodesQuery::instance()->consume_atomic( $hash, gmdate( 'Y-m-d H:i:s' ) );
		$second = AuthCodesQuery::instance()->consume_atomic( $hash, gmdate( 'Y-m-d H:i:s' ) );

		$this->assertNotNull( $first, 'first call must succeed' );
		$this->assertNull( $second, 'B10 CAS — second call MUST return null' );
	}

	public function test_expired_code_returns_null(): void {
		$hash = hash( 'sha256', 'test-code-expired' );
		$this->seed_code( $hash, gmdate( 'Y-m-d H:i:s', time() - 60 ) );

		$row = AuthCodesQuery::instance()->consume_atomic( $hash, gmdate( 'Y-m-d H:i:s' ) );

		$this->assertNull( $row, 'expired code MUST NOT be consumable' );
	}

	private function seed_code( string $hash, string $expires_at ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_oauth_auth_codes',
			array(
				'code_hash'             => $hash,
				'client_id'             => 'test-client',
				'user_id'               => 1,
				'redirect_uri'          => 'https://client.example.com/callback',
				'code_challenge'        => str_repeat( 'a', 43 ),
				'code_challenge_method' => 'S256',
				'scope'                 => 'mcp',
				'resource'              => 'https://this-site.example.com/wp-json/mcp/v1/server-1',
				'used'                  => 0,
				'expires_at'            => $expires_at,
				'created_at'            => current_time( 'mysql', 1 ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}
