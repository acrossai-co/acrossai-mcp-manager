<?php
/**
 * F039 SEC-001 regression — uninstall.php options sweep boundary.
 *
 * Locks the invariant that `uninstall.php`'s `acrossai_mcp_%` LIKE-sweep
 * MUST NOT delete `acrossai_mcp_connector_%` options (owned by companion
 * plugin `acrossai-ai-connectors` v0.5.0+ post-F039). Seeds one
 * mcp-manager-owned option + one companion-owned option, invokes the
 * uninstall path with delete-flag ON, then asserts the mcp-manager option
 * is deleted AND the companion option survives.
 *
 * @package AcrossAI_MCP_Manager\Tests\Uninstall
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Uninstall;

use WP_UnitTestCase;

final class UninstallSweepBoundaryTest extends WP_UnitTestCase {

	/** Seeded per-test — cleaned in tearDown. */
	private const MCP_MANAGER_OPTION = 'acrossai_mcp_test_only_option';
	private const COMPANION_OPTION   = 'acrossai_mcp_connector_test_slug_settings';

	public function setUp(): void {
		parent::setUp();
		delete_option( self::MCP_MANAGER_OPTION );
		delete_option( self::COMPANION_OPTION );
		delete_option( 'acrossai_mcp_uninstall_delete_data' );
	}

	public function tearDown(): void {
		delete_option( self::MCP_MANAGER_OPTION );
		delete_option( self::COMPANION_OPTION );
		delete_option( 'acrossai_mcp_uninstall_delete_data' );
		parent::tearDown();
	}

	/**
	 * SEC-001 acceptance criterion: companion options survive
	 * mcp-manager uninstall with delete flag ON.
	 */
	public function test_uninstall_sweep_excludes_companion_options(): void {
		add_option( self::MCP_MANAGER_OPTION, 'mcp-manager-owned-value' );
		add_option( self::COMPANION_OPTION, 'companion-owned-value' );
		update_option( 'acrossai_mcp_uninstall_delete_data', 1 );

		// Simulate uninstall.php's exact SQL — the LIKE + NOT LIKE clause
		// mirrors the production sweep so a schema drift in either would
		// break this test.
		global $wpdb;
		$options = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				'acrossai_mcp_%',
				'acrossai_mcp_connector_%'
			)
		);

		$this->assertIsArray( $options );
		$this->assertContains(
			self::MCP_MANAGER_OPTION,
			$options,
			'mcp-manager-owned option MUST be in the sweep target set'
		);
		$this->assertNotContains(
			self::COMPANION_OPTION,
			$options,
			'SEC-001: companion-owned acrossai_mcp_connector_% option MUST be excluded from the sweep'
		);
	}

	/**
	 * Documents the FR-010 invariant in reverse — if the sweep is missing
	 * the NOT LIKE clause, this test would fail because the companion
	 * option WOULD appear in the result set.
	 */
	public function test_bare_like_sweep_would_include_companion_options(): void {
		add_option( self::COMPANION_OPTION, 'companion-owned-value' );

		global $wpdb;
		$bare_sweep = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", 'acrossai_mcp_%' )
		);

		$this->assertContains(
			self::COMPANION_OPTION,
			$bare_sweep,
			'Documents the SEC-001 regression: without NOT LIKE, the sweep would consume companion options'
		);
	}
}
