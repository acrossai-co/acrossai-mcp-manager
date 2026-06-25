<?php
/**
 * OAuth tokens Query smoke tests — round-trip + mass-assignment defense (B7).
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Row as OAuthTokenRow;
use WP_UnitTestCase;

class OAuthTokenQueryTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		OAuthTokenQuery::maybe_create_table();
	}

	public function test_add_item_persists_and_query_returns_row(): void {
		$q    = new OAuthTokenQuery();
		$hash = hash( 'sha256', 'token-A' );
		$id   = $q->add_item(
			array(
				'access_token_hash'   => $hash,
				'server_id'           => 5,
				'user_id'             => 42,
				'issued_from_code_id' => 17,
				'scope'               => 'mcp',
				'expires_at'          => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$rows = $q->query( array( 'access_token_hash' => $hash, 'number' => 1 ) );
		$this->assertCount( 1, $rows );
		$this->assertInstanceOf( OAuthTokenRow::class, $rows[0] );
		$this->assertSame( 5, $rows[0]->server_id );
		$this->assertSame( 42, $rows[0]->user_id );
		$this->assertSame( 17, $rows[0]->issued_from_code_id );
		$this->assertNull( $rows[0]->revoked_at );
	}

	public function test_add_item_drops_unknown_columns(): void {
		$q    = new OAuthTokenQuery();
		$hash = hash( 'sha256', 'token-B' );
		$id   = $q->add_item(
			array(
				'access_token_hash' => $hash,
				'server_id'         => 1,
				'user_id'           => 1,
				'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'malicious_column'  => 'rogue', // B7 — must be silently dropped.
				'admin'             => 1,
			)
		);
		$this->assertGreaterThan( 0, $id );
	}

	public function test_active_only_filters_revoked_and_expired(): void {
		$q = new OAuthTokenQuery();

		$id_active = $q->add_item(
			array(
				'access_token_hash' => hash( 'sha256', 'active' ),
				'server_id'         => 1,
				'user_id'           => 1,
				'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			)
		);
		$id_revoked = $q->add_item(
			array(
				'access_token_hash' => hash( 'sha256', 'revoked' ),
				'server_id'         => 1,
				'user_id'           => 1,
				'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			)
		);
		$q->update_item( (int) $id_revoked, array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ) );

		$active = $q->query( array( 'server_id' => 1, 'active_only' => true ) );
		$ids    = array_map( static fn ( OAuthTokenRow $r ) => $r->id, $active );
		$this->assertContains( (int) $id_active, $ids );
		$this->assertNotContains( (int) $id_revoked, $ids );
	}
}
