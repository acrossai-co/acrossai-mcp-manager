<?php
/**
 * The MCP Tracker tab — link to the WPVMCPT plugin if installed.
 *
 * Feature 013 — ported from reference plugin's render_mcp_tracker_tab
 * (src/Admin/Settings.php:2293–2379). Detection-only render — never
 * executes tracker code directly; class_exists/defined guard per D8.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The MCP Tracker tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class McpTrackerTab extends AbstractServerTab {

	/**
	 * WordPress.org plugin page URL for the "Get MCP Tracker" call to action.
	 *
	 * @since 0.0.6
	 * @var string
	 */
	private const WPORG_URL = 'https://wordpress.org/plugins/mcp-tracker/';

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'mcp-tracker';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'MCP Tracker', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 80;
	}

	/**
	 * Renders MCP Tracker integration links. Gated on WPVMCPT presence per D8.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$server_slug = ! empty( $server['server_slug'] )
			? (string) $server['server_slug']
			: sanitize_title( (string) $server['server_name'] );

		$tracker_active = defined( 'WPVMCPT_PLUGIN_VERSION' ) || class_exists( 'WPVMCPT\\Plugin' );
		$tracker_url    = admin_url( 'admin.php?page=wpvmcpt-requests-list&server=' . rawurlencode( $server_slug ) );

		echo '<div class="mcp-tab-panel">';
		printf( '<h2>%s</h2>', esc_html__( 'MCP Tracker', 'acrossai-mcp-manager' ) );

		if ( $tracker_active ) {
			$this->render_active( $server_slug, $tracker_url );
		} else {
			$this->render_inactive( $tracker_url );
		}

		echo '</div>';
	}

	/**
	 * Renders the "tracker active" branch — success notice + button + slug hint.
	 *
	 * @since 0.0.6
	 * @param string $server_slug Server slug (already sanitized).
	 * @param string $tracker_url Direct link to the request log filtered to this server.
	 * @return void
	 */
	private function render_active( string $server_slug, string $tracker_url ): void {
		printf(
			'<div class="notice notice-success inline"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'MCP Tracker is active.', 'acrossai-mcp-manager' ),
			esc_html__( 'View all logged requests for this server below.', 'acrossai-mcp-manager' )
		);

		printf(
			'<p style="margin-top:16px;"><a href="%1$s" class="button button-primary">%2$s</a></p>',
			esc_url( $tracker_url ),
			esc_html__( 'View Request Log', 'acrossai-mcp-manager' )
		);

		echo '<p class="description" style="margin-top:12px;">';
		/* translators: %s: server slug (wrapped in <code>) */
		$slug_template = __( 'Direct link filtered to server: %s', 'acrossai-mcp-manager' );
		printf(
			wp_kses( $slug_template, array( 'code' => array() ) ),
			'<code>' . esc_html( $server_slug ) . '</code>'
		);
		echo '<br />';
		printf(
			'<code style="user-select:all;">%s</code>',
			esc_html( $tracker_url )
		);
		echo '</p>';
	}

	/**
	 * Renders the "tracker not installed" branch — info notice + facts table + CTA.
	 *
	 * @since 0.0.6
	 * @param string $tracker_url Direct link URL to display in the "Request log URL" row.
	 * @return void
	 */
	private function render_inactive( string $tracker_url ): void {
		printf(
			'<div class="notice notice-info inline"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'MCP Tracker is not installed.', 'acrossai-mcp-manager' ),
			esc_html__( 'Install the free MCP Tracker plugin to log and inspect every request made to this MCP server.', 'acrossai-mcp-manager' )
		);

		echo '<table class="form-table" style="margin-top:8px;">';

		printf(
			'<tr><th scope="row">%1$s</th><td><a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></td></tr>',
			esc_html__( 'Plugin', 'acrossai-mcp-manager' ),
			esc_url( self::WPORG_URL ),
			esc_html__( 'MCP Tracker — WordPress.org', 'acrossai-mcp-manager' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'What it does', 'acrossai-mcp-manager' ),
			esc_html__( 'Logs every incoming MCP request — tool calls, responses, errors, and timing — so you can audit activity, debug AI clients, and monitor server usage.', 'acrossai-mcp-manager' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><code style="user-select:all;">%2$s</code><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Request log URL', 'acrossai-mcp-manager' ),
			esc_html( $tracker_url ),
			esc_html__( 'Once installed and activated, this URL will open the request log filtered to this server.', 'acrossai-mcp-manager' )
		);

		echo '</table>';

		printf(
			'<p style="margin-top:16px;"><a href="%1$s" class="button" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( self::WPORG_URL ),
			esc_html__( 'Get MCP Tracker', 'acrossai-mcp-manager' )
		);
	}
}
