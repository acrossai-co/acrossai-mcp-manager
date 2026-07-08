<?php
/**
 * The Tools tab — MCP tools this server exposes to AI clients.
 *
 * Feature 013 — ported from reference plugin's render_tools_tab
 * (src/Admin/Settings.php:1893–1963). Lists the three core tools
 * defined by the wordpress/mcp-adapter package — same on every server.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Tools tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class ToolsTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'tools';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Tools', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 50;
	}

	/**
	 * Renders the MCP tools reference.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$enabled = ! empty( $server['is_enabled'] );

		echo '<div class="mcp-tab-panel">';
		printf( '<h2>%s</h2>', esc_html__( 'MCP Tools', 'acrossai-mcp-manager' ) );

		if ( ! $enabled ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Server is disabled.', 'acrossai-mcp-manager' ),
				esc_html__( 'Enable the server on the Overview tab to make these tools available to MCP clients.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'These are the MCP tools this server exposes to connected AI clients. Every server in this plugin provides the same three core tools backed by the WordPress Abilities API.', 'acrossai-mcp-manager' )
		);

		$this->render_tools_table( $this->get_core_tools() );

		printf(
			'<div class="notice notice-info inline" style="margin-top:16px;"><p>%s</p></div>',
			esc_html__( 'Tools are defined by the wordpress/mcp-adapter package and are the same for all servers. They act as a bridge — AI clients call these tools to discover and execute the WordPress abilities listed in the Abilities tab.', 'acrossai-mcp-manager' )
		);

		echo '</div>';
	}

	/**
	 * Returns the three built-in tools shipped by the wordpress/mcp-adapter package.
	 *
	 * @since 0.0.6
	 * @return array<int, array{name:string, label:string, description:string}>
	 */
	private function get_core_tools(): array {
		return array(
			array(
				'name'        => 'mcp-adapter/discover-abilities',
				'label'       => __( 'Discover Abilities', 'acrossai-mcp-manager' ),
				'description' => __( 'Lists all publicly available WordPress abilities registered on this site. AI clients use this to discover what actions the server can perform.', 'acrossai-mcp-manager' ),
			),
			array(
				'name'        => 'mcp-adapter/get-ability-info',
				'label'       => __( 'Get Ability Info', 'acrossai-mcp-manager' ),
				'description' => __( 'Returns detailed information about a specific ability, including its input/output schema and description. Used by AI clients before executing an ability.', 'acrossai-mcp-manager' ),
			),
			array(
				'name'        => 'mcp-adapter/execute-ability',
				'label'       => __( 'Execute Ability', 'acrossai-mcp-manager' ),
				'description' => __( 'Executes a WordPress ability with the provided input parameters and returns the result. This is the primary tool used by AI clients to interact with WordPress.', 'acrossai-mcp-manager' ),
			),
		);
	}

	/**
	 * Renders the 3-column Tool ID / Name / Description table.
	 *
	 * @since 0.0.6
	 * @param array<int, array{name:string, label:string, description:string}> $tools Tool rows.
	 * @return void
	 */
	private function render_tools_table( array $tools ): void {
		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">';
		echo '<thead><tr>';
		printf( '<th style="width:30%%">%s</th>', esc_html__( 'Tool ID', 'acrossai-mcp-manager' ) );
		printf( '<th style="width:20%%">%s</th>', esc_html__( 'Name', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Description', 'acrossai-mcp-manager' ) );
		echo '</tr></thead><tbody>';
		foreach ( $tools as $tool ) {
			printf(
				'<tr><td><code>%1$s</code></td><td><strong>%2$s</strong></td><td>%3$s</td></tr>',
				esc_html( (string) $tool['name'] ),
				esc_html( (string) $tool['label'] ),
				esc_html( (string) $tool['description'] )
			);
		}
		echo '</tbody></table>';
	}
}
