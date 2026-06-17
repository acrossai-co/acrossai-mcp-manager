<?php
/**
 * MCP Manager Settings — admin page handler + tab renderer.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

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

			$this->redirect_to_list( 'server_toggled' );
		}

		// ── Single-row delete ─────────────────────────────────────────────────
		if ( 'delete' === $action ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_delete_' . $server_id );

			if ( $server_id > 0 ) {
				( new Query() )->delete_item( $server_id );
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
	}

	/**
	 * Toggle a server row's enabled state. Two-step per research.md R1:
	 * read current value, flip, update.
	 */
	private function toggle_server_status( int $server_id ): void {
		$query = new Query();
		$rows  = $query->query( array( 'id' => $server_id, 'number' => 1 ) );
		if ( empty( $rows ) ) {
			return;
		}
		$current_enabled = $rows[0]->is_enabled;
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

		$query = new Query();
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
		$query = new Query();

		// Slug collision check via Query — R1 mapping.
		$existing = $query->query( array( 'server_slug' => $slug, 'number' => 1 ) );
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
		$query = new Query();
		$rows  = $query->query( array( 'id' => $server_id, 'number' => 1 ) );
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
			$this->redirect_to_edit( $server_id, 'general', 'empty_name' );
		}
		if ( '' === $data['server_route'] ) {
			$data['server_route'] = $rows[0]->server_slug;
		}

		$query->update_item( $server_id, $data );
		$this->redirect_to_edit( $server_id, 'general', 'server_saved' );
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
		$query = new Query();
		$rows  = $query->query( array( 'id' => $server_id, 'number' => 1 ) );
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

		$this->redirect_to_edit( $server_id, 'claude_connector', 'server_saved' );
	}

	private function is_secret_placeholder( string $value ): bool {
		// Mask we render is 12 bullet characters; treat any all-bullet input as placeholder.
		return '' !== $value && '' === preg_replace( '/[•\*]/u', '', $value );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Settings API registration (no-op stub; populated in US3 T020).
	// ─────────────────────────────────────────────────────────────────────────

	public function register_settings(): void {
		// US3 T020 ports the full register_setting / add_settings_section / field calls.
		// Empty body is safe — register_settings is called on admin_init and may be a no-op.
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
			<form method="post" action="<?php echo esc_url( $post_url ); /* SEC-S2: defense in depth — esc_url is idempotent */?>">
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
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// FR-014: missing-server → redirect to list.
		$rows = ( new Query() )->query( array( 'id' => $server_id, 'number' => 1 ) );
		if ( empty( $rows ) ) {
			$this->redirect_to_list( 'server_not_found' );
		}
		$row    = $rows[0];
		$server = $row->to_array();

		$tabs = array(
			'general'          => __( 'General', 'acrossai-mcp-manager' ),
			'tokens'           => __( 'Tokens', 'acrossai-mcp-manager' ),
			'access_control'   => __( 'Access Control', 'acrossai-mcp-manager' ),
			'claude_connector' => __( 'Claude Connector', 'acrossai-mcp-manager' ),
		);
		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = 'general';
		}

		echo '<div class="wrap">';
		printf(
			'<h1>%s — %s</h1>',
			esc_html__( 'Edit MCP Server', 'acrossai-mcp-manager' ),
			esc_html( $row->server_name )
		);

		SettingsRenderer::instance()->render_tab_nav( $tabs, $tab, $server_id );

		switch ( $tab ) {
			case 'tokens':
				$this->render_tokens_tab( $server );
				break;
			case 'access_control':
				$this->render_access_control_tab( $server );
				break;
			case 'claude_connector':
				$this->render_claude_connector_tab( $server );
				break;
			case 'general':
			default:
				$this->render_general_tab( $server );
				break;
		}

		echo '</div>';
	}

	/**
	 * General tab — Name, Description, Route Namespace, Route, Version. FR-009.
	 *
	 * @param array<string, mixed> $server Row data.
	 */
	private function render_general_tab( array $server ): void {
		$post_url = esc_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'update',
					'server' => (int) $server['id'],
				),
				admin_url( 'admin.php' )
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); /* SEC-S2: defense in depth — esc_url is idempotent */?>">
			<?php wp_nonce_field( 'acrossai_mcp_update_' . (int) $server['id'] ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="server_name"><?php esc_html_e( 'Name', 'acrossai-mcp-manager' ); ?></label></th>
					<td><input type="text" id="server_name" name="server_name" class="regular-text" value="<?php echo esc_attr( $server['server_name'] ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="description"><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></label></th>
					<td><textarea id="description" name="description" class="large-text" rows="3"><?php echo esc_textarea( $server['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="server_route_namespace"><?php esc_html_e( 'Route Namespace', 'acrossai-mcp-manager' ); ?></label></th>
					<td><input type="text" id="server_route_namespace" name="server_route_namespace" class="regular-text" value="<?php echo esc_attr( $server['server_route_namespace'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="server_route"><?php esc_html_e( 'Route', 'acrossai-mcp-manager' ); ?></label></th>
					<td><input type="text" id="server_route" name="server_route" class="regular-text" value="<?php echo esc_attr( $server['server_route'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="server_version"><?php esc_html_e( 'Version', 'acrossai-mcp-manager' ); ?></label></th>
					<td><input type="text" id="server_version" name="server_version" class="regular-text" value="<?php echo esc_attr( $server['server_version'] ); ?>" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save General', 'acrossai-mcp-manager' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Tokens tab — delegates to ApplicationPasswords.
	 *
	 * @param array<string, mixed> $server Row data.
	 */
	private function render_tokens_tab( array $server ): void {
		ApplicationPasswords::instance()->render_for_server( (int) $server['id'] );
	}

	/**
	 * Access Control tab — delegates to vendor manager when present,
	 * renders an informational notice when absent. FR-011.
	 *
	 * @param array<string, mixed> $server Row data.
	 */
	private function render_access_control_tab( array $server ): void {
		if ( ! class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
			echo '<div class="notice notice-info inline"><p>';
			esc_html_e( 'Access Control requires the wpb-access-control package. Install it to manage per-server access rules.', 'acrossai-mcp-manager' );
			echo '</p></div>';
			return;
		}

		// Vendor manager is responsible for its own rendering + persistence.
		$manager = \WPBoilerplate\AccessControl\AccessControlManager::instance( 'acrossai_mcp_access_control_providers' );
		if ( method_exists( $manager, 'render_admin_page' ) ) {
			$manager->render_admin_page( (int) $server['id'] );
			return;
		}

		// Fallback notice when the vendor API we expected isn't present yet.
		echo '<div class="notice notice-warning inline"><p>';
		esc_html_e( 'Access Control package is present but does not expose the expected render_admin_page() API for this version.', 'acrossai-mcp-manager' );
		echo '</p></div>';
	}

	/**
	 * Claude Connector tab — OAuth Client ID / Secret / Redirect URI. FR-012.
	 * Secret is masked on re-render after first save.
	 *
	 * @param array<string, mixed> $server Row data.
	 */
	private function render_claude_connector_tab( array $server ): void {
		$post_url = esc_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'save_claude_connector',
					'server' => (int) $server['id'],
				),
				admin_url( 'admin.php' )
			)
		);

		$has_secret    = ! empty( $server['claude_connector_client_secret'] );
		$secret_value  = $has_secret ? str_repeat( '•', 12 ) : '';
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); /* SEC-S2: defense in depth — esc_url is idempotent */?>">
			<?php wp_nonce_field( 'acrossai_mcp_claude_connector_' . (int) $server['id'] ); ?>
			<p class="description">
				<?php esc_html_e( 'OAuth credentials this plugin presents to Claude on behalf of this server. The Client Secret is shown as ••••• after first save — re-enter it only when you need to change it.', 'acrossai-mcp-manager' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="claude_connector_client_id"><?php esc_html_e( 'Client ID', 'acrossai-mcp-manager' ); ?></label></th>
					<td><input type="text" id="claude_connector_client_id" name="claude_connector_client_id" class="regular-text" value="<?php echo esc_attr( $server['claude_connector_client_id'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="claude_connector_client_secret"><?php esc_html_e( 'Client Secret', 'acrossai-mcp-manager' ); ?></label></th>
					<td>
						<input type="password" id="claude_connector_client_secret" name="claude_connector_client_secret" class="regular-text" value="<?php echo esc_attr( $secret_value ); ?>" autocomplete="off" />
						<?php if ( $has_secret ) : ?>
							<p class="description"><?php esc_html_e( 'A secret is currently stored. Leave the masked value unchanged to keep it.', 'acrossai-mcp-manager' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="claude_connector_redirect_uri"><?php esc_html_e( 'Redirect URI', 'acrossai-mcp-manager' ); ?></label></th>
					<td><input type="url" id="claude_connector_redirect_uri" name="claude_connector_redirect_uri" class="regular-text" value="<?php echo esc_url( $server['claude_connector_redirect_uri'] ); ?>" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Claude Connector', 'acrossai-mcp-manager' ) ); ?>
		</form>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Notice rendering — extracted to Admin\Partials\Notices per RT-2 (2026-06-17).
	// See admin/Partials/Notices.php for render_action_result_notice (FR-016) and
	// render_missing_adapter_notice + handle_adapter_notice_dismissal (FR-015 + Q3).
	// ─────────────────────────────────────────────────────────────────────────

	// ─────────────────────────────────────────────────────────────────────────
	// Submenu page callbacks (US1).
	// ─────────────────────────────────────────────────────────────────────────

	public function render_cli_auth_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ) );
		}
		$table = new CliAuthLogListTable( 0 ); // 0 = all servers
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'CLI Auth Log', 'acrossai-mcp-manager' ) . '</h1>';
		echo '<form method="get">';
		// Preserve `page` query var across pagination.
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( AdminPageSlugs::CLI_AUTH_LOG ) );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	public function render_access_control_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Access Control', 'acrossai-mcp-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'Access Control delegation lands in US3 (T038).', 'acrossai-mcp-manager' ) . '</p></div>';
	}
}
