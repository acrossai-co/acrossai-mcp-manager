<?php
/**
 * AccessTokenRepository — issuance surface for OAuth access tokens.
 *
 * SEC-021-001: caller MUST supply `token_family_id` (either fresh UUIDv4 on
 * initial code→token exchange, or the parent refresh's family_id on rotation).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Repositories
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Repositories;

use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Row as TokenRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class AccessTokenRepository {

	private const TTL_SECONDS = 3600;

	/**
	 * Issue a fresh access token. Hashes at boundary.
	 *
	 * Shape of $data:
	 *   client_id (string, required)
	 *   user_id (int, required)
	 *   scope (string, optional — default 'mcp')
	 *   resource (string, optional)
	 *   token_family_id (string, required — UUIDv4 char(36)).
	 *
	 * @param array<string, mixed> $data Access token parameters.
	 * @return array{raw: string, id: int, family_id: string, expires_at: string}
	 */
	public static function issue( array $data ): array {
		$raw = SecretsVault::random_token();

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );

		$id = TokensQuery::instance()->add_item(
			array(
				'token_hash'      => SecretsVault::hash( $raw ),
				'token_type'      => 'access',
				'client_id'       => (string) $data['client_id'],
				'user_id'         => (int) $data['user_id'],
				'scope'           => isset( $data['scope'] ) && '' !== $data['scope'] ? (string) $data['scope'] : 'mcp',
				'resource'        => isset( $data['resource'] ) ? (string) $data['resource'] : '',
				'expires_at'      => $expires_at,
				'revoked'         => 0,
				'token_family_id' => (string) $data['token_family_id'],
			)
		);

		return array(
			'raw'        => $raw,
			'id'         => is_int( $id ) ? $id : (int) $id,
			'family_id'  => (string) $data['token_family_id'],
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Look up a token row by SHA-256 hex hash.
	 *
	 * @param string $token_hash
	 * @return TokenRow|null
	 */
	public static function find_by_hash( string $token_hash ): ?TokenRow {
		return TokensQuery::instance()->find_by_hash( $token_hash );
	}

	/**
	 * Access-token TTL in seconds (3600s = 1h).
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return self::TTL_SECONDS;
	}

	/**
	 * Count non-revoked tokens for a client (F024 Connections panel).
	 *
	 * @param string $client_id Client identifier.
	 * @return int
	 */
	public static function count_active_by_client_id( string $client_id ): int {
		return TokensQuery::instance()->count_active_by_client_id( $client_id );
	}

	/**
	 * Distinct user_ids holding a non-revoked token for a client
	 * (F024 Connections panel).
	 *
	 * @param string $client_id Client identifier.
	 * @return array<int, int>
	 */
	public static function get_active_user_ids_by_client_id( string $client_id ): array {
		return TokensQuery::instance()->get_active_user_ids_by_client_id( $client_id );
	}
}
