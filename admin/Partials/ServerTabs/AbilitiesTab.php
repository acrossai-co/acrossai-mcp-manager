<?php
/**
 * The Abilities tab — WordPress Abilities API surface for this server.
 *
 * Feature 013 — port of reference plugin's render_abilities_tab
 * (src/Admin/Settings.php:1981–2134). Splits registered abilities into
 * two tables: MCP-Exposed (mcp.public === true) and Other Registered.
 *
 * Guards on function_exists('wp_get_abilities') per FR-005 — the API is
 * provided by an optional sibling plugin or vendor package.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Abilities tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class AbilitiesTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'abilities';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Abilities', 'acrossai-mcp-manager' );
	}

	/**
	 * Renders the abilities surface — gated on function_exists('wp_get_abilities').
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$enabled     = ! empty( $server['is_enabled'] );
		$server_slug = (string) ( $server['server_slug'] ?? '' );

		echo '<div class="mcp-tab-panel">';
		printf( '<h2>%s</h2>', esc_html__( 'WordPress Abilities', 'acrossai-mcp-manager' ) );

		if ( ! $enabled ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Server is disabled.', 'acrossai-mcp-manager' ),
				esc_html__( 'Enable the server on the Overview tab to expose these abilities to MCP clients.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'The WordPress Abilities API is not available on this installation.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		$abilities                        = \wp_get_abilities();
		list( $mcp_public, $mcp_private ) = $this->partition_abilities( $abilities, $server_slug );

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: count of MCP-exposed abilities, 2: total count */
					__( '%1$d of %2$d registered abilities are exposed on this server.', 'acrossai-mcp-manager' ),
					count( $mcp_public ),
					count( $abilities )
				)
			)
		);

		if ( empty( $abilities ) ) {
			printf(
				'<div class="notice notice-info inline" style="margin-top:12px;"><p>%s</p></div>',
				esc_html__( 'No abilities are registered yet. Abilities are registered by plugins and themes using the WordPress Abilities API.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		$this->render_public_table( $mcp_public );
		$this->render_private_table( $mcp_private );

		echo '</div>';
	}

	/**
	 * Partitions abilities into MCP-exposed vs private, applying the
	 * Abilities Manager per-server allowlist when the vendor plugin is active.
	 *
	 * @since 0.0.6
	 * @param array<int, \WP_Ability> $abilities   Ability instances from wp_get_abilities().
	 * @param string                  $server_slug Current server slug.
	 * @return array{0: array<int, \WP_Ability>, 1: array<int, \WP_Ability>}
	 */
	private function partition_abilities( array $abilities, string $server_slug ): array {
		$has_manager = class_exists( '\AcrossAI_Abilities_Manager\Runtime\Override_Applier' );
		$mcp_public  = array();
		$mcp_private = array();

		foreach ( $abilities as $ability ) {
			$meta      = $ability->get_meta();
			$slug      = $ability->get_name();
			$is_public = ! empty( $meta['mcp']['public'] );

			if ( $is_public && $has_manager && '' !== $server_slug ) {
				if ( \AcrossAI_Abilities_Manager\Runtime\Override_Applier::has_server_restriction( $slug ) ) {
					$is_public = \AcrossAI_Abilities_Manager\Runtime\Override_Applier::should_expose_to_mcp_server( $slug, $server_slug );
				}
			}

			if ( $is_public ) {
				$mcp_public[] = $ability;
			} else {
				$mcp_private[] = $ability;
			}
		}

		return array( $mcp_public, $mcp_private );
	}

	/**
	 * Renders the "MCP-Exposed Abilities" table.
	 *
	 * @since 0.0.6
	 * @param array<int, \WP_Ability> $mcp_public MCP-exposed abilities.
	 * @return void
	 */
	private function render_public_table( array $mcp_public ): void {
		printf(
			'<h3 style="margin-top:20px;">%s</h3>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'MCP-Exposed Abilities (%d)', 'acrossai-mcp-manager' ),
					count( $mcp_public )
				)
			)
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'These abilities are visible and executable by connected AI clients.', 'acrossai-mcp-manager' )
		);

		if ( empty( $mcp_public ) ) {
			printf(
				'<p class="description" style="margin-top:8px;font-style:italic;">%s</p>',
				esc_html__( 'None. Set mcp.public = true in an ability\'s meta to expose it.', 'acrossai-mcp-manager' )
			);
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">';
		echo '<thead><tr>';
		printf( '<th style="width:30%%">%s</th>', esc_html__( 'Ability Name', 'acrossai-mcp-manager' ) );
		printf( '<th style="width:20%%">%s</th>', esc_html__( 'Label', 'acrossai-mcp-manager' ) );
		printf( '<th style="width:12%%">%s</th>', esc_html__( 'Type', 'acrossai-mcp-manager' ) );
		printf( '<th style="width:12%%">%s</th>', esc_html__( 'Category', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Description', 'acrossai-mcp-manager' ) );
		echo '</tr></thead><tbody>';
		foreach ( $mcp_public as $ability ) {
			$meta     = $ability->get_meta();
			$mcp_type = isset( $meta['mcp']['type'] ) ? (string) $meta['mcp']['type'] : 'tool';
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td><span class="acrossai-source-badge acrossai-source-%3$s">%4$s</span></td><td><code>%5$s</code></td><td>%6$s</td></tr>',
				esc_html( $ability->get_name() ),
				esc_html( $ability->get_label() ),
				esc_attr( sanitize_html_class( $mcp_type ) ),
				esc_html( ucfirst( $mcp_type ) ),
				esc_html( $ability->get_category() ),
				esc_html( $ability->get_description() )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the "Other Registered Abilities" table.
	 *
	 * @since 0.0.6
	 * @param array<int, \WP_Ability> $mcp_private Private abilities.
	 * @return void
	 */
	private function render_private_table( array $mcp_private ): void {
		if ( empty( $mcp_private ) ) {
			return;
		}

		printf(
			'<h3 style="margin-top:24px;">%s</h3>',
			esc_html(
				sprintf(
					/* translators: %d: count */
					__( 'Other Registered Abilities (%d)', 'acrossai-mcp-manager' ),
					count( $mcp_private )
				)
			)
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'These abilities are registered but not exposed via MCP (mcp.public is not set).', 'acrossai-mcp-manager' )
		);

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:8px;">';
		echo '<thead><tr>';
		printf( '<th style="width:30%%">%s</th>', esc_html__( 'Ability Name', 'acrossai-mcp-manager' ) );
		printf( '<th style="width:20%%">%s</th>', esc_html__( 'Label', 'acrossai-mcp-manager' ) );
		printf( '<th style="width:12%%">%s</th>', esc_html__( 'Category', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Description', 'acrossai-mcp-manager' ) );
		echo '</tr></thead><tbody>';
		foreach ( $mcp_private as $ability ) {
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td><code>%3$s</code></td><td>%4$s</td></tr>',
				esc_html( $ability->get_name() ),
				esc_html( $ability->get_label() ),
				esc_html( $ability->get_category() ),
				esc_html( $ability->get_description() )
			);
		}
		echo '</tbody></table>';
	}
}
