<?php
/**
 * Append-only OAuth audit log writer.
 *
 * Singleton + private ctor (A2). No hooks in the constructor (A1) — the
 * audit log only writes; it has no inbound hooks of its own.
 *
 * @package AcrossAI_MCP_Manager\Includes\OAuth
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	public const EVENT_CODE_ISSUED               = 'code_issued';
	public const EVENT_CODE_REDEEMED             = 'code_redeemed';
	public const EVENT_CONSENT_DENIED            = 'consent_denied';
	public const EVENT_FAILED_UNKNOWN_CLIENT     = 'failed_unknown_client';
	public const EVENT_FAILED_REDIRECT_MISMATCH  = 'failed_redirect_mismatch';
	public const EVENT_FAILED_REPLAY_ATTEMPT     = 'failed_replay_attempt';
	public const EVENT_FAILED_RATE_LIMIT         = 'failed_rate_limit';
	public const EVENT_FAILED_CROSS_SERVER_TOKEN = 'failed_cross_server_token';
	public const EVENT_BEARER_AUTH_SUCCESS       = 'bearer_auth_success';
	public const EVENT_TOKEN_REVOKED             = 'token_revoked';
	public const EVENT_CLEANUP_RUN               = 'cleanup_run';

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
	 * Write an audit event.
	 *
	 * $context keys (all optional): server_id, user_id, client_id,
	 * token_hash_prefix, endpoint, details (array — JSON-encoded into
	 * details_json — MUST NEVER contain raw codes/tokens/secrets).
	 *
	 * @param string               $event_type One of the EVENT_* constants above.
	 * @param array<string, mixed> $context    Optional event payload (see method docblock).
	 */
	public function write( string $event_type, array $context = array() ): void {
		$query = OAuthAuditQuery::instance();
		$row   = array(
			'event_type'        => $event_type,
			'server_id'         => isset( $context['server_id'] ) ? (int) $context['server_id'] : 0,
			'user_id'           => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
			'client_id'         => isset( $context['client_id'] ) ? (string) $context['client_id'] : '',
			'token_hash_prefix' => isset( $context['token_hash_prefix'] ) ? substr( (string) $context['token_hash_prefix'], 0, 8 ) : '',
			'endpoint'          => isset( $context['endpoint'] ) ? (string) $context['endpoint'] : '',
		);
		if ( isset( $context['details'] ) && is_array( $context['details'] ) ) {
			$row['details_json'] = (string) wp_json_encode( $context['details'] );
		}
		$query->add_item( $row );
	}
}
