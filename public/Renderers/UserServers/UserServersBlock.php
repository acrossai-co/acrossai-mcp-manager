<?php
/**
 * Frontend renderer for the F038 [acrossai_mcp_servers] shortcode.
 *
 * Concrete singleton wrapping AbstractUserServersRenderer with the
 * production HTML shape defined in `MCP Servers Widget v2.dc.html` (v3
 * design brief from the `acrossai-mcp-manager.zip` deliverable). `final
 * class` per D36 (extension via `acrossai_mcp_servers_shortcode_html`
 * filter, not subclass). Companion plugins that want their own markup
 * subclass the abstract base directly and render however they want.
 *
 * ## Layout — client-first with scoped server switching
 *
 *   Header (title + summary pill + "Get an Application Password" button)
 *   +-----------------+-------------------------------------------+
 *   | Sidebar         | Selected client detail panel              |
 *   | (transport      |  * head: icon + name + transport + badge  |
 *   |  groups, each   |  * "WHICH SERVER" pill row                |
 *   |  collapsible,   |  * URL row (swaps with server pill)       |
 *   |  clients under) |  * Grid: Config file + Top-level key      |
 *   |                 |  * Code block (swaps with server pill)    |
 *   |                 |    OR OAuth notice for AI connectors      |
 *   |                 |  * Steps (numbered)                       |
 *   +-----------------+-------------------------------------------+
 *
 * Selection state managed by `src/js/frontend.js`. Two independent
 * pickers:
 *
 *  1. `data-amcp-client-select` in the sidebar → toggles which
 *     `[data-amcp-client]` client-panel is `data-active="true"`.
 *  2. `data-amcp-server-select` inside a client-panel → toggles which
 *     `[data-amcp-server]` URL row and code block INSIDE that panel are
 *     visible. Scoped — clicking a server pill in one client-panel does
 *     NOT change the server selection state of other client-panels.
 *
 * ## Assets
 *
 * All CSS lives in `src/scss/frontend.scss` and JS in `src/js/frontend.js`
 * (both bundled with FrontendAuth per the shared `build/{css,js}/frontend.*`
 * entry points defined in `webpack.config.js`). The shortcode enqueues the
 * built handles at render time.
 *
 * ## Trust boundary — filter output not re-sanitized (SEC-002)
 *
 * `acrossai_mcp_servers_shortcode_html` filter output returned verbatim
 * without re-escaping. Listener plugins trusted at the filter boundary.
 * See F037's `acrossai_mcp_embed_render_html` for the same idiom.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public\Renderers\UserServers
 * @since      0.1.11
 * @experimental May change without notice before 1.0.0. See
 *               DEC-CLIENT-RENDERER-PUBLIC-API. `final` per D36.
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Public\Renderers\UserServers;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;

defined( 'ABSPATH' ) || exit;

final class UserServersBlock extends AbstractUserServersRenderer {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $_instance = null;

	/**
	 * Registered handle for the built CSS + JS.
	 *
	 * @var string
	 */
	private const ASSET_HANDLE = 'acrossai-mcp-user-servers';

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.11
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor enforces singleton pattern (S6).
	 */
	private function __construct() {
	}

	/**
	 * Register the [acrossai_mcp_servers] shortcode.
	 *
	 * @since 0.1.11
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		add_shortcode( 'acrossai_mcp_servers', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @since 0.1.11
	 *
	 * @param array|string $atts_raw Shortcode attributes as passed by WP.
	 * @return string Rendered HTML or '' for anonymous.
	 */
	public function render_shortcode( $atts_raw ): string {
		$atts = shortcode_atts(
			array(
				'heading'          => __( 'Connect your AI tools', 'acrossai-mcp-manager' ),
				'show_description' => '1',
				'show_config'      => '1',
				'empty_message'    => __( 'You don\'t have access to any MCP servers yet. Contact your administrator to request access.', 'acrossai-mcp-manager' ),
			),
			is_array( $atts_raw ) ? $atts_raw : array(),
			'acrossai_mcp_servers'
		);

		if ( 0 === get_current_user_id() ) {
			return '';
		}

		$this->enqueue_assets();

		$data = $this->get_accessible_servers();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( empty( $data ) ) {
			$html = $this->render_empty_state( (string) $atts['empty_message'] );
		} else {
			$html = $this->render_widget( $data, $atts );
		}

		/**
		 * Filter the final rendered HTML for the [acrossai_mcp_servers]
		 * shortcode. Consumer contract: MAY wrap / decorate / replace
		 * the assembled HTML. F038 returns the filter result verbatim
		 * without re-escaping — see SEC-002 trust-boundary disclosure
		 * in this class docblock.
		 *
		 * @since 0.1.11
		 *
		 * @param string                           $html Assembled HTML.
		 * @param array<int, array<string, mixed>> $data Server list.
		 * @param array<string, mixed>             $atts Normalized shortcode atts.
		 */
		$html = (string) apply_filters( 'acrossai_mcp_servers_shortcode_html', $html, $data, $atts );

		return $html;
	}

	/**
	 * Enqueue the built CSS + JS handles.
	 *
	 * @since 0.1.11
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		$plugin_dir  = dirname( \ACROSSAI_MCP_MANAGER_PLUGIN_FILE );
		$plugin_file = \ACROSSAI_MCP_MANAGER_PLUGIN_FILE;
		$asset_path  = $plugin_dir . '/build/js/frontend.asset.php';
		$version     = \ACROSSAI_MCP_MANAGER_VERSION;

		if ( is_readable( $asset_path ) ) {
			$asset = require $asset_path;
			if ( is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) && '' !== $asset['version'] ) {
				$version = $asset['version'];
			}
		}

		wp_enqueue_style(
			self::ASSET_HANDLE,
			plugins_url( 'build/css/frontend.css', $plugin_file ),
			array(),
			$version
		);
		wp_style_add_data( self::ASSET_HANDLE, 'rtl', 'replace' );

		wp_enqueue_script(
			self::ASSET_HANDLE,
			plugins_url( 'build/js/frontend.js', $plugin_file ),
			array(),
			$version,
			true
		);
	}

	/**
	 * Render the empty-state card.
	 *
	 * @since 0.1.11
	 *
	 * @param string $empty_message Empty-state body text.
	 * @return string
	 */
	private function render_empty_state( string $empty_message ): string {
		$out  = '<div class="acrossai-mcp-servers acrossai-mcp-servers--empty-shell">';
		$out .= '<div class="acrossai-mcp-servers__empty">';
		$out .= '<div class="acrossai-mcp-servers__empty-icon" aria-hidden="true">';
		$out .= '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="7" rx="2" stroke="#94a3b8" stroke-width="1.8"/><rect x="3" y="14" width="18" height="6" rx="2" stroke="#94a3b8" stroke-width="1.8" stroke-dasharray="3 3"/></svg>';
		$out .= '</div>';
		$out .= '<span class="acrossai-mcp-servers__empty-title">' . esc_html__( 'No MCP servers yet', 'acrossai-mcp-manager' ) . '</span>';
		$out .= '<span class="acrossai-mcp-servers__empty-body">' . esc_html( $empty_message ) . '</span>';
		$out .= '</div>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Build the client-first data structure — union of unique clients
	 * across all accessible servers, keyed by slug. Each client entry
	 * records which servers have it enabled so the "Which server" pill
	 * row can be rendered per client.
	 *
	 * @since 0.1.11
	 *
	 * @param array<int, array<string, mixed>> $data Server list from `get_accessible_servers()`.
	 * @return array<int, array<string, mixed>> Ordered groups; each has
	 *         `key`, `label`, `priority`, `clients[]`. Each client has
	 *         `slug`, `name`, `icon`, `description`, `meta`,
	 *         `transport_key`, `transport_label`, `servers[]` (list of
	 *         `{server_id, server_name, server_slug, server_url}`).
	 */
	private function group_clients_by_transport( array $data ): array {
		$clients_by_slug = array();

		foreach ( $data as $server ) {
			if ( ! is_array( $server ) || ! isset( $server['transports'] ) || ! is_array( $server['transports'] ) ) {
				continue;
			}
			$server_meta = array(
				'server_id'   => isset( $server['server_id'] ) ? (int) $server['server_id'] : 0,
				'server_name' => isset( $server['server_name'] ) && is_string( $server['server_name'] ) ? $server['server_name'] : '',
				'server_slug' => isset( $server['server_slug'] ) && is_string( $server['server_slug'] ) ? $server['server_slug'] : '',
				'server_url'  => isset( $server['server_url'] ) && is_string( $server['server_url'] ) ? $server['server_url'] : '',
			);

			foreach ( $server['transports'] as $transport ) {
				if ( ! is_array( $transport ) || ! isset( $transport['dtos'] ) || ! is_array( $transport['dtos'] ) ) {
					continue;
				}
				$tkey     = isset( $transport['key'] ) && is_string( $transport['key'] ) ? $transport['key'] : '';
				$tlabel   = isset( $transport['label'] ) && is_string( $transport['label'] ) ? $transport['label'] : '';
				$priority = isset( $transport['priority'] ) ? (int) $transport['priority'] : 100;

				foreach ( $transport['dtos'] as $dto ) {
					if ( ! is_array( $dto ) || ! isset( $dto['slug'] ) || ! is_string( $dto['slug'] ) ) {
						continue;
					}
					$slug = $dto['slug'];

					if ( ! isset( $clients_by_slug[ $slug ] ) ) {
						$clients_by_slug[ $slug ] = array(
							'slug'            => $slug,
							'name'            => isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : '',
							'icon'            => isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '',
							'description'     => isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '',
							'meta'            => isset( $dto['meta'] ) && is_array( $dto['meta'] ) ? $dto['meta'] : array(),
							'transport_key'   => $tkey,
							'transport_label' => $tlabel,
							'transport_prio'  => $priority,
							'servers'         => array(),
						);
					}
					$clients_by_slug[ $slug ]['servers'][] = $server_meta;
				}
			}
		}

		// Group by transport, preserving priority order.
		$groups = array();
		foreach ( $clients_by_slug as $client ) {
			$k = $client['transport_key'];
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = array(
					'key'      => $client['transport_key'],
					'label'    => $client['transport_label'],
					'priority' => $client['transport_prio'],
					'clients'  => array(),
				);
			}
			$groups[ $k ]['clients'][] = $client;
		}
		$groups = array_values( $groups );
		usort(
			$groups,
			static function ( array $a, array $b ): int {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $groups;
	}

	/**
	 * Render the whole widget — header + two-column layout.
	 *
	 * @since 0.1.11
	 *
	 * @param array<int, array<string, mixed>> $data Server list from `get_accessible_servers()`.
	 * @param array<string, mixed>             $atts Normalized shortcode atts.
	 * @return string
	 */
	private function render_widget( array $data, array $atts ): string {
		$server_count     = count( $data );
		$groups           = $this->group_clients_by_transport( $data );
		$connection_count = 0;
		foreach ( $groups as $g ) {
			$connection_count += count( $g['clients'] );
		}

		$out = '<div class="acrossai-mcp-servers">';

		// Header.
		$out .= '<div class="acrossai-mcp-servers__header">';
		$out .= '<h2 class="acrossai-mcp-servers__title">' . esc_html( (string) $atts['heading'] ) . '</h2>';
		$out .= '<span class="acrossai-mcp-servers__summary">' . esc_html(
			sprintf(
				/* translators: 1: number of accessible MCP servers, 2: total number of unique connection methods (clients) across those servers. */
				_x( '%1$d servers · %2$d connection methods', 'shortcode header summary', 'acrossai-mcp-manager' ),
				$server_count,
				$connection_count
			)
		) . '</span>';
		$out .= '<span class="acrossai-mcp-servers__header-spacer"></span>';
		$out .= '<a class="acrossai-mcp-servers__password-btn" href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">';
		$out .= '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="10" width="16" height="10" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
		$out .= esc_html__( 'Get an Application Password', 'acrossai-mcp-manager' );
		$out .= '</a>';
		$out .= '</div>';

		// Two-column layout.
		$out .= '<div class="acrossai-mcp-servers__layout">';

		// Sidebar.
		$out .= $this->render_sidebar( $groups );

		// Main — one panel per unique client.
		$out            .= '<div class="acrossai-mcp-servers__main">';
		$is_first_client = true;
		foreach ( $groups as $group ) {
			foreach ( $group['clients'] as $client ) {
				$out            .= $this->render_client_panel( $client, $group, $is_first_client, $atts );
				$is_first_client = false;
			}
		}
		$out .= '</div>';

		$out .= '</div></div>'; // .__layout .__servers.

		return $out;
	}

	/**
	 * Render the sidebar — collapsible transport groups containing
	 * client-nav buttons.
	 *
	 * @since 0.1.11
	 *
	 * @param array<int, array<string, mixed>> $groups Client groups by transport.
	 * @return string
	 */
	private function render_sidebar( array $groups ): string {
		$out             = '<div class="acrossai-mcp-servers__sidebar">';
		$is_first_client = true;
		foreach ( $groups as $group ) {
			$group_clients = $group['clients'];
			$out          .= '<details class="acrossai-mcp-servers__transport-menu"';
			if ( $is_first_client ) {
				// Open the first group (contains the initially-selected client).
				$out .= ' open';
			}
			$out .= ' data-transport-key="' . esc_attr( $group['key'] ) . '">';
			$out .= '<summary class="acrossai-mcp-servers__transport-menu-head">';
			$out .= '<span class="acrossai-mcp-servers__transport-menu-caret" aria-hidden="true">&#9654;</span>';
			$out .= '<span class="acrossai-mcp-servers__transport-menu-name">' . esc_html( $group['label'] ) . '</span>';
			$out .= '<span class="acrossai-mcp-servers__transport-menu-count">' . esc_html( (string) count( $group_clients ) ) . '</span>';
			$out .= '</summary>';
			$out .= '<div class="acrossai-mcp-servers__transport-menu-body">';

			foreach ( $group_clients as $client ) {
				$out .= '<button type="button" class="acrossai-mcp-servers__client-nav"';
				$out .= ' data-amcp-client-select="' . esc_attr( $client['slug'] ) . '"';
				$out .= ' aria-selected="' . ( $is_first_client ? 'true' : 'false' ) . '">';
				$out .= '<span class="acrossai-mcp-servers__client-nav-icon" aria-hidden="true">';
				if ( '' !== $client['icon'] ) {
					if ( self::icon_is_url( $client['icon'] ) ) {
						$out .= '<img src="' . esc_url( $client['icon'] ) . '" alt="" />';
					} else {
						$out .= esc_html( $client['icon'] );
					}
				}
				$out            .= '</span>';
				$out            .= '<span class="acrossai-mcp-servers__client-nav-name">' . esc_html( $client['name'] ) . '</span>';
				$out            .= '</button>';
				$is_first_client = false;
			}

			$out .= '</div></details>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render a single client's detail panel.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $client Client entry.
	 * @param array<string, mixed> $group  Owning transport group entry.
	 * @param bool                 $active Whether this panel is initially visible.
	 * @param array<string, mixed> $atts   Normalized shortcode atts (unused, reserved for future).
	 * @return string
	 */
	private function render_client_panel( array $client, array $group, bool $active, array $atts ): string {
		unset( $atts );

		$slug            = (string) $client['slug'];
		$name            = (string) $client['name'];
		$icon            = (string) $client['icon'];
		$transport_key   = (string) $client['transport_key'];
		$transport_label = (string) $group['label'];
		$badge_label     = $this->tag_for_transport( $transport_key );
		$badge_class     = $this->badge_class_for_transport( $transport_key );
		$servers         = isset( $client['servers'] ) && is_array( $client['servers'] ) ? $client['servers'] : array();

		$out  = '<div class="acrossai-mcp-servers__client-panel"';
		$out .= ' data-amcp-client="' . esc_attr( $slug ) . '"';
		$out .= ' data-category="' . esc_attr( $transport_key ) . '"';
		$out .= ' data-active="' . ( $active ? 'true' : 'false' ) . '"';
		$out .= ' role="tabpanel">';

		// Head.
		$out .= '<div class="acrossai-mcp-servers__client-head">';
		$out .= '<span class="acrossai-mcp-servers__client-head-icon" aria-hidden="true">';
		if ( '' !== $icon ) {
			if ( self::icon_is_url( $icon ) ) {
				$out .= '<img src="' . esc_url( $icon ) . '" alt="" />';
			} else {
				$out .= esc_html( $icon );
			}
		}
		$out .= '</span>';
		$out .= '<div class="acrossai-mcp-servers__client-head-heading">';
		$out .= '<span class="acrossai-mcp-servers__client-head-name">' . esc_html( $name ) . '</span>';
		if ( '' !== $transport_label ) {
			$out .= '<span class="acrossai-mcp-servers__client-head-transport">' . esc_html( $transport_label ) . '</span>';
		}
		$out .= '</div>';
		if ( '' !== $badge_label ) {
			$out .= '<span class="acrossai-mcp-servers__client-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span>';
		}
		$out .= '</div>';

		// Body.
		$out .= '<div class="acrossai-mcp-servers__client-body">';

		// "Which server" pill row + URL rows.
		if ( ! empty( $servers ) ) {
			$out            .= '<div class="acrossai-mcp-servers__server-picker">';
			$out            .= '<span class="acrossai-mcp-servers__section-label">' . esc_html__( 'Which server', 'acrossai-mcp-manager' ) . '</span>';
			$out            .= '<div class="acrossai-mcp-servers__server-pills">';
			$is_first_server = true;
			foreach ( $servers as $srv ) {
				$server_id       = (int) ( $srv['server_id'] ?? 0 );
				$out            .= '<button type="button" class="acrossai-mcp-servers__server-pill"';
				$out            .= ' aria-selected="' . ( $is_first_server ? 'true' : 'false' ) . '"';
				$out            .= ' data-amcp-server-select="' . esc_attr( (string) $server_id ) . '">';
				$out            .= esc_html( (string) ( $srv['server_name'] ?? '' ) );
				$out            .= '</button>';
				$is_first_server = false;
			}
			$out .= '</div>';

			// URL rows — one per server, only active visible.
			$is_first_server = true;
			foreach ( $servers as $srv ) {
				$server_id  = (int) ( $srv['server_id'] ?? 0 );
				$server_url = (string) ( $srv['server_url'] ?? '' );
				if ( '' === $server_url ) {
					continue;
				}
				$url_id          = 'amcp-url-' . $server_id . '-' . sanitize_html_class( $slug );
				$out            .= '<div class="acrossai-mcp-servers__url-row" data-amcp-server="' . esc_attr( (string) $server_id ) . '" data-active="' . ( $is_first_server ? 'true' : 'false' ) . '">';
				$out            .= '<span class="acrossai-mcp-servers__url-label">' . esc_html__( 'URL', 'acrossai-mcp-manager' ) . '</span>';
				$out            .= '<code class="acrossai-mcp-servers__url" id="' . esc_attr( $url_id ) . '">' . esc_html( $server_url ) . '</code>';
				$out            .= '<button type="button" class="acrossai-mcp-servers__copy" data-amcp-copy="#' . esc_attr( $url_id ) . '">' . esc_html__( 'Copy', 'acrossai-mcp-manager' ) . '</button>';
				$out            .= '</div>';
				$is_first_server = false;
			}
			$out .= '</div>'; // .__server-picker.
		}

		// Client-general content — dispatched on transport.
		switch ( $transport_key ) {
			case 'client':
				$out .= $this->render_client_body_content( $client, $servers );
				break;
			case 'npm':
				$out .= $this->render_npm_body_content( $client, $servers );
				break;
			case 'ai_connector':
				$out .= $this->render_ai_connector_body_content( $client );
				break;
			default:
				$out .= $this->render_generic_body( $client );
				break;
		}

		$out .= '</div></div>'; // .__client-body .__client-panel.

		return $out;
	}

	/**
	 * Render client-config body: config file + top-level key grid,
	 * per-server code blocks, steps.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed>             $client  Client entry with `meta.class` FQCN.
	 * @param array<int, array<string, mixed>> $servers Servers this client is enabled on.
	 * @return string
	 */
	private function render_client_body_content( array $client, array $servers ): string {
		$meta = isset( $client['meta'] ) && is_array( $client['meta'] ) ? $client['meta'] : array();
		$fqcn = isset( $meta['class'] ) && is_string( $meta['class'] ) ? $meta['class'] : '';

		if ( '' === $fqcn || ! class_exists( $fqcn ) || ! is_subclass_of( $fqcn, AbstractMCPClient::class ) ) {
			return '';
		}

		/** @var AbstractMCPClient $mcp_client */
		$mcp_client    = new $fqcn();
		$config_file   = $mcp_client->get_config_file();
		$top_level_key = $mcp_client->get_top_level_key();
		$instructions  = $mcp_client->get_instructions();
		$is_toml       = '' !== $config_file && '.toml' === substr( $config_file, -5 );
		$language      = $is_toml ? 'toml' : 'json';
		$slug          = (string) $client['slug'];

		$out = '';

		// Config-file + top-level-key grid (client-general, one copy).
		if ( '' !== $config_file || '' !== $top_level_key ) {
			$out .= '<div class="acrossai-mcp-servers__grid">';
			if ( '' !== $config_file ) {
				$out .= '<div class="acrossai-mcp-servers__field">';
				$out .= '<span class="acrossai-mcp-servers__field-label">' . esc_html__( 'Config file', 'acrossai-mcp-manager' ) . '</span>';
				$out .= '<code class="acrossai-mcp-servers__field-value">' . esc_html( $config_file ) . '</code>';
				$out .= '</div>';
			}
			if ( '' !== $top_level_key ) {
				$out .= '<div class="acrossai-mcp-servers__field acrossai-mcp-servers__field--highlight">';
				$out .= '<span class="acrossai-mcp-servers__field-label">' . esc_html__( 'Top-level key', 'acrossai-mcp-manager' ) . '</span>';
				$out .= '<code class="acrossai-mcp-servers__field-value">' . esc_html( $top_level_key ) . '</code>';
				$out .= '</div>';
			}
			$out .= '</div>';
		}

		// One code block per server, only active visible.
		$is_first_server = true;
		foreach ( $servers as $srv ) {
			$server_id  = (int) ( $srv['server_id'] ?? 0 );
			$server_url = (string) ( $srv['server_url'] ?? '' );

			$snippet_raw = $mcp_client->get_config_snippet( $server_url, AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER );
			$snippet     = is_array( $snippet_raw )
				? (string) wp_json_encode( $snippet_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				: (string) $snippet_raw;
			if ( '' === $snippet ) {
				continue;
			}

			$code_id    = 'amcp-code-' . $server_id . '-' . sanitize_html_class( $slug );
			$line_count = substr_count( $snippet, "\n" ) + 1;

			$out .= '<div class="acrossai-mcp-servers__code" data-amcp-server="' . esc_attr( (string) $server_id ) . '" data-active="' . ( $is_first_server ? 'true' : 'false' ) . '">';
			$out .= '<div class="acrossai-mcp-servers__code-bar">';
			$out .= '<span class="acrossai-mcp-servers__lang">' . esc_html( $language ) . '</span>';
			$out .= '<span class="acrossai-mcp-servers__line-count">' . esc_html(
				sprintf(
					/* translators: %d — number of lines in the config snippet. */
					_n( '%d line', '%d lines', $line_count, 'acrossai-mcp-manager' ),
					$line_count
				)
			) . '</span>';
			$out .= '<button type="button" class="acrossai-mcp-servers__copy acrossai-mcp-servers__copy--ondark" data-amcp-copy="#' . esc_attr( $code_id ) . '">' . esc_html__( 'Copy', 'acrossai-mcp-manager' ) . '</button>';
			$out .= '</div>';
			$out .= '<pre class="acrossai-mcp-servers__pre"><code id="' . esc_attr( $code_id ) . '">' . esc_html( $snippet ) . '</code></pre>';
			$out .= '</div>';

			$is_first_server = false;
		}

		$out .= $this->render_steps( $instructions, true );

		return $out;
	}

	/**
	 * Render npm body: per-server bash code block + steps.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed>             $client  Client entry with `meta.command_template`.
	 * @param array<int, array<string, mixed>> $servers Servers this client is enabled on.
	 * @return string
	 */
	private function render_npm_body_content( array $client, array $servers ): string {
		$meta     = isset( $client['meta'] ) && is_array( $client['meta'] ) ? $client['meta'] : array();
		$template = isset( $meta['command_template'] ) && is_string( $meta['command_template'] ) ? $meta['command_template'] : '';
		$slug     = (string) $client['slug'];

		if ( '' === $template ) {
			return '';
		}

		$out = '';

		$is_first_server = true;
		foreach ( $servers as $srv ) {
			$server_id   = (int) ( $srv['server_id'] ?? 0 );
			$server_slug = (string) ( $srv['server_slug'] ?? '' );
			$command     = sprintf( $template, home_url(), $server_slug );
			$code_id     = 'amcp-code-' . $server_id . '-' . sanitize_html_class( $slug );
			$line_count  = substr_count( $command, "\n" ) + 1;

			$out .= '<div class="acrossai-mcp-servers__code" data-amcp-server="' . esc_attr( (string) $server_id ) . '" data-active="' . ( $is_first_server ? 'true' : 'false' ) . '">';
			$out .= '<div class="acrossai-mcp-servers__code-bar">';
			$out .= '<span class="acrossai-mcp-servers__lang">bash</span>';
			$out .= '<span class="acrossai-mcp-servers__line-count">' . esc_html(
				sprintf(
					/* translators: %d — number of lines in the command. */
					_n( '%d line', '%d lines', $line_count, 'acrossai-mcp-manager' ),
					$line_count
				)
			) . '</span>';
			$out .= '<button type="button" class="acrossai-mcp-servers__copy acrossai-mcp-servers__copy--ondark" data-amcp-copy="#' . esc_attr( $code_id ) . '">' . esc_html__( 'Copy', 'acrossai-mcp-manager' ) . '</button>';
			$out .= '</div>';
			$out .= '<pre class="acrossai-mcp-servers__pre"><code id="' . esc_attr( $code_id ) . '">' . esc_html( $command ) . '</code></pre>';
			$out .= '</div>';

			$is_first_server = false;
		}

		$description = (string) $client['description'];
		$out        .= $this->render_steps( $description, false );

		return $out;
	}

	/**
	 * Render AI connector body: OAuth notice + steps.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $client Client entry.
	 * @return string
	 */
	private function render_ai_connector_body_content( array $client ): string {
		$out  = '<div class="acrossai-mcp-servers__oauth-notice">';
		$out .= '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 11v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="7.7" r="1.05" fill="currentColor"/></svg>';
		$out .= '<span>' . esc_html(
			sprintf(
				/* translators: %s — connector name (e.g. "ChatGPT", "Claude", "Grok"). */
				__( 'No local config to paste. %s authorizes over OAuth on the provider\'s side — you\'ll be redirected here to approve access, so your Application Password never leaves WordPress.', 'acrossai-mcp-manager' ),
				(string) $client['name']
			)
		) . '</span>';
		$out .= '</div>';

		$description = (string) $client['description'];
		$out        .= $this->render_steps( $description, false );

		return $out;
	}

	/**
	 * Fallback body for third-party transport categories.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $client Client entry.
	 * @return string
	 */
	private function render_generic_body( array $client ): string {
		$description = (string) $client['description'];
		if ( '' === $description ) {
			return '';
		}
		return '<div class="acrossai-mcp-servers__oauth-notice">' . esc_html( $description ) . '</div>';
	}

	/**
	 * Render a numbered steps grid from a string. Splits on ` → ` (F034
	 * separator convention). Optional replace-pill on the STEPS label.
	 *
	 * @since 0.1.11
	 *
	 * @param string $instructions      Translated instructions string.
	 * @param bool   $with_replace_pill Whether to render the amber "replace" pill.
	 * @return string
	 */
	private function render_steps( string $instructions, bool $with_replace_pill ): string {
		if ( '' === $instructions ) {
			return '';
		}

		$split = preg_split( '/\s*(?:→|\x{2192})\s*/u', $instructions );
		if ( false === $split ) {
			$split = array( $instructions );
		}
		$parts = array_map( 'trim', $split );
		$parts = array_values( array_filter( $parts, static fn( string $p ): bool => '' !== $p ) );
		if ( empty( $parts ) ) {
			return '';
		}

		$out  = '<div class="acrossai-mcp-servers__steps-block">';
		$out .= '<div class="acrossai-mcp-servers__steps-header">';
		$out .= '<span class="acrossai-mcp-servers__section-label">' . esc_html__( 'Steps', 'acrossai-mcp-manager' ) . '</span>';
		if ( $with_replace_pill ) {
			$out .= '<span class="acrossai-mcp-servers__replace-pill">';
			$out .= '<span class="acrossai-mcp-servers__replace-dot" aria-hidden="true"></span>';
			$out .= sprintf(
				/* translators: %s — <code>-wrapped placeholder token that must be replaced by an Application Password. */
				esc_html__( 'replace %s', 'acrossai-mcp-manager' ),
				'<code>' . esc_html( AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER ) . '</code>'
			);
			$out .= '</span>';
		}
		$out .= '</div>';
		$out .= '<div class="acrossai-mcp-servers__steps-grid">';
		$i    = 1;
		foreach ( $parts as $step ) {
			$out .= '<div class="acrossai-mcp-servers__step">';
			$out .= '<span class="acrossai-mcp-servers__step-num">' . esc_html( (string) $i ) . '</span>';
			$out .= '<span class="acrossai-mcp-servers__step-text">' . esc_html( rtrim( $step, '.' ) ) . '</span>';
			$out .= '</div>';
			++$i;
		}
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Map a transport-category machine key to the CSS badge modifier class.
	 *
	 * @since 0.1.11
	 *
	 * @param string $transport_key Transport machine key.
	 * @return string
	 */
	private function badge_class_for_transport( string $transport_key ): string {
		switch ( $transport_key ) {
			case 'client':
				return 'acrossai-mcp-servers__client-badge--config';
			case 'npm':
				return 'acrossai-mcp-servers__client-badge--cli';
			case 'ai_connector':
				return 'acrossai-mcp-servers__client-badge--oauth';
			default:
				return '';
		}
	}

	/**
	 * Map a transport-category machine key to the human-readable badge label.
	 *
	 * @since 0.1.11
	 *
	 * @param string $transport_key Transport machine key.
	 * @return string
	 */
	private function tag_for_transport( string $transport_key ): string {
		switch ( $transport_key ) {
			case 'client':
				return __( 'Local config', 'acrossai-mcp-manager' );
			case 'npm':
				return __( 'CLI command', 'acrossai-mcp-manager' );
			case 'ai_connector':
				return __( 'AI Connector · OAuth', 'acrossai-mcp-manager' );
			default:
				return '';
		}
	}

	/**
	 * Detect whether an icon string is an http(s) URL. Whitelist approach.
	 *
	 * @since 0.1.11
	 *
	 * @param string $icon Icon value from the DTO.
	 * @return bool
	 */
	private static function icon_is_url( string $icon ): bool {
		return 0 === strpos( $icon, 'http://' ) || 0 === strpos( $icon, 'https://' );
	}
}
