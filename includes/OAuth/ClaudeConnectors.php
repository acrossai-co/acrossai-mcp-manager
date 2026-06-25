<?php
/**
 * Claude Connectors orchestrator — discovery + authorize + consent dispatch.
 *
 * Singleton + private ctor (constitution A2). Zero hooks in the constructor
 * (FR-021 / A1) — Main::define_public_hooks wires every callback below via
 * the Loader.
 *
 * @package AcrossAI_MCP_Manager\Includes\OAuth
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row as MCPServerRow;

defined( 'ABSPATH' ) || exit;

final class ClaudeConnectors {

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
	 * Register OAuth rewrite rules — three rules, all dot-escaped (B4).
	 *
	 * Wired via Loader on `init`.
	 */
	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?acrossai_mcp_oauth=as_metadata',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?acrossai_mcp_oauth=rs_metadata',
			'top'
		);
		add_rewrite_rule(
			'^acrossai-mcp-oauth/?$',
			'index.php?acrossai_mcp_oauth=authorize',
			'top'
		);
	}

	/**
	 * Wired via Loader on `query_vars`.
	 *
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = 'acrossai_mcp_oauth';
		return $vars;
	}

	/**
	 * Dispatch on `template_redirect` — branches by query var value.
	 *
	 * Wired via Loader on `template_redirect`.
	 */
	public function serve_discovery_or_authorize(): void {
		$mode = (string) get_query_var( 'acrossai_mcp_oauth' );
		if ( '' === $mode ) {
			return;
		}

		switch ( $mode ) {
			case 'as_metadata':
				$this->serve_as_metadata();
				return;
			case 'rs_metadata':
				$this->serve_rs_metadata();
				return;
			case 'authorize':
				if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
					$this->handle_consent_submit();
					return;
				}
				$this->render_authorize_page();
				return;
		}
	}

	/**
	 * RFC 8414 §3 — authorization server metadata.
	 */
	public function serve_as_metadata(): void {
		nocache_headers();
		header( 'Cache-Control: public, max-age=86400' );

		$payload = array(
			'issuer'                                => home_url(),
			'authorization_endpoint'                => home_url( '/acrossai-mcp-oauth/' ),
			'token_endpoint'                        => rest_url( 'acrossai-mcp/v1/token' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'client_secret_post' ),
			'scopes_supported'                      => array( 'mcp' ),
		);

		wp_send_json( $payload, 200 );
		exit;
	}

	/**
	 * RFC 9728 — protected resource metadata.
	 */
	public function serve_rs_metadata(): void {
		nocache_headers();
		header( 'Cache-Control: public, max-age=86400' );

		$payload = array(
			'resource'                 => untrailingslashit( rest_url( 'mcp' ) ),
			'authorization_servers'    => array( home_url() ),
			'bearer_methods_supported' => array( 'header' ),
		);

		wp_send_json( $payload, 200 );
		exit;
	}

	/**
	 * GET /acrossai-mcp-oauth/?... — render consent page or error.
	 *
	 * FR-004 → FR-007 validation chain (param presence → resolve client →
	 * redirect_uri match → logged in → admin → render).
	 */
	public function render_authorize_page(): void {
		$required = array(
			'response_type',
			'client_id',
			'redirect_uri',
			'scope',
			'code_challenge',
			'code_challenge_method',
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET-only
		// authorize page; the POST handler (handle_consent_submit) verifies the nonce.
		foreach ( $required as $field ) {
			if ( ! isset( $_GET[ $field ] ) || '' === trim( (string) wp_unslash( $_GET[ $field ] ) ) ) {
				$this->render_error_page( 'invalid_request', 'Missing required parameter: ' . $field );
				return;
			}
		}

		$response_type         = sanitize_text_field( (string) wp_unslash( $_GET['response_type'] ) );
		$client_id             = sanitize_text_field( (string) wp_unslash( $_GET['client_id'] ) );
		$redirect_uri          = esc_url_raw( (string) wp_unslash( $_GET['redirect_uri'] ) );
		$scope                 = sanitize_text_field( (string) wp_unslash( $_GET['scope'] ) );
		$code_challenge        = sanitize_text_field( (string) wp_unslash( $_GET['code_challenge'] ) );
		$code_challenge_method = sanitize_text_field( (string) wp_unslash( $_GET['code_challenge_method'] ) );
		$state                 = isset( $_GET['state'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'code' !== $response_type ) {
			$this->render_error_page( 'unsupported_response_type', 'Only response_type=code is supported.' );
			return;
		}
		if ( 'S256' !== $code_challenge_method ) {
			$this->render_error_page( 'invalid_request', 'Only code_challenge_method=S256 is supported.' );
			return;
		}
		if ( 'mcp' !== $scope ) {
			$this->render_error_page( 'invalid_scope', 'Only scope=mcp is supported.' );
			return;
		}
		if ( strlen( $code_challenge ) !== 43 ) {
			$this->render_error_page( 'invalid_request', 'code_challenge MUST be 43 chars base64url.' );
			return;
		}

		$server = $this->resolve_server_by_client_id( $client_id );
		if ( null === $server ) {
			AuditLog::instance()->write(
				AuditLog::EVENT_FAILED_UNKNOWN_CLIENT,
				array( 'client_id' => $client_id )
			);
			$this->render_error_page( 'invalid_client', 'Unknown client_id.' );
			return;
		}

		if ( ! hash_equals( (string) $server->claude_connector_redirect_uri, $redirect_uri ) ) {
			AuditLog::instance()->write(
				AuditLog::EVENT_FAILED_REDIRECT_MISMATCH,
				array(
					'client_id' => $client_id,
					'server_id' => $server->id,
					'details'   => array(
						'expected_redirect' => $server->claude_connector_redirect_uri,
						'received_redirect' => $redirect_uri,
					),
				)
			);
			$this->render_error_page( 'invalid_request', 'redirect_uri does not match registered value.' );
			return;
		}

		if ( ! is_user_logged_in() ) {
			$this_url = home_url( add_query_arg( null, null ) );
			wp_safe_redirect( wp_login_url( $this_url ) );
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			$this->render_error_page( 'access_denied', 'You do not have permission to authorize OAuth clients.' );
			return;
		}

		$this->render_consent_form( $server, $client_id, $redirect_uri, $scope, $state, $code_challenge, $code_challenge_method );
	}

	/**
	 * POST /acrossai-mcp-oauth/ — Approve / Deny handler.
	 *
	 * Nonce + manage_options recheck (S1). Issues code (Approve) or
	 * redirects with error=access_denied (Deny).
	 */
	public function handle_consent_submit(): void {
		if ( ! isset( $_POST['acrossai_mcp_oauth_server_id'] ) ) {
			wp_die( esc_html__( 'Invalid OAuth consent submission.', 'acrossai-mcp-manager' ), 400 );
		}

		$server_id = absint( wp_unslash( $_POST['acrossai_mcp_oauth_server_id'] ) );
		check_admin_referer( 'acrossai_mcp_oauth_consent_' . $server_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to authorize OAuth clients.', 'acrossai-mcp-manager' ), 403 );
		}

		$decision     = isset( $_POST['oauth_decision'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['oauth_decision'] ) ) : '';
		$client_id    = isset( $_POST['client_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['client_id'] ) ) : '';
		$redirect_uri = isset( $_POST['redirect_uri'] ) ? esc_url_raw( (string) wp_unslash( $_POST['redirect_uri'] ) ) : '';
		$scope        = isset( $_POST['scope'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['scope'] ) ) : '';
		$state        = isset( $_POST['state'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['state'] ) ) : '';
		$challenge    = isset( $_POST['code_challenge'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['code_challenge'] ) ) : '';
		$method       = isset( $_POST['code_challenge_method'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['code_challenge_method'] ) ) : '';

		$server = $this->resolve_server_by_client_id( $client_id );
		if ( null === $server || (int) $server->id !== $server_id ) {
			wp_die( esc_html__( 'Server resolution failed during consent submission.', 'acrossai-mcp-manager' ), 400 );
		}

		if ( 'approve' === $decision ) {
			$raw_code = Storage::instance()->issue_authorization_code(
				$client_id,
				$server_id,
				get_current_user_id(),
				$redirect_uri,
				$challenge,
				$method,
				$scope
			);
			if ( '' === $raw_code ) {
				// Edge case: DB write failure during code issuance — surface 503 (server_error) per spec.
				wp_die( esc_html__( 'Failed to issue authorization code.', 'acrossai-mcp-manager' ), 503 );
			}
			AuditLog::instance()->write(
				AuditLog::EVENT_CODE_ISSUED,
				array(
					'client_id' => $client_id,
					'server_id' => $server_id,
					'user_id'   => get_current_user_id(),
				)
			);
			$args = array( 'code' => $raw_code );
			if ( '' !== $state ) {
				$args['state'] = $state;
			}
			wp_safe_redirect( esc_url_raw( add_query_arg( $args, $redirect_uri ) ) );
			exit;
		}

		AuditLog::instance()->write(
			AuditLog::EVENT_CONSENT_DENIED,
			array(
				'client_id' => $client_id,
				'server_id' => $server_id,
				'user_id'   => get_current_user_id(),
			)
		);
		$args = array( 'error' => 'access_denied' );
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_safe_redirect( esc_url_raw( add_query_arg( $args, $redirect_uri ) ) );
		exit;
	}

	/**
	 * Hook callback for the daily cleanup cron.
	 *
	 * Wired via Loader on `acrossai_mcp_oauth_cleanup`.
	 */
	public function handle_cleanup_event(): void {
		$counts = Storage::instance()->cleanup_oauth_data();
		AuditLog::instance()->write(
			AuditLog::EVENT_CLEANUP_RUN,
			array( 'details' => $counts )
		);
	}

	/**
	 * Resolve an MCP server row by its `claude_connector_client_id` value.
	 *
	 * @param string $client_id Client identifier from the request.
	 */
	private function resolve_server_by_client_id( string $client_id ): ?MCPServerRow {
		if ( '' === $client_id ) {
			return null;
		}
		$query   = new MCPServerQuery();
		$results = $query->query(
			array(
				'claude_connector_client_id' => $client_id,
				'number'                     => 1,
			)
		);
		return $results[0] ?? null;
	}

	/**
	 * Render the RFC 6749 §4.1.1 consent page — plain HTML form with
	 * the Approve / Deny buttons and the hidden mirror of the authorize
	 * request parameters.
	 *
	 * @param MCPServerRow $server                Resolved server row.
	 * @param string       $client_id             Requesting client identifier.
	 * @param string       $redirect_uri          Redirect URI to echo through the form.
	 * @param string       $scope                 Requested scope.
	 * @param string       $state                 Opaque state value to echo on redirect.
	 * @param string       $code_challenge        PKCE S256 challenge.
	 * @param string       $code_challenge_method MUST be `S256`.
	 */
	private function render_consent_form(
		MCPServerRow $server,
		string $client_id,
		string $redirect_uri,
		string $scope,
		string $state,
		string $code_challenge,
		string $code_challenge_method
	): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );

		$server_name  = (string) $server->server_name;
		$action_url   = home_url( '/acrossai-mcp-oauth/' );
		$nonce_action = 'acrossai_mcp_oauth_consent_' . (int) $server->id;

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
		echo '<title>' . esc_html__( 'Authorize OAuth client', 'acrossai-mcp-manager' ) . '</title>';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<style>body{font-family:system-ui,sans-serif;max-width:540px;margin:5em auto;padding:0 1em;color:#1d2327}h1{font-size:1.5em}.box{background:#f6f7f7;border:1px solid #c3c4c7;padding:1em 1.5em;border-radius:4px;margin-top:1.5em}button{padding:0.5em 1.5em;margin-right:0.5em;border:1px solid #2271b1;background:#2271b1;color:#fff;border-radius:3px;cursor:pointer;font-size:1em}button.deny{background:#fff;color:#1d2327}</style></head><body>';
		echo '<h1>' . esc_html__( 'Authorize OAuth client', 'acrossai-mcp-manager' ) . '</h1>';
		echo '<div class="box">';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: client_id, 2: server name */
				__( 'Client %1$s is requesting access to MCP server "%2$s" with scope %3$s.', 'acrossai-mcp-manager' ),
				$client_id,
				$server_name,
				$scope
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		wp_nonce_field( $nonce_action );
		echo '<input type="hidden" name="acrossai_mcp_oauth_server_id" value="' . esc_attr( (string) $server->id ) . '">';
		echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
		echo '<input type="hidden" name="redirect_uri" value="' . esc_attr( $redirect_uri ) . '">';
		echo '<input type="hidden" name="scope" value="' . esc_attr( $scope ) . '">';
		echo '<input type="hidden" name="state" value="' . esc_attr( $state ) . '">';
		echo '<input type="hidden" name="code_challenge" value="' . esc_attr( $code_challenge ) . '">';
		echo '<input type="hidden" name="code_challenge_method" value="' . esc_attr( $code_challenge_method ) . '">';
		echo '<button type="submit" name="oauth_decision" value="approve">' . esc_html__( 'Approve', 'acrossai-mcp-manager' ) . '</button>';
		echo '<button type="submit" name="oauth_decision" value="deny" class="deny">' . esc_html__( 'Deny', 'acrossai-mcp-manager' ) . '</button>';
		echo '</form></div></body></html>';
		exit;
	}

	/**
	 * Render a minimal HTML error page for non-redirectable failure modes
	 * (RFC 6749 §4.1.2.1 — unknown client / redirect_uri mismatch).
	 *
	 * @param string $error_code  RFC error_code value.
	 * @param string $description Human-readable description.
	 */
	private function render_error_page( string $error_code, string $description ): void {
		nocache_headers();
		status_header( 400 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
		echo '<title>' . esc_html__( 'OAuth error', 'acrossai-mcp-manager' ) . '</title>';
		echo '</head><body>';
		echo '<h1>' . esc_html__( 'OAuth error', 'acrossai-mcp-manager' ) . '</h1>';
		echo '<p><strong>' . esc_html( $error_code ) . '</strong>: ' . esc_html( $description ) . '</p>';
		echo '</body></html>';
		exit;
	}
}
