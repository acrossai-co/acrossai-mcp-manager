<?php
/**
 * Bearer auth — happy path: token for (server, user) authenticates a
 * request hitting that server's route. Audit row recorded.
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

class BearerAuthSuccessTest extends WP_UnitTestCase {

	public function test_valid_token_for_target_server_resolves_user(): void {
		MCPServerQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
		OAuthAuditQuery::maybe_create_table();

		$server_id = (int) ( new MCPServerQuery() )->add_item(
			array(
				'server_name'  => 'WPDS',
				'server_slug'  => 'wpds',
				'is_enabled'   => 1,
				'server_route' => 'wpds-server',
			)
		);
		$user_id = (int) self::factory()->user->create();

		list( $raw_token ) = Storage::instance()->issue_access_token( $server_id, $user_id, 0, 'mcp' );
		$this->assertNotSame( '', $raw_token );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw_token;
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/wpds-server/tools/list';

		$resolved = BearerAuth::instance()->resolve_bearer_token( 0 );
		$this->assertSame( $user_id, (int) $resolved );

		$rows = ( new OAuthAuditQuery() )->query(
			array( 'event_type' => AuditLog::EVENT_BEARER_AUTH_SUCCESS, 'number' => 1 )
		);
		$this->assertNotEmpty( $rows );
		$this->assertSame( $server_id, $rows[0]->server_id );
	}
}
