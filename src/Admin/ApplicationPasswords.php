<?php
/**
 * Application Passwords Manager class.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace ACROSSAI_MCP_MANAGER\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ACROSSAI_MCP_MANAGER\Database\MCPServerTable;
use ACROSSAI_MCP_MANAGER\MCPClients\AbstractMCPClient;
use ACROSSAI_MCP_MANAGER\MCPClients\ClaudeCodeClient;
use ACROSSAI_MCP_MANAGER\MCPClients\ClaudeDesktopClient;
use ACROSSAI_MCP_MANAGER\MCPClients\CodexClient;
use ACROSSAI_MCP_MANAGER\MCPClients\CursorClient;
use ACROSSAI_MCP_MANAGER\MCPClients\CustomClient;
use ACROSSAI_MCP_MANAGER\MCPClients\GitHubCopilotClient;
use ACROSSAI_MCP_MANAGER\MCPClients\VSCodeClient;

/**
 * Manages Application Passwords for MCP clients and the REST endpoints
 * that the admin JS uses to generate passwords and fetch client configs.
 *
 * REST namespace: acrossai-mcp-manager/v1
 *
 * Endpoints:
 *   POST /generate-app-password  – create a new WP Application Password
 *   GET  /get-client-config/{client} – return the JSON config for a client
 *   GET  /list-app-passwords     – list passwords created by this plugin
 *
 * @since 1.0.0
 */
class ApplicationPasswords {

	/**
	 * Supported MCP clients keyed by client ID.
	 *
	 * @var array<string,AbstractMCPClient>
	 */
	private $clients = array();

	/**
	 * Constructor — registers REST routes.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->clients = $this->build_client_registry();
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	// -------------------------------------------------------------------------
	// REST routes
	// -------------------------------------------------------------------------

	/**
	 * Register all REST API routes for this class.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$namespace       = 'acrossai-mcp-manager/v1';
		$admin_only_perm = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			$namespace,
			'/generate-app-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_app_password' ),
				'permission_callback' => $admin_only_perm,
				'args'                => array(
					'client'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'server_id' => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/get-client-config/(?P<client>[a-z\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_client_config' ),
				'permission_callback' => $admin_only_perm,
				'args'                => array(
					'server_id' => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/list-app-passwords',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_app_passwords' ),
				'permission_callback' => $admin_only_perm,
			)
		);
	}

	// -------------------------------------------------------------------------
	// REST callbacks
	// -------------------------------------------------------------------------

	/**
	 * Generate a WordPress Application Password for the given MCP client.
	 *
	 * The password name includes the client label and, when a valid server_id
	 * is supplied, the server name — making it easy to identify in the profile
	 * page. The raw password is returned once and never stored by this plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_app_password( \WP_REST_Request $request ) {
		$client    = sanitize_text_field( $request->get_param( 'client' ) );
		$server_id = (int) $request->get_param( 'server_id' );
		$client_definition = $this->get_client( $client );

		if ( ! $client_definition ) {
			return new \WP_Error(
				'invalid_client',
				__( 'Invalid client type.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'not_supported',
				__( 'Application Passwords are not supported on this WordPress version.', 'acrossai-mcp-manager' ),
				array( 'status' => 501 )
			);
		}

		$current_user = wp_get_current_user();
		$client_label = $client_definition->get_label();

		// Append server name when a specific server is referenced.
		$server_suffix = '';
		if ( $server_id > 0 ) {
			$server = MCPServerTable::get_by_id( $server_id );
			if ( $server ) {
				$server_suffix = ' (' . $server['server_name'] . ')';
			}
		}

		$app_name = sprintf( 'AcrossAI MCP Manager - %s%s', $client_label, $server_suffix );

		$result = \WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		list( $password, $app_details ) = $result;

		return rest_ensure_response(
			array(
				'success'  => true,
				'password' => $password,
				'username' => $current_user->user_login,
				'client'   => $client,
				'app_id'   => isset( $app_details['uuid'] ) ? $app_details['uuid'] : '',
				'message'  => __( 'Application Password created. Store it safely — it is shown only once.', 'acrossai-mcp-manager' ),
			)
		);
	}

	/**
	 * Return the full MCP JSON configuration for a client.
	 *
	 * Accepts an optional server_id query parameter. All servers currently share
	 * the same MCP adapter endpoint; server_id is forwarded for future use when
	 * each server may expose a unique URL.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_client_config( \WP_REST_Request $request ) {
		$client    = sanitize_text_field( $request->get_param( 'client' ) );
		$server_id = (int) $request->get_param( 'server_id' );
		$client_definition = $this->get_client( $client );

		if ( ! $client_definition ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Invalid client type.', 'acrossai-mcp-manager' ),
				)
			);
		}

		$current_user  = wp_get_current_user();
		$mcp_config    = $this->generate_mcp_server_config( $current_user->user_login, $server_id );
		$top_level_key = $client_definition->get_top_level_key();
		$server_name   = $this->build_server_key( $server_id );
		$full_config   = $client_definition->build_full_config( $server_name, $mcp_config );

		return rest_ensure_response(
			array(
				'success'          => true,
				'client'           => $client,
				'mcp_config'       => $mcp_config,
				'full_config'      => $full_config,
				'username'         => $current_user->user_login,
				'top_level_key'    => $top_level_key,
				'config_file_path' => $client_definition->get_config_file(),
			)
		);
	}

	/**
	 * List existing Application Passwords created by this plugin for the current user.
	 *
	 * Filters by the "AcrossAI MCP Manager" name prefix so unrelated passwords
	 * are never exposed.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response
	 */
	public function list_app_passwords() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'passwords' => array(),
				)
			);
		}

		$user_id   = get_current_user_id();
		$all       = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$passwords = array_values(
			array_filter(
				$all,
				function ( $pwd ) {
					return isset( $pwd['name'] ) && false !== strpos( $pwd['name'], 'AcrossAI MCP Manager' );
				}
			)
		);

		return rest_ensure_response(
			array(
				'success'   => true,
				'passwords' => $passwords,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the JSON config key for a server: {sitename}-{serverslug}.
	 *
	 * Matches the key format used by the @acrossai/mcp-manager CLI tool so that
	 * manually-pasted configs and CLI-generated configs share the same key.
	 *
	 * @since 1.0.0
	 *
	 * @param int $server_id DB ID of the server being configured.
	 *
	 * @return string Slugified key, e.g. "wordpress-default-mcp-server".
	 */
	private function build_server_key( $server_id ) {
		$site_name = sanitize_title( get_bloginfo( 'name' ) );

		if ( $server_id > 0 ) {
			$server_row = MCPServerTable::get_by_id( $server_id );
			if ( $server_row ) {
				// Use the stored server_slug (set once at creation, never changes).
				$server_slug = ! empty( $server_row['server_slug'] )
					? $server_row['server_slug']
					: sanitize_title( $server_row['server_name'] );

				if ( $site_name && $server_slug ) {
					return $site_name . '-' . $server_slug;
				}
				if ( $server_slug ) {
					return $server_slug;
				}
			}
		}

		return $site_name ?: 'mcp-wordpress';
	}

	/**
	 * Build the inner MCP server configuration block.
	 *
	 * Uses the standard @automattic/mcp-wordpress-remote package with WordPress
	 * Application Passwords and explicitly disables OAuth discovery so the
	 * remote package follows the plugin's supported auth path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $username  WordPress username for the WP_API_USERNAME env var.
	 * @param int    $server_id Optional DB server ID (reserved for future use).
	 *
	 * @return array MCP server configuration array.
	 */
	private function generate_mcp_server_config( $username, $server_id = 0 ) {
		// Derive the MCP URL from the server row when a valid server_id is given.
		$mcp_api_url = rest_url( 'mcp/mcp-adapter-default-server' ); // safe fallback

		if ( $server_id > 0 ) {
			$server_row = MCPServerTable::get_by_id( $server_id );
			if ( $server_row ) {
				$slug      = ! empty( $server_row['server_slug'] )
					? $server_row['server_slug']
					: sanitize_title( $server_row['server_name'] );
				$namespace = ! empty( $server_row['server_route_namespace'] )
					? $server_row['server_route_namespace']
					: 'mcp';
				$route     = ! empty( $server_row['server_route'] )
					? $server_row['server_route']
					: $slug;

				$mcp_api_url = rest_url( $namespace . '/' . $route );
			}
		}

		return array(
			'command' => 'npx',
			'args'    => array(
				'-y',
				'@automattic/mcp-wordpress-remote@latest',
			),
			'env'     => array(
				'OAUTH_ENABLED'   => 'false',
				'WP_API_URL'      => $mcp_api_url,
				'WP_API_USERNAME' => $username,
				'WP_API_PASSWORD' => '(paste generated password here)',
			),
		);
	}

	/**
	 * Return the legacy client metadata array expected by the admin UI.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array>
	 */
	public function get_clients() {
		$clients = array();

		foreach ( $this->clients as $client_id => $client ) {
			$clients[ $client_id ] = $client->to_array();
		}

		return $clients;
	}

	/**
	 * Build the ordered client registry.
	 *
	 * @return array<string,AbstractMCPClient>
	 */
	private function build_client_registry() {
		$clients = array(
			new ClaudeDesktopClient(),
			new ClaudeCodeClient(),
			new VSCodeClient(),
			new GitHubCopilotClient(),
			new CodexClient(),
			new CursorClient(),
			new CustomClient(),
		);
		$registry = array();

		foreach ( $clients as $client ) {
			$registry[ $client->get_id() ] = $client;
		}

		return $registry;
	}

	/**
	 * Return a concrete client definition by ID.
	 *
	 * @param string $client_id Client ID.
	 *
	 * @return AbstractMCPClient|null
	 */
	private function get_client( $client_id ) {
		return isset( $this->clients[ $client_id ] ) ? $this->clients[ $client_id ] : null;
	}
}
