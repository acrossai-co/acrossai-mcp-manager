<?php
namespace AcrossAI_MCP_Manager\Admin\Partials;

/**
 * AcrossAI_MCP_Manager_Main_Menu Main Menu Class.
 *
 * @since AcrossAI_MCP_Manager_Main_Menu 0.0.1
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


/**
 * Fired during plugin licences.
 *
 * This class defines all code necessary to run during the plugin's licences and update.
 *
 * @since      0.0.1
 * @package    AcrossAI_MCP_Manager\Admin\Partials\Menu
 * @subpackage AcrossAI_MCP_Manager\Admin\Partials
 */
class Menu {

	/**
	 * The single instance of the class.
	 *
	 * @var Menu
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
	}

	/**
	 * Adds the plugin license page to the admin menu.
	 *
	 * @return void
	 */
	public function main_menu() {
		add_menu_page(
			__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' ),
			__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' ),
			'manage_options',
			'acrossai-mcp-manager',
			array( $this, 'about' )
		);
	}

	/**
	 * About us for the plugins
	 */
	public function about() {
		?>
		<div class="acrossai-mcp-manager-container">
			<div class="acrossai-mcp-manager-content">
				<h2>AcrossAI MCP Manager</h2>
			</div>
		</div>
		<?php
	}

	/**
	 * Add Settings link to plugins area.
	 *
	 * @since    0.0.1
	 *
	 * @param array  $links Links array in which we would prepend our link.
	 * @param string $file  Current plugin basename.
	 * @return array Processed links.
	 */
	public function plugin_action_links( $links, $file ) {

		// Return normal links if not BuddyPress.
		if ( \ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		// Add a few links to the existing links array.
		return array_merge(
			$links,
			array(
				'about' => sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=acrossai-mcp-manager' ) ), esc_html__( 'About', 'acrossai-mcp-manager' ) ),
			)
		);
	}
}
