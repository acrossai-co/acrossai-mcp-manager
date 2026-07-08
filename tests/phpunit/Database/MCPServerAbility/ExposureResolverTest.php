<?php
/**
 * Feature 017 — ExposureResolver test.
 *
 * FR-007 fallback invariant + FR-008 single-source-of-truth invariant.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database\MCPServerAbility
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\MCPServerAbility;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class ExposureResolverTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::instance()->maybe_upgrade();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_server_abilities' ) );
		ExposureResolver::_reset_cache_for_tests();
	}

	public function test_row_is_exposed_true_overrides_falsy_meta(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', true );
		$this->assertTrue( ExposureResolver::resolve( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => false ) ) ) );
	}

	public function test_row_is_exposed_false_overrides_truthy_meta(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', false );
		$this->assertFalse( ExposureResolver::resolve( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => true ) ) ) );
	}

	public function test_no_row_falls_back_to_meta_true(): void {
		$this->assertTrue( ExposureResolver::resolve( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => true ) ) ) );
	}

	public function test_no_row_falls_back_to_meta_missing(): void {
		$this->assertFalse( ExposureResolver::resolve( 1, 'core/get-user-info', array() ) );
	}

	public function test_no_row_falls_back_to_meta_false(): void {
		$this->assertFalse( ExposureResolver::resolve( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => false ) ) ) );
	}

	public function test_per_request_cache_avoids_second_query(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', true );
		$first  = ExposureResolver::resolve( 1, 'core/get-user-info', array() );
		// Mutate the row directly and re-resolve — expect the CACHED value
		// (proves the second call did not hit the DB).
		Query::instance()->upsert( 1, 'core/get-user-info', false );
		$second = ExposureResolver::resolve( 1, 'core/get-user-info', array() );
		$this->assertTrue( $first, 'first read must be true' );
		$this->assertTrue( $second, 'second read must return cached true — did not hit DB after row was flipped' );
	}
}
