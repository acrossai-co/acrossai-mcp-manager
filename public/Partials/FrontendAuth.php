<?php
/**
 * Browser-mediated CLI authentication approval page (Phase 6.0).
 *
 * Singleton + private ctor (A2). Zero hooks in the constructor (A1) —
 * Main::define_public_hooks wires every callback via the Loader. The
 * static `get_base_url()` helper is the one piece other modules
 * (CliController, Activator) couple to.
 *
 * @package AcrossAI_MCP_Manager\Public\Partials
 */

namespace AcrossAI_MCP_Manager\Public\Partials;

use AcrossAI_MCP_Manager\Includes\REST\CliController;

defined( 'ABSPATH' ) || exit;

final class FrontendAuth {

	const PAGE_SLUG = 'acrossai-mcp-manager';
	const QUERY_VAR = 'acrossai_mcp_frontend_auth';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead.
	 */
	private function __construct() {}

	/**
	 * Base URL of the approval page — `https://{site}/acrossai-mcp-manager/`.
	 *
	 * Static so callers (CliController::handle_auth_start, Activator) can
	 * reach it without holding a singleton reference.
	 */
	public static function get_base_url(): string {
		return home_url( '/' . self::PAGE_SLUG . '/' );
	}

	/**
	 * Register the single rewrite rule. Wired on `init` via Loader.
	 *
	 * Pattern has no `.` to escape — B4 not triggered here.
	 */
	public function register_rewrite_rule(): void {
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Append the custom query var. Wired on `query_vars` via Loader.
	 *
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Asset enqueue stub. Wired on `wp_enqueue_scripts` via Loader.
	 *
	 * No JS this phase; CSS is inline in `render_page_shell` so themes
	 * cannot collide with our consent flow.
	 */
	public function enqueue_assets(): void {
		// Intentionally empty.
	}

	/**
	 * Dispatch on `template_redirect` — branches by `?action=…`.
	 *
	 * Wired via Loader on `template_redirect` priority 10.
	 */
	public function maybe_render_page(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		nocache_headers();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::get_base_url() ) );
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ),
				403
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only render of GET params; the state-mutating cli_auth_approve branch verifies nonce explicitly via check_admin_referer.
		$action  = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );

		if ( ! $enabled ) {
			$this->render_disabled_notice();
			exit;
		}

		switch ( $action ) {
			case 'cli_auth':
				$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
				$server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : '';
				$this->handle_cli_auth( $code, $server );
				break;
			case 'cli_auth_approve':
				$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
				$server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : '';
				check_admin_referer( 'cli_auth_approve_' . $code );
				$this->handle_approve( $code, $server );
				break;
			case 'cli_auth_approved':
				$this->handle_approved();
				break;
			default:
				$this->handle_cli_auth( '', '' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		exit;
	}

	/**
	 * Render the consent form for `?action=cli_auth`.
	 *
	 * @param string $code   Authorization code from CLI's `/auth/start`.
	 * @param string $server Server slug from CLI's `/auth/start`.
	 */
	private function handle_cli_auth( string $code, string $server ): void {
		if ( '' === $code || '' === $server ) {
			$this->render_page_shell(
				'<h1>' . esc_html__( 'Missing Authentication Parameters', 'acrossai-mcp-manager' ) . '</h1>'
				. '<p>' . esc_html__( 'This page must be opened via a link from your CLI tool.', 'acrossai-mcp-manager' ) . '</p>'
			);
			return;
		}

		$approve_url = add_query_arg(
			array(
				'action'   => 'cli_auth_approve',
				'code'     => $code,
				'server'   => $server,
				'_wpnonce' => wp_create_nonce( 'cli_auth_approve_' . $code ),
			),
			self::get_base_url()
		);

		$html  = '<h1>' . esc_html__( 'Authorize CLI Access', 'acrossai-mcp-manager' ) . '</h1>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: server slug */
				__( 'A CLI tool is requesting access to your MCP server "%1$s".', 'acrossai-mcp-manager' ),
				$server
			)
		) . '</p>';
		$html .= '<p>' . esc_html__( 'Click Approve to grant the tool access. The session is single-use.', 'acrossai-mcp-manager' ) . '</p>';
		$html .= '<p><a class="button button-primary" href="' . esc_url( $approve_url ) . '">'
			. esc_html__( 'Approve', 'acrossai-mcp-manager' ) . '</a></p>';

		$this->render_page_shell( $html );
	}

	/**
	 * Handle Approve click — state-mutating; called only after nonce verify.
	 *
	 * @param string $code   Authorization code.
	 * @param string $server Server slug (validated by CliController::approve_auth_code via the transient).
	 */
	private function handle_approve( string $code, string $server ): void {
		unset( $server ); // server is validated downstream via the transient's stored value.

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to approve CLI authentication.', 'acrossai-mcp-manager' ),
				403
			);
		}

		if ( '' === $code ) {
			wp_die( esc_html__( 'Missing authorization code.', 'acrossai-mcp-manager' ), 400 );
		}

		$approved = CliController::approve_auth_code( $code, get_current_user_id() );

		if ( ! $approved ) {
			wp_die(
				esc_html__( 'This authorization code is no longer valid. It may have expired or been used already.', 'acrossai-mcp-manager' ),
				400
			);
		}

		wp_safe_redirect( add_query_arg( 'action', 'cli_auth_approved', self::get_base_url() ) );
		exit;
	}

	/**
	 * Render the success page after `handle_approve` redirect.
	 */
	private function handle_approved(): void {
		$html  = '<h1>' . esc_html__( 'CLI Authorization Approved', 'acrossai-mcp-manager' ) . '</h1>';
		$html .= '<p>' . esc_html__( 'You can now return to your CLI tool — it will detect the approval shortly.', 'acrossai-mcp-manager' ) . '</p>';
		$html .= '<p>' . esc_html__( 'This page can be closed.', 'acrossai-mcp-manager' ) . '</p>';
		$this->render_page_shell( $html );
	}

	/**
	 * Render the kill-switch notice when feature flag is OFF.
	 */
	private function render_disabled_notice(): void {
		status_header( 503 );
		$html  = '<h1>' . esc_html__( 'CLI Login Not Enabled', 'acrossai-mcp-manager' ) . '</h1>';
		$html .= '<p>' . esc_html__( 'The CLI login flow is currently disabled on this site. Contact your administrator.', 'acrossai-mcp-manager' ) . '</p>';
		$this->render_page_shell( $html );
	}

	/**
	 * Wrap body content in a minimal HTML shell — NO `wp_head()` so themes
	 * cannot inject markup into the consent flow.
	 *
	 * @param string $content Pre-escaped HTML body (caller's responsibility).
	 */
	private function render_page_shell( string $content ): void {
		header( 'Content-Type: text/html; charset=UTF-8' );
		$title = esc_html__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' );

		echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>';
		echo '<meta charset="utf-8">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<style>body{font-family:system-ui,sans-serif;max-width:520px;margin:5em auto;padding:0 1em;color:#1d2327}h1{font-size:1.5em}.button{display:inline-block;padding:0.5em 1.5em;background:#2271b1;color:#fff;border:1px solid #2271b1;border-radius:3px;text-decoration:none;font-size:1em}.button-primary{font-weight:600}</style>';
		echo '</head><body>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller pre-escapes; this is the docblock contract.
		echo $content;
		echo '</body></html>';
	}
}
