<?php
/**
 * F038 UserServersBlock rendering tests — v2 design brief markup.
 *
 * Covers the production HTML shape defined in `MCP Servers Widget v2.dc.html`
 * (design deliverable from `acrossai-mcp-manager.zip`). Two-column sidebar
 * layout: click a server nav button → the matching server panel becomes
 * `data-active="true"`; click a client pill → the matching client-detail card
 * becomes `data-active="true"`. Selection state managed by
 * `src/js/frontend.js` at runtime; PHP renders every server/client at once
 * with the first of each marked active.
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
	// v2 shell — header + layout + sidebar + main
	// ────────────────────────────────────────────────────────────────

	public function test_header_renders_title_summary_and_password_button(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__header', $out );
		$this->assertStringContainsString( '<h2 class="acrossai-mcp-servers__title">Your MCP Servers</h2>', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__summary', $out );
		$this->assertStringContainsString( '1 servers · 1 clients', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__password-btn', $out );
		$this->assertStringContainsString( 'Get an Application Password', $out );
	}

	public function test_heading_attribute_overrides_title(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers heading="My tools"]' );

		$this->assertStringContainsString( '<h2 class="acrossai-mcp-servers__title">My tools</h2>', $out );
	}

	public function test_layout_grid_renders_sidebar_and_main(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__layout', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__sidebar', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__main', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Sidebar server nav
	// ────────────────────────────────────────────────────────────────

	public function test_sidebar_renders_one_nav_button_per_server_first_selected(): void {
		$this->create_and_enable_server( 'srv-alpha', 'Alpha' );
		$this->create_and_enable_server( 'srv-beta', 'Beta' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertSame(
			2,
			substr_count( $out, 'data-amcp-server-select=' ),
			'One sidebar button per server.'
		);
		$this->assertSame(
			1,
			substr_count( $out, 'aria-selected="true" data-amcp-server-select' ),
			'Exactly one sidebar button aria-selected="true" — the first server.'
		);
		$this->assertStringContainsString( 'acrossai-mcp-servers__server-nav-name', $out );
		$this->assertStringContainsString( '<span class="acrossai-mcp-servers__server-nav-name">Alpha</span>', $out );
		$this->assertStringContainsString( '<span class="acrossai-mcp-servers__server-nav-name">Beta</span>', $out );
	}

	public function test_sidebar_summary_shows_client_count_and_preview(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A', 'claude-desktop' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__server-nav-summary', $out );
		$this->assertStringContainsString( '1 client · Claude Desktop', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Server panel — context card + client pills + client detail cards
	// ────────────────────────────────────────────────────────────────

	public function test_server_panel_renders_context_url_pills(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Panel wrapper.
		$this->assertStringContainsString( 'acrossai-mcp-servers__server-panel', $out );
		$this->assertMatchesRegularExpression( '#data-active="true"[^>]*data-amcp-server=#', $out );
		// Server context card.
		$this->assertStringContainsString( 'acrossai-mcp-servers__server-context', $out );
		$this->assertStringContainsString( '<span class="acrossai-mcp-servers__server-name">Server A</span>', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__server-desc', $out );
		// URL row.
		$this->assertStringContainsString( 'acrossai-mcp-servers__url-row', $out );
		$this->assertStringContainsString( '<span class="acrossai-mcp-servers__url-label">URL</span>', $out );
		$this->assertStringContainsString( 'data-amcp-copy="#amcp-url-', $out );
		// Client pills.
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-pills', $out );
		$this->assertStringContainsString( 'data-amcp-client-select="claude-desktop"', $out );
		$this->assertStringContainsString( 'aria-selected="true" data-amcp-client-select', $out );
	}

	public function test_client_detail_card_renders_head_grid_and_code(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__client-detail', $out );
		$this->assertMatchesRegularExpression( '#data-active="true"[^>]*data-amcp-client=#', $out );
		// Head + name + badge.
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-detail-head', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-detail-name', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__client-badge--config', $out );
		$this->assertStringContainsString( 'Local config', $out );
		// Two-field grid.
		$this->assertStringContainsString( 'acrossai-mcp-servers__grid', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__field-label', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__field-value', $out );
		$this->assertStringContainsString( 'Config file', $out );
		$this->assertStringContainsString( 'Top-level key', $out );
		// F034 metadata surfaces.
		$this->assertStringContainsString( 'claude_desktop_config.json', $out );
		$this->assertStringContainsString( 'mcpServers', $out );
		// Code block with lang bar + line count + Copy.
		$this->assertStringContainsString( 'acrossai-mcp-servers__code-bar', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__lang', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__line-count', $out );
		$this->assertStringContainsString( 'data-amcp-copy="#amcp-code-', $out );
	}

	public function test_steps_grid_renders_numbered_from_instructions(): void {
		$this->create_and_enable_server( 'srv-steps', 'Server Steps' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__steps-block', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__steps-grid', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__step-num', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__step-text', $out );
		$this->assertStringContainsString( 'Generate a password', $out );
		// Replace pill rendered next to STEPS label for client-config transport.
		$this->assertStringContainsString( 'acrossai-mcp-servers__replace-pill', $out );
	}

	public function test_show_description_false_omits_server_desc(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$with    = do_shortcode( '[acrossai_mcp_servers]' );
		$without = do_shortcode( '[acrossai_mcp_servers show_description="0"]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__server-desc', $with );
		$this->assertStringNotContainsString( 'acrossai-mcp-servers__server-desc', $without );
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

	public function test_multi_server_summary(): void {
		$this->create_and_enable_server( 'srv-a', 'Alpha' );
		$this->create_and_enable_server( 'srv-b', 'Beta' );
		$this->create_and_enable_server( 'srv-c', 'Cetera' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( '3 servers · 3 clients', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Icon rendering
	// ────────────────────────────────────────────────────────────────

	public function test_icon_url_becomes_img(): void {
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $classes ): array {
				$classes[] = FakeTransportUrlIcon::class;
				return $classes;
			}
		);
		AbstractEmbedTransport::flush_cache();

		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Server URL',
				'server_slug'            => 'srv-url',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'srv-url',
				'server_version'         => 'v1.0.0',
			)
		);
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'fake-url-icon' => array( 'has-url' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Pill icon uses <img>.
		$this->assertStringContainsString( '<img src="https://cdn.example.com/icon.png"', $out );
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
		$this->assertStringContainsString( 'class="acrossai-mcp-servers"', $out );
	}

	public function test_html_filter_can_wrap_output(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		add_filter(
			'acrossai_mcp_servers_shortcode_html',
			static function ( $html ): string {
				return '<div class="my-brand-wrapper">' . (string) $html . '</div>';
			}
		);

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringStartsWith( '<div class="my-brand-wrapper">', $out );
		$this->assertStringEndsWith( '</div>', $out );
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

final class FakeTransportUrlIcon extends AbstractEmbedTransport {

	public function get_transport_key(): string {
		return 'fake-url-icon';
	}

	public function get_checkbox_label(): string {
		return 'Fake URL Icon';
	}

	public function get_priority(): int {
		return 50;
	}

	public function get_dtos(): array {
		return array(
			array(
				'slug'        => 'has-url',
				'name'        => 'URL Icon Item',
				'icon'        => 'https://cdn.example.com/icon.png',
				'description' => '',
				'meta'        => array(),
			),
		);
	}
}
