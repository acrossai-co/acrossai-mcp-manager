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
				'heading'          => '',
				'show_description' => '1',
				'empty_message'    => __( 'You do not have access to any MCP server yet.', 'acrossai-mcp-manager' ),
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
		$out              = '<div class="acrossai-mcp-servers">';
		$heading          = (string) $atts['heading'];
		$show_description = '1' === (string) $atts['show_description'];

		if ( '' !== $heading ) {
			$out .= '<h2 class="acrossai-mcp-servers__heading">' . esc_html( $heading ) . '</h2>';
		}

		$out .= '<ul class="acrossai-mcp-servers__list">';
		foreach ( $data as $server ) {
			if ( ! is_array( $server ) ) {
				continue;
			}
			$out .= $this->render_server_item( $server, $show_description );
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
	 * @param array<string, mixed> $server           Server projection.
	 * @param bool                 $show_description Whether to render the description paragraph.
	 * @return string
	 */
	private function render_server_item( array $server, bool $show_description ): string {
		$server_id          = isset( $server['server_id'] ) ? (int) $server['server_id'] : 0;
		$server_slug        = isset( $server['server_slug'] ) && is_string( $server['server_slug'] ) ? $server['server_slug'] : '';
		$server_name        = isset( $server['server_name'] ) && is_string( $server['server_name'] ) ? $server['server_name'] : '';
		$server_description = isset( $server['description'] ) && is_string( $server['description'] ) ? $server['description'] : '';
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
			$out .= $this->render_transport_section( $transport );
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
	 * @param array<string, mixed> $transport Transport projection.
	 * @return string
	 */
	private function render_transport_section( array $transport ): string {
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
			$out .= $this->render_dto_item( $dto );
		}
		$out .= '</ul>';
		$out .= '</section>';

		return $out;
	}

	/**
	 * Render a single DTO `<li>`.
	 *
	 * @since 0.1.11
	 *
	 * @param array<string, mixed> $dto DTO projection.
	 * @return string
	 */
	private function render_dto_item( array $dto ): string {
		$slug = isset( $dto['slug'] ) && is_string( $dto['slug'] ) ? $dto['slug'] : '';
		$name = isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : '';
		$icon = isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '';

		$out = '<li class="acrossai-mcp-servers__dto" data-slug="' . esc_attr( $slug ) . '">';

		if ( '' !== $icon ) {
			if ( self::icon_is_url( $icon ) ) {
				$out .= '<img class="acrossai-mcp-servers__icon" src="' . esc_url( $icon ) . '" alt="" />';
			} else {
				$out .= '<span class="acrossai-mcp-servers__icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
			}
		}

		$out .= '<span class="acrossai-mcp-servers__name">' . esc_html( $name ) . '</span>';
		$out .= '</li>';

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
