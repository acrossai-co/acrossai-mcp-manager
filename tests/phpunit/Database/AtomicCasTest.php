<?php
/**
 * SEC-001 atomic-CAS regression test for the CliAuthLog redeem_atomic method.
 *
 * Feature 011 preserves the check-then-act guarantee per FR-006 + BUGS.md B10.
 * The WHERE id = %d AND completed_at IS NULL predicate is the sole defense
 * against concurrent duplicate redemption of a one-shot CLI auth code.
 *
 * A regression here (e.g., dropping the IS NULL guard) would allow two
 * concurrent HTTP hits to both redeem the same auth code — a critical bug.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class AtomicCasTest extends WP_UnitTestCase {

	/**
	 * (A) Seed one row with completed_at NULL; redeem_atomic returns true and completed_at becomes non-NULL.
	 */
	public function test_redeem_atomic_first_call_succeeds(): void {
		global $wpdb;

		$id  = $this->seed_row_null_completed();
		$now = current_time( 'mysql', 1 );

		$result = CliAuthLogQuery::instance()->redeem_atomic( $id, $now );

		$this->assertTrue( $result, 'redeem_atomic must return true on first successful redemption' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT completed_at FROM %i WHERE id = %d',
				$wpdb->prefix . 'acrossai_mcp_cli_auth_logs',
				$id
			)
		);

		$this->assertNotNull( $row );
		$this->assertNotNull( $row->completed_at, 'completed_at must be stamped after redeem_atomic' );
	}

	/**
	 * (B) Second call on the same row returns falsy (idempotent no-op).
	 */
	public function test_redeem_atomic_second_call_is_no_op(): void {
		$id  = $this->seed_row_null_completed();
		$now = current_time( 'mysql', 1 );

		$first  = CliAuthLogQuery::instance()->redeem_atomic( $id, $now );
		$second = CliAuthLogQuery::instance()->redeem_atomic( $id, $now );

		$this->assertTrue( $first, 'first call must succeed' );
		$this->assertFalse( $second, 'second call on already-completed row must return false' );
	}

	/**
	 * (C) SQL predicate assertion via $wpdb->last_query — the AND completed_at IS NULL clause is the atomic-CAS guarantee.
	 */
	public function test_redeem_atomic_sql_predicate_contains_is_null_clause(): void {
		global $wpdb;

		$id = $this->seed_row_null_completed();
		CliAuthLogQuery::instance()->redeem_atomic( $id, current_time( 'mysql', 1 ) );

		$this->assertNotEmpty( $wpdb->last_query );
		$this->assertMatchesRegularExpression(
			'#UPDATE.+SET completed_at.+WHERE id = \d+ AND completed_at IS NULL#i',
			$wpdb->last_query,
			'The AND completed_at IS NULL clause is the SEC-001 atomic-CAS guarantee (BUGS.md B10) — regression forbidden.'
		);
	}

	/**
	 * Seed one CliAuthLog row with completed_at NULL.
	 *
	 * @return int Auth-log row primary key.
	 */
	private function seed_row_null_completed(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'acrossai_mcp_cli_auth_logs',
			array(
				'server_id'             => 1,
				'server_slug'           => 'test-server',
				'user_id'               => 1,
				'status'                => 'pending',
				'auth_code_hash'        => str_repeat( 'a', 64 ),
				'redirect_uri'          => 'https://example.test/',
				'code_challenge'        => str_repeat( 'b', 43 ),
				'code_challenge_method' => 'S256',
				'scope'                 => 'mcp',
				'created_at'            => current_time( 'mysql', 1 ),
			)
		);

		return (int) $wpdb->insert_id;
	}
}
