<?php
/**
 * Custom authorize controller for PKCE-aware auth-code issuance.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage OAuth
 */

namespace ACROSSAI_MCP_MANAGER\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends the default controller so auth codes keep PKCE data.
 */
class AuthorizeController extends \OAuth2\Controller\AuthorizeController {

	/**
	 * Add PKCE parameters to the authorization-code payload.
	 *
	 * @param \OAuth2\RequestInterface  $request  OAuth request.
	 * @param \OAuth2\ResponseInterface $response OAuth response.
	 * @param mixed                     $user_id  Authorized user ID.
	 *
	 * @return array
	 */
	protected function buildAuthorizeParameters( $request, $response, $user_id ) {
		$params = parent::buildAuthorizeParameters( $request, $response, $user_id );

		if ( ! is_array( $params ) ) {
			return $params;
		}

		$code_challenge = $request->query( 'code_challenge', $request->request( 'code_challenge' ) );
		$method         = $request->query( 'code_challenge_method', $request->request( 'code_challenge_method', 'plain' ) );

		if ( ! empty( $code_challenge ) ) {
			$params['code_challenge']        = $code_challenge;
			$params['code_challenge_method'] = $method ?: 'plain';
		}

		$resource = $request->query( 'resource', $request->request( 'resource' ) );
		if ( ! empty( $resource ) ) {
			$params['resource_url'] = $resource;
		}

		return $params;
	}
}
