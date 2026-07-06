<?php
/**
 * Singleton dispatch + ordered tab list for per-server-edit tabs.
 *
 * Feature 013 — every concrete tab class under admin/Partials/ServerTabs/
 * is registered here. Settings::render_edit_page() calls
 * Registry::instance()->render( $tab_slug, $server ) to dispatch to the
 * correct tab body.
 *
 * Singleton member ordering matches F012 SettingsMenu (DEC-VENDOR-SETTINGS-
 * TAB-INTEGRATION): protected static $instance → public static instance() →
 * private __construct().
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registry — dispatches per-server-edit tab renders.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class Registry {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.6
	 * @var Registry|null
	 */
	protected static $instance = null;

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @since 0.0.6
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
	 * @since 0.0.6
	 */
	private function __construct() {}

	/**
	 * Returns the ordered list of tab instances.
	 *
	 * Post-Feature 016 the tab count is 10.
	 *
	 * @since 0.0.6
	 * @return AbstractServerTab[]
	 */
	public function all_tabs(): array {
		return array(
			new OverviewTab(),
			new NpmTab(),
			new ClientsTab(),
			new WpCliTab(),
			new ToolsTab(),
			new AbilitiesTab(),
			new AccessControlTab(),
			new McpTrackerTab(),
			new UpdateServerTab(),
			new DangerZoneTab(),
		);
	}

	/**
	 * Returns tabs filtered by their visible_for() check for the given server.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return AbstractServerTab[]
	 */
	public function visible_tabs( array $server ): array {
		return array_values(
			array_filter(
				$this->all_tabs(),
				static function ( AbstractServerTab $t ) use ( $server ): bool {
					return $t->visible_for( $server );
				}
			)
		);
	}

	/**
	 * Dispatches on tab slug; renders the matching tab. Unknown slug falls
	 * back to the first tab in all_tabs() (OverviewTab post-T012).
	 *
	 * @since 0.0.6
	 * @param string $tab_slug Requested tab slug.
	 * @param array  $server   Server row data.
	 * @return void
	 */
	public function render( string $tab_slug, array $server ): void {
		$tabs = $this->all_tabs();
		foreach ( $tabs as $tab ) {
			if ( $tab->slug() === $tab_slug ) {
				$tab->render( $server );
				return;
			}
		}
		// Fallback — render the first tab in the ordered list.
		if ( ! empty( $tabs ) ) {
			$tabs[0]->render( $server );
		}
	}
}
