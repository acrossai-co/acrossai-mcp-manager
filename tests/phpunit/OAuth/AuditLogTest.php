<?php
/**
 * AuditLog: every event_type maps to a persisted row + token_hash_prefix
 * is truncated to 8 chars.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\AuditLog;
use WP_UnitTestCase;

class AuditLogTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		OAuthAuditQuery::maybe_create_table();
	}

	public function test_write_persists_event_type(): void {
		AuditLog::instance()->write(
			AuditLog::EVENT_CODE_ISSUED,
			array( 'client_id' => 'cli-x', 'server_id' => 1, 'user_id' => 42 )
		);

		$rows = ( new OAuthAuditQuery() )->query(
			array( 'event_type' => AuditLog::EVENT_CODE_ISSUED, 'number' => 1 )
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'cli-x', $rows[0]->client_id );
		$this->assertSame( 1, $rows[0]->server_id );
		$this->assertSame( 42, $rows[0]->user_id );
	}

	public function test_token_hash_prefix_truncated_to_eight(): void {
		$full_hash = hash( 'sha256', 'token-xyz' );
		AuditLog::instance()->write(
			AuditLog::EVENT_BEARER_AUTH_SUCCESS,
			array( 'token_hash_prefix' => $full_hash )
		);

		$rows = ( new OAuthAuditQuery() )->query(
			array( 'event_type' => AuditLog::EVENT_BEARER_AUTH_SUCCESS, 'number' => 1 )
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( substr( $full_hash, 0, 8 ), $rows[0]->token_hash_prefix );
	}

	public function test_details_json_encoded(): void {
		AuditLog::instance()->write(
			AuditLog::EVENT_CLEANUP_RUN,
			array( 'details' => array( 'rows_deleted_codes' => 3, 'rows_deleted_tokens' => 7 ) )
		);

		$rows = ( new OAuthAuditQuery() )->query(
			array( 'event_type' => AuditLog::EVENT_CLEANUP_RUN, 'number' => 1 )
		);
		$decoded = json_decode( (string) $rows[0]->details_json, true );
		$this->assertSame( 3, $decoded['rows_deleted_codes'] );
		$this->assertSame( 7, $decoded['rows_deleted_tokens'] );
	}
}
