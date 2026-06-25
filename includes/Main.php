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
		$this->define( 'ACROSSAI_MCP_MANAGER_VERSION', '0.0.1' );
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
			$this->define_admin_hooks();
			$this->define_public_hooks();
		}
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

		// Phase 5 — WP-CLI command for the OAuth cleanup sweep. Only
		// registered when running under WP-CLI; guarded inside the helper.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\AcrossAI_MCP_Manager\Includes\OAuth\CliCommand::register();
		}
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
		 * Add the Plugin Main Menu (US1 — Phase 2).
		 *
		 * Menu::register_menu() registers the top-level "MCP Manager" page
		 * (slug `acrossai_mcp_manager`) + Servers / CLI Auth Log submenus +
		 * the Access Control submenu (only when the vendor pkg is present).
		 *
		 * plugin_action_links_<basename> is the row-specific filter — fires
		 * only for this plugin's row on the Plugins screen (FR-003).
		 */
		$menu = \AcrossAI_MCP_Manager\Admin\Partials\Menu::instance();
		$this->loader->add_action( 'admin_menu', $menu, 'register_menu' );
		$this->loader->add_filter(
			'plugin_action_links_' . ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME,
			$menu,
			'plugin_action_links',
			10,
			1
		);

		/**
		 * Settings handler hooks (US2 — Phase 2).
		 * - handle_actions runs at priority 5 on admin_init so it fires
		 *   BEFORE other admin_init handlers; it dispatches toggle/delete/
		 *   create/bulk via `?page=acrossai_mcp_manager&action=...`
		 * - register_settings is a no-op stub until US3 T020 ports the
		 *   Settings API registration
		 * - render_action_result_notice consumes the `?notice=...` query var
		 *   set by handle_actions redirects (FR-016)
		 */
		$settings = \AcrossAI_MCP_Manager\Admin\Partials\Settings::instance();
		$this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );
		$this->loader->add_action( 'admin_init', $settings, 'register_settings' );

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

		/**
		 * Access Control vendor wiring (US3 — Phase 2), guarded so the
		 * plugin degrades gracefully when wpb-access-control isn't installed.
		 * (Per D8 + the Phase 1 Activator's class_exists pattern.)
		 *
		 * NOTE: This block intentionally only mirrors the *render* contract —
		 * the rest_pre_dispatch access-enforcement filter is owned by Phase 7
		 * and remains a TODO below.
		 */
		if ( class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
			// The render method lives on the vendor singleton; Settings::
			// render_access_control_tab() resolves it at use-site (no Loader
			// wiring needed for tab rendering itself). This block exists for
			// any future hooks the vendor pkg may require — currently empty.
		}

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

		// TODO (phase 4): wire Includes\MCP\Controller.
		// $mcp_controller = \AcrossAI_MCP_Manager\Includes\MCP\Controller::instance();
		// $this->loader->add_action( 'rest_api_init', $mcp_controller, 'initialize_adapter' );

		// TODO (phase 5): wire REST\CliController.
		// $cli_controller = \AcrossAI_MCP_Manager\Includes\REST\CliController::instance();
		// $this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );

		// TODO (phase 6): wire Includes\OAuth\ClaudeConnectors (10 hooks).
		// $claude_connectors = \AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors::instance();
		// $this->loader->add_action( 'init', $claude_connectors, 'register_rewrite_rules' );
		// $this->loader->add_action( 'init', $claude_connectors, 'maybe_flush_rewrite_rules', 20 );
		// $this->loader->add_filter( 'query_vars', $claude_connectors, 'add_query_vars' );
		// $this->loader->add_filter( 'redirect_canonical', $claude_connectors, 'disable_canonical_redirects', 10, 2 );
		// $this->loader->add_action( 'wp_enqueue_scripts', $claude_connectors, 'enqueue_assets' );
		// $this->loader->add_action( 'template_redirect', $claude_connectors, 'handle_frontend_request' );
		// $this->loader->add_action( 'rest_api_init', $claude_connectors, 'register_rest_routes' );
		// $this->loader->add_filter( 'determine_current_user', $claude_connectors, 'determine_current_user_from_bearer', 20 );
		// $this->loader->add_filter( 'rest_post_dispatch', $claude_connectors, 'decorate_mcp_response', 10, 3 );
		// $this->loader->add_action( 'acrossai_mcp_access_denied', $claude_connectors, 'log_access_denied_event', 10, 4 );

		// TODO (phase 7): wire rest_pre_dispatch access-control filter.
		// $access_control = \WPBoilerplate\AccessControl\AccessControlManager::instance( 'acrossai_mcp_access_control_providers' );
		// $this->loader->add_filter( 'rest_pre_dispatch', $access_control, 'enforce_access' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = \AcrossAI_MCP_Manager\Public\Main::instance();

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

		/**
		 * Phase 5 — OAuth / Claude Connectors wiring (FR-021 / A1).
		 *
		 * Every OAuth hook trace MUST be in this method — feature classes
		 * never call add_action / add_filter themselves.
		 */
		$claude_connectors = \AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors::instance();
		$this->loader->add_action( 'init', $claude_connectors, 'register_rewrite_rules' );
		$this->loader->add_filter( 'query_vars', $claude_connectors, 'add_query_var' );
		$this->loader->add_action( 'template_redirect', $claude_connectors, 'serve_discovery_or_authorize', 9 );
		$this->loader->add_action( 'acrossai_mcp_oauth_cleanup', $claude_connectors, 'handle_cleanup_event' );

		$token_controller = \AcrossAI_MCP_Manager\Includes\OAuth\TokenController::instance();
		$this->loader->add_action( 'rest_api_init', $token_controller, 'register_routes' );

		$bearer_auth = \AcrossAI_MCP_Manager\Includes\OAuth\BearerAuth::instance();
		$this->loader->add_filter( 'determine_current_user', $bearer_auth, 'resolve_bearer_token', 20 );

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
