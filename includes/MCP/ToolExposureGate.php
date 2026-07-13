<?php
/**
 * Feature 020 — call-time enforcement gate for per-server tool curation.
 *
 * Wired on the vendor's `mcp_adapter_pre_tool_call` filter at priority 30 —
 * runs LATER than F015 access control (10) and F017 ability exposure (20).
 * Deny-precedence honored: F020 NEVER re-allows an ability that an earlier
 * gate denied. F020 injects its OWN denial when the ability is not in the
 * operator-curated tools set for this server.
 *
 * SEC-020-001 closure. Uses duck-typed feature detection
 * (`method_exists( $server, 'get_server_id' )`) NOT `instanceof` against a
 * specific vendor class — SEC-020-007 anti-regression.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\MCP
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Call-time enforcement gate for per-server tool selections.
 *
 * @since 0.1.0
 */
final class ToolExposureGate {

	/**
	 * Slugs that F020 never gates — the mcp-adapter protocol tools were, at
	 * F020 shipping time, considered non-curatable and always callable.
	 *
	 * VESTIGIAL post-Feature 025 (2026-07-14) — SEC-025-INFO-3:
	 * Under F025's DB-authoritative model, protocol slugs are excluded from
	 * the `tools/list` response when the corresponding `tool_*` column on the
	 * server row is `0`. The adapter refuses `tools/call` on unregistered
	 * tools at the tool-lookup layer regardless of this bypass. This constant
	 * is retained as a belt-and-braces safety net for AI clients that cached
	 * a slug from an earlier session and hit the gate before the adapter's
	 * own lookup rejects the call.
	 *
	 * Do NOT cite this constant as precedent for new bypass rules in future
	 * enforcement gates. The canonical PHP source for the three protocol
	 * slugs is `AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::PROTOCOL_TOOLS`.
	 *
	 * @var string[]
	 */
	public const EXCLUDED_SLUGS = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Per-request memoizer for `get_added_slugs` results, keyed by server_id.
	 *
	 * Bounded by count of servers queried × mean tools per server (typical
	 * install < 200 rows total). Cleared by process end.
	 *
	 * @var array<int, string[]>
	 */
	private static array $cache_by_server_id = array();

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — singleton enforcement (S6).
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * `mcp_adapter_pre_tool_call` callback — 403 on abilities not curated as tools.
	 *
	 * Signature matches the vendor's filter (fired at
	 * `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`):
	 *   apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name, $mcp_tool, $server )
	 *
	 * Callback semantics (see `contracts/enforcement.md`):
	 *   1. Deny-precedence: propagate an earlier-priority WP_Error unchanged.
	 *   2. Duck-typed server resolution + fail-open on unresolvable server_id.
	 *   3. Protocol-tool bypass: `mcp-adapter/*` slugs pass through.
	 *   4. Presence check via per-request memoized `get_added_slugs`.
	 *   5. Absence-deny: return WP_Error with `acrossai_mcp_tool_not_added`.
	 *   6. Presence-allow: return `$args` unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed>|\WP_Error       $args      Tool call args (or a WP_Error
	 *                                                already returned by an earlier
	 *                                                priority callback).
	 * @param string                       $tool_name The MCP tool name (== ability slug).
	 * @param mixed                        $mcp_tool  Vendor McpTool instance (unused).
	 * @param \WP\MCP\Core\McpServer|mixed $server    Vendor McpServer instance.
	 * @return array<mixed>|\WP_Error Original `$args` on allow / fail-open; WP_Error on deny.
	 */
	public function gate_tool_call_by_curation( $args, string $tool_name, $mcp_tool, $server ) {
		unset( $mcp_tool );

		// 1. Deny-precedence — never re-allow an already-denied ability.
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		/*
		 * 2. Duck-typed server resolution + fail-open. Use `method_exists`,
		 * NOT `instanceof \WP\MCP\Server` (wrong name) or
		 * `instanceof \WP\MCP\Core\McpServer` (couples to vendor namespace).
		 * SEC-020-007 anti-regression.
		 */
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return $args;
		}
		$server_slug = (string) $server->get_server_id();
		if ( '' === $server_slug ) {
			return $args;
		}

		// Slug → integer server_id via the F011 MCPServer table.
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);
		if ( empty( $rows ) ) {
			/**
			 * Fires when the gate cannot resolve a server slug to a real
			 * server row. Observability hook (D19 fail-open pattern).
			 *
			 * @since 0.1.0
			 *
			 * @param string $tool_name   Ability slug being called.
			 * @param string $server_slug Server slug that failed to resolve.
			 */
			do_action( 'acrossai_mcp_tool_gate_missing_server', $tool_name, $server_slug );
			return $args;
		}
		$server_id = (int) $rows[0]->id;

		// 3. Protocol-tool bypass — always callable regardless of tools set.
		if ( in_array( $tool_name, self::EXCLUDED_SLUGS, true ) ) {
			return $args;
		}

		// 4. Presence check via per-request cache.
		$added = self::get_added_slugs_cached( $server_id );
		if ( in_array( $tool_name, $added, true ) ) {
			// 6. Presence-allow.
			return $args;
		}

		// 5. Absence-deny.
		return new \WP_Error(
			'acrossai_mcp_tool_not_added',
			__( 'This tool is not enabled on this MCP server.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Memoized `get_added_slugs` — at most one DB read per (server, request).
	 *
	 * @since 0.1.0
	 * @param int $server_id Server id.
	 * @return string[]
	 */
	private static function get_added_slugs_cached( int $server_id ): array {
		if ( ! isset( self::$cache_by_server_id[ $server_id ] ) ) {
			self::$cache_by_server_id[ $server_id ] = MCPServerToolQuery::instance()->get_added_slugs( $server_id );
		}
		return self::$cache_by_server_id[ $server_id ];
	}

	/**
	 * Invalidate the per-request cache for a specific server_id.
	 *
	 * Called by `ToolsController::post_tools()` after a successful
	 * `replace_set()` commit — so a same-request tool call following an
	 * admin save sees fresh state.
	 *
	 * @since 0.1.0
	 * @param int $server_id Server id whose cache to clear.
	 * @return void
	 */
	public static function flush_cache( int $server_id ): void {
		unset( self::$cache_by_server_id[ $server_id ] );
	}
}
