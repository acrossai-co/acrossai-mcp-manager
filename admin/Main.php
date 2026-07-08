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

		// F015 — Access Control tab React app (vendor's <AccessControl> component).
		// Only enqueue on the per-server-edit page with tab=access-control so we
		// don't ship the React bundle on unrelated screens.
		$this->maybe_enqueue_access_control_app();

		// F017 — Abilities tab React app (@wordpress/dataviews).
		// Scoped to the Abilities tab only — same guard shape as F015.
		$this->maybe_enqueue_abilities_app();
	}

	/**
	 * Enqueue the vendor AccessControl React app on the Access Control tab.
	 *
	 * @since 0.0.7
	 * @return void
	 */
	private function maybe_enqueue_access_control_app(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$is_edit = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
		$is_ac   = isset( $_GET['tab'] ) && 'access-control' === sanitize_key( wp_unslash( $_GET['tab'] ) );
		if ( ! $is_edit || ! $is_ac ) {
			return;
		}
		// phpcs:enable

		$asset = $this->read_asset_manifest( 'build/js/access-control.asset.php' );
		if ( null === $asset ) {
			return;
		}

		$handle = $this->plugin_name . '-access-control';
		wp_enqueue_script(
			$handle,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/access-control.js' ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Vendor CSS bundled alongside the JS entry (webpack emits alongside
		// the .js/.asset.php). Same handle so the style is deregistered when
		// the script is.
		wp_enqueue_style(
			$handle,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/access-control.css' ),
			array(),
			$asset['version']
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$server_id = isset( $_GET['server'] ) ? absint( wp_unslash( $_GET['server'] ) ) : 0;
		// phpcs:enable
		$server_slug = '';
		if ( $server_id > 0 ) {
			$rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
				array(
					'id'     => $server_id,
					'number' => 1,
				)
			);
			if ( ! empty( $rows ) ) {
				$server_slug = (string) $rows[0]->server_slug;
			}
		}

		wp_localize_script(
			$handle,
			'acrossaiMcpAccessControl',
			array(
				'pluginSlug'  => \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::TABLE_SLUG,
				'namespace'   => 'acrossai-mcp-manager',
				'resourceKey' => $server_slug,
				// The vendor's React component concatenates restApiRoot + '/wpb-ac/…'.
				// `rest_url()` returns with a trailing slash, which would produce
				// `/wp-json//wpb-ac/…` → 404. Strip the trailing slash here.
				'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Enqueue the F017 Abilities tab React app on the Abilities tab only.
	 *
	 * Mirrors the F015 `maybe_enqueue_access_control_app()` shape verbatim —
	 * `?action=edit` + `?tab=abilities` guard, silent bail on missing asset
	 * manifest (FR-019), localize the `acrossaiMcpAbilities` config.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private function maybe_enqueue_abilities_app(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$is_edit      = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
		$is_abilities = isset( $_GET['tab'] ) && 'abilities' === sanitize_key( wp_unslash( $_GET['tab'] ) );
		if ( ! $is_edit || ! $is_abilities ) {
			return;
		}
		// phpcs:enable

		$asset = $this->read_asset_manifest( 'build/js/abilities.asset.php' );
		if ( null === $asset ) {
			return;
		}

		$handle = $this->plugin_name . '-abilities';
		wp_enqueue_script(
			$handle,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/abilities.js' ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// SCSS is optional — emit a matching stylesheet only if webpack
		// produced `build/js/abilities.css` alongside the JS.
		$css_path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/abilities.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				$handle,
				esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/abilities.css' ),
				array(),
				$asset['version']
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$server_id = isset( $_GET['server'] ) ? absint( wp_unslash( $_GET['server'] ) ) : 0;
		// phpcs:enable
		$server_slug = '';
		if ( $server_id > 0 ) {
			$rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
				array(
					'id'     => $server_id,
					'number' => 1,
				)
			);
			if ( ! empty( $rows ) ) {
				$server_slug = (string) $rows[0]->server_slug;
			}
		}

		wp_localize_script(
			$handle,
			'acrossaiMcpAbilities',
			array(
				'serverId'    => $server_id,
				'serverSlug'  => $server_slug,
				// B17 defense — `rest_url()` returns with a trailing slash;
				// the client concatenates `restApiRoot + '/acrossai-mcp-manager/v1/…'`
				// so we strip the slash here to avoid `//`-doubled routes → 404.
				'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'namespace'   => 'acrossai-mcp-manager/v1',
			)
		);
	}
}
