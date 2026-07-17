<?php
/**
 * Tests for AddonsFilter — drops this plugin's own slug from the
 * `acrossai_addons` list rendered on the shared Add-ons page.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin;

use AcrossAI_MCP_Manager\Admin\Partials\AddonsFilter;
use WP_UnitTestCase;

final class AddonsFilterTest extends WP_UnitTestCase {

	public function test_remove_self_strips_own_slug_and_reindexes(): void {
		$input = array(
			array( 'slug' => 'acrossai-abilities-manager', 'name' => 'A' ),
			array( 'slug' => 'acrossai-mcp-manager',       'name' => 'Self' ),
			array( 'slug' => 'acrossai-model-manager',     'name' => 'M' ),
		);

		$result = AddonsFilter::instance()->remove_self( $input );

		$this->assertCount( 2, $result );
		$this->assertSame( array( 0, 1 ), array_keys( $result ) );
		foreach ( $result as $addon ) {
			$this->assertNotSame( 'acrossai-mcp-manager', $addon['slug'] );
		}
	}

	public function test_remove_self_is_noop_when_own_slug_absent(): void {
		$input = array(
			array( 'slug' => 'acrossai-abilities-manager', 'name' => 'A' ),
			array( 'slug' => 'turn-off-ai-features',       'name' => 'T' ),
		);

		$this->assertSame( $input, AddonsFilter::instance()->remove_self( $input ) );
	}

	public function test_remove_self_normalizes_non_array_input(): void {
		$this->assertSame( array(), AddonsFilter::instance()->remove_self( null ) );
		$this->assertSame( array(), AddonsFilter::instance()->remove_self( 'oops' ) );
		$this->assertSame( array(), AddonsFilter::instance()->remove_self( false ) );
	}

	public function test_remove_self_drops_non_array_entries(): void {
		$input = array(
			array( 'slug' => 'acrossai-abilities-manager', 'name' => 'A' ),
			'not-an-array',
			array( 'slug' => 'acrossai-mcp-manager',       'name' => 'Self' ),
		);

		$result = AddonsFilter::instance()->remove_self( $input );

		$this->assertCount( 1, $result );
		$this->assertSame( 'acrossai-abilities-manager', $result[0]['slug'] );
	}
}
