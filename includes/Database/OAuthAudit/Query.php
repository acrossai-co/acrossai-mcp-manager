<?php
/**
 * OAuth audit log Query class — BerlinDB-style four-method interface.
 *
 * Append-only at the application level: update_item/delete_item are
 * preserved for cleanup cron only.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAudit
 */

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAudit;

defined( 'ABSPATH' ) || exit;

class Query {

	/**
	 * Activator helper — proxies to Table::maybe_create_table().
	 */
	public static function maybe_create_table(): void {
		Table::instance()->maybe_create_table();
	}

	/**
	 * Run a SELECT against the audit table.
	 *
	 * Supported $args keys: id, event_type, server_id, user_id, client_id,
	 * token_hash_prefix, number, offset, orderby, order, and 'older_than'
	 * (ISO datetime — for cleanup queries).
	 *
	 * @param array<string, mixed> $args
	 * @return Row[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$schema = Schema::instance();
		$table  = Table::instance()->get_table_name();

		$where_clause = array();
		$where_values = array();

		foreach ( array( 'id', 'event_type', 'server_id', 'user_id', 'client_id', 'token_hash_prefix' ) as $col ) {
			if ( ! isset( $args[ $col ] ) ) {
				continue;
			}
			$where_clause[] = "{$col} = " . $schema->format_for( $col );
			$where_values[] = $args[ $col ];
		}

		if ( isset( $args['older_than'] ) ) {
			$where_clause[] = 'created_at < %s';
			$where_values[] = (string) $args['older_than'];
		}

		$where_sql = ! empty( $where_clause ) ? ' WHERE ' . implode( ' AND ', $where_clause ) : '';

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
	 * Insert a row (mass-assignment defended via Schema column whitelist).
	 *
	 * @param array<string, mixed> $data
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

	/**
	 * Update a row by primary key. Only declared schema columns are written.
	 *
	 * @param int                  $id   Primary key of the row to update.
	 * @param array<string, mixed> $data Column-keyed values to write.
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

		return false !== $result;
	}

	/**
	 * Delete a row by primary key.
	 *
	 * @param int $id Primary key of the row to delete.
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

		return false !== $result;
	}

	/**
	 * Bulk delete rows older than the given datetime — used by FR-019b cleanup cron.
	 *
	 * @param string $datetime ISO 8601 MySQL datetime.
	 * @return int Number of rows deleted.
	 */
	public function delete_older_than( string $datetime ): int {
		global $wpdb;
		$table = Table::instance()->get_table_name();
		// Safe interpolation: $table is derived from a class constant + $wpdb->prefix,
		// never from user input. $datetime is bound via $wpdb->prepare.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $datetime ) );
		return (int) ( false === $result ? 0 : $result );
	}
}
