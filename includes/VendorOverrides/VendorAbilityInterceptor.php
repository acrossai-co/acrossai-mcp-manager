<?php
/**
 * Vendor override for the three mcp-adapter default abilities — per-server visibility.
 *
 * Intercepts the vendor package's built-in `mcp-adapter/discover-abilities`,
 * `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` at call time
 * to apply per-server visibility semantics (F017 storage overlaid on top of the
 * ability's `meta.mcp.public` flag, filtered by `mcp.type = 'tool'`).
 *
 * Uses TWO existing vendor filter hooks — no ability unregister, no ability
 * re-registration, no vendor class copies:
 *
 *   - `mcp_adapter_pre_tool_call`    (vendor/.../ToolsHandler.php:182) —
 *     BLOCKS execute-ability against target abilities not in the server's set.
 *   - `mcp_adapter_tool_call_result` (vendor/.../ToolsHandler.php:205) —
 *     REWRITES discover-abilities responses to the per-server subset; REPLACES
 *     get-ability-info responses with a WP_Error for hidden abilities (no info leak).
 *
 * ---
 *
 * This file is TEMPORARY and MUST be deleted when the upstream vendor issue lands.
 *
 * @sunset-when   https://github.com/WordPress/mcp-adapter/issues/243 lands upstream
 * @sunset-grep   ACROSSAI_MCP_MANAGER_VENDOR_OVERRIDE_243
 * @sunset-remove-list
 *   - includes/VendorOverrides/                      (this folder — entire)
 *   - tests/phpunit/VendorOverrides/                 (test folder — entire)
 *   - Two `add_filter` lines in includes/Main.php    (grep for sunset constant)
 *   - docs/planings-tasks/026-vendor-abilities-override.md
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\VendorOverrides
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\VendorOverrides;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\AbilityDiscovery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Call-time interceptor for the three vendor default abilities.
 *
 * Plugin-wide singleton per A11. Public methods are filter callbacks; wire in
 * `Includes\Main::define_admin_hooks()` at priority 40 (after F015/F017/F020
 * at 10/20/30 — see DEC-F020-TOOL-ENFORCEMENT-PRIORITY).
 *
 * @since 0.1.0
 */
final class VendorAbilityInterceptor {

	/**
	 * Sunset marker. Grep this constant to find every touch point for removal
	 * when https://github.com/WordPress/mcp-adapter/issues/243 lands upstream.
	 */
	public const ACROSSAI_MCP_MANAGER_VENDOR_OVERRIDE_243 = true;

	public const VENDOR_ABILITY_DISCOVER = 'mcp-adapter/discover-abilities';
	public const VENDOR_ABILITY_GET_INFO = 'mcp-adapter/get-ability-info';
	public const VENDOR_ABILITY_EXECUTE  = 'mcp-adapter/execute-ability';

	public const CONTEXT_DISCOVER = 'discover';
	public const CONTEXT_GET_INFO = 'get_info';
	public const CONTEXT_EXECUTE  = 'execute';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance(). Per A1, hook wiring lives in Main.php.
	 */
	private function __construct() {}

	/**
	 * Callback for `mcp_adapter_pre_tool_call` at priority 40.
	 *
	 * Blocks `mcp-adapter/execute-ability` calls whose target ability is not
	 * in the server's F017-effective set (widened/narrowed by the
	 * `acrossai_mcp_manager_vendor_override_effective_slugs` filter). All other
	 * tool names pass through untouched.
	 *
	 * Fail-open pattern matches F017 `AbilityExposureGate::gate_tool_call_by_exposure`:
	 * missing server accessor / missing DB row / missing Abilities API → return
	 * `$args` unchanged rather than deny.
	 *
	 * @param mixed  $args      Tool call arguments (may already be a WP_Error from an earlier gate).
	 * @param string $tool_name The tool being called (ability slug).
	 * @param mixed  $mcp_tool  Vendor McpTool instance (unused).
	 * @param mixed  $server    Vendor McpServer instance.
	 * @return mixed The original `$args`, or a `WP_Error` when the target is not exposed.
	 */
	public function maybe_block_execute( $args, string $tool_name, $mcp_tool, $server ) {
		unset( $mcp_tool );

		if ( self::VENDOR_ABILITY_EXECUTE !== $tool_name ) {
			return $args;
		}

		// Respect an earlier-priority deny — never re-allow.
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$server_id = $this->resolve_server_id( $server );
		if ( null === $server_id ) {
			return $args; // Fail-open.
		}

		$target_slug = $this->extract_target_slug( $args );
		if ( '' === $target_slug ) {
			return $args; // No target to check — vendor's own validation will surface the error.
		}

		$effective = $this->effective_slugs_for(
			$server_id,
			self::CONTEXT_EXECUTE,
			$this->maybe_get_ability( $target_slug )
		);

		if ( in_array( $target_slug, $effective, true ) ) {
			return $args;
		}

		return new \WP_Error(
			'acrossai_mcp_ability_not_exposed_for_server',
			__( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Callback for `mcp_adapter_tool_call_result` at priority 40.
	 *
	 * For `mcp-adapter/discover-abilities`: rewrites the `abilities` array to
	 * the per-server subset.
	 *
	 * For `mcp-adapter/get-ability-info`: replaces the result with a `WP_Error`
	 * when the target ability isn't in the per-server set (no metadata leak
	 * for hidden abilities).
	 *
	 * All other tool names and any pre-existing `WP_Error` results pass through.
	 *
	 * @param mixed  $result    Raw execution result (may be WP_Error).
	 * @param array  $args      Tool arguments used.
	 * @param string $tool_name The tool that was called.
	 * @param mixed  $mcp_tool  Vendor McpTool instance (unused).
	 * @param mixed  $server    Vendor McpServer instance.
	 * @return mixed The (possibly rewritten) result.
	 */
	public function filter_result_by_server( $result, array $args, string $tool_name, $mcp_tool, $server ) {
		unset( $mcp_tool );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$server_id = $this->resolve_server_id( $server );
		if ( null === $server_id ) {
			return $result; // Fail-open.
		}

		if ( self::VENDOR_ABILITY_DISCOVER === $tool_name ) {
			return $this->rewrite_discover_result( $result, $server_id );
		}

		if ( self::VENDOR_ABILITY_GET_INFO === $tool_name ) {
			return $this->rewrite_get_info_result( $result, $args, $server_id );
		}

		return $result;
	}

	/**
	 * Rewrite `discover-abilities` output: keep only entries whose `name` is
	 * in the F017-effective (+ filter-adjusted) set.
	 *
	 * @param mixed $result    The vendor discover result (expected shape: `[ 'abilities' => [ [name, label, description], ... ] ]`).
	 * @param int   $server_id The resolved server ID.
	 * @return mixed The rewritten result, or `$result` untouched when shape is unexpected.
	 */
	private function rewrite_discover_result( $result, int $server_id ) {
		if ( ! is_array( $result ) || ! isset( $result['abilities'] ) || ! is_array( $result['abilities'] ) ) {
			return $result;
		}

		$effective = $this->effective_slugs_for( $server_id, self::CONTEXT_DISCOVER, null );
		$allowed   = array_flip( $effective );

		$result['abilities'] = array_values(
			array_filter(
				$result['abilities'],
				static function ( $entry ) use ( $allowed ) {
					return is_array( $entry ) && isset( $entry['name'] ) && isset( $allowed[ (string) $entry['name'] ] );
				}
			)
		);

		return $result;
	}

	/**
	 * Rewrite `get-ability-info` output: replace with `WP_Error` when the
	 * target ability isn't in the F017-effective (+ filter-adjusted) set.
	 * Prevents metadata enumeration for hidden abilities.
	 *
	 * @param mixed $result    The vendor get-info result.
	 * @param array $args      The tool arguments (`[ 'name' => <ability_slug> ]`).
	 * @param int   $server_id The resolved server ID.
	 * @return mixed The result on allow, `WP_Error` on hide, or `$result` untouched when args are unexpected.
	 */
	private function rewrite_get_info_result( $result, array $args, int $server_id ) {
		$target_slug = $this->extract_target_slug( $args );
		if ( '' === $target_slug ) {
			return $result;
		}

		$effective = $this->effective_slugs_for(
			$server_id,
			self::CONTEXT_GET_INFO,
			$this->maybe_get_ability( $target_slug )
		);

		if ( in_array( $target_slug, $effective, true ) ) {
			return $result;
		}

		return new \WP_Error(
			'acrossai_mcp_ability_not_exposed_for_server',
			__( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Compute the effective ability slug list for a server, then apply the
	 * plugin-owned filter so companion code can add or remove entries per
	 * (server_id, context).
	 *
	 * @param int    $server_id The resolved server ID.
	 * @param string $context   One of self::CONTEXT_* — `'discover' | 'get_info' | 'execute'`.
	 * @param mixed  $target    The target ability (for get_info / execute), or null (for discover).
	 * @return string[] Deduped, string-normalized, zero-indexed slug list.
	 */
	private function effective_slugs_for( int $server_id, string $context, $target ): array {
		$slugs = AbilityDiscovery::for_server( $server_id, AbilityDiscovery::TYPE_TOOL );

		/**
		 * Filter the effective ability slug set the vendor-override interceptor
		 * uses to gate the three built-in mcp-adapter abilities.
		 *
		 * Fires once per (server, context) pair per intercepted call. Companion
		 * plugins may add or remove any slug freely. The result is defensively
		 * re-normalized (deduped, string-normalized, zero-indexed) before use.
		 *
		 * @since 0.1.0 (Feature 026 vendor override — temporary, sunset by mcp-adapter#243)
		 *
		 * @param string[] $slugs     Default: `AbilityDiscovery::for_server( $server_id, 'tool' )`.
		 * @param int      $server_id Resolved MCP server ID.
		 * @param string   $context   One of `'discover' | 'get_info' | 'execute'`.
		 * @param mixed    $target    The target ability instance (get_info / execute) or null (discover).
		 */
		$slugs = apply_filters(
			'acrossai_mcp_manager_vendor_override_effective_slugs',
			$slugs,
			$server_id,
			$context,
			$target
		);

		return array_values( array_unique( array_map( 'strval', (array) $slugs ) ) );
	}

	/**
	 * Resolve the McpServer instance to an integer server_id via the F011
	 * MCPServer table lookup. Returns null on any resolution failure —
	 * callers fail-open on null.
	 *
	 * @param mixed $server Vendor McpServer instance (duck-typed for testability).
	 * @return int|null
	 */
	private function resolve_server_id( $server ): ?int {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return null;
		}

		$server_slug = (string) $server->get_server_id();
		if ( '' === $server_slug ) {
			return null;
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);
		if ( empty( $rows ) ) {
			return null;
		}

		return (int) $rows[0]->id;
	}

	/**
	 * Extract the target ability slug from an execute-ability / get-info args
	 * payload. Vendor schema (per ExecuteAbilityAbility.php:37) uses a
	 * top-level `name` key holding the slug.
	 *
	 * @param mixed $args Args from the vendor's tool call.
	 */
	private function extract_target_slug( $args ): string {
		if ( ! is_array( $args ) || ! isset( $args['name'] ) ) {
			return '';
		}
		return trim( (string) $args['name'] );
	}

	/**
	 * `wp_get_ability()` wrapper — returns the ability or null when the
	 * Abilities API isn't loaded / the slug is unknown.
	 *
	 * @param string $slug Ability slug to look up.
	 * @return mixed The WP_Ability instance on hit, null otherwise.
	 */
	private function maybe_get_ability( string $slug ) {
		if ( '' === $slug || ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}
		$ability = \wp_get_ability( $slug );
		return $ability ? $ability : null;
	}
}
