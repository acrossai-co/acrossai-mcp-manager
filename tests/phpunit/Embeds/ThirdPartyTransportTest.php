<?php
/**
 * F037 T029-R — Third-party transport extensibility E2E (post-Pivots A + B).
 *
 * Rescoped from the original T029 (pre-Pivots — 3-method contract) to cover
 * the shipped 5-method contract:
 *   1. get_transport_key()   (abstract; required)
 *   2. get_checkbox_label()  (abstract; required)
 *   3. get_priority()        (default 100; recommended override)
 *   4. get_storage_key()     (default = transport_key; override for storage alias per Pivot B)
 *   5. get_dtos()            (default []; override to expose DTOs per Pivot A)
 *   + is_single_item()       (default false; override to `true` for single-DTO shorthand)
 *
 * Covers:
 *   - User Story 2 acceptance scenarios 1–3
 *   - SC-003 (revised) — third-party transport in ≤35 LOC
 *   - SC-009 — orphan survival + GC helper idempotency
 *   - Pivot B — storage-key aliasing round-trips correctly
 *
 * Runs under the `embeds` suite with WP bootstrap.
 *
 * @package AcrossAI_MCP_Manager\Tests\Embeds
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Embeds;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use WP_UnitTestCase;

/**
 * Third-party transport fixture — the shape a companion plugin author would
 * write. Overrides all 5 recommended methods (get_transport_key/label/
 * priority/storage_key/dtos). Uses a storage-key alias to exercise Pivot B.
 */
final class FakeThirdPartyTransport extends AbstractEmbedTransport {

	public function get_transport_key(): string {
		return 'buddyboss-profile';
	}

	public function get_checkbox_label(): string {
		return 'BuddyPress profile MCP badge';
	}

	public function get_priority(): int {
		return 40;
	}

	public function get_storage_key(): string {
		return 'bb-profiles'; // Alias — differs from transport_key to exercise the aliasing pathway.
	}

	public function get_dtos(): array {
		return array(
			array(
				'slug' => 'badge-primary',
				'name' => 'Primary Badge',
				'icon' => '🏅',
			),
			array(
				'slug' => 'badge-mini',
				'name' => 'Mini Badge',
				'icon' => '🎖️',
			),
		);
	}
}

/**
 * Second fixture for the single-item shorthand shape (Pivot A).
 */
final class SingleItemFakeTransport extends AbstractEmbedTransport {

	public function get_transport_key(): string {
		return 'single-fake';
	}

	public function get_checkbox_label(): string {
		return 'Single Fake';
	}

	public function get_priority(): int {
		return 45;
	}

	public function is_single_item(): bool {
		return true;
	}

	public function get_dtos(): array {
		return array(
			array( 'slug' => 'the-one-dto', 'name' => 'The One', 'icon' => '' ),
		);
	}
}

final class ThirdPartyTransportTest extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private $server_id = 1;

	protected function setUp(): void {
		parent::setUp();
		AbstractEmbedTransport::flush_cache();
	}

	protected function tearDown(): void {
		ServerMetaQuery::delete_by_server_id( $this->server_id );
		AbstractEmbedTransport::flush_cache();
		remove_all_filters( 'acrossai_mcp_embed_transports' );
		parent::tearDown();
	}

	private function register_fake(): void {
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $fqns ): array {
				$fqns[] = FakeThirdPartyTransport::class;
				return $fqns;
			}
		);
	}

	// ── Enumeration ────────────────────────────────────────────────────

	public function test_third_party_transport_appears_in_enumeration(): void {
		$this->register_fake();

		$transports = AbstractEmbedTransport::get_all_registered_transports();
		$keys       = array_map( static fn( $t ) => $t->get_transport_key(), $transports );

		$this->assertContains( 'buddyboss-profile', $keys );
		// Priority 40 puts it AFTER ai_connector (30) and before default third-party (100).
		$position = array_search( 'buddyboss-profile', $keys, true );
		$this->assertSame( 3, $position, 'Priority-40 transport MUST land at index 3 (after the 3 built-ins).' );
	}

	public function test_meta_for_resolves_storage_key_alias(): void {
		$this->register_fake();

		$meta = AbstractEmbedTransport::meta_for( 'buddyboss-profile' );

		$this->assertSame( 'bb-profiles', $meta['storage_key'], 'get_storage_key() override MUST propagate through meta_for().' );
		$this->assertFalse( $meta['is_single'], 'Default is_single_item() is false.' );
	}

	public function test_meta_for_unknown_key_falls_through(): void {
		// No filter registration — 'buddyboss-profile' is not registered.
		$meta = AbstractEmbedTransport::meta_for( 'not-registered' );

		$this->assertSame( 'not-registered', $meta['storage_key'], 'Unknown key falls through to itself.' );
		$this->assertFalse( $meta['is_single'] );
	}

	// ── Round-trip via storage-key alias ───────────────────────────────

	public function test_persisted_slug_via_alias_key_gates_correctly(): void {
		$this->register_fake();

		// Master ON + persist Primary Badge under the 'bb-profiles' alias.
		ServerMetaQuery::update_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );
		AbstractEmbedTransport::save_items_for_server(
			$this->server_id,
			array( 'bb-profiles' => array( 'badge-primary' ) )
		);

		$this->assertTrue(
			AbstractEmbedTransport::is_enabled_for_server( $this->server_id, 'buddyboss-profile', 'badge-primary' ),
			'is_enabled_for_server MUST resolve the alias via meta_for().'
		);
		$this->assertFalse(
			AbstractEmbedTransport::is_enabled_for_server( $this->server_id, 'buddyboss-profile', 'badge-mini' ),
			'Slug not in the array MUST return false.'
		);
	}

	// ── Single-item shorthand (Pivot A) ────────────────────────────────

	public function test_single_item_transport_uses_int_shorthand(): void {
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $fqns ): array {
				$fqns[] = SingleItemFakeTransport::class;
				return $fqns;
			}
		);

		// Master ON + persist as int 1 under the transport's default storage_key.
		ServerMetaQuery::update_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );
		AbstractEmbedTransport::save_items_for_server(
			$this->server_id,
			array( 'single-fake' => 1 )
		);

		$this->assertTrue(
			AbstractEmbedTransport::is_enabled_for_server( $this->server_id, 'single-fake', 'the-one-dto' ),
			'Single-item transport MUST resolve int 1 as ON for every dto_slug.'
		);
		$this->assertTrue(
			AbstractEmbedTransport::is_enabled_for_server( $this->server_id, 'single-fake', 'any-other-slug' ),
			'Single-item shorthand covers every slug in the category.'
		);
	}

	// ── Orphan survival + GC (SC-009) ──────────────────────────────────

	public function test_orphan_row_survives_deregistration(): void {
		$this->register_fake();

		// Save state with the third-party transport enabled.
		ServerMetaQuery::update_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );
		AbstractEmbedTransport::save_items_for_server(
			$this->server_id,
			array(
				'mcp-client'  => array( 'claude-desktop' ),
				'bb-profiles' => array( 'badge-primary' ),
			)
		);

		// Deregister the third-party transport (mimic plugin deactivation).
		remove_all_filters( 'acrossai_mcp_embed_transports' );
		AbstractEmbedTransport::flush_cache();

		// Enumeration MUST NOT contain the deregistered key.
		$keys = array_map(
			static fn( $t ) => $t->get_transport_key(),
			AbstractEmbedTransport::get_all_registered_transports()
		);
		$this->assertNotContains( 'buddyboss-profile', $keys );

		// The orphan row MUST survive per FR-022 persist-silently.
		$items = AbstractEmbedTransport::get_items_for_server( $this->server_id );
		$this->assertArrayHasKey( 'bb-profiles', $items, 'Orphan category key MUST survive deregistration.' );
		$this->assertContains( 'badge-primary', $items['bb-profiles'] );

		// The known category should also survive intact.
		$this->assertContains( 'claude-desktop', $items['mcp-client'] );
	}

	public function test_garbage_collect_prunes_orphan_category_and_is_idempotent(): void {
		$this->register_fake();

		ServerMetaQuery::update_meta( $this->server_id, AbstractEmbedTransport::META_KEY_MASTER, '1' );
		AbstractEmbedTransport::save_items_for_server(
			$this->server_id,
			array(
				'mcp-client'  => array( 'claude-desktop' ),
				'bb-profiles' => array( 'badge-primary' ),
			)
		);

		// Deregister third-party then GC.
		remove_all_filters( 'acrossai_mcp_embed_transports' );
		AbstractEmbedTransport::flush_cache();

		$pruned = AbstractEmbedTransport::garbage_collect_orphans();
		$this->assertSame( 1, $pruned, 'GC MUST prune exactly 1 orphan category key.' );

		// Idempotent — second call returns 0.
		$this->assertSame( 0, AbstractEmbedTransport::garbage_collect_orphans() );

		// Known category survives; orphan pruned.
		$items = AbstractEmbedTransport::get_items_for_server( $this->server_id );
		$this->assertArrayHasKey( 'mcp-client', $items );
		$this->assertArrayNotHasKey( 'bb-profiles', $items );
	}

	// ── Contract shape sanity ──────────────────────────────────────────

	public function test_fake_transport_has_all_5_methods(): void {
		$this->register_fake();

		$transports = AbstractEmbedTransport::get_all_registered_transports();
		$fake       = null;
		foreach ( $transports as $t ) {
			if ( 'buddyboss-profile' === $t->get_transport_key() ) {
				$fake = $t;
				break;
			}
		}
		$this->assertNotNull( $fake );
		$this->assertSame( 'buddyboss-profile', $fake->get_transport_key() );
		$this->assertSame( 'BuddyPress profile MCP badge', $fake->get_checkbox_label() );
		$this->assertSame( 40, $fake->get_priority() );
		$this->assertSame( 'bb-profiles', $fake->get_storage_key() );
		$this->assertFalse( $fake->is_single_item() );
		$this->assertCount( 2, $fake->get_dtos() );
	}
}
