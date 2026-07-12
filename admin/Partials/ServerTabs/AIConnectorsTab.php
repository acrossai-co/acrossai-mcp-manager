<?php
/**
 * AI Connectors tab — built-in per-server tab (priority 35).
 *
 * Renders one card per registered AbstractConnectorProfile with a
 * Generate/Regenerate button. Contribution of profiles happens ONLY via
 * the `acrossai_mcp_manager_connector_profiles` filter — the base plugin
 * ships zero profiles.
 *
 * **This is a BUILT-IN tab wired directly in Registry::all_tabs().**
 * Third-party tabs use the `acrossai_mcp_manager_server_tabs` filter — see
 * docs/extending-per-server-tabs.md. Do not convert this tab to
 * filter-registered without a major version bump
 * (DEC-OAUTH-BUILTIN-TAB-NOT-FILTER).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.1.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The AI Connectors tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.1.0
 */
final class AIConnectorsTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function slug(): string {
		return 'ai-connectors';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function label(): string {
		return __( 'AI Connectors', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot 35 — between ClientsTab (30) and WpCliTab (40).
	 *
	 * @since 0.1.0
	 * @return int
	 */
	public function priority(): int {
		return 35;
	}

	/**
	 * Renders the tab body. Empty-state notice when no profiles are
	 * registered; otherwise a bare wrapper that delegates ALL per-profile
	 * rendering (icon, heading, credentials, action buttons, setup
	 * instructions) to each companion plugin's
	 * `AbstractConnectorProfile::render_tab_section`.
	 *
	 * The wrapper exposes `data-server-id` + `data-wp-rest-nonce` so
	 * companion-plugin JS can find them via `.closest()` without needing
	 * its own localize + enqueue coordination with the base plugin.
	 *
	 * @since 0.1.0
	 * @param array<string, mixed> $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$profiles = ConnectorProfileRegistry::instance()->get_profiles();

		if ( empty( $profiles ) ) {
			$this->render_empty_state();
			return;
		}

		$server_id = (int) ( $server['id'] ?? 0 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$selected_slug  = isset( $_GET['connector'] ) ? sanitize_key( wp_unslash( (string) $_GET['connector'] ) ) : '';
		$selected_panel = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( (string) $_GET['panel'] ) ) : '';
		// phpcs:enable

		$slug_index = array();
		foreach ( $profiles as $profile ) {
			$slug_index[ $profile->get_slug() ] = $profile;
		}

		if ( '' === $selected_slug || ! isset( $slug_index[ $selected_slug ] ) ) {
			$selected_slug = (string) array_key_first( $slug_index );
		}
		if ( ! in_array( $selected_panel, array( 'generate', 'connections', 'settings' ), true ) ) {
			$selected_panel = 'generate';
		}

		$active_profile = $slug_index[ $selected_slug ];

		echo '<div class="acrossai-mcp-ai-connectors" data-server-id="' . esc_attr( (string) $server_id ) . '" data-wp-rest-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';

		$this->render_level2_bar( $server, $profiles, $selected_slug );
		$this->render_level3_bar( $server, $selected_slug, $selected_panel );
		$this->render_panel( $server, $active_profile, $selected_panel );

		echo '</div>';
	}

	/**
	 * Render Level 2 sub-tab bar — one tab per connector profile.
	 *
	 * @param array<string, mixed>                                                           $server        Server row.
	 * @param array<int, \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile> $profiles      Profiles.
	 * @param string                                                                         $selected_slug Currently selected connector slug.
	 * @return void
	 */
	private function render_level2_bar( array $server, array $profiles, string $selected_slug ): void {
		echo '<nav class="nav-tab-wrapper acrossai-mcp-ai-connectors__level2">';
		foreach ( $profiles as $profile ) {
			$slug   = $profile->get_slug();
			$url    = $this->panel_url( $server, $slug, 'generate' );
			$active = $slug === $selected_slug ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $url ),
				esc_attr( $active ),
				esc_html( $profile->get_name() )
			);
		}
		echo '</nav>';
	}

	/**
	 * Render Level 3 panel bar — Generate | Connections | Settings.
	 *
	 * @param array<string, mixed> $server         Server row.
	 * @param string               $selected_slug  Selected connector slug.
	 * @param string               $selected_panel Selected panel.
	 * @return void
	 */
	private function render_level3_bar( array $server, string $selected_slug, string $selected_panel ): void {
		$panels = array(
			'generate'    => __( 'Generate', 'acrossai-mcp-manager' ),
			'connections' => __( 'Connections', 'acrossai-mcp-manager' ),
			'settings'    => __( 'Settings', 'acrossai-mcp-manager' ),
		);
		echo '<nav class="nav-tab-wrapper acrossai-mcp-ai-connectors__level3">';
		foreach ( $panels as $slug => $label ) {
			$active = $slug === $selected_panel ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $this->panel_url( $server, $selected_slug, $slug ) ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Dispatch to the active panel renderer.
	 *
	 * @param array<string, mixed>                                               $server  Server row.
	 * @param \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile $profile Active profile.
	 * @param string                                                             $panel   Panel slug.
	 * @return void
	 */
	private function render_panel( array $server, $profile, string $panel ): void {
		echo '<div class="acrossai-mcp-ai-connectors__panel acrossai-mcp-ai-connectors__panel--' . esc_attr( $panel ) . '" data-acrossai-connector-slug="' . esc_attr( $profile->get_slug() ) . '">';

		switch ( $panel ) {
			case 'connections':
				$this->render_connections_panel( $server, $profile );
				break;
			case 'settings':
				$this->render_settings_panel( $server, $profile );
				break;
			case 'generate':
			default:
				$this->render_generate_panel( $server, $profile );
		}

		echo '</div>';
	}

	/**
	 * Build a panel URL preserving existing query args.
	 *
	 * @param array<string, mixed> $server    Server row.
	 * @param string               $slug      Connector slug.
	 * @param string               $panel     Panel slug.
	 * @return string
	 */
	private function panel_url( array $server, string $slug, string $panel ): string {
		$args = array(
			'page'      => 'acrossai_mcp_manager',
			'action'    => 'edit',
			'server'    => (int) ( $server['id'] ?? 0 ),
			'tab'       => 'ai-connectors',
			'connector' => $slug,
			'panel'     => $panel,
		);
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Generate panel — MCP URL + setup instructions + Advanced fallback.
	 *
	 * @param array<string, mixed>                                               $server  Server row.
	 * @param \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile $profile Active profile.
	 * @return void
	 */
	private function render_generate_panel( array $server, $profile ): void {
		// F011 CliController pattern: rest_url( namespace . '/' . route ).
		$namespace = isset( $server['server_route_namespace'] ) && '' !== $server['server_route_namespace']
			? (string) $server['server_route_namespace']
			: 'mcp';
		$route     = isset( $server['server_route'] ) ? (string) $server['server_route'] : '';
		$mcp_url   = '' !== $route ? rest_url( trailingslashit( $namespace ) . $route ) : rest_url( $namespace );

		echo '<div class="acrossai-mcp-connector-panel">';

		printf( '<h3 class="acrossai-mcp-connector-panel__title">%s</h3>', esc_html__( 'Connect your AI client', 'acrossai-mcp-manager' ) );

		echo '<p class="acrossai-mcp-connector-panel__label">' . esc_html__( 'MCP URL to paste into your AI client:', 'acrossai-mcp-manager' ) . '</p>';
		echo '<div class="acrossai-mcp-connector__copy-row">';
		printf(
			'<input type="text" class="acrossai-mcp-connector__input regular-text code" value="%s" readonly>',
			esc_attr( $mcp_url )
		);
		printf(
			'<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-copy="mcp-url">%s</button>',
			esc_html__( 'Copy', 'acrossai-mcp-manager' )
		);
		echo '</div>';

		echo '<div class="acrossai-mcp-connector-panel__setup">';
		echo wp_kses_post( $profile->get_mcp_url_setup_html( $mcp_url ) );
		echo '</div>';

		echo '<details class="acrossai-mcp-connector-panel__advanced">';
		printf( '<summary>%s</summary>', esc_html__( 'Advanced: pre-generate credentials manually', 'acrossai-mcp-manager' ) );
		echo '<div class="acrossai-mcp-connector-panel__advanced-body">';
		echo '<p class="description">' . esc_html__( 'Most modern AI clients (Claude Desktop, ChatGPT, Cursor, Gemini) support Dynamic Client Registration and will handle credentials automatically when you paste the URL above. Only use this if your AI client does NOT support DCR.', 'acrossai-mcp-manager' ) . '</p>';
		$profile->render_tab_section( $server );
		echo '</div>';
		echo '</details>';

		echo '</div>';
	}

	/**
	 * Connections panel — table of every OAuth client for this (server, connector).
	 *
	 * @param array<string, mixed>                                               $server  Server row.
	 * @param \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile $profile Active profile.
	 * @return void
	 */
	private function render_connections_panel( array $server, $profile ): void {
		$server_id = (int) ( $server['id'] ?? 0 );
		$slug      = $profile->get_slug();

		$admin_rows = ClientRepository::find_admin_for_server_connector( $server_id, $slug );
		$dcr_rows   = array();
		foreach ( ClientRepository::find_dcr_all() as $dcr_row ) {
			if ( $profile->matches_dcr_client( (string) $dcr_row->client_name, $dcr_row->decoded_redirect_uris() ) ) {
				$dcr_rows[] = $dcr_row;
			}
		}
		$all_rows = array_merge( $admin_rows, $dcr_rows );

		echo '<div class="acrossai-mcp-connector-panel">';
		printf( '<h3 class="acrossai-mcp-connector-panel__title">%s</h3>', esc_html__( 'Active connections', 'acrossai-mcp-manager' ) );

		if ( empty( $all_rows ) ) {
			printf(
				'<p class="acrossai-mcp-connector-panel__empty description">%s</p>',
				sprintf(
					/* translators: %s: connector display name */
					esc_html__( 'No AI clients have connected via %s yet.', 'acrossai-mcp-manager' ),
					esc_html( $profile->get_name() )
				)
			);
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped acrossai-mcp-connector-panel__table">';
		echo '<thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Client ID', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Client name', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Registered via', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Active tokens', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Users', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Issued at', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Actions', 'acrossai-mcp-manager' ) );
		echo '</tr></thead><tbody>';

		foreach ( $all_rows as $row ) {
			$active_count = AccessTokenRepository::count_active_by_client_id( (string) $row->client_id );
			$user_ids     = AccessTokenRepository::get_active_user_ids_by_client_id( (string) $row->client_id );
			$user_names   = array();
			foreach ( $user_ids as $uid ) {
				$user = get_userdata( (int) $uid );
				if ( $user instanceof \WP_User ) {
					$user_names[] = sprintf( '%s (#%d)', $user->user_login, (int) $uid );
				}
			}
			$registered = 0 === strpos( (string) $row->client_id, 'server-' ) ? __( 'Admin', 'acrossai-mcp-manager' ) : __( 'DCR', 'acrossai-mcp-manager' );

			echo '<tr>';
			printf( '<td><code>%s</code></td>', esc_html( (string) $row->client_id ) );
			printf( '<td>%s</td>', esc_html( '' !== $row->client_name ? $row->client_name : '—' ) );
			printf( '<td>%s</td>', esc_html( $registered ) );
			printf( '<td>%d</td>', (int) $active_count );
			printf( '<td>%s</td>', esc_html( ! empty( $user_names ) ? implode( ', ', $user_names ) : '—' ) );
			printf( '<td>%s</td>', esc_html( (string) $row->created_at ) );
			printf(
				'<td><button type="button" class="button-link acrossai-mcp-connector-panel__revoke-btn" data-acrossai-client-id="%1$s">%2$s</button> | <button type="button" class="button-link-delete acrossai-mcp-connector-panel__delete-btn" data-acrossai-client-id="%1$s">%3$s</button></td>',
				esc_attr( (string) $row->client_id ),
				esc_html__( 'Revoke tokens', 'acrossai-mcp-manager' ),
				esc_html__( 'Delete client', 'acrossai-mcp-manager' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Settings panel — enable/require-approval + revoke-all button + pending approval list.
	 *
	 * @param array<string, mixed>                                               $server  Server row.
	 * @param \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile $profile Active profile.
	 * @return void
	 */
	private function render_settings_panel( array $server, $profile ): void {
		$server_id = (int) ( $server['id'] ?? 0 );
		$slug      = $profile->get_slug();
		$settings  = \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::get( $server_id, $slug );
		$pending   = \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::pending_user_ids( $server_id, $slug );

		echo '<div class="acrossai-mcp-connector-panel">';
		printf( '<h3 class="acrossai-mcp-connector-panel__title">%s</h3>', esc_html__( 'Settings', 'acrossai-mcp-manager' ) );

		echo '<form class="acrossai-mcp-connector-panel__settings-form" data-acrossai-connector-slug="' . esc_attr( $slug ) . '">';

		echo '<p><label>';
		printf(
			'<input type="checkbox" name="enabled" value="1" %s> <strong>%s</strong>',
			checked( $settings['enabled'], true, false ),
			esc_html__( 'Enable this connector on this server', 'acrossai-mcp-manager' )
		);
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'When disabled, every active token for this connector on this server is immediately revoked.', 'acrossai-mcp-manager' ) . '</p>';

		echo '<p><label>';
		printf(
			'<input type="checkbox" name="require_admin_approval" value="1" %s> <strong>%s</strong>',
			checked( $settings['require_admin_approval'], true, false ),
			esc_html__( 'Require admin approval for new connections', 'acrossai-mcp-manager' )
		);
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'When enabled, a user must be pre-approved by an admin before they can complete the OAuth consent flow.', 'acrossai-mcp-manager' ) . '</p>';

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save settings', 'acrossai-mcp-manager' )
		);
		echo '</form>';

		echo '<hr>';

		if ( ! empty( $pending ) ) {
			printf( '<h4>%s</h4>', esc_html__( 'Pending approvals', 'acrossai-mcp-manager' ) );
			echo '<ul class="acrossai-mcp-connector-panel__pending-list">';
			foreach ( $pending as $pending_user_id ) {
				$user = get_user_by( 'id', (int) $pending_user_id );
				if ( ! $user ) {
					continue;
				}
				printf(
					'<li>%1$s (<code>#%2$d</code>) <button type="button" class="button button-small acrossai-mcp-connector-panel__approve-btn" data-acrossai-connector-slug="%3$s" data-acrossai-user-id="%2$d">%4$s</button></li>',
					esc_html( $user->display_name ),
					(int) $pending_user_id,
					esc_attr( $slug ),
					esc_html__( 'Approve', 'acrossai-mcp-manager' )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p><button type="button" class="button button-secondary acrossai-mcp-connector-panel__nuclear-btn" data-acrossai-connector-slug="%s" data-acrossai-confirm="%s">%s</button></p>',
			esc_attr( $slug ),
			esc_attr__( 'Revoke every active token for this connector on this server? This cannot be undone.', 'acrossai-mcp-manager' ),
			esc_html__( 'Revoke all connections for this connector', 'acrossai-mcp-manager' )
		);

		echo '</div>';
	}

	/**
	 * Empty state — centered card matching the AcrossAI Ability Library
	 * pattern (circular icon, heading, description, primary button,
	 * dashed separator, tip). Emits self-scoped CSS so this tab doesn't
	 * depend on an external stylesheet.
	 *
	 * @return void
	 */
	private function render_empty_state(): void {
		$addons_url = admin_url( 'admin.php?page=acrossai-addons' );
		$docs_url   = 'https://github.com/acrossai-co/acrossai-mcp-manager/blob/main/docs/extending-connector-profiles.md';

		?>
		<style>
			.acrossai-mcp-empty {
				display: flex;
				justify-content: center;
				padding: 40px 20px;
			}
			.acrossai-mcp-empty__card {
				box-sizing: border-box;
				max-width: 620px;
				width: 100%;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 40px 32px 32px;
				text-align: center;
				box-shadow: 0 1px 2px rgba( 0, 0, 0, .04 );
			}
			.acrossai-mcp-empty__icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 64px;
				height: 64px;
				background: #eef4fb;
				border-radius: 50%;
				color: #2271b1;
				margin: 0 auto 20px;
			}
			.acrossai-mcp-empty__heading {
				margin: 0 0 12px;
				font-size: 20px;
				font-weight: 600;
				color: #1d2327;
			}
			.acrossai-mcp-empty__body {
				margin: 0 auto 24px;
				max-width: 460px;
				color: #50575e;
				line-height: 1.55;
			}
			.acrossai-mcp-empty__button {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				padding: 8px 18px !important;
				height: auto !important;
				line-height: 1.4 !important;
			}
			.acrossai-mcp-empty__button-icon {
				width: 14px;
				height: 14px;
				flex-shrink: 0;
			}
			.acrossai-mcp-empty__divider {
				border: none;
				border-top: 1px dashed #dcdcde;
				margin: 28px 0 20px;
			}
			.acrossai-mcp-empty__tip {
				margin: 0;
				font-size: 13px;
				color: #646970;
				line-height: 1.5;
			}
			.acrossai-mcp-empty__tip a {
				color: #2271b1;
				text-decoration: none;
			}
			.acrossai-mcp-empty__tip a:hover,
			.acrossai-mcp-empty__tip a:focus {
				text-decoration: underline;
			}
		</style>
		<div class="acrossai-mcp-empty" role="status" aria-live="polite">
			<div class="acrossai-mcp-empty__card">
				<div class="acrossai-mcp-empty__icon" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M9 2v6"/>
						<path d="M15 2v6"/>
						<path d="M12 17.5V22"/>
						<path d="M5 8h14a2 2 0 0 1 2 2v2a7 7 0 0 1-14 0v-2a2 2 0 0 1 2-2z"/>
					</svg>
				</div>

				<h2 class="acrossai-mcp-empty__heading">
					<?php esc_html_e( 'No AI connectors registered yet', 'acrossai-mcp-manager' ); ?>
				</h2>

				<p class="acrossai-mcp-empty__body">
					<?php
					esc_html_e(
						'Connector profiles are provided by AcrossAI add-ons. Install and activate an add-on such as "AcrossAI Claude Connectors" to see connector cards appear on this tab.',
						'acrossai-mcp-manager'
					);
					?>
				</p>

				<a class="button button-primary acrossai-mcp-empty__button" href="<?php echo esc_url( $addons_url ); ?>">
					<?php esc_html_e( 'Browse add-ons', 'acrossai-mcp-manager' ); ?>
					<svg class="acrossai-mcp-empty__button-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
						<polyline points="15 3 21 3 21 9"/>
						<line x1="10" y1="14" x2="21" y2="3"/>
					</svg>
				</a>

				<hr class="acrossai-mcp-empty__divider">

				<p class="acrossai-mcp-empty__tip">
					<?php
					printf(
						/* translators: %s: link to the docs on writing a connector profile plugin */
						esc_html__( 'Tip: open the Add-ons page and install "AcrossAI Claude Connectors" (or any other connector plugin you need) to populate this tab. Companion plugins register profiles via the %s.', 'acrossai-mcp-manager' ),
						'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'connector-profile filter', 'acrossai-mcp-manager' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
