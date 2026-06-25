<?php
/**
 * Calling cleanup twice in a row MUST succeed; the second call records
 * all-zero counts (no double-delete failure).
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\AuditLog;
use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;
use WP_UnitTestCase;

class CleanupIdempotencyTest extends WP_UnitTestCase {

	public function test_second_cleanup_call_is_a_noop(): void {
		CliAuthLogQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();

		// First sweep (probably zero rows) — should not throw.
		ClaudeConnectors::instance()->handle_cleanup_event();
		// Second sweep — also noop.
		ClaudeConnectors::instance()->handle_cleanup_event();

		// Two cleanup_run audit rows present.
		$runs = ( new OAuthAuditQuery() )->query( array( 'event_type' => AuditLog::EVENT_CLEANUP_RUN ) );
		$this->assertGreaterThanOrEqual( 2, count( $runs ) );

		// Both runs report all-zero counts (assuming no seed data here).
		foreach ( $runs as $row ) {
			$details = json_decode( (string) $row->details_json, true );
			$this->assertSame( 0, (int) ( $details['rows_deleted_codes'] ?? 1 ) );
			$this->assertSame( 0, (int) ( $details['rows_deleted_tokens'] ?? 1 ) );
		}
	}
}
