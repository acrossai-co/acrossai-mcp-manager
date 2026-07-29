<?php
/**
 * F038 T017-T018 — Third-party extensibility validation.
 *
 * Two scenarios end-to-end:
 *
 *  1. Companion plugin consumes the base as a DATA-ONLY source: subclass
 *     AbstractUserServersRenderer, call get_accessible_servers(),
 *     assert the returned array shape matches contracts/data-model.md.
 *     No shortcode involvement.
 *
 *  2. Companion plugin registers a FAKE FOURTH TRANSPORT via
 *     acrossai_mcp_embed_transports and expects its DTOs to surface in
 *     the F038 payload with zero F038 code changes (proves SC-005).
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use AcrossAI_MCP_Manager\Public\Renderers\UserServers\AbstractUserServersRenderer;
use WP_UnitTestCase;

final class ThirdPartyExtensibilityTest extends WP_UnitTestCase {

	private int $user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		AbstractEmbedTransport::flush_cache();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers_meta" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers" );

		AbstractEmbedTransport::flush_cache();
		remove_all_filters( 'acrossai_mcp_embed_transports' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function seed_server( string $slug, string $name ): int {
		return (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => $name,
				'server_slug'            => $slug,
				'description'            => '',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
	}

	// ────────────────────────────────────────────────────────────────
	// T017 — Data-only consumer subclass
	// ────────────────────────────────────────────────────────────────

	public function test_companion_plugin_consumes_base_as_data_source(): void {
		$server_id = $this->seed_server( 'srv-a', 'Server A' );
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);
		AbstractEmbedTransport::flush_cache();

		// Companion plugin: subclass the base, no rendering — pure data.
		$companion = new class() extends AbstractUserServersRenderer {
			/**
			 * Simulates a companion plugin's own render function that
			 * consumes the data and iterates over it — no F038 HTML
			 * rendering involved.
			 *
			 * @return array<int, string> Server names for the given user.
			 */
			public function collect_names( int $user_id ): array {
				$names = array();
				foreach ( $this->get_accessible_servers( $user_id ) as $entry ) {
					if ( isset( $entry['server_name'] ) ) {
						$names[] = (string) $entry['server_name'];
					}
				}
				return $names;
			}
		};

		$names = $companion->collect_names( $this->user_id );

		$this->assertSame( array( 'Server A' ), $names );
	}

	public function test_return_shape_matches_contract(): void {
		$server_id = $this->seed_server( 'srv-shape', 'Shape Server' );
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$renderer = new class() extends AbstractUserServersRenderer {};
		$data     = $renderer->get_accessible_servers( $this->user_id );

		$this->assertCount( 1, $data );
		$entry = $data[0];

		// Top-level keys per FR-013.
		foreach ( array( 'server_id', 'server_slug', 'server_name', 'description', 'transports' ) as $key ) {
			$this->assertArrayHasKey( $key, $entry, "Top-level key `{$key}` MUST be present." );
		}

		$this->assertIsInt( $entry['server_id'] );
		$this->assertIsString( $entry['server_slug'] );
		$this->assertIsString( $entry['server_name'] );
		$this->assertIsString( $entry['description'] );
		$this->assertIsArray( $entry['transports'] );

		// Transport keys.
		foreach ( $entry['transports'] as $transport ) {
			foreach ( array( 'key', 'label', 'priority', 'dtos' ) as $key ) {
				$this->assertArrayHasKey( $key, $transport, "Transport key `{$key}` MUST be present." );
			}
			$this->assertIsString( $transport['key'] );
			$this->assertIsString( $transport['label'] );
			$this->assertIsInt( $transport['priority'] );
			$this->assertIsArray( $transport['dtos'] );

			// DTO keys.
			foreach ( $transport['dtos'] as $dto ) {
				foreach ( array( 'slug', 'name', 'icon', 'description', 'meta' ) as $key ) {
					$this->assertArrayHasKey( $key, $dto, "DTO key `{$key}` MUST be present." );
				}
				$this->assertIsString( $dto['slug'] );
				$this->assertIsString( $dto['name'] );
				$this->assertIsString( $dto['icon'] );
				$this->assertIsString( $dto['description'] );
				$this->assertIsArray( $dto['meta'] );
			}
		}
	}

	// ────────────────────────────────────────────────────────────────
	// T018 — Fake fourth transport surfaces automatically (SC-005)
	// ────────────────────────────────────────────────────────────────

	public function test_fake_fourth_transport_surfaces_with_zero_f038_changes(): void {
		// Register the fake fourth transport (companion-plugin path).
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $classes ): array {
				$classes[] = FakeFourthTransport::class;
				return $classes;
			}
		);
		AbstractEmbedTransport::flush_cache();

		$server_id = $this->seed_server( 'srv-fourth', 'Fourth Server' );
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'test-fourth-transport' => array( 'test-dto' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$renderer = new class() extends AbstractUserServersRenderer {};
		$data     = $renderer->get_accessible_servers( $this->user_id );

		$this->assertCount( 1, $data );

		$transport_keys = array_column( $data[0]['transports'], 'key' );
		$this->assertContains(
			'test-fourth-transport',
			$transport_keys,
			'Fake fourth transport MUST surface in the F038 payload with zero F038 code changes.'
		);

		// Find the transport entry and verify its DTO.
		$fourth = null;
		foreach ( $data[0]['transports'] as $t ) {
			if ( 'test-fourth-transport' === $t['key'] ) {
				$fourth = $t;
				break;
			}
		}
		$this->assertNotNull( $fourth );
		$this->assertCount( 1, $fourth['dtos'] );
		$this->assertSame( 'test-dto', $fourth['dtos'][0]['slug'] );
		$this->assertSame( 'Test DTO', $fourth['dtos'][0]['name'] );
	}
}

// ────────────────────────────────────────────────────────────────
// Fake fourth transport fixture (SC-005 proof)
// ────────────────────────────────────────────────────────────────

final class FakeFourthTransport extends AbstractEmbedTransport {

	public function get_transport_key(): string {
		return 'test-fourth-transport';
	}

	public function get_checkbox_label(): string {
		return 'Test Fourth';
	}

	public function get_priority(): int {
		return 40;
	}

	public function get_dtos(): array {
		return array(
			array(
				'slug'        => 'test-dto',
				'name'        => 'Test DTO',
				'icon'        => '🧪',
				'description' => 'For fourth-transport surfacing tests only.',
				'meta'        => array(),
			),
		);
	}
}
