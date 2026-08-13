<?php
/**
 * MCP HTTP transport default-capability filter callback (F042).
 *
 * This class is filter #1 of the plugin's TWO-FILTER, PER-SERVER MCP
 * permission stack. The two filters run side-by-side, wired adjacently in
 * `includes/Main.php::define_public_hooks()`:
 *
 *   1. `mcp_adapter_default_transport_permission_user_capability` (F042, THIS class)
 *      Fires inside the WP REST `permission_callback` at
 *      `vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:119`.
 *      Returns a WP capability string; the vendor caller runs
 *      `current_user_can( <returned-cap> )` and returns 401 on false.
 *      Server resolution: URL route → MCPServerQuery lookup → server_slug.
 *
 *   2. `mcp_adapter_pre_tool_call` (F015, {@see AcrossAI_MCP_Access_Control::gate_mcp_tool_call})
 *      Fires at MCP tool-call dispatch. Returns $args on allow, WP_Error 403
 *      on deny per the operator's wpb-ac rule.
 *      Server resolution: $server->get_server_id() from the McpServer instance
 *      passed as the 4th filter arg → server_slug.
 *
 * Rule-aware semantics for THIS filter:
 *
 *   - When the wpb-access-control table has NO rule for the current server
 *     (RuleQuery::get_rule() returns `['key' => '', 'value' => []]` — the same
 *     state that renders "No user access added by admin" in the vendor
 *     dropdown) → return `'manage_options'` so the transport gate blocks
 *     non-admins (defense-in-depth: hard-stops non-admin traffic before it
 *     ever reaches the F015 tool-call layer, which would otherwise fail-open).
 *
 *   - When any rule exists (Anyone / Authenticated / role / user / capability)
 *     → return the vendor default (`'read'`) so the transport gate becomes
 *     permissive and the F015 wrapper's `gate_mcp_tool_call` does the real
 *     true/false enforcement per the operator's configured rule.
 *
 * Per-server property: neither filter has any hardcoded server slug or
 * "default server special case". Both resolve the current server
 * independently per request. Adding a new server via **Add New Server**
 * causes both filters to apply their semantics to that server on the next
 * request with no code change.
 *
 * No DB writes. No coupling to server-creation code paths. Every server
 * (default-seeded + user-created + pre-042 legacy) inherits the admin-only
 * default automatically until the operator sets a rule.
 *
 * Fail direction is INTENTIONALLY asymmetric between the two gates:
 *   - F015 fails OPEN when `AccessControlManager` is missing (returns args
 *     so authenticated clients aren't hard-locked out by a missing library).
 *   - F042 (this class) fails CLOSED under the same condition — returns
 *     `manage_options` so the transport rejects non-admin traffic before
 *     it can hit F015's fail-open path.
 * Together they preserve defense-in-depth: a single dependency failure
 * degrades to admin-only rather than "wide open to every logged-in user".
 * Prior to 0.2.8 both gates failed open together, which meant a missing
 * vendor package collapsed the entire two-gate stack.
 *
 * Test coverage:
 *   - {@see \AcrossAI_MCP_Manager\Tests\Includes\AccessControl\TransportPermissionDefaultTest}
 *     14 unit tests, every branch of `filter_default_capability()` in isolation.
 *   - {@see \AcrossAI_MCP_Manager\Tests\Includes\AccessControl\TransportPermissionRoleMatrixTest}
 *     12 composed tests exercising BOTH filters end-to-end across 6 user roles
 *     × 4 rule shapes × ≥4 servers per test, including a 5×4 truth-table
 *     matrix that directly proves per-server independence.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.2.5
 */

namespace AcrossAI_MCP_Manager\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Callback for `mcp_adapter_default_transport_permission_user_capability`.
 *
 * @since 0.2.5
 */
final class TransportPermissionDefault {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Rule namespace matching {@see AcrossAI_MCP_Access_Control::user_has_server_access()}.
	 */
	private const NAMESPACE_SLUG = 'acrossai-mcp-manager';

	/**
	 * Default WordPress capability applied when no wpb-ac rule is configured.
	 */
	private const ADMIN_ONLY_CAPABILITY = 'manage_options';

	/**
	 * Per-request memoization keyed by "$namespace/$route" so repeat filter
	 * fires within a single MCP request don't re-run the DB lookup.
	 *
	 * @var array<string, string>
	 */
	private $memo = array();

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — enforces the plugin-wide singleton convention.
	 */
	private function __construct() {}

	/**
	 * Filter callback for `mcp_adapter_default_transport_permission_user_capability`.
	 *
	 * Returns 'manage_options' when the current server has no wpb-ac rule,
	 * otherwise returns the vendor default so the F015 wrapper's tool-call
	 * gate handles enforcement.
	 *
	 * @param string             $default_capability Vendor default (typically 'read').
	 * @param HttpRequestContext $context HTTP request context (exposes the WP_REST_Request).
	 * @return string A WordPress capability slug for `current_user_can()`.
	 */
	public function filter_default_capability( string $default_capability, HttpRequestContext $context ): string {
		$route = ltrim( (string) $context->request->get_route(), '/' );
		if ( '' === $route ) {
			return $default_capability;
		}

		// The vendor mcp-adapter registers routes as `/{namespace}/{route}` where
		// {route} may itself contain slashes (per WP REST convention). Split on
		// the first slash only.
		$parts = explode( '/', $route, 2 );
		if ( count( $parts ) !== 2 || '' === $parts[0] || '' === $parts[1] ) {
			return $default_capability;
		}

		$ns   = $parts[0];
		$path = $parts[1];
		$memo = $ns . '/' . $path;

		if ( isset( $this->memo[ $memo ] ) ) {
			return $this->memo[ $memo ];
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_route_namespace' => $ns,
				'server_route'           => $path,
				'number'                 => 1,
			)
		);

		if ( empty( $rows ) ) {
			// Route not owned by this plugin (some other consumer of the same
			// filter) — pass the default through unchanged.
			$this->memo[ $memo ] = $default_capability;
			return $default_capability;
		}

		$server_slug = (string) $rows[0]->server_slug;
		if ( '' === $server_slug ) {
			$this->memo[ $memo ] = $default_capability;
			return $default_capability;
		}

		if ( ! $this->has_access_control_library() ) {
			// Defense-in-depth: F015 (`gate_mcp_tool_call`) fails OPEN under
			// the same condition (see `AcrossAI_MCP_Access_Control::is_available()`
			// → early return $args). If BOTH gates fail open together, a
			// missing wpb-access-control vendor package (composer breakage,
			// autoloader race, missed dependency after site clone) collapses
			// the entire two-gate stack — every authenticated user reaches
			// every tool on every server, whether "no rule" or rule-configured.
			// Preserve the stack by failing CLOSED here: hard-lock the
			// transport to admins so F015's fail-open is caught upstream.
			// Site admins still reach MCP endpoints and see the failure.
			$this->memo[ $memo ] = self::ADMIN_ONLY_CAPABILITY;
			return self::ADMIN_ONLY_CAPABILITY;
		}

		$rules    = new RuleQuery( AcrossAI_MCP_Access_Control::TABLE_SLUG );
		$existing = $rules->get_rule( self::NAMESPACE_SLUG, $server_slug );

		if ( ! empty( $existing['key'] ) ) {
			// Operator has configured a rule — defer to the F015 wrapper's
			// tool-call gate for real enforcement.
			$this->memo[ $memo ] = $default_capability;
			return $default_capability;
		}

		$this->memo[ $memo ] = self::ADMIN_ONLY_CAPABILITY;
		return self::ADMIN_ONLY_CAPABILITY;
	}

	/**
	 * Test seam — whether the wpb-access-control vendor package is loaded.
	 *
	 * Extracted from an inline `class_exists()` call so PHPUnit tests can
	 * subclass this class and override the check to exercise the fail-closed
	 * branch without unloading Composer's autoloader.
	 *
	 * @since 0.2.8
	 * @return bool True when `RuleQuery` is available (production path);
	 *              false only when the vendor package failed to load.
	 */
	protected function has_access_control_library(): bool {
		return class_exists( RuleQuery::class );
	}
}
