<?php
/**
 * CLI auth log list table.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace ACROSSAI_MCP_MANAGER\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\CliAuthLogTable;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays paginated CLI auth logs for one MCP server.
 */
class CliAuthLogListTable extends \WP_List_Table {

	/**
	 * Current MCP server row ID.
	 *
	 * @var int
	 */
	private $server_id;

	/**
	 * Constructor.
	 *
	 * @param int $server_id MCP server row ID.
	 */
	public function __construct( $server_id ) {
		$this->server_id = absint( $server_id );

		parent::__construct(
			array(
				'singular' => 'cli_auth_log',
				'plural'   => 'cli_auth_logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'user'       => __( 'User', 'acrossai-mcp-manager' ),
			'status'     => __( 'Status', 'acrossai-mcp-manager' ),
			'detail'     => __( 'Detail', 'acrossai-mcp-manager' ),
			'created_at' => __( 'Date / Time', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Message shown when the table is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No CLI connection activity has been logged for this server yet.', 'acrossai-mcp-manager' );
	}

	/**
	 * Prepare items and pagination.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$page     = $this->get_pagenum();

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = CliAuthLogTable::get_logs_by_server( $this->server_id, $per_page, $page );

		$this->set_pagination_args(
			array(
				'total_items' => CliAuthLogTable::count_by_server( $this->server_id ),
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Render the user column.
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_user( $item ) {
		$user_id = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
		if ( $user_id <= 0 ) {
			return '&mdash;';
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return '&mdash;';
		}

		return esc_html( $user->user_login );
	}

	/**
	 * Render the status column.
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_status( $item ) {
		$status = sanitize_key( $item['status'] ?? '' );
		$labels = array(
			'approved' => __( 'Approved', 'acrossai-mcp-manager' ),
			'success'  => __( 'Success', 'acrossai-mcp-manager' ),
			'failed'   => __( 'Failed', 'acrossai-mcp-manager' ),
		);

		return esc_html( $labels[ $status ] ?? ucfirst( $status ) );
	}

	/**
	 * Render the detail column.
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_detail( $item ) {
		$status = sanitize_key( $item['status'] ?? '' );

		if ( 'failed' === $status && ! empty( $item['failure_code'] ) ) {
			return esc_html( $item['failure_code'] );
		}

		if ( 'success' === $status ) {
			return esc_html__( 'Application Password created', 'acrossai-mcp-manager' );
		}

		if ( 'approved' === $status ) {
			return esc_html__( 'Approved in browser', 'acrossai-mcp-manager' );
		}

		return '&mdash;';
	}

	/**
	 * Render the created_at column.
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_created_at( $item ) {
		if ( empty( $item['created_at'] ) ) {
			return '&mdash;';
		}

		$timestamp = mysql2date( 'U', $item['created_at'], false );
		if ( ! $timestamp ) {
			return esc_html( $item['created_at'] );
		}

		return esc_html(
			wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				$timestamp
			)
		);
	}

	/**
	 * Default renderer.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column name.
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}
}
