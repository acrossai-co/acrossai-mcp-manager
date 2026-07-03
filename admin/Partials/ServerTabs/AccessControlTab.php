<?php
/**
 * Access Control tab — vendor delegate per D8.
 *
 * Preserves the class_exists('\WPBoilerplate\AccessControl\AccessControlManager')
 * guard verbatim from the pre-F013 Access Control tab body.
 * TASK-2 shape validation only — no functional changes.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Access Control tab — delegates to wpb-access-control vendor manager
 * when present, renders an informational notice when absent.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class AccessControlTab extends AbstractServerTab {

	/**
	 * The tab's URL slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'access-control';
	}

	/**
	 * The tab's operator-visible label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Access Control', 'acrossai-mcp-manager' );
	}

	/**
	 * Renders the tab body. Preserves the D8 vendor guard verbatim.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		if ( ! class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
			echo '<div class="notice notice-info inline"><p>';
			esc_html_e( 'Access Control requires the wpb-access-control package. Install it to manage per-server access rules.', 'acrossai-mcp-manager' );
			echo '</p></div>';
			return;
		}

		// Vendor manager is responsible for its own rendering + persistence.
		$manager = \WPBoilerplate\AccessControl\AccessControlManager::instance( 'acrossai_mcp_access_control_providers' );
		if ( method_exists( $manager, 'render_admin_page' ) ) {
			$manager->render_admin_page( (int) $server['id'] );
			return;
		}

		// Fallback notice when the vendor API we expected isn't present yet.
		echo '<div class="notice notice-warning inline"><p>';
		esc_html_e( 'Access Control package is present but does not expose the expected render_admin_page() API for this version.', 'acrossai-mcp-manager' );
		echo '</p></div>';
	}
}
