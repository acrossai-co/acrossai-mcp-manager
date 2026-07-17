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
		$this->define( 'ACROSSAI_MCP_MANAGER_VERSION', '0.1.1' );
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
		// Feature 017 — per DEC-BERLINDB-TABLE-REQUEST-BOOT.
		\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table::instance();
		// Feature 020 — per DEC-BERLINDB-TABLE-REQUEST-BOOT. Co-commit invariant
		// with Activator's MCPServerToolTable::instance()->maybe_upgrade() —
		// omitting either produces the "Table doesn't exist" fallback bug or the
		// silent-fail-open enforcement gate (SEC-020-T-001).
		\AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table::instance();
		// Feature 021 — per DEC-BERLINDB-TABLE-REQUEST-BOOT. Three new OAuth
		// tables must materialize before any /authorize, /token, or Bearer
		// bootstrap fires. Co-commit invariant with Activator maybe_upgrade().
		\AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Table::instance();
		\AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Table::instance();
		\AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Table::instance();
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
		 * `acrossai_addons` filter — drop our own slug from the list rendered
		 * on the shared Add-ons page (bundled in acrossai-co/main-menu 0.0.22+).
		 * An already-active plugin should not appear as an installable add-on.
		 */
		$addons_filter = \AcrossAI_MCP_Manager\Admin\Partials\AddonsFilter::instance();
		$this->loader->add_filter( 'acrossai_addons', $addons_filter, 'remove_self' );

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
		$this->loader->add_filter( 'mcp_adapter_default_server_config', $mcp_controller, 'filter_default_server_config' );

		/**
		 * Feature 017 — Per-server Ability Selection.
		 *
		 * Two wiring points:
		 * 1. REST controller for the per-server abilities read/write surface,
		 *    registered on `rest_api_init` (matching the F015 access-control
		 *    REST wiring shape immediately above).
		 * 2. Call-time enforcement gate on `mcp_adapter_pre_tool_call` at
		 *    priority 20 — F015's `AcrossAI_MCP_Access_Control::gate_mcp_tool_call`
		 *    runs at priority 10, so F017's hidden-on-this-server decision
		 *    runs LATER and supersedes any F015 "allow" verdict. F017 never
		 *    overrides an F015 deny (see AbilityExposureGate::gate_tool_call_by_exposure).
		 *    Closes SEC-001 per FR-030.
		 */
		$abilities_rest = \AcrossAI_MCP_Manager\Includes\REST\AbilitiesController::instance();
		$this->loader->add_action( 'rest_api_init', $abilities_rest, 'register_routes' );

		$ability_exposure_gate = \AcrossAI_MCP_Manager\Includes\MCP\AbilityExposureGate::instance();
		$this->loader->add_filter( 'mcp_adapter_pre_tool_call', $ability_exposure_gate, 'gate_tool_call_by_exposure', 20, 4 );

		/**
		 * Feature 020 — Per-server Tool Selection.
		 *
		 * Three wiring points:
		 * 1. REST controller for the per-server tools read/write surface,
		 *    registered on `rest_api_init` (matches the F017 shape immediately above).
		 * 2. Call-time enforcement gate on `mcp_adapter_pre_tool_call` at
		 *    priority 30 — F015 access control runs at 10, F017 ability
		 *    exposure at 20, F020 tool curation at 30. Deny-precedence honored;
		 *    F020 never re-allows an already-denied ability. Closes SEC-020-001.
		 * 3. Cascade cleanup on BerlinDB's `mcp_server_deleted` action — fired
		 *    by `MCPServer\Query::delete_item()` and covers both single-row and
		 *    bulk-delete admin paths (FR-026).
		 */
		$tools_rest = \AcrossAI_MCP_Manager\Includes\REST\ToolsController::instance();
		$this->loader->add_action( 'rest_api_init', $tools_rest, 'register_routes' );

		$tool_exposure_gate = \AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate::instance();
		$this->loader->add_filter( 'mcp_adapter_pre_tool_call', $tool_exposure_gate, 'gate_tool_call_by_curation', 30, 4 );

		/**
		 * Feature — plugin-owned replacements for the three vendor default MCP
		 * abilities (`mcp-adapter/discover-abilities`, `.../get-ability-info`,
		 * `.../execute-ability`).
		 *
		 * Swaps their callbacks at registration time via `wp_register_ability_args`
		 * (WP core hook) so the abilities keep vendor's schema/label/description
		 * but run our code — which emits `acrossai_mcp_is_ability_exposed` with
		 * the current server_id.
		 *
		 * The `CurrentServerHolder` singleton is populated during
		 * `rest_pre_dispatch` (priority 5) when the incoming REST route matches a
		 * registered MCP server, and cleared during `rest_post_dispatch` /
		 * `shutdown` (priority 999). Our replacement callbacks read from it via
		 * `AbilityHelpers::apply_exposure_filter()`.
		 *
		 * Supersedes the previous vendor-abilities interceptor module (deleted
		 * in the same change — see git history for the prior post-hoc approach).
		 */
		$callback_replacer = \AcrossAI_MCP_Manager\Includes\Abilities\CallbackReplacer::instance();
		$this->loader->add_filter( 'wp_register_ability_args', $callback_replacer, 'replace_callbacks', 10, 2 );

		$current_server_holder = \AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder::instance();
		// `rest_pre_dispatch` is a filter that returns $result; priority 5 to
		// fire before any short-circuiting handlers at default 10.
		$this->loader->add_filter( 'rest_pre_dispatch', $current_server_holder, 'capture_from_request', 5, 3 );
		$this->loader->add_filter( 'rest_post_dispatch', $current_server_holder, 'clear', 999, 1 );
		// Safety net for fatal-error / early-exit paths.
		$this->loader->add_action( 'shutdown', $current_server_holder, 'clear', 999 );

		/**
		 * Feature 021 — Admin OAuth credential generator REST route.
		 *
		 * `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client` — POST from
		 * AIConnectorsTab. Capability gate (`manage_options`) + WP nonce enforced
		 * in the controller's permission callback.
		 */
		$oauth_client_registration = \AcrossAI_MCP_Manager\Includes\OAuth\ClientRegistrationController::instance();
		$this->loader->add_action( 'rest_api_init', $oauth_client_registration, 'register_routes' );

		/**
		 * F024 — Admin REST endpoints for the nested-tab AI Connectors UI:
		 * connector-settings save, revoke-client-tokens, delete-client,
		 * revoke-connector-tokens (nuclear), approve-pending-consent.
		 */
		$oauth_connector_admin = \AcrossAI_MCP_Manager\Includes\OAuth\ConnectorAdminController::instance();
		$this->loader->add_action( 'rest_api_init', $oauth_connector_admin, 'register_routes' );

		$this->loader->add_action(
			'mcp_server_deleted',
			\AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query::class,
			'on_mcp_server_deleted',
			10,
			2
		);

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

		/**
		 * Feature 021 — OAuth 2.1 rewrite-rule router + daily cleanup cron.
		 *
		 * All A1-compliant wiring — the router owns rule shape, this method
		 * owns hook registration. parse_request dispatches to Discovery /
		 * Authorization / Token controllers (Phase 5 completes those).
		 *
		 * SEC-021-T01 co-commit: the cron action wire below MUST land with
		 * the Activator's wp_schedule_event() call, so no cron-without-callback
		 * window exists between Phase 2 activation and Phase 5 controller ship.
		 */
		$oauth_router = \AcrossAI_MCP_Manager\Includes\OAuth\OAuthRouter::instance();
		$this->loader->add_action( 'init', $oauth_router, 'register_rewrite_rules' );
		$this->loader->add_filter( 'query_vars', $oauth_router, 'add_query_var' );
		$this->loader->add_action( 'parse_request', $oauth_router, 'parse_request' );

		$oauth_cleanup = \AcrossAI_MCP_Manager\Includes\OAuth\Cleanup::instance();
		$this->loader->add_action( 'acrossai_mcp_manager_oauth_cleanup', $oauth_cleanup, 'run' );

		/**
		 * Feature 021 — Bearer TokenValidator + user-deletion cascade.
		 *
		 * TokenValidator hooks `determine_current_user @ 20`. Q1 audience-binding
		 * enforced at call time — cross-server tokens rejected → anonymous
		 * (mcp-adapter denies at current_user_can). Zero DB touch when no
		 * bearer header is present (SC-011 short-circuit).
		 *
		 * UserLifecycle hooks `deleted_user @ 10`. Bulk-revokes every token
		 * for the deleted user + deletes pending auth codes + fires
		 * `token_revoked` per row (FR-042 / Q4).
		 */
		$token_validator = \AcrossAI_MCP_Manager\Includes\OAuth\TokenValidator::instance();
		$this->loader->add_filter( 'determine_current_user', $token_validator, 'authenticate', 20 );

		$user_lifecycle = \AcrossAI_MCP_Manager\Includes\OAuth\UserLifecycle::instance();
		$this->loader->add_action( 'deleted_user', $user_lifecycle, 'on_user_deleted', 10 );

		/**
		 * F024 hotfix (2026-07-11) — RFC 6750 / RFC 9728 `WWW-Authenticate`
		 * header on 401 responses from MCP routes. AI clients (Claude,
		 * ChatGPT, Cursor) rely on this header to auto-discover the OAuth
		 * server via the RFC 9728 protected-resource metadata pointer.
		 */
		$bearer_challenge = \AcrossAI_MCP_Manager\Includes\OAuth\BearerChallengeHeader::instance();
		$this->loader->add_filter( 'rest_post_dispatch', $bearer_challenge, 'add_bearer_challenge', 10, 3 );
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
