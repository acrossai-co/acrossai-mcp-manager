<?php
/**
 * CLI auth log query class — instance-based interface for admin code.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

/**
 * Query class for the `{prefix}acrossai_mcp_cli_auth_logs` table.
 *
 * Four-method instance interface (BerlinDB-style):
 *   - query( array $args = [] ): Row[]
 *   - add_item( array $data ): int|false
 *   - update_item( int $id, array $data ): bool
 *   - delete_item( int $id ): bool
 *
 * Static helper:
 *   - Query::maybe_create_table()  — delegates to Table::instance()->maybe_create_table().
 *
 * This phase reads only; writes are reserved for the CLI auth feature phase.
 */
class Query {

	public static function maybe_create_table(): void {
		Table::instance()->maybe_create_table();
	}

	/**
	 * Run a SELECT.
	 *
	 * Supported $args keys:
	 *   - id, server_id, server_slug, user_id, status — exact-match filters
	 *   - number (LIMIT, default 0 = no limit), offset (default 0)
	 *   - orderby (column name, default 'created_at'), order ('ASC'|'DESC', default 'DESC')
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

		foreach ( array( 'id', 'server_id', 'server_slug', 'user_id', 'status', 'auth_code_hash' ) as $col ) {
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
			: 'created_at';
		$order   = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] )
			? 'ASC'
			: 'DESC';

		$limit_sql = '';
		$number    = isset( $args['number'] ) ? (int) $args['number'] : 0;
		$offset    = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		if ( $number > 0 ) {
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $number, max( 0, $offset ) );
		}

		$sql = "SELECT * FROM {$table}{$where_sql} ORDER BY {$orderby} {$order}, id {$order}{$limit_sql}";

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
	 * @return int|false New row ID on success.
	 */
	public function add_item( array $data ) {
		global $wpdb;

		$schema = Schema::instance();
		$table  = Table::instance()->get_table_name();

		$insert  = array();
		$formats = array();
		foreach ( $schema->columns() as $col => $meta ) {
			if ( 'id' === $col || 'created_at' === $col ) {
				continue;
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

		return (int) $wpdb->insert_id;
	}

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

		return false !== $result;
	}

	public function delete_item( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}

		$table = Table::instance()->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * SEC-001 atomic redeem — sets completed_at to the given timestamp only
	 * if it is currently NULL. Single UPDATE for race-free check-and-set.
	 *
	 * @return bool true if THIS request won the race (rows_affected === 1).
	 */
	public function redeem_atomic( int $id, string $now ): bool {
		global $wpdb;
		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}
		$table = Table::instance()->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET completed_at = %s WHERE id = %d AND completed_at IS NULL",
				$now,
				$id
			)
		);
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Bulk-delete OAuth code rows beyond the retention window.
	 *
	 * @return int rows deleted.
	 */
	public function delete_expired_oauth_codes( string $cutoff ): int {
		global $wpdb;
		$table = Table::instance()->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'oauth_code_issued' AND created_at < %s",
				$cutoff
			)
		);
		return (int) ( false === $result ? 0 : $result );
	}
}
