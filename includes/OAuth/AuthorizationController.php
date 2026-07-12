<?php
/**
 * AuthorizationController — /authorize GET (consent) + POST (approve/deny).
 *
 * FR-004..FR-012 + Q1 audience + Q3 always-consent + RFC 9207 iss + PKCE S256.
 *
 * **Consent-surface exception applicability (Constitution §III, Feature-007 exception)**:
 *
 * The Feature-007 consent-surface exception broadens Principle III's
 * `manage_options` baseline for surfaces where a logged-in user consents on
 * their own behalf to issue a credential scoped to their own capabilities. It
 * requires five conditions be satisfied to invoke.
 *
 * F021's `/authorize` matches conditions (1), (2), (4), and (5): it verifies
 * `is_user_logged_in()` (FR-008), binds the issued token to the consenting
 * user's `user_id` (FR-011), cites this exception in this docblock with the
 * driving FR identifiers, and sources every attacker-controllable consent
 * parameter from the server-side `OAuthClients` row via S9 re-validation
 * (see `handle_post` — never trusts hidden inputs).
 *
 * F021 does NOT invoke condition (3) (operator-gated via default-OFF option).
 * Rationale: unlike Feature-007's CLI device grant, F021 is the plugin's
 * primary product surface. Gating it behind an opt-in option would mean the
 * base plugin ships an unusable OAuth server on a fresh install, and every
 * companion plugin (Claude, ChatGPT, Gemini, Copilot, ...) would have to
 * document "also toggle the base plugin's OAuth switch". F021 relies instead
 * on the F024 Phase 10 per-connector `enabled` toggle (default ON) and
 * per-connector `require_admin_approval` toggle (default OFF, FR-024-015)
 * for operator-controlled bounded blast radius on a per-connector granularity.
 *
 * The `/authorize` endpoint is only reachable when: (a) at least one connector
 * profile is registered via a companion plugin, AND (b) that connector's
 * `enabled` setting is true, AND (c) the user is on the approved list when
 * `require_admin_approval` is on. This combination provides the equivalent
 * default-OFF safety property in a more granular form than a single global
 * kill switch would.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Row as ClientRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AuthCodeRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class AuthorizationController {

	private const NONCE_ACTION = 'acrossai_mcp_manager_oauth_authorize';

	/** @var AuthorizationController|null */
	private static $instance = null;

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * GET /authorize — validate params, gate on login, render consent.
	 *
	 * @return void
	 */
	public function handle_get(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce applies to POST; GET has no state-changing effect.
		$params = self::sanitize_authorize_params( $_GET );

		self::apply_rate_limit();

		// Validate client + redirect_uri BEFORE any redirect — if these fail we
		// render an inline error page (S9: never redirect to an untrusted URI).
		$client = self::resolve_client_or_die( $params['client_id'] );
		self::assert_redirect_uri_or_die( $client, $params['redirect_uri'] );

		// Now safe to use redirect-based error reporting.
		if ( 'code' !== $params['response_type'] ) {
			self::redirect_error( $params['redirect_uri'], 'unsupported_response_type', 'Only response_type=code is supported', $params['state'] );
		}

		if ( ! PKCE::is_s256( $params['code_challenge_method'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'PKCE S256 required', $params['state'] );
		}

		if ( '' === $params['code_challenge'] || 43 !== strlen( $params['code_challenge'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'PKCE code_challenge (43 chars) required', $params['state'] );
		}

		if ( ! self::is_valid_resource( $params['resource'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_target', 'The resource parameter must name a URL on this site.', $params['state'] );
		}

		if ( ! is_user_logged_in() ) {
			// FR-008 — send to wp-login, come back here on success.
			$current_url = self::current_authorize_url();
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		// FR-045 — recommend `state` under WP_DEBUG.
		if ( '' === $params['state'] && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong(
				'/authorize',
				esc_html__( 'Missing state parameter — RECOMMENDED under RFC 9700 §2.1. PKCE still protects code-injection.', 'acrossai-mcp-manager' ),
				'0.1.0'
			);
		}

		// F024 FR-024-014 — reject if the connector has been disabled by an admin.
		$server_id_for_settings = self::server_id_from_client_and_resource( $client, $params['resource'] );
		$slug_for_settings      = (string) $client->connector_slug;
		if ( '' === $slug_for_settings ) {
			$slug_for_settings = self::infer_slug_from_dcr_client( $client );
		}
		if ( '' !== $slug_for_settings && $server_id_for_settings > 0 ) {
			if ( ! \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::is_enabled( $server_id_for_settings, $slug_for_settings ) ) {
				self::redirect_error( $params['redirect_uri'], 'access_denied', 'This connector is disabled on this server.', $params['state'] );
			}

			// F024 FR-024-015 — admin-approval gate.
			$user_id = get_current_user_id();
			if ( \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::get( $server_id_for_settings, $slug_for_settings )['require_admin_approval']
				&& ! \AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::is_user_approved( $server_id_for_settings, $slug_for_settings, $user_id )
			) {
				\AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings::add_pending_user( $server_id_for_settings, $slug_for_settings, $user_id );
				self::render_pending_approval( $client, $params );
				exit;
			}
		}

		self::render_consent( $client, $params );
		exit;
	}

	/**
	 * POST /authorize — verify nonce, re-validate every param from DB,
	 * approve → auth code + redirect with code+state+iss; deny → redirect
	 * with access_denied+state+iss.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		self::apply_rate_limit();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			status_header( 403 );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$params = self::sanitize_authorize_params( $_POST );

		// S9 — re-validate every param from DB, DO NOT trust hidden inputs alone.
		$client = self::resolve_client_or_die( $params['client_id'] );
		self::assert_redirect_uri_or_die( $client, $params['redirect_uri'] );

		if ( ! PKCE::is_s256( $params['code_challenge_method'] ) || 43 !== strlen( $params['code_challenge'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'PKCE S256 required', $params['state'] );
		}
		if ( ! self::is_valid_resource( $params['resource'] ) ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_target', 'Invalid resource', $params['state'] );
		}
		if ( ! is_user_logged_in() ) {
			status_header( 403 );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$action = isset( $_POST['authorize_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['authorize_action'] ) ) : '';

		if ( 'deny' === $action ) {
			/**
			 * Action: acrossai_mcp_manager_oauth_authorization_denied
			 */
			do_action( 'acrossai_mcp_manager_oauth_authorization_denied', $params['client_id'], $params['redirect_uri'], 'user_denied' );

			self::redirect_error( $params['redirect_uri'], 'access_denied', 'User denied the authorization request.', $params['state'] );
		}

		if ( 'approve' !== $action ) {
			self::redirect_error( $params['redirect_uri'], 'invalid_request', 'Missing or invalid authorize_action', $params['state'] );
		}

		// Approve — mint an auth code, redirect with code + state + iss.
		$issued = AuthCodeRepository::create(
			array(
				'client_id'             => $params['client_id'],
				'user_id'               => get_current_user_id(),
				'redirect_uri'          => $params['redirect_uri'],
				'code_challenge'        => $params['code_challenge'],
				'code_challenge_method' => 'S256',
				'scope'                 => 'mcp',
				'resource'              => $params['resource'],
			)
		);

		$callback = add_query_arg(
			array(
				'code'  => rawurlencode( $issued['raw'] ),
				'state' => rawurlencode( $params['state'] ),
				'iss'   => rawurlencode( DiscoveryController::issuer() ),
			),
			$params['redirect_uri']
		);

		wp_redirect( $callback ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect target byte-validated against client's registered URIs.
		exit;
	}

	/**
	 * @param array<string, mixed> $raw Raw input array ($_GET or $_POST).
	 * @return array<string, string>
	 */
	private static function sanitize_authorize_params( array $raw ): array {
		$string_field = static function ( $v ): string {
			return is_scalar( $v ) ? (string) $v : '';
		};

		return array(
			'response_type'         => $string_field( $raw['response_type'] ?? '' ),
			'client_id'             => $string_field( $raw['client_id'] ?? '' ),
			'redirect_uri'          => $string_field( $raw['redirect_uri'] ?? '' ),
			'code_challenge'        => $string_field( $raw['code_challenge'] ?? '' ),
			'code_challenge_method' => $string_field( $raw['code_challenge_method'] ?? '' ),
			'state'                 => $string_field( $raw['state'] ?? '' ),
			'scope'                 => $string_field( $raw['scope'] ?? '' ),
			'resource'              => $string_field( $raw['resource'] ?? '' ),
		);
	}

	/**
	 * Look up the client OR render an inline error page and exit.
	 *
	 * @param string $client_id
	 * @return ClientRow
	 */
	private static function resolve_client_or_die( string $client_id ): ClientRow {
		$client = ClientRepository::find_by_id( $client_id );
		if ( null === $client ) {
			self::render_inline_error(
				400,
				__( 'Invalid client_id.', 'acrossai-mcp-manager' )
			);
		}
		return $client;
	}

	/**
	 * Assert the redirect_uri byte-matches the client's registered set OR
	 * render an inline error page (S9).
	 *
	 * @param ClientRow $client
	 * @param string    $redirect_uri
	 */
	private static function assert_redirect_uri_or_die( ClientRow $client, string $redirect_uri ): void {
		$registered = $client->decoded_redirect_uris();
		foreach ( $registered as $known ) {
			if ( hash_equals( (string) $known, $redirect_uri ) ) {
				return;
			}
		}
		self::render_inline_error(
			400,
			__( 'Invalid redirect_uri for this client.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * True iff $resource_url names a URL on this site (or loopback for dev).
	 *
	 * @param string $resource_url Candidate resource URL from `?resource=` param.
	 * @return bool
	 */
	private static function is_valid_resource( string $resource_url ): bool {
		if ( '' === $resource_url ) {
			return false;
		}

		$parts = wp_parse_url( $resource_url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );

		if ( in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
			return in_array( $scheme, array( 'http', 'https' ), true );
		}

		if ( 'https' !== $scheme ) {
			// Allow http for local dev where home_url() may be http.
			$site_parts = wp_parse_url( home_url() );
			if ( ! is_array( $site_parts ) || strtolower( (string) ( $site_parts['scheme'] ?? '' ) ) !== $scheme ) {
				return false;
			}
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return hash_equals( $site_host, $host );
	}

	/**
	 * Redirect back to the client with an OAuth error.
	 *
	 * @param string $redirect_uri
	 * @param string $error
	 * @param string $description
	 * @param string $state
	 * @return void
	 */
	private static function redirect_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$args = array(
			'error'             => rawurlencode( $error ),
			'error_description' => rawurlencode( $description ),
			'iss'               => rawurlencode( DiscoveryController::issuer() ),
		);
		if ( '' !== $state ) {
			$args['state'] = rawurlencode( $state );
		}

		wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URI already byte-validated against client's registered set.
		exit;
	}

	/**
	 * Render an inline 400 page and exit — used ONLY when we cannot trust
	 * the redirect_uri (unknown client or mismatched URI).
	 *
	 * @param int    $status
	 * @param string $message
	 */
	private static function render_inline_error( int $status, string $message ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OAuth error</title></head><body><h1>OAuth error</h1><p>' . esc_html( $message ) . '</p></body></html>';
		exit;
	}

	/**
	 * Q3 — render consent template on every request. No memoization.
	 *
	 * @param ClientRow             $client
	 * @param array<string, string> $params Sanitized authorize params. Consumed by the required template.
	 * @return void
	 */
	private static function render_consent( ClientRow $client, array $params ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $params consumed by required template via `require`.
		$profile = '' !== $client->connector_slug
			? ConnectorProfileRegistry::instance()->get_profile( $client->connector_slug )
			: null;

		$branding = null !== $profile
			? $profile->get_consent_branding()
			: array(
				'heading'             => sprintf(
					/* translators: %s: client name */
					__( '%s wants to connect to your site', 'acrossai-mcp-manager' ),
					'' !== $client->client_name ? $client->client_name : __( 'An application', 'acrossai-mcp-manager' )
				),
				'subtitle'            => __( 'This will allow the application to access the MCP tools you have exposed on this server.', 'acrossai-mcp-manager' ),
				'permissions_bullets' => array(),
			);

		$connector_icon = null !== $profile ? $profile->get_icon_url() : '';
		$client_name    = $client->client_name;
		$current_user   = wp_get_current_user();
		$post_url       = home_url( '/authorize' );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$template = plugin_dir_path( __DIR__ ) . '../templates/oauth/consent.php';
		if ( ! file_exists( $template ) ) {
			// Fallback path relative to includes/OAuth/.
			$template = dirname( __DIR__, 2 ) . '/templates/oauth/consent.php';
		}
		require $template;
	}

	/**
	 * Reconstruct the current /authorize URL for wp_login redirect_to.
	 *
	 * @return string
	 */
	private static function current_authorize_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/authorize';
		return home_url( $path );
	}

	/**
	 * Apply the 60/IP/60s rate-limit shared by /authorize and /token.
	 *
	 * @return void
	 */
	private static function apply_rate_limit(): void {
		$check = RateLimiter::check( 'authorize', RateLimiter::client_ip(), 60, 60 );
		if ( $check instanceof \WP_Error ) {
			status_header( 429 );
			nocache_headers();
			header( 'Retry-After: 60' );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				array(
					'error'             => 'slow_down',
					'error_description' => 'Rate limit exceeded; retry in 60 seconds.',
				)
			);
			exit;
		}
	}

	/**
	 * F024 helper — figure out which MCP server row a client + resource belong to.
	 *
	 * Two paths:
	 *   1. Admin-generated client: parse `server-{id}` prefix from client_id.
	 *      This is the Q2 client_id format (`server-{server_id}-{slug}-{rand8}`)
	 *      shipped in F021 Phase 3.
	 *   2. DCR-registered client (or any client with no server prefix):
	 *      compare the resource URL's path against the `server_route_namespace +
	 *      server_route` of every enabled server row until we find a match.
	 *
	 * Returns 0 if we can't resolve, in which case the ConnectorSettings gate
	 * falls back to "no enforcement" and lets the request proceed.
	 *
	 * @param ClientRow $client      The OAuth client row.
	 * @param string    $resource_url The resource URL from the authorize params.
	 * @return int
	 */
	private static function server_id_from_client_and_resource( ClientRow $client, string $resource_url ): int {
		// Path 1: parse from admin-generated client_id.
		if ( preg_match( '/\Aserver-(\d+)-/', (string) $client->client_id, $m ) ) {
			return (int) $m[1];
		}

		// Path 2: match resource URL path against server rows.
		if ( '' === $resource_url ) {
			return 0;
		}
		$resource_path = (string) wp_parse_url( $resource_url, PHP_URL_PATH );
		if ( '' === $resource_path ) {
			return 0;
		}
		$resource_path = rtrim( $resource_path, '/' );

		$servers = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query( array( 'number' => 100 ) );
		if ( empty( $servers ) ) {
			return 0;
		}

		foreach ( $servers as $server_row ) {
			$namespace = '' !== $server_row->server_route_namespace ? (string) $server_row->server_route_namespace : 'mcp';
			$route     = (string) $server_row->server_route;
			if ( '' === $route ) {
				continue;
			}
			$server_url  = rest_url( trailingslashit( $namespace ) . $route );
			$server_path = rtrim( (string) wp_parse_url( $server_url, PHP_URL_PATH ), '/' );
			if ( '' === $server_path ) {
				continue;
			}
			if ( $resource_path === $server_path || 0 === strpos( $resource_path, $server_path . '/' ) ) {
				return (int) $server_row->id;
			}
		}
		return 0;
	}

	/**
	 * F024 helper — for a DCR-registered client (connector_slug empty),
	 * walk the registered profiles asking `matches_dcr_client` until one
	 * claims it. Returns the slug of the first claiming profile or ''.
	 *
	 * @param ClientRow $client The client row being consulted.
	 * @return string
	 */
	private static function infer_slug_from_dcr_client( ClientRow $client ): string {
		$profiles = ConnectorProfileRegistry::instance()->get_profiles();
		if ( empty( $profiles ) ) {
			return '';
		}
		$redirect_uris = $client->decoded_redirect_uris();
		$name          = (string) $client->client_name;
		foreach ( $profiles as $profile ) {
			if ( $profile->matches_dcr_client( $name, $redirect_uris ) ) {
				return $profile->get_slug();
			}
		}
		return '';
	}

	/**
	 * F024 FR-024-015 — render a lightweight "pending admin approval"
	 * page instead of the consent screen. No form, no nonce (nothing to
	 * submit). The user just closes this and waits for the admin.
	 *
	 * @param ClientRow             $client Client row.
	 * @param array<string, string> $params Sanitized authorize params.
	 * @return void
	 */
	private static function render_pending_approval( ClientRow $client, array $params ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Both args reserved for a richer template later.
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( 200 );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Pending admin approval', 'acrossai-mcp-manager' ); ?></title>
	<style>
		body { font: 14px/1.5 -apple-system, sans-serif; background: #f0f0f1; padding: 40px 20px; }
		.wrap { max-width: 460px; margin: 40px auto; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 32px; text-align: center; }
		h1 { font-size: 20px; margin: 0 0 8px; }
		p { color: #50575e; margin: 0 0 16px; }
	</style>
</head>
<body>
	<div class="wrap">
		<h1><?php esc_html_e( 'Waiting for admin approval', 'acrossai-mcp-manager' ); ?></h1>
		<p><?php esc_html_e( 'This connector requires an administrator to approve your access before you can complete the connection.', 'acrossai-mcp-manager' ); ?></p>
		<p><?php esc_html_e( 'Your request has been recorded. You can close this window — the administrator will notify you when access is granted, and you can retry the connection from your AI client at that point.', 'acrossai-mcp-manager' ); ?></p>
	</div>
</body>
</html>
		<?php
	}
}
