<?php
/**
 * Bearer header parsing — REDIRECT_HTTP_AUTHORIZATION fallback, 256-char
 * length guard, case-insensitive prefix.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth;
use AcrossAI_MCP_Manager\Includes\OAuth\Storage;
use WP_UnitTestCase;

class BearerHeaderParsingTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		MCPServerQuery::maybe_create_table();
		OAuthTokenQuery::maybe_create_table();
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	public function test_redirect_http_authorization_fallback(): void {
		$server_id = (int) ( new MCPServerQuery() )->add_item(
			array( 'server_name' => 'S', 'server_slug' => 'redir', 'is_enabled' => 1, 'server_route' => 'redir-route' )
		);
		$user_id = (int) self::factory()->user->create();
		list( $raw_token ) = Storage::instance()->issue_access_token( $server_id, $user_id, 0, 'mcp' );

		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $raw_token;
		$_SERVER['REQUEST_URI']                 = '/wp-json/mcp/redir-route/tools/list';

		$this->assertSame( $user_id, (int) BearerAuth::instance()->resolve_bearer_token( 0 ) );
	}

	public function test_oversized_token_rejected(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat( 'a', 300 );
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/anything/tools/list';
		$this->assertSame( 0, (int) BearerAuth::instance()->resolve_bearer_token( 0 ) );
	}

	public function test_non_bearer_scheme_ignored(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/anything/tools/list';
		$this->assertSame( 0, (int) BearerAuth::instance()->resolve_bearer_token( 0 ) );
	}

	public function test_oauth_endpoint_path_returns_unchanged(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer something';
		$_SERVER['REQUEST_URI']        = '/wp-json/acrossai-mcp/v1/token';
		$this->assertSame( 7, (int) BearerAuth::instance()->resolve_bearer_token( 7 ) );
	}
}
