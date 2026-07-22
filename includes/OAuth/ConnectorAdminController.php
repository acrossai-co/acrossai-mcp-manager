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
use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query as ConnectorApprovedUsersQuery;
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

		// Server-neutral revoke — operator-visible "Revoke from all servers" nuclear button.
		// Intentional carve-out from D31 (F032 per-server invariant). Requires only client_id;
		// bypasses server_id validation by design.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-client-tokens-all-servers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_client_tokens_all_servers' ),
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

		// F032 — Deny a pending consent request WITHOUT approving.
		// Removes the user from the pending list; the user must re-attempt the
		// connect flow (which will re-add them to pending, or immediately succeed
		// if require_admin_approval was turned off in the meantime).
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/deny-pending-consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_deny_pending_consent' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// F032 — Revoke an existing approval. Deletes the row from the
		// ConnectorApprovedUsers table. Fires `acrossai_mcp_connector_user_approval_revoked`
		// action; the default listener (`cascade_revoke_tokens_on_approval_revoked`)
		// then revokes the user's active OAuth tokens for that (server, connector) pair.
		// Third-party listeners can opt out via the `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` filter.
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/revoke-user-approval',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_user_approval' ),
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
	 * FR-024-021 — revoke every token for a client_id on a specific server.
	 *
	 * F032 (T035) — requires both `server_id` and `client_id` in body. Cross-server
	 * mismatch fires 4-arg `acrossai_mcp_oauth_cross_server_attempted` observability
	 * action BEFORE returning WP_Error 403 (D19 fail-open + SEC-032-001 remediation).
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_client_tokens( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( $server_id <= 0 || '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id or client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		// F032 (T035) — cross-server-safe composite lookup. Null return signals
		// mismatch OR missing client — either way, respond with an opaque 403 so
		// cross-server existence is not disclosed.
		$client_row = ClientsQuery::instance()->find_by_client_id_and_server_id( $client_id, $server_id );
		if ( null === $client_row ) {
			// SEC-032-001 remediation: 4-arg observability action — MUST NOT include
			// the actual owning server_id, which would recreate the oracle F032 exists
			// to close. Listeners that need the owning server for forensics can query
			// the DB directly from within their handler.
			do_action(
				'acrossai_mcp_oauth_cross_server_attempted',
				$client_id,
				$server_id,
				get_current_user_id(),
				time()
			);
			return new \WP_Error(
				'acrossai_mcp_oauth_cross_server',
				__( 'This client does not belong to the specified server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_client_id( $client_id, $server_id );
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_revoke' );
		}

		return new \WP_REST_Response( array( 'revoked_count' => count( $revoked_ids ) ), 200 );
	}

	/**
	 * FR-024-022 — revoke tokens then delete the client row.
	 *
	 * F032 (T036) — same server_id validation + 4-arg observability fire on 403
	 * as `handle_revoke_client_tokens` (T035).
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_delete_client( \WP_REST_Request $request ) {
		$server_id = (int) $request->get_param( 'server_id' );
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( $server_id <= 0 || '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing server_id or client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$client_row = ClientsQuery::instance()->find_by_client_id_and_server_id( $client_id, $server_id );
		if ( null === $client_row ) {
			do_action(
				'acrossai_mcp_oauth_cross_server_attempted',
				$client_id,
				$server_id,
				get_current_user_id(),
				time()
			);
			return new \WP_Error(
				'acrossai_mcp_oauth_cross_server',
				__( 'This client does not belong to the specified server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_client_id( $client_id, $server_id );
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_delete_client' );
		}

		ClientsQuery::instance()->delete_by_id( (int) $client_row->id );

		return new \WP_REST_Response(
			array(
				'revoked_count'  => count( $revoked_ids ),
				'client_deleted' => true,
			),
			200
		);
	}

	/**
	 * Server-neutral revoke — kill every non-revoked token for this client_id across
	 * EVERY server on this site. Intentional operator-visible carve-out from F032's
	 * per-server invariant (D31): the "Revoke from all servers" nuclear button.
	 *
	 * Contrast with `handle_revoke_client_tokens` (per-server, 4-arg observability
	 * on cross-server mismatch). This endpoint is *always* server-neutral and MUST NOT
	 * fire the `acrossai_mcp_oauth_cross_server_attempted` action — the operator is
	 * DELIBERATELY invoking a cross-server operation, not attempting a bypass.
	 *
	 * Fires per-token `acrossai_mcp_manager_oauth_token_revoked` (existing shape) +
	 * one aggregate `acrossai_mcp_oauth_client_revoked_across_all_servers` signal.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_client_tokens_all_servers( \WP_REST_Request $request ) {
		$client_id = self::sanitize_client_id( (string) $request->get_param( 'client_id' ) );
		if ( '' === $client_id ) {
			return new \WP_Error( 'invalid_request', __( 'Missing client_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_client_id_across_all_servers( $client_id );
		foreach ( $revoked_ids as $token_id ) {
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'admin_revoke_all_servers' );
		}

		/**
		 * Fires once per "Revoke from all servers" admin action. Distinct from the
		 * per-server signal so operators can differentiate scoped vs global revoke.
		 *
		 * @param string $client_id          The client_id that was revoked globally.
		 * @param int    $revoked_token_count Total tokens revoked across all servers.
		 * @param int    $user_id            Admin performing the action.
		 * @param int    $timestamp          UNIX timestamp.
		 */
		do_action(
			'acrossai_mcp_oauth_client_revoked_across_all_servers',
			$client_id,
			count( $revoked_ids ),
			get_current_user_id(),
			time()
		);

		return new \WP_REST_Response(
			array(
				'revoked_count' => count( $revoked_ids ),
				'scope'         => 'all_servers',
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

	/**
	 * Deny a pending consent request WITHOUT adding to the approved list.
	 * Symmetric counterpart to `handle_approve_pending_consent`. The user
	 * must re-attempt the connect flow from their AI host if they want to try again.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_deny_pending_consent( \WP_REST_Request $request ) {
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

		return new \WP_REST_Response( array( 'denied_user_id' => $user_id ), 200 );
	}

	/**
	 * Revoke an existing approval for a (server, connector, user) triple.
	 * Deletes the row from the ConnectorApprovedUsers table. Does NOT touch
	 * existing OAuth tokens for the user — the operator should use the
	 * Connections panel's Revoke/Delete buttons if they also want to force
	 * an immediate disconnect. This separation keeps approval-lifecycle
	 * (who is allowed to connect) and token-lifecycle (who is currently
	 * connected) as independent concerns.
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_user_approval( \WP_REST_Request $request ) {
		$body = self::validate_server_and_slug( $request );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}
		list( $server_id, $slug ) = $body;

		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'invalid_request', __( 'Missing user_id.', 'acrossai-mcp-manager' ), array( 'status' => 400 ) );
		}

		$deleted = ConnectorApprovedUsersQuery::instance()->revoke( $server_id, $slug, $user_id );

		/**
		 * Fires immediately after a connector user approval is revoked.
		 *
		 * The default listener (`ConnectorAdminController::cascade_revoke_tokens_on_approval_revoked`)
		 * is registered by `Main::define_admin_hooks()` and revokes every active
		 * OAuth token the user holds for this (server, connector) pair. To opt out
		 * of the token cascade without removing the listener entirely, hook the
		 * `acrossai_mcp_connector_revoke_tokens_on_approval_revoked` filter and
		 * return false. Third-party plugins can also `add_action()` on this hook
		 * to layer additional side effects (audit log, email notification, etc.).
		 *
		 * @param int $server_id       MCP server row id.
		 * @param string $connector_slug Connector profile slug.
		 * @param int $user_id         WP user id whose approval was revoked.
		 * @param int $revoked_by      Admin's user id (get_current_user_id() at call site).
		 */
		do_action(
			'acrossai_mcp_connector_user_approval_revoked',
			$server_id,
			$slug,
			$user_id,
			(int) get_current_user_id()
		);

		return new \WP_REST_Response(
			array(
				'revoked_user_id' => $user_id,
				'was_approved'    => $deleted,
			),
			200
		);
	}

	/**
	 * Default listener for `acrossai_mcp_connector_user_approval_revoked`.
	 * Cascades the approval revoke into an active-token revoke for the same
	 * (server, connector, user) triple — enumerates every client matching the
	 * connector profile (admin-generated + DCR) and calls
	 * `TokensQuery::revoke_by_user_and_server_and_client_ids()`.
	 *
	 * Opt-out via the `acrossai_mcp_connector_revoke_tokens_on_approval_revoked`
	 * filter (returns true by default). Fires per-token
	 * `acrossai_mcp_manager_oauth_token_revoked` with reason `approval_revoked`
	 * for downstream observability.
	 *
	 * @param int    $server_id       MCP server row id.
	 * @param string $connector_slug  Connector profile slug.
	 * @param int    $user_id         User id whose approval was revoked.
	 * @param int    $revoked_by      Admin who triggered the revoke.
	 * @return void
	 */
	public static function cascade_revoke_tokens_on_approval_revoked( int $server_id, string $connector_slug, int $user_id, int $revoked_by ): void {
		/**
		 * Opt-out filter. Return false to skip the token cascade entirely — the
		 * approval-revoke DB row is still deleted, but the user's existing tokens
		 * stay active until they expire naturally or an admin uses the
		 * Connections panel's Revoke/Delete buttons.
		 *
		 * @param bool   $should_revoke   Default true.
		 * @param int    $server_id       MCP server row id.
		 * @param string $connector_slug  Connector profile slug.
		 * @param int    $user_id         User id.
		 * @param int    $revoked_by      Admin id.
		 */
		if ( ! apply_filters( 'acrossai_mcp_connector_revoke_tokens_on_approval_revoked', true, $server_id, $connector_slug, $user_id, $revoked_by ) ) {
			return;
		}

		if ( $server_id <= 0 || '' === $connector_slug || $user_id <= 0 ) {
			return;
		}

		// Enumerate every client on this server that belongs to this connector
		// profile — admin-generated (prefix-matched) + DCR (profile-matched via
		// `matches_dcr_client`). Delegates to the shared enumeration helper
		// (V1 refactor) so this cascade + `mass_revoke_connector_tokens` share
		// the same enumeration semantic — no risk of drift.
		$client_ids = array();
		foreach ( self::enumerate_connector_clients( $server_id, $connector_slug ) as $client_row ) {
			$client_ids[] = (string) $client_row->client_id;
		}

		if ( empty( $client_ids ) ) {
			return;
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_user_and_server_and_client_ids( $user_id, $server_id, $client_ids );
		foreach ( $revoked_ids as $token_id ) {
			/**
			 * Per-token observability action fired once per row transitioned to
			 * `revoked=1`. Reason `approval_revoked` is a stable enum;
			 * downstream loggers can differentiate this cascade from admin
			 * revoke / delete / nuclear paths via the reason string.
			 */
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'approval_revoked' );
		}
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
		$client_rows = self::enumerate_connector_clients( $server_id, $slug );
		if ( empty( $client_rows ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $client_rows as $client_row ) {
			// F032 (T037) — always pass server_id from the current row (matches
			// this loop's server scope by construction — admin_clients is already
			// server-scoped and dcr_clients is filtered above).
			$revoked_ids = TokensQuery::instance()->revoke_by_client_id( (string) $client_row->client_id, (int) $client_row->server_id );
			foreach ( $revoked_ids as $token_id ) {
				do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, $reason );
			}
			$total += count( $revoked_ids );
		}

		return $total;
	}

	/**
	 * F032 (V1 refactor) — enumerate every OAuth client on a server that
	 * belongs to a connector profile. Combines the admin-generated set
	 * (`server-{id}-{slug}-{rand}` prefix — already server-scoped by the
	 * dedicated Query helper) with the DCR set filtered by
	 * `AbstractConnectorProfile::matches_dcr_client`.
	 *
	 * Extracted from two prior duplicated call sites — `mass_revoke_connector_tokens`
	 * and `cascade_revoke_tokens_on_approval_revoked`. Any new caller that
	 * needs the "all clients for this (server, connector)" set MUST route
	 * through here so the DCR filter stays a single-source-of-truth.
	 *
	 * Returns an empty array (no fatal) if the connector profile is not
	 * registered — matches the pre-refactor early-return behavior of both
	 * original call sites.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return array<int, object> Client rows (Row instances from ClientsQuery).
	 */
	private static function enumerate_connector_clients( int $server_id, string $connector_slug ): array {
		if ( $server_id <= 0 || '' === $connector_slug ) {
			return array();
		}

		$profile = ConnectorProfileRegistry::instance()->get_profile( $connector_slug );
		if ( null === $profile ) {
			return array();
		}

		$admin_clients = ClientsQuery::instance()->find_admin_clients_for_server_connector( $server_id, $connector_slug );

		// F032 (T037) — restrict DCR-side enumeration to this server ONLY.
		// Pre-F032, find_dcr_clients() unfiltered returned rows across every
		// server sharing a matching DCR profile; iterating those and calling
		// the unscoped revoke_by_client_id() would silently revoke tokens on
		// other servers (cross-server leak — the P1 F032 fix).
		$dcr_clients = array();
		foreach ( ClientsQuery::instance()->find_dcr_clients( $server_id ) as $dcr_row ) {
			if ( $profile->matches_dcr_client( (string) $dcr_row->client_name, $dcr_row->decoded_redirect_uris() ) ) {
				$dcr_clients[] = $dcr_row;
			}
		}

		return array_merge( $admin_clients, $dcr_clients );
	}
}
