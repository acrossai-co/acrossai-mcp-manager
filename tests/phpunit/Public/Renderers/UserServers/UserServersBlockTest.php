<?php
/**
 * F038 UserServersBlock rendering tests — v3 client-first sidebar layout.
 *
 * Covers the production HTML shape defined in v3 `MCP Servers Widget v2.dc.html`
 * (design deliverable from `acrossai-mcp-manager.zip`).
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use AcrossAI_MCP_Manager\Public\Renderers\UserServers\UserServersBlock;
use ReflectionClass;
use WP_UnitTestCase;

final class UserServersBlockTest extends WP_UnitTestCase {

	private int $user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		AbstractEmbedTransport::flush_cache();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );

		UserServersBlock::instance()->register_shortcode();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers_meta" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers" );

		AbstractEmbedTransport::flush_cache();
		remove_all_filters( 'acrossai_mcp_servers_shortcode_html' );
		remove_all_filters( 'acrossai_mcp_user_accessible_servers' );
		remove_all_filters( 'acrossai_mcp_embed_transports' );
		remove_shortcode( 'acrossai_mcp_servers' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function create_and_enable_server( string $slug, string $name, string $client_slug = 'claude-desktop' ): int {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => $name,
				'server_slug'            => $slug,
				'description'            => 'desc for ' . $name,
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'mcp-client' => array( $client_slug ) )
		);
		AbstractEmbedTransport::flush_cache();
		return $server_id;
	}

	// ────────────────────────────────────────────────────────────────
	// Anonymous handling
	// ────────────────────────────────────────────────────────────────

	public function test_anonymous_returns_empty_string(): void {
		wp_set_current_user( 0 );
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertSame( '', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Empty state
	// ────────────────────────────────────────────────────────────────

	public function test_empty_state_renders_wrapper_and_default_message(): void {
		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers--empty-shell', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__empty', $out );
		$this->assertStringContainsString( 'don&#039;t have access', $out );
	}

	public function test_custom_empty_message_attribute(): void {
		$out = do_shortcode( '[acrossai_mcp_servers empty_message="Nothing here"]' );

		$this->assertStringContainsString( 'Nothing here', $out );
		$this->assertStringNotContainsString( 'don&#039;t have access', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Header
	// ────────────────────────────────────────────────────────────────

	public function test_header_default_title_summary_and_password_button(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__header', $out );
		$this->assertStringContainsString( '<h2 class="acrossai-mcp-servers__title">Connect your AI tools</h2>', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__summary', $out );
		$this->assertStringContainsString( '1 servers · 1 connection methods', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__password-btn', $out );
		$this->assertStringContainsString( 'Get an Application Password', $out );
	}

	public function test_heading_attribute_overrides_title(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers heading="My tools"]' );

		$this->assertStringContainsString( '<h2 class="acrossai-mcp-servers__title">My tools</h2>', $out );
	}

	public function test_multi_server_connection_summary(): void {
		$this->create_and_enable_server( 'srv-a', 'Alpha' );
		$this->create_and_enable_server( 'srv-b', 'Beta' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// 2 servers but same client (claude-desktop) enabled on both → 1 connection method.
		$this->assertStringContainsString( '2 servers · 1 connection methods', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Sidebar — collapsible transport groups → client-nav buttons
	// ────────────────────────────────────────────────────────────────

	public function test_sidebar_renders_transport_menu_with_group_head_and_client_nav(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A', 'claude-desktop' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Sidebar wrapper.
		$this->assertStringContainsString( 'acrossai-mcp-servers__sidebar', $out );
		// Collapsible transport-menu group.
		$this->assertStringContainsString( '<details class="acrossai-mcp-servers__transport-menu"', $out );
		$this->assertStringContainsString( 'data-transport-key="client"', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__transport-menu-head', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__transport-menu-name', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__transport-menu-count', $out );
		// Client-nav button inside the group.
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-nav', $out );
		$this->assertStringContainsString( 'data-amcp-client-select="claude-desktop"', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-nav-name', $out );
	}

	public function test_first_client_in_first_group_is_initially_selected(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A', 'claude-desktop' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertSame(
			1,
			substr_count( $out, 'aria-selected="true" data-amcp-client-select' ),
			'Exactly one client-nav button aria-selected="true".'
		);
	}

	// ────────────────────────────────────────────────────────────────
	// Right panel — client head + server picker + config content
	// ────────────────────────────────────────────────────────────────

	public function test_client_panel_head_shows_icon_name_transport_and_badge(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__client-panel', $out );
		$this->assertMatchesRegularExpression( '#data-active="true"[^>]*data-amcp-client=#', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-head', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-head-name', $out );
		// Transport label subtitle.
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-head-transport', $out );
		// Colored badge.
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-badge--config', $out );
		$this->assertStringContainsString( 'Local config', $out );
	}

	public function test_server_picker_renders_pill_per_server_first_selected(): void {
		$this->create_and_enable_server( 'srv-a', 'Server Alpha' );
		$this->create_and_enable_server( 'srv-b', 'Server Beta' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__server-picker', $out );
		$this->assertStringContainsString( 'Which server', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__server-pills', $out );
		$this->assertSame(
			2,
			substr_count( $out, 'acrossai-mcp-servers__server-pill' ),
			'One pill per server this client is enabled on.'
		);
		$this->assertSame(
			1,
			substr_count( $out, 'aria-selected="true" data-amcp-server-select' ),
			'Exactly one server pill initially selected.'
		);
	}

	public function test_url_rows_render_per_server_only_first_active(): void {
		$this->create_and_enable_server( 'srv-a', 'Alpha' );
		$this->create_and_enable_server( 'srv-b', 'Beta' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Two URL rows (one per server).
		$this->assertSame(
			2,
			substr_count( $out, 'acrossai-mcp-servers__url-row' ),
			'One URL row per server.'
		);
		$this->assertSame(
			1,
			substr_count( $out, 'data-active="true" data-amcp-server="' ) - 0,
			'Only one server-scoped block starts data-active="true" (URL row for first server).'
		);
	}

	public function test_config_grid_shows_config_file_and_top_level_key(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__grid', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__field-label', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__field-value', $out );
		$this->assertStringContainsString( 'Config file', $out );
		$this->assertStringContainsString( 'Top-level key', $out );
		$this->assertStringContainsString( 'claude_desktop_config.json', $out );
		$this->assertStringContainsString( 'mcpServers', $out );
	}

	public function test_code_blocks_render_per_server(): void {
		$this->create_and_enable_server( 'srv-a', 'Alpha' );
		$this->create_and_enable_server( 'srv-b', 'Beta' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Two code blocks (one per server).
		$this->assertSame(
			2,
			substr_count( $out, 'acrossai-mcp-servers__code-bar' ),
			'One code block per server.'
		);
		$this->assertStringContainsString( 'acrossai-mcp-servers__lang', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__line-count', $out );
		$this->assertStringContainsString( 'data-amcp-copy="#amcp-code-', $out );
	}

	public function test_steps_grid_renders_numbered_from_instructions(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__steps-block', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__steps-grid', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__step-num', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__step-text', $out );
		$this->assertStringContainsString( 'Generate a password', $out );
		// Replace pill for local-config transport.
		$this->assertStringContainsString( 'acrossai-mcp-servers__replace-pill', $out );
	}

	public function test_client_deduplicated_across_servers(): void {
		// Same client (claude-desktop) enabled on 2 servers → only one
		// client-nav button in the sidebar, but the server picker inside
		// the panel shows both servers.
		$this->create_and_enable_server( 'srv-a', 'Alpha' );
		$this->create_and_enable_server( 'srv-b', 'Beta' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertSame(
			1,
			substr_count( $out, 'data-amcp-client-select="claude-desktop"' ),
			'One client-nav button per unique client slug.'
		);
		$this->assertSame(
			2,
			substr_count( $out, 'acrossai-mcp-servers__server-pill' ),
			'Two server pills (one per server enabling this client).'
		);
	}

	public function test_server_url_uses_rest_url_shape(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'URL Server',
				'server_slug'            => 'url-srv',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'url-srv-route',
				'server_version'         => 'v1.0.0',
			)
		);
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'mcp/url-srv-route', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Escape at boundary — XSS regression
	// ────────────────────────────────────────────────────────────────

	public function test_escape_at_boundary_server_name(): void {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Foo <script>alert(1)</script>',
				'server_slug'            => 'srv-xss',
				'description'            => 'ok',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'srv-xss',
				'server_version'         => 'v1.0.0',
			)
		);
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $out );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Singleton contract (D36 + S6 + A2)
	// ────────────────────────────────────────────────────────────────

	public function test_singleton_private_ctor(): void {
		$reflect = new ReflectionClass( UserServersBlock::class );
		$ctor    = $reflect->getConstructor();

		$this->assertNotNull( $ctor, 'Constructor must exist.' );
		$this->assertTrue( $ctor->isPrivate(), 'Constructor MUST be private per S6.' );
		$this->assertTrue( $reflect->isFinal(), 'Class MUST be final per D36.' );
	}

	public function test_singleton_returns_same_instance(): void {
		$a = UserServersBlock::instance();
		$b = UserServersBlock::instance();
		$this->assertSame( $a, $b );
	}

	// ────────────────────────────────────────────────────────────────
	// HTML filter contract (US3 / SEC-002)
	// ────────────────────────────────────────────────────────────────

	public function test_html_filter_round_trip_prepends_comment(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		add_filter(
			'acrossai_mcp_servers_shortcode_html',
			static function ( $html ): string {
				return '<!-- f038-filter-applied -->' . (string) $html;
			}
		);

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringStartsWith( '<!-- f038-filter-applied -->', $out );
	}

	public function test_html_filter_fires_exactly_once_per_render(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$fires = 0;
		add_filter(
			'acrossai_mcp_servers_shortcode_html',
			static function ( $html ) use ( &$fires ) {
				++$fires;
				return $html;
			}
		);

		do_shortcode( '[acrossai_mcp_servers]' );
		$this->assertSame( 1, $fires );
	}

	// ────────────────────────────────────────────────────────────────
	// Assets — enqueue-time behavior
	// ────────────────────────────────────────────────────────────────

	public function test_assets_enqueued_on_render(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertTrue( wp_style_is( 'acrossai-mcp-user-servers', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'acrossai-mcp-user-servers', 'enqueued' ) );
	}

	public function test_assets_not_enqueued_for_anonymous(): void {
		wp_set_current_user( 0 );
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertFalse( wp_style_is( 'acrossai-mcp-user-servers', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'acrossai-mcp-user-servers', 'enqueued' ) );
	}
}
