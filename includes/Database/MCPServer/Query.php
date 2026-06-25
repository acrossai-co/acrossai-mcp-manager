<?php
/**
 * MCP Server query class — instance-based interface for admin code.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Query class for the `{prefix}acrossai_mcp_servers` table.
 *
 * Four-method instance interface (BerlinDB-style):
 *   - query( array $args = [] ): Row[]
 *   - add_item( array $data ): int|false   New row ID on success.
 *   - update_item( int $id, array $data ): bool
 *   - delete_item( int $id ): bool
 *
 * One static method for Phase 1 Activator:
 *   - Query::maybe_create_table()  — delegates to Table::instance()->maybe_create_table().
 */
class Query {

	/**
	 * Static helper called by Includes\Activator::activate() — Phase 1 contract.
	 */
	public static function maybe_create_table(): void {
		Table::instance()->maybe_create_table();
	}

	/**
	 * Run a SELECT against the table.
	 *
	 * Supported $args keys:
	 *   - id                    int   exact match on PK
	 *   - server_slug           string exact match
	 *   - is_enabled            int   exact match (0|1)
	 *   - registered_from       string exact match
	 *   - number                int   LIMIT — default 0 (no limit)
	 *   - offset                int   OFFSET — default 0
	 *   - orderby               string column name — default 'id'
	 *   - order                 string 'ASC'|'DESC' — default 'ASC'
	 *
	 * @return Row[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$schema = Schema::instance();
		$table  = Table::instance()->get_table_name();

		$where_sql    = '';
		$where_clause = array();
		$where_values = array();

		foreach ( array( 'id', 'server_slug', 'is_enabled', 'registered_from', 'claude_connector_client_id', 'server_route' ) as $col ) {
			if ( ! isset( $args[ $col ] ) ) {
				continue;
			}
			$where_clause[] = "{$col} = " . $schema->format_for( $col );
			$where_values[] = $args[ $col ];
		}

		if ( ! empty( $where_clause ) ) {
			$where_sql = ' WHERE ' . implode( ' AND ', $where_clause );
		}

		$orderby = isset( $args['orderby'] ) && $schema->has_column( (string) $args['orderby'] )
			? (string) $args['orderby']
			: 'id';
		$order   = isset( $args['order'] ) && 'DESC' === strtoupper( (string) $args['order'] )
			? 'DESC'
			: 'ASC';

		$limit_sql = '';
		$number    = isset( $args['number'] ) ? (int) $args['number'] : 0;
		$offset    = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		if ( $number > 0 ) {
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $number, max( 0, $offset ) );
		}

		$sql = "SELECT * FROM {$table}{$where_sql} ORDER BY {$orderby} {$order}{$limit_sql}";

		if ( ! empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $where_values );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $results ) ) {
			return array();
		}

		$rows = array();
		foreach ( $results as $r ) {
			$rows[] = new Row( $r );
		}
		return $rows;
	}

	/**
	 * Insert a new row. Unknown keys are dropped; missing defaults are filled
	 * from Schema::columns().
	 *
	 * @return int|false New row ID on success, false on failure.
	 */
	public function add_item( array $data ) {
		global $wpdb;

		$schema = Schema::instance();
		$table  = Table::instance()->get_table_name();

		$insert  = array();
		$formats = array();
		foreach ( $schema->columns() as $col => $meta ) {
			if ( 'id' === $col || 'created_at' === $col ) {
				continue; // auto-managed
			}
			if ( array_key_exists( $col, $data ) ) {
				$insert[ $col ] = ( '%d' === $meta['format'] ) ? (int) $data[ $col ] : (string) $data[ $col ];
			} elseif ( null !== $meta['default'] ) {
				$insert[ $col ] = $meta['default'];
			} else {
				continue;
			}
			$formats[] = $meta['format'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $table, $insert, $formats );
		if ( false === $result ) {
			return false;
		}

		wp_cache_delete( 'all_servers', Table::CACHE_GROUP );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a row by primary key. Only declared schema columns are written.
	 */
	public function update_item( int $id, array $data ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}

		$schema = Schema::instance();
		$table  = Table::instance()->get_table_name();

		$update  = array();
		$formats = array();
		foreach ( $data as $col => $value ) {
			if ( 'id' === $col || ! $schema->has_column( (string) $col ) ) {
				continue;
			}
			$format         = $schema->format_for( (string) $col );
			$update[ $col ] = ( '%d' === $format ) ? (int) $value : (string) $value;
			$formats[]      = $format;
		}

		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $table, $update, array( 'id' => $id ), $formats, array( '%d' ) );

		wp_cache_delete( 'server_' . $id, Table::CACHE_GROUP );
		wp_cache_delete( 'all_servers', Table::CACHE_GROUP );

		return false !== $result;
	}

	/**
	 * Delete a row by primary key.
	 */
	public function delete_item( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}

		$table = Table::instance()->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		wp_cache_delete( 'server_' . $id, Table::CACHE_GROUP );
		wp_cache_delete( 'all_servers', Table::CACHE_GROUP );

		return false !== $result;
	}
}
