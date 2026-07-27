<?php
/**
 * Public discovery API — unified enumeration of every registered NPM method,
 * MCP client, and AI connector as plain-associative-array DTOs.
 *
 * Feature 035. Motivating consumer: the planned BuddyBoss add-on that needs
 * a single canonical entry point to enumerate connection methods and build
 * per-server allowlist admin UIs + frontend BuddyPress profile rendering.
 *
 * Delegates transparently to F034 (`AbstractMCPClient::get_all_registered_clients`)
 * and F021 (`ConnectorProfileRegistry::get_profiles`). NEVER re-fires either
 * underlying filter — the enforcement gate is spec.md SC-005 grep gate.
 *
 * ## Consumer Security Responsibility
 *
 * DTO string fields (`name`, `description`, `icon`, `meta.*`) are contributed
 * by admin-installed companion plugins and are NOT pre-escaped by this class.
 * If you render these values into an admin page, REST response, or frontend
 * HTML, escape at the render boundary using the most-specific WordPress
 * escaping function (`esc_html()`, `esc_attr()`, `esc_url()` per context).
 * Mirrors F034 SEC-034-001 preservation invariant. See SEC-035-002.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public\Discovery
 * @since      0.1.9
 * @experimental May change without notice before 1.0.0. See
 *               DEC-CLIENT-RENDERER-PUBLIC-API.
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Public\Discovery;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton discovery registry. Six public methods plus `flush_cache()`
 * (documented as a supported surface for test isolation + rare mid-request
 * filter re-registration; production-shape name per B23).
 */
final class ConnectionMethodRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static ?self $_instance = null;

	/**
	 * Memoized `get_all()` result. Null before first fetch. Cleared by
	 * `flush_cache()`.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private ?array $assembled_cache = null;

	/**
	 * Private constructor enforces the A2 singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance (A2).
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Return the assembled three-category discovery result.
	 *
	 * Fires `acrossai_mcp_connection_methods` exactly once per request on
	 * the composed array. Memoized: subsequent calls within the same
	 * request return the cached result without re-firing any filter.
	 *
	 * @return array{npm:array<int,array<string,mixed>>,clients:array<int,array<string,mixed>>,ai_connectors:array<int,array<string,mixed>>}
	 */
	public function get_all(): array {
		if ( null !== $this->assembled_cache ) {
			return $this->assembled_cache;
		}

		$assembled = array(
			'npm'           => $this->get_npm_methods(),
			'clients'       => $this->get_clients(),
			'ai_connectors' => $this->get_ai_connectors(),
		);

		/**
		 * Filter the fully-assembled three-category discovery result.
		 *
		 * Consumers MAY modify the entire result (remove categories,
		 * prepend/append DTOs, decorate `meta` fields). Return value MUST
		 * be an array with the three required category keys — else the
		 * return is discarded per FR-012a.
		 *
		 * @param array<string,array<int,array<string,mixed>>> $assembled Pre-filter composed result.
		 */
		$filtered = apply_filters( 'acrossai_mcp_connection_methods', $assembled );

		if ( ! $this->is_valid_assembled_shape( $filtered ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong(
					'ConnectionMethodRegistry::get_all',
					esc_html__( 'acrossai_mcp_connection_methods callback returned an invalid shape (must be array with npm/clients/ai_connectors keys). Falling back to pre-filter result.', 'acrossai-mcp-manager' ),
					'0.1.9'
				);
			}
			$filtered = $assembled;
		}

		$this->assembled_cache = $filtered;

		return $this->assembled_cache;
	}

	/**
	 * Return the NPM connection methods.
	 *
	 * Seeds with the built-in npx bridge from `NpmClientBlock::get_default_npm_method()`,
	 * fires `acrossai_mcp_npm_methods` exactly once, drops invalid contributions
	 * per FR-009b (key + type validation — SEC-035-001), dedups by slug
	 * later-wins per FR-009a.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_npm_methods(): array {
		$seed = array( NpmClientBlock::get_default_npm_method() );

		/**
		 * Filter the NPM connection methods list. Consumers MAY append or
		 * replace entries. Each entry MUST be a DTO with the six required
		 * top-level keys AND correct value types (5 strings + `meta` array) —
		 * else silently dropped per FR-009b.
		 *
		 * @param array<int, array<string, mixed>> $methods Seeded with built-in npx bridge DTO.
		 */
		$methods = apply_filters( 'acrossai_mcp_npm_methods', $seed );

		// Defensive: non-array return from a broken callback recovers with
		// seed. Individual malformed entries handled below by the loop.
		if ( ! is_array( $methods ) ) {
			$methods = $seed;
		}

		$dedup = array();
		foreach ( $methods as $entry ) {
			if ( ! is_array( $entry ) || ! $this->validate_npm_dto( $entry ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'ConnectionMethodRegistry::get_npm_methods',
						esc_html__( 'acrossai_mcp_npm_methods contribution dropped: missing required key or wrong value type. DTO must have six string keys (category, slug, name, description, icon) plus meta array.', 'acrossai-mcp-manager' ),
						'0.1.9'
					);
				}
				continue;
			}
			// Later-wins dedup by slug (FR-009a).
			$dedup[ $entry['slug'] ] = $entry;
		}

		return array_values( $dedup );
	}

	/**
	 * Return the MCP client connection methods.
	 *
	 * Delegates to F034 canonical enumeration; NEVER re-fires
	 * `acrossai_mcp_client_classes` (enforced by SC-005 grep gate).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_clients(): array {
		$clients = AbstractMCPClient::get_all_registered_clients();
		$dtos    = array();

		foreach ( $clients as $client ) {
			$dtos[] = array(
				'category'    => 'client',
				'slug'        => $client->get_client_slug(),
				'name'        => $client->get_client_name(),
				'description' => $client->get_description(),
				'icon'        => $client->get_icon(),
				'meta'        => array(
					'config_file'   => $client->get_config_file(),
					'top_level_key' => $client->get_top_level_key(),
					'class'         => get_class( $client ),
				),
			);
		}

		return $dtos;
	}

	/**
	 * Return the AI connector connection methods.
	 *
	 * Delegates to F021 canonical enumeration; NEVER re-fires
	 * `acrossai_mcp_manager_connector_profiles` (enforced by SC-005 grep gate).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_ai_connectors(): array {
		$profiles = ConnectorProfileRegistry::instance()->get_profiles();
		$dtos     = array();

		foreach ( $profiles as $profile ) {
			$icon_url  = $profile->get_icon_url();
			$whitelist = $profile->get_redirect_uri_whitelist();
			$dtos[]    = array(
				'category'    => 'ai_connector',
				'slug'        => $profile->get_slug(),
				'name'        => $profile->get_name(),
				'description' => '',
				'icon'        => $icon_url,
				'meta'        => array(
					'icon_url'               => $icon_url,
					'has_redirect_whitelist' => ! empty( $whitelist ),
					'class'                  => get_class( $profile ),
				),
			);
		}

		return $dtos;
	}

	/**
	 * Look up a single DTO by `(category, slug)` exact match.
	 *
	 * Zero validation on inputs — unknown category or unknown slug both
	 * return `null` (developer signal). No `_doing_it_wrong`.
	 *
	 * @param string $category One of 'npm' / 'client' / 'ai_connector' (or anything — unknown returns null).
	 * @param string $slug     Machine identifier to match.
	 * @return array<string, mixed>|null
	 */
	public function find( string $category, string $slug ): ?array {
		$all = $this->get_all();
		if ( ! isset( $all[ $category ] ) ) {
			return null;
		}
		foreach ( $all[ $category ] as $dto ) {
			if ( isset( $dto['slug'] ) && $dto['slug'] === $slug ) {
				return $dto;
			}
		}
		return null;
	}

	/**
	 * Reset the memoized `get_all()` cache. Documented supported surface —
	 * production-shape name per B23 (never `_reset_for_tests()`). Callers:
	 * PHPUnit `setUp()` for test isolation; companion plugins that
	 * legitimately need mid-request filter re-registration.
	 *
	 * Does NOT flush F034's `get_all_registered_clients()` (not memoized in
	 * F034) or F021's `ConnectorProfileRegistry::get_profiles()` (has its
	 * own reset path used by OAuthTestCase).
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->assembled_cache = null;
	}

	/**
	 * Validate an NPM DTO has all six required top-level keys AND that each
	 * value has the expected type — the five string keys hold `is_string()`
	 * values, `meta` holds `is_array()`. Direct application of FR-009b
	 * (tightened per SEC-035-001). A key-only gate would let malicious
	 * contributions like `slug => array()` / `meta => 'string'` reach
	 * downstream consumers.
	 *
	 * @param array<string, mixed> $dto Candidate DTO from filter contribution.
	 * @return bool True if DTO passes shape + type validation.
	 */
	private function validate_npm_dto( array $dto ): bool {
		foreach ( array( 'category', 'slug', 'name', 'description', 'icon' ) as $string_key ) {
			if ( ! isset( $dto[ $string_key ] ) || ! is_string( $dto[ $string_key ] ) ) {
				return false;
			}
		}
		return isset( $dto['meta'] ) && is_array( $dto['meta'] );
	}

	/**
	 * Validate the shape returned by an `acrossai_mcp_connection_methods`
	 * callback. Must be an array with all three required category keys,
	 * each mapping to an array.
	 *
	 * @param mixed $candidate Filter return value to validate.
	 * @return bool True if shape is valid.
	 */
	private function is_valid_assembled_shape( $candidate ): bool {
		if ( ! is_array( $candidate ) ) {
			return false;
		}
		foreach ( array( 'npm', 'clients', 'ai_connectors' ) as $category_key ) {
			if ( ! isset( $candidate[ $category_key ] ) || ! is_array( $candidate[ $category_key ] ) ) {
				return false;
			}
		}
		return true;
	}
}
