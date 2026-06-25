<?php
/**
 * If $user_id is already truthy (cookie auth, App Password auth), the
 * Bearer filter MUST NOT override.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth;
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
use WP_UnitTestCase;

class BearerNoOverrideTest extends WP_UnitTestCase {

	public function test_existing_user_id_is_preserved(): void {
		MCPServerQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();

		$server_id = (int) ( new MCPServerQuery() )->add_item(
			array( 'server_name' => 'S', 'server_slug' => 'sno', 'is_enabled' => 1, 'server_route' => 'sno-route' )
		);
		$bearer_user = (int) self::factory()->user->create();
		$cookie_user = (int) self::factory()->user->create();

		list( $raw_token ) = Storage::instance()->issue_access_token( $server_id, $bearer_user, 0, 'mcp' );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw_token;
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/sno-route/tools/list';

		$resolved = BearerAuth::instance()->resolve_bearer_token( $cookie_user );
		$this->assertSame( $cookie_user, (int) $resolved, 'Existing $user_id MUST win — Bearer filter does not override.' );
	}
}
