<?php
/**
 * Discovery endpoint conformance — RFC 8414 + RFC 9728 + FR-001/FR-002.
 *
 * @package AcrossAI_MCP_Manager\Tests\OAuth
 */

namespace AcrossAI_MCP_Manager\Tests\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;
use WP_UnitTestCase;

class ClaudeConnectorsDiscoveryTest extends WP_UnitTestCase {

	public function test_as_metadata_matches_golden_fixture(): void {
		$payload = $this->capture_payload( 'serve_as_metadata' );

		$expected = $this->load_fixture( 'discovery-as.json' );
		$this->assertSame( $expected['issuer'], $payload['issuer'] );
		$this->assertSame( $expected['authorization_endpoint'], $payload['authorization_endpoint'] );
		$this->assertSame( $expected['token_endpoint'], $payload['token_endpoint'] );
		$this->assertSame( $expected['response_types_supported'], $payload['response_types_supported'] );
		$this->assertSame( $expected['grant_types_supported'], $payload['grant_types_supported'] );
		$this->assertSame( $expected['code_challenge_methods_supported'], $payload['code_challenge_methods_supported'] );
		$this->assertSame( $expected['token_endpoint_auth_methods_supported'], $payload['token_endpoint_auth_methods_supported'] );
		$this->assertSame( $expected['scopes_supported'], $payload['scopes_supported'] );
	}

	public function test_rs_metadata_matches_golden_fixture(): void {
		$payload = $this->capture_payload( 'serve_rs_metadata' );

		$expected = $this->load_fixture( 'discovery-rs.json' );
		$this->assertSame( $expected['resource'], $payload['resource'] );
		$this->assertSame( $expected['authorization_servers'], $payload['authorization_servers'] );
		$this->assertSame( $expected['bearer_methods_supported'], $payload['bearer_methods_supported'] );
	}

	/**
	 * Invoke the handler with output buffering and a JSON decode.
	 * The handler calls wp_send_json + exit; intercept via shutdown_handler.
	 *
	 * @return array<string, mixed>
	 */
	private function capture_payload( string $method ): array {
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message, $title, $args ) {
					throw new \RuntimeException( (string) $message );
				};
			}
		);

		$captured = '';
		ob_start();
		try {
			ClaudeConnectors::instance()->{$method}();
		} catch ( \Throwable $e ) {
			// wp_send_json calls exit; we cannot intercept it easily in PHPUnit.
			// Instead, decode whatever was buffered.
		}
		$captured = (string) ob_get_clean();

		$decoded = json_decode( $captured, true );
		$this->assertIsArray( $decoded, 'discovery handler must emit JSON: got ' . $captured );
		return $decoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load_fixture( string $name ): array {
		$raw = (string) file_get_contents( __DIR__ . '/fixtures/' . $name );
		$issuer   = home_url();
		$resource = untrailingslashit( rest_url( 'mcp' ) );
		$raw = str_replace( '{ISSUER}', $issuer, $raw );
		$raw = str_replace( '{RESOURCE}', $resource, $raw );
		return (array) json_decode( $raw, true );
	}
}
