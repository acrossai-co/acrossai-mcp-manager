<?php
/**
 * US2 / SC-005 — RFC 7591 idempotent dedup.
 *
 * Byte-identical repeat POST returns the previously-issued client_id with
 * NO new row inserted, NO secret returned, NO token_issued action fires.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController;

/**
 * @coversNothing
 */
class DCRDedupTest extends OAuthTestCase {

	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
		parent::set_up();
		ClientRegistrationController::instance()->register_routes();
	}

	public function test_identical_body_returns_same_client_id_no_new_row(): void {
		global $wpdb;

		$body = array(
			'redirect_uris'              => array( 'https://client.example.com/callback' ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'client_secret_post',
			'client_name'                => 'Idempotent Client',
		);

		$first  = $this->dispatch( $body );
		$this->assertSame( 201, $first->get_status() );
		$first_client_id = (string) $first->get_data()['client_id'];

		$issued_captured = $this->capture_action( 'acrossai_mcp_manager_oauth_token_issued' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_count_before = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'acrossai_mcp_oauth_clients' )
		);

		// Second POST with byte-identical body.
		$second = $this->dispatch( $body );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first_client_id, (string) $second->get_data()['client_id'] );
		$this->assertArrayNotHasKey( 'client_secret', $second->get_data() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_count_after = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'acrossai_mcp_oauth_clients' )
		);
		$this->assertSame( $row_count_before, $row_count_after, 'DCR dedup violated: new row inserted on identical body' );

		// SC-005 — token_issued MUST NOT fire on dedup.
		$this->assertCount( 0, $issued_captured['calls'] );
	}

	public function test_field_order_does_not_affect_fingerprint(): void {
		// Same metadata expressed with fields in different order + array element order.
		$body_a = array(
			'redirect_uris'              => array( 'https://a.example.com/cb', 'https://b.example.com/cb' ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'client_secret_post',
		);

		$body_b = array(
			'token_endpoint_auth_method' => 'client_secret_post',
			'response_types'             => array( 'code' ),
			'grant_types'                => array( 'refresh_token', 'authorization_code' ), // reversed
			'redirect_uris'              => array( 'https://b.example.com/cb', 'https://a.example.com/cb' ), // reversed
		);

		$first  = $this->dispatch( $body_a );
		$second = $this->dispatch( $body_b );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 200, $second->get_status(), 'Fingerprint should be canonical — reordered fields must dedup' );
		$this->assertSame(
			(string) $first->get_data()['client_id'],
			(string) $second->get_data()['client_id']
		);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/oauth/register' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}
}
