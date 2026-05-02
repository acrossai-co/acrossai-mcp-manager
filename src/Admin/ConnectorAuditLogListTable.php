<?php
/**
 * Connector audit log list table.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace ACROSSAI_MCP_MANAGER\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\ConnectorAuditLogTable;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays paginated direct connector audit logs for one MCP server.
 */
class ConnectorAuditLogListTable extends \WP_List_Table {

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
				'singular' => 'connector_audit_log',
				'plural'   => 'connector_audit_logs',
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
			'event_type'  => __( 'Event', 'acrossai-mcp-manager' ),
			'status'      => __( 'Status', 'acrossai-mcp-manager' ),
			'user'        => __( 'User', 'acrossai-mcp-manager' ),
			'client_id'   => __( 'Client', 'acrossai-mcp-manager' ),
			'detail'      => __( 'Detail', 'acrossai-mcp-manager' ),
			'created_at'  => __( 'Date / Time', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Message shown when the table is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No direct connector activity has been logged for this server yet.', 'acrossai-mcp-manager' );
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
		$this->items           = ConnectorAuditLogTable::get_logs_by_server( $this->server_id, $per_page, $page, true );

		$this->set_pagination_args(
			array(
				'total_items' => ConnectorAuditLogTable::count_by_server( $this->server_id, true ),
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Render the event column.
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_event_type( $item ) {
		$event = sanitize_key( $item['event_type'] ?? '' );
		$map   = array(
			'authorization_server_metadata' => __( 'Auth Server Metadata', 'acrossai-mcp-manager' ),
			'resource_metadata'   => __( 'Resource Metadata', 'acrossai-mcp-manager' ),
			'authorize_request'   => __( 'Authorize Request', 'acrossai-mcp-manager' ),
			'authorize_decision'  => __( 'Authorize Decision', 'acrossai-mcp-manager' ),
			'token_exchange'      => __( 'Token Exchange', 'acrossai-mcp-manager' ),
			'bearer_auth'         => __( 'Bearer Auth', 'acrossai-mcp-manager' ),
			'mcp_request'         => __( 'MCP Request', 'acrossai-mcp-manager' ),
			'access_control'      => __( 'Access Control', 'acrossai-mcp-manager' ),
		);

		return esc_html( $map[ $event ] ?? ucfirst( str_replace( '_', ' ', $event ) ) );
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
		return esc_html( ucfirst( $status ?: 'unknown' ) );
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
		return $user ? esc_html( $user->user_login ) : '&mdash;';
	}

	/**
	 * Render the client ID column.
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_client_id( $item ) {
		if ( empty( $item['client_id'] ) ) {
			return '&mdash;';
		}

		return '<code>' . esc_html( $item['client_id'] ) . '</code>';
	}

	/**
	 * Render the detail column.
	 *
	 * @param array $item Row data.
	 *
	 * @return string
	 */
	public function column_detail( $item ) {
		$parts = array();

		if ( ! empty( $item['failure_code'] ) ) {
			$parts[] = $item['failure_code'];
		}

		if ( ! empty( $item['request_route'] ) ) {
			$parts[] = $item['request_route'];
		}

		if ( ! empty( $item['resource_url'] ) ) {
			$parts[] = $item['resource_url'];
		}

		if ( ! empty( $item['response_code'] ) ) {
			$parts[] = '#' . (int) $item['response_code'];
		}

		if ( ! empty( $item['details'] ) ) {
			$decoded = json_decode( (string) $item['details'], true );
			if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
				$parts[] = $decoded['message'];
			}
		}

		if ( empty( $parts ) ) {
			return '&mdash;';
		}

		return esc_html( implode( ' | ', $parts ) );
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
		unset( $item, $column_name );
		return '';
	}
}
