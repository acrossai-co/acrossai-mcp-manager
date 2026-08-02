<?php
/**
 * AI Connectors promo tab — fallback placeholder when the
 * acrossai-ai-connectors companion add-on is not installed / not active.
 *
 * Post-Feature 040, the real AI Connectors admin surface lives in the
 * companion plugin. When the companion is active, it registers its real
 * `AIConnectorsTab` via the `acrossai_mcp_manager_server_tabs` filter at
 * priority 35, and Registry's last-wins dedup (also introduced in F040)
 * replaces this built-in placeholder with the real tab automatically. This
 * class only renders when no such filter registration overrides it — i.e.
 * when the companion is missing OR installed-but-inactive.
 *
 * Structure + state-resolution pattern mirrors
 * `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php`
 * (F030 sibling-plugin promo). CSS is scoped to `acrossai-mcp-aic-promo__*`
 * and emitted once per request via a static flag. All external URLs use
 * `target="_blank" rel="noopener noreferrer"`.
 *
 * Constitution v1.1.0 §IV Connector-picker card layout exception applies —
 * this is a branded promo card, not a filterable/sortable data grid, so
 * hand-rolled markup is preferred over DataViews/DataForm.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.2.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * AI Connectors promo tab (placeholder for the companion plugin).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.2.0
 */
final class AIConnectorsPromoTab extends AbstractServerTab {

	/**
	 * Companion plugin slug — the folder name under wp-content/plugins/.
	 */
	private const SIBLING_SLUG = 'acrossai-ai-connectors';

	/**
	 * Shared Add-ons page slug — matches `\AcrossAI_Main_Menu\SettingsPage::ADDONS_SLUG`.
	 */
	private const ADDONS_PAGE_SLUG = 'acrossai-addons';

	/**
	 * External landing page for the AI Connectors add-on.
	 */
	private const LANDING_URL = 'https://acrossai.co/ai-connectors/';

	/**
	 * Whether the scoped CSS has been emitted this request. Prevents
	 * duplicate `<style>` blocks when the tab renders more than once.
	 *
	 * @var bool
	 */
	private static $styles_emitted = false;

	/**
	 * Returns the tab slug.
	 *
	 * MUST match the companion plugin's slug so the URL
	 * `?page=acrossai_mcp_manager&action=edit&server=<id>&tab=ai-connectors`
	 * resolves either to this placeholder or (when the companion is active
	 * and its filter callback overrides via last-wins dedup) to the real tab.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'ai-connectors';
	}

	/**
	 * Returns the tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'AI Connectors', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot — matches the pre-F040 built-in position (between
	 * ClientsTab at 30 and WpCliTab at 40) and matches the companion's
	 * registration priority so replacement is visually seamless.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 35;
	}

	/**
	 * Renders the promo card body.
	 *
	 * Bypasses the default `render_body()` scaffold from AbstractServerTab
	 * because the promo card is a self-contained visual unit — no heading /
	 * description / server-disabled notice needed. Wrapped in the standard
	 * `<div class="mcp-tab-panel">` so admin CSS gutters stay consistent
	 * with sibling tabs.
	 *
	 * @param array<string, mixed> $server Server row data (unused; kept for signature compat).
	 * @return void
	 */
	protected function render_body( array $server ): void {
		unset( $server ); // Promo does not vary by server.

		$state = $this->resolve_state();

		// Safety net: if somehow we get here with 'active', it means the
		// companion is loaded but its filter callback failed to run — bail
		// silently rather than shadow the real tab. Should not happen under
		// normal flow because last-wins dedup would have replaced us.
		if ( 'active' === $state ) {
			return;
		}

		$this->emit_styles_once();

		echo '<div class="mcp-tab-panel">';
		echo '<div class="acrossai-mcp-aic-promo__card">';

		// Header row: icon + heading.
		echo '<div class="acrossai-mcp-aic-promo__head">';
		echo '<div class="acrossai-mcp-aic-promo__icon" aria-hidden="true">';
		echo $this->render_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG literal, no dynamic content.
		echo '</div>';
		echo '<h2 class="acrossai-mcp-aic-promo__title">' . esc_html__( 'AI Connectors', 'acrossai-mcp-manager' ) . '</h2>';
		echo '</div>';

		// Description.
		echo '<p class="acrossai-mcp-aic-promo__desc">';
		echo esc_html__( 'Connect your WordPress site to leading AI platforms and services with pre-built connectors — extend your workflows without writing custom integrations.', 'acrossai-mcp-manager' );
		echo '</p>';

		// Action row: primary CTA on the left, "Learn more" on the right.
		echo '<div class="acrossai-mcp-aic-promo__actions">';
		$this->render_primary_cta( $state );
		$this->render_learn_more_link();
		echo '</div>';

		echo '</div>'; // .acrossai-mcp-aic-promo__card
		echo '</div>'; // .mcp-tab-panel
	}

	/**
	 * Resolves the companion plugin's install/active state.
	 *
	 * Returns one of:
	 *   - 'active'   — plugin file present AND `is_plugin_active()` true
	 *   - 'inactive' — plugin file present, not active
	 *   - 'missing'  — plugin file not present in wp-content/plugins
	 *
	 * @return string
	 */
	private function resolve_state(): string {
		$this->ensure_plugin_helpers_loaded();

		$plugin_file = $this->find_sibling_plugin_file();
		if ( null === $plugin_file ) {
			return 'missing';
		}
		if ( is_plugin_active( $plugin_file ) ) {
			return 'active';
		}
		return 'inactive';
	}

	/**
	 * Locates the companion plugin's main file (relative to wp-content/plugins/).
	 *
	 * Prefers `\AcrossAI_Main_Menu\AddonsInstaller::find_plugin_file()` when
	 * the vendor helper is loaded (handles edge cases where the plugin folder
	 * name differs from the slug). Falls back to a `get_plugins()` scan
	 * matching entries whose path starts with `acrossai-ai-connectors/`.
	 *
	 * @return string|null Plugin file path (e.g. `acrossai-ai-connectors/acrossai-ai-connectors.php`) or null when missing.
	 */
	private function find_sibling_plugin_file(): ?string {
		if ( class_exists( '\\AcrossAI_Main_Menu\\AddonsInstaller' ) ) {
			$installer = new \AcrossAI_Main_Menu\AddonsInstaller();
			$found     = $installer->find_plugin_file( array( 'slug' => self::SIBLING_SLUG ) );
			return null === $found ? null : (string) $found;
		}

		foreach ( (array) get_plugins() as $file => $data ) {
			unset( $data );
			if ( 0 === strpos( (string) $file, self::SIBLING_SLUG . '/' ) ) {
				return (string) $file;
			}
		}
		return null;
	}

	/**
	 * Ensures the admin plugin helpers (`is_plugin_active`, `get_plugins`)
	 * are loaded. They live in wp-admin/includes/plugin.php which is not
	 * autoloaded on every request.
	 *
	 * @return void
	 */
	private function ensure_plugin_helpers_loaded(): void {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Renders the primary CTA button. Label + URL depend on the resolved
	 * companion state.
	 *
	 * @param string $state One of 'inactive' or 'missing'.
	 * @return void
	 */
	private function render_primary_cta( string $state ): void {
		if ( 'inactive' === $state ) {
			$plugin_file = $this->find_sibling_plugin_file();
			// Should always be non-null when state is 'inactive', but guard
			// against a race where the plugin was deleted between resolve
			// and render.
			if ( null === $plugin_file ) {
				$this->render_install_button();
				return;
			}
			$activate_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'activate',
						'plugin' => rawurlencode( $plugin_file ),
					),
					admin_url( 'plugins.php' )
				),
				'activate-plugin_' . $plugin_file
			);
			printf(
				'<a class="button button-primary button-hero acrossai-mcp-aic-promo__cta" href="%1$s">%2$s</a>',
				esc_url( $activate_url ),
				esc_html__( 'Activate', 'acrossai-mcp-manager' )
			);
			return;
		}

		// 'missing' — direct the operator to the Add-ons page (or landing
		// page fallback if the Add-ons page isn't registered).
		$this->render_install_button();
	}

	/**
	 * Renders the "Install" button pointing at the shared Add-ons page,
	 * falling back to the external landing page when the Add-ons page
	 * hasn't been registered (e.g. the operator hasn't visited the main
	 * menu yet in this session).
	 *
	 * @return void
	 */
	private function render_install_button(): void {
		$addons_url = menu_page_url( self::ADDONS_PAGE_SLUG, false );
		if ( empty( $addons_url ) ) {
			$addons_url = self::LANDING_URL;
		}
		printf(
			'<a class="button button-primary button-hero acrossai-mcp-aic-promo__cta" href="%1$s">%2$s</a>',
			esc_url( $addons_url ),
			esc_html__( 'Install', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Renders the "Learn more" text link to the external landing page.
	 *
	 * @return void
	 */
	private function render_learn_more_link(): void {
		printf(
			'<a class="acrossai-mcp-aic-promo__learn-more" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( self::LANDING_URL ),
			esc_html__( 'Learn more', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Returns the inline SVG for the AI Connectors brand icon — an
	 * interconnected-nodes network graphic matching the design mockup.
	 * Static literal; safe to echo without escaping.
	 *
	 * @return string SVG markup.
	 */
	private function render_icon_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="40" height="40" role="img" focusable="false">'
			// Connection lines — drawn first so nodes overlay them.
			. '<g stroke="#4f46e5" stroke-width="1.5" fill="none" stroke-linecap="round">'
			. '<line x1="24" y1="24" x2="24" y2="8" />'
			. '<line x1="24" y1="24" x2="40" y2="16" />'
			. '<line x1="24" y1="24" x2="40" y2="32" />'
			. '<line x1="24" y1="24" x2="24" y2="40" />'
			. '<line x1="24" y1="24" x2="8" y2="32" />'
			. '<line x1="24" y1="24" x2="8" y2="16" />'
			. '<line x1="24" y1="8" x2="40" y2="16" />'
			. '<line x1="40" y1="16" x2="40" y2="32" />'
			. '<line x1="40" y1="32" x2="24" y2="40" />'
			. '<line x1="24" y1="40" x2="8" y2="32" />'
			. '<line x1="8" y1="32" x2="8" y2="16" />'
			. '<line x1="8" y1="16" x2="24" y2="8" />'
			. '</g>'
			// Outer nodes.
			. '<g fill="#4f46e5">'
			. '<circle cx="24" cy="8" r="3" />'
			. '<circle cx="40" cy="16" r="3" />'
			. '<circle cx="40" cy="32" r="3" />'
			. '<circle cx="24" cy="40" r="3" />'
			. '<circle cx="8" cy="32" r="3" />'
			. '<circle cx="8" cy="16" r="3" />'
			. '</g>'
			// Central node — larger, filled.
			. '<circle cx="24" cy="24" r="4.5" fill="#4f46e5" />'
			. '<circle cx="24" cy="24" r="2" fill="#ffffff" />'
			. '</svg>';
	}

	/**
	 * Emits the promo card's scoped stylesheet once per request. Layout only
	 * (flex row, icon container background, button + link spacing) — inherits
	 * WordPress admin visual language for typography + button styling.
	 *
	 * @return void
	 */
	private function emit_styles_once(): void {
		if ( self::$styles_emitted ) {
			return;
		}
		self::$styles_emitted = true;

		echo '<style>'
			. '.acrossai-mcp-aic-promo__card{'
			. 'background:#fff;border:1px solid #dcdcde;border-radius:6px;'
			. 'box-shadow:0 1px 2px rgba(0,0,0,.04);padding:24px;max-width:720px;}'
			. '.acrossai-mcp-aic-promo__head{'
			. 'display:flex;align-items:center;gap:16px;margin-bottom:12px;}'
			. '.acrossai-mcp-aic-promo__icon{'
			. 'width:64px;height:64px;border-radius:8px;background:#eef2ff;'
			. 'display:flex;align-items:center;justify-content:center;flex:0 0 auto;}'
			. '.acrossai-mcp-aic-promo__title{'
			. 'margin:0;padding:0;font-size:20px;line-height:1.2;font-weight:600;color:#1d2327;}'
			. '.acrossai-mcp-aic-promo__desc{'
			. 'margin:0 0 20px 0;color:#50575e;font-size:14px;line-height:1.5;}'
			. '.acrossai-mcp-aic-promo__actions{'
			. 'display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}'
			. '.acrossai-mcp-aic-promo__cta{'
			. 'min-width:120px;text-align:center;}'
			. '.acrossai-mcp-aic-promo__learn-more{'
			. 'color:#4f46e5;text-decoration:none;font-weight:500;}'
			. '.acrossai-mcp-aic-promo__learn-more:hover,'
			. '.acrossai-mcp-aic-promo__learn-more:focus{'
			. 'color:#3730a3;text-decoration:underline;}'
			. '</style>';
	}
}
