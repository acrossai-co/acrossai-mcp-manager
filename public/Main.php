<?php
/**
 * Public-facing enqueue orchestrator — scoped to the OAuth authorize/consent
 * surface ONLY.
 *
 * Phase 8 refactor (2026-07-01) closes the previous global asset-leak defect
 * where `enqueue_styles/scripts` fired on every front-end page. Post-Phase-8:
 *
 *   - CLI consent surface enqueue is owned entirely by
 *     `Public\Partials\FrontendAuth::enqueue_assets()` (Phase 7). This class
 *     does NOT compete with it. B12: `wp_enqueue_scripts` never fires when
 *     FrontendAuth exits from `template_redirect` before `wp_head()`, so any
 *     CLI-surface code in this method would be dead. DEV3: avoiding a parallel
 *     Public\Main → Public\Partials\FrontendAuth import prevents a bidirectional
 *     coupling parallel to the T044 A9-promotion cleanup.
 *   - OAuth authorize/consent surface enqueue is owned HERE. Guarded on the
 *     `ClaudeConnectors::is_authorize_page()` predicate (R1 research).
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/public
 */

namespace AcrossAI_MCP_Manager\Public;

use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Public-facing enqueue orchestrator.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/public
 * @author     WPBoilerplate <contact@wpboilerplate.com>
 */
class Main {

	/**
	 * Enqueue handle for the OAuth consent surface CSS.
	 */
	const OAUTH_STYLE_HANDLE = 'acrossai-mcp-frontend-oauth';

	/**
	 * The single instance of the class.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Main instance.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead. Prevents B5 double-registration.
	 */
	private function __construct() {}

	/**
	 * Register the OAuth-consent stylesheet. Wired to `wp_enqueue_scripts`
	 * via Loader.
	 *
	 * Guarded — no-op on any surface that is not the OAuth authorize/consent
	 * page. Reads version + dependencies from `build/css/frontend-oauth.asset.php`
	 * via B11 defensive triple-check; falls back to the plugin version constant
	 * on missing/malformed manifest.
	 *
	 * @since 0.0.1
	 */
	public function enqueue_styles(): void {
		if ( ! ClaudeConnectors::is_authorize_page() ) {
			return;
		}

		$asset = $this->read_asset_manifest( 'frontend-oauth', 'css' );

		wp_enqueue_style(
			self::OAUTH_STYLE_HANDLE,
			\plugins_url( 'build/css/frontend-oauth.css', \ACROSSAI_MCP_MANAGER_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version']
		);

		// FR-021 — WP auto-substitutes `build/css/frontend-oauth-rtl.css` when
		// `is_rtl()` returns true.
		wp_style_add_data( self::OAUTH_STYLE_HANDLE, 'rtl', 'replace' );
	}

	/**
	 * Register the OAuth-consent JavaScript. Wired to `wp_enqueue_scripts`
	 * via Loader.
	 *
	 * The v0.0.4 `assets/frontend-oauth.css` had no companion JS, and the
	 * OAuth consent form (`ClaudeConnectors::render_consent_form`) is a plain
	 * HTML form — no client-side scripting required. This method is retained
	 * as a hook target so future OAuth-consent JS additions don't need new
	 * Loader wiring, but currently no-ops after the guard.
	 *
	 * @since 0.0.1
	 */
	public function enqueue_scripts(): void {
		if ( ! ClaudeConnectors::is_authorize_page() ) {
			return;
		}

		// No JS is currently enqueued on the OAuth consent surface — see
		// method docblock. Reserved for future extension.
	}

	/**
	 * Read a `build/{css|js}/<handle>.asset.php` manifest with B11 defensive
	 * triple-check. Returns a shape-guaranteed array — callers can consume
	 * `['dependencies']` and `['version']` without further checks.
	 *
	 * Silent fallback on missing / unreadable / malformed manifest: version
	 * is the plugin constant, dependencies are `[]`. No `error_log`, no
	 * `_doing_it_wrong` — the SEC-008-001 deploy-time gate is the operational
	 * signal for missing manifests.
	 *
	 * @param string $handle_stem Manifest basename without extension (e.g. `'frontend-oauth'`).
	 * @param string $bucket      `'css'` or `'js'`.
	 * @return array{dependencies: string[], version: string}
	 */
	private function read_asset_manifest( string $handle_stem, string $bucket ): array {
		$fallback = array(
			'dependencies' => array(),
			'version'      => \ACROSSAI_MCP_MANAGER_VERSION,
		);

		$path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/' . $bucket . '/' . $handle_stem . '.asset.php';
		if ( ! \file_exists( $path ) || ! \is_readable( $path ) ) {
			return $fallback;
		}

		$asset = include $path;

		// B11 defensive triple-check (generalized from transient reads to
		// require-returned arrays).
		if ( ! \is_array( $asset ) ) {
			return $fallback;
		}
		if ( ! isset( $asset['dependencies'], $asset['version'] ) ) {
			return $fallback;
		}
		if ( ! \is_array( $asset['dependencies'] ) ) {
			return $fallback;
		}
		if ( ! \is_string( $asset['version'] ) || '' === $asset['version'] ) {
			return $fallback;
		}

		return array(
			'dependencies' => $asset['dependencies'],
			'version'      => $asset['version'],
		);
	}
}
