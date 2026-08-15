<?php
/**
 * Utilities\MCPServerFieldSanitizer — WP-bootstrapped tests.
 *
 * Feature 069 (T013 / TASK-SEC-001 + REF-001) — parity test proving the
 * shared sanitizer produces identical output for a broad fixture set,
 * including the mass-assignment negative case (memory pattern B7) that
 * verifies forged POST keys are silently dropped by the whitelist filter.
 *
 * @package AcrossAI_MCP_Manager\Tests\Utilities
 */

namespace AcrossAI_MCP_Manager\Tests\Utilities;

use AcrossAI_MCP_Manager\Includes\Utilities\MCPServerFieldSanitizer;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

final class MCPServerFieldSanitizerTest extends WP_UnitTestCase {

	// ─────────────────────────────────────────────────────────────────────
	// Whitelist behaviour (REF-001 / SEC-T-001 primary defence)
	// ─────────────────────────────────────────────────────────────────────

	public function test_whitelist_drops_all_forged_keys_mass_assignment_negative_case(): void {
		// The critical B7 negative case: forged keys MUST NEVER reach output.
		$input = array(
			'server_name' => 'Legit Server',
			'is_enabled'  => 1,       // Forged — must be dropped.
			'id'          => 999,     // Forged — must be dropped.
			'created_at'  => '2020-01-01 00:00:00', // Forged — must be dropped.
			'claude_connector_client_secret' => 'plaintext-secret-attempt', // Forged — must be dropped.
		);

		$out = MCPServerFieldSanitizer::sanitize( $input );

		$this->assertArrayNotHasKey( 'is_enabled', $out, 'is_enabled MUST NOT appear in output' );
		$this->assertArrayNotHasKey( 'id', $out, 'id MUST NOT appear in output' );
		$this->assertArrayNotHasKey( 'created_at', $out, 'created_at MUST NOT appear in output' );
		$this->assertArrayNotHasKey( 'claude_connector_client_secret', $out, 'secret column MUST NOT appear in output' );

		$this->assertSame( 'Legit Server', $out['server_name'] );
		// slug auto-derived from name.
		$this->assertSame( 'legit-server', $out['server_slug'] );
	}

	public function test_output_always_contains_exactly_the_six_whitelisted_keys(): void {
		$out = MCPServerFieldSanitizer::sanitize( array() );

		$this->assertSame(
			array(
				'server_name',
				'server_slug',
				'description',
				'server_route_namespace',
				'server_route',
				'server_version',
			),
			array_keys( $out ),
			'Output MUST always contain exactly the 6 whitelisted keys in order.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Per-field sanitization (parity with Settings.php:264-268 behaviour)
	// ─────────────────────────────────────────────────────────────────────

	public function test_server_name_strips_html_tags_and_trims(): void {
		$out = MCPServerFieldSanitizer::sanitize( array( 'server_name' => "  <b>My</b> Server  " ) );
		$this->assertSame( 'My Server', $out['server_name'] );
	}

	public function test_server_name_handles_unicode_intact(): void {
		$out = MCPServerFieldSanitizer::sanitize( array( 'server_name' => 'サーバー ✨' ) );
		$this->assertSame( 'サーバー ✨', $out['server_name'] );
	}

	public function test_server_slug_derived_from_name_when_missing(): void {
		$out = MCPServerFieldSanitizer::sanitize( array( 'server_name' => 'My Server' ) );
		$this->assertSame( 'my-server', $out['server_slug'] );
	}

	public function test_server_slug_sanitized_when_provided(): void {
		$out = MCPServerFieldSanitizer::sanitize(
			array( 'server_name' => 'foo', 'server_slug' => 'MY_Custom Slug' )
		);
		$this->assertSame( 'my_custom-slug', $out['server_slug'] );
	}

	public function test_description_uses_textarea_field_preserving_newlines(): void {
		$out = MCPServerFieldSanitizer::sanitize(
			array( 'server_name' => 'x', 'description' => "line 1\nline 2\n<script>alert(1)</script>" )
		);
		$this->assertStringContainsString( "line 1\nline 2", $out['description'] );
		$this->assertStringNotContainsString( '<script>', $out['description'] );
	}

	public function test_route_namespace_defaults_to_mcp_when_missing(): void {
		$out = MCPServerFieldSanitizer::sanitize( array( 'server_name' => 'x' ) );
		$this->assertSame( 'mcp', $out['server_route_namespace'] );
	}

	public function test_route_defaults_to_slug_when_missing(): void {
		$out = MCPServerFieldSanitizer::sanitize( array( 'server_name' => 'My Server' ) );
		$this->assertSame( 'my-server', $out['server_route'] );
	}

	public function test_version_defaults_to_v1_0_0_when_missing(): void {
		$out = MCPServerFieldSanitizer::sanitize( array( 'server_name' => 'x' ) );
		$this->assertSame( 'v1.0.0', $out['server_version'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Edge cases (12-input fixture set from spec)
	// ─────────────────────────────────────────────────────────────────────

	public function test_sql_injection_attempt_in_name_survives_as_escaped_text(): void {
		$out = MCPServerFieldSanitizer::sanitize(
			array( 'server_name' => "Robert'); DROP TABLE users;--" )
		);
		// sanitize_text_field does NOT strip apostrophes — it just kills tags/nulls.
		// $wpdb->prepare downstream handles SQL escaping.
		$this->assertStringContainsString( 'Robert', $out['server_name'] );
	}

	public function test_null_byte_stripped_from_name(): void {
		$out = MCPServerFieldSanitizer::sanitize(
			array( 'server_name' => "foo\0bar" )
		);
		$this->assertStringNotContainsString( "\0", $out['server_name'] );
	}

	public function test_extremely_long_name_not_truncated_by_sanitizer(): void {
		// sanitize_text_field does not enforce length — that's schema/DB layer.
		$long = str_repeat( 'a', 5000 );
		$out  = MCPServerFieldSanitizer::sanitize( array( 'server_name' => $long ) );
		$this->assertSame( 5000, strlen( $out['server_name'] ) );
	}

	public function test_empty_input_produces_all_default_output(): void {
		$out = MCPServerFieldSanitizer::sanitize( array() );
		$this->assertSame(
			array(
				'server_name'            => '',
				'server_slug'            => '',
				'description'            => '',
				'server_route_namespace' => 'mcp',
				'server_route'           => '',
				'server_version'         => 'v1.0.0',
			),
			$out
		);
	}

	public function test_whitespace_only_name_survives_as_empty(): void {
		$out = MCPServerFieldSanitizer::sanitize( array( 'server_name' => '   ' ) );
		$this->assertSame( '', $out['server_name'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// sanitize_from_post — $_POST-source wrapper (unslash pathway)
	// ─────────────────────────────────────────────────────────────────────

	public function test_sanitize_from_post_unslashes_before_sanitizing(): void {
		// Simulate WP-slashed input.
		$post = array(
			'server_name' => "O\\'Brien Server",
		);
		$out = MCPServerFieldSanitizer::sanitize_from_post( $post );
		$this->assertSame( "O'Brien Server", $out['server_name'] );
	}

	public function test_sanitize_from_post_also_drops_forged_keys(): void {
		$post = array(
			'server_name' => 'foo',
			'is_enabled'  => '1',
			'id'          => '999',
		);
		$out = MCPServerFieldSanitizer::sanitize_from_post( $post );
		$this->assertArrayNotHasKey( 'is_enabled', $out );
		$this->assertArrayNotHasKey( 'id', $out );
	}
}
