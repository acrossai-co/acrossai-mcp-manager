<?php
/**
 * OAuth Authorization Endpoint — renders the consent page and issues auth codes.
 *
 * Served as a virtual frontend page via WP rewrite rule:
 *   GET  /acrossai-mcp-manager/oauth/authorize/ → show consent form
 *   POST /acrossai-mcp-manager/oauth/authorize/ → validate nonce, issue code, redirect
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

use ACROSSAI_MCP_MANAGER\Database\OAuthClientsTable;
use ACROSSAI_MCP_MANAGER\Database\OAuthAuthCodesTable;
use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the OAuth 2.1 authorization endpoint (PKCE, resource indicators).
 *
 * @since 1.6.0
 */
class AuthorizationEndpoint {

	/**
	 * Query var that identifies requests to the authorize page.
	 */
	const QUERY_VAR = 'acrossai_mcp_oauth_authorize';

	/**
	 * Dispatch GET/POST requests to the authorize endpoint.
	 *
	 * Called from template_redirect when the query var is present.
	 *
	 * @return void
	 */
	public static function handle_request() {
		if ( ! (bool) get_option( 'acrossai_mcp_oauth_enabled', false ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' === $method ) {
			self::handle_post();
		} else {
			self::handle_get();
		}
	}

	// -------------------------------------------------------------------------
	// GET — render consent page
	// -------------------------------------------------------------------------

	/**
	 * Render the consent page for a GET authorize request.
	 *
	 * @return void
	 */
	private static function handle_get() {
		// phpcs:disable WordPress.Security.NonceVerification
		$client_id             = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect_uri          = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$state                 = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code_challenge        = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : 'S256';
		$resource              = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : '';
		$scope                 = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : 'mcp';
		// phpcs:enable WordPress.Security.NonceVerification

		// Require login — redirect to wp-login.php with return URL.
		if ( ! is_user_logged_in() ) {
			$return_url = add_query_arg( array_map( 'sanitize_text_field', $_GET ), home_url( 'acrossai-mcp-manager/oauth/authorize/' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( wp_login_url( $return_url ) );
			exit;
		}

		$error = self::validate_authorize_params( $client_id, $redirect_uri, $code_challenge, $code_challenge_method, $resource );
		if ( $error ) {
			self::render_error_page( $error );
			return;
		}

		$client      = OAuthClientsTable::get( $client_id );
		$server_name = self::get_server_name_for_resource( $resource );

		header( 'Content-Type: text/html; charset=utf-8' );
		self::render_consent_page( $client, $server_name, $client_id, $redirect_uri, $state, $code_challenge, $code_challenge_method, $resource, $scope );
		exit;
	}

	// -------------------------------------------------------------------------
	// POST — process form submission
	// -------------------------------------------------------------------------

	/**
	 * Process the consent form POST and redirect to the callback.
	 *
	 * @return void
	 */
	private static function handle_post() {
		// phpcs:disable WordPress.Security.NonceVerification
		$client_id             = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$redirect_uri          = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '';
		$state                 = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$code_challenge        = isset( $_POST['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_POST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge_method'] ) ) : 'S256';
		$resource              = isset( $_POST['resource'] ) ? esc_url_raw( wp_unslash( $_POST['resource'] ) ) : '';
		$scope                 = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'mcp';
		$approved              = isset( $_POST['approve'] );
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		check_admin_referer( 'acrossai_mcp_oauth_authorize_' . $client_id );

		// User denied — redirect with error.
		if ( ! $approved ) {
			wp_safe_redirect(
				add_query_arg(
					array_filter( array(
						'error'             => 'access_denied',
						'error_description' => 'The user denied access.',
						'state'             => $state ?: null,
					) ),
					$redirect_uri
				)
			);
			exit;
		}

		$error = self::validate_authorize_params( $client_id, $redirect_uri, $code_challenge, $code_challenge_method, $resource );
		if ( $error ) {
			wp_safe_redirect( add_query_arg( array( 'error' => 'invalid_request', 'state' => $state ), $redirect_uri ) );
			exit;
		}

		// Generate a single-use authorization code.
		$code      = bin2hex( random_bytes( 32 ) );
		$code_hash = hash( 'sha256', $code );

		$inserted = OAuthAuthCodesTable::insert(
			$code_hash,
			$client_id,
			get_current_user_id(),
			$redirect_uri,
			$scope,
			$resource,
			$code_challenge,
			$code_challenge_method,
			60
		);

		if ( ! $inserted ) {
			wp_safe_redirect( add_query_arg( array( 'error' => 'server_error', 'state' => $state ), $redirect_uri ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array_filter( array(
					'code'  => $code,
					'state' => $state ?: null,
				) ),
				$redirect_uri
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate the common authorize request parameters.
	 *
	 * @param string $client_id             Client ID.
	 * @param string $redirect_uri          Redirect URI from request.
	 * @param string $code_challenge        PKCE code challenge.
	 * @param string $code_challenge_method Must be 'S256'.
	 * @param string $resource              MCP server canonical URI.
	 *
	 * @return string|null Error message or null if valid.
	 */
	private static function validate_authorize_params( $client_id, $redirect_uri, $code_challenge, $code_challenge_method, $resource ) {
		if ( empty( $client_id ) ) {
			return 'Missing client_id.';
		}

		$client = OAuthClientsTable::get( $client_id );
		if ( ! $client ) {
			return 'Unknown client_id.';
		}

		if ( empty( $redirect_uri ) || ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			return 'redirect_uri does not match any registered URI for this client.';
		}

		if ( ! empty( $code_challenge ) && 'S256' !== $code_challenge_method ) {
			return 'Only S256 code_challenge_method is supported.';
		}

		if ( empty( $resource ) ) {
			return 'resource parameter is required.';
		}

		// Verify the resource corresponds to a connector-enabled server.
		$server = self::find_server_by_mcp_url( $resource );
		if ( ! $server || empty( $server['connector_enabled'] ) ) {
			return 'Requested resource is not available as a connector.';
		}

		return null;
	}

	/**
	 * Find the server row whose mcp_url matches the given resource URI.
	 *
	 * @param string $resource Canonical MCP server URI.
	 *
	 * @return array|null
	 */
	private static function find_server_by_mcp_url( $resource ) {
		foreach ( MCPServerTable::get_all() as $row ) {
			$ns    = ! empty( $row['server_route_namespace'] ) ? $row['server_route_namespace'] : 'mcp';
			$route = ! empty( $row['server_route'] ) ? $row['server_route'] : $row['server_slug'];
			if ( rest_url( $ns . '/' . $route ) === $resource ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Return the human-readable server name for the given MCP resource URI.
	 *
	 * @param string $resource Canonical MCP server URI.
	 *
	 * @return string
	 */
	private static function get_server_name_for_resource( $resource ) {
		$server = self::find_server_by_mcp_url( $resource );
		return $server ? $server['server_name'] : $resource;
	}

	// -------------------------------------------------------------------------
	// HTML rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the HTML consent page.
	 *
	 * @param array  $client                Registered client row.
	 * @param string $server_name           Human-readable server name.
	 * @param string $client_id             Client ID.
	 * @param string $redirect_uri          Callback URI.
	 * @param string $state                 CSRF state parameter.
	 * @param string $code_challenge        PKCE challenge.
	 * @param string $code_challenge_method 'S256'.
	 * @param string $resource              MCP server URI.
	 * @param string $scope                 Approved scope.
	 *
	 * @return void
	 */
	private static function render_consent_page(
		array $client,
		$server_name,
		$client_id,
		$redirect_uri,
		$state,
		$code_challenge,
		$code_challenge_method,
		$resource,
		$scope
	) {
		$nonce     = wp_create_nonce( 'acrossai_mcp_oauth_authorize_' . $client_id );
		$blog_name = get_bloginfo( 'name' );
		$user      = wp_get_current_user();

		$form_url = home_url( 'acrossai-mcp-manager/oauth/authorize/' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( __( 'Authorize Connector', 'acrossai-mcp-manager' ) . ' — ' . $blog_name ); ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, sans-serif;
	font-size: 14px;
	line-height: 1.6;
	color: #1d2327;
	background: #f0f0f1;
	margin: 0;
	padding: 40px 20px;
}
.acrossai-auth-wrap {
	max-width: 480px;
	margin: 0 auto;
	background: #fff;
	border-radius: 4px;
	box-shadow: 0 1px 3px rgba(0,0,0,.13);
	padding: 32px;
}
.acrossai-auth-logo { text-align: center; margin-bottom: 24px; }
.acrossai-auth-logo h1 { font-size: 18px; font-weight: 600; color: #1d2327; margin: 0; }
.acrossai-auth-logo p { font-size: 12px; color: #646970; margin: 4px 0 0; }
.acrossai-notice { border-left: 4px solid #72aee6; background: #f0f6fc; padding: 10px 14px; margin-bottom: 20px; border-radius: 0 3px 3px 0; }
.acrossai-notice-warning { border-color: #dba617; background: #fcf9e8; }
.acrossai-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
.acrossai-table th, .acrossai-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
.acrossai-table th { width: 40%; color: #646970; font-weight: 500; }
.acrossai-table code { background: #f6f7f7; padding: 2px 6px; border-radius: 3px; font-size: 13px; word-break: break-all; }
.acrossai-actions { display: flex; gap: 10px; margin-top: 24px; }
.acrossai-btn { display: inline-block; padding: 8px 18px; font-size: 13px; font-weight: 500; border-radius: 3px; border: 1px solid #c3c4c7; background: #f6f7f7; color: #1d2327; text-decoration: none; cursor: pointer; line-height: 1.4; }
.acrossai-btn:hover { background: #f0f0f1; }
.acrossai-btn-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
.acrossai-btn-primary:hover { background: #135e96; border-color: #135e96; color: #fff; }
.acrossai-description { color: #646970; font-size: 13px; }
</style>
</head>
<body>
<div class="acrossai-auth-wrap">
	<div class="acrossai-auth-logo">
		<h1><?php esc_html_e( 'MCP Manager', 'acrossai-mcp-manager' ); ?></h1>
		<p><?php echo esc_html( $blog_name ); ?></p>
	</div>

	<div class="acrossai-notice acrossai-notice-warning">
		<strong>
			<?php
			printf(
				/* translators: %s: client name */
				esc_html__( '"%s" wants to access your MCP server.', 'acrossai-mcp-manager' ),
				esc_html( $client['client_name'] )
			);
			?>
		</strong>
	</div>

	<table class="acrossai-table">
		<tr>
			<th><?php esc_html_e( 'Server', 'acrossai-mcp-manager' ); ?></th>
			<td><strong><?php echo esc_html( $server_name ); ?></strong></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Logged in as', 'acrossai-mcp-manager' ); ?></th>
			<td><?php echo esc_html( $user->user_login ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Access', 'acrossai-mcp-manager' ); ?></th>
			<td><?php esc_html_e( 'Read and write access to MCP tools on this site', 'acrossai-mcp-manager' ); ?></td>
		</tr>
	</table>

	<p class="acrossai-description">
		<?php esc_html_e( 'Approving grants the connector an access token scoped to this MCP server. You can revoke it at any time from the server settings.', 'acrossai-mcp-manager' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( $form_url ); ?>">
		<?php wp_nonce_field( 'acrossai_mcp_oauth_authorize_' . $client_id ); ?>
		<input type="hidden" name="client_id"             value="<?php echo esc_attr( $client_id ); ?>">
		<input type="hidden" name="redirect_uri"          value="<?php echo esc_attr( $redirect_uri ); ?>">
		<input type="hidden" name="state"                 value="<?php echo esc_attr( $state ); ?>">
		<input type="hidden" name="code_challenge"        value="<?php echo esc_attr( $code_challenge ); ?>">
		<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>">
		<input type="hidden" name="resource"              value="<?php echo esc_attr( $resource ); ?>">
		<input type="hidden" name="scope"                 value="<?php echo esc_attr( $scope ); ?>">

		<div class="acrossai-actions">
			<button type="submit" name="approve" value="1" class="acrossai-btn acrossai-btn-primary">
				<?php esc_html_e( 'Approve', 'acrossai-mcp-manager' ); ?>
			</button>
			<button type="submit" class="acrossai-btn">
				<?php esc_html_e( 'Deny', 'acrossai-mcp-manager' ); ?>
			</button>
		</div>
	</form>
</div>
</body>
</html>
		<?php
	}

	/**
	 * Render a simple error page (no redirect possible — bad client params).
	 *
	 * @param string $message Error message.
	 *
	 * @return void
	 */
	private static function render_error_page( $message ) {
		$blog_name = get_bloginfo( 'name' );
		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( 400 );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<title><?php echo esc_html( __( 'Authorization Error', 'acrossai-mcp-manager' ) . ' — ' . $blog_name ); ?></title>
<style>body{font-family:sans-serif;max-width:480px;margin:60px auto;padding:20px;}</style>
</head>
<body>
<h1><?php esc_html_e( 'Authorization Error', 'acrossai-mcp-manager' ); ?></h1>
<p><?php echo esc_html( $message ); ?></p>
</body>
</html>
		<?php
		exit;
	}
}
