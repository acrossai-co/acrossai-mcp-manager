<?php
/**
 * MCP Controller — boots the WP MCP adapter singleton and registers each
 * enabled MCP server row as an adapter endpoint.
 *
 * Ported from v0.0.4 `src/MCP/Controller.php` (170 LOC) on 2026-07-01 as
 * part of Feature-009 (Phase 4 gap closure). The v0.0.4 file was omitted
 * during Phase 4's PR #6, which shipped only the `MCPClients/*` classes.
 * Without this port, no MCP servers are exposed via the adapter package —
 * a silent functional regression against v0.0.4 behavior.
 *
 * Key differences from v0.0.4:
 *   - Namespace `ACROSSAI_MCP_MANAGER\MCP` → `AcrossAI_MCP_Manager\Includes\MCP`
 *   - Static `MCPServerTable::has_any_enabled()` → instance `MCPServerQuery::query()`
 *   - `add_action('init', ...)` in constructor → wired via Loader in Main.php (A1)
 *   - Singleton pattern with private constructor (A2 / S6 / B5 defense)
 *   - Exception catch broadened to `\Throwable` for PHPStan L8 friendliness
 *
 * State machine (`get_adapter_status()` return values):
 *   'unknown'   — initialize_adapter() not yet called
 *   'running'   — adapter initialised successfully
 *   'disabled'  — no enabled server rows in the DB
 *   'not-found' — \WP\MCP\Plugin class not available (adapter package absent)
 *   'error'     — exception thrown during adapter init
 *
 * @package AcrossAI_MCP_Manager\Includes\MCP
 * @since   0.0.1 (Feature-009 / 2026-07-01)
 */

namespace AcrossAI_MCP_Manager\Includes\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

defined( 'ABSPATH' ) || exit;

final class Controller {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Adapter status — one of 'unknown' | 'running' | 'disabled' | 'not-found' | 'error'.
	 * Null until initialize_adapter() runs at least once.
	 *
	 * @var string|null
	 */
	private $adapter_status = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead. Empty body per A1: hook wiring lives
	 * in `Includes\Main::define_admin_hooks()` via Loader, not here.
	 */
	private function __construct() {}

	/**
	 * Boot the MCP Adapter when at least one enabled server row exists.
	 *
	 * Called by the Loader on `rest_api_init`. Idempotent — safe to call
	 * multiple times per request (status is set once, downstream calls
	 * short-circuit on the non-null value).
	 *
	 * Registers `register_database_servers` as an `mcp_adapter_init` handler
	 * at priority 11 (immediately after DefaultServerFactory's priority 10)
	 * BEFORE calling `Plugin::instance()`, so our hook is in place when the
	 * adapter fires its own init chain.
	 */
	public function initialize_adapter(): void {
		if ( null !== $this->adapter_status ) {
			return;
		}

		if ( ! $this->has_any_enabled_server() ) {
			$this->adapter_status = 'disabled';
			return;
		}

		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			$this->adapter_status = 'not-found';
			return;
		}

		try {
			add_action( 'mcp_adapter_init', array( $this, 'register_database_servers' ), 11 );

			\WP\MCP\Plugin::instance();
			$this->adapter_status = 'running';
		} catch ( \Throwable $e ) {
			$this->adapter_status = 'error';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				do_action( 'acrossai_mcp_manager_adapter_init_error', $e );
			}
		}
	}

	/**
	 * Register enabled database-sourced MCP servers with the adapter.
	 *
	 * Hooked on `mcp_adapter_init` (priority 11 — after DefaultServerFactory).
	 * Each enabled row where `registered_from = 'database'` gets its own MCP
	 * server instance via `$adapter->create_server()`.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The MCP Adapter singleton
	 *                                         instance passed by the action.
	 */
	public function register_database_servers( $adapter ): void {
		$servers = $this->get_enabled_database_servers();

		if ( empty( $servers ) ) {
			return;
		}

		foreach ( $servers as $server ) {
			$slug = (string) $server->server_slug;

			if ( '' === $slug ) {
				continue;
			}

			$namespace = '' !== $server->server_route_namespace ? $server->server_route_namespace : 'mcp';
			$route     = '' !== $server->server_route ? $server->server_route : $slug;
			$version   = '' !== $server->server_version ? $server->server_version : 'v1.0.0';

			$tools = ToolPolicy::compose_for_row( $server );

			/**
			 * Filter the tools list a plugin-registered (database) MCP server exposes.
			 *
			 * Fired inside Controller::register_database_servers() per server,
			 * immediately before $adapter->create_server(). The initial list is
			 * the union of the row's enabled tool_* columns (protocol slugs) and
			 * the ability slugs saved in wp_acrossai_mcp_server_tools for this
			 * server_id. Callbacks may add or remove any slug freely.
			 *
			 * NOT fired for the default server (server_slug =
			 * 'mcp-adapter-default-server'). Hook `mcp_adapter_default_server_config`
			 * for that path.
			 *
			 * @since 0.1.0 (Feature 025)
			 *
			 * @param string[] $tools  Ability slugs to register as MCP tools.
			 * @param \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server The server row being registered.
			 */
			$tools = apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $server );
			$tools = array_values( array_unique( array_map( 'strval', (array) $tools ) ) );

			$result = $adapter->create_server(
				$slug,
				$namespace,
				$route,
				$server->server_name,
				$server->description,
				$version,
				array( HttpTransport::class ),
				ErrorLogMcpErrorHandler::class,
				NullMcpObservabilityHandler::class,
				$tools,
				array(),
				array()
			);

			if ( is_wp_error( $result ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: server slug, 2: error message, 3: error code */
							'AcrossAI MCP Manager: Failed to create database server "%1$s". Error: %2$s (Code: %3$s)',
							$slug,
							(string) $result->get_error_message(),
							(string) $result->get_error_code()
						)
					),
					'0.0.1'
				);
			}
		}
	}

	/**
	 * Callback for the vendor filter `mcp_adapter_default_server_config`.
	 *
	 * REPLACES $config['tools'] with the composed slug set for the seeded
	 * default server row — the enabled `tool_*` columns plus whatever the
	 * operator saved in wp_acrossai_mcp_server_tools. The schema DEFAULT 1
	 * on the ALTER (Feature 025 schema bump 1.0.0 → 1.1.0) ensures a fresh
	 * install exposes all three protocol tools out of the box.
	 *
	 * Wired via Loader in `Includes\Main::define_admin_hooks()`. Called once
	 * per `mcp_adapter_init` firing.
	 *
	 * Defensive: returns the input untouched if
	 *  - the config is not an array,
	 *  - $config['tools'] is not an array,
	 *  - the default server row cannot be located by slug (unseeded install
	 *    — unexpected),
	 *  - the composed picks array is empty (the operator explicitly removed
	 *    every tool AND has no curated picks AND declined Reset — vendor
	 *    defaults are the safer fallback per spec §Edge Cases). See
	 *    security-review v2 SEC-025-v2-1 for the DB-vs-default-server
	 *    behavioral asymmetry note.
	 *
	 * Does NOT fire `acrossai_mcp_manager_server_tools` — the vendor filter
	 * is the single extension seam for this path (spec §FR-009).
	 *
	 * @since 0.1.0 (Feature 025)
	 *
	 * @param mixed $config The vendor-supplied config array.
	 * @return mixed The config with the composed slug set replacing `tools`, or the input untouched.
	 */
	public function filter_default_server_config( $config ) {
		if ( ! is_array( $config ) || ! isset( $config['tools'] ) || ! is_array( $config['tools'] ) ) {
			return $config;
		}

		// SEC-025-v2-3: server_slug index is 'key' not 'unique' (F011 baseline);
		// MCPServerQuery::query returns first insertion-order match; safe within
		// manage_options trust boundary — see security-review v2.
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => DefaultServerSeeder::SLUG,
				'number'      => 1,
			)
		);
		if ( empty( $rows ) ) {
			return $config;
		}

		$tools = ToolPolicy::compose_for_row( $rows[0] );
		if ( empty( $tools ) ) {
			return $config;
		}

		$config['tools'] = $tools;
		return $config;
	}

	/**
	 * Return the current adapter status string. If `initialize_adapter()`
	 * hasn't run yet, run it lazily so callers always see the up-to-date
	 * state derived from the current DB contents.
	 */
	public function get_adapter_status(): string {
		if ( null === $this->adapter_status ) {
			$this->initialize_adapter();
		}

		return $this->adapter_status ?? 'unknown';
	}

	/**
	 * Fast-path check for "is at least one server row enabled". Uses the
	 * BerlinDB-style Query with `number => 1` so the SQL uses `LIMIT 1`
	 * rather than scanning the full table.
	 */
	private function has_any_enabled_server(): bool {
		$rows = MCPServerQuery::instance()->query(
			array(
				'is_enabled' => 1,
				'number'     => 1,
			)
		);
		return ! empty( $rows );
	}

	/**
	 * Return all enabled server rows whose `registered_from = 'database'`.
	 * Used by `register_database_servers` — plugin-registered rows are handled
	 * by the adapter's DefaultServerFactory on priority 10.
	 *
	 * @return array<int, \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row>
	 */
	private function get_enabled_database_servers(): array {
		return MCPServerQuery::instance()->query(
			array(
				'is_enabled'      => 1,
				'registered_from' => 'database',
			)
		);
	}
}
