<?php
/**
 * F034 — canonical filter-aware enumeration coverage.
 *
 * Replaces the pre-F034 glob-based get_all_clients() tests. This suite exercises
 * the SEC-013-008 invalid-FQN-silent-skip semantic, slug regex validation,
 * dedup-by-slug (later-wins) semantic, and (priority ASC, slug ASC) sort order.
 *
 * Per SC-003 the suite runs WITHOUT bootstrapping WordPress; a lightweight
 * apply_filters + _doing_it_wrong stub in tests/bootstrap.php provides the
 * two WP core symbols this test uses.
 *
 * @package AcrossAI_MCP_Manager\Tests\MCPClients
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\MCPClients;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use PHPUnit\Framework\TestCase;

// Named fake subclasses used by filter-contribution tests. Must live at the top
// of the file so the FQNs `\AcrossAI_MCP_Manager\Tests\MCPClients\<X>` resolve
// during the filter's class_exists() check.

/** Valid third-party subclass — sorts among defaults per priority 45. */
final class FakeInterleavedClient extends AbstractMCPClient {
	public function get_client_slug(): string {
		return 'fake-interleaved';
	}
	public function get_client_name(): string {
		return 'Fake Interleaved';
	}
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array();
	}
	public function get_priority(): int {
		return 45; // Between GitHubCopilot (40) and Codex (50).
	}
}

/** Valid third-party subclass — takes default priority 100 (appended after built-ins). */
final class FakeDefaultPriorityClient extends AbstractMCPClient {
	public function get_client_slug(): string {
		return 'fake-default-priority';
	}
	public function get_client_name(): string {
		return 'Fake Default Priority';
	}
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array();
	}
}

/** Second default-priority subclass to prove alphabetical slug tiebreaker among same-priority peers. */
final class FakeAnotherDefaultClient extends AbstractMCPClient {
	public function get_client_slug(): string {
		return 'aaa-another-default';
	}
	public function get_client_name(): string {
		return 'AAA Another Default';
	}
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array();
	}
}

/** Subclass with a bad (uppercase) slug — triggers _doing_it_wrong + skip. */
final class FakeBadSlugClient extends AbstractMCPClient {
	public function get_client_slug(): string {
		return 'BAD_SLUG'; // Uppercase + underscore — fails /\A[a-z0-9-]{1,64}\z/.
	}
	public function get_client_name(): string {
		return 'Bad Slug';
	}
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array();
	}
}

/** Subclass with an empty slug — triggers _doing_it_wrong + skip. */
final class FakeEmptySlugClient extends AbstractMCPClient {
	public function get_client_slug(): string {
		return '';
	}
	public function get_client_name(): string {
		return 'Empty Slug';
	}
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array();
	}
}

/** Subclass reusing an existing built-in slug — triggers duplicate-slug _doing_it_wrong; later-wins. */
final class FakeDuplicateGeminiClient extends AbstractMCPClient {
	public function get_client_slug(): string {
		return 'gemini'; // Same slug as the built-in GeminiClient.
	}
	public function get_client_name(): string {
		return 'Fake Duplicate Gemini';
	}
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array();
	}
}

final class GetAllRegisteredClientsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset the pure-PHP apply_filters + _doing_it_wrong stubs between tests
		// so filter contributions don't leak. The stubs are defined in
		// tests/bootstrap.php per FR-008 SC-003.
		if ( function_exists( 'acrossai_test_reset_filters' ) ) {
			acrossai_test_reset_filters();
		}
	}

	/**
	 * F034 FR-006 default state — DEFAULT_CLIENT_CLASSES seed passes through
	 * the filter untouched. Result: 16 built-in slugs in priority order.
	 *
	 * Priority table:
	 *   Original set (F034):
	 *     claude-desktop=10, claude-code=20, vscode=30, github-copilot=40,
	 *     codex=50, cursor=60, gemini=70
	 *   Extended set (added post-F034 to close the gap against competing
	 *   WordPress MCP plugins for free-tier IDE users; the 5 acrossai-pro
	 *   OAuth connectors — Claude / ChatGPT / Grok / Gemini / Cursor — are
	 *   NOT duplicated here since those ship as one-click OAuth in the paid
	 *   companion, not as JSON-config generators):
	 *     windsurf=72, zed=73, cline=74, roo-code=75, kilo-code=76,
	 *     amazon-q=77, opencode=78, antigravity=79
	 *   Fallback:
	 *     custom=80
	 */
	public function testDefaultStateReturnsBuiltinsInPriorityOrder(): void {
		$clients = AbstractMCPClient::get_all_registered_clients();
		$this->assertCount( 16, $clients, 'MUST return exactly 16 built-in clients.' );

		$slugs = array_map(
			static fn( AbstractMCPClient $c ): string => $c->get_client_slug(),
			$clients
		);
		$this->assertSame(
			array(
				'claude-desktop',
				'claude-code',
				'vscode',
				'github-copilot',
				'codex',
				'cursor',
				'gemini',
				'windsurf',
				'zed',
				'cline',
				'roo-code',
				'kilo-code',
				'amazon-q',
				'opencode',
				'antigravity',
				'custom',
			),
			$slugs,
			'Built-in sub-nav order MUST be priority-sorted (10, 20, 30, ..., 70, 72, 73, ..., 79, 80).'
		);
	}

	/**
	 * F034 FR-006 + FR-010 — filter contribution with explicit priority 45
	 * interleaves between GitHubCopilot (40) and Codex (50).
	 */
	public function testFilterContributionWithExplicitPriorityInterleaves(): void {
		add_filter(
			'acrossai_mcp_client_classes',
			static function ( array $fqns ): array {
				$fqns[] = FakeInterleavedClient::class;
				return $fqns;
			}
		);

		$slugs = array_map(
			static fn( AbstractMCPClient $c ): string => $c->get_client_slug(),
			AbstractMCPClient::get_all_registered_clients()
		);

		$this->assertContains( 'fake-interleaved', $slugs );
		$this->assertSame(
			array( 'claude-desktop', 'claude-code', 'vscode', 'github-copilot', 'fake-interleaved', 'codex', 'cursor', 'gemini', 'custom' ),
			$slugs,
			'Priority 45 subclass MUST sort between github-copilot (40) and codex (50).'
		);
	}

	/**
	 * F034 FR-010 — default priority 100 subclasses sort AFTER all built-ins.
	 * When two default-priority contributions coexist, alphabetical slug is the tiebreaker.
	 */
	public function testDefaultPriorityContributionsSortAfterBuiltinsWithSlugTiebreaker(): void {
		add_filter(
			'acrossai_mcp_client_classes',
			static function ( array $fqns ): array {
				$fqns[] = FakeDefaultPriorityClient::class;   // slug 'fake-default-priority'
				$fqns[] = FakeAnotherDefaultClient::class;     // slug 'aaa-another-default'
				return $fqns;
			}
		);

		$slugs = array_map(
			static fn( AbstractMCPClient $c ): string => $c->get_client_slug(),
			AbstractMCPClient::get_all_registered_clients()
		);

		// Built-ins first (priorities 10-80), then defaults sorted alphabetically among themselves.
		$this->assertSame(
			array(
				'claude-desktop', 'claude-code', 'vscode', 'github-copilot',
				'codex', 'cursor', 'gemini', 'custom',
				'aaa-another-default',  // slug tiebreaker: 'aaa' < 'fake'
				'fake-default-priority',
			),
			$slugs
		);
	}

	/**
	 * F034 FR-007 + SEC-013-008 — invalid FQNs silently skipped (no fatal, no
	 * _doing_it_wrong for FQN-shape validation, per matching current
	 * MCPClientsBlock::render_body behaviour).
	 */
	public function testInvalidFqnsAreSilentlySkipped(): void {
		add_filter(
			'acrossai_mcp_client_classes',
			static function ( array $fqns ): array {
				$fqns[] = 42;                                        // Non-string.
				$fqns[] = 'ThisClassDefinitelyDoesNotExist_xyz';     // Missing class.
				$fqns[] = \stdClass::class;                          // Not extending AbstractMCPClient.
				return $fqns;
			}
		);

		$clients = AbstractMCPClient::get_all_registered_clients();
		$this->assertCount( 16, $clients, 'Invalid FQNs MUST be silently skipped; result stays at the 16 built-ins.' );
	}

	/**
	 * F034 FR-008 — bad slug (empty, uppercase, underscore, >64 chars) skips
	 * the subclass and fires _doing_it_wrong under WP_DEBUG.
	 */
	public function testBadSlugContributionIsSkipped(): void {
		add_filter(
			'acrossai_mcp_client_classes',
			static function ( array $fqns ): array {
				$fqns[] = FakeBadSlugClient::class;    // 'BAD_SLUG'
				$fqns[] = FakeEmptySlugClient::class;  // ''
				return $fqns;
			}
		);

		$slugs = array_map(
			static fn( AbstractMCPClient $c ): string => $c->get_client_slug(),
			AbstractMCPClient::get_all_registered_clients()
		);
		$this->assertCount( 16, $slugs, 'Bad slugs MUST be rejected; result stays at the 16 built-ins.' );
		$this->assertNotContains( 'BAD_SLUG', $slugs );
		$this->assertNotContains( '', $slugs );
	}

	/**
	 * F034 FR-009 — duplicate slug: later contribution wins the slot.
	 * The built-in GeminiClient (priority 70) is REPLACED by the fake
	 * duplicate at the same sub-nav position (still priority 70 since the
	 * fake default is 100 but shares the 'gemini' slug — wait, dedup happens
	 * BEFORE sort, so the later-wins fake keeps its OWN priority = 100.
	 * Net result: 'gemini' appears at the appended position, not slot 7.
	 */
	public function testDuplicateSlugLaterWinsAndTakesOverrideClassesPriority(): void {
		add_filter(
			'acrossai_mcp_client_classes',
			static function ( array $fqns ): array {
				$fqns[] = FakeDuplicateGeminiClient::class; // slug 'gemini', priority default 100
				return $fqns;
			}
		);

		$clients = AbstractMCPClient::get_all_registered_clients();
		$this->assertCount( 16, $clients, 'Dedup MUST keep count at 16 (the built-in seed), not 17 — the fake duplicate takes over the gemini slot rather than being appended.' );

		// Find the 'gemini' entry — should now be the fake, not the built-in.
		$gemini_entries = array_filter(
			$clients,
			static fn( AbstractMCPClient $c ): bool => 'gemini' === $c->get_client_slug()
		);
		$this->assertCount( 1, $gemini_entries );
		$gemini = reset( $gemini_entries );
		$this->assertInstanceOf( FakeDuplicateGeminiClient::class, $gemini, 'Later-wins: fake takes over the gemini slot.' );

		// Fake's priority is 100 (default), so the gemini slot moves to the end.
		$slugs = array_map(
			static fn( AbstractMCPClient $c ): string => $c->get_client_slug(),
			$clients
		);
		$this->assertSame( 'gemini', end( $slugs ), 'Fake gemini (priority 100) sorts to the end, past built-in custom (80).' );
	}
}
