<?php
/**
 * OAuth audit Query smoke tests — append-only writes for every event_type
 * + JSON round-trip on details_json.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\AuditLog;
use WP_UnitTestCase;

class OAuthAuditQueryTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		OAuthAuditQuery::maybe_create_table();
	}

	public function test_every_event_type_persists(): void {
		$q = new OAuthAuditQuery();
		foreach ( $this->all_event_types() as $event_type ) {
			$id = $q->add_item( array( 'event_type' => $event_type, 'client_id' => 'cli', 'server_id' => 1 ) );
			$this->assertGreaterThan( 0, (int) $id, "Failed to persist {$event_type}." );
		}

		$rows = $q->query( array( 'number' => 100 ) );
		$saw  = array_unique( array_map( static fn ( $r ) => $r->event_type, $rows ) );
		foreach ( $this->all_event_types() as $event_type ) {
			$this->assertContains( $event_type, $saw, "Missing {$event_type} in audit table." );
		}
	}

	public function test_details_json_round_trips(): void {
		$q       = new OAuthAuditQuery();
		$payload = array( 'expected_redirect' => 'https://a.example', 'received_redirect' => 'http://a.example' );
		$id      = $q->add_item(
			array(
				'event_type'   => AuditLog::EVENT_FAILED_REDIRECT_MISMATCH,
				'client_id'    => 'client-x',
				'details_json' => (string) wp_json_encode( $payload ),
			)
		);
		$rows = $q->query( array( 'id' => (int) $id, 'number' => 1 ) );
		$this->assertCount( 1, $rows );
		$decoded = json_decode( (string) $rows[0]->details_json, true );
		$this->assertSame( $payload, $decoded );
	}

	public function test_older_than_filter_supports_cleanup(): void {
		$q   = new OAuthAuditQuery();
		$old = $q->add_item( array( 'event_type' => AuditLog::EVENT_CLEANUP_RUN ) );
		// Backdate one row.
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_oauth_audit';
		$wpdb->update( $table, array( 'created_at' => '2000-01-01 00:00:00' ), array( 'id' => $old ) );

		$old_rows = $q->query( array( 'older_than' => '2010-01-01 00:00:00' ) );
		$this->assertNotEmpty( $old_rows );
	}

	/**
	 * @return string[]
	 */
	private function all_event_types(): array {
		return array(
			AuditLog::EVENT_CODE_ISSUED,
			AuditLog::EVENT_CODE_REDEEMED,
			AuditLog::EVENT_CONSENT_DENIED,
			AuditLog::EVENT_FAILED_UNKNOWN_CLIENT,
			AuditLog::EVENT_FAILED_REDIRECT_MISMATCH,
			AuditLog::EVENT_FAILED_REPLAY_ATTEMPT,
			AuditLog::EVENT_FAILED_RATE_LIMIT,
			AuditLog::EVENT_FAILED_CROSS_SERVER_TOKEN,
			AuditLog::EVENT_BEARER_AUTH_SUCCESS,
			AuditLog::EVENT_TOKEN_REVOKED,
			AuditLog::EVENT_CLEANUP_RUN,
		);
	}
}
