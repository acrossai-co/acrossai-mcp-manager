<?php
/**
 * Base test case for Feature 021 OAuth PHPUnit tests.
 *
 * Prevents cross-test state bleed by truncating the three F021 tables +
 * clearing rate-limit transients + resetting ConnectorProfileRegistry
 * memoization before every test method. Every OAuth test class MUST extend
 * this instead of WP_UnitTestCase.
 *
 * Governance origin: /speckit-architecture-guard-governed-tasks Refactor Task 7
 * (test infrastructure isolation).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use WP_UnitTestCase;

abstract class OAuthTestCase extends WP_UnitTestCase {

	/**
	 * Reset all F021 state before every test.
	 *
	 * Order matters: tables first (so transient reads during teardown don't
	 * re-populate anything), then transients, then registry memoization.
	 */
	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps — WP_UnitTestCase uses snake_case per WP core convention.
		parent::set_up();

		$this->truncate_oauth_tables();
		$this->clear_rate_limit_transients();
		$this->reset_connector_profile_registry();
	}

	/**
	 * TRUNCATE the three F021 tables.
	 *
	 * Uses direct $wpdb because BerlinDB's delete_items() is per-row (slow)
	 * and this runs before every test method. Table names are hard-coded
	 * (they are cryptographic invariants).
	 */
	protected function truncate_oauth_tables(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'acrossai_mcp_oauth_clients',
			$wpdb->prefix . 'acrossai_mcp_oauth_tokens',
			$wpdb->prefix . 'acrossai_mcp_oauth_auth_codes',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		}
	}

	/**
	 * Delete every F021 rate-limit transient.
	 *
	 * F021's RateLimiter uses transients keyed
	 * `acrossai_mcp_oauth_rl_<bucket>_<ip_hash>`. TRUNCATE-style wipe.
	 */
	protected function clear_rate_limit_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_acrossai_mcp_oauth_rl_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_acrossai_mcp_oauth_rl_' ) . '%'
			)
		);

		wp_cache_flush();
	}

	/**
	 * Reset ConnectorProfileRegistry memoization via reflection.
	 *
	 * The registry caches the filter output to a private property on first
	 * `get_profiles()` call. Without a reset, tests that add profiles via
	 * add_filter() would see the cached empty array from a prior test.
	 *
	 * Uses reflection because the registry does NOT expose a public reset
	 * method by design (production code MUST NOT re-fire the filter mid-request).
	 */
	protected function reset_connector_profile_registry(): void {
		$class_fqn = '\\AcrossAI_MCP_Manager\\Includes\\Connectors\\ConnectorProfileRegistry';

		if ( ! class_exists( $class_fqn ) ) {
			return;
		}

		try {
			$reflection = new \ReflectionClass( $class_fqn );

			if ( $reflection->hasProperty( 'profiles' ) ) {
				$prop = $reflection->getProperty( 'profiles' );
				$prop->setAccessible( true );
				$prop->setValue( $class_fqn::instance(), null );
			}

			if ( $reflection->hasProperty( 'instance' ) ) {
				$prop = $reflection->getProperty( 'instance' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		} catch ( \ReflectionException $e ) {
			// Class exists but private property layout diverges — surface via test failure, not silent skip.
			$this->fail( 'OAuthTestCase reset_connector_profile_registry failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Helper: seed an OAuth client row directly via $wpdb.
	 *
	 * Used by tests that need a pre-existing client without exercising the
	 * DCR / admin-generate flow. Never used by tests that verify DCR itself.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return int Client row id.
	 */
	protected function seed_client( array $overrides = array() ): int {
		global $wpdb;

		$defaults = array(
			'client_id'                  => 'server-1-test-connector-' . bin2hex( random_bytes( 4 ) ),
			'client_secret_hash'         => hash( 'sha256', 'test-secret' ),
			'client_name'                => 'Test Client',
			'redirect_uris'              => wp_json_encode( array( 'https://client.example.com/callback' ) ),
			'grant_types'                => 'authorization_code refresh_token',
			'token_endpoint_auth_method' => 'client_secret_post',
			'connector_slug'             => 'test-connector',
			'metadata_fingerprint'       => hash( 'sha256', 'test-fingerprint' ),
			'created_at'                 => current_time( 'mysql', 1 ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'acrossai_mcp_oauth_clients', array_merge( $defaults, $overrides ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Helper: capture a `do_action` payload for observability action tests.
	 *
	 * Returns a captured-payload accumulator that survives the current test
	 * method. Use in tests like:
	 *   $captured = $this->capture_action( 'acrossai_mcp_manager_oauth_token_revoked' );
	 *   ...perform work...
	 *   $this->assertCount( 3, $captured['calls'] );
	 *   $this->assertSame( 'family_reuse_detected', $captured['calls'][0][1] );
	 *
	 * @param string $action_name Action to observe.
	 * @return array{calls: array<int, array<int, mixed>>} Passed by reference via array.
	 */
	protected function capture_action( string $action_name ): array {
		$captured = array( 'calls' => array() );

		add_action(
			$action_name,
			function ( ...$args ) use ( &$captured ) {
				$captured['calls'][] = $args;
			},
			10,
			99
		);

		return $captured;
	}
}
