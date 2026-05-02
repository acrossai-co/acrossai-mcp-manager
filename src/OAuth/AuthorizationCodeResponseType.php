<?php
/**
 * Authorization-code response type with resource context support.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists PKCE and resource context alongside the authorization code.
 */
class AuthorizationCodeResponseType extends \OAuth2\ResponseType\AuthorizationCode {

	/**
	 * Build the redirect response for authorization code flow.
	 *
	 * @param array $params  Authorization params.
	 * @param mixed $user_id Authorized user ID.
	 *
	 * @return array
	 */
	public function getAuthorizeResponse( $params, $user_id = null ) {
		$result = array( 'query' => array() );

		$params += array(
			'scope'                 => null,
			'state'                 => null,
			'code_challenge'        => null,
			'code_challenge_method' => null,
			'resource_url'          => null,
		);

		$result['query']['code'] = $this->createAuthorizationCode(
			$params['client_id'],
			$user_id,
			$params['redirect_uri'],
			$params['scope'],
			$params['code_challenge'],
			$params['code_challenge_method'],
			$params['resource_url']
		);

		if ( isset( $params['state'] ) ) {
			$result['query']['state'] = $params['state'];
		}

		return array( $params['redirect_uri'], $result );
	}

	/**
	 * Persist the authorization code including the requested resource URL.
	 *
	 * @param string      $client_id             OAuth client ID.
	 * @param mixed       $user_id               User ID.
	 * @param string      $redirect_uri          Redirect URI.
	 * @param string|null $scope                 Scope string.
	 * @param string|null $code_challenge        PKCE code challenge.
	 * @param string|null $code_challenge_method PKCE method.
	 * @param string|null $resource_url          Requested protected resource URL.
	 *
	 * @return string
	 */
	public function createAuthorizationCode( $client_id, $user_id, $redirect_uri, $scope = null, $code_challenge = null, $code_challenge_method = null, $resource_url = null ) {
		$code = $this->generateAuthorizationCode();
		$this->storage->setAuthorizationCode(
			$code,
			$client_id,
			$user_id,
			$redirect_uri,
			time() + $this->config['auth_code_lifetime'],
			$scope,
			null,
			$code_challenge,
			$code_challenge_method,
			$resource_url
		);

		return $code;
	}
}
