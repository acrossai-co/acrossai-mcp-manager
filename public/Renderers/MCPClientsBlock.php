<?php
/**
 * The MCP Clients configuration block — sub-nav + per-client config.
 *
 * Feature 013 — dispatches per-client configuration rendering across the
 * 7 F004 MCPClients (Claude Desktop, Claude Code, VS Code, GitHub Copilot,
 * Codex, Cursor, Custom Client) via the acrossai_mcp_client_classes filter
 * (Clarifications Q4).
 *
 * NOT gated by any F012 toggle (FR-019).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 *
 * See DEC-CLIENT-RENDERER-PUBLIC-API.
 */

namespace AcrossAI_MCP_Manager\Public\Renderers;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeCodeClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeDesktopClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CodexClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CursorClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CustomClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\GitHubCopilotClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\VSCodeClient;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The MCP Clients block — sub-nav + selected client's config. Singleton.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 */
final class MCPClientsBlock extends AbstractClientRenderer {

	/**
	 * Per-client display metadata (emoji + description + config file path +
	 * top-level JSON key + instructions). Keyed by AbstractMCPClient::get_client_slug().
	 * Extracted from each client class's docblock into a display-layer table so
	 * F004 MCPClients don't need new methods (F013 constraint: "do not re-implement
	 * any MCPClient class").
	 *
	 * @since 0.0.6
	 * @var array<string, array{emoji:string,description:string,config_file:string,top_level_key:string,instructions:string}>
	 */
	private const CLIENT_META = array(
		'claude-desktop' => array(
			'emoji'         => '🍰',
			'description'   => 'Anthropic Claude Desktop App',
			'config_file'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
			'top_level_key' => 'mcpServers',
			'instructions'  => 'Generate a password → copy the JSON → open the config file path above → paste under the top-level key → restart Claude Desktop.',
		),
		'claude-code'    => array(
			'emoji'         => '📄',
			'description'   => 'Anthropic Claude Code CLI',
			'config_file'   => '~/.claude/mcp_servers.json',
			'top_level_key' => 'mcpServers',
			'instructions'  => 'Generate a password → copy the JSON → run `claude mcp add-json` with the block, or paste into ~/.claude/mcp_servers.json under mcpServers → restart Claude Code.',
		),
		'vscode'         => array(
			'emoji'         => '▤',
			'description'   => 'Visual Studio Code',
			'config_file'   => '~/.vscode/mcp.json',
			'top_level_key' => 'servers',
			'instructions'  => 'Generate a password → copy the JSON → open .vscode/mcp.json in your workspace (or user-level ~/.vscode/mcp.json) → paste under servers → reload VS Code.',
		),
		'github-copilot' => array(
			'emoji'         => '🐱',
			'description'   => 'GitHub Copilot in VS Code (user-level MCP config)',
			'config_file'   => '~/.vscode/mcp.json',
			'top_level_key' => 'servers',
			'instructions'  => 'Generate a password → copy the JSON → open the user-level ~/.vscode/mcp.json → paste under servers → restart VS Code + GitHub Copilot extension.',
		),
		'codex'          => array(
			'emoji'         => '🐙',
			'description'   => 'OpenAI Codex CLI',
			'config_file'   => '~/.codex/config.toml',
			'top_level_key' => 'mcp_servers',
			'instructions'  => 'Generate a password → copy the TOML snippet → open ~/.codex/config.toml → paste under [mcp_servers] → restart Codex CLI.',
		),
		'cursor'         => array(
			'emoji'         => '⚡',
			'description'   => 'Cursor AI Code Editor',
			'config_file'   => '~/.cursor/mcp.json',
			'top_level_key' => 'mcpServers',
			'instructions'  => 'Generate a password → copy the JSON → open ~/.cursor/mcp.json → paste under mcpServers → restart Cursor.',
		),
		'custom'         => array(
			'emoji'         => '⚙',
			'description'   => 'Custom MCP Client Implementation',
			'config_file'   => 'depends on your client',
			'top_level_key' => 'depends on your client',
			'instructions'  => 'Use the JSON below as a starting point — most MCP clients accept the same command / args / env shape. Consult your client\'s docs for the exact config file path and top-level key.',
		),
	);

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.6
	 * @var MCPClientsBlock|null
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
		return 'clients';
	}

	/**
	 * Renders the block body — sub-nav pills + selected client's config.
	 *
	 * NOT gated by any F012 toggle (FR-019). Iterates over the client class
	 * FQNs returned by the acrossai_mcp_client_classes filter. Invalid FQNs
	 * silently skipped per FR-016b + SEC-013-008.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param array $server  Server row data.
	 * @param array $context Resolved context array.
	 * @return void
	 */
	protected function render_body( array $server, array $context ): void {
		$default_classes = array(
			ClaudeDesktopClient::class,
			ClaudeCodeClient::class,
			VSCodeClient::class,
			GitHubCopilotClient::class,
			CodexClient::class,
			CursorClient::class,
			CustomClient::class,
		);

		/**
		 * Filter — third-party plugins may append their own AbstractMCPClient subclass FQNs.
		 * Invalid FQNs silently skipped per SEC-013-008.
		 *
		 * @since 0.0.6
		 * @experimental May change without notice before 1.0.0.
		 *
		 * @param string[] $client_class_fqns Ordered list of AbstractMCPClient subclass FQNs.
		 */
		$class_fqns = (array) apply_filters( 'acrossai_mcp_client_classes', $default_classes );

		$clients = array();
		foreach ( $class_fqns as $fqn ) {
			if ( ! is_string( $fqn ) || ! class_exists( $fqn ) ) {
				continue;
			}
			if ( ! is_subclass_of( $fqn, AbstractMCPClient::class ) ) {
				continue;
			}
			$clients[] = new $fqn();
		}

		if ( empty( $clients ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'No MCP client integrations available.', 'acrossai-mcp-manager' )
			);
			return;
		}

		$sub_client_slug = isset( $context['sub_client'] ) ? sanitize_key( (string) $context['sub_client'] ) : '';
		$active_client   = null;
		foreach ( $clients as $client ) {
			if ( $client->get_client_slug() === $sub_client_slug ) {
				$active_client = $client;
				break;
			}
		}
		if ( null === $active_client ) {
			$active_client = reset( $clients );
		}

		echo '<div class="mcp-tab-panel acrossai-clients-panel">';
		$this->render_subnav( $clients, $active_client, $context );
		$this->render_client_details( $server, $context, $active_client );
		echo '</div>';
	}

	/**
	 * Renders the horizontal pill sub-nav (Claude Desktop, Claude Code, VS Code, ...).
	 *
	 * @since 0.0.6
	 * @param AbstractMCPClient[] $clients       Ordered client instances.
	 * @param AbstractMCPClient   $active_client Currently-active client.
	 * @param array               $context       Resolved context array.
	 * @return void
	 */
	private function render_subnav( array $clients, AbstractMCPClient $active_client, array $context ): void {
		echo '<div class="acrossai-client-tabs-nav">';
		foreach ( $clients as $client ) {
			$slug      = $client->get_client_slug();
			$meta      = self::CLIENT_META[ $slug ] ?? array( 'emoji' => '' );
			$emoji     = (string) ( $meta['emoji'] ?? '' );
			$is_active = ( $client === $active_client );
			$url       = add_query_arg( 'client', $slug, (string) $context['submit_target_url'] );
			$css_class = $is_active ? 'acrossai-client-tab acrossai-client-tab-active' : 'acrossai-client-tab';

			printf(
				'<a href="%1$s" class="%2$s"><span class="acrossai-client-tab-icon">%3$s</span><span>%4$s</span></a>',
				esc_url( $url ),
				esc_attr( $css_class ),
				esc_html( $emoji ),
				esc_html( $client->get_client_name() )
			);
		}
		echo '</div>';
	}

	/**
	 * Renders the selected client's details: heading + description + generate
	 * button + Config File row + Top-Level Key row + Configuration JSON block +
	 * Copy button + instructions callout.
	 *
	 * @since 0.0.6
	 * @param array             $server  Server row data.
	 * @param array             $context Resolved context array.
	 * @param AbstractMCPClient $client  Selected client instance.
	 * @return void
	 */
	private function render_client_details( array $server, array $context, AbstractMCPClient $client ): void {
		$slug = $client->get_client_slug();
		$meta = self::CLIENT_META[ $slug ] ?? array(
			'emoji'         => '',
			'description'   => '',
			'config_file'   => '',
			'top_level_key' => '',
			'instructions'  => '',
		);

		// Heading + subtitle.
		printf(
			'<h2>%1$s %2$s</h2>',
			esc_html( (string) ( $meta['emoji'] ?? '' ) ),
			esc_html( $client->get_client_name() )
		);
		if ( ! empty( $meta['description'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( (string) $meta['description'] )
			);
		}

		// Generate button + hint.
		echo '<div class="password-actions">';
		$this->passwords_generate_button( $server, $context );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Creates a one-time password via WordPress Application Passwords. Shown only once — store it safely.', 'acrossai-mcp-manager' )
		);
		echo '</div>';

		// Config File row.
		if ( ! empty( $meta['config_file'] ) ) {
			printf(
				'<div class="acrossai-mcp-meta-row"><span class="acrossai-mcp-meta-label">%1$s</span><span class="acrossai-mcp-meta-value">%2$s</span></div>',
				esc_html__( 'Config File', 'acrossai-mcp-manager' ),
				esc_html( (string) $meta['config_file'] )
			);
		}

		// Top-Level Key row.
		if ( ! empty( $meta['top_level_key'] ) ) {
			printf(
				'<div class="acrossai-mcp-meta-row"><span class="acrossai-mcp-meta-label">%1$s</span><span class="acrossai-mcp-meta-value">"%2$s"</span></div>',
				esc_html__( 'Top-Level Key', 'acrossai-mcp-manager' ),
				esc_html( (string) $meta['top_level_key'] )
			);
		}

		// Configuration JSON block — matches reference plugin's textarea shape.
		$server_url  = rest_url(
			trailingslashit( (string) $server['server_route_namespace'] ) . (string) $server['server_route']
		);
		$snippet     = $client->get_config_snippet( $server_url, '' );
		$textarea_id = 'acrossai-mcp-' . sanitize_key( $slug ) . '-config-' . (int) $server['id'];
		$body        = is_array( $snippet )
			? (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: (string) $snippet;

		echo '<div class="mcp-config-json">';
		printf(
			'<label for="%1$s"><strong>%2$s</strong></label>',
			esc_attr( $textarea_id ),
			esc_html__( 'Configuration JSON', 'acrossai-mcp-manager' )
		);
		printf(
			'<textarea id="%1$s" class="widefat code" readonly rows="12">%2$s</textarea>',
			esc_attr( $textarea_id ),
			esc_textarea( $body )
		);
		printf(
			'<button type="button" class="button copy-to-clipboard" data-field="%1$s">%2$s</button>',
			esc_attr( $textarea_id ),
			esc_html__( 'Copy Configuration', 'acrossai-mcp-manager' )
		);
		echo '</div>';

		// Instructions callout — reuse WP core notice styles.
		if ( ! empty( $meta['instructions'] ) ) {
			printf(
				'<div class="notice notice-info inline"><p>%1$s</p><p>%2$s</p></div>',
				esc_html( (string) $meta['instructions'] ),
				esc_html__( 'The generated password belongs to your current WordPress user. Access Control still applies to every MCP request, so a user who is not allowed for this server will receive an access denied response even if they have a saved config.', 'acrossai-mcp-manager' )
			);
		}
	}
}
