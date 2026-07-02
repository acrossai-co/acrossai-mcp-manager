<?php
/**
 * MCP Server list table (WP_List_Table).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the server list page (`?page=acrossai_mcp_manager`) per FR-004 / FR-006.
 *
 * NOTE: List-table subclasses are excepted from the singleton-only rule
 * because (a) they extend \WP_List_Table which requires its own public
 * constructor + parent::__construct() call, (b) they are instantiated
 * per-render inside Settings, never wired into hooks via the Loader
 * (so the B5 double-hook risk does not apply).
 */
class MCPServerListTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mcp_server',
				'plural'   => 'mcp_servers',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns per FR-004: Name, Slug, Status, Registered From, Route Namespace,
	 * Route, Version, Actions. Plus a `cb` checkbox column to enable bulk actions.
	 */
	public function get_columns(): array {
		return array(
			'cb'              => '<input type="checkbox" />',
			'name'            => esc_html__( 'Name', 'acrossai-mcp-manager' ),
			'slug'            => esc_html__( 'Slug', 'acrossai-mcp-manager' ),
			'status'          => esc_html__( 'Status', 'acrossai-mcp-manager' ),
			'source'          => esc_html__( 'Registered From', 'acrossai-mcp-manager' ),
			'route_namespace' => esc_html__( 'Route Namespace', 'acrossai-mcp-manager' ),
			'route'           => esc_html__( 'Route', 'acrossai-mcp-manager' ),
			'version'         => esc_html__( 'Version', 'acrossai-mcp-manager' ),
			'actions'         => esc_html__( 'Actions', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Bulk actions per FR-006. Each handler in Settings::handle_actions
	 * verifies the WP-Lists nonce action `bulk-mcp_servers`.
	 */
	public function get_bulk_actions(): array {
		return array(
			'enable'  => esc_html__( 'Enable', 'acrossai-mcp-manager' ),
			'disable' => esc_html__( 'Disable', 'acrossai-mcp-manager' ),
			'delete'  => esc_html__( 'Delete', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Pull rows via the BerlinDB-style Query class. FR-005 + FR-022.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$rows = Query::instance()->query(
			array(
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$this->items = array_map(
			static function ( $row ) {
				return array(
					'id'                     => $row->id,
					'name'                   => $row->server_name,
					'slug'                   => $row->server_slug,
					'description'            => $row->description,
					'enabled'                => 1 === $row->is_enabled,
					'registered_from'        => $row->registered_from,
					'server_route_namespace' => $row->server_route_namespace,
					'server_route'           => $row->server_route,
					'server_version'         => $row->server_version,
				);
			},
			$rows
		);
	}

	/**
	 * Row checkbox for bulk actions.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="server_ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	/**
	 * Fallback column renderer for `slug`, `route_namespace`, `route`, `version`.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'slug':
				return esc_html( $item['slug'] );
			case 'route_namespace':
				return esc_html( $item['server_route_namespace'] );
			case 'route':
				return esc_html( $item['server_route'] );
			case 'version':
				return esc_html( $item['server_version'] );
			default:
				return '';
		}
	}

	/**
	 * Name column with row actions (Edit + conditional Delete).
	 * Source-repo behavior preserved: Delete row action only appears for
	 * 'database'-source rows (the seeded default-plugin row is not deletable
	 * from the UI).
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_name( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'edit',
				'server' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$row_actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'acrossai-mcp-manager' )
			),
		);

		// Toggle Status row action (in addition to the button in the Actions column).
		$toggle_url            = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'toggle_status',
					'server' => (int) $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'acrossai_mcp_toggle_' . (int) $item['id']
		);
		$row_actions['toggle'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $toggle_url ),
			esc_html__( 'Toggle Status', 'acrossai-mcp-manager' )
		);

		if ( 'database' === $item['registered_from'] ) {
			$delete_url            = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'delete',
						'server' => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'acrossai_mcp_delete_' . (int) $item['id']
			);
			$row_actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Are you sure you want to delete this server? This cannot be undone.', 'acrossai-mcp-manager' ) ),
				esc_html__( 'Delete', 'acrossai-mcp-manager' )
			);
		}

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $row_actions )
		);
	}

	/**
	 * Source badge: plugin / database / theme / core.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_source( $item ): string {
		$source = $item['registered_from'];

		$labels = array(
			'plugin'   => __( 'Plugin', 'acrossai-mcp-manager' ),
			'database' => __( 'Database', 'acrossai-mcp-manager' ),
			'theme'    => __( 'Theme', 'acrossai-mcp-manager' ),
			'core'     => __( 'Core', 'acrossai-mcp-manager' ),
		);

		$label = $labels[ $source ] ?? $source;
		$class = 'acrossai-source-badge acrossai-source-' . sanitize_html_class( $source );

		return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Status badge (Active / Inactive).
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_status( $item ): string {
		if ( $item['enabled'] ) {
			return '<span class="acrossai-status-badge acrossai-status-active">'
				. esc_html__( 'Active', 'acrossai-mcp-manager' )
				. '</span>';
		}
		return '<span class="acrossai-status-badge acrossai-status-inactive">'
			. esc_html__( 'Inactive', 'acrossai-mcp-manager' )
			. '</span>';
	}

	/**
	 * Enable / Disable button in the Actions column. Carries a per-row nonce.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_actions( $item ): string {
		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'toggle_status',
					'server' => (int) $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'acrossai_mcp_toggle_' . (int) $item['id']
		);

		if ( $item['enabled'] ) {
			return sprintf(
				'<a href="%s" class="button button-small acrossai-btn-disable">%s</a>',
				esc_url( $toggle_url ),
				esc_html__( 'Disable', 'acrossai-mcp-manager' )
			);
		}
		return sprintf(
			'<a href="%s" class="button button-small button-primary acrossai-btn-enable">%s</a>',
			esc_url( $toggle_url ),
			esc_html__( 'Enable', 'acrossai-mcp-manager' )
		);
	}
}
