<?php
/**
 * CLI auth log list table (WP_List_Table).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read-only paginated list of CLI auth log rows, optionally scoped to one server.
 *
 * Surfaced in:
 *  - CLI Auth Log submenu (Settings::render_cli_auth_log_page, all servers)
 *  - Tokens tab on the per-server edit page (US3, scoped to that server)
 *
 * Singleton exception per MCPServerListTable — see that file for rationale.
 */
class CliAuthLogListTable extends \WP_List_Table {

	/** @var int 0 = all servers; >0 = scoped to one server */
	private $server_id;

	public function __construct( int $server_id = 0 ) {
		$this->server_id = absint( $server_id );

		parent::__construct(
			array(
				'singular' => 'cli_auth_log',
				'plural'   => 'cli_auth_logs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		$cols = array();
		if ( 0 === $this->server_id ) {
			$cols['server_slug'] = esc_html__( 'Server', 'acrossai-mcp-manager' );
		}
		$cols['user']       = esc_html__( 'User', 'acrossai-mcp-manager' );
		$cols['status']     = esc_html__( 'Status', 'acrossai-mcp-manager' );
		$cols['detail']     = esc_html__( 'Detail', 'acrossai-mcp-manager' );
		$cols['created_at'] = esc_html__( 'Date / Time', 'acrossai-mcp-manager' );
		return $cols;
	}

	public function no_items(): void {
		if ( 0 === $this->server_id ) {
			esc_html_e( 'No CLI connection activity has been logged yet.', 'acrossai-mcp-manager' );
		} else {
			esc_html_e( 'No CLI connection activity has been logged for this server yet.', 'acrossai-mcp-manager' );
		}
	}

	public function prepare_items(): void {
		$per_page = 20;
		$page     = max( 1, $this->get_pagenum() );

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$query_args = array(
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);

		if ( $this->server_id > 0 ) {
			$query_args['server_id'] = $this->server_id;
		}

		$query       = Query::instance();
		$rows        = $query->query( $query_args );
		$this->items = array_map( static fn( $row ) => $row->to_array(), $rows );

		// Total count for pagination — re-query with no LIMIT.
		$total_args  = $this->server_id > 0 ? array( 'server_id' => $this->server_id ) : array();
		$total_rows  = $query->query( $total_args );
		$total_items = count( $total_rows );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_server_slug( $item ): string {
		return esc_html( $item['server_slug'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_user( $item ): string {
		$user_id = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
		if ( $user_id <= 0 ) {
			return '—';
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return '—';
		}
		return esc_html( $user->user_login );
	}

	/**
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_status( $item ): string {
		$status = sanitize_key( $item['status'] ?? '' );
		$labels = array(
			'approved' => __( 'Approved', 'acrossai-mcp-manager' ),
			'success'  => __( 'Success', 'acrossai-mcp-manager' ),
			'failed'   => __( 'Failed', 'acrossai-mcp-manager' ),
		);
		return esc_html( $labels[ $status ] ?? ucfirst( $status ) );
	}

	/**
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_detail( $item ): string {
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
		return '—';
	}

	/**
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_created_at( $item ): string {
		if ( empty( $item['created_at'] ) ) {
			return '—';
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

	public function column_default( $item, $column_name ): string {
		return '';
	}
}
