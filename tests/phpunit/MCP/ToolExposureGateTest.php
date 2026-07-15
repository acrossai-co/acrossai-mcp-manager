<?php
/**
 * Feature 020 — ToolExposureGate.
 *
 * Guards the EXCLUDED_SLUGS bypass — the three built-in protocol tools MUST
 * bypass F020's presence check regardless of whether the AI client sent the
 * raw ability form (`mcp-adapter/discover-abilities`, with slash) or the
 * vendor-sanitized form (`mcp-adapter-discover-abilities`, with hyphen).
 * The sanitized form is what actually arrives at `mcp_adapter_pre_tool_call`
 * time (`McpNameSanitizer::sanitize_name` swaps `/` → `-` when the ability
 * is registered as an MCP tool).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\MCP
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

/**
 * Duck-typed McpServer stand-in — the gate only calls `get_server_id()`.
 */
final class FakeMcpServerForToolGate {
	public function __construct( private string $slug ) {}
	public function get_server_id(): string {
		return $this->slug;
	}
}

class ToolExposureGateTest extends WP_UnitTestCase {

	private string $server_slug = '';

	public function set_up(): void {
		parent::set_up();
		MCPServerTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT server_slug FROM {$wpdb->prefix}acrossai_mcp_servers LIMIT 1" );
		if ( $row ) {
			$this->server_slug = (string) $row->server_slug;
		}
	}

	// -----------------------------------------------------------------
	// EXCLUDED_SLUGS bypass — must match BOTH raw and sanitized forms.
	// -----------------------------------------------------------------

	public function test_bypass_matches_raw_ability_slug_form(): void {
		$args = array( 'p' => 'q' );
		$out  = ToolExposureGate::instance()->gate_tool_call_by_curation(
			$args,
			'mcp-adapter/discover-abilities', // raw form
			null,
			new FakeMcpServerForToolGate( $this->server_slug )
		);
		$this->assertSame( $args, $out );
	}

	public function test_bypass_matches_vendor_sanitized_form(): void {
		// This case pins the fix. Before the EXCLUDED_SLUGS expansion, the
		// vendor's sanitizer swapped `/` → `-` in the tool name at
		// registration time, so `$tool_name` at gate time is the hyphen
		// form and the raw-form-only constant never matched — F020 denied
		// with 'This tool is not enabled on this MCP server.'
		$args = array( 'p' => 'q' );
		$out  = ToolExposureGate::instance()->gate_tool_call_by_curation(
			$args,
			'mcp-adapter-discover-abilities', // sanitized form
			null,
			new FakeMcpServerForToolGate( $this->server_slug )
		);
		$this->assertSame(
			$args,
			$out,
			'EXCLUDED_SLUGS bypass must match the vendor-sanitized tool name that actually arrives at gate time.'
		);
	}

	public function test_bypass_matches_sanitized_get_ability_info(): void {
		$args = array( 'p' => 'q' );
		$out  = ToolExposureGate::instance()->gate_tool_call_by_curation(
			$args,
			'mcp-adapter-get-ability-info',
			null,
			new FakeMcpServerForToolGate( $this->server_slug )
		);
		$this->assertSame( $args, $out );
	}

	public function test_bypass_matches_sanitized_execute_ability(): void {
		$args = array( 'p' => 'q' );
		$out  = ToolExposureGate::instance()->gate_tool_call_by_curation(
			$args,
			'mcp-adapter-execute-ability',
			null,
			new FakeMcpServerForToolGate( $this->server_slug )
		);
		$this->assertSame( $args, $out );
	}

	// -----------------------------------------------------------------
	// Non-bypassed slugs still gated
	// -----------------------------------------------------------------

	public function test_non_protocol_slug_not_in_curated_returns_wp_error(): void {
		$out = ToolExposureGate::instance()->gate_tool_call_by_curation(
			array( 'x' => 'y' ),
			'some-plugin/not-added',
			null,
			new FakeMcpServerForToolGate( $this->server_slug )
		);
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'acrossai_mcp_tool_not_added', $out->get_error_code() );
	}

	public function test_propagates_earlier_wp_error_unchanged(): void {
		$err = new \WP_Error( 'earlier_deny', 'nope' );
		$out = ToolExposureGate::instance()->gate_tool_call_by_curation(
			$err,
			'mcp-adapter-discover-abilities',
			null,
			new FakeMcpServerForToolGate( $this->server_slug )
		);
		$this->assertSame( $err, $out );
	}
}
