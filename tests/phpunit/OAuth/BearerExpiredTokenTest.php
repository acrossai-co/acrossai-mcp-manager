<?php
/**
 * Expired token → anonymous + NO audit row (no oracle).
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth;
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
use WP_UnitTestCase;

class BearerExpiredTokenTest extends WP_UnitTestCase {

	public function test_expired_token_does_not_resolve_and_writes_no_audit(): void {
		MCPServerQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();

		$server_id = (int) ( new MCPServerQuery() )->add_item(
			array( 'server_name' => 'S', 'server_slug' => 'sx', 'is_enabled' => 1, 'server_route' => 'sx-route' )
		);
		$user_id = (int) self::factory()->user->create();

		list( $raw_token ) = Storage::instance()->issue_access_token( $server_id, $user_id, 0, 'mcp' );

		// Force-expire the token row.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			array( 'expires_at' => '2000-01-01 00:00:00' ),
			array( 'server_id' => $server_id )
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw_token;
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/sx-route/tools/list';

		$rows_before = ( new OAuthAuditQuery() )->query( array() );
		$count_before = count( $rows_before );

		$resolved = BearerAuth::instance()->resolve_bearer_token( 0 );
		$this->assertSame( 0, (int) $resolved );

		$rows_after = ( new OAuthAuditQuery() )->query( array() );
		$this->assertSame( $count_before, count( $rows_after ), 'Expired token MUST NOT create an audit row (no oracle).' );
	}
}
