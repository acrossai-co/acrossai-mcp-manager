<?php
/**
 * MCP tab on the shared AcrossAI Settings page.
 *
 * Registers the "MCP" tab via the `acrossai_settings_tabs` filter provided
 * by acrossai-co/main-menu, and wires the tab's sections + fields onto the
 * per-tab page slug returned by \AcrossAI_Main_Menu\SettingsPage::tab_page_slug().
 * The option group stays the shared 'acrossai-settings' so the vendor's
 * settings_fields() emit + options.php handoff resolve for every tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.1.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The MCP tab on the shared AcrossAI Settings page.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.1.0
 */
class SettingsMenu {

	/**
	 * Singleton instance.
	 *
	 * @since 0.1.0
	 * @var SettingsMenu|null
	 */
	protected static $instance = null;

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @since 0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Tab slug for this plugin's sections on the shared host Settings page.
	 *
	 * Kept in sync with \AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs::SETTINGS_TAB.
	 * Lowercase a-z0-9-_ only — sanitize_key() compliant.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TAB_SLUG = 'mcp';

	/**
	 * Registers the "MCP" tab on the shared AcrossAI Settings page.
	 *
	 * Hooked to the `acrossai_settings_tabs` filter provided by
	 * acrossai-co/main-menu.
	 *
	 * @since 0.1.0
	 * @param array $tabs Tabs collected from previous filter calls.
	 * @return array
	 */
	public function register_tab( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		$tabs[] = array(
			'slug'     => self::TAB_SLUG,
			'label'    => __( 'MCP', 'acrossai-mcp-manager' ),
			'priority' => 20,
		);

		return $tabs;
	}

	/**
	 * Registers settings sections and fields via the WordPress Settings API.
	 *
	 * Hooked to admin_init. Sections target the per-tab page slug derived from
	 * the host package's `SettingsPage::tab_page_slug()` helper — `option_group`
	 * stays the shared `'acrossai-settings'` so the form submission and nonce
	 * flow continue to resolve regardless of which tab the user is on.
	 *
	 * The vendor package acrossai-co/main-menu is a hard-require in composer.json,
	 * so the `SettingsPage` class is guaranteed present at admin_init and no
	 * class_exists() guard is needed. If that dependency is ever demoted to an
	 * optional integration, revisit DEC-VENDOR-SETTINGS-TAB-INTEGRATION and add
	 * a guard here.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_settings(): void {
		$page_slug = \AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG );

		// npm / CLI login toggle.
		register_setting(
			'acrossai-settings',
			'acrossai_mcp_npm_login_enabled',
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		// Uninstall opt-in flag.
		register_setting(
			'acrossai-settings',
			'acrossai_mcp_uninstall_delete_data',
			array(
				'sanitize_callback' => array( $this, 'sanitize_uninstall_flag' ),
				'default'           => 0,
			)
		);

		// Section: npm / CLI Settings.
		add_settings_section(
			'acrossai_mcp_npm_section',
			__( 'npm / CLI Settings', 'acrossai-mcp-manager' ),
			array( $this, 'render_npm_section_description' ),
			$page_slug
		);

		add_settings_field(
			'acrossai_mcp_npm_login_enabled',
			__( 'Enable CLI Connections', 'acrossai-mcp-manager' ),
			array( $this, 'render_npm_login_field' ),
			$page_slug,
			'acrossai_mcp_npm_section'
		);

		// Section: Uninstall Settings.
		add_settings_section(
			'acrossai_mcp_uninstall_section',
			__( 'Uninstall Settings', 'acrossai-mcp-manager' ),
			'__return_false',
			$page_slug
		);

		add_settings_field(
			'acrossai_mcp_uninstall_delete_data',
			__( 'Delete all data on uninstall', 'acrossai-mcp-manager' ),
			array( $this, 'render_uninstall_field' ),
			$page_slug,
			'acrossai_mcp_uninstall_section'
		);
	}

	/**
	 * Renders the description + warning banner for the npm / CLI section.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_npm_section_description(): void {
		$auth_url = FrontendAuth::get_base_url();

		printf(
			'<p class="description">%s</p><div class="notice notice-warning inline" style="margin:8px 0 0;"><p><strong>%s</strong> ' .
			/* translators: %s: the frontend authorization URL */
			wp_kses_post( __( 'The frontend authorization page at <code>%s</code> contains time-sensitive auth codes and nonces. If your hosting, CDN, or caching plugin caches this URL, authentication will silently fail. Exclude this path from all page-caching rules.', 'acrossai-mcp-manager' ) ) .
			'</p></div>',
			esc_html__( 'Control whether the npm / npx CLI connection flow is available on server edit pages.', 'acrossai-mcp-manager' ),
			esc_html__( 'Do not cache the CLI auth URL.', 'acrossai-mcp-manager' ),
			esc_url( $auth_url )
		);
	}

	/**
	 * Renders the npm / CLI login enable checkbox.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_npm_login_field(): void {
		$checked = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
		printf(
			'<label><input type="checkbox" id="acrossai_mcp_npm_login_enabled" name="acrossai_mcp_npm_login_enabled" value="1" %s /> %s</label><p class="description">%s</p>',
			checked( $checked, true, false ),
			esc_html__( 'Allow CLI connections via npm / npx', 'acrossai-mcp-manager' ),
			esc_html__( 'When enabled, the npm tab on each server\'s edit page will display the npx CLI command and let users connect the AcrossAI MCP Manager CLI tool to this site. Users still sign in to WordPress in the browser, then approve access so the CLI can receive a WordPress Application Password without any manual JSON editing. Keep this disabled if you do not want to expose CLI-based connections.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Sanitizes the uninstall delete data checkbox value.
	 *
	 * Returns 1 when the checkbox is checked, 0 when unchecked or absent.
	 * Browsers do not send unchecked checkboxes, so an absent value means 0.
	 *
	 * @since 0.1.0
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_uninstall_flag( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Renders the uninstall delete data checkbox field.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_uninstall_field(): void {
		$checked = (bool) get_option( 'acrossai_mcp_uninstall_delete_data', 0 );
		printf(
			'<label><input type="checkbox" id="acrossai_mcp_uninstall_delete_data" name="acrossai_mcp_uninstall_delete_data" value="1" %s /> %s</label><p class="description"><span style="color: #d63638;">%s</span></p>',
			checked( $checked, true, false ),
			esc_html__( 'Delete all data on uninstall', 'acrossai-mcp-manager' ),
			esc_html__( '⚠ Warning: When checked, uninstalling this plugin will permanently delete all custom database tables and plugin options. This cannot be undone.', 'acrossai-mcp-manager' )
		);
	}
}
