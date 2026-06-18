<?php
/**
 * @package AcrossAI_MCP_Manager\Tests\MCPClients
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\MCPClients;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AbstractMCPClient helpers + the get_all_clients() factory.
 *
 * Per SC-003 this entire suite runs WITHOUT bootstrapping WordPress —
 * proof that the MCPClients module is pure service layer (FR-008).
 */
final class AbstractMCPClientTest extends TestCase {

	private function newSubject(): AbstractMCPClient {
		// Anonymous concrete subclass for invoking protected helpers.
		return new class() extends AbstractMCPClient {
			public function get_client_slug(): string {
				return 'test-stub';
			}
			public function get_client_name(): string {
				return 'Test Stub';
			}
			public function get_config_snippet( string $server_url, string $auth_token ) {
				return array(
					'url'   => $server_url,
					'token' => $this->safe_token( $auth_token ),
				);
			}
			// Expose protected helpers for testing.
			public function exposeBuildServerUrl( string $base, string $ns, string $route ): string {
				return $this->build_server_url( $base, $ns, $route );
			}
			public function exposeDeriveServerKey( string $url ): string {
				return $this->derive_server_key( $url );
			}
			public function exposeSafeToken( string $token ): string {
				return $this->safe_token( $token );
			}
			public function exposeRedactToken( string $token ): string {
				return $this->redact_token( $token );
			}
		};
	}

	// ─── derive_server_key matrix (research.md R2) ──────────────────────────

	public static function deriveServerKeyMatrix(): array {
		return array(
			'empty url returns fallback'        => array( '', 'wordpress-mcp' ),
			'full rest url returns last segment' => array( 'https://example.com/wp-json/mcp/foo', 'foo' ),
			'trailing slash stripped'           => array( 'https://example.com/wp-json/mcp/foo/', 'foo' ),
			'query string stripped'             => array( 'https://example.com/wp-json/mcp/foo?x=1', 'foo' ),
			'bare slug accepted'                => array( 'foo', 'foo' ),
			// research.md R2 wart: host-only URLs return the host as the
			// key (the host IS the last path segment). Acceptable per spec.
			'host-only with slash returns host' => array( 'https://example.com/', 'example.com' ),
			'host-only bare returns host'       => array( 'example.com', 'example.com' ),
		);
	}

	#[DataProvider('deriveServerKeyMatrix')]
	public function testDeriveServerKey( string $url, string $expected ): void {
		$this->assertSame( $expected, $this->newSubject()->exposeDeriveServerKey( $url ) );
	}

	// ─── safe_token ─────────────────────────────────────────────────────────

	public function testSafeTokenReturnsPlaceholderOnEmpty(): void {
		$this->assertSame(
			'(paste generated password here)',
			$this->newSubject()->exposeSafeToken( '' )
		);
	}

	public function testSafeTokenReturnsRawOnNonEmpty(): void {
		$this->assertSame( 'xyz', $this->newSubject()->exposeSafeToken( 'xyz' ) );
		$this->assertSame(
			'abcd EFGH ijkl MNOP',
			$this->newSubject()->exposeSafeToken( 'abcd EFGH ijkl MNOP' )
		);
	}

	// ─── redact_token ───────────────────────────────────────────────────────

	public function testRedactTokenFirstFourLastTwo(): void {
		// 'abcdef5678' → 'abcd' + '…' + '78'
		$this->assertSame( 'abcd…78', $this->newSubject()->exposeRedactToken( 'abcdef5678' ) );
	}

	public function testRedactTokenEmptyReturnsEmptyMarker(): void {
		$this->assertSame( '(empty)', $this->newSubject()->exposeRedactToken( '' ) );
	}

	public function testRedactTokenShortInputDoesNotCrash(): void {
		// 4-char token: prefix is the whole thing, suffix is last 2 chars → overlap.
		// Just assert it doesn't throw and returns something non-empty.
		$result = $this->newSubject()->exposeRedactToken( 'abcd' );
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	// ─── build_server_url ───────────────────────────────────────────────────

	public function testBuildServerUrlConcatenatesPathsCorrectly(): void {
		$s = $this->newSubject();
		$this->assertSame(
			'https://example.com/wp-json/mcp/foo',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json', 'mcp', 'foo' )
		);
		$this->assertSame(
			'https://example.com/wp-json/mcp/foo',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json/', '/mcp/', '/foo/' )
		);
	}

	public function testBuildServerUrlHandlesEmptyNamespaceOrRoute(): void {
		$s = $this->newSubject();
		$this->assertSame(
			'https://example.com/wp-json/mcp',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json', 'mcp', '' )
		);
		$this->assertSame(
			'https://example.com/wp-json/foo',
			$s->exposeBuildServerUrl( 'https://example.com/wp-json', '', 'foo' )
		);
	}

	// ─── get_all_clients factory ────────────────────────────────────────────

	public function testGetAllClientsExcludesAbstractClass(): void {
		$clients = AbstractMCPClient::get_all_clients();
		foreach ( $clients as $client ) {
			$this->assertNotInstanceOf( \ReflectionClass::class, $client );
			$reflection = new \ReflectionClass( $client );
			$this->assertFalse(
				$reflection->isAbstract(),
				'get_all_clients() returned an instance of an abstract class: ' . $reflection->getName()
			);
			$this->assertNotSame( AbstractMCPClient::class, $reflection->getName() );
		}
	}

	public function testGetAllClientsReturnsAbstractMCPClientInstances(): void {
		foreach ( AbstractMCPClient::get_all_clients() as $client ) {
			$this->assertInstanceOf( AbstractMCPClient::class, $client );
		}
	}

	public function testGetAllClientsReturnsExactlySevenClients(): void {
		// Phase 4 FR-004 enumerates 7 concrete clients. This count is the
		// load-bearing canary: adding an 8th (e.g. Windsurf) means
		// updating this assertion.
		$this->assertCount( 7, AbstractMCPClient::get_all_clients() );
	}

	public function testGetAllClientSlugsAreUnique(): void {
		$slugs = array_map(
			static fn( AbstractMCPClient $c ) => $c->get_client_slug(),
			AbstractMCPClient::get_all_clients()
		);
		$this->assertSame(
			array_unique( $slugs ),
			$slugs,
			'Two MCPClient subclasses returned the same slug — violates FR-007 uniqueness invariant.'
		);
	}
}
