<?php
/**
 * MCP Manager Settings — admin page handler + tab renderer.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

/**
 * Settings is the central admin handler for the MCP Manager page.
 *
 * Responsibilities (populated over Phase 2 user stories):
 *   - US2 (here): handle_actions dispatcher for toggle/delete/bulk/create;
 *                 render_list_page (dispatcher: list | create form | edit page)
 *   - US3 (here): edit page tabs, update + claude_connector handlers
 *   - Notices (FR-015 + FR-016): extracted to Admin\Partials\Notices per RT-2
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter in body.
 * All hooks are wired externally by Includes\Main::define_admin_hooks().
 */
class Settings {

	/** @var Settings|null */
	protected static $_instance = null;

	/** @var string */
	private $plugin_name;

	/** @var string */
	private $version;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
		$this->version     = ACROSSAI_MCP_MANAGER_VERSION;
		// NO add_action / add_filter — wired by Includes\Main::define_admin_hooks().
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Action dispatcher (US2 + partial US3) — wired on admin_init priority 5.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Auto-heal the default MCP server row when it goes missing.
	 *
	 * DefaultServerSeeder::seed() is idempotent — it only inserts when the
	 * canonical slug is absent. Running it on admin_init means the row
	 * self-restores after a manual delete or bulk-delete that removed it,
	 * without requiring plugin reactivation. Mirrors the reference plugin
	 * pattern (see MCPServerTable::maybe_create_table → always seed).
	 *
	 * @return void
	 */
	public function maybe_seed_default_server(): void {
		DefaultServerSeeder::seed();
	}

	/**
	 * Route plugin-page actions to the right handler. FR-007 / FR-007a / FR-013.
	 *
	 * NB: Nonce verification happens inside each per-action branch (the page
	 * + action gate has no nonce of its own).
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || AdminPageSlugs::PARENT !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// US2: toggle_status, delete (single), create (POST), bulk.
		// US3: update (General-tab save), save_claude_connector.
		// F015 (post-Q4): access-control saves are owned by the vendor React
		// component via vendor REST — no plugin-owned action handler.
		if ( ! in_array( $action, array( 'toggle_status', 'delete', 'create', 'update', 'save_claude_connector' ), true )
			&& ! $this->is_bulk_request()
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ) );
		}

		// ── Single-row toggle_status ──────────────────────────────────────────
		if ( 'toggle_status' === $action ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_toggle_' . $server_id );

			if ( $server_id > 0 ) {
				$this->toggle_server_status( $server_id );
			}

			// `redirect_to=edit` is set by OverviewTab so the toggle button
			// on the server-edit page returns the user to the edit page
			// (overview tab) instead of the list.
			$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_key( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'edit' === $redirect_to && $server_id > 0 ) {
				$this->redirect_to_edit( $server_id, 'overview', 'server_toggled' );
			}

			$this->redirect_to_list( 'server_toggled' );
		}

		// ── Single-row delete ─────────────────────────────────────────────────
		if ( 'delete' === $action ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_delete_' . $server_id );

			if ( $server_id > 0 ) {
				Query::instance()->delete_item( $server_id );
			}
			$this->redirect_to_list( 'server_deleted' );
		}

		// ── Bulk action (enable / disable / delete) ──────────────────────────
		if ( $this->is_bulk_request() ) {
			check_admin_referer( 'bulk-mcp_servers' );
			$this->handle_bulk_actions();
			$this->redirect_to_list( 'bulk_completed' );
		}

		// ── Create (POST) ─────────────────────────────────────────────────────
		if ( 'create' === $action && $this->is_post_request() ) {
			check_admin_referer( 'acrossai_mcp_create_server' );
			$this->handle_create_server();
		}

		// ── Update (General-tab save, POST) — US3 / FR-009 / FR-013 ───────────
		if ( 'update' === $action && $this->is_post_request() ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_update_' . $server_id );
			$this->handle_update_server( $server_id );
		}

		// ── Claude Connector tab save (POST) — US3 / FR-012 / FR-013 ──────────
		if ( 'save_claude_connector' === $action && $this->is_post_request() ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_claude_connector_' . $server_id );
			$this->handle_claude_connector_update( $server_id );
		}

		// F015 note (post-Q4): access-control saves are owned by the vendor
		// React component via vendor REST endpoints (PUT/DELETE
		// /wpb-ac/v1/mcp/rules/{ns}/{key}). No plugin-owned POST handler here.
	}

	/**
	 * Toggle a server row's enabled state. Two-step per research.md R1:
	 * read current value, flip, update.
	 */
	private function toggle_server_status( int $server_id ): void {
		$query = Query::instance();
		$rows  = $query->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return;
		}
		$current_enabled = (int) $rows[0]->is_enabled;
		$query->update_item( $server_id, array( 'is_enabled' => 1 === $current_enabled ? 0 : 1 ) );
	}

	/**
	 * Detect a bulk-list-submit request. WP_List_Table puts the chosen action
	 * in `action` OR `action2` (top vs bottom dropdown) and serialised row
	 * IDs in `server_ids[]`.
	 */
	private function is_bulk_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action1 = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		$bulk    = in_array( $action1, array( 'enable', 'disable', 'delete' ), true )
			|| in_array( $action2, array( 'enable', 'disable', 'delete' ), true );
		$has_ids = isset( $_REQUEST['server_ids'] ) && is_array( $_REQUEST['server_ids'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $bulk && $has_ids;
	}

	private function is_post_request(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Apply enable / disable / delete to each selected row. FR-006 / FR-007.
	 * Caller verified the `bulk-mcp_servers` nonce and the capability.
	 */
	private function handle_bulk_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action1 = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		$action  = in_array( $action1, array( 'enable', 'disable', 'delete' ), true ) ? $action1 : $action2;
		$ids     = isset( $_REQUEST['server_ids'] ) && is_array( $_REQUEST['server_ids'] )
			? array_map( 'absint', wp_unslash( $_REQUEST['server_ids'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = Query::instance();
		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			if ( 'enable' === $action ) {
				$query->update_item( $id, array( 'is_enabled' => 1 ) );
			} elseif ( 'disable' === $action ) {
				$query->update_item( $id, array( 'is_enabled' => 0 ) );
			} elseif ( 'delete' === $action ) {
				$query->delete_item( $id );
			}
		}
	}

	/**
	 * Create-form handler. FR-007a. Caller already verified the nonce + cap.
	 */
	private function handle_create_server(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$name        = isset( $_POST['server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['server_name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$namespace   = isset( $_POST['server_route_namespace'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route_namespace'] ) ) : 'mcp';
		$route       = isset( $_POST['server_route'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route'] ) ) : '';
		$version     = isset( $_POST['server_version'] ) ? sanitize_text_field( wp_unslash( $_POST['server_version'] ) ) : 'v1.0.0';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $name ) {
			$this->redirect_to_create( 'empty_name' );
		}

		$slug  = sanitize_title( $name );
		$query = Query::instance();

		// Slug collision check via Query — R1 mapping.
		$existing = $query->query(
			array(
				'server_slug' => $slug,
				'number'      => 1,
			)
		);
		if ( ! empty( $existing ) ) {
			$this->redirect_to_create( 'slug_exists' );
		}

		if ( '' === $route ) {
			$route = $slug;
		}

		$new_id = $query->add_item(
			array(
				'server_name'            => $name,
				'server_slug'            => $slug,
				'description'            => $description,
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => $namespace,
				'server_route'           => $route,
				'server_version'         => $version,
			)
		);

		if ( ! $new_id ) {
			$this->redirect_to_create( 'db_error' );
		}

		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'edit',
						'server' => $new_id,
						'notice' => 'server_created',
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	private function redirect_to_list( string $notice ): void {
		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'notice' => $notice,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	private function redirect_to_create( string $notice ): void {
		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'create',
						'notice' => $notice,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	private function redirect_to_edit( int $server_id, string $tab, string $notice ): void {
		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'edit',
						'server' => $server_id,
						'tab'    => $tab,
						'notice' => $notice,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	/**
	 * General-tab save handler. FR-009 / FR-013. Caller verified nonce + cap.
	 */
	private function handle_update_server( int $server_id ): void {
		$query = Query::instance();
		$rows  = $query->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			$this->redirect_to_list( 'server_not_found' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$data = array(
			'server_name'            => isset( $_POST['server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['server_name'] ) ) : '',
			'description'            => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'server_route_namespace' => isset( $_POST['server_route_namespace'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route_namespace'] ) ) : 'mcp',
			'server_route'           => isset( $_POST['server_route'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route'] ) ) : '',
			'server_version'         => isset( $_POST['server_version'] ) ? sanitize_text_field( wp_unslash( $_POST['server_version'] ) ) : 'v1.0.0',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $data['server_name'] ) {
			$this->redirect_to_edit( $server_id, 'update-server', 'empty_name' );
		}
		if ( '' === $data['server_route'] ) {
			$data['server_route'] = $rows[0]->server_slug;
		}

		$query->update_item( $server_id, $data );
		$this->redirect_to_edit( $server_id, 'update-server', 'server_saved' );
	}

	/**
	 * Claude Connector tab save handler. FR-012 / FR-013.
	 *
	 * Notable: when the Secret field receives only the masked placeholder
	 * (the dots we render for re-display), we KEEP the existing stored value
	 * — don't overwrite with placeholder. This prevents a UX surprise where
	 * a user re-saves the form without re-entering the secret.
	 */
	private function handle_claude_connector_update( int $server_id ): void {
		$query = Query::instance();
		$rows  = $query->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			$this->redirect_to_list( 'server_not_found' );
		}
		$row = $rows[0];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$client_id     = isset( $_POST['claude_connector_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['claude_connector_client_id'] ) ) : '';
		$client_secret = isset( $_POST['claude_connector_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['claude_connector_client_secret'] ) ) : '';
		$redirect_uri  = isset( $_POST['claude_connector_redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['claude_connector_redirect_uri'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Preserve existing secret if the user submitted only the mask.
		if ( $this->is_secret_placeholder( $client_secret ) ) {
			$client_secret = $row->claude_connector_client_secret;
		}

		$query->update_item(
			$server_id,
			array(
				'claude_connector_client_id'     => $client_id,
				'claude_connector_client_secret' => $client_secret,
				'claude_connector_redirect_uri'  => $redirect_uri,
			)
		);

		$this->redirect_to_edit( $server_id, 'claude-connector', 'server_saved' );
	}

	private function is_secret_placeholder( string $value ): bool {
		// Mask we render is 12 bullet characters; treat any all-bullet input as placeholder.
		return '' !== $value && '' === preg_replace( '/[•\*]/u', '', $value );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Page render — wired as the menu callback by Menu::register_menu().
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Dispatcher: route to list / create / edit based on the `action` query var.
	 */
	public function render_list_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'create' === $action ) {
			$this->render_create_form();
			return;
		}
		if ( 'edit' === $action ) {
			$this->render_edit_page();
			return;
		}

		$this->render_servers_table();
	}

	private function render_servers_table(): void {
		$table = new MCPServerListTable();
		$table->prepare_items();

		$create_url = esc_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'create',
				),
				admin_url( 'admin.php' )
			)
		);

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end" />',
			esc_html__( 'MCP Servers', 'acrossai-mcp-manager' ),
			esc_url( $create_url ), // SEC-S2: defense in depth — esc_url is idempotent.
			esc_html__( 'Add New', 'acrossai-mcp-manager' )
		);

		echo '<form method="post">';
		// Required nonce for bulk actions — WP_List_Table::display() expects `bulk-{plural}` nonce.
		wp_nonce_field( 'bulk-mcp_servers' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * "Add New" create form. Submits POST to ?page=...&action=create which is
	 * handled by Settings::handle_actions → handle_create_server().
	 */
	private function render_create_form(): void {
		$post_url = esc_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'create',
				),
				admin_url( 'admin.php' )
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add New MCP Server', 'acrossai-mcp-manager' ); ?></h1>
			<form method="post" action="<?php echo esc_url( $post_url ); /* SEC-S2: defense in depth — esc_url is idempotent */ ?>">
				<?php wp_nonce_field( 'acrossai_mcp_create_server' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="server_name"><?php esc_html_e( 'Name', 'acrossai-mcp-manager' ); ?></label></th>
						<td><input type="text" id="server_name" name="server_name" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="description"><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></label></th>
						<td><textarea id="description" name="description" class="large-text" rows="3"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="server_route_namespace"><?php esc_html_e( 'Route Namespace', 'acrossai-mcp-manager' ); ?></label></th>
						<td><input type="text" id="server_route_namespace" name="server_route_namespace" value="mcp" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="server_route"><?php esc_html_e( 'Route', 'acrossai-mcp-manager' ); ?></label></th>
						<td>
							<input type="text" id="server_route" name="server_route" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Defaults to the sanitised name slug if left blank.', 'acrossai-mcp-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="server_version"><?php esc_html_e( 'Version', 'acrossai-mcp-manager' ); ?></label></th>
						<td><input type="text" id="server_version" name="server_version" value="v1.0.0" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create Server', 'acrossai-mcp-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Four-tab edit page. FR-008 / FR-014.
	 *
	 * URL: ?page=acrossai_mcp_manager&action=edit&server=ID&tab=<slug>
	 * Tabs: general (default), tokens, access_control, claude_connector.
	 */
	private function render_edit_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0;
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Feature 013 — legacy tab slug back-compat (pre-F013 bookmarks/links).
		$legacy_slug_map = array(
			'general'          => 'overview',
			'access_control'   => 'access-control',
			'claude_connector' => 'claude-connector',
		);
		if ( isset( $legacy_slug_map[ $tab ] ) ) {
			$tab = $legacy_slug_map[ $tab ];
		}

		// FR-014: missing-server → redirect to list.
		$rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			$this->redirect_to_list( 'server_not_found' );
		}
		$row    = $rows[0];
		$server = $row->to_array();

		// Feature 013 — Registry dispatches per-tab render + supplies visible tab list to the nav.
		$registry = \AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::instance();
		$tabs     = array();
		foreach ( $registry->visible_tabs( $server ) as $tab_obj ) {
			$tabs[ $tab_obj->slug() ] = $tab_obj->label();
		}
		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap">';
		printf(
			'<h1>%s — %s</h1>',
			esc_html__( 'Edit MCP Server', 'acrossai-mcp-manager' ),
			esc_html( $row->server_name )
		);

		SettingsRenderer::instance()->render_tab_nav( $tabs, $tab, $server_id );

		$registry->render( $tab, $server );

		echo '</div>';
	}
}
