<?php
/**
 * Shared admin page slugs for the MCP Manager menu structure.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 */

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the `?page=` slug used by the parent menu and
 * each submenu. Consumed by Menu (to register), by Settings + list tables
 * (to build URLs), and by Admin\Main (to whitelist `get_current_screen()`).
 *
 * Extracted from Menu::PAGE_SLUG per RT-1 (Architecture Review 2026-06-17)
 * to satisfy Constitution Module Contract item 3 — "Depend only on shared
 * utilities from `includes/Utilities/` — never on sibling modules directly".
 *
 * NOTE: This file deliberately does NOT use the singleton ceremony — it
 * exposes only `const` values. There is no instance state to share.
 */
final class AdminPageSlugs {

	/** Top-level menu page slug. Matches `?page=` URL value. */
	public const PARENT = 'acrossai_mcp_manager';

	/** CLI Auth Log submenu page slug. */
	public const CLI_AUTH_LOG = 'acrossai_mcp_manager_cli_auth_log';

	/** Access Control submenu page slug (only registered when vendor pkg present). */
	public const ACCESS_CONTROL = 'acrossai_mcp_manager_access_control';

	/**
	 * Screen IDs WordPress generates for our pages — derived from the parent
	 * menu *title* (`MCP Manager` → `mcp-manager`) and the per-page slugs above.
	 * Used by Admin\Main::is_plugin_admin_screen() for the asset-enqueue guard.
	 *
	 * @return string[]
	 */
	public static function plugin_screen_ids(): array {
		return array(
			'toplevel_page_' . self::PARENT,
			'mcp-manager_page_' . self::CLI_AUTH_LOG,
			'mcp-manager_page_' . self::ACCESS_CONTROL,
		);
	}

	private function __construct() {
		// Never instantiated — constants-only utility.
	}
}
