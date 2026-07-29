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

	// ============================================================
	// Declarative body — the "extend + fields, get a rendered tab
	// for free" surface (D35 self-contained-subsystem pattern).
	// ============================================================

	/**
	 * Renders the tab's body. Concrete default = declarative dispatch to the
	 * `get_*` overrides below. Non-`final` on purpose: subclasses whose body
	 * doesn't fit the standard scaffold (form-heavy tabs like NpmTab /
	 * DangerZoneTab / Overview) can still override this method directly and
	 * emit whatever HTML they need. React-mount tabs (Embeds / Abilities /
	 * Tools / AI Connectors) should prefer the declarative getters below.
	 *
	 * Standard scaffold order:
	 *
	 *   <div class="mcp-tab-panel">
	 *     <h2>{@see get_heading()}</h2>
	 *     <p class="description">{@see get_description()}</p>
	 *     [optional] {@see render_disabled_server_notice()} when
	 *                 {@see requires_enabled_server()} && ! $server['is_enabled']
	 *     {@see render_content($server)}           ← default renders React mount
	 *     {@see render_noscript_fallback($server)} ← default renders nothing
	 *   </div>
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		echo '<div class="mcp-tab-panel">';
		$this->render_heading();
		$this->render_description();
		if ( $this->requires_enabled_server() && empty( $server['is_enabled'] ) ) {
			$this->render_disabled_server_notice();
		}
		$this->render_content( $server );
		$this->render_noscript_fallback( $server );
		echo '</div>';
	}

	/**
	 * Tab-body heading (rendered as `<h2>`). Defaults to `label()` so a
	 * subclass that only declares slug/label gets a sensible heading for free.
	 * Override when the tab title in the nav should differ from the body
	 * heading (e.g. label "Embeds" vs heading "Frontend Embeds").
	 *
	 * @since 0.1.11
	 * @return string Translated heading; empty string suppresses the `<h2>`.
	 */
	public function get_heading(): string {
		return $this->label();
	}

	/**
	 * Optional one-liner description shown under the heading. Empty string
	 * suppresses the `<p class="description">`.
	 *
	 * @since 0.1.11
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * DOM id of the React mount div. Empty string = no mount emitted (the
	 * default; only React-mount tabs override).
	 *
	 * @since 0.1.11
	 * @return string
	 */
	public function get_react_root_id(): string {
		return '';
	}

	/**
	 * Data attributes to emit on the React mount `<div>`. Keys are the
	 * attribute *suffix* — e.g. returning `['server-id' => 42]` emits
	 * `data-server-id="42"`. Default: empty array (no data attrs; React
	 * reads state from `wp_localize_script` bootstrap payload instead).
	 *
	 * @since 0.1.11
	 * @param array $server Server row data.
	 * @return array<string, string|int>
	 */
	public function get_react_root_data( array $server ): array {
		unset( $server ); // Subclasses override to derive from $server.
		return array();
	}

	/**
	 * Text shown inside the React mount before the app hydrates. Empty
	 * string = no placeholder (mount `<div>` renders empty until React
	 * takes over).
	 *
	 * @since 0.1.11
	 * @return string
	 */
	public function get_loading_placeholder(): string {
		return '';
	}

	/**
	 * When true and `! $server['is_enabled']`, the base emits a "server
	 * disabled" warning notice above the content. Default false — enable
	 * only for tabs whose contents depend on the server being on.
	 *
	 * @since 0.1.11
	 * @return bool
	 */
	public function requires_enabled_server(): bool {
		return false;
	}

	/**
	 * Emits the standard `<h2>` heading. Override
	 * {@see get_heading()} to change the text.
	 *
	 * @since 0.1.11
	 * @return void
	 */
	protected function render_heading(): void {
		$heading = $this->get_heading();
		if ( '' === $heading ) {
			return;
		}
		printf( '<h2>%s</h2>', esc_html( $heading ) );
	}

	/**
	 * Emits the standard `<p class="description">`. Override
	 * {@see get_description()} to change the text.
	 *
	 * @since 0.1.11
	 * @return void
	 */
	protected function render_description(): void {
		$description = $this->get_description();
		if ( '' === $description ) {
			return;
		}
		printf( '<p class="description">%s</p>', esc_html( $description ) );
	}

	/**
	 * Default content — the React mount div, driven by
	 * {@see get_react_root_id()}, {@see get_react_root_data()}, and
	 * {@see get_loading_placeholder()}. Emits nothing when
	 * `get_react_root_id()` returns empty (the default). Non-React tabs can
	 * override this method directly to emit form / table / custom markup
	 * without touching `render_body()`.
	 *
	 * @since 0.1.11
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_content( array $server ): void {
		$root_id = $this->get_react_root_id();
		if ( '' === $root_id ) {
			return;
		}

		$attrs = '';
		foreach ( $this->get_react_root_data( $server ) as $key => $value ) {
			$attrs .= sprintf(
				' data-%s="%s"',
				esc_attr( (string) $key ),
				esc_attr( (string) $value )
			);
		}

		$placeholder = $this->get_loading_placeholder();
		$inner       = '' === $placeholder
			? ''
			: '<p class="description">' . esc_html( $placeholder ) . '</p>';

		printf(
			'<div id="%s"%s>%s</div>',
			esc_attr( $root_id ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled from pre-escaped fragments above.
			$inner  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled from pre-escaped fragments above.
		);
	}

	/**
	 * Optional `<noscript>` notice text — the warning at the top of the
	 * no-JS fallback block explaining that JavaScript is required. Empty
	 * string suppresses the notice. Return non-empty (and/or non-empty
	 * summary rows) to have the base emit a `<noscript>` block for you.
	 *
	 * @since 0.1.11
	 * @return string
	 */
	public function get_noscript_notice(): string {
		return '';
	}

	/**
	 * Optional read-only key/value rows for the no-JS fallback table. Each
	 * row is `[ 'label' => 'X', 'value' => 'Y' ]`. Return `[]` to skip the
	 * table entirely (just the notice above will render).
	 *
	 * @since 0.1.11
	 * @param array $server Server row data.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function get_noscript_summary_rows( array $server ): array {
		unset( $server );
		return array();
	}

	/**
	 * No-JS fallback renderer — assembles a `<noscript>` block from the
	 * declarative getters above. Emits nothing when both getters return
	 * empty (the default; only React-mount tabs whose state should be
	 * inspectable without JS bother declaring these).
	 *
	 * Subclasses whose no-JS fallback doesn't fit the "notice + summary
	 * table" shape can still override this method directly.
	 *
	 * @since 0.1.11
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_noscript_fallback( array $server ): void {
		$notice = $this->get_noscript_notice();
		$rows   = $this->get_noscript_summary_rows( $server );
		if ( '' === $notice && empty( $rows ) ) {
			return;
		}

		echo '<noscript>';
		if ( '' !== $notice ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html( $notice )
			);
		}
		if ( ! empty( $rows ) ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $rows as $row ) {
				printf(
					'<tr><th>%1$s</th><td>%2$s</td></tr>',
					esc_html( (string) ( $row['label'] ?? '' ) ),
					esc_html( (string) ( $row['value'] ?? '' ) )
				);
			}
			echo '</tbody></table>';
		}
		echo '</noscript>';
	}

	/**
	 * Standard "Server is disabled" warning notice — rendered by the base
	 * when {@see requires_enabled_server()} is true and the server row is
	 * flagged disabled. Override to customize copy.
	 *
	 * @since 0.1.11
	 * @return void
	 */
	protected function render_disabled_server_notice(): void {
		printf(
			'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Server is disabled.', 'acrossai-mcp-manager' ),
			esc_html__( 'Enable the server on the Overview tab. You can still edit this tab — your changes take effect the moment the server is enabled.', 'acrossai-mcp-manager' )
		);
	}

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
