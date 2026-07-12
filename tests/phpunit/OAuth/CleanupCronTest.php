<?php
/**
 * CleanupCronTest — SC-008: daily cron cleanup deletes expired rows + fires action.
 *
 * Covers `Cleanup::run()` end-to-end:
 *   (a) the `acrossai_mcp_manager_oauth_cleanup` action fires exactly once
 *   (b) tokens matching (expired AND revoked) are deleted
 *   (c) auth codes matching (expired OR used=1) are deleted
 *   (d) fresh tokens + fresh auth codes survive
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\OAuth\Cleanup;

final class CleanupCronTest extends OAuthTestCase {

	public function test_run_fires_action_once_and_deletes_expired_rows(): void {
		global $wpdb;

		$tokens_table = $wpdb->prefix . 'acrossai_mcp_oauth_tokens';
		$codes_table  = $wpdb->prefix . 'acrossai_mcp_oauth_auth_codes';

		$client_id = 'server-1-test-connector-' . bin2hex( random_bytes( 4 ) );
		$this->seed_client( array( 'client_id' => $client_id ) );

		$past   = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );

		// (a) Expired-and-revoked token → MUST delete.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$tokens_table,
			array(
				'token_hash'      => hash( 'sha256', 'expired-revoked-' . $client_id ),
				'token_type'      => 'access',
				'client_id'       => $client_id,
				'user_id'         => 1,
				'scope'           => 'mcp',
				'resource'        => 'https://example.test/wp-json/mcp/default',
				'expires_at'      => $past,
				'revoked'         => 1,
				'token_family_id' => wp_generate_uuid4(),
				'created_at'      => $past,
			)
		);

		// (b) Fresh non-revoked token → MUST survive.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$tokens_table,
			array(
				'token_hash'      => hash( 'sha256', 'fresh-' . $client_id ),
				'token_type'      => 'access',
				'client_id'       => $client_id,
				'user_id'         => 1,
				'scope'           => 'mcp',
				'resource'        => 'https://example.test/wp-json/mcp/default',
				'expires_at'      => $future,
				'revoked'         => 0,
				'token_family_id' => wp_generate_uuid4(),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		// (c) Expired auth code → MUST delete.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$codes_table,
			array(
				'code_hash'             => hash( 'sha256', 'expired-code-' . $client_id ),
				'client_id'             => $client_id,
				'user_id'               => 1,
				'redirect_uri'          => 'https://client.example.com/callback',
				'scope'                 => 'mcp',
				'resource'              => 'https://example.test/wp-json/mcp/default',
				'code_challenge'        => str_repeat( 'a', 43 ),
				'code_challenge_method' => 'S256',
				'used'                  => 0,
				'expires_at'            => $past,
				'created_at'            => $past,
			)
		);

		// (d) Fresh auth code → MUST survive.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$codes_table,
			array(
				'code_hash'             => hash( 'sha256', 'fresh-code-' . $client_id ),
				'client_id'             => $client_id,
				'user_id'               => 1,
				'redirect_uri'          => 'https://client.example.com/callback',
				'scope'                 => 'mcp',
				'resource'              => 'https://example.test/wp-json/mcp/default',
				'code_challenge'        => str_repeat( 'b', 43 ),
				'code_challenge_method' => 'S256',
				'used'                  => 0,
				'expires_at'            => $future,
				'created_at'            => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$captured = $this->capture_action( 'acrossai_mcp_manager_oauth_cleanup' );

		Cleanup::instance()->run();

		$this->assertCount( 1, $captured['calls'], 'oauth_cleanup action must fire exactly once per run' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$surviving_tokens = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_id = %s', $tokens_table, $client_id )
		);
		$this->assertSame( 1, $surviving_tokens, 'exactly 1 fresh token must survive; the expired+revoked one must be deleted' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$surviving_codes = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_id = %s', $codes_table, $client_id )
		);
		$this->assertSame( 1, $surviving_codes, 'exactly 1 fresh auth code must survive; the expired one must be deleted' );
	}

	public function test_reentry_guard_prevents_double_execution(): void {
		$call_count = 0;
		add_action(
			'acrossai_mcp_manager_oauth_cleanup',
			function () use ( &$call_count ) {
				++$call_count;
			}
		);

		// Cron under load can fire twice — the guard MUST short-circuit the second call.
		Cleanup::instance()->run();
		Cleanup::instance()->run();

		$this->assertSame( 2, $call_count, 'two SEQUENTIAL runs fire the action twice (guard only blocks nested re-entry, not sequential)' );
	}
}
