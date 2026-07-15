<?php
/**
 * Request-scoped holder for the current MCP server.
 *
 * Populated during `rest_pre_dispatch` when the incoming REST route
 * matches a registered MCP server. Consumed by the plugin-owned
 * replacements for the three vendor default abilities so they can
 * pass a `server_id` to the `acrossai_mcp_is_ability_exposed` filter.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide singleton per A11. Public methods are hook callbacks;
 * wire in `Includes\Main::define_admin_hooks()`.
 *
 * @since 0.1.0
 */
final class CurrentServerHolder {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * The current MCP server object, or null when no MCP request is in flight.
	 *
	 * @var \WP\MCP\Core\McpServer|null
	 */
	private ?McpServer $current = null;

	/**
	 * Cached slug → int PK map, refreshed lazily. Cleared on each
	 * `rest_pre_dispatch` call so a mid-request server registration
	 * cannot poison the cache.
	 *
	 * @var array<string, int>
	 */
	private array $slug_to_id_cache = array();

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
	 * Private — use ::instance(). Hook wiring lives in Main.php per A1.
	 */
	private function __construct() {}

	/**
	 * Set the current server. Called by `capture_from_request()`.
	 *
	 * @param \WP\MCP\Core\McpServer|null $server The active server, or null to clear.
	 */
	public function set( ?McpServer $server ): void {
		$this->current = $server;
	}

	/**
	 * Return the current server object, or null.
	 *
	 * @return \WP\MCP\Core\McpServer|null
	 */
	public function get(): ?McpServer {
		return $this->current;
	}

	/**
	 * Return the current server's integer PK from `wp_acrossai_mcp_servers`,
	 * or null if no server is set or the lookup fails.
	 *
	 * Fail-open pattern: callers must treat null as "no per-server context
	 * available" (typically → fall back to `meta.mcp.public`).
	 */
	public function get_server_id(): ?int {
		if ( null === $this->current ) {
			return null;
		}

		$slug = (string) $this->current->get_server_id();
		if ( '' === $slug ) {
			return null;
		}

		if ( isset( $this->slug_to_id_cache[ $slug ] ) ) {
			return $this->slug_to_id_cache[ $slug ];
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $slug,
				'number'      => 1,
			)
		);
		if ( empty( $rows ) ) {
			return null;
		}

		$id                              = (int) $rows[0]->id;
		$this->slug_to_id_cache[ $slug ] = $id;
		return $id;
	}

	/**
	 * Callback for `rest_pre_dispatch` @ priority 5.
	 *
	 * Reads the incoming REST route and, if it matches a registered
	 * MCP server's namespace/route, captures that server. Otherwise
	 * leaves the holder null.
	 *
	 * @param mixed            $result  Pre-dispatch short-circuit value (pass through unchanged).
	 * @param \WP_REST_Server  $server  REST server (unused).
	 * @param \WP_REST_Request $request The incoming request.
	 * @return mixed
	 */
	public function capture_from_request( $result, $server, $request ) {
		unset( $server );

		// Refresh cache per-request so a mid-request server registration
		// (unlikely but possible in tests) doesn't return stale IDs.
		$this->slug_to_id_cache = array();
		$this->current          = null;

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}
		if ( ! class_exists( McpAdapter::class ) ) {
			return $result;
		}

		$route = trim( (string) $request->get_route(), '/' );
		if ( '' === $route ) {
			return $result;
		}

		foreach ( McpAdapter::instance()->get_servers() as $mcp_server ) {
			$ns   = trim( (string) $mcp_server->get_server_route_namespace(), '/' );
			$path = trim( (string) $mcp_server->get_server_route(), '/' );

			// Match "<namespace>/<route>" as a prefix of the incoming route.
			// Handles both the exact endpoint (POST) and any sub-routes.
			$expected = trim( $ns . '/' . $path, '/' );
			if ( '' === $expected ) {
				continue;
			}

			if ( $route === $expected || 0 === strpos( $route, $expected . '/' ) ) {
				$this->current = $mcp_server;
				break;
			}
		}

		return $result;
	}

	/**
	 * Callback for `rest_post_dispatch` @ priority 999 and `shutdown` @ priority 999.
	 *
	 * The `shutdown` binding is intentional — if a fatal error kills
	 * the request mid-flight, `rest_post_dispatch` may not fire and
	 * the holder would otherwise leak across requests in long-lived
	 * PHP processes (Roadrunner, FrankenPHP, wp-env with keep-alive).
	 *
	 * @param mixed $passthrough Value to return unchanged (varies by hook).
	 * @return mixed
	 */
	public function clear( $passthrough = null ) {
		$this->current          = null;
		$this->slug_to_id_cache = array();
		return $passthrough;
	}
}
