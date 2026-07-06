<?php
namespace AcrossAI_MCP_Manager\Includes;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      0.0.1
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 * @author     WPBoilerplate <contact@wpboilerplate.com>
 */
final class Main {

	/**
	 * The single instance of the class.
	 *
	 * @var AcrossAI_MCP_Manager
	 * @since 0.0.1
	 */
	protected static $_instance = null;

	/**
	 * The autoloader instance.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      Autoloader    $autoloader    The plugin autoloader instance.
	 */
	protected $autoloader;

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      AcrossAI_MCP_Manager_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The plugin dir path
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $plugin_path    The string for plugin dir path
	 */
	protected $plugin_path;

	/**
	 * The current version of the plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	protected $plugin_dir;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    0.0.1
	 */
	public function __construct() {

		$this->define_constants();
		$this->version = ACROSSAI_MCP_MANAGER_VERSION;

		$this->plugin_name = 'acrossai-mcp-manager';
		$this->plugin_dir  = ACROSSAI_MCP_MANAGER_PLUGIN_PATH;

		$this->load_composer_dependencies();

		$this->load_dependencies();

		$this->set_locale();

		$this->load_hooks();
	}

	/**
	 * Main AcrossAI_MCP_Manager Instance.
	 *
	 * Ensures only one instance of WooCommerce is loaded or can be loaded.
	 *
	 * @since 0.0.1
	 * @static
	 * @see AcrossAI_MCP_Manager()
	 * @return AcrossAI_MCP_Manager - Main instance.
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Define WCE Constants
	 */
	private function define_constants() {

		$this->define( 'ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME', plugin_basename( \ACROSSAI_MCP_MANAGER_PLUGIN_FILE ) );
		$this->define( 'ACROSSAI_MCP_MANAGER_PLUGIN_PATH', plugin_dir_path( \ACROSSAI_MCP_MANAGER_PLUGIN_FILE ) );
		$this->define( 'ACROSSAI_MCP_MANAGER_PLUGIN_URL', plugin_dir_url( \ACROSSAI_MCP_MANAGER_PLUGIN_FILE ) );
		$this->define( 'ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG', 'acrossai-mcp-manager' );
		$this->define( 'ACROSSAI_MCP_MANAGER_PLUGIN_NAME', 'AcrossAI MCP Manager' );
		$this->define( 'ACROSSAI_MCP_MANAGER_VERSION', '0.0.9' );
	}

	/**
	 * Define constant if not already set
	 *
	 * @param  string      $name
	 * @param  string|bool $value
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Register all the hook once all the active plugins are loaded
	 *
	 * Uses the plugins_loaded to load all the hooks and filters
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	public function load_hooks() {

		/**
		 * Check if plugin can be loaded safely or not
		 *
		 * @since    0.0.1
		 */
		if ( apply_filters( 'acrossai_mcp_manager_load', true ) ) {
			$this->bootstrap_database_tables();
			$this->define_admin_hooks();
			$this->define_public_hooks();
		}
	}

	/**
	 * Instantiate the four BerlinDB Table subclasses at request time.
	 *
	 * BerlinDB v3 requires the Table subclass to be instantiated so its
	 * `sunrise()` / `set_prefixes()` boot registers the physical table
	 * name with the DB interface. Without this, Query subclasses fall
	 * back to using $table_alias as the FROM clause, producing
	 * `Table 'db.<alias>' doesn't exist` errors at runtime.
	 *
	 * Matches the sibling plugin's boot pattern
	 * (acrossai-abilities-manager Main::define_admin_hooks:349) but is
	 * hoisted here so it also covers public/REST request paths
	 * (MCP\Controller::has_any_enabled_server hits Query on rest_api_init).
	 *
	 * @since 0.0.1
	 * @return void
	 */
	private function bootstrap_database_tables() {
		\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table::instance();
		\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table::instance();
	}

	/**
	 * Load the required composer dependencies for this plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function load_composer_dependencies() {

		/**
		 * Add composer file
		 */
		$plugin_path = ACROSSAI_MCP_MANAGER_PLUGIN_PATH;

		if ( file_exists( $plugin_path . 'vendor/autoload_packages.php' ) ) {
			require_once $plugin_path . 'vendor/autoload_packages.php';
		}
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - AcrossAI_MCP_Manager\Admin\Loader. Orchestrates the hooks of the plugin.
	 * - AcrossAI_MCP_Manager\Admin\I18n. Defines internationalization functionality.
	 * - AcrossAI_MCP_Manager\Admin\Main. Defines all hooks for the admin area.
	 * - AcrossAI_MCP_Manager_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function load_dependencies() {

		$this->loader = Loader::instance();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the AcrossAI_MCP_Manager_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function set_locale() {
		$i18n = new I18n();

		// Now attach it to `init`, not `plugins_loaded`
		$this->loader->add_action( 'init', $i18n, 'do_load_textdomain' );
	}


	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = \AcrossAI_MCP_Manager\Admin\Main::instance();

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		/**
		 * Add the Plugin Submenus under the shared `acrossai` parent (Feature 010 — FR-021).
		 *
		 * Menu::register_submenu() registers MCP Manager (position 2), CLI Auth Log
		 * (position 3), and Access Control (position 4, conditional). The shared
		 * `acrossai` parent menu itself is auto-hooked internally by
		 * \AcrossAI_Main_Menu\SettingsPage (bootstrapped from the plugin entry file
		 * on plugins_loaded priority 0 per FR-029 / D15 / DEV4).
		 *
		 * plugin_action_links_<basename> is the row-specific filter — fires
		 * only for this plugin's row on the Plugins screen (FR-003).
		 */
		$menu = \AcrossAI_MCP_Manager\Admin\Partials\Menu::instance();
		$this->loader->add_action( 'admin_menu', $menu, 'register_submenu' );
		$this->loader->add_filter(
			'plugin_action_links_' . ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME,
			$menu,
			'plugin_action_links',
			10,
			1
		);

		/**
		 * Settings action handler hooks (US2 — Phase 2).
		 *
		 * `Settings` owns the list-page controller for MCP servers (toggle,
		 * delete, create, bulk actions dispatched via
		 * `?page=acrossai_mcp_manager&action=...`). It is deliberately kept
		 * separate from `SettingsMenu` below, which owns the WordPress
		 * Settings API surface on the shared `?page=acrossai-settings` page.
		 *
		 * - handle_actions runs at priority 5 on admin_init so it fires
		 *   BEFORE other admin_init handlers.
		 * - render_action_result_notice consumes the `?notice=...` query var
		 *   set by handle_actions redirects (FR-016).
		 */
		$settings = \AcrossAI_MCP_Manager\Admin\Partials\Settings::instance();
		// Auto-heal the default MCP server row (reference-plugin pattern).
		// Runs at priority 4 so the row exists before handle_actions/list-render.
		$this->loader->add_action( 'admin_init', $settings, 'maybe_seed_default_server', 4 );
		$this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );

		/**
		 * Shared AcrossAI Settings page — MCP tab (Feature 012).
		 *
		 * Registers the MCP tab on the vendor-owned `?page=acrossai-settings`
		 * page via the `acrossai_settings_tabs` filter, and wires the tab's
		 * three sections + toggles via the Settings API on admin_init. The
		 * vendor's `SettingsPage` is guaranteed present at admin_init because
		 * the acrossai-co/main-menu package is a hard-require in composer.json
		 * (Feature 010 — D15 / DEV4). See DEC-VENDOR-SETTINGS-TAB-INTEGRATION.
		 *
		 * Loader-order note: this wiring lands after the Feature 011
		 * `bootstrap_database_tables()` call in load_hooks() (per
		 * DEC-BERLINDB-TABLE-REQUEST-BOOT), so BerlinDB Tables are booted
		 * before any admin_init handler runs.
		 */
		$settings_menu = \AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu::instance();
		$this->loader->add_filter( 'acrossai_settings_tabs', $settings_menu, 'register_tab' );
		$this->loader->add_action( 'admin_init', $settings_menu, 'register_settings' );

		/**
		 * Admin notices — extracted to Admin\Partials\Notices per RT-2.
		 * - render_action_result_notice consumes the `?notice=...` query var set by
		 *   Settings::handle_actions() redirects (FR-016)
		 * - render_missing_adapter_notice + handle_adapter_notice_dismissal
		 *   together implement the dismissible adapter-missing warning (FR-015 + Q3)
		 */
		$notices = \AcrossAI_MCP_Manager\Admin\Partials\Notices::instance();
		$this->loader->add_action( 'admin_notices', $notices, 'render_action_result_notice' );

		/**
		 * ApplicationPasswords REST routes (US3 — Phase 2).
		 * Registers POST /generate-app-password + GET /list-app-passwords
		 * on the rest_api_init action. The Tokens tab JS calls these via
		 * wp.apiFetch with the per-user `wp_rest` nonce.
		 */
		$application_passwords = \AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords::instance();
		$this->loader->add_action( 'rest_api_init', $application_passwords, 'register_rest_routes' );

		// Feature 015 — Access Control v2 adoption. Replaces the previous Phase 7
		// TODO block that referenced v1's ::instance() static (fatal in v2). All
		// wiring flows through the plugin-scoped wrapper; the mcp_adapter_pre_tool_call
		// filter (D18) is the canonical MCP-boundary enforcement hook.
		// NB: `register_default_providers` filter is intentionally NOT wired —
		// the vendor's AccessControlManager::load_providers() already registers
		// WpRoleProvider + WpUserProvider + WpCapabilityProvider + BuddyBoss +
		// MemberPress as defaults. Third-party plugins can still hook the
		// `acrossai_mcp_access_control_providers` filter to append their own.
		$access_control = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
		$this->loader->add_action( 'init', $access_control, 'boot_manager', 5 );
		$this->loader->add_action( 'rest_api_init', $access_control, 'register_rest_api' );
		$this->loader->add_action( 'admin_notices', $access_control, 'maybe_show_library_notice' );
		$this->loader->add_filter( 'mcp_adapter_pre_tool_call', $access_control, 'gate_mcp_tool_call', 10, 4 );

		/**
		 * Adapter-missing notice (US4 — FR-015 + Q3). Lives on Notices per RT-2.
		 * - render_missing_adapter_notice runs unconditionally on admin_notices;
		 *   the renderer self-guards on class_exists('\WP\MCP\Plugin') AND on the
		 *   per-user user_meta dismissal flag (sticky, never resets on upgrade).
		 * - handle_adapter_notice_dismissal is the admin-ajax endpoint called
		 *   when the user clicks the X button. Nonce + manage_options gated.
		 */
		$this->loader->add_action( 'admin_notices', $notices, 'render_missing_adapter_notice' );
		$this->loader->add_action( 'admin_notices', $notices, 'render_oauth_https_notice' );
		$this->loader->add_action( 'admin_notices', $notices, 'render_disable_wp_cron_notice' );
		$this->loader->add_action(
			'wp_ajax_acrossai_mcp_dismiss_adapter_notice',
			$notices,
			'handle_adapter_notice_dismissal'
		);

		// TODO (phase N): wire Admin\Partials\ApplicationPasswords.
		// $app_passwords = \AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords::instance();

		/**
		 * Phase 4 gap closure — Feature-009 (2026-07-01). Boots the WP MCP
		 * adapter singleton when at least one enabled MCP server row exists.
		 * v0.0.4 wired this on `init` priority 1; the target design defers to
		 * `rest_api_init` so `Plugin::instance()`'s internal REST route
		 * registration lands during WordPress's REST bootstrap window.
		 * Graceful when adapter package is absent (US3 — sets status
		 * 'not-found'; `Notices::render_missing_adapter_notice()` handles the
		 * admin banner separately).
		 */
		$mcp_controller = \AcrossAI_MCP_Manager\Includes\MCP\Controller::instance();
		$this->loader->add_action( 'rest_api_init', $mcp_controller, 'initialize_adapter' );

		// TODO (phase 5): wire REST\CliController.
		// $cli_controller = \AcrossAI_MCP_Manager\Includes\REST\CliController::instance();
		// $this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function define_public_hooks() {

		/**
		 * Phase 6 — REST CLI Authentication Controller + Phase 6.0 FrontendAuth.
		 *
		 * Every CLI-flow hook trace MUST be in this method — feature classes
		 * never call add_action / add_filter themselves (A1 / FR-021).
		 */
		$frontend_auth = \AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::instance();
		$this->loader->add_action( 'init', $frontend_auth, 'register_rewrite_rule' );
		$this->loader->add_filter( 'query_vars', $frontend_auth, 'add_query_var' );
		$this->loader->add_action( 'template_redirect', $frontend_auth, 'maybe_render_page', 10 );
		$this->loader->add_action( 'wp_enqueue_scripts', $frontend_auth, 'enqueue_assets' );

		$cli_controller = \AcrossAI_MCP_Manager\Includes\REST\CliController::instance();
		$this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );

		/**
		 * Feature 013 — Public Renderer layer.
		 *
		 * Wires the REST endpoint + 2 shortcodes for the public client-config
		 * renderers (NpmClientBlock, MCPClientsBlock).
		 * Third-party plugins (BuddyBoss, WooCommerce, etc.) consume via:
		 *   - do_action( 'acrossai_mcp_render_client_block', $slug, $server_id, $context )
		 *   - apply_filters( 'acrossai_mcp_client_block_context', $context, $slug, $server_id )
		 *   - apply_filters( 'acrossai_mcp_client_classes', $default_fqns )
		 *   - Shortcodes: [acrossai_mcp_npm_block server=X], etc.
		 * All @experimental until 1.0.0 per DEC-CLIENT-RENDERER-PUBLIC-API.
		 */
		$client_renderer_rest = \AcrossAI_MCP_Manager\Includes\REST\ClientRendererController::instance();
		$this->loader->add_action( 'rest_api_init', $client_renderer_rest, 'register_rest_routes' );

		$this->loader->add_action( 'init', $client_renderer_rest, 'register_shortcodes_and_actions' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    0.0.1
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     0.0.1
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     0.0.1
	 * @return    AcrossAI_MCP_Manager_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * The reference to the autoloader instance.
	 *
	 * @since     0.0.1
	 * @return    Autoloader    The plugin autoloader instance.
	 */
	public function get_autoloader() {
		return $this->autoloader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     0.0.1
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
