<?php
/**
 * Feature 017 — AbilityExposureGate SEC-001 regression test.
 *
 * The gate is the closure for the SEC-001 HIGH plan-review finding — it
 * MUST return `WP_Error` 403 when a per-server override says `is_exposed=0`
 * for the tool being invoked, MUST propagate an earlier-priority WP_Error
 * unchanged (never override an F015 deny), and MUST fail-open on
 * unresolvable server context (matches D19 pattern).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\MCP
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;
use AcrossAI_MCP_Manager\Includes\MCP\AbilityExposureGate;
use WP_Error;
use WP_UnitTestCase;

/**
 * Minimal stand-in for the vendor McpServer object — the gate only calls
 * `$server->get_server_id()`, which returns the F011 `server_slug`.
 */
final class FakeMcpServer {
	private string $slug;
	public function __construct( string $slug ) {
		$this->slug = $slug;
	}
	public function get_server_id(): string {
		return $this->slug;
	}
}

/**
 * @coversNothing
 */
class AbilityExposureGateTest extends WP_UnitTestCase {

	private int $server_id      = 0;
	private string $server_slug = '';

	public function set_up(): void {
		parent::set_up();
		MCPServerTable::instance()->maybe_upgrade();
		MCPServerAbilityTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_server_abilities' ) );

		$row = $wpdb->get_row( "SELECT id, server_slug FROM {$wpdb->prefix}acrossai_mcp_servers LIMIT 1" ); // phpcs:ignore
		if ( $row ) {
			$this->server_id   = (int) $row->id;
			$this->server_slug = (string) $row->server_slug;
		}
		ExposureResolver::_reset_cache_for_tests();
	}

	public function test_returns_args_unchanged_when_exposure_is_true(): void {
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', true );
		$gate = AbilityExposureGate::instance();
		$args = array( 'foo' => 'bar' );
		$out  = $gate->gate_tool_call_by_exposure( $args, 'core/get-user-info', null, new FakeMcpServer( $this->server_slug ) );
		$this->assertSame( $args, $out );
	}

	public function test_returns_403_wp_error_when_exposure_is_false(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'wp_get_ability() is not available in this test environment.' );
		}
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', false );
		$gate = AbilityExposureGate::instance();
		$out  = $gate->gate_tool_call_by_exposure( array( 'x' => 1 ), 'core/get-user-info', null, new FakeMcpServer( $this->server_slug ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'acrossai_mcp_ability_not_exposed', $out->get_error_code() );
		$data = $out->get_error_data();
		$this->assertSame( 403, isset( $data['status'] ) ? (int) $data['status'] : 0 );
	}

	public function test_propagates_existing_wp_error_unchanged(): void {
		// Simulate an F015 deny already-returned by an earlier priority-10
		// callback. F017 MUST return it unchanged — never override a deny.
		$incoming = new WP_Error( 'acrossai_mcp_access_denied', 'blocked by F015', array( 'status' => 403 ) );
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', true );
		$gate = AbilityExposureGate::instance();
		$out  = $gate->gate_tool_call_by_exposure( $incoming, 'core/get-user-info', null, new FakeMcpServer( $this->server_slug ) );
		$this->assertSame( $incoming, $out, 'F017 must never override an F015 deny.' );
	}

	public function test_fails_open_when_server_slug_unresolvable(): void {
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', false );
		$gate = AbilityExposureGate::instance();
		$args = array( 'foo' => 'bar' );
		$out  = $gate->gate_tool_call_by_exposure( $args, 'core/get-user-info', null, new FakeMcpServer( 'no-such-server' ) );
		$this->assertSame( $args, $out, 'Missing server slug → fail-open per D19.' );
	}

	public function test_fails_open_when_server_object_lacks_get_server_id(): void {
		MCPServerAbilityQuery::instance()->upsert( $this->server_id, 'core/get-user-info', false );
		$gate = AbilityExposureGate::instance();
		$args = array( 'foo' => 'bar' );
		$out  = $gate->gate_tool_call_by_exposure( $args, 'core/get-user-info', null, new \stdClass() );
		$this->assertSame( $args, $out, 'Unrecognized server object → fail-open per D19.' );
	}
}
