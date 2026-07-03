<?php
/**
 * Connector Audit Log list table — per-server OAuth event inspector.
 *
 * Adapted for F011 BerlinDB Query API + F013 PascalCase namespace.
 * Instantiated ONLY from within ClaudeConnectorBlock::render_connector_audit_log().
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAudit\Query as OAuthAuditQuery;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays paginated connector audit events for one MCP server.
 *
 * Per A10: WP_List_Table subclasses require a public constructor and are
 * instantiated per-render.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.0.6
 */
class ConnectorAuditLogListTable extends \WP_List_Table {

	/**
	 * MCP server row ID being inspected.
	 *
	 * @since 0.0.6
	 * @var int
	 */
	private $server_id;

	/**
	 * Constructor.
	 *
	 * @since 0.0.6
	 * @param int $server_id MCP server row ID.
	 */
	public function __construct( int $server_id ) {
		$this->server_id = absint( $server_id );

		parent::__construct(
			array(
				'singular' => 'connector_audit_event',
				'plural'   => 'connector_audit_events',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @since 0.0.6
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'event'      => __( 'Event', 'acrossai-mcp-manager' ),
			'user'       => __( 'User', 'acrossai-mcp-manager' ),
			'detail'     => __( 'Detail', 'acrossai-mcp-manager' ),
			'created_at' => __( 'Date / Time', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Empty-state message.
	 *
	 * @since 0.0.6
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No connector audit events have been logged for this server yet.', 'acrossai-mcp-manager' );
	}

	/**
	 * Prepare items + pagination via F011 BerlinDB Query API.
	 *
	 * @since 0.0.6
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$page     = $this->get_pagenum();

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$query = OAuthAuditQuery::instance();
		$args  = array(
			'server_id' => $this->server_id,
			'number'    => $per_page,
			'offset'    => ( $page - 1 ) * $per_page,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		);

		$rows        = (array) $query->query( $args );
		$this->items = array_map(
			static function ( $row ) {
				return method_exists( $row, 'to_array' ) ? $row->to_array() : (array) $row;
			},
			$rows
		);

		$count_args           = $args;
		$count_args['count']  = true;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		$total_items          = (int) $query->query( $count_args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Event column.
	 *
	 * @since 0.0.6
	 * @param array $item Row.
	 * @return string
	 */
	public function column_event( $item ) {
		return esc_html( sanitize_key( $item['event_type'] ?? '' ) );
	}

	/**
	 * User column.
	 *
	 * @since 0.0.6
	 * @param array $item Row.
	 * @return string
	 */
	public function column_user( $item ) {
		$user_id = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
		if ( 0 >= $user_id ) {
			return '&mdash;';
		}
		$user = get_user_by( 'id', $user_id );
		return $user ? esc_html( $user->user_login ) : '&mdash;';
	}

	/**
	 * Detail column.
	 *
	 * @since 0.0.6
	 * @param array $item Row.
	 * @return string
	 */
	public function column_detail( $item ) {
		if ( ! empty( $item['detail'] ) ) {
			return esc_html( (string) $item['detail'] );
		}
		return '&mdash;';
	}

	/**
	 * Created-at column.
	 *
	 * @since 0.0.6
	 * @param array $item Row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		if ( empty( $item['created_at'] ) ) {
			return '&mdash;';
		}
		$timestamp = mysql2date( 'U', $item['created_at'], false );
		if ( ! $timestamp ) {
			return esc_html( (string) $item['created_at'] );
		}
		return esc_html(
			wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				(int) $timestamp
			)
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @since 0.0.6
	 * @param array  $item        Row.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}
}
