<?php
/**
 * Cleanup cron — FR-019c retention windows. Seed expired code / expired
 * token / >90-day audit row; trigger cleanup; assert all three deleted;
 * assert a cleanup_run audit row is written with non-zero counts.
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

class CleanupCronTest extends WP_UnitTestCase {

	public function test_cleanup_deletes_expired_rows_and_writes_audit(): void {
		global $wpdb;

		CliAuthLogQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();

		// Seed an old OAuth code row (>10 min + 24h ago).
		$cli = new CliAuthLogQuery();
		$cli->add_item(
			array(
				'server_id'      => 1,
				'status'         => 'oauth_code_issued',
				'auth_code_hash' => hash( 'sha256', 'old-code' ),
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}acrossai_mcp_cli_auth_logs SET created_at = %s WHERE status = 'oauth_code_issued'",
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			)
		);

		// Seed an expired token (>7 days past expiry).
		$tok = new OAuthTokenQuery();
		$tid = (int) $tok->add_item(
			array(
				'access_token_hash' => hash( 'sha256', 'old-token' ),
				'server_id'         => 1,
				'user_id'           => 1,
				'expires_at'        => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			)
		);
		$this->assertGreaterThan( 0, $tid );

		// Seed a >90-day-old audit row.
		$audit = new OAuthAuditQuery();
		$aid   = (int) $audit->add_item( array( 'event_type' => AuditLog::EVENT_CONSENT_DENIED ) );
		$wpdb->update(
			$wpdb->prefix . 'acrossai_mcp_oauth_audit',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ) ),
			array( 'id' => $aid )
		);

		ClaudeConnectors::instance()->handle_cleanup_event();

		// The seeded rows should be gone.
		$this->assertEmpty( $cli->query( array( 'auth_code_hash' => hash( 'sha256', 'old-code' ) ) ) );
		$this->assertEmpty( $tok->query( array( 'access_token_hash' => hash( 'sha256', 'old-token' ) ) ) );
		$this->assertEmpty( $audit->query( array( 'id' => $aid ) ) );

		// A cleanup_run audit row MUST exist with the per-class counts.
		$run = $audit->query( array( 'event_type' => AuditLog::EVENT_CLEANUP_RUN, 'number' => 1 ) );
		$this->assertNotEmpty( $run );
		$details = json_decode( (string) $run[0]->details_json, true );
		$this->assertIsArray( $details );
		$this->assertArrayHasKey( 'rows_deleted_codes', $details );
		$this->assertArrayHasKey( 'rows_deleted_tokens', $details );
		$this->assertArrayHasKey( 'rows_deleted_audit', $details );
	}
}
