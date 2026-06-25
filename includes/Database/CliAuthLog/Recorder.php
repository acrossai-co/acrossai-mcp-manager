<?php
/**
 * CLI auth log static-helper recorder.
 *
 * A11-style stateless helper per Q1 Clarification. No singleton, no
 * instance state, no hooks. The class owns the audit-write boundary
 * between Phase 6 feature classes (CliController, FrontendAuth) and
 * Phase 2's BerlinDB Query layer.
 *
 * Audit failures are best-effort (FR-014) — exceptions are caught and
 * routed to `error_log()`; the calling flow always completes.
 *
 * @package AcrossAI_MCP_Manager\Includes\Database\CliAuthLog
 */

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;

defined( 'ABSPATH' ) || exit;

final class Recorder {

	/**
	 * Audit row for the `status='approved'` transition.
	 *
	 * Called from `CliController::approve_auth_code` after the E1
	 * transient is flipped. Best-effort — never throws.
	 *
	 * @param int    $user_id         Approving admin user ID.
	 * @param string $server_slug     Server slug from the E1 transient.
	 * @param string $auth_code_hash  SHA-256 hex of the raw auth code.
	 */
	public static function record_approved( int $user_id, string $server_slug, string $auth_code_hash ): void {
		try {
			$server_id = self::resolve_server_id( $server_slug );
			$query     = new Query();
			$query->add_item(
				array(
					'server_id'      => $server_id,
					'server_slug'    => $server_slug,
					'user_id'        => $user_id,
					'status'         => 'approved',
					'auth_code_hash' => $auth_code_hash,
					'approved_at'    => current_time( 'mysql', 1 ),
				)
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[acrossai-mcp] CliAuthLog\\Recorder::record_approved failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Audit row for the `status='success'` transition (App Password issued).
	 *
	 * Called from `CliController::handle_auth_exchange` step 8 after the
	 * Application Password is created. Best-effort — never throws.
	 *
	 * @param int    $user_id            Granting admin user ID.
	 * @param string $server_slug        Server slug from the validated request body.
	 * @param string $auth_code_hash     SHA-256 hex of the raw auth code.
	 * @param string $app_password_uuid  WP-core Application Password UUID.
	 */
	public static function record_success( int $user_id, string $server_slug, string $auth_code_hash, string $app_password_uuid ): void {
		try {
			$server_id = self::resolve_server_id( $server_slug );
			$query     = new Query();
			$query->add_item(
				array(
					'server_id'         => $server_id,
					'server_slug'       => $server_slug,
					'user_id'           => $user_id,
					'status'            => 'success',
					'auth_code_hash'    => $auth_code_hash,
					'app_password_uuid' => $app_password_uuid,
					'completed_at'      => current_time( 'mysql', 1 ),
				)
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[acrossai-mcp] CliAuthLog\\Recorder::record_success failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Resolve a server slug to its numeric primary key for the audit row.
	 *
	 * Returns 0 (graceful degradation) if the slug doesn't resolve — the
	 * audit row still carries the slug so admins can correlate forensically.
	 *
	 * @param string $server_slug The server slug to resolve.
	 */
	private static function resolve_server_id( string $server_slug ): int {
		if ( '' === $server_slug ) {
			return 0;
		}
		$rows = ( new MCPServerQuery() )->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);
		return isset( $rows[0] ) ? (int) $rows[0]->id : 0;
	}
}
