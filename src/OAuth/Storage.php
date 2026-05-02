<?php
/**
 * Lightweight OAuth storage for the experimental Claude Connectors flow.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;
use OAuth2\Storage\AccessTokenInterface;
use OAuth2\Storage\AuthorizationCodeInterface;
use OAuth2\Storage\ClientCredentialsInterface;
use OAuth2\Storage\RefreshTokenInterface;
use OAuth2\Storage\ScopeInterface;

/**
 * WordPress-backed OAuth storage.
 *
 * Uses plugin options for the single configured client and transients for the
 * short-lived authorization codes, access tokens, and refresh tokens.
 */
class Storage implements ClientCredentialsInterface, AccessTokenInterface, AuthorizationCodeInterface, RefreshTokenInterface, ScopeInterface {

	/**
	 * Supported scope list.
	 *
	 * @var string[]
	 */
	const SUPPORTED_SCOPES = array( 'mcp' );

	/**
	 * Current server-specific connector config context.
	 *
	 * @var array<string,mixed>|null
	 */
	private $server_context = null;

	/**
	 * Set the current server context used for OAuth client validation.
	 *
	 * @param array<string,mixed>|null $server_row Server row or null to clear.
	 *
	 * @return void
	 */
	public function set_server_context( $server_row ) {
		$this->server_context = is_array( $server_row ) ? $server_row : null;
	}

	/**
	 * Return the configured client details or false when not configured.
	 *
	 * @param string $client_id Requested OAuth client ID.
	 *
	 * @return array|false
	 */
	public function getClientDetails( $client_id ) {
		$server_row = $this->get_server_for_client( $client_id );

		if ( ! $server_row ) {
			return false;
		}

		$configured_client_id = (string) ( $server_row['claude_connector_client_id'] ?? '' );
		$redirect_uri         = (string) ( $server_row['claude_connector_redirect_uri'] ?? '' );

		return array(
			'client_id'    => $configured_client_id,
			'redirect_uri' => $redirect_uri,
			'grant_types'  => 'authorization_code refresh_token',
			'scope'        => implode( ' ', self::SUPPORTED_SCOPES ),
		);
	}

	/**
	 * Return scopes allowed for the configured client.
	 *
	 * @param string $client_id OAuth client ID.
	 *
	 * @return string|null
	 */
	public function getClientScope( $client_id ) {
		return $this->getClientDetails( $client_id ) ? implode( ' ', self::SUPPORTED_SCOPES ) : null;
	}

	/**
	 * Check restricted grant types for the configured client.
	 *
	 * @param string $client_id  OAuth client ID.
	 * @param string $grant_type Requested grant type.
	 *
	 * @return bool
	 */
	public function checkRestrictedGrantType( $client_id, $grant_type ) {
		if ( ! $this->getClientDetails( $client_id ) ) {
			return false;
		}

		return in_array( $grant_type, array( 'authorization_code', 'refresh_token' ), true );
	}

	/**
	 * Validate client credentials.
	 *
	 * @param string      $client_id     OAuth client ID.
	 * @param string|null $client_secret Optional client secret.
	 *
	 * @return bool
	 */
	public function checkClientCredentials( $client_id, $client_secret = null ) {
		$server_row = $this->get_server_for_client( $client_id );

		if ( ! $server_row || ! $this->getClientDetails( $client_id ) ) {
			return false;
		}

		$configured_secret = (string) ( $server_row['claude_connector_client_secret'] ?? '' );

		if ( '' === $configured_secret ) {
			return true;
		}

		return is_string( $client_secret ) && hash_equals( $configured_secret, $client_secret );
	}

	/**
	 * Return whether the configured client is public.
	 *
	 * @param string $client_id OAuth client ID.
	 *
	 * @return bool
	 */
	public function isPublicClient( $client_id ) {
		$server_row = $this->get_server_for_client( $client_id );

		return $server_row && $this->getClientDetails( $client_id ) && '' === (string) ( $server_row['claude_connector_client_secret'] ?? '' );
	}

	/**
	 * Resolve the server row that owns a given OAuth client ID.
	 *
	 * @param string $client_id OAuth client ID.
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_server_for_client( $client_id ) {
		$client_id = (string) $client_id;

		if ( '' === $client_id ) {
			return null;
		}

		if ( is_array( $this->server_context )
			&& $client_id === (string) ( $this->server_context['claude_connector_client_id'] ?? '' )
			&& '' !== (string) ( $this->server_context['claude_connector_redirect_uri'] ?? '' ) ) {
			return $this->server_context;
		}

		$matched_server = null;

		foreach ( MCPServerTable::get_all() as $row ) {
			if ( $client_id !== (string) ( $row['claude_connector_client_id'] ?? '' ) ) {
				continue;
			}

			if ( '' === (string) ( $row['claude_connector_redirect_uri'] ?? '' ) ) {
				continue;
			}

			if ( null !== $matched_server ) {
				return null;
			}

			$matched_server = $row;
		}

		return $matched_server;
	}

	/**
	 * Fetch access token payload.
	 *
	 * @param string $oauth_token Access token.
	 *
	 * @return array|null
	 */
	public function getAccessToken( $oauth_token ) {
		$data = $this->get_transient_payload( 'access', $oauth_token );

		if ( empty( $data ) || empty( $data['expires'] ) || (int) $data['expires'] < time() ) {
			return null;
		}

		return $data;
	}

	/**
	 * Store access token payload.
	 *
	 * @param string      $oauth_token Access token.
	 * @param string      $client_id   OAuth client ID.
	 * @param int|string  $user_id     WordPress user ID.
	 * @param int|null    $expires     Expiration timestamp.
	 * @param string|null $scope       Optional scope string.
	 *
	 * @return void
	 */
	public function setAccessToken( $oauth_token, $client_id, $user_id, $expires, $scope = null ) {
		$ttl = $this->expiration_to_ttl( $expires, HOUR_IN_SECONDS );

		$this->set_transient_payload(
			'access',
			$oauth_token,
			array(
				'expires'   => (int) $expires,
				'client_id' => (string) $client_id,
				'user_id'   => (int) $user_id,
				'scope'     => $scope,
			),
			$ttl
		);
	}

	/**
	 * Revoke an access token.
	 *
	 * @param string $access_token Access token.
	 *
	 * @return bool
	 */
	public function unsetAccessToken( $access_token ) {
		return delete_transient( $this->build_transient_key( 'access', $access_token ) );
	}

	/**
	 * Fetch authorization code payload.
	 *
	 * @param string $code Authorization code.
	 *
	 * @return array|null
	 */
	public function getAuthorizationCode( $code ) {
		$data = $this->get_transient_payload( 'code', $code );

		if ( empty( $data ) || empty( $data['expires'] ) || (int) $data['expires'] < time() ) {
			return null;
		}

		return $data;
	}

	/**
	 * Store authorization code payload.
	 *
	 * @param string      $code                  Authorization code.
	 * @param string      $client_id             OAuth client ID.
	 * @param int|string  $user_id               WordPress user ID.
	 * @param string      $redirect_uri          Redirect URI.
	 * @param int         $expires               Expiration timestamp.
	 * @param string|null $scope                 Optional scope string.
	 * @param string|null $id_token              Unused OpenID ID token placeholder.
	 * @param string|null $code_challenge        PKCE code challenge.
	 * @param string|null $code_challenge_method PKCE method.
	 *
	 * @return void
	 */
	public function setAuthorizationCode( $code, $client_id, $user_id, $redirect_uri, $expires, $scope = null, $id_token = null, $code_challenge = null, $code_challenge_method = null, $resource_url = null ) {
		$ttl = $this->expiration_to_ttl( $expires, 300 );

		$this->set_transient_payload(
			'code',
			$code,
			array(
				'code'                  => $code,
				'client_id'             => (string) $client_id,
				'user_id'               => (int) $user_id,
				'redirect_uri'          => (string) $redirect_uri,
				'expires'               => (int) $expires,
				'scope'                 => $scope,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'resource_url'          => $resource_url ? esc_url_raw( $resource_url ) : '',
			),
			$ttl
		);
	}

	/**
	 * Expire an authorization code after use.
	 *
	 * @param string $code Authorization code.
	 *
	 * @return bool
	 */
	public function expireAuthorizationCode( $code ) {
		return delete_transient( $this->build_transient_key( 'code', $code ) );
	}

	/**
	 * Fetch refresh token payload.
	 *
	 * @param string $refresh_token Refresh token.
	 *
	 * @return array|null
	 */
	public function getRefreshToken( $refresh_token ) {
		$data = $this->get_transient_payload( 'refresh', $refresh_token );

		if ( empty( $data ) || ( isset( $data['expires'] ) && (int) $data['expires'] > 0 && (int) $data['expires'] < time() ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Store refresh token payload.
	 *
	 * @param string      $refresh_token Refresh token.
	 * @param string      $client_id     OAuth client ID.
	 * @param int|string  $user_id       WordPress user ID.
	 * @param int         $expires       Expiration timestamp or 0.
	 * @param string|null $scope         Optional scope string.
	 *
	 * @return void
	 */
	public function setRefreshToken( $refresh_token, $client_id, $user_id, $expires, $scope = null ) {
		$ttl = $expires > 0 ? $this->expiration_to_ttl( $expires, WEEK_IN_SECONDS * 2 ) : WEEK_IN_SECONDS * 2;

		$this->set_transient_payload(
			'refresh',
			$refresh_token,
			array(
				'refresh_token' => $refresh_token,
				'client_id'     => (string) $client_id,
				'user_id'       => (int) $user_id,
				'expires'       => (int) $expires,
				'scope'         => $scope,
			),
			$ttl
		);
	}

	/**
	 * Expire a refresh token.
	 *
	 * @param string $refresh_token Refresh token.
	 *
	 * @return bool
	 */
	public function unsetRefreshToken( $refresh_token ) {
		return delete_transient( $this->build_transient_key( 'refresh', $refresh_token ) );
	}

	/**
	 * Attach extra context to an access token after issuance.
	 *
	 * @param string $access_token Access token.
	 * @param array  $context      Additional token context.
	 *
	 * @return void
	 */
	public function attach_access_token_context( $access_token, array $context ) {
		$existing = $this->get_transient_payload( 'access', $access_token );

		if ( ! is_array( $existing ) ) {
			return;
		}

		$existing['resource_url'] = ! empty( $context['resource_url'] ) ? esc_url_raw( $context['resource_url'] ) : ( $existing['resource_url'] ?? '' );
		$existing['server_id']    = isset( $context['server_id'] ) ? (int) $context['server_id'] : ( $existing['server_id'] ?? 0 );
		$existing['server_slug']  = isset( $context['server_slug'] ) ? sanitize_title( $context['server_slug'] ) : ( $existing['server_slug'] ?? '' );

		$this->set_transient_payload( 'access', $access_token, $existing, $this->expiration_to_ttl( $existing['expires'] ?? null, HOUR_IN_SECONDS ) );
	}

	/**
	 * Attach extra context to a refresh token after issuance.
	 *
	 * @param string $refresh_token Refresh token.
	 * @param array  $context       Additional token context.
	 *
	 * @return void
	 */
	public function attach_refresh_token_context( $refresh_token, array $context ) {
		$existing = $this->get_transient_payload( 'refresh', $refresh_token );

		if ( ! is_array( $existing ) ) {
			return;
		}

		$existing['resource_url'] = ! empty( $context['resource_url'] ) ? esc_url_raw( $context['resource_url'] ) : ( $existing['resource_url'] ?? '' );
		$existing['server_id']    = isset( $context['server_id'] ) ? (int) $context['server_id'] : ( $existing['server_id'] ?? 0 );
		$existing['server_slug']  = isset( $context['server_slug'] ) ? sanitize_title( $context['server_slug'] ) : ( $existing['server_slug'] ?? '' );

		$default_ttl = ! empty( $existing['expires'] ) ? WEEK_IN_SECONDS * 2 : WEEK_IN_SECONDS * 2;
		$this->set_transient_payload( 'refresh', $refresh_token, $existing, $this->expiration_to_ttl( $existing['expires'] ?? null, $default_ttl ) );
	}

	/**
	 * Return whether the requested scopes are supported.
	 *
	 * @param string $scope Scope string.
	 *
	 * @return bool
	 */
	public function scopeExists( $scope ) {
		$requested = preg_split( '/\s+/', trim( (string) $scope ) );

		if ( empty( $requested ) ) {
			return false;
		}

		foreach ( $requested as $scope_name ) {
			if ( '' === $scope_name ) {
				continue;
			}

			if ( ! in_array( $scope_name, self::SUPPORTED_SCOPES, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return the default scope for the configured client.
	 *
	 * @param string|null $client_id Optional client ID.
	 *
	 * @return string
	 */
	public function getDefaultScope( $client_id = null ) {
		return 'mcp';
	}

	/**
	 * Convert an expiration timestamp to a transient TTL.
	 *
	 * @param int|null $expires     Expiration timestamp.
	 * @param int      $default_ttl Fallback TTL.
	 *
	 * @return int
	 */
	private function expiration_to_ttl( $expires, $default_ttl ) {
		if ( empty( $expires ) ) {
			return $default_ttl;
		}

		return max( 1, (int) $expires - time() );
	}

	/**
	 * Build a transient key for an OAuth artifact.
	 *
	 * @param string $prefix Artifact type prefix.
	 * @param string $value  Raw token/code value.
	 *
	 * @return string
	 */
	private function build_transient_key( $prefix, $value ) {
		return 'acrossai_mcp_oauth_' . $prefix . '_' . sha1( (string) $value );
	}

	/**
	 * Read a transient payload array.
	 *
	 * @param string $prefix Artifact type prefix.
	 * @param string $value  Raw token/code value.
	 *
	 * @return array|null
	 */
	private function get_transient_payload( $prefix, $value ) {
		$data = get_transient( $this->build_transient_key( $prefix, $value ) );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Persist a transient payload array.
	 *
	 * @param string $prefix Artifact type prefix.
	 * @param string $value  Raw token/code value.
	 * @param array  $data   Payload.
	 * @param int    $ttl    Lifetime in seconds.
	 *
	 * @return void
	 */
	private function set_transient_payload( $prefix, $value, array $data, $ttl ) {
		set_transient( $this->build_transient_key( $prefix, $value ), $data, max( 1, (int) $ttl ) );
	}
}
