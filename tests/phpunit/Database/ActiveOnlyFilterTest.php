<?php
/**
 * Regression test for OAuthToken active_only PHP-side filter (FR-008 / Clarification Q3).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class ActiveOnlyFilterTest extends WP_UnitTestCase {

	/**
	 * Rows: active + expired + revoked → active_only returns only the active row.
	 *
	 * @return void
	 */
	public function test_active_only_returns_only_unrevoked_unexpired(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';
		$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$past   = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$now    = current_time( 'mysql', 1 );

		// (a) active: not revoked, expires in future
		$this->seed_row( $table, str_repeat( 'a', 64 ), $now, $future, null );
		// (b) expired: not revoked, expires in past
		$this->seed_row( $table, str_repeat( 'b', 64 ), $now, $past, null );
		// (c) revoked: revoked, expires in future
		$this->seed_row( $table, str_repeat( 'c', 64 ), $now, $future, $now );

		$active = OAuthTokenQuery::instance()->query(
			array(
				'active_only' => true,
				'number'      => 0,
			)
		);
		$all    = OAuthTokenQuery::instance()->query( array( 'number' => 0 ) );

		$this->assertCount( 1, $active, 'active_only must return exactly one row (a)' );
		$this->assertCount( 3, $all, 'default query returns all three rows' );
	}

	/**
	 * Empty result set → active_only returns array(), not null.
	 *
	 * @return void
	 */
	public function test_active_only_returns_empty_array_not_null(): void {
		$result = OAuthTokenQuery::instance()->query(
			array(
				'active_only' => true,
				'number'      => 0,
			)
		);
		$this->assertIsArray( $result );
	}

	/**
	 * Seed one OAuthToken row with the given metadata.
	 *
	 * @param string      $table   Full table name (with wpdb prefix).
	 * @param string      $hash    Access token hash (char 64).
	 * @param string      $created Created-at datetime (mysql format, GMT).
	 * @param string      $expires Expires-at datetime (mysql format, GMT).
	 * @param string|null $revoked Revoked-at datetime or null.
	 *
	 * @return void
	 */
	private function seed_row( string $table, string $hash, string $created, string $expires, ?string $revoked ): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'access_token_hash'   => $hash,
				'server_id'           => 1,
				'user_id'             => 1,
				'issued_from_code_id' => 0,
				'scope'               => 'mcp',
				'created_at'          => $created,
				'expires_at'          => $expires,
				'revoked_at'          => $revoked,
			)
		);
	}
}
