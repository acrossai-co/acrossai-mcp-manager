<?php
/**
 * Frontend renderer for the F038 [acrossai_mcp_servers] shortcode.
 *
 * Concrete singleton wrapping AbstractUserServersRenderer with the
 * production HTML shape defined in `shortcode-output.html` (design brief
 * from the `acrossai-mcp-manager.zip` deliverable). `final class` per D36
 * (extension via `acrossai_mcp_servers_shortcode_html` filter, not
 * subclass). Companion plugins that want their own markup subclass the
 * abstract base directly and render however they want.
 *
 * ## Assets
 *
 * All CSS lives in `src/scss/frontend.scss` and JS in `src/js/frontend.js`
 * (both bundled with FrontendAuth per the shared `build/{css,js}/frontend.*`
 * entry points defined in `webpack.config.js`). The shortcode enqueues the
 * built handles at render time — lazy loading (no site-wide enqueue,
 * matches how WordPress core recommends shortcode assets).
 *
 * ## Trust boundary — filter output not re-sanitized (SEC-002)
 *
 * The `acrossai_mcp_servers_shortcode_html` filter is fired on the
 * assembled HTML just before return. F038 returns the filter result
 * verbatim WITHOUT re-escaping — listener plugins are trusted at the
 * filter boundary. A malicious or buggy listener can introduce XSS by
 * returning attacker-controlled HTML. This matches WordPress's standard
 * filter idiom (F037's `acrossai_mcp_embed_render_html` has the same
 * shape). Operators who install untrusted plugins that hook this filter
 * accept the same risk they accept anywhere in the WP filter ecosystem.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public\Renderers\UserServers
 * @since      0.1.11
 * @experimental May change without notice before 1.0.0. See
 *               DEC-CLIENT-RENDERER-PUBLIC-API. `final` per D36 (public
 *               `@experimental` renderer — extend by filter, not subclass).
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
	 * Register the [acrossai_mcp_servers] shortcode. Wired to `init` by
	 * `Main::define_public_hooks()` per A1 (Loader-wired bootstrap
	 * method — A1 transitivity per D17 permits the nested add_shortcode
	 * call here without violating the "no add_* outside Main.php" rule).
	 *
	 * @since 0.1.11
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		add_shortcode( 'acrossai_mcp_servers', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Shortcode callback for `[acrossai_mcp_servers]`.
	 *
	 * @since 0.1.11
	 *
	 * @param array|string $atts_raw Shortcode attributes as passed by WP.
	 * @return string Rendered HTML (already escaped) or '' for anonymous.
	 */
	public function render_shortcode( $atts_raw ): string {
		$atts = shortcode_atts(
			array(
				'heading'          => __( 'Your MCP Servers', 'acrossai-mcp-manager' ),
				'intro'            => __( 'Connect your AI tools to this site. Pick a server, then follow the steps for the tool you use.', 'acrossai-mcp-manager' ),
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
	 * Enqueue the built CSS + JS handles produced by `@wordpress/scripts`
	 * from `src/scss/frontend.scss` and `src/js/frontend.js`. Reads the
	 * version from `build/js/frontend.asset.php` (falls back to the plugin
	 * version constant if the manifest is missing — silent fallback,
	 * matches FrontendAuth's pattern at
	 * `public/Partials/FrontendAuth.php:113-138`).
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
	 * Render the empty-state card shown when the logged-in user has
	 * access to zero embed-enabled servers.
	 *
	 * @since 0.1.11
	 *
	 * @param string $empty_message Custom (or default) empty-state body text.
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
	 * Render the whole widget — header, notice, one card per server.
	 *
	 * @since 0.1.11
	 *
	 * @param array<int, array<string, mixed>> $data Server list from
	 *                                               `get_accessible_servers()`.
	 * @param array<string, mixed>             $atts Normalized shortcode atts.
	 * @return string
	 */
	private function render_widget( array $data, array $atts ): string {
		$show_desc  = '1' === (string) $atts['show_description'];
		$total_dtos = 0;
		$server_ids = array();
		foreach ( $data as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$server_ids[] = isset( $s['server_id'] ) ? (int) $s['server_id'] : 0;
			if ( isset( $s['transports'] ) && is_array( $s['transports'] ) ) {
				foreach ( $s['transports'] as $t ) {
					if ( is_array( $t ) && isset( $t['dtos'] ) && is_array( $t['dtos'] ) ) {
						$total_dtos += count( $t['dtos'] );
					}
				}
			}
		}
		$server_count = count( $data );

		$out = '<div class="acrossai-mcp-servers">';

		// Header: title + summary pill + password button.
		$out .= '<div class="acrossai-mcp-servers__header">';
		$out .= '<h2 class="acrossai-mcp-servers__title">' . esc_html( (string) $atts['heading'] ) . '</h2>';
		$out .= '<span class="acrossai-mcp-servers__summary">' . esc_html(
			sprintf(
				/* translators: 1: number of MCP servers, 2: total number of enabled clients across all servers. */
				_x( '%1$d servers · %2$d clients', 'shortcode header summary', 'acrossai-mcp-manager' ),
				$server_count,
				$total_dtos
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

		// Sidebar — server nav.
		$out  .= '<div class="acrossai-mcp-servers__sidebar" role="tablist" aria-label="' . esc_attr__( 'MCP servers', 'acrossai-mcp-manager' ) . '">';
		$out  .= '<span class="acrossai-mcp-servers__section-label">' . esc_html__( 'Servers', 'acrossai-mcp-manager' ) . '</span>';
		$first = true;
		foreach ( $data as $server ) {
			if ( ! is_array( $server ) ) {
				continue;
			}
			$out  .= $this->render_sidebar_item( $server, $first );
			$first = false;
		}
		$out .= '</div>';

		// Main — one panel per server, only first active.
		$out  .= '<div class="acrossai-mcp-servers__main">';
		$first = true;
		foreach ( $data as $server ) {
			if ( ! is_array( $server ) ) {
				continue;
			}
			$out  .= $this->render_server_panel( $server, $first, $show_desc );
			$first = false;
		}
		$out .= '</div>';

		$out .= '</div></div>'; // .__layout .__servers.

		return $out;
	}

	/**
	 * Render one sidebar entry — a `<button>` that switches which server
	 * panel is visible. First entry has `aria-selected="true"`.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $server   Server projection.
	 * @param bool                 $selected Whether this is the initially-selected sidebar entry.
	 * @return string
	 */
	private function render_sidebar_item( array $server, bool $selected ): string {
		$server_id   = isset( $server['server_id'] ) ? (int) $server['server_id'] : 0;
		$server_name = isset( $server['server_name'] ) && is_string( $server['server_name'] ) ? $server['server_name'] : '';
		$transports  = isset( $server['transports'] ) && is_array( $server['transports'] ) ? $server['transports'] : array();

		$client_names = array();
		$client_count = 0;
		foreach ( $transports as $t ) {
			if ( ! is_array( $t ) || ! isset( $t['dtos'] ) || ! is_array( $t['dtos'] ) ) {
				continue;
			}
			foreach ( $t['dtos'] as $d ) {
				if ( is_array( $d ) && isset( $d['name'] ) && is_string( $d['name'] ) ) {
					++$client_count;
					$client_names[] = $d['name'];
				}
			}
		}

		$preview = implode( ', ', array_slice( $client_names, 0, 2 ) );
		if ( count( $client_names ) > 2 ) {
			$preview .= '…';
		}
		$summary = sprintf(
			/* translators: 1: number of clients, 2: comma-separated list of first two client names. */
			_n( '%1$d client · %2$s', '%1$d clients · %2$s', $client_count, 'acrossai-mcp-manager' ),
			$client_count,
			$preview
		);

		$out  = '<button type="button" class="acrossai-mcp-servers__server-nav" role="tab"';
		$out .= ' aria-selected="' . ( $selected ? 'true' : 'false' ) . '"';
		$out .= ' data-amcp-server-select="' . esc_attr( (string) $server_id ) . '">';
		$out .= '<span class="acrossai-mcp-servers__server-nav-dot" aria-hidden="true"></span>';
		$out .= '<span class="acrossai-mcp-servers__server-nav-body">';
		$out .= '<span class="acrossai-mcp-servers__server-nav-name">' . esc_html( $server_name ) . '</span>';
		$out .= '<span class="acrossai-mcp-servers__server-nav-summary">' . esc_html( $summary ) . '</span>';
		$out .= '</span></button>';

		return $out;
	}

	/**
	 * Render the RIGHT-side panel for one server. Contains the server
	 * context card (name + description + URL + client pills) and one
	 * client-detail card per enabled DTO.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $server    Server projection.
	 * @param bool                 $active    Whether this panel is initially visible.
	 * @param bool                 $show_desc Whether to render the server description.
	 * @return string
	 */
	private function render_server_panel( array $server, bool $active, bool $show_desc ): string {
		$server_id   = isset( $server['server_id'] ) ? (int) $server['server_id'] : 0;
		$server_slug = isset( $server['server_slug'] ) && is_string( $server['server_slug'] ) ? $server['server_slug'] : '';
		$server_name = isset( $server['server_name'] ) && is_string( $server['server_name'] ) ? $server['server_name'] : '';
		$server_desc = isset( $server['description'] ) && is_string( $server['description'] ) ? $server['description'] : '';
		$server_url  = isset( $server['server_url'] ) && is_string( $server['server_url'] ) ? $server['server_url'] : '';
		$transports  = isset( $server['transports'] ) && is_array( $server['transports'] ) ? $server['transports'] : array();

		// Group DTOs per transport so the pills render as separate labeled
		// sections (MCP Clients / NPM / AI Connectors) per the v2 design
		// screenshot. The client-detail cards below stay flat — only one
		// is visible at a time regardless of the selected pill's section.
		$groups     = array();
		$flat_dtos  = array();
		$total_dtos = 0;
		foreach ( $transports as $t ) {
			if ( ! is_array( $t ) || ! isset( $t['dtos'] ) || ! is_array( $t['dtos'] ) ) {
				continue;
			}
			$key        = isset( $t['key'] ) && is_string( $t['key'] ) ? $t['key'] : '';
			$label      = isset( $t['label'] ) && is_string( $t['label'] ) ? $t['label'] : '';
			$group_dtos = array();
			foreach ( $t['dtos'] as $d ) {
				if ( is_array( $d ) ) {
					$group_dtos[] = $d;
					$flat_dtos[]  = array(
						'key' => $key,
						'dto' => $d,
					);
					++$total_dtos;
				}
			}
			if ( ! empty( $group_dtos ) ) {
				$groups[] = array(
					'key'   => $key,
					'label' => $label,
					'dtos'  => $group_dtos,
				);
			}
		}

		$url_id = 'amcp-url-' . $server_id;

		$out  = '<div class="acrossai-mcp-servers__server-panel"';
		$out .= ' data-amcp-server="' . esc_attr( (string) $server_id ) . '"';
		$out .= ' data-active="' . ( $active ? 'true' : 'false' ) . '"';
		$out .= ' role="tabpanel">';

		// Server context card.
		$out .= '<div class="acrossai-mcp-servers__server-context">';
		$out .= '<div class="acrossai-mcp-servers__server-heading">';
		$out .= '<span class="acrossai-mcp-servers__server-name">' . esc_html( $server_name ) . '</span>';
		if ( $show_desc && '' !== $server_desc ) {
			$out .= '<span class="acrossai-mcp-servers__server-desc">' . esc_html( $server_desc ) . '</span>';
		}
		$out .= '</div>';

		if ( '' !== $server_url ) {
			$out .= '<div class="acrossai-mcp-servers__url-row">';
			$out .= '<span class="acrossai-mcp-servers__url-label">' . esc_html__( 'URL', 'acrossai-mcp-manager' ) . '</span>';
			$out .= '<code class="acrossai-mcp-servers__url" id="' . esc_attr( $url_id ) . '">' . esc_html( $server_url ) . '</code>';
			$out .= '<button type="button" class="acrossai-mcp-servers__copy" data-amcp-copy="#' . esc_attr( $url_id ) . '">' . esc_html__( 'Copy', 'acrossai-mcp-manager' ) . '</button>';
			$out .= '</div>';
		}

		if ( ! empty( $groups ) ) {
			$out .= '<div class="acrossai-mcp-servers__client-pills-block">';
			$out .= '<span class="acrossai-mcp-servers__section-label">' . esc_html(
				sprintf(
					/* translators: %d — total number of supported client tools across all transport categories for this server. */
					_n( 'Supported clients (%d)', 'Supported clients (%d)', $total_dtos, 'acrossai-mcp-manager' ),
					$total_dtos
				)
			) . '</span>';

			$is_first_pill = true;
			foreach ( $groups as $group ) {
				$group_count = count( $group['dtos'] );
				$out        .= '<div class="acrossai-mcp-servers__transport-group" data-transport-key="' . esc_attr( $group['key'] ) . '">';
				if ( '' !== $group['label'] ) {
					$out .= '<span class="acrossai-mcp-servers__transport-label">';
					$out .= esc_html(
						sprintf(
							/* translators: 1: transport section label (e.g. "MCP Clients"), 2: number of clients in that transport section. */
							_x( '%1$s (%2$d)', 'shortcode transport section header', 'acrossai-mcp-manager' ),
							$group['label'],
							$group_count
						)
					);
					$out .= '</span>';
				}
				$out .= '<div class="acrossai-mcp-servers__client-pills">';
				foreach ( $group['dtos'] as $d ) {
					$out          .= $this->render_client_pill( $d, $is_first_pill );
					$is_first_pill = false;
				}
				$out .= '</div></div>';
			}

			$out .= '</div>';
		}

		$out .= '</div>'; // .__server-context

		// Client-detail cards. Flat order — the JS selection swap works
		// across all groups because the pill's data-amcp-client-select
		// slug is unique per DTO regardless of the transport section it
		// lives in.
		$is_first_detail = true;
		foreach ( $flat_dtos as $c ) {
			$out            .= $this->render_client_detail( $c['dto'], $c['key'], $server_id, $server_slug, $server_url, $is_first_detail );
			$is_first_detail = false;
		}

		$out .= '</div>'; // .__server-panel

		return $out;
	}

	/**
	 * Render a single client pill inside the client-pills row.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto      F035 DTO projection.
	 * @param bool                 $selected Whether this pill is initially selected.
	 * @return string
	 */
	private function render_client_pill( array $dto, bool $selected ): string {
		$slug = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : '';
		$name = isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : '';
		$icon = isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '';

		$out  = '<button type="button" class="acrossai-mcp-servers__client-pill" role="tab"';
		$out .= ' aria-selected="' . ( $selected ? 'true' : 'false' ) . '"';
		$out .= ' data-amcp-client-select="' . esc_attr( $slug ) . '">';
		$out .= '<span class="acrossai-mcp-servers__client-pill-icon" aria-hidden="true">';
		if ( '' !== $icon ) {
			if ( self::icon_is_url( $icon ) ) {
				$out .= '<img src="' . esc_url( $icon ) . '" alt="" />';
			} else {
				$out .= esc_html( $icon );
			}
		}
		$out .= '</span>';
		$out .= '<span class="acrossai-mcp-servers__client-pill-name">' . esc_html( $name ) . '</span>';
		$out .= '</button>';

		return $out;
	}

	/**
	 * Render a single client's detail card. Dispatches on transport category
	 * for local-config / OAuth-connector / CLI-bridge variants of the body.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto           F035 DTO projection.
	 * @param string               $transport_key `client` | `npm` | `ai_connector` | third-party.
	 * @param int                  $server_id     Owning server row id.
	 * @param string               $server_slug   Server slug (for NPM substitution).
	 * @param string               $server_url    Full REST URL for the server.
	 * @param bool                 $active        Whether this card is initially visible.
	 * @return string
	 */
	private function render_client_detail( array $dto, string $transport_key, int $server_id, string $server_slug, string $server_url, bool $active ): string {
		$slug        = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : '';
		$name        = isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : '';
		$icon        = isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '';
		$badge_label = $this->tag_for_transport( $transport_key );
		$badge_class = $this->badge_class_for_transport( $transport_key );

		$out  = '<div class="acrossai-mcp-servers__client-detail"';
		$out .= ' data-amcp-client="' . esc_attr( $slug ) . '"';
		$out .= ' data-category="' . esc_attr( $transport_key ) . '"';
		$out .= ' data-active="' . ( $active ? 'true' : 'false' ) . '"';
		$out .= ' role="tabpanel">';

		// Head — icon, name, badge.
		$out .= '<div class="acrossai-mcp-servers__client-detail-head">';
		$out .= '<span class="acrossai-mcp-servers__client-detail-icon" aria-hidden="true">';
		if ( '' !== $icon ) {
			if ( self::icon_is_url( $icon ) ) {
				$out .= '<img src="' . esc_url( $icon ) . '" alt="" />';
			} else {
				$out .= esc_html( $icon );
			}
		}
		$out .= '</span>';
		$out .= '<span class="acrossai-mcp-servers__client-detail-name">' . esc_html( $name ) . '</span>';
		if ( '' !== $badge_label ) {
			$out .= '<span class="acrossai-mcp-servers__client-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span>';
		}
		$out .= '</div>';

		// Body — dispatched on transport.
		$out .= '<div class="acrossai-mcp-servers__client-detail-body">';
		switch ( $transport_key ) {
			case 'client':
				$out .= $this->render_client_body( $dto, $server_id, $server_url );
				break;
			case 'npm':
				$out .= $this->render_npm_body( $dto, $server_id, $server_slug );
				break;
			case 'ai_connector':
				$out .= $this->render_ai_connector_body( $dto );
				break;
			default:
				$out .= $this->render_generic_body( $dto );
				break;
		}
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Map a transport-category machine key to the CSS modifier class
	 * appended to `.acrossai-mcp-servers__client-badge` for color coding.
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
	 * Render body for a `client` DTO — composes with F034
	 * `AbstractMCPClient` metadata + `get_config_snippet()`.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto        F035 client DTO with `meta.class` FQCN.
	 * @param int                  $server_id  Owning server row id.
	 * @param string               $server_url Full REST URL for the server.
	 * @return string
	 */
	private function render_client_body( array $dto, int $server_id, string $server_url ): string {
		$meta = isset( $dto['meta'] ) && is_array( $dto['meta'] ) ? $dto['meta'] : array();
		$fqcn = isset( $meta['class'] ) && is_string( $meta['class'] ) ? $meta['class'] : '';

		if ( '' === $fqcn || ! class_exists( $fqcn ) || ! is_subclass_of( $fqcn, AbstractMCPClient::class ) ) {
			return '';
		}

		/** @var AbstractMCPClient $client */
		$client         = new $fqcn();
		$config_file    = $client->get_config_file();
		$top_level_key  = $client->get_top_level_key();
		$snippet_raw    = $client->get_config_snippet( $server_url, AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER );
		$instructions   = $client->get_instructions();
		$snippet_string = is_array( $snippet_raw )
			? (string) wp_json_encode( $snippet_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: (string) $snippet_raw;

		$slug     = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : 'client';
		$code_id  = 'amcp-code-' . $server_id . '-' . sanitize_html_class( $slug );
		$is_toml  = '' !== $config_file && '.toml' === substr( $config_file, -5 );
		$language = $is_toml ? 'toml' : 'json';

		$out = '';

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

		if ( '' !== $snippet_string ) {
			$line_count = substr_count( $snippet_string, "\n" ) + 1;
			$out       .= '<div class="acrossai-mcp-servers__code">';
			$out       .= '<div class="acrossai-mcp-servers__code-bar">';
			$out       .= '<span class="acrossai-mcp-servers__lang">' . esc_html( $language ) . '</span>';
			$out       .= '<span class="acrossai-mcp-servers__line-count">' . esc_html(
				sprintf(
					/* translators: %d — number of lines in the config snippet. */
					_n( '%d line', '%d lines', $line_count, 'acrossai-mcp-manager' ),
					$line_count
				)
			) . '</span>';
			$out .= '<button type="button" class="acrossai-mcp-servers__copy acrossai-mcp-servers__copy--ondark" data-amcp-copy="#' . esc_attr( $code_id ) . '">' . esc_html__( 'Copy', 'acrossai-mcp-manager' ) . '</button>';
			$out .= '</div>';
			$out .= '<pre class="acrossai-mcp-servers__pre"><code id="' . esc_attr( $code_id ) . '">' . esc_html( $snippet_string ) . '</code></pre>';
			$out .= '</div>';
		}

		$out .= $this->render_steps( $instructions, true );

		return $out;
	}

	/**
	 * Render body for an `npm` DTO. Substitutes the two `%s` placeholders
	 * in `meta.command_template` with `home_url()` + server slug.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto         F035 npm DTO with `meta.command_template`.
	 * @param int                  $server_id   Owning server row id.
	 * @param string               $server_slug Server slug for the `--server=` argument.
	 * @return string
	 */
	private function render_npm_body( array $dto, int $server_id, string $server_slug ): string {
		$meta     = isset( $dto['meta'] ) && is_array( $dto['meta'] ) ? $dto['meta'] : array();
		$template = isset( $meta['command_template'] ) && is_string( $meta['command_template'] ) ? $meta['command_template'] : '';

		if ( '' === $template ) {
			return '';
		}

		$command    = sprintf( $template, home_url(), $server_slug );
		$slug       = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : 'npm';
		$code_id    = 'amcp-code-' . $server_id . '-' . sanitize_html_class( $slug );
		$line_count = substr_count( $command, "\n" ) + 1;

		$out  = '<div class="acrossai-mcp-servers__code">';
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

		$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';
		$out        .= $this->render_steps( $description, false );

		return $out;
	}

	/**
	 * Render body for an `ai_connector` DTO — OAuth-flow informational
	 * block (no local config paste).
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto F035 ai_connector DTO.
	 * @return string
	 */
	private function render_ai_connector_body( array $dto ): string {
		$out  = '<div class="acrossai-mcp-servers__oauth-notice">';
		$out .= '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 11v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="7.7" r="1.05" fill="currentColor"/></svg>';
		$out .= '<span>' . esc_html__( 'No local config to paste. This connector authorizes over OAuth on the provider\'s side — you\'ll be redirected here to approve access, so your Application Password never leaves WordPress.', 'acrossai-mcp-manager' ) . '</span>';
		$out .= '</div>';

		$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';
		$out        .= $this->render_steps( $description, false );

		return $out;
	}

	/**
	 * Fallback body for third-party transport categories.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto Third-party DTO.
	 * @return string
	 */
	private function render_generic_body( array $dto ): string {
		$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';
		if ( '' === $description ) {
			return '';
		}
		return '<div class="acrossai-mcp-servers__oauth-notice">' . esc_html( $description ) . '</div>';
	}

	/**
	 * Render a numbered steps grid from a string. Splits on the ` → `
	 * separator that every F034 `AbstractMCPClient::get_instructions()`
	 * uses; falls back to a single-item list when the string has no
	 * arrows (matches `CustomClient` shape).
	 *
	 * Returns an empty string when `$instructions` is empty, so callers
	 * can safely concatenate without a defensive guard.
	 *
	 * @since 0.1.11
	 *
	 * @param string $instructions Translated instructions string.
	 * @param bool   $with_replace_pill Whether to render the amber "replace (paste generated password here)" pill next to the STEPS label.
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
	 * Map a transport-category machine key to the short label rendered
	 * on the client-summary tag pill. Companion-plugin transport keys
	 * return an empty string (no tag) — the design allows this.
	 *
	 * @since 0.1.11
	 *
	 * @param string $transport_key `client` | `npm` | `ai_connector` | third-party.
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
	 * Detect whether an icon string is an http(s) URL. Whitelist approach:
	 * only `http://` and `https://` prefixes render as `<img>`. Anything
	 * else — emoji, empty string, `data:` URIs, relative paths — renders
	 * as `esc_html` text. `esc_url` at the render seam strips remaining
	 * scheme-injection vectors as defense-in-depth (SEC-003).
	 *
	 * @since 0.1.11
	 *
	 * @param string $icon Icon value from the DTO.
	 * @return bool True if the value should render as `<img src="...">`.
	 */
	private static function icon_is_url( string $icon ): bool {
		return 0 === strpos( $icon, 'http://' ) || 0 === strpos( $icon, 'https://' );
	}
}
