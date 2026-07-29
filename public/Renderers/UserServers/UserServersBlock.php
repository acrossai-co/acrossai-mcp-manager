<?php
/**
 * Frontend renderer for the F038 [acrossai_mcp_servers] shortcode.
 *
 * Concrete singleton wrapping AbstractUserServersRenderer with a default
 * HTML shape + inline scoped `<style>` block. `final class` per D36
 * (extension via `acrossai_mcp_servers_shortcode_html` filter, not
 * subclass). Companion plugins that want their own markup subclass the
 * abstract base directly and render however they want.
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
	 * Per-request flag — true after the inline `<style>` block has been
	 * emitted once during this request. Ensures FR-016 (style emitted
	 * exactly once per HTTP request regardless of how many times the
	 * shortcode appears on the page).
	 *
	 * ## Static CSS only (SEC-005)
	 *
	 * The emitted `<style>` block content (INLINE_STYLE constant below)
	 * is a static CSS literal. No user-supplied, DTO-supplied,
	 * filter-supplied, or shortcode-attribute-supplied value may be
	 * interpolated into it — ever. Future dynamic values (per-shortcode
	 * theming, custom brand colors, etc.) MUST be applied via `data-*`
	 * attributes on the wrapper `<div>` and CSS attribute selectors
	 * (`.acrossai-mcp-servers[data-theme="dark"] { … }`) — never via
	 * string concatenation into the `<style>` block. `esc_html` and
	 * `esc_attr` DO NOT escape CSS context (analog of B36 for CSS
	 * instead of JS).
	 *
	 * @var bool
	 */
	private static bool $style_emitted = false;

	/**
	 * Inline scoped CSS for the shortcode output. Emitted at most once
	 * per request per SEC-005 static-only invariant. All selectors
	 * prefixed with `.acrossai-mcp-servers` — never leaks generic
	 * selectors. Uses `currentColor` for borders so the widget inherits
	 * theme text color.
	 *
	 * @var string
	 */
	private const INLINE_STYLE = <<<'CSS'
<style type="text/css">
.acrossai-mcp-servers{}
.acrossai-mcp-servers--empty{}
.acrossai-mcp-servers__heading{margin:0 0 1em 0;}
.acrossai-mcp-servers__list{list-style:none;padding:0;margin:0;display:grid;gap:1em;}
.acrossai-mcp-servers__server{border:1px solid currentColor;border-radius:4px;padding:1em;}
.acrossai-mcp-servers__server-name{margin:0 0 0.25em 0;font-size:1.1em;}
.acrossai-mcp-servers__server-desc{margin:0 0 0.75em 0;opacity:0.85;font-size:0.9em;}
.acrossai-mcp-servers__transports{display:grid;gap:0.75em;}
.acrossai-mcp-servers__transport-label{margin:0 0 0.35em 0;font-size:0.95em;}
.acrossai-mcp-servers__dtos{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:0.5em;}
.acrossai-mcp-servers__dto{display:inline-flex;align-items:center;gap:0.35em;padding:0.25em 0.6em;border:1px solid currentColor;border-radius:999px;font-size:0.9em;}
.acrossai-mcp-servers__icon{display:inline-block;width:1.1em;height:1.1em;}
.acrossai-mcp-servers__icon img{max-width:100%;height:auto;display:block;}
.acrossai-mcp-servers__dto-details{margin-top:0.75em;border-top:1px dashed currentColor;padding-top:0.75em;font-size:0.9em;display:none;}
.acrossai-mcp-servers__dto--open .acrossai-mcp-servers__dto-details{display:block;}
.acrossai-mcp-servers__dto{flex-direction:column;align-items:stretch;}
.acrossai-mcp-servers__dto-head{display:flex;align-items:center;gap:0.35em;cursor:pointer;}
.acrossai-mcp-servers__meta-row{margin:0.35em 0;}
.acrossai-mcp-servers__meta-label{font-weight:600;margin-right:0.35em;}
.acrossai-mcp-servers__snippet{background:rgba(0,0,0,0.06);padding:0.75em;border-radius:4px;overflow-x:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:0.85em;white-space:pre;margin:0.35em 0;}
.acrossai-mcp-servers__instructions{margin:0.5em 0 0 0;opacity:0.85;}
.acrossai-mcp-servers__auth-notice{margin:0.5em 0;padding:0.5em 0.75em;border-left:3px solid currentColor;font-size:0.85em;opacity:0.9;}
</style>
CSS;

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
	 *
	 * Public constructor would allow duplicate instantiation which would
	 * fragment the `$style_emitted` flag across instances and break
	 * FR-016 (style emitted exactly once per request).
	 */
	private function __construct() {
	}

	/**
	 * Reset the per-request style-emitted flag. Intended for test
	 * isolation only — production code MUST NOT call this method. The
	 * flag naturally resets between HTTP requests (PHP static process
	 * boundary).
	 *
	 * @since 0.1.11
	 *
	 * @return void
	 */
	public static function reset_style_emitted_for_tests(): void {
		self::$style_emitted = false;
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
		// Step 1 — normalize attributes.
		$atts = shortcode_atts(
			array(
				'heading'           => '',
				'show_description'  => '1',
				'show_config'       => '1',
				'show_instructions' => '1',
				'empty_message'     => __( 'You do not have access to any MCP server yet.', 'acrossai-mcp-manager' ),
			),
			is_array( $atts_raw ) ? $atts_raw : array(),
			'acrossai_mcp_servers'
		);

		// Step 2 — anonymous silent no-render.
		if ( 0 === get_current_user_id() ) {
			return '';
		}

		// Step 3 — fetch data via the abstract base's canonical
		// primitive. The base already defensively coerces a non-array
		// filter return to []; belt-and-braces here in case a subclass
		// override loosens that.
		$data = $this->get_accessible_servers();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Step 4 — emit inline `<style>` block once per request.
		$html = '';
		if ( ! self::$style_emitted ) {
			$html               .= self::INLINE_STYLE;
			self::$style_emitted = true;
		}

		// Step 5 — build HTML.
		if ( empty( $data ) ) {
			$html .= '<div class="acrossai-mcp-servers acrossai-mcp-servers--empty">';
			$html .= '<p>' . esc_html( (string) $atts['empty_message'] ) . '</p>';
			$html .= '</div>';
		} else {
			$html .= $this->render_server_list( $data, $atts );
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
	 * Render the server list HTML. Escape-at-boundary discipline per
	 * spec.md FR-014 / FR-015.
	 *
	 * @since 0.1.11
	 *
	 * @param array<int, array<string, mixed>> $data Server list from get_accessible_servers().
	 * @param array<string, mixed>             $atts Normalized shortcode atts.
	 * @return string
	 */
	private function render_server_list( array $data, array $atts ): string {
		$out               = '<div class="acrossai-mcp-servers">';
		$heading           = (string) $atts['heading'];
		$show_description  = '1' === (string) $atts['show_description'];
		$show_config       = '1' === (string) $atts['show_config'];
		$show_instructions = '1' === (string) $atts['show_instructions'];

		if ( '' !== $heading ) {
			$out .= '<h2 class="acrossai-mcp-servers__heading">' . esc_html( $heading ) . '</h2>';
		}

		$out .= '<ul class="acrossai-mcp-servers__list">';
		foreach ( $data as $server ) {
			if ( ! is_array( $server ) ) {
				continue;
			}
			$out .= $this->render_server_item( $server, $show_description, $show_config, $show_instructions );
		}
		$out .= '</ul>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render a single server `<li>`.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $server            Server projection.
	 * @param bool                 $show_description  Whether to render the description paragraph.
	 * @param bool                 $show_config       Whether to render per-DTO config snippets + config file + top-level key.
	 * @param bool                 $show_instructions Whether to render per-DTO instructions text.
	 * @return string
	 */
	private function render_server_item( array $server, bool $show_description, bool $show_config, bool $show_instructions ): string {
		$server_id          = isset( $server['server_id'] ) ? (int) $server['server_id'] : 0;
		$server_slug        = isset( $server['server_slug'] ) && is_string( $server['server_slug'] ) ? $server['server_slug'] : '';
		$server_name        = isset( $server['server_name'] ) && is_string( $server['server_name'] ) ? $server['server_name'] : '';
		$server_description = isset( $server['description'] ) && is_string( $server['description'] ) ? $server['description'] : '';
		$server_url         = isset( $server['server_url'] ) && is_string( $server['server_url'] ) ? $server['server_url'] : '';
		$transports         = isset( $server['transports'] ) && is_array( $server['transports'] ) ? $server['transports'] : array();

		$out  = '<li class="acrossai-mcp-servers__server"';
		$out .= ' data-server-id="' . esc_attr( (string) $server_id ) . '"';
		$out .= ' data-server-slug="' . esc_attr( $server_slug ) . '">';
		$out .= '<h3 class="acrossai-mcp-servers__server-name">' . esc_html( $server_name ) . '</h3>';

		if ( $show_description && '' !== $server_description ) {
			$out .= '<p class="acrossai-mcp-servers__server-desc">' . esc_html( $server_description ) . '</p>';
		}

		$out .= '<div class="acrossai-mcp-servers__transports">';
		foreach ( $transports as $transport ) {
			if ( ! is_array( $transport ) ) {
				continue;
			}
			$out .= $this->render_transport_section( $transport, $server_slug, $server_url, $show_config, $show_instructions );
		}
		$out .= '</div>';
		$out .= '</li>';

		return $out;
	}

	/**
	 * Render a single transport `<section>`.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $transport         Transport projection.
	 * @param string               $server_slug       Server slug (needed for NPM `--server=%s` substitution).
	 * @param string               $server_url        Full REST URL for the server.
	 * @param bool                 $show_config       Whether to render per-DTO config snippets.
	 * @param bool                 $show_instructions Whether to render per-DTO instructions text.
	 * @return string
	 */
	private function render_transport_section( array $transport, string $server_slug, string $server_url, bool $show_config, bool $show_instructions ): string {
		$key   = isset( $transport['key'] ) && is_string( $transport['key'] ) ? $transport['key'] : '';
		$label = isset( $transport['label'] ) && is_string( $transport['label'] ) ? $transport['label'] : '';
		$dtos  = isset( $transport['dtos'] ) && is_array( $transport['dtos'] ) ? $transport['dtos'] : array();

		if ( empty( $dtos ) ) {
			return '';
		}

		$out  = '<section class="acrossai-mcp-servers__transport" data-key="' . esc_attr( $key ) . '">';
		$out .= '<h4 class="acrossai-mcp-servers__transport-label">' . esc_html( $label ) . '</h4>';
		$out .= '<ul class="acrossai-mcp-servers__dtos">';
		foreach ( $dtos as $dto ) {
			if ( ! is_array( $dto ) ) {
				continue;
			}
			$out .= $this->render_dto_item( $dto, $key, $server_slug, $server_url, $show_config, $show_instructions );
		}
		$out .= '</ul>';
		$out .= '</section>';

		return $out;
	}

	/**
	 * Render a single DTO `<li>`.
	 *
	 * When `$show_config` is TRUE and the DTO category is known
	 * (`client`, `npm`, `ai_connector`), the render includes per-DTO
	 * "how to connect" content composed via F034/F035 primitives —
	 * never a bespoke re-implementation:
	 *
	 *  - `client`: instantiate the `AbstractMCPClient` subclass named in
	 *    `meta.class`, call `get_config_file()`, `get_top_level_key()`,
	 *    `get_config_snippet( $server_url, EMPTY_TOKEN_PLACEHOLDER )`,
	 *    `get_instructions()`. Render as an inline `<pre>` block.
	 *  - `npm`: substitute `%s` placeholders in `meta.command_template`
	 *    with `home_url()` and `$server_slug`, render as `<pre>`.
	 *  - `ai_connector`: informational text only (connection happens on
	 *    the AI provider side via OAuth; no local paste-in config).
	 *
	 * When `$show_config` is FALSE (opt-out), only the icon + name are
	 * rendered — matches the pre-FR-029 shape for callers that just want
	 * a compact list.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto               DTO projection.
	 * @param string               $transport_key     `client` | `npm` | `ai_connector` | third-party.
	 * @param string               $server_slug       Server slug (needed for NPM `--server=%s`).
	 * @param string               $server_url        Full REST URL for the server.
	 * @param bool                 $show_config       Whether to render per-DTO config content.
	 * @param bool                 $show_instructions Whether to render per-DTO instructions text.
	 * @return string
	 */
	private function render_dto_item(
		array $dto,
		string $transport_key,
		string $server_slug,
		string $server_url,
		bool $show_config,
		bool $show_instructions
	): string {
		$slug = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : '';
		$name = isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : '';
		$icon = isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '';

		$has_details_block = $show_config && '' !== $server_url;
		$li_class          = 'acrossai-mcp-servers__dto' . ( $has_details_block ? ' acrossai-mcp-servers__dto--open' : '' );

		$out  = '<li class="' . esc_attr( $li_class ) . '" data-slug="' . esc_attr( $slug ) . '" data-category="' . esc_attr( $transport_key ) . '">';
		$out .= '<span class="acrossai-mcp-servers__dto-head">';

		if ( '' !== $icon ) {
			if ( self::icon_is_url( $icon ) ) {
				$out .= '<img class="acrossai-mcp-servers__icon" src="' . esc_url( $icon ) . '" alt="" />';
			} else {
				$out .= '<span class="acrossai-mcp-servers__icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
			}
		}

		$out .= '<span class="acrossai-mcp-servers__name">' . esc_html( $name ) . '</span>';
		$out .= '</span>';

		if ( $has_details_block ) {
			$out .= $this->render_dto_details( $dto, $transport_key, $server_slug, $server_url, $show_instructions );
		}

		$out .= '</li>';

		return $out;
	}

	/**
	 * Render the per-DTO "how to connect" block for the three built-in
	 * transport categories. Delegates every string-generation call to
	 * shipped upstream helpers (F034 `AbstractMCPClient` for `client`,
	 * F035 DTO `meta.command_template` for `npm`, `AbstractConnectorProfile`
	 * metadata for `ai_connector`) — never re-implements the format.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto               DTO projection.
	 * @param string               $transport_key     Category machine key.
	 * @param string               $server_slug       Server slug for NPM substitution.
	 * @param string               $server_url        Full REST URL for the server.
	 * @param bool                 $show_instructions Whether to render instructions text.
	 * @return string
	 */
	private function render_dto_details(
		array $dto,
		string $transport_key,
		string $server_slug,
		string $server_url,
		bool $show_instructions
	): string {
		switch ( $transport_key ) {
			case 'client':
				return $this->render_client_config( $dto, $server_url, $show_instructions );
			case 'npm':
				return $this->render_npm_config( $dto, $server_slug, $show_instructions );
			case 'ai_connector':
				return $this->render_ai_connector_info( $dto, $show_instructions );
			default:
				return '';
		}
	}

	/**
	 * Render the "how to connect" block for a `client`-category DTO.
	 * Composes with F034 `AbstractMCPClient` metadata + `get_config_snippet()`
	 * — never re-implements the config-format logic.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto               F035 client DTO with `meta.class` FQCN.
	 * @param string               $server_url        Full REST URL for the server.
	 * @param bool                 $show_instructions Whether to render instructions text.
	 * @return string
	 */
	private function render_client_config( array $dto, string $server_url, bool $show_instructions ): string {
		$meta = isset( $dto['meta'] ) && is_array( $dto['meta'] ) ? $dto['meta'] : array();
		$fqcn = isset( $meta['class'] ) && is_string( $meta['class'] ) ? $meta['class'] : '';

		if ( '' === $fqcn || ! class_exists( $fqcn ) || ! is_subclass_of( $fqcn, AbstractMCPClient::class ) ) {
			return ''; // Malformed DTO — silent no-render, matches F038's defensive posture.
		}

		/** @var AbstractMCPClient $client */
		$client         = new $fqcn();
		$config_file    = $client->get_config_file();
		$top_level_key  = $client->get_top_level_key();
		$snippet_raw    = $client->get_config_snippet( $server_url, AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER );
		$instructions   = $show_instructions ? $client->get_instructions() : '';
		$snippet_string = is_array( $snippet_raw )
			? (string) wp_json_encode( $snippet_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: (string) $snippet_raw;

		$out = '<div class="acrossai-mcp-servers__dto-details">';

		if ( '' !== $config_file ) {
			$out .= '<div class="acrossai-mcp-servers__meta-row">';
			$out .= '<span class="acrossai-mcp-servers__meta-label">' . esc_html__( 'Config file', 'acrossai-mcp-manager' ) . '</span>';
			$out .= '<code>' . esc_html( $config_file ) . '</code>';
			$out .= '</div>';
		}

		if ( '' !== $top_level_key ) {
			$out .= '<div class="acrossai-mcp-servers__meta-row">';
			$out .= '<span class="acrossai-mcp-servers__meta-label">' . esc_html__( 'Top-level key', 'acrossai-mcp-manager' ) . '</span>';
			$out .= '<code>' . esc_html( $top_level_key ) . '</code>';
			$out .= '</div>';
		}

		if ( '' !== $snippet_string ) {
			$out .= '<pre class="acrossai-mcp-servers__snippet">' . esc_html( $snippet_string ) . '</pre>';
		}

		$out .= '<p class="acrossai-mcp-servers__auth-notice">';
		$out .= esc_html__( 'Replace the placeholder token with a WordPress Application Password generated from your profile (Users → Profile → Application Passwords).', 'acrossai-mcp-manager' );
		$out .= '</p>';

		if ( '' !== $instructions ) {
			$out .= '<p class="acrossai-mcp-servers__instructions">' . esc_html( $instructions ) . '</p>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Render the "how to connect" block for an `npm`-category DTO.
	 * Substitutes the two `%s` placeholders in `meta.command_template`
	 * with `home_url()` (site URL, per F035 DTO contract) and
	 * `$server_slug`.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto               F035 npm DTO with `meta.command_template`.
	 * @param string               $server_slug       Server slug for the `--server=` argument.
	 * @param bool                 $show_instructions Whether to render instructions text.
	 * @return string
	 */
	private function render_npm_config( array $dto, string $server_slug, bool $show_instructions ): string {
		$meta     = isset( $dto['meta'] ) && is_array( $dto['meta'] ) ? $dto['meta'] : array();
		$template = isset( $meta['command_template'] ) && is_string( $meta['command_template'] ) ? $meta['command_template'] : '';

		if ( '' === $template ) {
			return '';
		}

		$command     = sprintf( $template, home_url(), $server_slug );
		$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';

		$out  = '<div class="acrossai-mcp-servers__dto-details">';
		$out .= '<pre class="acrossai-mcp-servers__snippet">' . esc_html( $command ) . '</pre>';
		$out .= '<p class="acrossai-mcp-servers__auth-notice">';
		$out .= esc_html__( 'This bridge uses your WordPress Application Password over HTTP Basic auth. Generate one from your profile (Users → Profile → Application Passwords) before running the command.', 'acrossai-mcp-manager' );
		$out .= '</p>';

		if ( $show_instructions && '' !== $description ) {
			$out .= '<p class="acrossai-mcp-servers__instructions">' . esc_html( $description ) . '</p>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Render the "how to connect" block for an `ai_connector`-category DTO.
	 * AI Connectors use OAuth on the AI provider's side — no local
	 * paste-in config exists. Render a short informational block.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto               F035 ai_connector DTO.
	 * @param bool                 $show_instructions Whether to render instructions text.
	 * @return string
	 */
	private function render_ai_connector_info( array $dto, bool $show_instructions ): string {
		$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';

		$out  = '<div class="acrossai-mcp-servers__dto-details">';
		$out .= '<p class="acrossai-mcp-servers__auth-notice">';
		$out .= esc_html__( 'This connector uses OAuth on the AI provider side. Add this WordPress site as an MCP server inside the provider\'s connector settings — you will be redirected here to authorize.', 'acrossai-mcp-manager' );
		$out .= '</p>';

		if ( $show_instructions && '' !== $description ) {
			$out .= '<p class="acrossai-mcp-servers__instructions">' . esc_html( $description ) . '</p>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Detect whether an icon string is an http(s) URL. Whitelist
	 * approach: only `http://` and `https://` prefixes render as
	 * `<img>`. Anything else — including emoji, empty string, `data:`
	 * URIs, and relative paths — renders as `esc_html` text. `esc_url`
	 * at the render seam strips remaining scheme-injection vectors as
	 * defense-in-depth (SEC-003).
	 *
	 * Uses `strpos` for PHP 7.4 compatibility (AGENTS.md declared
	 * minimum). `str_starts_with` is PHP 8.0+.
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
