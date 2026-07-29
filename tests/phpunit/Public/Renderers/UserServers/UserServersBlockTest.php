<?php
/**
 * F038 UserServersBlock rendering tests — post-design-brief refresh.
 *
 * Covers the production HTML shape defined in `shortcode-output.html`
 * (design deliverable from `acrossai-mcp-manager.zip`). CSS + JS assets
 * live in `src/scss/frontend.scss` + `src/js/frontend.js`; the widget
 * enqueues built handles at render time — no inline `<style>` block to
 * assert on.
 *
 * Original T015 + T021 coverage preserved; five obsolete tests replaced:
 *   - test_style_emitted_exactly_once   (no inline style block anymore)
 *   - test_style_reset_between_requests (same)
 *   - test_default_render_shape         (new markup — details/summary)
 *   - test_heading_attribute_renders_h2 (still valid, but new class name)
 *   - test_show_instructions_zero_omits_instructions_paragraph (attribute removed)
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

		// Register the shortcode (init doesn't fire in unit-test env).
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
	// Empty-state
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
	// Default render shape — new design-brief markup
	// ────────────────────────────────────────────────────────────────

	public function test_default_render_shape(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Outer wrapper.
		$this->assertStringContainsString( '<div class="acrossai-mcp-servers">', $out );
		// Header title.
		$this->assertStringContainsString( '<h2 class="acrossai-mcp-servers__title">', $out );
		// Server count pill.
		$this->assertStringContainsString( 'acrossai-mcp-servers__count', $out );
		$this->assertStringContainsString( '1 server available', $out );
		// Global Application Password notice.
		$this->assertStringContainsString( 'acrossai-mcp-servers__notice', $out );
		$this->assertStringContainsString( 'You need a WordPress Application Password', $out );
		// Server details card.
		$this->assertStringContainsString( '<details class="acrossai-mcp-servers__server"', $out );
		$this->assertStringContainsString( 'data-server-slug="srv-a"', $out );
		$this->assertStringContainsString( 'Server A', $out );
		// First card is open.
		$this->assertMatchesRegularExpression( '#<details class="acrossai-mcp-servers__server"[^>]*\bopen\b#', $out );
		// Server URL row with Copy button.
		$this->assertStringContainsString( 'acrossai-mcp-servers__urlrow', $out );
		$this->assertStringContainsString( 'data-amcp-copy="#amcp-url-', $out );
		// Client card.
		$this->assertStringContainsString( '<details class="acrossai-mcp-servers__client"', $out );
		$this->assertStringContainsString( 'data-slug="claude-desktop"', $out );
		$this->assertStringContainsString( 'data-category="client"', $out );
	}

	public function test_multi_server_count_pill(): void {
		$this->create_and_enable_server( 'srv-a', 'Alpha' );
		$this->create_and_enable_server( 'srv-b', 'Beta' );
		$this->create_and_enable_server( 'srv-c', 'Cetera' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( '3 servers available', $out );
	}

	public function test_heading_and_intro_attributes(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers heading="My servers" intro="Custom lede"]' );

		$this->assertStringContainsString( '<h2 class="acrossai-mcp-servers__title">My servers</h2>', $out );
		$this->assertStringContainsString( 'Custom lede', $out );
	}

	public function test_show_description_false_omits_desc(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$with_desc = do_shortcode( '[acrossai_mcp_servers]' );
		$without   = do_shortcode( '[acrossai_mcp_servers show_description="0"]' );

		$this->assertStringContainsString( 'acrossai-mcp-servers__server-desc', $with_desc );
		$this->assertStringNotContainsString( 'acrossai-mcp-servers__server-desc', $without );
	}

	// ────────────────────────────────────────────────────────────────
	// Per-DTO details block (FR-029/030/031)
	// ────────────────────────────────────────────────────────────────

	public function test_client_body_renders_config_file_top_level_key_and_snippet(): void {
		$this->create_and_enable_server( 'srv-cfg', 'Server Cfg' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Two-field grid + code block + Copy button.
		$this->assertStringContainsString( 'acrossai-mcp-servers__grid', $out );
		$this->assertStringContainsString( 'Config file', $out );
		$this->assertStringContainsString( 'Top-level key', $out );
		// Claude Desktop's config file is documented in F034 metadata.
		$this->assertStringContainsString( 'claude_desktop_config.json', $out );
		// The config snippet includes the top-level key `mcpServers`.
		$this->assertStringContainsString( 'mcpServers', $out );
		// Code block with lang bar + Copy button.
		$this->assertStringContainsString( 'acrossai-mcp-servers__code-bar', $out );
		$this->assertStringContainsString( 'acrossai-mcp-servers__lang', $out );
		$this->assertStringContainsString( 'data-amcp-copy="#amcp-code-', $out );
	}

	public function test_client_body_renders_numbered_steps_from_instructions(): void {
		$this->create_and_enable_server( 'srv-steps', 'Server Steps' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Ordered list rendered from get_instructions() split on ` → `.
		$this->assertStringContainsString( 'acrossai-mcp-servers__steps', $out );
		$this->assertMatchesRegularExpression( '#<ol class="acrossai-mcp-servers__steps">\s*<li>#', $out );
		// Every F034 client's instructions contain "Generate a password" as step 1.
		$this->assertStringContainsString( 'Generate a password', $out );
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

		$this->assertStringContainsString(
			'<img class="acrossai-mcp-servers__icon" src="https://cdn.example.com/icon.png"',
			$out
		);
	}

	public function test_icon_non_url_becomes_text(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Claude Desktop's icon is 🍰 (emoji) — should render as text span.
		$this->assertMatchesRegularExpression(
			'#<span class="acrossai-mcp-servers__icon" aria-hidden="true">[^<]+</span>#u',
			$out
		);
		$this->assertStringNotContainsString( '<img class="acrossai-mcp-servers__icon"', $out );
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
	// Singleton contract (S6 + A2 + D36)
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
		$this->assertStringContainsString( 'class="acrossai-mcp-servers"', $out );
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

// ────────────────────────────────────────────────────────────────
// Fake transport fixture — DTO with URL icon
// ────────────────────────────────────────────────────────────────

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
