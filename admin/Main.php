<?php
namespace AcrossAI_MCP_Manager\Admin;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;


/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/admin
 * @author     WPBoilerplate <contact@wpboilerplate.com>
 */
class Main {

	/**
	 * The single instance of the class.
	 *
	 * @var Main
	 * @since 0.0.1
	 */
	protected static $_instance = null;

	/**
	 * The ID of this plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * The js_asset_file of the backend
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $js_asset_file;

	/**
	 * The css_asset_file of the backend
	 *
	 * @since    0.0.1
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $css_asset_file;

	/**
	 * Main instance.
	 *
	 * @since  0.0.1
	 * @static
	 * @return self Single instance.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    0.0.1
	 */
	private function __construct() {

		$this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
		$this->version     = ACROSSAI_MCP_MANAGER_VERSION;

		$this->js_asset_file  = include \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/backend.asset.php';
		$this->css_asset_file = include \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/css/backend.asset.php';
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    0.0.1
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in AcrossAI_MCP_Manager_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The AcrossAI_MCP_Manager_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/css/backend.css', $this->css_asset_file['dependencies'], $this->css_asset_file['version'], 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    0.0.1
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in AcrossAI_MCP_Manager_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The AcrossAI_MCP_Manager_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/backend.js', $this->js_asset_file['dependencies'], $this->js_asset_file['version'], false );
	}
}
