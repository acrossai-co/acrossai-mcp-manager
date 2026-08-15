<?php
/**
 * Feature 069 — Quick Setup Wizard REST controller.
 *
 * Registers three routes under the plugin's REST namespace:
 *
 *   GET  /acrossai-mcp-manager/v1/quick-setup/state    — snapshot for the React app
 *   POST /acrossai-mcp-manager/v1/quick-setup/step     — persist per-step scratchpad
 *   POST /acrossai-mcp-manager/v1/quick-setup/complete — clear scratchpad
 *
 * All routes gate on `manage_options` (S2). POSTs additionally require a
 * valid `X-WP-Nonce` header for action `wp_rest` (S1).
 *
 * Error-hygiene invariant (TASK-SEC-003 / T055): every WP_Error returned by
 * any handler in this file MUST use a hand-authored, user-facing message.
 * Raw `$e->getMessage()`, `$wpdb->last_error`, transient key strings, or
 * file paths MUST NEVER appear in the response `message` field. Internal
 * diagnostics MAY be logged via `error_log()` — never surfaced to the client.
 *
 * F017 abilities integration (TASK-SEC-002 / T022): Step 3 "Enable all
 * abilities" DOES NOT round-trip through F017's REST route. Instead we call
 * the underlying service class `MCPServerAbilityQuery::instance()->upsert()`
 * directly for each ability from `wp_get_abilities()`. Single auth check
 * (this controller's outer permission_callback), no internal REST-to-REST
 * nonce lifecycle question, no cross-controller REST-wire coupling. Matches
 * `DEC-ABILITY-OVERRIDE-RESOLUTION` service-call intent.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/REST
 * @since      0.2.11
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\MCPServerFieldSanitizer;
use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Quick Setup Wizard REST controller.
 *
 * @since 0.2.11
 */
final class QuickSetupController {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';

	/**
	 * REST route prefix (relative to namespace).
	 */
	private const ROUTE_PREFIX = '/quick-setup';

	/**
	 * Per-user scratchpad transient key prefix. The current user id is
	 * appended at read/write time (data-model.md E1).
	 */
	private const SCRATCHPAD_KEY_PREFIX = 'acrossai_mcp_manager_quick_setup_state_';

	/**
	 * Scratchpad TTL — 30 minutes per FR-026.
	 */
	private const SCRATCHPAD_TTL = 1800;

	/**
	 * Valid step values accepted on POST /step.
	 */
	private const VALID_STEPS = array( 1, 2, 3, 4, 5 );

	/**
	 * Valid method values accepted on step 5.
	 */
	private const VALID_METHODS = array( 'connectors', 'client', 'npm', 'wpcli' );

	/**
	 * Singleton instance.
	 *
	 * @since 0.2.11
	 * @var self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Singleton accessor.
	 *
	 * @since 0.2.11
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor enforces singleton pattern (S6).
	 *
	 * @since 0.2.11
	 */
	private function __construct() {}

	// ─────────────────────────────────────────────────────────────────────
	// Route registration
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Register the three quick-setup routes. Wired on `rest_api_init` via
	 * `Main::define_public_hooks()` (T024).
	 *
	 * @since 0.2.11
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_state' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_step' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_complete' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * Shared permission callback for all three routes. Boolean per S2.
	 * WP REST layer additionally validates X-WP-Nonce automatically for
	 * cookie-authenticated requests when the nonce is present.
	 *
	 * @since 0.2.11
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Route: GET /quick-setup/state
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Return the full snapshot the React app needs to render every step.
	 *
	 * Inherits TASK-SEC-003 error-hygiene constraint from the class docblock.
	 *
	 * @since 0.2.11
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_state() {
		$servers      = $this->collect_servers();
		$abilities    = $this->collect_abilities_summary();
		$plugins      = $this->collect_plugin_states();
		$methods      = ConnectionMethodRegistry::instance()->get_all();
		$wizard_state = $this->read_scratchpad();

		return rest_ensure_response(
			array(
				'servers'     => $servers,
				'abilities'   => $abilities,
				'plugins'     => $plugins,
				'methods'     => $methods,
				'wizardState' => $wizard_state,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Route: POST /quick-setup/step
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Persist per-step scratchpad state + delegate authoritative writes to
	 * existing plugin APIs (MCPServerQuery::add_item/update_item,
	 * MCPServerAbilityQuery::upsert per SEC-002 direct service call).
	 *
	 * Inherits TASK-SEC-003 error-hygiene constraint from the class docblock —
	 * every WP_Error uses a hand-authored user-facing message.
	 *
	 * @since 0.2.11
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_step( WP_REST_Request $request ) {
		$step = (int) $request->get_param( 'step' );
		if ( ! in_array( $step, self::VALID_STEPS, true ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_setup_invalid_step',
				esc_html__( 'Invalid step. Expected an integer from 1 to 5.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$data = $request->get_param( 'data' );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$scratchpad      = $this->read_scratchpad();
		$refresh_servers = false;

		switch ( $step ) {
			case 1:
				$result = $this->apply_step_1( $data, $scratchpad );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scratchpad      = $result;
				$refresh_servers = isset( $data['new_server'] );
				break;

			case 2:
				$scratchpad['access_saved'] = ! empty( $data['access_saved'] );
				break;

			case 3:
				$result = $this->apply_step_3( $data, $scratchpad );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scratchpad = $result;
				break;

			case 4:
				$result = $this->apply_step_4( $data, $scratchpad );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scratchpad      = $result;
				$refresh_servers = true;
				break;

			case 5:
				$method = isset( $data['method'] ) ? (string) $data['method'] : '';
				if ( ! in_array( $method, self::VALID_METHODS, true ) ) {
					return new WP_Error(
						'acrossai_mcp_quick_setup_invalid_method',
						esc_html__( 'Invalid connection method. Choose one of: connectors, client, npm, wpcli.', 'acrossai-mcp-manager' ),
						array( 'status' => 400 )
					);
				}
				$scratchpad['method'] = $method;
				break;
		}

		$scratchpad['current_step'] = $step;
		if ( ! $this->write_scratchpad( $scratchpad ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_setup_persist_failed',
				esc_html__( 'Failed to save your progress. Try again in a moment.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		$response = array( 'wizardState' => $scratchpad );
		if ( $refresh_servers ) {
			$response['servers'] = $this->collect_servers();
		}

		return rest_ensure_response( $response );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Route: POST /quick-setup/complete
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Clear the per-user scratchpad. 204 No Content on success. Idempotent.
	 *
	 * Inherits TASK-SEC-003 error-hygiene constraint from the class docblock.
	 *
	 * @since 0.2.11
	 * @return WP_REST_Response
	 */
	public function handle_complete() {
		delete_transient( self::SCRATCHPAD_KEY_PREFIX . get_current_user_id() );
		return new WP_REST_Response( null, 204 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Per-step apply helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Step 1 — select existing server OR create new server.
	 *
	 * @param array $data       Step payload.
	 * @param array $scratchpad Current scratchpad.
	 * @return array|WP_Error Updated scratchpad or error.
	 */
	private function apply_step_1( array $data, array $scratchpad ) {
		// Existing-server selection path.
		if ( isset( $data['server_id'] ) ) {
			$server_id = (int) $data['server_id'];
			if ( $server_id <= 0 ) {
				return new WP_Error(
					'acrossai_mcp_quick_setup_invalid_data',
					esc_html__( 'Choose a server to continue.', 'acrossai-mcp-manager' ),
					array( 'status' => 400 )
				);
			}
			if ( ! $this->server_exists( $server_id ) ) {
				return new WP_Error(
					'acrossai_mcp_quick_setup_server_gone',
					esc_html__( 'That server no longer exists. Restart the wizard.', 'acrossai-mcp-manager' ),
					array( 'status' => 410 )
				);
			}
			$scratchpad['server_id'] = $server_id;
			return $scratchpad;
		}

		// Create-new-server path.
		if ( isset( $data['new_server'] ) && is_array( $data['new_server'] ) ) {
			$sanitized = MCPServerFieldSanitizer::sanitize( $data['new_server'] );
			if ( '' === $sanitized['server_name'] ) {
				return new WP_Error(
					'acrossai_mcp_quick_setup_invalid_data',
					esc_html__( 'Server name is required.', 'acrossai-mcp-manager' ),
					array( 'status' => 400 )
				);
			}

			$query = MCPServerQuery::instance();

			// Slug collision guard — matches existing admin form contract.
			$existing = $query->query(
				array(
					'server_slug' => $sanitized['server_slug'],
					'number'      => 1,
				)
			);
			if ( ! empty( $existing ) ) {
				return new WP_Error(
					'acrossai_mcp_quick_setup_invalid_data',
					esc_html__( 'A server with that name already exists. Choose a different name.', 'acrossai-mcp-manager' ),
					array( 'status' => 400 )
				);
			}

			$new_id = $query->add_item(
				array(
					'server_name'            => $sanitized['server_name'],
					'server_slug'            => $sanitized['server_slug'],
					'description'            => $sanitized['description'],
					'is_enabled'             => 0,
					'registered_from'        => 'database',
					'server_route_namespace' => $sanitized['server_route_namespace'],
					'server_route'           => $sanitized['server_route'],
					'server_version'         => $sanitized['server_version'],
				)
			);
			if ( ! $new_id ) {
				return new WP_Error(
					'acrossai_mcp_quick_setup_persist_failed',
					esc_html__( 'Failed to create the server. Try again.', 'acrossai-mcp-manager' ),
					array( 'status' => 500 )
				);
			}

			$scratchpad['server_id'] = (int) $new_id;
			return $scratchpad;
		}

		return new WP_Error(
			'acrossai_mcp_quick_setup_invalid_data',
			esc_html__( 'Step 1 requires either a server_id or a new_server payload.', 'acrossai-mcp-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Step 3 — optionally bulk-enable all abilities for the wizard's server.
	 *
	 * TASK-SEC-002 remediation: calls MCPServerAbilityQuery::upsert() directly
	 * — no internal REST-to-REST call to the F017 abilities controller.
	 *
	 * @param array $data       Step payload.
	 * @param array $scratchpad Current scratchpad.
	 * @return array|WP_Error Updated scratchpad or error.
	 */
	private function apply_step_3( array $data, array $scratchpad ) {
		if ( empty( $data['enable_all_abilities'] ) ) {
			$scratchpad['abilities_saved'] = true; // "explicit skip" is a valid completion.
			return $scratchpad;
		}

		$server_id = isset( $scratchpad['server_id'] ) ? (int) $scratchpad['server_id'] : 0;
		if ( $server_id <= 0 ) {
			return new WP_Error(
				'acrossai_mcp_quick_setup_invalid_data',
				esc_html__( 'Pick a server before enabling abilities.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			// Abilities API not present — nothing to enable; treat as saved.
			$scratchpad['abilities_saved'] = true;
			return $scratchpad;
		}

		$ability_query = MCPServerAbilityQuery::instance();
		foreach ( \wp_get_abilities() as $ability ) {
			$slug = $ability->get_name();
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$ability_query->upsert( $server_id, $slug, true );
		}

		$scratchpad['abilities_saved'] = true;
		return $scratchpad;
	}

	/**
	 * Step 4 — enable the wizard's server.
	 *
	 * @param array $data       Step payload.
	 * @param array $scratchpad Current scratchpad.
	 * @return array|WP_Error Updated scratchpad or error.
	 */
	private function apply_step_4( array $data, array $scratchpad ) {
		$server_id = isset( $scratchpad['server_id'] ) ? (int) $scratchpad['server_id'] : 0;
		if ( $server_id <= 0 ) {
			return new WP_Error(
				'acrossai_mcp_quick_setup_invalid_data',
				esc_html__( 'Pick a server before enabling it.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$enabled = ! empty( $data['enabled'] );
		$updated = MCPServerQuery::instance()->update_item( $server_id, array( 'is_enabled' => $enabled ? 1 : 0 ) );
		if ( false === $updated ) {
			return new WP_Error(
				'acrossai_mcp_quick_setup_persist_failed',
				esc_html__( 'Failed to update the server. Try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}
		$scratchpad['enabled'] = $enabled;
		return $scratchpad;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Collect helpers (state assembly)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Server list DTO for GET /state.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_servers(): array {
		$rows = MCPServerQuery::instance()->query(
			array(
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$dtos = array();
		foreach ( (array) $rows as $row ) {
			$namespace  = isset( $row->server_route_namespace ) ? (string) $row->server_route_namespace : 'mcp';
			$route      = isset( $row->server_route ) ? (string) $row->server_route : '';
			$route_full = ltrim( $namespace, '/' ) . ( '' !== $route ? '/' . ltrim( $route, '/' ) : '' );
			$dtos[]     = array(
				'id'              => isset( $row->id ) ? (int) $row->id : 0,
				'name'            => isset( $row->server_name ) ? (string) $row->server_name : '',
				'slug'            => isset( $row->server_slug ) ? (string) $row->server_slug : '',
				'route_namespace' => $namespace,
				'route'           => $route,
				'route_full'      => $route_full,
				'enabled'         => ! empty( $row->is_enabled ),
			);
		}
		return $dtos;
	}

	/**
	 * Abilities summary for GET /state.
	 *
	 * @return array{total:int,hasManagerPlugin:bool}
	 */
	private function collect_abilities_summary(): array {
		$total = function_exists( 'wp_get_abilities' ) ? count( \wp_get_abilities() ) : 0;
		return array(
			'total'            => $total,
			'hasManagerPlugin' => 'active' === $this->plugin_activation_state( 'acrossai-abilities-manager/acrossai-abilities-manager.php' ),
		);
	}

	/**
	 * Plugin activation state map for GET /state.
	 *
	 * @return array{acrossaiPro:string,abilitiesManager:string}
	 */
	private function collect_plugin_states(): array {
		return array(
			'acrossaiPro'      => $this->plugin_activation_state( 'acrossai-pro/acrossai-pro.php' ),
			'abilitiesManager' => $this->plugin_activation_state( 'acrossai-abilities-manager/acrossai-abilities-manager.php' ),
		);
	}

	/**
	 * Resolve one plugin's activation state to `'missing'|'inactive'|'active'`.
	 * Mirrors F040 AIConnectorsPromoTab tri-state semantics.
	 *
	 * @param string $plugin_file Relative plugin file (e.g. `foo/foo.php`).
	 * @return string
	 */
	private function plugin_activation_state( string $plugin_file ): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( $plugin_file ) ) {
			return 'active';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$installed = get_plugins();
			if ( isset( $installed[ $plugin_file ] ) ) {
				return 'inactive';
			}
		}
		return 'missing';
	}

	// ─────────────────────────────────────────────────────────────────────
	// Scratchpad + helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Default scratchpad shape when no transient exists yet.
	 *
	 * @return array<string,mixed>
	 */
	private function default_scratchpad(): array {
		return array(
			'current_step'    => 1,
			'server_id'       => null,
			'access_saved'    => false,
			'abilities_saved' => false,
			'enabled'         => false,
			'method'          => null,
			'created_at'      => time(),
		);
	}

	/**
	 * Read the current user's scratchpad, defaulting when missing.
	 *
	 * @return array<string,mixed>
	 */
	private function read_scratchpad(): array {
		$stored = get_transient( self::SCRATCHPAD_KEY_PREFIX . get_current_user_id() );
		if ( ! is_array( $stored ) ) {
			return $this->default_scratchpad();
		}
		return array_merge( $this->default_scratchpad(), $stored );
	}

	/**
	 * Persist the current user's scratchpad with a fresh TTL.
	 *
	 * @param array<string,mixed> $data Full scratchpad shape.
	 * @return bool True on success, false on failure.
	 */
	private function write_scratchpad( array $data ): bool {
		return (bool) set_transient(
			self::SCRATCHPAD_KEY_PREFIX . get_current_user_id(),
			$data,
			self::SCRATCHPAD_TTL
		);
	}

	/**
	 * Check whether the given server row still exists.
	 *
	 * @param int $server_id Server row id.
	 * @return bool
	 */
	private function server_exists( int $server_id ): bool {
		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		return ! empty( $rows );
	}
}
