<?php
/**
 * Admin area entry point — asset enqueue with plugin-page guard.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace AcrossAI_MCP_Manager\Admin;

use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

/**
 * Loads backend.js / backend.css on plugin admin pages only (US5).
 *
 * Per FR-017 / FR-018 / FR-019 + research.md R5:
 *   - get_current_screen() whitelist of three plugin screen IDs
 *   - file_exists() guard around the *.asset.php include
 *   - version + dependencies sourced from build/{js,css}/backend.asset.php
 *     — no hardcoded version or dependency array
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 * Hooks wired by Includes\Main::define_admin_hooks().
 */
class Main {

	/** @var Main|null */
	protected static $_instance = null;

	/** @var string */
	private $plugin_name;

	/** @var string */
	private $version;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
		$this->version     = ACROSSAI_MCP_MANAGER_VERSION;
		// Asset manifest reads are deferred to enqueue_*() — see notes there
		// for the file_exists() guard (FR-019) and the screen-ID guard (FR-017).
	}

	private function is_plugin_admin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, AdminPageSlugs::plugin_screen_ids(), true );
	}

	/**
	 * Lazy-load an asset manifest. Returns null when the file is missing
	 * so callers can silently skip enqueue (FR-019).
	 *
	 * @return array{dependencies: string[], version: string}|null
	 */
	private function read_asset_manifest( string $relative_path ): ?array {
		$path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . $relative_path;
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$asset = include $path;
		if ( ! is_array( $asset ) || ! isset( $asset['version'], $asset['dependencies'] ) ) {
			return null;
		}
		return $asset;
	}

	/**
	 * Enqueue backend.css on plugin admin pages only.
	 *
	 * Wired on `admin_enqueue_scripts` by Includes\Main::define_admin_hooks().
	 */
	public function enqueue_styles(): void {
		if ( ! $this->is_plugin_admin_screen() ) {
			return;
		}
		$asset = $this->read_asset_manifest( 'build/css/backend.asset.php' );
		if ( null === $asset ) {
			return;
		}
		wp_enqueue_style(
			$this->plugin_name,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/css/backend.css' ),
			$asset['dependencies'],
			$asset['version'],
			'all'
		);
	}

	/**
	 * Enqueue backend.js on plugin admin pages only.
	 *
	 * Wired on `admin_enqueue_scripts` by Includes\Main::define_admin_hooks().
	 */
	public function enqueue_scripts(): void {
		if ( ! $this->is_plugin_admin_screen() ) {
			return;
		}
		$asset = $this->read_asset_manifest( 'build/js/backend.asset.php' );
		if ( null === $asset ) {
			return;
		}
		wp_enqueue_script(
			$this->plugin_name,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/backend.js' ),
			$asset['dependencies'],
			$asset['version'],
			true // load in footer
		);
	}
}
