<?php
/**
 * Cross-server defense — token issued for server A cannot authenticate
 * a request to server B. Audit row failed_cross_server_token.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\AuditLog;
use AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth;
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
use WP_UnitTestCase;

class BearerCrossServerRejectionTest extends WP_UnitTestCase {

	public function test_token_for_server_a_rejected_at_server_b(): void {
		MCPServerQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();

		$server_a = (int) ( new MCPServerQuery() )->add_item(
			array( 'server_name' => 'A', 'server_slug' => 'a', 'is_enabled' => 1, 'server_route' => 'a-route' )
		);
		$server_b = (int) ( new MCPServerQuery() )->add_item(
			array( 'server_name' => 'B', 'server_slug' => 'b', 'is_enabled' => 1, 'server_route' => 'b-route' )
		);
		$user_id = (int) self::factory()->user->create();

		list( $token_a ) = Storage::instance()->issue_access_token( $server_a, $user_id, 0, 'mcp' );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token_a;
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/b-route/tools/list';

		$resolved = BearerAuth::instance()->resolve_bearer_token( 0 );
		$this->assertSame( 0, (int) $resolved, 'Cross-server token MUST NOT authenticate.' );

		$rows = ( new OAuthAuditQuery() )->query(
			array( 'event_type' => AuditLog::EVENT_FAILED_CROSS_SERVER_TOKEN, 'number' => 1 )
		);
		$this->assertNotEmpty( $rows );
	}
}
