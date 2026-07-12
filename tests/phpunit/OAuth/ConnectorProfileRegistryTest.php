<?php
/**
 * ConnectorProfileRegistry — filter contribution, dedup, sort, memoization.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\OAuth;

use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;

/**
 * @coversNothing
 */
class ConnectorProfileRegistryTest extends OAuthTestCase {

	public function test_empty_filter_returns_empty_array(): void {
		$this->assertSame( array(), ConnectorProfileRegistry::instance()->get_profiles() );
	}

	public function test_single_profile_registers(): void {
		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge( $p, array( new StubProfile( 'claude-desktop', 'Claude Desktop' ) ) )
		);

		$profiles = ConnectorProfileRegistry::instance()->get_profiles();

		$this->assertCount( 1, $profiles );
		$this->assertSame( 'claude-desktop', $profiles[0]->get_slug() );
	}

	public function test_duplicate_slug_later_wins(): void {
		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge(
				$p,
				array(
					new StubProfile( 'chatgpt', 'First Name' ),
					new StubProfile( 'chatgpt', 'Later Wins' ),
				)
			)
		);

		$profiles = ConnectorProfileRegistry::instance()->get_profiles();

		$this->assertCount( 1, $profiles );
		$this->assertSame( 'Later Wins', $profiles[0]->get_name() );
	}

	public function test_profiles_sorted_by_slug(): void {
		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge(
				$p,
				array(
					new StubProfile( 'gemini', 'Gemini' ),
					new StubProfile( 'claude-desktop', 'Claude Desktop' ),
					new StubProfile( 'chatgpt', 'ChatGPT' ),
				)
			)
		);

		$slugs = array_map(
			static fn ( AbstractConnectorProfile $p ) => $p->get_slug(),
			ConnectorProfileRegistry::instance()->get_profiles()
		);

		$this->assertSame( array( 'chatgpt', 'claude-desktop', 'gemini' ), $slugs );
	}

	public function test_filter_fires_once_across_many_calls(): void {
		$fire_count = 0;
		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static function ( array $p ) use ( &$fire_count ) {
				++$fire_count;
				return $p;
			}
		);

		for ( $i = 0; $i < 100; $i++ ) {
			ConnectorProfileRegistry::instance()->get_profiles();
		}

		$this->assertSame( 1, $fire_count, 'FR-030 memoization violated' );
	}

	public function test_non_abstract_profile_discarded(): void {
		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge(
				$p,
				array(
					new \stdClass(),
					new StubProfile( 'claude-desktop', 'Claude Desktop' ),
				)
			)
		);

		$profiles = ConnectorProfileRegistry::instance()->get_profiles();

		$this->assertCount( 1, $profiles );
	}

	public function test_get_profile_by_slug(): void {
		add_filter(
			'acrossai_mcp_manager_connector_profiles',
			static fn ( array $p ) => array_merge( $p, array( new StubProfile( 'claude-desktop', 'Claude' ) ) )
		);

		$this->assertNotNull( ConnectorProfileRegistry::instance()->get_profile( 'claude-desktop' ) );
		$this->assertNull( ConnectorProfileRegistry::instance()->get_profile( 'nope' ) );
	}
}

/**
 * Minimal in-test stub — abstract methods only.
 */
final class StubProfile extends AbstractConnectorProfile {

	public function __construct( private string $slug, private string $name ) {
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_icon_url(): string {
		return 'https://example.com/icon.svg';
	}

	public function get_redirect_uri_whitelist(): array {
		return array( 'https://example.com/callback' );
	}

	public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
		return '<p>setup</p>';
	}

	public function render_tab_section( array $server ): void {
		echo 'rendered';
	}
}
