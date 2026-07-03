<?php
/**
 * MCP access-control integration wrapper (v2 adoption).
 *
 * Feature 015 — wraps the wpb-access-control v2 vendor package with a
 * plugin-scoped singleton that owns the AccessControlManager instance,
 * the DB table slug, and the providers filter tag. Copy-adapted verbatim
 * from the sibling acrossai-abilities-manager plugin's
 * AcrossAI_Abilities_Access_Control (see DEC-ACCESS-CONTROL-V2-ADOPTION).
 *
 * Observability hooks (per FR-026 + Clarifications Q2 + Q3, D19 pattern):
 *   - do_action( 'acrossai_mcp_access_control_denied', int $user_id, string $server_slug_or_route, ?string $tool_name, string $context_slug )
 *     fires BEFORE the WP_Error / empty-list return at both enforcement sites.
 *     $tool_name is null at the /servers site (no tool), non-null at MCP boundary.
 *     $context_slug ∈ { 'cli_servers', 'mcp_tool_call' }.
 *   - do_action( 'acrossai_mcp_access_control_missing_server', int $server_id_or_slug, string $tool_name, int $user_id )
 *     fires on race with concurrent DELETE (MCPServerQuery returns 0 rows for
 *     the mcp-adapter-supplied server_id). Fail-open follows.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.0.7
 */

namespace AcrossAI_MCP_Manager\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WPBoilerplate\AccessControl\AccessControlManager;

defined( 'ABSPATH' ) || exit;

/**
 * MCP access-control integration wrapper (v2 adoption).
 *
 * @since 0.0.7
 */
final class AcrossAI_MCP_Access_Control {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.7
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Plugin-scoped provider filter tag.
	 *
	 * @since 0.0.7
	 * @var string
	 */
	public const PROVIDERS_FILTER = 'acrossai_mcp_access_control_providers';

	/**
	 * Per-consumer table slug (wpb-access-control v2+).
	 *
	 * Drives: `{$wpdb->prefix}mcp_access_control` table,
	 * `wpb_ac_mcp_db_version` schema option, `wpb_ac_mcp` cache group,
	 * `/wpb-ac/v1/mcp/...` REST namespace. Must match `^[a-z0-9_]{1,32}$`
	 * (validated upstream at construction time).
	 *
	 * @since 0.0.7
	 * @var string
	 */
	public const TABLE_SLUG = 'mcp';

	/**
	 * Access-control manager instance.
	 *
	 * @since 0.0.7
	 * @var AccessControlManager|null
	 */
	private $manager = null;

	/**
	 * Return singleton instance.
	 *
	 * @since 0.0.7
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.0.7
	 */
	private function __construct() {}

	/**
	 * Boot the access-control manager.
	 *
	 * @since 0.0.7
	 * @return void
	 */
	public function boot_manager(): void {
		if ( ! $this->is_available() || $this->manager instanceof AccessControlManager ) {
			return;
		}

		$this->manager = new AccessControlManager( self::PROVIDERS_FILTER, self::TABLE_SLUG );
	}

	/**
	 * Register the library REST routes when available.
	 *
	 * @since 0.0.7
	 * @return void
	 */
	public function register_rest_api(): void {
		$manager = $this->get_manager();

		if ( null === $manager ) {
			return;
		}

		$manager->register_rest_api();
	}

	/**
	 * Check whether the access-control library is available.
	 *
	 * @since 0.0.7
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( AccessControlManager::class );
	}

	/**
	 * Return the manager instance when available.
	 *
	 * @since 0.0.7
	 * @return AccessControlManager|null
	 */
	public function get_manager(): ?AccessControlManager {
		if ( ! $this->manager instanceof AccessControlManager ) {
			$this->boot_manager();
		}

		return $this->manager;
	}

	/**
	 * Enumerate every WordPress capability registered across all roles.
	 *
	 * The result is the union of `capabilities` arrays across every role in
	 * `wp_roles()->role_objects`, deduplicated + sorted alphabetically. This
	 * matches the sibling `acrossai-abilities-manager` plugin's User Access
	 * capability picker shape per Clarifications Q4 (supersedes the earlier
	 * curated allow-list that shipped in the initial F015 draft).
	 *
	 * Administrators bypass every rule per the v2 access-hierarchy step 2,
	 * so exposing high-privilege capabilities (manage_options, edit_users) in
	 * this list is harmless: only admins hold them, and admins already have
	 * unrestricted access. A rule allowing `manage_options` is a no-op, not
	 * a privilege-escalation vector.
	 *
	 * @since 0.0.7
	 * @return array<int, string>
	 */
	public function get_available_capabilities(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		$roles_obj = wp_roles();
		if ( ! is_object( $roles_obj ) || empty( $roles_obj->role_objects ) ) {
			return array();
		}
		$caps = array();
		foreach ( $roles_obj->role_objects as $role ) {
			if ( is_object( $role ) && ! empty( $role->capabilities ) && is_array( $role->capabilities ) ) {
				$caps = array_merge( $caps, array_keys( $role->capabilities ) );
			}
		}
		/**
		 * Filter — third-party plugins may append custom capabilities the
		 * operator should see in the picker (e.g., `manage_woocommerce`).
		 *
		 * @since 0.0.7
		 *
		 * @param array<int, string> $capabilities Enumerated role capabilities.
		 */
		$caps = (array) apply_filters( 'acrossai_mcp_ac_available_capabilities', $caps );
		$caps = array_values( array_unique( array_filter( array_map( 'strval', $caps ) ) ) );
		sort( $caps );
		return $caps;
	}

	/**
	 * Display an admin notice when the wpb-access-control library is absent.
	 *
	 * Hooked to admin_notices. Only shown to users with manage_options and only
	 * when the library class is not loaded. Fail-open per sibling DEC-PERM-CB.
	 *
	 * @since 0.0.7
	 * @return void
	 */
	public function maybe_show_library_notice(): void {
		if ( $this->is_available() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_admin_notice(
			sprintf(
				/* translators: %s: library class name */
				esc_html__( 'AcrossAI MCP Manager: The wpb-access-control library (%s) is not loaded. Per-server MCP access-control rules are inactive and all tool calls will pass (fail-open). Install or activate the library to enforce saved rules.', 'acrossai-mcp-manager' ),
				'<code>WPBoilerplate\\AccessControl\\AccessControlManager</code>'
			),
			array(
				'type'           => 'warning',
				'dismissible'    => true,
				'paragraph_wrap' => false,
			)
		);
	}

	/**
	 * Filter callback for `mcp_adapter_pre_tool_call` — the MCP-boundary
	 * enforcement site (FR-007, D18 canonical hook, Q2 + Q3 observability).
	 *
	 * Wired via `Main::define_public_hooks()` per A1.
	 *
	 * @since 0.0.7
	 *
	 * @param array<mixed>                 $args      Tool call args.
	 * @param string                       $tool_name The MCP tool name.
	 * @param mixed                        $mcp_tool  The McpTool instance (unused here).
	 * @param \WP\MCP\Core\McpServer|mixed $server    The McpServer instance.
	 * @return array<mixed>|\WP_Error Original args on allow / fail-open; WP_Error on deny.
	 */
	public function gate_mcp_tool_call( $args, $tool_name, $mcp_tool, $server ) {
		unset( $mcp_tool );

		if ( ! $this->is_available() ) {
			return $args;
		}

		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return $args;
		}

		$server_slug = (string) $server->get_server_id();
		$user_id     = get_current_user_id();

		// Defensive resolution — verify the server still exists in our F011 DB
		// (Clarifications Q2 covers the race with a concurrent DELETE).
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);

		if ( empty( $rows ) ) {
			/**
			 * Fires when the mcp-adapter routes a tool call to a server ID
			 * that no longer exists in the F011 DB (race with concurrent
			 * DELETE). Fire-and-forget; fail-open follows.
			 *
			 * @since 0.0.7
			 *
			 * @param string $server_slug The server_id string from mcp-adapter.
			 * @param string $tool_name   The MCP tool being invoked.
			 * @param int    $user_id     The current user id.
			 */
			do_action( 'acrossai_mcp_access_control_missing_server', $server_slug, $tool_name, $user_id );
			return $args;
		}

		$manager = $this->get_manager();
		if ( null === $manager ) {
			return $args;
		}

		if ( $manager->user_has_access( $user_id, 'acrossai-mcp-manager', $server_slug ) ) {
			return $args;
		}

		/**
		 * Fires immediately BEFORE returning the WP_Error on deny at the MCP
		 * tool-call boundary. Fire-and-forget; operators may hook for audit.
		 *
		 * @since 0.0.7
		 *
		 * @param int    $user_id     The current user id.
		 * @param string $server_slug The rule key.
		 * @param string $tool_name   The MCP tool name (never null at this site).
		 * @param string $context     Fixed value 'mcp_tool_call'.
		 */
		do_action( 'acrossai_mcp_access_control_denied', $user_id, $server_slug, $tool_name, 'mcp_tool_call' );

		return new \WP_Error(
			'acrossai_mcp_access_denied',
			__( 'You do not have permission to invoke tools on this MCP server.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Provider filter callback — registers the 3 built-in v2 providers.
	 * Third-party plugins may append their own via the same filter tag.
	 *
	 * @since 0.0.7
	 *
	 * @param array<int, object> $providers Existing provider instances.
	 * @return array<int, object>
	 */
	public static function register_default_providers( array $providers ): array {
		$providers[] = new \WPBoilerplate\AccessControl\WpRoleProvider();
		$providers[] = new \WPBoilerplate\AccessControl\WpUserProvider();
		$providers[] = new \WPBoilerplate\AccessControl\WpCapabilityProvider();

		return $providers;
	}
}
