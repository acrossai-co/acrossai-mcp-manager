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
		$heading      = (string) $atts['heading'];
		$intro        = (string) $atts['intro'];
		$show_config  = '1' === (string) $atts['show_config'];
		$show_desc    = '1' === (string) $atts['show_description'];
		$server_count = count( $data );

		$out = '<div class="acrossai-mcp-servers">';

		// Header — title / intro / server count pill.
		$out .= '<div class="acrossai-mcp-servers__head"><div>';
		if ( '' !== $heading ) {
			$out .= '<h2 class="acrossai-mcp-servers__title">' . esc_html( $heading ) . '</h2>';
		}
		if ( '' !== $intro ) {
			$out .= '<p class="acrossai-mcp-servers__intro">' . esc_html( $intro ) . '</p>';
		}
		$out .= '</div>';
		$out .= '<span class="acrossai-mcp-servers__count"><span class="acrossai-mcp-servers__dot"></span>';
		$out .= esc_html(
			sprintf(
				/* translators: %d — count of MCP servers the user can access. */
				_n( '%d server available', '%d servers available', $server_count, 'acrossai-mcp-manager' ),
				$server_count
			)
		);
		$out .= '</span></div>';

		// Global Application Password notice.
		$out .= '<div class="acrossai-mcp-servers__notice">';
		$out .= '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="10" width="16" height="10" rx="2.5" stroke="currentColor" stroke-width="1.7"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="12" cy="15" r="1.4" fill="currentColor"/></svg>';
		$out .= '<span>';
		$out .= '<span class="acrossai-mcp-servers__notice-title">' . esc_html__( 'You need a WordPress Application Password', 'acrossai-mcp-manager' ) . '</span>';
		$out .= '<span class="acrossai-mcp-servers__notice-body">';
		$out .= sprintf(
			/* translators: 1: <strong>-wrapped Users → Profile → Application Passwords navigation path. 2: <code>-wrapped placeholder token that must be replaced. */
			esc_html__( 'Generate one under %1$s, then paste it wherever you see %2$s below. Generate once — reuse it for every tool.', 'acrossai-mcp-manager' ),
			'<strong>' . esc_html__( 'Users → Profile → Application Passwords', 'acrossai-mcp-manager' ) . '</strong>',
			'<code class="acrossai-mcp-servers__kbd">' . esc_html__( '(paste generated password here)', 'acrossai-mcp-manager' ) . '</code>'
		);
		$out .= '</span></span></div>';

		// One <details> card per server.
		$first = true;
		foreach ( $data as $server ) {
			if ( ! is_array( $server ) ) {
				continue;
			}
			$out  .= $this->render_server_card( $server, $first, $show_desc, $show_config );
			$first = false;
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Render a single server `<details>` card.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $server      Server projection.
	 * @param bool                 $open        Whether to render with the `open` attribute (first card).
	 * @param bool                 $show_desc   Whether to render the server description.
	 * @param bool                 $show_config Whether to render per-client config bodies.
	 * @return string
	 */
	private function render_server_card( array $server, bool $open, bool $show_desc, bool $show_config ): string {
		$server_id   = isset( $server['server_id'] ) ? (int) $server['server_id'] : 0;
		$server_slug = isset( $server['server_slug'] ) && is_string( $server['server_slug'] ) ? $server['server_slug'] : '';
		$server_name = isset( $server['server_name'] ) && is_string( $server['server_name'] ) ? $server['server_name'] : '';
		$server_desc = isset( $server['description'] ) && is_string( $server['description'] ) ? $server['description'] : '';
		$server_url  = isset( $server['server_url'] ) && is_string( $server['server_url'] ) ? $server['server_url'] : '';
		$transports  = isset( $server['transports'] ) && is_array( $server['transports'] ) ? $server['transports'] : array();

		$total_dtos = 0;
		foreach ( $transports as $t ) {
			if ( is_array( $t ) && isset( $t['dtos'] ) && is_array( $t['dtos'] ) ) {
				$total_dtos += count( $t['dtos'] );
			}
		}

		$url_id = 'amcp-url-' . $server_id;

		$out  = '<details class="acrossai-mcp-servers__server"' . ( $open ? ' open' : '' );
		$out .= ' data-server-id="' . esc_attr( (string) $server_id ) . '"';
		$out .= ' data-server-slug="' . esc_attr( $server_slug ) . '">';
		$out .= '<summary class="acrossai-mcp-servers__server-summary">';
		$out .= '<span class="acrossai-mcp-servers__caret" aria-hidden="true">&#9654;</span>';
		$out .= '<span class="acrossai-mcp-servers__server-meta">';
		$out .= '<span class="acrossai-mcp-servers__server-name">' . esc_html( $server_name ) . '</span>';
		if ( $show_desc && '' !== $server_desc ) {
			$out .= '<span class="acrossai-mcp-servers__server-desc">' . esc_html( $server_desc ) . '</span>';
		}
		$out .= '</span>';
		$out .= '<span class="acrossai-mcp-servers__pill">' . esc_html(
			sprintf(
				/* translators: %d — number of tools/DTOs enabled for this server. */
				_n( '%d tool', '%d tools', $total_dtos, 'acrossai-mcp-manager' ),
				$total_dtos
			)
		) . '</span>';
		$out .= '</summary>';

		$out .= '<div class="acrossai-mcp-servers__server-body">';

		// Server URL row (present for every server variant).
		if ( '' !== $server_url ) {
			$out .= '<div>';
			$out .= '<span class="acrossai-mcp-servers__label">' . esc_html__( 'Server URL', 'acrossai-mcp-manager' ) . '</span>';
			$out .= '<div class="acrossai-mcp-servers__urlrow">';
			$out .= '<code class="acrossai-mcp-servers__url" id="' . esc_attr( $url_id ) . '">' . esc_html( $server_url ) . '</code>';
			$out .= '<button type="button" class="acrossai-mcp-servers__copy" data-amcp-copy="#' . esc_attr( $url_id ) . '">' . esc_html__( 'Copy URL', 'acrossai-mcp-manager' ) . '</button>';
			$out .= '</div></div>';
		}

		// Client rows.
		$out .= '<div class="acrossai-mcp-servers__clients">';
		$out .= '<span class="acrossai-mcp-servers__label">' . esc_html(
			sprintf(
				/* translators: %d — number of tools/DTOs enabled for this server. */
				_n( 'Your tools (%d)', 'Your tools (%d)', $total_dtos, 'acrossai-mcp-manager' ),
				$total_dtos
			)
		) . '</span>';

		$first_client = true;
		foreach ( $transports as $transport ) {
			if ( ! is_array( $transport ) ) {
				continue;
			}
			$transport_key = isset( $transport['key'] ) && is_string( $transport['key'] ) ? $transport['key'] : '';
			$dtos          = isset( $transport['dtos'] ) && is_array( $transport['dtos'] ) ? $transport['dtos'] : array();

			foreach ( $dtos as $dto ) {
				if ( ! is_array( $dto ) ) {
					continue;
				}
				$out         .= $this->render_client( $dto, $transport_key, $server_id, $server_slug, $server_url, $first_client && $show_config );
				$first_client = false;
			}
		}

		$out .= '</div></div></details>';

		return $out;
	}

	/**
	 * Render a single client `<details>` inside a server card. Dispatches
	 * on the transport category to render local-config / OAuth-connector /
	 * CLI-bridge variants of the details body.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto             F035 DTO projection.
	 * @param string               $transport_key   `client` | `npm` | `ai_connector` | third-party.
	 * @param int                  $server_id       Owning server row id (needed for unique copy target IDs).
	 * @param string               $server_slug     Server slug (needed for NPM `--server=%s` substitution).
	 * @param string               $server_url      Full REST URL for the server.
	 * @param bool                 $open            Whether to render with the `open` attribute (only the first client of the first server, when show_config=1).
	 * @return string
	 */
	private function render_client( array $dto, string $transport_key, int $server_id, string $server_slug, string $server_url, bool $open ): string {
		$slug = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : '';
		$name = isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : '';
		$icon = isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '';
		$tag  = $this->tag_for_transport( $transport_key );

		$out  = '<details class="acrossai-mcp-servers__client"' . ( $open ? ' open' : '' ) . ' data-slug="' . esc_attr( $slug ) . '" data-category="' . esc_attr( $transport_key ) . '">';
		$out .= '<summary class="acrossai-mcp-servers__client-summary">';
		$out .= '<span class="acrossai-mcp-servers__caret" aria-hidden="true">&#9654;</span>';
		if ( '' !== $icon ) {
			if ( self::icon_is_url( $icon ) ) {
				$out .= '<img class="acrossai-mcp-servers__icon" src="' . esc_url( $icon ) . '" alt="" />';
			} else {
				$out .= '<span class="acrossai-mcp-servers__icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
			}
		}
		$out .= '<span class="acrossai-mcp-servers__client-name">' . esc_html( $name ) . '</span>';
		if ( '' !== $tag ) {
			$out .= '<span class="acrossai-mcp-servers__tag">' . esc_html( $tag ) . '</span>';
		}
		$out .= '</summary>';

		$out .= '<div class="acrossai-mcp-servers__client-body">';
		switch ( $transport_key ) {
			case 'client':
				$out .= $this->render_client_body( $dto, $server_id, $server_url );
				break;
			case 'npm':
				$out .= $this->render_npm_body( $dto, $server_id, $server_slug );
				break;
			case 'ai_connector':
				$out .= $this->render_ai_connector_body( $dto, $server_url );
				break;
			default:
				// Companion-plugin transport category — render a minimal
				// info block so the client still explains itself.
				$out .= $this->render_generic_body( $dto );
				break;
		}
		$out .= '</div></details>';

		return $out;
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
				$out .= '<span class="acrossai-mcp-servers__label">' . esc_html__( 'Config file', 'acrossai-mcp-manager' ) . '</span>';
				$out .= '<code>' . esc_html( $config_file ) . '</code>';
				$out .= '</div>';
			}
			if ( '' !== $top_level_key ) {
				$out .= '<div class="acrossai-mcp-servers__field">';
				$out .= '<span class="acrossai-mcp-servers__label">' . esc_html__( 'Top-level key', 'acrossai-mcp-manager' ) . '</span>';
				$out .= '<code>' . esc_html( $top_level_key ) . '</code>';
				$out .= '</div>';
			}
			$out .= '</div>';
		}

		if ( '' !== $snippet_string ) {
			$out .= '<div class="acrossai-mcp-servers__code">';
			$out .= '<div class="acrossai-mcp-servers__code-bar">';
			$out .= '<span class="acrossai-mcp-servers__lang">' . esc_html( $language ) . '</span>';
			$out .= '<button type="button" class="acrossai-mcp-servers__copy acrossai-mcp-servers__copy--ondark" data-amcp-copy="#' . esc_attr( $code_id ) . '">' . esc_html__( 'Copy', 'acrossai-mcp-manager' ) . '</button>';
			$out .= '</div>';
			$out .= '<pre class="acrossai-mcp-servers__pre"><code id="' . esc_attr( $code_id ) . '">' . esc_html( $snippet_string ) . '</code></pre>';
			$out .= '</div>';
		}

		$out .= '<div class="acrossai-mcp-servers__notice">';
		$out .= '<span class="acrossai-mcp-servers__notice-body">' . sprintf(
			/* translators: %s — <code>-wrapped placeholder token that must be replaced by the user's Application Password. */
			esc_html__( 'Replace %s with your Application Password.', 'acrossai-mcp-manager' ),
			'<code class="acrossai-mcp-servers__kbd">' . esc_html( AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER ) . '</code>'
		) . '</span></div>';

		$out .= $this->render_steps( $instructions );

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

		$command = sprintf( $template, home_url(), $server_slug );
		$slug    = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : 'npm';
		$code_id = 'amcp-code-' . $server_id . '-' . sanitize_html_class( $slug );

		$out  = '<div class="acrossai-mcp-servers__code">';
		$out .= '<div class="acrossai-mcp-servers__code-bar">';
		$out .= '<span class="acrossai-mcp-servers__lang">bash</span>';
		$out .= '<button type="button" class="acrossai-mcp-servers__copy acrossai-mcp-servers__copy--ondark" data-amcp-copy="#' . esc_attr( $code_id ) . '">' . esc_html__( 'Copy', 'acrossai-mcp-manager' ) . '</button>';
		$out .= '</div>';
		$out .= '<pre class="acrossai-mcp-servers__pre"><code id="' . esc_attr( $code_id ) . '">' . esc_html( $command ) . '</code></pre>';
		$out .= '</div>';

		$out .= '<div class="acrossai-mcp-servers__notice">';
		$out .= '<span class="acrossai-mcp-servers__notice-body">' . esc_html__( 'You need a WordPress Application Password — the bridge prompts for it on first run.', 'acrossai-mcp-manager' ) . '</span></div>';

		$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';
		$out        .= $this->render_steps( $description );

		return $out;
	}

	/**
	 * Render body for an `ai_connector` DTO — OAuth-flow informational
	 * block (no local config paste).
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto        F035 ai_connector DTO.
	 * @param string               $server_url Full REST URL for the server.
	 * @return string
	 */
	private function render_ai_connector_body( array $dto, string $server_url ): string {
		unset( $server_url );

		$out  = '<div class="acrossai-mcp-servers__notice" style="border-color:color-mix(in srgb,currentColor 22%,transparent);background:var(--amcp-surface-2)">';
		$out .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M12 11v5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="12" cy="7.7" r="1.05" fill="currentColor"/></svg>';
		$out .= '<span class="acrossai-mcp-servers__notice-body">' . esc_html__( 'No local config to paste. This connector authorizes over OAuth on the provider\'s side — you\'ll be redirected here to approve access, so your Application Password never leaves WordPress.', 'acrossai-mcp-manager' ) . '</span>';
		$out .= '</div>';

		$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';
		$out        .= $this->render_steps( $description );

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
		return '<div class="acrossai-mcp-servers__notice"><span class="acrossai-mcp-servers__notice-body">' . esc_html( $description ) . '</span></div>';
	}

	/**
	 * Render a numbered steps `<ol>` from a string. Splits on the ` → `
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
	 * @return string
	 */
	private function render_steps( string $instructions ): string {
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

		$out  = '<div><span class="acrossai-mcp-servers__label">' . esc_html__( 'Steps', 'acrossai-mcp-manager' ) . '</span>';
		$out .= '<ol class="acrossai-mcp-servers__steps">';
		foreach ( $parts as $step ) {
			$out .= '<li>' . esc_html( rtrim( $step, '.' ) ) . '</li>';
		}
		$out .= '</ol></div>';

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
