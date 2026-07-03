<?php
/**
 * The AccessControl per-server rule UI block.
 *
 * Feature 015 — encapsulates the per-server AccessControl rule UI so admin
 * (AccessControlTab) AND third-party plugins consume the same UI with zero
 * code duplication. Follows F013 DEC-CLIENT-RENDERER-PUBLIC-API precedent.
 *
 * UX: mounts the vendor `wpb-access-control` React component (shipped by
 * `vendor/wpboilerplate/wpb-access-control/js/AccessControl.js`). The React
 * app owns the provider dropdown, role checkboxes, and user autocomplete —
 * this PHP block only emits the mount `<div id="acrossai-mcp-ac-root">` and
 * defers to the vendor for rendering. Configuration + REST nonce are
 * localized on the enqueued script by admin/Main.php.
 *
 * Rule storage: vendor REST endpoints (`/wpb-ac/v1/mcp/rules/{ns}/{key}` +
 * `/wpb-ac/v1/mcp/users?search=…`). Enforcement lives at two sites
 * (FR-006 CliController + FR-007 mcp_adapter_pre_tool_call filter) — the
 * block is UI-only.
 *
 * Fail-open when the wpb-access-control package is unavailable (sibling
 * DEC-PERM-CB pattern): renders an info notice + returns; no mount div.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.7
 * @experimental May change without notice before 1.0.0.
 *
 * See DEC-CLIENT-RENDERER-PUBLIC-API + DEC-ACCESS-CONTROL-V2-ADOPTION.
 */

namespace AcrossAI_MCP_Manager\Public\Renderers;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * AccessControl block — mount div for the vendor React app.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.7
 * @experimental May change without notice before 1.0.0.
 */
final class AccessControlBlock extends AbstractClientRenderer {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.7
	 * @var AccessControlBlock|null
	 */
	protected static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @since 0.0.7
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
	 * @since 0.0.7
	 */
	private function __construct() {}

	/**
	 * Returns the block slug.
	 *
	 * @since 0.0.7
	 * @return string
	 */
	public function slug(): string {
		return 'access-control';
	}

	/**
	 * Renders the vendor-AccessControl React mount point.
	 *
	 * @since 0.0.7
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param array $server  Server row data.
	 * @param array $context Resolved context array (unused — React reads from wp_localize_script).
	 * @return void
	 */
	protected function render_body( array $server, array $context ): void {
		unset( $context );

		$ac = AcrossAI_MCP_Access_Control::instance();
		if ( ! $ac->is_available() ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'Access Control is inactive because the wpb-access-control library is not loaded. Tool calls pass through unrestricted.', 'acrossai-mcp-manager' )
			);
			return;
		}

		$server_slug = (string) $server['server_slug'];
		if ( '' === $server_slug ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Access Control cannot render — this server has no slug.', 'acrossai-mcp-manager' )
			);
			return;
		}

		// Mount div — the vendor's <AccessControl> React component renders inside.
		// Config + REST nonce are wp_localize_script'd by admin/Main.php on the
		// access-control tab enqueue path.
		printf(
			'<div id="acrossai-mcp-ac-root" data-server-slug="%s"><p><em>%s</em></p></div>',
			esc_attr( $server_slug ),
			esc_html__( 'Loading Access Control…', 'acrossai-mcp-manager' )
		);
	}
}
