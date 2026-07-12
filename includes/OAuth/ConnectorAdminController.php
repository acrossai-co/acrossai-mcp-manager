<?php
/**
 * F024 admin REST endpoints for per-server, per-connector operations.
 *
 * Endpoints (all under `acrossai-mcp-manager/v1`):
 *   POST /oauth/connector-settings      — save enabled + require_admin_approval
 *   POST /oauth/revoke-client-tokens    — revoke every token for a client_id
 *   POST /oauth/delete-client           — revoke tokens + delete client row
 *   POST /oauth/revoke-connector-tokens — mass-revoke all tokens for a
 *                                          connector on a server
 *   POST /oauth/approve-pending-consent — admin approves a user's pending consent
 *
 * Every endpoint gated on `manage_options` + `X-WP-Nonce` (`wp_rest`).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 * @since 0.1.0 (F024)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;

defined( 'ABSPATH' ) || exit;

final class ConnectorAdminController {

	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';

	/** @var ConnectorAdminController|null */
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
	 * Register the 5 F024 admin routes. Wired by Main.php on `rest_api_init`.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/connector-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-client-tokens',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_client_tokens' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/delete-client',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_delete_client' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-connector-tokens',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_connector_tokens' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/approve-pending-consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_approve_pending_consent' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	/**
	 * Shared permission callback — manage_options + wp_rest nonce.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return bool|\WP_Error
	 */
	public function admin_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'acrossai_mcp_oauth_forbidden', __( 'Insufficient permissions.', 'acrossai-mcp-manager' ), array( 'status' => 403 ) );
		}
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'acrossai_mcp_oauth_bad_nonce', __( 'Invalid or missing nonce.', 'acrossai-mcp-manager' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * FR-024-020 — save (server_id, slug) settings. When `enabled` flips
	 * from true to false, mass-revoke every non-revoked token for that
	 * connector on that server.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_save_settings( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$new_settings = array(
			'enabled'                => (bool) $request->get_param( 'enabled' ),
			'require_admin_approval' => (bool) $request->get_param( 'require_admin_approval' ),
		);

		$previous = ConnectorSettings::get( $server_id, $slug );
		ConnectorSettings::save( $server_id, $slug, $new_settings );

		$revoked_count = 0;
		if ( $previous['enabled'] && ! $new_settings['enabled'] ) {
			$revoked_count = self::mass_revoke_connector_tokens( $server_id, $slug, 'connector_disabled' );
		}

		return new \WP_REST_Response(
			array(
				'settings'      => $new_settings,
				'revoked_count' => $revoked_count,
			),
			200
		);
	}

	/**
	 * FR-024-021 — revoke every token for a client_id.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_client_tokens( \WP_REST_Request $request ) {
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_client_id( $client_id );
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_revoke' );
		}

		return new \WP_REST_Response( array( 'revoked_count' => count( $revoked_ids ) ), 200 );
	}

	/**
	 * FR-024-022 — revoke tokens then delete the client row.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete_client( \WP_REST_Request $request ) {
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_client_id( $client_id );
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_delete_client' );
		}

		$client_row = ClientsQuery::instance()->find_by_id( $client_id );
		if ( null !== $client_row ) {
			ClientsQuery::instance()->delete_by_id( (int) $client_row->id );
		}

		return new \WP_REST_Response(
			array(
				'revoked_count'  => count( $revoked_ids ),
				'client_deleted' => null !== $client_row,
			),
			200
		);
	}

	/**
	 * FR-024-016 — nuclear revoke: kill every token for a connector on a server.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_connector_tokens( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$revoked_count = self::mass_revoke_connector_tokens( $server_id, $slug, 'admin_nuclear_revoke' );

		return new \WP_REST_Response( array( 'revoked_count' => $revoked_count ), 200 );
	}

	/**
	 * FR-024-023 — admin approves a specific user's pending consent.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_approve_pending_consent( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		ConnectorSettings::remove_pending_user( $server_id, $slug, $user_id );
		ConnectorSettings::add_approved_user( $server_id, $slug, $user_id );

		return new \WP_REST_Response( array( 'approved_user_id' => $user_id ), 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Validate + extract server_id + connector_slug from the request body.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return array{0: int, 1: string}|\WP_Error
	 */
	private static function validate_server_and_slug( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		if ( $server_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}
		$slug = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $request->get_param( 'connector_slug' ) ) );
		if ( '' === $slug || ! preg_match( '/\A[a-z0-9-]{1,64}\z/', $slug ) ) {
			return new \WP_Error( 'invalid_request', __( 'Missing or invalid connector_slug.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}
		if ( null === ConnectorProfileRegistry::instance()->get_profile( $slug ) ) {
			return new \WP_Error( 'not_found', __( 'Connector profile is not registered.', 'acrossai-mcp-manager' ), array( 'status' => 404 ) );
		}
		return array( $server_id, $slug );
	}

	/**
	 * Basic sanitizer for client_id parameter.
	 *
	 * @param string $client_id Raw input.
	 * @return string
	 */
	private static function sanitize_client_id( string $client_id ): string {
		$clean = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $client_id );
		return null === $clean ? '' : $clean;
	}

	/**
	 * Mass-revoke every non-revoked token belonging to any client on this
	 * (server, connector) pair. Fires `token_revoked` per row with the
	 * supplied reason.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param string $reason    Reason string for the observability action.
	 * @return int Total revoked.
	 */
	private static function mass_revoke_connector_tokens( int $server_id, string $slug, string $reason ): int {
		$profile = ConnectorProfileRegistry::instance()->get_profile( $slug );
		if ( null === $profile ) {
			return 0;
		}

		$admin_clients = ClientsQuery::instance()->find_admin_clients_for_server_connector( $server_id, $slug );

		$dcr_clients = array();
		foreach ( ClientsQuery::instance()->find_dcr_clients() as $dcr_row ) {
			$redirect_uris = $dcr_row->decoded_redirect_uris();
			if ( $profile->matches_dcr_client( (string) $dcr_row->client_name, $redirect_uris ) ) {
				$dcr_clients[] = $dcr_row;
			}
		}

		$total = 0;
		foreach ( array_merge( $admin_clients, $dcr_clients ) as $client_row ) {
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id( (string) $client_row->client_id );
			foreach ( $revoked_ids as $token_id ) {
				do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, $reason );
			}
			$total += count( $revoked_ids );
		}

		return $total;
	}
}
