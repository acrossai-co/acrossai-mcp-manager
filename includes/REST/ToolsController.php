<?php
/**
 * Feature 020 — Per-server Tool Selection REST controller.
 *
 * Registers two routes under the plugin's REST namespace:
 *
 *   GET  /acrossai-mcp-manager/v1/servers/{server_id}/tools
 *   POST /acrossai-mcp-manager/v1/servers/{server_id}/tools
 *
 * Both gate on `manage_options` (S2 / FR-021).
 *
 * READ path:
 *   1. Look up server (404 if missing).
 *   2. Return `{ tools: [ ability_slug, ... ] }` — the current curated set.
 *   3. When `?include_abilities=1`, also return the full ability catalog
 *      (excluding the three protocol tools) as `{ abilities: [ ... ] }`.
 *
 * WRITE path (replace-all semantics):
 *   1. Look up server (404 if missing).
 *   2. Sanitize + validate every slug (400 on unknown or excluded).
 *   3. Call `Query::replace_set()` inside a try/catch — TX rollback
 *      surfaces as generic 500 with `acrossai_mcp_tools_save_failed`.
 *   4. Flush the ToolExposureGate per-request cache.
 *   5. Fire `acrossai_mcp_tools_changed` per applied add/remove — each
 *      `do_action` individually wrapped in try/catch so observer errors
 *      never bubble to the REST response (FR-031).
 *
 * Wired via `includes/Main.php::define_admin_hooks()` per A1.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\REST
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the per-server tools surface.
 *
 * @since 0.1.0
 */
final class ToolsController {

	/**
	 * REST namespace shared with the rest of the plugin.
	 *
	 * @var string
	 */
	private const NS = 'acrossai-mcp-manager/v1';

	/**
	 * Slugs never exposed in the pool (protocol tools). Mirrors JS-side
	 * `EXCLUDED_SLUGS` and `ToolExposureGate::EXCLUDED_SLUGS`.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_SLUGS = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — enforces the singleton pattern (S6). No hook
	 * registration in ctors (A1) — wiring lives in Main::define_admin_hooks().
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Register both routes on `rest_api_init`.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_routes(): void {
		$server_id_arg = array(
			'server_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ) {
					if ( (int) $value > 0 ) {
						return true;
					}
					return new WP_Error(
						'rest_invalid_id',
						esc_html__( 'server_id must be a positive integer.', 'acrossai-mcp-manager' ),
						array( 'status' => 400 )
					);
				},
			),
		);

		register_rest_route(
			self::NS,
			'/servers/(?P<server_id>\d+)/tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tools' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array_merge(
						$server_id_arg,
						array(
							'include_abilities' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_tools' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array_merge(
						$server_id_arg,
						array(
							'tools' => array(
								'type'              => 'array',
								'items'             => array( 'type' => 'string' ),
								'required'          => true,
								'sanitize_callback' => static function ( $value ) {
									return array_values(
										array_map(
											'sanitize_text_field',
											(array) $value
										)
									);
								},
								'validate_callback' => static function ( $value ) {
									if ( is_array( $value ) ) {
										return true;
									}
									return new WP_Error(
										'rest_invalid_type',
										esc_html__( 'The `tools` field must be an array of ability slug strings.', 'acrossai-mcp-manager' ),
										array( 'status' => 400 )
									);
								},
							),
						)
					),
				),
			)
		);
	}

	/**
	 * REST permission callback — gates both routes on `manage_options` (S2).
	 * Never `__return_true` (Constitution §III).
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler — return current curated slug set + optional ability catalog.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tools( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];

		$server_check = $this->require_server( $server_id );
		if ( is_wp_error( $server_check ) ) {
			return $server_check;
		}

		$response = array(
			'tools' => MCPServerToolQuery::instance()->get_added_slugs( $server_id ),
		);

		$include_abilities = (bool) $request->get_param( 'include_abilities' );
		if ( $include_abilities && function_exists( 'wp_get_abilities' ) ) {
			$abilities = array();
			foreach ( \wp_get_abilities() as $ability ) {
				$name = (string) $ability->get_name();
				if ( in_array( $name, self::EXCLUDED_SLUGS, true ) ) {
					continue;
				}
				$meta        = $ability->get_meta();
				$abilities[] = array(
					'name'        => $name,
					'label'       => (string) $ability->get_label(),
					'description' => (string) $ability->get_description(),
					'type'        => is_array( $meta ) && isset( $meta['mcp']['type'] ) ? (string) $meta['mcp']['type'] : '',
					'category'    => (string) $ability->get_category(),
				);
			}
			$response['abilities'] = $abilities;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * POST handler — replace the tool set with the submitted array.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_tools( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];

		$server_check = $this->require_server( $server_id );
		if ( is_wp_error( $server_check ) ) {
			return $server_check;
		}

		$tools_param = $request->get_param( 'tools' );
		if ( ! is_array( $tools_param ) ) {
			// Belt-and-braces — the args validate_callback should have caught this.
			return new WP_Error(
				'rest_invalid_type',
				esc_html__( 'The `tools` field must be an array of ability slug strings.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		// Reject excluded protocol tools defense-in-depth.
		$excluded_hits = array_values( array_intersect( $tools_param, self::EXCLUDED_SLUGS ) );
		if ( ! empty( $excluded_hits ) ) {
			return new WP_Error(
				'acrossai_mcp_excluded_tool_slug',
				esc_html__( 'Cannot add MCP-adapter protocol tools as per-server tools.', 'acrossai-mcp-manager' ),
				array(
					'status'         => 400,
					'excluded_slugs' => $excluded_hits,
				)
			);
		}

		// Validate against the currently-registered ability catalog (all-or-nothing).
		if ( function_exists( 'wp_get_abilities' ) ) {
			$registered = array();
			foreach ( \wp_get_abilities() as $ability ) {
				$registered[ (string) $ability->get_name() ] = true;
			}
			$invalid_slugs = array();
			foreach ( $tools_param as $slug ) {
				$slug_str = (string) $slug;
				if ( '' === $slug_str ) {
					continue;
				}
				if ( ! isset( $registered[ $slug_str ] ) ) {
					$invalid_slugs[] = $slug_str;
				}
			}
			if ( ! empty( $invalid_slugs ) ) {
				return new WP_Error(
					'acrossai_mcp_invalid_tool_slug',
					esc_html__( 'One or more submitted ability slugs are not registered on this site.', 'acrossai-mcp-manager' ),
					array(
						'status'        => 400,
						'invalid_slugs' => array_values( array_unique( $invalid_slugs ) ),
					)
				);
			}
		}

		// Apply the diff — transactional replace_set with FOR UPDATE lock.
		try {
			$applied = MCPServerToolQuery::instance()->replace_set( $server_id, $tools_param );
		} catch ( \Throwable $e ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- SEC-020-010: log-specific / respond-generic. Log has no user-controlled content.
				sprintf(
					'[acrossai_mcp_tools_save_failed] server_id=%d, desired_count=%d: %s',
					$server_id,
					count( $tools_param ),
					$e->getMessage()
				)
			);
			return new WP_Error(
				'acrossai_mcp_tools_save_failed',
				esc_html__( 'Could not save the tools list. Please try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		// Invalidate the enforcement gate's per-request cache so a same-request
		// tool call following this save sees fresh state.
		ToolExposureGate::flush_cache( $server_id );

		// Fire the change action per applied add + per applied remove. Each
		// individually wrapped in try/catch so a broken observer never 500s
		// the response (FR-031 / SEC-020-004).
		foreach ( $applied['added'] as $slug ) {
			$this->fire_change_action( $server_id, (string) $slug, 'added' );
		}
		foreach ( $applied['removed'] as $slug ) {
			$this->fire_change_action( $server_id, (string) $slug, 'removed' );
		}

		return rest_ensure_response(
			array(
				'tools' => MCPServerToolQuery::instance()->get_added_slugs( $server_id ),
			)
		);
	}

	/**
	 * Resolve a server row or return a 404 WP_Error.
	 *
	 * @since 0.1.0
	 * @param int $server_id Server id.
	 * @return true|WP_Error True when the server exists.
	 */
	private function require_server( int $server_id ) {
		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return new WP_Error(
				'acrossai_mcp_server_not_found',
				esc_html__( 'MCP server not found.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * Fire the `acrossai_mcp_tools_changed` action wrapped in try/catch.
	 *
	 * A throwing observer is caught + `error_log`'d; it does NOT bubble to
	 * the REST response. Later observers continue firing. FR-031.
	 *
	 * @since 0.1.0
	 * @param int    $server_id    Server id.
	 * @param string $ability_slug Ability slug.
	 * @param string $operation    'added' | 'removed'.
	 * @return void
	 */
	private function fire_change_action( int $server_id, string $ability_slug, string $operation ): void {
		try {
			/**
			 * Fires once per applied add + once per applied remove during a
			 * successful POST /servers/{id}/tools.
			 *
			 * Payload is a positional array — callbacks receive it via the
			 * first parameter. Never contains user IDs, IP addresses, or
			 * session identifiers.
			 *
			 * @since 0.1.0
			 *
			 * @param array{server_id:int, ability_slug:string, operation:string} $payload
			 */
			do_action(
				'acrossai_mcp_tools_changed',
				array(
					'server_id'    => $server_id,
					'ability_slug' => $ability_slug,
					'operation'    => $operation,
				)
			);
		} catch ( \Throwable $e ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- FR-031 SEC-020-004 observer isolation. Slug is validated by wp_get_abilities catalog membership above; server_id is an int.
				sprintf(
					'[acrossai_mcp_tools_changed] observer error for %s on %d: %s',
					$ability_slug,
					$server_id,
					$e->getMessage()
				)
			);
		}
	}
}
