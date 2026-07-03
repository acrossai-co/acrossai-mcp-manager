<?php
/**
 * The Claude Connector configuration block.
 *
 * Feature 013 — this Block encapsulates the Claude Connector configuration
 * render so admin tab (ClaudeConnectorTab) AND third-party plugins consume
 * the same UI with zero code duplication.
 *
 * Ported from reference plugin's render_claude_connector_tab
 * (src/Admin/Settings.php:1498–1698). Emits the same markup + class names
 * as the reference so the shared admin.css/backend.scss styles + admin.js
 * copy-to-clipboard handler light up automatically.
 *
 * F012 gate: acrossai_mcp_claude_connectors_enabled option. When disabled,
 * renders the "currently disabled" notice + link to Settings INSTEAD of the
 * config UI. Connector audit log renders regardless of the toggle state.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 *
 * See DEC-CLIENT-RENDERER-PUBLIC-API.
 */

namespace AcrossAI_MCP_Manager\Public\Renderers;

use AcrossAI_MCP_Manager\Admin\Partials\ConnectorAuditLogListTable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Claude Connector block. Singleton.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 */
final class ClaudeConnectorBlock extends AbstractClientRenderer {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.6
	 * @var ClaudeConnectorBlock|null
	 */
	protected static $instance = null;

	/**
	 * Singleton accessor.
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
	 * Returns the block slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'claude-connector';
	}

	/**
	 * Renders the block body. Gated on F012 acrossai_mcp_claude_connectors_enabled.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0. (SEC-013-005)
	 *
	 * @param array $server  Server row data.
	 * @param array $context Resolved context array.
	 * @return void
	 */
	protected function render_body( array $server, array $context ): void {
		echo '<div class="mcp-tab-panel">';

		$enabled = (bool) get_option( 'acrossai_mcp_claude_connectors_enabled', false );
		if ( ! $enabled ) {
			$settings_url = admin_url( 'admin.php?page=acrossai-settings&tab=mcp' );
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: %s: settings page URL */
						__( 'Direct Claude Connectors mode is currently disabled. Turn it on from the <a href="%s">settings page</a> first.', 'acrossai-mcp-manager' ),
						esc_url( $settings_url )
					)
				)
			);
			echo '</div>';
			return;
		}

		$server_id       = (int) $server['id'];
		$mcp_url         = $this->build_mcp_url( $server );
		$display_name    = $this->build_display_name( $server );
		$connector_ready = $this->is_connector_ready( $server );

		$this->render_form( $server, $context, $server_id );
		$this->render_example_notice( $display_name, $mcp_url, $server_id );

		if ( $connector_ready ) {
			$this->render_paste_into_claude( $display_name, $mcp_url, $server_id );
		}

		printf(
			'<div class="notice notice-info inline"><p>%s</p></div>',
			esc_html__( 'Per-server Access Control is still enforced after OAuth sign-in. If a WordPress user is denied for this MCP server, Claude will not be able to use it even after a successful connector login.', 'acrossai-mcp-manager' )
		);

		$this->render_audit_log( $server_id );

		echo '</div>';
	}

	/**
	 * Builds the MCP server URL for the "Remote MCP server URL" field.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return string
	 */
	private function build_mcp_url( array $server ): string {
		return rest_url(
			trailingslashit( (string) $server['server_route_namespace'] ) . (string) $server['server_route']
		);
	}

	/**
	 * Builds the suggested display name for Claude's custom connector form.
	 * Format: "<site name> - <server name>".
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return string
	 */
	private function build_display_name( array $server ): string {
		$site_name   = (string) get_bloginfo( 'name' );
		$server_name = (string) ( $server['server_name'] ?? '' );
		if ( '' === $site_name ) {
			return $server_name;
		}
		return $site_name . ' - ' . $server_name;
	}

	/**
	 * True when the server has at least a client ID + redirect URI — enough
	 * to be usable from Claude. Secret is optional (public PKCE clients).
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return bool
	 */
	private function is_connector_ready( array $server ): bool {
		return ! empty( $server['claude_connector_client_id'] )
			&& ! empty( $server['claude_connector_redirect_uri'] );
	}

	/**
	 * Renders the client_id / client_secret / redirect_uri form.
	 *
	 * @since 0.0.6
	 * @param array $server    Server row data.
	 * @param array $context   Resolved context array.
	 * @param int   $server_id Server ID.
	 * @return void
	 */
	private function render_form( array $server, array $context, int $server_id ): void {
		$oauth_client_id = (string) ( $server['claude_connector_client_id'] ?? '' );
		$oauth_secret    = (string) ( $server['claude_connector_client_secret'] ?? '' );
		$redirect_uri    = (string) ( $server['claude_connector_redirect_uri'] ?? '' );
		$post_url        = (string) $context['submit_target_url'];
		$nonce_action    = (string) $context['nonce_action'];

		printf( '<h2>%s</h2>', esc_html__( 'Advanced OAuth registration', 'acrossai-mcp-manager' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'These values come from the Claude connector registration for this specific server. WordPress cannot generate them for you.', 'acrossai-mcp-manager' )
		);

		printf(
			'<form method="post" action="%s" style="margin-top:16px;">',
			esc_url( $post_url )
		);
		wp_nonce_field( $nonce_action );
		echo '<input type="hidden" name="action" value="save_claude_connector" />';
		printf(
			'<input type="hidden" name="server" value="%s" />',
			esc_attr( (string) $server_id )
		);

		echo '<table class="form-table" role="presentation">';

		$this->render_input_row(
			'claude_connector_client_id_input_' . $server_id,
			'claude_connector_client_id',
			__( 'OAuth Client ID (Advanced)', 'acrossai-mcp-manager' ),
			'text',
			$oauth_client_id,
			__( 'Paste the client ID from this server\'s Claude connector registration.', 'acrossai-mcp-manager' ),
			'',
			'off'
		);

		$this->render_input_row(
			'claude_connector_client_secret_input_' . $server_id,
			'claude_connector_client_secret',
			__( 'OAuth Client Secret (Advanced)', 'acrossai-mcp-manager' ),
			'password',
			$oauth_secret,
			__( 'Optional. Leave blank if this connector uses a public PKCE client with no secret.', 'acrossai-mcp-manager' ),
			'',
			'new-password'
		);

		$this->render_input_row(
			'claude_connector_redirect_uri_input_' . $server_id,
			'claude_connector_redirect_uri',
			__( 'OAuth Redirect URI (Advanced)', 'acrossai-mcp-manager' ),
			'url',
			$redirect_uri,
			__( 'Paste the callback URL from this server\'s Claude connector registration. WordPress cannot generate this URL.', 'acrossai-mcp-manager' ),
			'https://your-oauth-client.example/callback',
			''
		);

		echo '</table>';
		submit_button( __( 'Save Claude Connector Settings', 'acrossai-mcp-manager' ) );
		echo '</form>';
	}

	/**
	 * Renders one `form-table` row (label + input + description).
	 *
	 * @since 0.0.6
	 * @param string $field_id     DOM id for the input.
	 * @param string $field_name   Form name attribute.
	 * @param string $label        Label text.
	 * @param string $input_type   'text' | 'password' | 'url'.
	 * @param string $value        Current field value.
	 * @param string $description  Helper text under the input.
	 * @param string $placeholder  Placeholder text (empty to skip).
	 * @param string $autocomplete Autocomplete attribute (empty to skip).
	 * @return void
	 */
	private function render_input_row( string $field_id, string $field_name, string $label, string $input_type, string $value, string $description, string $placeholder, string $autocomplete ): void {
		$input_class = 'url' === $input_type ? 'regular-text code' : 'regular-text';

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>',
			esc_attr( $field_id ),
			esc_html( $label )
		);
		printf(
			'<input type="%1$s" class="%2$s" id="%3$s" name="%4$s" value="%5$s" placeholder="%6$s" autocomplete="%7$s" />',
			esc_attr( $input_type ),
			esc_attr( $input_class ),
			esc_attr( $field_id ),
			esc_attr( $field_name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_attr( $autocomplete )
		);
		printf( '<p class="description">%s</p></td></tr>', esc_html( $description ) );
	}

	/**
	 * Renders the blue info notice with the "Example format" table.
	 *
	 * @since 0.0.6
	 * @param string $display_name Server display name.
	 * @param string $mcp_url      MCP server URL.
	 * @param int    $server_id    Server ID.
	 * @return void
	 */
	private function render_example_notice( string $display_name, string $mcp_url, int $server_id ): void {
		echo '<div class="notice notice-info inline" style="margin-top:16px;">';
		printf(
			'<p><strong>%s</strong></p>',
			esc_html__( 'Example format (OAuth fields are placeholder only)', 'acrossai-mcp-manager' )
		);
		printf(
			'<p>%s</p>',
			esc_html__( 'The Name and Remote MCP server URL below are this server\'s actual values. The OAuth fields show placeholder examples only — use your real OAuth values from the Claude connector registration.', 'acrossai-mcp-manager' )
		);

		$demo_client = 'claude-demo-client-' . substr( wp_hash( (string) $server_id ), 0, 8 );
		$demo_secret = 'demo-secret-' . substr( wp_hash( $server_id . 'secret' ), 0, 8 );
		$demo_uri    = 'https://claude.example.com/connectors/oauth/callback';

		echo '<table class="widefat striped" style="max-width:860px;margin-top:8px;"><tbody>';
		$this->render_example_row( __( 'Name', 'acrossai-mcp-manager' ), $display_name );
		$this->render_example_row( __( 'Remote MCP server URL', 'acrossai-mcp-manager' ), $mcp_url );
		$this->render_example_row( __( 'OAuth Client ID', 'acrossai-mcp-manager' ), $demo_client );
		$this->render_example_row( __( 'OAuth Client Secret', 'acrossai-mcp-manager' ), $demo_secret );
		$this->render_example_row( __( 'OAuth Redirect URI', 'acrossai-mcp-manager' ), $demo_uri );
		echo '</tbody></table></div>';
	}

	/**
	 * Renders one two-column row (label + code) inside the example table.
	 *
	 * @since 0.0.6
	 * @param string $label Label.
	 * @param string $value Code value.
	 * @return void
	 */
	private function render_example_row( string $label, string $value ): void {
		printf(
			'<tr><td style="width:240px;"><strong>%1$s</strong></td><td><code>%2$s</code></td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Renders the "Paste into Claude" section (only when the server has
	 * enough OAuth values that Claude can actually connect).
	 *
	 * @since 0.0.6
	 * @param string $display_name Server display name.
	 * @param string $mcp_url      MCP server URL.
	 * @param int    $server_id    Server ID.
	 * @return void
	 */
	private function render_paste_into_claude( string $display_name, string $mcp_url, int $server_id ): void {
		printf(
			'<h2 style="margin-top:24px;">%s</h2>',
			esc_html__( 'Paste into Claude', 'acrossai-mcp-manager' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'After saving the server registration values above, copy these two values into Claude\'s Add custom connector screen.', 'acrossai-mcp-manager' )
		);

		echo '<table class="form-table" role="presentation" style="margin-top:16px;">';

		$name_id = 'claude_connector_name_' . $server_id;
		$this->render_paste_row(
			$name_id,
			__( 'Claude field: Name', 'acrossai-mcp-manager' ),
			$display_name,
			'widefat code mcp-cmd',
			1,
			__( 'Copy Name', 'acrossai-mcp-manager' ),
			__( 'This is just the label shown inside Claude. You can change it, but using the server name keeps things clear when you have multiple connectors.', 'acrossai-mcp-manager' )
		);

		$url_id = 'claude_connector_url_' . $server_id;
		$this->render_paste_row(
			$url_id,
			__( 'Claude field: Remote MCP server URL', 'acrossai-mcp-manager' ),
			$mcp_url,
			'widefat code',
			2,
			__( 'Copy URL', 'acrossai-mcp-manager' ),
			__( 'Paste this exact MCP server URL into Claude. This value is specific to the server you are editing right now.', 'acrossai-mcp-manager' )
		);

		echo '</table>';
	}

	/**
	 * Renders one paste-into-Claude row: label / readonly textarea / copy button / helper text.
	 *
	 * @since 0.0.6
	 * @param string $field_id      DOM id for the textarea.
	 * @param string $th_label      Row label (i18n'd).
	 * @param string $body          Textarea body.
	 * @param string $textarea_css  Textarea CSS class.
	 * @param int    $rows          Row count.
	 * @param string $button_label  Copy button label.
	 * @param string $helper_text   Description below the button.
	 * @return void
	 */
	private function render_paste_row( string $field_id, string $th_label, string $body, string $textarea_css, int $rows, string $button_label, string $helper_text ): void {
		printf( '<tr><th scope="row">%s</th><td>', esc_html( $th_label ) );
		printf(
			'<textarea id="%1$s" class="%2$s" rows="%3$d" readonly>%4$s</textarea>',
			esc_attr( $field_id ),
			esc_attr( $textarea_css ),
			(int) $rows,
			esc_textarea( $body )
		);
		printf(
			'<button type="button" class="button copy-to-clipboard" data-field="%1$s">%2$s</button>',
			esc_attr( $field_id ),
			esc_html( $button_label )
		);
		printf( '<p class="description">%s</p>', esc_html( $helper_text ) );
		echo '</td></tr>';
	}

	/**
	 * Renders the Direct Connector Audit Log section.
	 *
	 * @since 0.0.6
	 * @param int $server_id Server ID.
	 * @return void
	 */
	private function render_audit_log( int $server_id ): void {
		echo '<hr style="margin:24px 0;" />';
		printf( '<h3>%s</h3>', esc_html__( 'Direct Connector Audit Log', 'acrossai-mcp-manager' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Discovery, authorization, token, bearer-auth, and MCP request events for this server. Global connector events may also appear here for context.', 'acrossai-mcp-manager' )
		);

		if ( ! class_exists( ConnectorAuditLogListTable::class ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Audit log unavailable.', 'acrossai-mcp-manager' )
			);
			return;
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="acrossai_mcp_manager" />';
		echo '<input type="hidden" name="action" value="edit" />';
		printf(
			'<input type="hidden" name="server" value="%s" />',
			esc_attr( (string) $server_id )
		);
		echo '<input type="hidden" name="tab" value="claude-connector" />';
		$table = new ConnectorAuditLogListTable( $server_id );
		$table->prepare_items();
		$table->display();
		echo '</form>';
	}
}
