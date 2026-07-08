<?php
/**
 * Abstract base class for per-server-edit tab classes.
 *
 * Feature 013 refactors the monolithic render_*_tab methods on
 * admin/Partials/Settings.php into per-tab class hierarchy. Every concrete
 * tab under admin/Partials/ServerTabs/ extends this class.
 *
 * Template method pattern: subclasses implement slug(), label(), and
 * render_body(); the final render() method is the public entry point.
 * Shared helpers (open_form, nonce_field, json_config_block, etc.) live
 * here so tab bodies do not duplicate form/nonce/HTML boilerplate.
 *
 * Note per BUGS.md B16: any printf/sprintf inside a helper here MUST use
 * ONE placeholder style (all positional %s OR all numbered %1$s) — do not
 * mix within a single call.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for per-server-edit tabs.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
abstract class AbstractServerTab {

	/**
	 * The tab's URL slug (e.g. 'overview', 'npm', 'clients').
	 *
	 * @since 0.0.6
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * The tab's operator-visible label (already i18n'd).
	 *
	 * @since 0.0.6
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Whether this tab is visible for the given server.
	 *
	 * Override to gate tabs on server metadata (e.g. Update Server + Danger
	 * Zone tabs only render when $server['registered_from'] === 'database').
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return bool
	 */
	public function visible_for( array $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Subclasses override and use $server.
		return true;
	}

	/**
	 * Sort key for the effective tab list.
	 *
	 * Feature 019 introduces the `acrossai_mcp_manager_server_tabs` filter and
	 * a stable priority-based sort in `Registry::for_server()`. Built-in tabs
	 * override this to their fixed slot (Overview = 10 … Danger Zone = 100 in
	 * 10-step increments). Third-party entries from the filter default to 100
	 * when omitted — matching Danger Zone's slot — so an unmarked third-party
	 * tab lands adjacent to the last built-in with insertion order breaking
	 * the tie.
	 *
	 * @since 0.0.7
	 * @return int Lower values render first in the nav bar.
	 */
	public function priority(): int {
		return 100;
	}

	/**
	 * Public entry point — called by Registry::render().
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	final public function render( array $server ): void {
		$this->render_body( $server );
	}

	/**
	 * Renders the tab's body. Subclasses implement this.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	abstract protected function render_body( array $server ): void;

	/**
	 * Emits the opening <form> tag for a per-server admin form.
	 *
	 * Callers requiring a distinct target URL (e.g. the DB-only Update Server
	 * / Danger Zone tabs, whose Settings.php handlers expect the action in the
	 * query string) may pass $target_url_override. When omitted, the form
	 * posts to `admin.php?page=<parent>` with the action + server id emitted
	 * as hidden inputs — the shared-tab default.
	 *
	 * @since 0.0.6
	 * @param array  $server              Server row data.
	 * @param string $action              Action query var value (e.g. 'save_general').
	 * @param string $target_url_override Optional pre-built form action URL. Empty = default.
	 * @return void
	 */
	protected function open_form( array $server, string $action, string $target_url_override = '' ): void {
		$post_url = '' !== $target_url_override
			? $target_url_override
			: admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT );
		printf(
			'<form method="post" action="%s">',
			esc_url( $post_url )
		);

		// When the caller supplied its own URL, the action + server are already
		// in the query string — skip the hidden inputs to avoid duplication.
		if ( '' !== $target_url_override ) {
			return;
		}

		printf(
			'<input type="hidden" name="page" value="%s" />',
			esc_attr( AdminPageSlugs::PARENT )
		);
		printf(
			'<input type="hidden" name="action" value="%s" />',
			esc_attr( $action )
		);
		printf(
			'<input type="hidden" name="server" value="%s" />',
			esc_attr( (string) (int) $server['id'] )
		);
	}

	/**
	 * Emits the per-server nonce field.
	 *
	 * Default action name is `'acrossai_mcp_manager_server_' . (int) $server['id']`
	 * — matches the standard save handlers wired by Settings.php.
	 *
	 * Callers requiring a distinct action (Update Server tab uses
	 * `'acrossai_mcp_update_' . $id`; Danger Zone uses
	 * `'acrossai_mcp_delete_' . $id` per the existing Settings.php handlers
	 * at lines 124 + 131) pass $custom_action_override. Both actions still
	 * bind (int) $server['id'] for per-server isolation.
	 *
	 * @since 0.0.6
	 * @param array  $server                 Server row data.
	 * @param string $custom_action_override Optional nonce action name. Empty = default.
	 * @return void
	 */
	protected function nonce_field( array $server, string $custom_action_override = '' ): void {
		$action = '' !== $custom_action_override
			? $custom_action_override
			: 'acrossai_mcp_manager_server_' . (int) $server['id'];
		wp_nonce_field( $action, '_wpnonce', true, true );
	}

	/**
	 * Emits the closing </form> tag with a submit button.
	 *
	 * @since 0.0.6
	 * @param string $submit_label Submit button label (already i18n'd).
	 * @return void
	 */
	protected function close_form( string $submit_label ): void {
		submit_button( $submit_label );
		echo '</form>';
	}

	/**
	 * Renders a Configuration JSON <pre> block for an MCP client config.
	 *
	 * @since 0.0.6
	 * @param array  $server      Server row data.
	 * @param string $client_slug Client slug (e.g. 'claude-desktop').
	 * @param array  $config      JSON-encodable client config array.
	 * @return void
	 */
	protected function json_config_block( array $server, string $client_slug, array $config ): void {
		$json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		printf(
			'<pre id="acrossai-mcp-%1$s-config-%2$s"><code>%3$s</code></pre>',
			esc_attr( sanitize_key( $client_slug ) ),
			esc_attr( (string) (int) $server['id'] ),
			esc_html( (string) $json )
		);
	}

	/**
	 * Renders the reusable notice about WordPress Application Passwords.
	 *
	 * @since 0.0.6
	 * @return void
	 */
	protected function passwords_notice(): void {
		printf(
			'<p class="description">%s <a href="%s">%s</a></p>',
			esc_html__( 'Passwords generated in the client tabs are stored as WordPress Application Passwords. View, revoke, or manage them on your', 'acrossai-mcp-manager' ),
			esc_url( admin_url( 'profile.php#application-passwords-section' ) ),
			esc_html__( 'profile page', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Builds the URL for a specific tab on this server's edit page.
	 *
	 * Returns a RAW URL — callers MUST escape at the point of output
	 * (esc_url() when the URL is embedded in HTML, or pass unescaped to
	 * add_query_arg() for further query-arg manipulation). Escaping here
	 * would cause double-escape (`&` → `&#038;` → `&amp;#038;`) when the
	 * URL is threaded through `add_query_arg()` downstream, breaking sub-nav
	 * links inside MCPClientsBlock.
	 *
	 * S5/B6 defense: URL components are constructed from constants +
	 * sanitize_key() + (int) cast, so no user-controlled data reaches the
	 * URL. Callers still MUST esc_url() at the output boundary.
	 *
	 * @since 0.0.6
	 * @param array  $server Server row data.
	 * @param string $tab    Tab slug (e.g. 'overview', 'npm').
	 * @return string Raw URL — call esc_url() at the output boundary.
	 */
	protected function server_edit_url( array $server, string $tab ): string {
		return add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'edit',
				'server' => (int) $server['id'],
				'tab'    => sanitize_key( $tab ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders a client label + vendor pair line (used by NpmTab, ClientsTab).
	 *
	 * @since 0.0.6
	 * @param string $client_name Client display name.
	 * @param string $vendor      Vendor/description line.
	 * @return void
	 */
	protected function client_label_pair( string $client_name, string $vendor ): void {
		printf(
			'<p><strong>%1$s</strong> — %2$s</p>',
			esc_html( $client_name ),
			esc_html( $vendor )
		);
	}
}
