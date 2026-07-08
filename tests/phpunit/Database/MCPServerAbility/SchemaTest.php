<?php
/**
 * Feature 017 — Schema DDL parity test for the MCPServerAbility module.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database\MCPServerAbility
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\MCPServerAbility;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class SchemaTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::instance()->maybe_upgrade();
	}

	public function test_all_three_indexes_present_verbatim(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_server_abilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$this->assertNotEmpty( $rows, 'SHOW INDEX returned no rows — table may not exist.' );

		$index_names = array_unique( array_column( $rows, 'Key_name' ) );
		sort( $index_names );

		$expected = array( 'PRIMARY', 'server_ability', 'server_id' );
		sort( $expected );

		$this->assertSame( $expected, $index_names, 'F017 schema must expose exactly PRIMARY, server_ability, and server_id indexes.' );

		// The composite UNIQUE must include both columns in order.
		$server_ability_cols = array();
		foreach ( $rows as $row ) {
			if ( 'server_ability' === $row['Key_name'] ) {
				$server_ability_cols[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
			}
		}
		ksort( $server_ability_cols );
		$this->assertSame(
			array( 1 => 'server_id', 2 => 'ability_slug' ),
			$server_ability_cols,
			'UNIQUE server_ability must be (server_id, ability_slug) in that order.'
		);
	}

	public function test_ability_slug_column_is_varchar_191_utf8mb4_key_ceiling(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_server_abilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$slug = null;
		foreach ( $columns as $col ) {
			if ( 'ability_slug' === $col['Field'] ) {
				$slug = $col;
				break;
			}
		}
		$this->assertNotNull( $slug, 'ability_slug column missing' );
		$this->assertSame( 'varchar(191)', strtolower( $slug['Type'] ), 'ability_slug must be varchar(191) — F017 utf8mb4 767-byte key ceiling.' );
	}
}
