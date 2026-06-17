<?php
/**
 * Admin notice renderers — action-result notices + adapter-missing notice.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised admin-notice handlers extracted from Settings (RT-2, 2026-06-17).
 *
 * Spec FR-015 explicitly admits "a dedicated `Admin\Partials\Notices` class —
 * implementer's choice"; this is the implementer choosing it.
 *
 * Responsibilities:
 *  - FR-016 — render success/error notice from the `?notice=...` query var
 *             set by Settings::handle_actions() redirects
 *  - FR-015 — render the dismissible "MCP adapter package missing" warning
 *             and persist its per-user, sticky dismissal via admin-ajax (Q3)
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 * Hooks wired by Includes\Main::define_admin_hooks().
 */
class Notices {

	public const ADAPTER_DISMISS_META_KEY     = 'acrossai_mcp_dismissed_adapter_notice';
	public const ADAPTER_DISMISS_NONCE_ACTION = 'acrossai_mcp_dismiss_adapter_notice';

	/** @var Notices|null */
	protected static $_instance = null;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		// NO add_action / add_filter — wired by Includes\Main::define_admin_hooks().
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FR-016 — Action-result notice from `?notice=...` query var.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render success / error notice based on the `notice` query var set by
	 * post-action redirects in Settings::handle_actions(). Wired on `admin_notices`.
	 */
	public function render_action_result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'server_created'   => array( 'success', __( 'Server created.', 'acrossai-mcp-manager' ) ),
			'server_saved'     => array( 'success', __( 'Server saved.', 'acrossai-mcp-manager' ) ),
			'server_deleted'   => array( 'success', __( 'Server deleted.', 'acrossai-mcp-manager' ) ),
			'server_toggled'   => array( 'success', __( 'Server status toggled.', 'acrossai-mcp-manager' ) ),
			'bulk_completed'   => array( 'success', __( 'Bulk action completed.', 'acrossai-mcp-manager' ) ),
			'slug_exists'      => array( 'error',   __( 'Slug already in use.', 'acrossai-mcp-manager' ) ),
			'empty_name'       => array( 'error',   __( 'Server name is required.', 'acrossai-mcp-manager' ) ),
			'db_error'         => array( 'error',   __( 'Database write failed.', 'acrossai-mcp-manager' ) ),
			'server_not_found' => array( 'error',   __( 'Server not found.', 'acrossai-mcp-manager' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// FR-015 / Q3 — Adapter-missing notice (render + dismissal).
	// Contract: specs/002-admin-ui/contracts/notice-dismissal.md
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render a dismissible warning when the WordPress MCP adapter package is
	 * missing. Short-circuits on (a) adapter present, (b) sticky per-user dismissal.
	 * Wired on `admin_notices`.
	 */
	public function render_missing_adapter_notice(): void {
		if ( class_exists( '\WP\MCP\Plugin' ) ) {
			return; // Adapter installed — nothing to surface.
		}
		if ( get_user_meta( get_current_user_id(), self::ADAPTER_DISMISS_META_KEY, true ) ) {
			return; // User already dismissed it — Q3 sticky semantics.
		}

		$nonce = wp_create_nonce( self::ADAPTER_DISMISS_NONCE_ACTION );

		printf(
			'<div class="notice notice-warning is-dismissible acrossai-mcp-adapter-notice" data-nonce="%s"><p>%s</p></div>',
			esc_attr( $nonce ),
			esc_html__( 'The WordPress MCP adapter package is not installed. MCP servers will not respond until you install the wordpress/mcp-adapter package.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Admin-ajax handler: persist the user's dismissal of the adapter notice.
	 * Wired on `wp_ajax_acrossai_mcp_dismiss_adapter_notice`.
	 *
	 * Idempotent: setting the meta to 1 when already 1 is a no-op success.
	 */
	public function handle_adapter_notice_dismissal(): void {
		check_ajax_referer( self::ADAPTER_DISMISS_NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		update_user_meta(
			get_current_user_id(),
			self::ADAPTER_DISMISS_META_KEY,
			1
		);

		wp_send_json_success();
	}
}
