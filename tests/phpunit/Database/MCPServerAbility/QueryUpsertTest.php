<?php
/**
 * Feature 017 — Query::upsert() test.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database\MCPServerAbility
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\MCPServerAbility;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class QueryUpsertTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::instance()->maybe_upgrade();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_server_abilities' ) );
	}

	public function test_upsert_inserts_when_row_absent(): void {
		$ok = Query::instance()->upsert( 1, 'core/get-user-info', true );
		$this->assertTrue( $ok );
		$rows = Query::instance()->query(
			array(
				'server_id'    => 1,
				'ability_slug' => 'core/get-user-info',
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 1, (int) $rows[0]->is_exposed );
	}

	public function test_upsert_updates_when_row_exists(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', true );
		Query::instance()->upsert( 1, 'core/get-user-info', false );
		$rows = Query::instance()->query(
			array(
				'server_id'    => 1,
				'ability_slug' => 'core/get-user-info',
			)
		);
		$this->assertCount( 1, $rows, 'Second upsert must not create a duplicate row' );
		$this->assertSame( 0, (int) $rows[0]->is_exposed );
	}

	public function test_upsert_isolates_per_server(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', true );
		Query::instance()->upsert( 2, 'core/get-user-info', false );
		$rows_s1 = Query::instance()->query(
			array(
				'server_id'    => 1,
				'ability_slug' => 'core/get-user-info',
			)
		);
		$rows_s2 = Query::instance()->query(
			array(
				'server_id'    => 2,
				'ability_slug' => 'core/get-user-info',
			)
		);
		$this->assertSame( 1, (int) $rows_s1[0]->is_exposed );
		$this->assertSame( 0, (int) $rows_s2[0]->is_exposed );
	}
}
