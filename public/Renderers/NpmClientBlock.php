<?php
/**
 * The npm / npx CLI configuration block.
 *
 * Feature 013 — this Block encapsulates the npm client-configuration render
 * so admin tab (NpmTab) AND third-party plugins (BuddyBoss, WooCommerce)
 * consume the same UI with zero code duplication.
 *
 * Renders the terminal `npx` command that starts the AcrossAI MCP Manager
 * CLI against this server + Site URL + Server slug rows + CLI Connection
 * Log table. F012 gate: acrossai_mcp_npm_login_enabled — when disabled,
 * renders the "currently disabled" notice + link to Settings INSTEAD of
 * the command UI. CLI Connection Log renders regardless of toggle state.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 *
 * See DEC-CLIENT-RENDERER-PUBLIC-API.
 */

namespace AcrossAI_MCP_Manager\Public\Renderers;

use AcrossAI_MCP_Manager\Admin\Partials\CliAuthLogListTable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The npm client configuration block. Singleton.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 */
final class NpmClientBlock extends AbstractClientRenderer {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.6
	 * @var NpmClientBlock|null
	 */
	protected static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @since 0.0.6
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
	 * @since 0.0.6
	 */
	private function __construct() {}

	/**
	 * Returns the block slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'npm';
	}

	/**
	 * Renders the block body. Gated on F012 acrossai_mcp_npm_login_enabled.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0. (SEC-013-005)
	 *
	 * @param array $server  Server row data.
	 * @param array $context Resolved context array.
	 * @return void
	 */
	protected function render_body( array $server, array $context ): void {
		echo '<div class="mcp-tab-panel">';
		$this->render_section_heading( __( 'npm / npx CLI', 'acrossai-mcp-manager' ) );

		$enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
		if ( ! $enabled ) {
			$this->render_feature_disabled_notice(
				__( 'npm / npx CLI', 'acrossai-mcp-manager' ),
				__( 'enable CLI Connections in Settings', 'acrossai-mcp-manager' ),
				__( 'Enabling this feature allows terminal users to connect the AcrossAI MCP Manager CLI tool to this WordPress site using the npx command. Users sign in through WordPress and approve access in the browser, then the CLI receives an Application Password automatically so no JSON files need to be configured by hand. Only enable this if you intend to use the CLI for local development or trusted environments.', 'acrossai-mcp-manager' )
			);
		} else {
			$this->render_command_ui( $server );
		}

		// Divider — matches reference spacing (24px above/below).
		echo '<hr style="margin:24px 0;" />';

		$this->render_cli_connection_log( $server );
		echo '</div>';
	}

	/**
	 * Renders the npx command box + Copy button + Site URL + Server rows.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	private function render_command_ui( array $server ): void {
		$site_url    = home_url();
		$server_slug = (string) $server['server_slug'];
		$command     = sprintf(
			'npx -y @acrossai/mcp-manager --siteurl=%1$s --server=%2$s',
			$site_url,
			$server_slug
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Run this command in your terminal to connect the AcrossAI MCP Manager CLI to this server.', 'acrossai-mcp-manager' )
		);

		// Command field — reference plugin's exact markup:
		// widefat + code (WP core classes) + mcp-cmd (single-line variant).
		$cmd_id = 'npm_command_' . (int) $server['id'];
		echo '<div class="mcp-config-json">';
		printf(
			'<label for="%1$s"><strong>%2$s</strong></label>',
			esc_attr( $cmd_id ),
			esc_html__( 'Command', 'acrossai-mcp-manager' )
		);
		printf(
			'<textarea id="%1$s" class="widefat code mcp-cmd" rows="1" readonly>%2$s</textarea>',
			esc_attr( $cmd_id ),
			esc_textarea( $command )
		);
		printf(
			'<button type="button" class="button copy-to-clipboard" data-field="%1$s">%2$s</button>',
			esc_attr( $cmd_id ),
			esc_html__( 'Copy Command', 'acrossai-mcp-manager' )
		);
		echo '</div>';

		// Site URL + Server rows — reference uses form-table, not custom divs.
		echo '<table class="form-table" role="presentation">';
		printf(
			'<tr><th scope="row">%1$s</th><td><code>%2$s</code></td></tr>',
			esc_html__( 'Site URL', 'acrossai-mcp-manager' ),
			esc_html( $site_url )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><code>%2$s</code></td></tr>',
			esc_html__( 'Server', 'acrossai-mcp-manager' ),
			esc_html( $server_slug )
		);
		echo '</table>';
	}

	/**
	 * Renders the CLI Connection Log table below the config UI.
	 * Renders regardless of toggle state (past events remain visible).
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	private function render_cli_connection_log( array $server ): void {
		printf( '<h3>%s</h3>', esc_html__( 'CLI Connection Log', 'acrossai-mcp-manager' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Approved, successful, and failed CLI connection attempts for this MCP server.', 'acrossai-mcp-manager' )
		);
		if ( class_exists( CliAuthLogListTable::class ) ) {
			$table = new CliAuthLogListTable( (int) $server['id'] );
			$table->prepare_items();
			$table->display();
		} else {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Connection log unavailable.', 'acrossai-mcp-manager' )
			);
		}
	}
}
