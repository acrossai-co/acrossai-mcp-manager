<?php
/**
 * OAuth persistence facade — auth codes, access tokens, rate-limit, cleanup.
 *
 * Singleton + private ctor (A2). No hooks (A1).
 *
 * @package AcrossAI_MCP_Manager\Includes\OAuth
 */

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Row as CliAuthLogRow;
use AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Query as OAuthTokenQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;

defined( 'ABSPATH' ) || exit;

final class Storage {

	const ACCESS_TOKEN_TTL_SECONDS = 3600;
	const AUTH_CODE_TTL_SECONDS    = 600;

	const RATE_LIMIT_MINUTE_THRESHOLD = 5;
	const RATE_LIMIT_HOUR_THRESHOLD   = 50;

	const STATUS_OAUTH_CODE_ISSUED = 'oauth_code_issued';

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
	 * Issue an authorization code and persist its hash + metadata.
	 *
	 * @param string $client_id             Requesting client identifier.
	 * @param int    $server_id             MCP server row id.
	 * @param int    $user_id               Granting user id.
	 * @param string $redirect_uri          Redirect URI from the authorize request.
	 * @param string $code_challenge        PKCE S256 challenge.
	 * @param string $code_challenge_method MUST be `S256` in this phase.
	 * @param string $scope                 Requested scope (only `mcp`).
	 *
	 * @return string Raw code (43-char base64url). Empty string on failure.
	 */
	public function issue_authorization_code(
		string $client_id,
		int $server_id,
		int $user_id,
		string $redirect_uri,
		string $code_challenge,
		string $code_challenge_method,
		string $scope
	): string {
		try {
			$raw = $this->generate_raw_credential();
		} catch ( \Throwable $e ) {
			return '';
		}
		$hash = hash( 'sha256', $raw );

		$query = new CliAuthLogQuery();
		$id    = $query->add_item(
			array(
				'server_id'             => $server_id,
				'user_id'               => $user_id,
				'status'                => self::STATUS_OAUTH_CODE_ISSUED,
				'auth_code_hash'        => $hash,
				'redirect_uri'          => $redirect_uri,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'scope'                 => $scope,
				'approved_at'           => current_time( 'mysql', 1 ),
			)
		);

		if ( false === $id ) {
			return '';
		}

		return $raw;
	}

	/**
	 * Look up an authorization code by its raw value (the lookup hashes inside).
	 *
	 * @param string $raw_code Opaque base64url token value as the client submitted it.
	 */
	public function lookup_authorization_code( string $raw_code ): ?CliAuthLogRow {
		$hash  = hash( 'sha256', $raw_code );
		$query = new CliAuthLogQuery();
		$rows  = $query->query(
			array(
				'auth_code_hash' => $hash,
				'status'         => self::STATUS_OAUTH_CODE_ISSUED,
				'number'         => 1,
			)
		);
		return $rows[0] ?? null;
	}

	/**
	 * SEC-001 atomic redeem — race-free check-and-set on completed_at.
	 *
	 * @param int $code_row_id CliAuthLog primary key for the code being redeemed.
	 * @return bool true if THIS caller won the race.
	 */
	public function redeem_authorization_code_cas( int $code_row_id ): bool {
		return ( new CliAuthLogQuery() )->redeem_atomic( $code_row_id, current_time( 'mysql', 1 ) );
	}

	/**
	 * Issue a new access token. Returns the raw 43-char base64url token.
	 *
	 * @param int    $server_id           MCP server row id.
	 * @param int    $user_id             Granting user id.
	 * @param int    $issued_from_code_id CliAuthLog row id of the code that issued this token.
	 * @param string $scope               Granted scope.
	 *
	 * @return array{0:string, 1:int} Tuple of raw token + token row ID (0 on failure).
	 */
	public function issue_access_token( int $server_id, int $user_id, int $issued_from_code_id, string $scope ): array {
		try {
			$raw = $this->generate_raw_credential();
		} catch ( \Throwable $e ) {
			return array( '', 0 );
		}
		$hash = hash( 'sha256', $raw );
		/**
		 * Filter the access token lifetime in seconds (FR-013 step 3).
		 *
		 * @param int $ttl       Default 3600 (1 hour).
		 * @param int $server_id MCP server row id.
		 * @param int $user_id   Granting user id.
		 */
		$ttl        = (int) apply_filters( 'acrossai_mcp_oauth_access_token_lifetime', self::ACCESS_TOKEN_TTL_SECONDS, $server_id, $user_id );
		$ttl        = $ttl > 0 ? $ttl : self::ACCESS_TOKEN_TTL_SECONDS;
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$query = new OAuthTokenQuery();
		$id    = $query->add_item(
			array(
				'access_token_hash'   => $hash,
				'server_id'           => $server_id,
				'user_id'             => $user_id,
				'issued_from_code_id' => $issued_from_code_id,
				'scope'               => $scope,
				'expires_at'          => $expires_at,
			)
		);
		if ( false === $id ) {
			return array( '', 0 );
		}
		return array( $raw, (int) $id );
	}

	/**
	 * Revoke every access token whose `issued_from_code_id` matches.
	 *
	 * @param int $code_row_id CliAuthLog row id of the code whose tokens should be revoked.
	 * @return int[] IDs of revoked token rows (for downstream audit writes).
	 */
	public function revoke_all_tokens_for_code( int $code_row_id ): array {
		if ( $code_row_id <= 0 ) {
			return array();
		}
		$query = new OAuthTokenQuery();
		$rows  = $query->query( array( 'issued_from_code_id' => $code_row_id ) );
		$ids   = array();
		$now   = current_time( 'mysql', 1 );
		foreach ( $rows as $row ) {
			if ( null !== $row->revoked_at ) {
				continue;
			}
			$query->update_item( (int) $row->id, array( 'revoked_at' => $now ) );
			$ids[] = (int) $row->id;
		}
		return $ids;
	}

	/**
	 * Rate-limit check + increment (FR-014a).
	 *
	 * @param string $client_id Client identifier from the request body.
	 * @param string $ip        Remote IP — REMOTE_ADDR only (no XFF trust).
	 *
	 * @return array{0:string, 1:int} Tuple of [$status, $retry_after_seconds].
	 *                                 $status is 'ok' | 'minute' | 'hour'.
	 */
	public function rate_limit_check_and_increment( string $client_id, string $ip ): array {
		$minute_key = $this->rate_key( $client_id, $ip, gmdate( 'Y-m-d-H-i' ) );
		$hour_key   = $this->rate_key( $client_id, $ip, gmdate( 'Y-m-d-H' ) );

		$minute_count = (int) ( false === get_transient( $minute_key ) ? 0 : get_transient( $minute_key ) );
		$hour_count   = (int) ( false === get_transient( $hour_key ) ? 0 : get_transient( $hour_key ) );

		if ( $minute_count >= self::RATE_LIMIT_MINUTE_THRESHOLD ) {
			return array( 'minute', 60 );
		}
		if ( $hour_count >= self::RATE_LIMIT_HOUR_THRESHOLD ) {
			return array( 'hour', 3600 );
		}

		// Increment both buckets. Atomic when wp_cache_incr is available.
		$this->bucket_increment( $minute_key, 60 );
		$this->bucket_increment( $hour_key, 3600 );

		return array( 'ok', 0 );
	}

	/**
	 * Reset the per-(client_id, IP) rate-limit counters on a successful exchange.
	 *
	 * @param string $client_id Client identifier.
	 * @param string $ip        Remote IP.
	 */
	public function rate_limit_reset( string $client_id, string $ip ): void {
		delete_transient( $this->rate_key( $client_id, $ip, gmdate( 'Y-m-d-H-i' ) ) );
		delete_transient( $this->rate_key( $client_id, $ip, gmdate( 'Y-m-d-H' ) ) );
	}

	/**
	 * Retention sweep for codes / tokens / audit rows (FR-019c).
	 *
	 * @return array{rows_deleted_codes:int, rows_deleted_tokens:int, rows_deleted_audit:int}
	 */
	public function cleanup_oauth_data(): array {
		global $wpdb;

		$cli_query    = new CliAuthLogQuery();
		$audit_query  = new OAuthAuditQuery();
		$tokens_table = \AcrossAI_MCP_Manager\Includes\Database\OAuthToken\Table::instance()->get_table_name();

		// Codes: delete OAuth code rows older than 10-min expiry + 24-h grace.
		$code_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::AUTH_CODE_TTL_SECONDS - DAY_IN_SECONDS );
		$rows_codes  = $cli_query->delete_expired_oauth_codes( $code_cutoff );

		// Tokens: delete expired/revoked rows older than 7-day grace.
		$token_cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		// $tokens_table is a $wpdb->prefix-derived class constant — safe to interpolate;
		// $token_cutoff is bound through $wpdb->prepare below.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql_delete_tokens = "DELETE FROM {$tokens_table} WHERE (expires_at < %s) OR (revoked_at IS NOT NULL AND revoked_at < %s)";
		$rows_tokens       = (int) $wpdb->query(
			$wpdb->prepare( $sql_delete_tokens, $token_cutoff, $token_cutoff )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Audit: 90-day retention.
		$audit_cutoff = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$rows_audit   = $audit_query->delete_older_than( $audit_cutoff );

		return array(
			'rows_deleted_codes'  => (int) $rows_codes,
			'rows_deleted_tokens' => (int) $rows_tokens,
			'rows_deleted_audit'  => (int) $rows_audit,
		);
	}

	/**
	 * Generate 32 bytes of CSPRNG entropy → base64url (43 chars unpadded).
	 *
	 * @throws \Exception When CSPRNG is unavailable (caller MUST return HTTP 503).
	 */
	private function generate_raw_credential(): string {
		$raw = random_bytes( 32 );
		// base64_encode here is the canonical base64url transport encoding for an
		// opaque CSPRNG token — this is not obfuscation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return strtr( rtrim( base64_encode( $raw ), '=' ), '+/', '-_' );
	}

	/**
	 * Derive a rate-limit transient key for the given (client_id, IP, bucket) tuple.
	 *
	 * @param string $client_id Client identifier.
	 * @param string $ip        Remote IP.
	 * @param string $bucket    Bucket label (e.g. `Y-m-d-H` for hour or `Y-m-d-H-i` for minute).
	 */
	private function rate_key( string $client_id, string $ip, string $bucket ): string {
		return 'oauth_rate_' . hash( 'sha256', $client_id . '|' . $ip . '|' . $bucket );
	}

	/**
	 * Increment a rate-limit bucket transient, creating it at TTL on miss.
	 *
	 * @param string $key Transient key.
	 * @param int    $ttl Time-to-live in seconds.
	 */
	private function bucket_increment( string $key, int $ttl ): void {
		$existing = get_transient( $key );
		$current  = false === $existing ? 0 : (int) $existing;
		set_transient( $key, $current + 1, $ttl );
	}
}
