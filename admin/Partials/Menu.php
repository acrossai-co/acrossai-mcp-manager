<?php
/**
 * MCP Manager admin menu — top-level + submenus + plugin action link.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level "MCP Manager" admin menu, its submenus
 * (Servers, CLI Auth Log, conditional Access Control), and the
 * "Settings" plugin-action link on the Plugins screen.
 *
 * Per FR-001 / FR-002 / FR-003.
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 * Hooks wired by Includes\Main::define_admin_hooks().
 *
 * Page slugs live in Includes\Utilities\AdminPageSlugs (RT-1, 2026-06-17)
 * so list tables and Settings don't depend on this sibling for constants.
 */
class Menu {

	/** @var Menu|null */
	protected static $_instance = null;

	/** @var string */
	private $plugin_name;

	/** @var string */
	private $version;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
		$this->version     = ACROSSAI_MCP_MANAGER_VERSION;
	}

	/**
	 * Register the top-level menu + submenus. FR-001 / FR-002.
	 *
	 * Render callbacks delegate to Settings::instance() so that Settings
	 * owns all page rendering and stays the single source of truth for
	 * admin HTML (Constitution Admin Partials Rule).
	 */
	public function register_menu(): void {
		$settings = Settings::instance();

		// 1) Top-level "MCP Manager" menu (FR-001).
		add_menu_page(
			__( 'MCP Manager', 'acrossai-mcp-manager' ),
			__( 'MCP Manager', 'acrossai-mcp-manager' ),
			'manage_options',
			AdminPageSlugs::PARENT,
			array( $settings, 'render_list_page' )
		);

		// 2) "Servers" submenu — same slug rewrites the parent label (WP convention).
		add_submenu_page(
			AdminPageSlugs::PARENT,
			__( 'Servers', 'acrossai-mcp-manager' ),
			__( 'Servers', 'acrossai-mcp-manager' ),
			'manage_options',
			AdminPageSlugs::PARENT,
			array( $settings, 'render_list_page' )
		);

		// 3) "CLI Auth Log" submenu.
		add_submenu_page(
			AdminPageSlugs::PARENT,
			__( 'CLI Auth Log', 'acrossai-mcp-manager' ),
			__( 'CLI Auth Log', 'acrossai-mcp-manager' ),
			'manage_options',
			AdminPageSlugs::CLI_AUTH_LOG,
			array( $settings, 'render_cli_auth_log_page' )
		);

		// 4) "Access Control" submenu — only when the vendor package is present.
		if ( class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
			add_submenu_page(
				AdminPageSlugs::PARENT,
				__( 'Access Control', 'acrossai-mcp-manager' ),
				__( 'Access Control', 'acrossai-mcp-manager' ),
				'manage_options',
				AdminPageSlugs::ACCESS_CONTROL,
				array( $settings, 'render_access_control_page' )
			);
		}
	}

	/**
	 * Prepend a "Settings" action link to the plugin's row on the Plugins screen.
	 * Wired via `plugin_action_links_<basename>` so this callback only fires
	 * for this plugin (FR-003).
	 *
	 * S5/B6: admin_url() output is wrapped with esc_url() at the boundary.
	 *
	 * @param array<int|string, string> $links Existing action links.
	 * @return array<int|string, string> Modified links.
	 */
	public function plugin_action_links( $links ): array {
		$settings_url = esc_url( admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT ) );
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			$settings_url,
			esc_html__( 'Settings', 'acrossai-mcp-manager' )
		);

		// Prepend the Settings link so it appears first.
		array_unshift( $links, $settings_link );

		return $links;
	}
}
