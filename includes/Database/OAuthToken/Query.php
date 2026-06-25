<?php
/**
 * OAuth access tokens Query class — BerlinDB-style four-method interface.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthToken
 */

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthToken;

defined( 'ABSPATH' ) || exit;

class Query {

	/**
	 * Activator helper — proxies to Table::maybe_create_table().
	 */
	public static function maybe_create_table(): void {
		Table::instance()->maybe_create_table();
	}

	/**
	 * Run a SELECT against the access-tokens table.
	 *
	 * Supported $args keys: id, access_token_hash, server_id, user_id,
	 * issued_from_code_id, number, offset, orderby, order,
	 * and the boolean flag 'active_only' (revoked_at IS NULL AND expires_at > NOW()).
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

		foreach ( array( 'id', 'access_token_hash', 'server_id', 'user_id', 'issued_from_code_id' ) as $col ) {
			if ( ! isset( $args[ $col ] ) ) {
				continue;
			}
			$where_clause[] = "{$col} = " . $schema->format_for( $col );
			$where_values[] = $args[ $col ];
		}

		if ( ! empty( $args['active_only'] ) ) {
			$where_clause[] = 'revoked_at IS NULL AND expires_at > %s';
			$where_values[] = current_time( 'mysql', 1 );
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
}
