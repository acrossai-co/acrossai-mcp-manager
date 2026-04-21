<?php
/**
 * Admin Settings class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace ACROSSAI_MCP_MANAGER\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;

/**
 * Handles the admin menu, list page, and per-server edit page.
 *
 * URL routing
 * -----------
 *   ?page=acrossai_mcp_manager                        → server list (WP_List_Table)
 *   ?page=acrossai_mcp_manager&action=edit&server=ID  → tabbed edit page for one server
 *   ?page=acrossai_mcp_manager&action=toggle_status&server=ID&_wpnonce=... → toggle + redirect
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Application Passwords manager.
	 *
	 * @var ApplicationPasswords
	 */
	private $app_passwords;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->app_passwords = new ApplicationPasswords();

		add_action( 'admin_init', array( $this, 'handle_actions' ), 5 );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Show notice if the MCP adapter package is not installed.
		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_adapter_notice' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Process actions (toggle_status) before any HTML output.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['page'] ) || 'acrossai_mcp_manager' !== $_GET['page'] ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'cli_auth_approve' === $action ) {
			$this->handle_cli_auth_approve();
			return;
		}

		if ( 'toggle_status' !== $action ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ) );
		}

		$server_id   = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_key( $_GET['redirect_to'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'acrossai_mcp_toggle_' . $server_id );

		if ( $server_id > 0 ) {
			MCPServerTable::toggle_status( $server_id );
		}

		if ( 'edit' === $redirect_to ) {
			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'action'  => 'edit',
						'server'  => $server_id,
						'tab'     => $active_tab,
						'updated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
		} else {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'acrossai_mcp_manager',
						'updated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
		}

		exit;
	}

	/**
	 * Register the top-level admin menu page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' ),
			__( 'MCP Manager', 'acrossai-mcp-manager' ),
			'manage_options',
			'acrossai_mcp_manager',
			array( $this, 'render_settings_page' ),
			'dashicons-hammer',
			99
		);
	}

	/**
	 * Enqueue CSS and JS only on our admin page.
	 *
	 * Passes server_id to JS so REST calls can be server-scoped.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_acrossai_mcp_manager' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'acrossai-mcp-manager-admin',
			ACROSSAI_MCP_MANAGER_URL . 'assets/admin.css',
			array(),
			ACROSSAI_MCP_MANAGER_VERSION
		);

		wp_enqueue_script(
			'acrossai-mcp-manager-admin',
			ACROSSAI_MCP_MANAGER_URL . 'assets/admin.js',
			array( 'wp-api' ),
			ACROSSAI_MCP_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'acrossai-mcp-manager-admin',
			'acrossaiMcpManagerData',
			array(
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'rest_url'     => rest_url( 'acrossai-mcp-manager/v1/' ),
				'current_user' => wp_get_current_user(),
				'server_id'    => isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'clients'      => array_keys( $this->app_passwords->get_clients() ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Page routing
	// -------------------------------------------------------------------------

	/**
	 * Route to list or edit view based on the ?action= param.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'cli_auth' === $action ) {
			$this->render_cli_auth_page();
		} elseif ( 'cli_auth_approved' === $action ) {
			$this->render_cli_auth_approved_page();
		} elseif ( 'edit' === $action ) {
			$this->render_edit_page();
		} else {
			$this->render_list_page();
		}
	}

	// -------------------------------------------------------------------------
	// List page
	// -------------------------------------------------------------------------

	/**
	 * Render the MCP server list (WP_List_Table).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_list_page() {
		$list_table = new MCPServerListTable();
		$list_table->prepare_items();

		$updated = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'MCP Server status updated successfully.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="acrossai_mcp_manager">
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Edit page
	// -------------------------------------------------------------------------

	/**
	 * Render the tabbed edit page for a single MCP server.
	 *
	 * Loads the server row from the DB using the ?server= param.
	 * Redirects to the list if the server ID is missing or invalid.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_edit_page() {
		$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$server    = $server_id ? MCPServerTable::get_by_id( $server_id ) : null;

		if ( ! $server ) {
			wp_safe_redirect( admin_url( 'admin.php?page=acrossai_mcp_manager' ) );
			exit;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$clients    = $this->app_passwords->get_clients();
		$back_url   = admin_url( 'admin.php?page=acrossai_mcp_manager' );
		$updated    = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification

		$make_tab_url = function( $tab ) use ( $server_id ) {
			return add_query_arg(
				array(
					'page'   => 'acrossai_mcp_manager',
					'action' => 'edit',
					'server' => $server_id,
					'tab'    => $tab,
				),
				admin_url( 'admin.php' )
			);
		};
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1>
				<a href="<?php echo esc_url( $back_url ); ?>" class="acrossai-back-link">
					&#8592; <?php esc_html_e( 'MCP Servers', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php echo esc_html( $server['server_name'] ); ?>
			</h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'MCP Server status updated successfully.', 'acrossai-mcp-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $make_tab_url( 'overview' ) ); ?>"
				   class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'acrossai-mcp-manager' ); ?>
				</a>
				<?php foreach ( $clients as $client_id => $client_data ) : ?>
					<a href="<?php echo esc_url( $make_tab_url( $client_id ) ); ?>"
					   class="nav-tab <?php echo $client_id === $active_tab ? 'nav-tab-active' : ''; ?>">
						<span class="mcp-client-icon"><?php echo esc_html( $client_data['icon'] ); ?></span>
						<?php echo esc_html( $client_data['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content">
				<?php
				if ( 'overview' === $active_tab ) {
					$this->render_overview_tab( $server );
				} elseif ( isset( $clients[ $active_tab ] ) ) {
					$this->render_client_tab( $active_tab, $clients[ $active_tab ], $server_id );
				}
				?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the Overview tab for a server.
	 *
	 * Shows the server name, description, live status, and an Enable/Disable
	 * toggle that redirects back to the same tab after the action.
	 *
	 * @since 1.0.0
	 *
	 * @param array $server DB row for the server being edited.
	 *
	 * @return void
	 */
	private function render_overview_tab( array $server ) {
		$server_id  = (int) $server['id'];
		$enabled    = (bool) $server['is_enabled'];
		$nonce      = wp_create_nonce( 'acrossai_mcp_toggle_' . $server_id );
		$toggle_url = add_query_arg(
			array(
				'page'        => 'acrossai_mcp_manager',
				'action'      => 'toggle_status',
				'server'      => $server_id,
				'redirect_to' => 'edit',
				'tab'         => 'overview',
				'_wpnonce'    => $nonce,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="mcp-tab-panel">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server Name', 'acrossai-mcp-manager' ); ?></th>
					<td><strong><?php echo esc_html( $server['server_name'] ); ?></strong></td>
				</tr>
				<?php if ( $server['description'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></th>
					<td><?php echo esc_html( $server['description'] ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'acrossai-mcp-manager' ); ?></th>
					<td>
						<?php if ( $enabled ) : ?>
							<span class="acrossai-status-badge acrossai-status-active">
								<?php esc_html_e( 'Active', 'acrossai-mcp-manager' ); ?>
							</span>
							&nbsp;
							<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-small">
								<?php esc_html_e( 'Disable', 'acrossai-mcp-manager' ); ?>
							</a>
						<?php else : ?>
							<span class="acrossai-status-badge acrossai-status-inactive">
								<?php esc_html_e( 'Inactive', 'acrossai-mcp-manager' ); ?>
							</span>
							&nbsp;
							<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-primary button-small">
								<?php esc_html_e( 'Enable', 'acrossai-mcp-manager' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP API URL', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?></code></td>
				</tr>
			</table>

			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: link to profile page */
						wp_kses_post( __( 'Passwords generated in the client tabs are stored as WordPress Application Passwords. View, revoke, or manage them on your <a href="%s">profile page</a>.', 'acrossai-mcp-manager' ) ),
						esc_url( admin_url( 'profile.php' ) )
					);
					?>
				</p>
			</div>

			<h3><?php esc_html_e( 'Supported MCP Clients', 'acrossai-mcp-manager' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Click a client tab above to generate credentials and copy the ready-to-paste JSON configuration.', 'acrossai-mcp-manager' ); ?>
			</p>
			<ul class="mcp-clients-list">
				<?php foreach ( $this->app_passwords->get_clients() as $client_data ) : ?>
					<li>
						<span><?php echo esc_html( $client_data['icon'] ); ?></span>
						<strong><?php echo esc_html( $client_data['label'] ); ?></strong>
						— <?php echo esc_html( $client_data['description'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

		</div>
		<?php
	}

	/**
	 * Render a client-specific configuration tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $client_id   Client ID key (e.g. 'vscode', 'claude').
	 * @param array  $client_data Client metadata array.
	 * @param int    $server_id   DB ID of the server being edited.
	 *
	 * @return void
	 */
	private function render_client_tab( $client_id, array $client_data, $server_id ) {
		?>
		<div class="mcp-tab-panel">

			<h2>
				<span class="mcp-client-icon"><?php echo esc_html( $client_data['icon'] ); ?></span>
				<?php echo esc_html( $client_data['label'] ); ?>
			</h2>
			<p class="description"><?php echo esc_html( $client_data['description'] ); ?></p>

			<!-- Step 1: Generate password -->
			<div class="password-actions">
				<button
					type="button"
					class="button button-primary generate-app-password"
					data-client="<?php echo esc_attr( $client_id ); ?>"
					data-server="<?php echo esc_attr( $server_id ); ?>">
					<?php esc_html_e( 'Generate New Application Password', 'acrossai-mcp-manager' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Creates a one-time password via WordPress Application Passwords. Shown only once — store it safely.', 'acrossai-mcp-manager' ); ?>
				</p>
			</div>

			<!-- Step 2: Config metadata -->
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Config File', 'acrossai-mcp-manager' ); ?></th>
					<td>
						<code id="config_path_<?php echo esc_attr( $client_id ); ?>">
							<?php esc_html_e( 'Loading…', 'acrossai-mcp-manager' ); ?>
						</code>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Top-Level Key', 'acrossai-mcp-manager' ); ?></th>
					<td>
						<code id="top_level_key_<?php echo esc_attr( $client_id ); ?>">
							<?php esc_html_e( 'Loading…', 'acrossai-mcp-manager' ); ?>
						</code>
					</td>
				</tr>
			</table>

			<!-- Step 3: JSON config -->
			<div class="mcp-config-json">
				<label for="config_json_<?php echo esc_attr( $client_id ); ?>">
					<strong><?php esc_html_e( 'Configuration JSON', 'acrossai-mcp-manager' ); ?></strong>
				</label>
				<textarea
					id="config_json_<?php echo esc_attr( $client_id ); ?>"
					class="widefat code"
					rows="12"
					readonly></textarea>
				<button
					type="button"
					class="button copy-to-clipboard"
					data-field="config_json_<?php echo esc_attr( $client_id ); ?>">
					<?php esc_html_e( 'Copy Configuration', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>

			<!-- How-to reminder -->
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: MCP client label */
						esc_html__( 'Generate a password → copy the JSON → open the config file path above → paste under the top-level key → restart %s.', 'acrossai-mcp-manager' ),
						esc_html( $client_data['label'] )
					);
					?>
				</p>
			</div>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// CLI auth approval page
	// -------------------------------------------------------------------------

	/**
	 * Process the CLI auth approval form POST.
	 *
	 * Called from handle_actions() when action=cli_auth_approve.
	 * Validates the nonce, approves the auth code, and redirects.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function handle_cli_auth_approve() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ) );
		}

		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'acrossai_cli_approve_' . $code );

		\ACROSSAI_MCP_MANAGER\REST\CliController::approve_auth_code( $code, get_current_user_id() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'acrossai_mcp_manager',
					'action' => 'cli_auth_approved',
					'server' => rawurlencode( $server ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the CLI auth approval page.
	 *
	 * Shown when the user visits the auth_url produced by POST /auth/start.
	 * If they're not logged in, WordPress redirects to wp-login.php first.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_cli_auth_page() {
		$code       = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$server     = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$nonce      = wp_create_nonce( 'acrossai_cli_approve_' . $code );
		$approve_url = add_query_arg(
			array(
				'page'      => 'acrossai_mcp_manager',
				'action'    => 'cli_auth_approve',
				'code'      => $code,
				'server'    => rawurlencode( $server ),
				'_wpnonce'  => $nonce,
			),
			admin_url( 'admin.php' )
		);

		if ( empty( $code ) ) {
			wp_die( esc_html__( 'Invalid or missing auth code.', 'acrossai-mcp-manager' ) );
		}
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1><?php esc_html_e( 'MCP Manager — CLI Authorization', 'acrossai-mcp-manager' ); ?></h1>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'A CLI tool is requesting access to your MCP server.', 'acrossai-mcp-manager' ); ?></strong>
				</p>
			</div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server', 'acrossai-mcp-manager' ); ?></th>
					<td><code><?php echo esc_html( $server ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logged in as', 'acrossai-mcp-manager' ); ?></th>
					<td><strong><?php echo esc_html( wp_get_current_user()->user_login ); ?></strong></td>
				</tr>
			</table>

			<p><?php esc_html_e( 'Approving will allow the CLI tool to generate an Application Password for this site and server.', 'acrossai-mcp-manager' ); ?></p>

			<p>
				<a href="<?php echo esc_url( $approve_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Approve', 'acrossai-mcp-manager' ); ?>
				</a>
				&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=acrossai_mcp_manager' ) ); ?>" class="button">
					<?php esc_html_e( 'Cancel', 'acrossai-mcp-manager' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the confirmation page shown after a successful CLI approval.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_cli_auth_approved_page() {
		$server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap acrossai-mcp-manager-wrap">
			<h1><?php esc_html_e( 'MCP Manager — Authorization Approved', 'acrossai-mcp-manager' ); ?></h1>

			<div class="notice notice-success inline">
				<p>
					<strong><?php esc_html_e( '✅ Authorization approved!', 'acrossai-mcp-manager' ); ?></strong>
				</p>
			</div>

			<p>
				<?php
				printf(
					/* translators: %s: server slug */
					esc_html__( 'The CLI tool has been granted access to the "%s" server. You can now return to your terminal.', 'acrossai-mcp-manager' ),
					esc_html( $server )
				);
				?>
			</p>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=acrossai_mcp_manager' ) ); ?>" class="button">
					<?php esc_html_e( '← Back to MCP Manager', 'acrossai-mcp-manager' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the admin notice shown when the MCP adapter package is missing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_missing_adapter_notice() {
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				echo wp_kses_post(
					__( 'AcrossAI MCP Manager: the <code>wordpress/mcp-adapter</code> package is not installed. Please run <code>composer install</code> inside the plugin directory.', 'acrossai-mcp-manager' )
				);
				?>
			</p>
		</div>
		<?php
	}
}
