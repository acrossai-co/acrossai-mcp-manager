<?php
/**
 * Frontend renderer for the F037 [acrossai_mcp_embed] shortcode.
 *
 * Renders F035 connection-method DTOs on public pages, gated by the F037
 * 3-check cascade: (1) master toggle ON for the server, (2) per-transport
 * toggle ON for the requested category, (3) F015 access control allows
 * the current user (fail-open per D19 when F015 wrapper absent).
 *
 * `final class` per D36 (extension via `acrossai_mcp_embed_render_html`
 * filter, not subclass). See `specs/036-shortcode-block-embeds/contracts/
 * EmbedBlockRenderer.contract.md` for the full escape shape + filter
 * contract.
 *
 * ## Consumer Security Responsibility (SEC-035-002 inheritance)
 *
 * F035 DTO string fields (`name`, `description`, `icon`, `meta.*`) are
 * NOT pre-escaped by `ConnectionMethodRegistry`. This class escapes at
 * render boundary per contract; any consumer subscribing to
 * `acrossai_mcp_embed_render_html` that inserts new dynamic content MUST
 * escape their own additions.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public\Renderers\EmbedBlock
 * @since      0.1.10
 * @experimental May change without notice before 1.0.0. See
 *               DEC-CLIENT-RENDERER-PUBLIC-API.
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Public\Renderers\EmbedBlock;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;

defined( 'ABSPATH' ) || exit;

final class EmbedBlockRenderer {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $_instance = null;

	/**
	 * Get the singleton instance.
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
	 * Register the [acrossai_mcp_embed] shortcode. Wired to `init` by
	 * `Main::define_public_hooks()` per A1 (Loader-wired bootstrap
	 * method — A1 transitivity per D17 permits the nested add_shortcode
	 * call here without violating the "no add_* outside Main.php" rule).
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		add_shortcode( 'acrossai_mcp_embed', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Shortcode callback for `[acrossai_mcp_embed]`. Registered via
	 * `register_shortcode()` which is Loader-wired in
	 * `Main::define_public_hooks()` per A1.
	 *
	 * @param array|string $atts_raw Shortcode attributes.
	 * @return string Rendered HTML (empty string on any gate miss).
	 */
	public function render_shortcode( $atts_raw ): string {
		$atts = shortcode_atts(
			array(
				'server'   => '',
				'category' => '',
				'slug'     => '',
			),
			is_array( $atts_raw ) ? $atts_raw : array(),
			'acrossai_mcp_embed'
		);

		$server_slug   = sanitize_text_field( (string) $atts['server'] );
		$category      = sanitize_text_field( (string) $atts['category'] );
		$dto_slug_attr = sanitize_text_field( (string) $atts['slug'] );

		// Step 1 — server resolution.
		if ( '' === $server_slug || '' === $category ) {
			return '';
		}
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);
		if ( empty( $rows ) ) {
			return '';
		}
		$server_id = (int) $rows[0]->id;

		// Step 2 — Gate 1: F015 access control (master + per-DTO gates
		// evaluated per-DTO in Step 4 below). Fail-open per D19 when
		// wrapper absent (matches F015/F017/F020/F030/F032 precedent).
		if ( class_exists( '\AcrossAI_MCP_Access_Control' ) ) {
			$user_id = get_current_user_id();
			if ( ! \AcrossAI_MCP_Access_Control::user_has_server_access( $user_id, $server_id ) ) {
				return '';
			}
		}

		// Step 3 — DTO resolution. If `slug` attribute present, resolve a
		// single DTO via find(); else resolve the whole category.
		$dtos = array();
		if ( '' !== $dto_slug_attr ) {
			$single = ConnectionMethodRegistry::instance()->find( $category, $dto_slug_attr );
			if ( null === $single ) {
				return '';
			}
			$dtos = array( $single );
		} else {
			$dtos = $this->get_all_dtos_for_category( $category );
			if ( empty( $dtos ) ) {
				return '';
			}
		}

		// Step 4 — Per-DTO gate cascade (master toggle → per-DTO toggle).
		// F037 per-DTO redesign: master gate lives inside
		// `AbstractEmbedTransport::is_enabled_for_server` (checks
		// wp_acrossai_mcp_servers.embeds_enabled first), then the specific
		// (server_id, category, dto_slug) presence row. Silent-drop any
		// DTO whose per-DTO toggle is OFF. Empty result → silent no-render.
		$enabled_dtos = array();
		foreach ( $dtos as $dto ) {
			if ( ! is_array( $dto ) || empty( $dto['slug'] ) ) {
				continue;
			}
			if ( AbstractEmbedTransport::is_enabled_for_server( $server_id, $category, (string) $dto['slug'] ) ) {
				$enabled_dtos[] = $dto;
			}
		}
		if ( empty( $enabled_dtos ) ) {
			return '';
		}
		$dtos = $enabled_dtos;

		// Step 5 — Render.
		$html = $this->render_dtos( $dtos, $server_id );

		/**
		 * Filter the final rendered HTML for the [acrossai_mcp_embed]
		 * shortcode. Consumer contract: MAY wrap / decorate / replace
		 * the assembled HTML. Consumer inherits the SEC-035-002
		 * preservation invariant — new dynamic content MUST be escaped.
		 *
		 * @since 0.1.10
		 *
		 * @param string $html      Assembled HTML from the gate cascade.
		 * @param array  $atts      Normalized shortcode attributes.
		 * @param int    $server_id Resolved server primary key.
		 */
		$html = (string) apply_filters( 'acrossai_mcp_embed_render_html', $html, $atts, $server_id );

		return $html;
	}

	/**
	 * Return DTOs for a category from the F035 discovery registry.
	 * F037 transport keys align 1:1 with F035 DTO `category` field per
	 * Clarifications Q1, but F035's `get_all()` uses plural array keys —
	 * this helper maps between them.
	 *
	 * @param string $category F037 transport key (`npm` / `client` / `ai_connector` / third-party).
	 * @return array<int, array<string, mixed>>
	 */
	private function get_all_dtos_for_category( string $category ): array {
		$registry = ConnectionMethodRegistry::instance();
		switch ( $category ) {
			case 'npm':
				return $registry->get_npm_methods();
			case 'client':
				return $registry->get_clients();
			case 'ai_connector':
				return $registry->get_ai_connectors();
			default:
				// Companion-plugin transport category — F035 doesn't know about it.
				// Silent no-render (matches cascade contract).
				return array();
		}
	}

	/**
	 * Render a list of DTOs into HTML with escape-at-boundary per
	 * `contracts/EmbedBlockRenderer.contract.md`.
	 *
	 * @param array<int, array<string, mixed>> $dtos      DTO list.
	 * @param int                              $server_id Resolved server PK (for possible future use in the template).
	 * @return string
	 */
	private function render_dtos( array $dtos, int $server_id ): string {
		unset( $server_id ); // Reserved for future template context — not consumed by the current shape.

		$out  = '<div class="acrossai-mcp-embed">';
		$out .= '<ul class="acrossai-mcp-embed__list">';
		foreach ( $dtos as $dto ) {
			if ( ! is_array( $dto ) || ! isset( $dto['slug'], $dto['name'] ) ) {
				continue; // Defensive: F035 already validates DTO shape, but skip malformed just in case.
			}

			$slug        = is_string( $dto['slug'] ) ? $dto['slug'] : '';
			$name        = is_string( $dto['name'] ) ? $dto['name'] : '';
			$description = isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '';
			$icon        = isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '';

			$out .= '<li class="acrossai-mcp-embed__item" data-slug="' . esc_attr( $slug ) . '">';
			if ( '' !== $icon ) {
				// Icon may be emoji (text) OR URL (for ai_connectors).
				if ( false !== filter_var( $icon, FILTER_VALIDATE_URL ) ) {
					$out .= '<img class="acrossai-mcp-embed__icon" src="' . esc_url( $icon ) . '" alt="" />';
				} else {
					$out .= '<span class="acrossai-mcp-embed__icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
				}
			}
			$out .= '<span class="acrossai-mcp-embed__name">' . esc_html( $name ) . '</span>';
			if ( '' !== $description ) {
				$out .= '<span class="acrossai-mcp-embed__description">' . esc_html( $description ) . '</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ul>';
		$out .= '</div>';

		return $out;
	}
}
