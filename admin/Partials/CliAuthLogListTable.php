<?php
/**
 * CLI Auth Log list table — per-server auth attempt inspector.
 *
 * Ported from the reference plugin (wordpress-ai / src/Admin/CliAuthLogListTable.php).
 * Adapted to F011 BerlinDB Query API + F013 PascalCase namespace.
 *
 * Reintroduction is on-pattern per DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG
 * (F012) which pruned the standalone admin submenu but explicitly permits
 * per-server-tab inspection as the blessed replacement path. This ListTable
 * is instantiated ONLY from within NpmClientBlock::render_cli_connection_log()
 * — no standalone admin submenu is registered.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays paginated CLI auth logs for one MCP server.
 *
 * Per A10: WP_List_Table subclasses require a public constructor and are
 * instantiated per-render (not Loader-wired).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.0.6
 */
class CliAuthLogListTable extends \WP_List_Table {

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
				'singular' => 'cli_auth_log',
				'plural'   => 'cli_auth_logs',
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
			'user'       => __( 'User', 'acrossai-mcp-manager' ),
			'status'     => __( 'Status', 'acrossai-mcp-manager' ),
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
		esc_html_e( 'No CLI connection activity has been logged for this server yet.', 'acrossai-mcp-manager' );
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

		$query = CliAuthLogQuery::instance();
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
		if ( ! $user ) {
			return '&mdash;';
		}
		return esc_html( $user->user_login );
	}

	/**
	 * Status column.
	 *
	 * @since 0.0.6
	 * @param array $item Row.
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
	 * Detail column.
	 *
	 * @since 0.0.6
	 * @param array $item Row.
	 * @return string
	 */
	public function column_detail( $item ) {
		$status = sanitize_key( $item['status'] ?? '' );
		if ( 'failed' === $status && ! empty( $item['failure_code'] ) ) {
			return esc_html( (string) $item['failure_code'] );
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
	 * Default column renderer (returns empty string for unknown columns).
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
