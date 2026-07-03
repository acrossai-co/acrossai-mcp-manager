<?php
/**
 * Access Control tab — thin delegate to AccessControlBlock.
 *
 * Feature 015 — converts the F013 shape-only shell to a thin delegate to
 * `public/Renderers/AccessControlBlock.php`, matching the F013
 * NpmTab/ClientsTab/ClaudeConnectorTab delegate pattern per
 * DEC-CLIENT-RENDERER-PUBLIC-API. The v1-API singleton call at the old line 65
 * is deleted (was fatal in v2 — see F015 US2 + FR-016 grep gate).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Access Control tab — thin delegate to AccessControlBlock (F015).
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
	 * Delegates render to AccessControlBlock. The Block owns the vendor-package
	 * fail-open branch, the form UI, and the read-side rule lookup.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		\AcrossAI_MCP_Manager\Public\Renderers\AccessControlBlock::instance()->render(
			(int) $server['id'],
			array(
				'context'           => 'admin',
				'cap'               => 'manage_options',
				'submit_target_url' => $this->server_edit_url( $server, 'access-control' ),
				'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
			)
		);
	}
}
