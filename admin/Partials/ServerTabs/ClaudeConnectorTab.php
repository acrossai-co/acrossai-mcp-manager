<?php
/**
 * Claude Connector tab — thin delegate to ClaudeConnectorBlock.
 *
 * Feature 013 — T029 converts this from the T011 minimal port to a thin
 * delegate. The full render (form + audit log) lives in
 * ClaudeConnectorBlock so third-party plugins can embed the same UI.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Public\Renderers\ClaudeConnectorBlock;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Claude Connector tab — thin delegate to ClaudeConnectorBlock.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class ClaudeConnectorTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'claude-connector';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Claude Connector', 'acrossai-mcp-manager' );
	}

	/**
	 * Delegates render to ClaudeConnectorBlock.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		ClaudeConnectorBlock::instance()->render(
			(int) $server['id'],
			array(
				'context'           => 'admin',
				'cap'               => 'manage_options',
				'submit_target_url' => $this->server_edit_url( $server, 'claude-connector' ),
				'nonce_action'      => 'acrossai_mcp_claude_connector_' . (int) $server['id'],
			)
		);
	}
}
