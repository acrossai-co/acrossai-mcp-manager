<?php
/**
 * US1 — GET /health endpoint coverage.
 *
 * @package AcrossAI_MCP_Manager\Tests\RestCli
 */

namespace AcrossAI_MCP_Manager\Tests\RestCli;

use AcrossAI_MCP_Manager\Includes\REST\CliController;
use WP_REST_Request;
use WP_UnitTestCase;

class HealthEndpointTest extends WP_UnitTestCase {

	public function test_returns_200_with_expected_shape(): void {
		$req  = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/health' );
		$resp = CliController::instance()->handle_health( $req );
		$data = $resp->get_data();

		$this->assertSame( 200, $resp->get_status() );
		$this->assertTrue( $data['plugin_installed'] );
		$this->assertTrue( $data['plugin_active'] );
		$this->assertIsString( $data['version'] );
		$this->assertIsString( $data['site_slug'] );
	}

	public function test_site_slug_uses_sanitize_title(): void {
		update_option( 'blogname', 'Example Site — Production' );

		$req  = new WP_REST_Request( 'GET', '/' . CliController::REST_NAMESPACE . '/health' );
		$resp = CliController::instance()->handle_health( $req );
		$data = $resp->get_data();

		$this->assertSame( 'example-site-production', $data['site_slug'] );
	}
}
