<?php
/**
 * SEC-021-T04 / Q3 — No consent memoization surfaces exist.
 *
 * Q3 clarification is that consent renders on EVERY /authorize request —
 * no `OAuthConsents` companion table, no `approved_at` column on
 * `OAuthClients`, no cache lookup. This test proves the invariant by
 * inspecting the DB schema for any surface that would enable memoization.
 *
 * Regression guard: any future PR that adds an `approved_at`-style column
 * to `OAuthClients` OR creates a new `OAuthConsents` table will fail here.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

/**
 * @coversNothing
 */
class ConsentAlwaysRendersTest extends OAuthTestCase {

	public function test_no_oauth_consents_table_exists(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hits = (string) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'acrossai_mcp_oauth_consents' )
		);

		$this->assertSame(
			'',
			$hits,
			'Q3 violated: OAuthConsents table exists — consent memoization surface introduced.'
		);
	}

	public function test_oauth_clients_has_no_approved_at_column(): void {
		global $wpdb;

		\AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table::instance()->maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$wpdb->prefix . 'acrossai_mcp_oauth_clients'
			),
			0
		);

		$this->assertIsArray( $cols );

		$forbidden_columns = array(
			'approved_at',
			'consented_at',
			'consent_at',
			'last_consent',
			'consent_expires_at',
		);

		foreach ( $forbidden_columns as $forbidden ) {
			$this->assertNotContains(
				$forbidden,
				array_map( 'strtolower', $cols ),
				'Q3 violated: forbidden memoization column "' . $forbidden . '" found on OAuthClients.'
			);
		}
	}

	public function test_authorization_controller_source_contains_no_memoization_keywords(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/OAuth/AuthorizationController.php'
		);

		if ( false === $source ) {
			$this->fail( 'Could not read AuthorizationController.php' );
		}

		// Keywords that would signal a caller checking prior-approval state.
		$forbidden_patterns = array(
			'find_approved',
			'has_consented',
			'is_previously_authorized',
			'skip_consent',
			'consent_cache',
		);

		foreach ( $forbidden_patterns as $pattern ) {
			$this->assertStringNotContainsString(
				$pattern,
				$source,
				'Q3 violated: memoization keyword "' . $pattern . '" found in AuthorizationController.'
			);
		}
	}
}
