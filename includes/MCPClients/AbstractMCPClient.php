<?php
/**
 * Abstract base class for all MCP client definitions.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

defined( 'ABSPATH' ) || exit;

/**
 * Pure service layer — each concrete subclass produces a copy-paste
 * configuration snippet for one AI tool, given a server URL and an
 * Application Password.
 *
 * Constitutional invariants (FR-008, FR-009):
 *   - No WordPress hooks (no add_action / add_filter anywhere in this module).
 *   - No DB / HTTP / cookies / global state.
 *   - No singleton pattern — instances are stateless and interchangeable.
 *   - Tests run WITHOUT WordPress bootstrap (SC-003).
 *
 * The singleton exemption is justified parallel to A10 (WP_List_Table
 * subclasses): different rationale (no instance state to share), same
 * outcome (not every class in the codebase is a singleton).
 * See docs/memory/INDEX.md A2 vs FR-009 soft exemption note.
 */
abstract class AbstractMCPClient {

	/**
	 * Empty-token placeholder. When the caller hasn't yet generated an
	 * Application Password, the snippet renders this text in the token
	 * slot so the user sees a self-documenting gap rather than a
	 * silently-broken config (Q2 clarification 2026-06-17).
	 */
	public const EMPTY_TOKEN_PLACEHOLDER = '(paste generated password here)';

	/**
	 * Fallback server-key when derive_server_key() can't extract a usable
	 * path segment from the URL.
	 */
	public const SERVER_KEY_FALLBACK = 'wordpress-mcp';

	// ─────────────────────────────────────────────────────────────────────────
	// Abstract contract (FR-001).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Unique machine-readable identifier (kebab-case, lowercase, ASCII).
	 *
	 * @return string e.g. 'claude-desktop'
	 */
	abstract public function get_client_slug(): string;

	/**
	 * Human-readable name as the AI tool markets itself.
	 *
	 * @return string e.g. 'Claude Desktop'
	 */
	abstract public function get_client_name(): string;

	/**
	 * The copy-paste payload the user pastes into their AI tool.
	 *
	 * Return-type union (string|array) reflects per-client format
	 * choices: JSON-config tools return arrays; CLI-install tools
	 * return strings. The consumer differentiates via `is_array()`.
	 *
	 * MUST embed both $server_url and $auth_token; never hardcode URLs;
	 * never read env vars or options for the token. When $auth_token is
	 * empty, the token slot MUST render EMPTY_TOKEN_PLACEHOLDER (via
	 * safe_token()) rather than an empty string.
	 *
	 * @param string $server_url Already-sanitized server URL (caller's responsibility).
	 * @param string $auth_token Already-issued Application Password (caller's responsibility).
	 *
	 * @return string|array
	 */
	abstract public function get_config_snippet( string $server_url, string $auth_token );

	// ─────────────────────────────────────────────────────────────────────────
	// Public static factory (FR-010 — V3=both per Q3 2026-06-17).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Discover and instantiate every concrete client in this module.
	 *
	 * Internal mechanism: glob `includes/MCPClients/*.php`, skip the
	 * abstract class itself, autoload each remaining class, instantiate
	 * only when class_exists() AND is_subclass_of() both succeed.
	 *
	 * This preserves SC-002 ("adding a new client = exactly one new
	 * file") — no edits to this factory needed when a new client lands.
	 *
	 * Returned array is sorted by class file name (alphabetical) — most
	 * filesystems return glob() results sorted; we don't re-sort to
	 * preserve OS-native ordering. Consumers wanting a specific order
	 * should sort by `get_client_slug()` themselves.
	 *
	 * @return AbstractMCPClient[]
	 */
	public static function get_all_clients(): array {
		$clients = array();
		$files   = glob( __DIR__ . '/*.php' );
		if ( false === $files ) {
			return $clients;
		}

		foreach ( $files as $file ) {
			$basename = basename( $file, '.php' );
			if ( 'AbstractMCPClient' === $basename ) {
				continue;
			}
			$fqn = __NAMESPACE__ . '\\' . $basename;
			if ( ! class_exists( $fqn ) ) {
				continue;
			}
			if ( ! is_subclass_of( $fqn, self::class ) ) {
				continue;
			}
			$clients[] = new $fqn();
		}

		return $clients;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Protected helpers (FR-002).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Concatenate a base REST URL with a route namespace + route segment.
	 *
	 * Pure string composition — no get_option(), no home_url(), no WP
	 * globals. Caller supplies the base URL (typically `rest_url()` from
	 * the consumer's WP context); this method only joins.
	 *
	 * @param string $base_rest_url   Base URL e.g. 'https://example.com/wp-json/'.
	 * @param string $route_namespace Route namespace, e.g. 'mcp'.
	 * @param string $route           Route path, e.g. 'wordpress-default-server'.
	 *
	 * @return string Composed URL.
	 */
	protected function build_server_url(
		string $base_rest_url,
		string $route_namespace,
		string $route
	): string {
		$base = rtrim( $base_rest_url, '/' );
		$ns   = trim( $route_namespace, '/' );
		$rt   = trim( $route, '/' );
		if ( '' === $ns && '' === $rt ) {
			return $base;
		}
		if ( '' === $ns ) {
			return $base . '/' . $rt;
		}
		if ( '' === $rt ) {
			return $base . '/' . $ns;
		}
		return $base . '/' . $ns . '/' . $rt;
	}

	/**
	 * Extract the inner mcpServers key from a server URL (Q1 2026-06-17).
	 *
	 * Strips query string + trailing slash, takes the last path segment.
	 * Falls back to SERVER_KEY_FALLBACK on empty / unparsable inputs.
	 *
	 * Test matrix in research.md R2.
	 *
	 * @param string $server_url Full server URL.
	 *
	 * @return string Derived server key.
	 */
	protected function derive_server_key( string $server_url ): string {
		$no_query = (string) strtok( $server_url, '?' );
		$no_slash = rtrim( $no_query, '/' );
		if ( '' === $no_slash ) {
			return self::SERVER_KEY_FALLBACK;
		}
		$parts = explode( '/', $no_slash );
		$last  = end( $parts );
		if ( false === $last || '' === $last ) {
			return self::SERVER_KEY_FALLBACK;
		}
		return $last;
	}

	/**
	 * Render the token for snippet output. Empty → placeholder text;
	 * non-empty → verbatim.
	 *
	 * NEVER use this for logs — it returns plaintext. Use redact_token()
	 * for log-safe representation.
	 *
	 * @param string $token Raw Application Password (may be empty).
	 *
	 * @return string Either the token verbatim, or the placeholder.
	 */
	protected function safe_token( string $token ): string {
		return '' === $token ? self::EMPTY_TOKEN_PLACEHOLDER : $token;
	}

	/**
	 * Log-safe token representation. First 4 chars + ellipsis + last 2
	 * chars, or '(empty)' when input is empty.
	 *
	 * Use this ONLY for log lines / debug strings. NEVER use it as the
	 * actual snippet payload (FR-002 security note).
	 *
	 * @param string $token Raw Application Password (may be empty).
	 *
	 * @return string Log-safe redacted representation.
	 */
	protected function redact_token( string $token ): string {
		if ( '' === $token ) {
			return '(empty)';
		}
		// PHP multibyte-safe substr — Application Passwords are ASCII but
		// belt-and-suspenders for arbitrary token strings.
		$prefix = mb_substr( $token, 0, 4 );
		$suffix = mb_substr( $token, -2 );
		return $prefix . '…' . $suffix;
	}
}
