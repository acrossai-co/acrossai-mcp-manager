<?php
/**
 * REST API controller for access-control rules.
 *
 * Registers the wpb-ac/v1 REST namespace and exposes endpoints for reading,
 * writing, and deleting per-resource rules, purging namespaces, listing
 * registered providers, and searching WordPress users.
 *
 * Permission model
 * ----------------
 * All endpoints require `manage_options` by default.
 * Use the `wpb_access_control_rest_permission` filter to override:
 *
 *   add_filter( 'wpb_access_control_rest_permission', function( bool $can, WP_REST_Request $request ) {
 *       // Example: allow editors to read, but only admins to write.
 *       if ( 'GET' === $request->get_method() ) {
 *           return current_user_can( 'edit_posts' );
 *       }
 *       return $can;
 *   }, 10, 2 );
 *
 * Write authorization
 * -------------------
 * Set/clear endpoints additionally pass through the `wpb_access_control_can_save`
 * filter so consuming plugins can restrict which namespace/key pairs a user may
 * modify, independent of the base permission check.
 *
 * URL encoding note
 * -----------------
 * The `namespace` route segment uses [^/]+ — it cannot contain literal slashes.
 * If a namespace includes slashes (e.g. `procureco/v1`), encode them as `%2F`
 * in the URL. PHP / WordPress will URL-decode the value before it reaches the
 * callback. The `key` segment allows slashes as the trailing catch-all (.+).
 *
 * Usage (from AccessControlManager::register_rest_api)
 * -----------------------------------------------------
 *   add_action( 'rest_api_init', function() use ( $manager ) {
 *       $manager->register_rest_api();
 *   } );
 *
 * @package WPBoilerplate\AccessControl\RestApi
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl\RestApi;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WPBoilerplate\AccessControl\AccessControlManager;
use WPBoilerplate\AccessControl\Database\Rule\RuleTable;
use WPBoilerplate\AccessControl\WpUserProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the wpb-ac/v1 namespace.
 *
 * @since 1.0.0
 */
class RulesController extends WP_REST_Controller {

	/** @var string REST namespace. */
	protected $namespace = 'wpb-ac/v1';

	/** @var AccessControlManager */
	private $manager;

	/**
	 * @since 1.0.0
	 *
	 * @param AccessControlManager $manager Provider registry instance.
	 */
	public function __construct( AccessControlManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Register all REST routes for the wpb-ac/v1 namespace.
	 *
	 * Called automatically by AccessControlManager::register_rest_api() during
	 * `rest_api_init`. Do not call directly unless you instantiate the
	 * controller yourself.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		// GET / PUT / DELETE  /rules/{namespace}/{key}
		register_rest_route(
			$this->namespace,
			'/rules/(?P<namespace>[^/]+)/(?P<key>.+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rule' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_ns_key_args(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_rule' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$this->get_ns_key_args(),
						array(
							'ac_key'     => array(
								'description'       => __( 'Rule type slug. Empty string or "everyone" to clear restrictions; otherwise a registered provider ID.', 'wpb-access-control' ),
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
							),
							'ac_options' => array(
								'description'       => __( 'Option values for the chosen rule type (role slugs, user ID strings, etc.).', 'wpb-access-control' ),
								'type'              => 'array',
								'items'             => array( 'type' => 'string' ),
								'default'           => array(),
							),
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_rule' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_ns_key_args(),
				),
			)
		);

		// DELETE  /namespaces/{namespace}
		register_rest_route(
			$this->namespace,
			'/namespaces/(?P<namespace>[^/]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'purge_namespace' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'namespace' => array(
						'description'       => __( 'Resource namespace to purge.', 'wpb-access-control' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_namespace' ),
					),
				),
			)
		);

		// GET  /providers
		register_rest_route(
			$this->namespace,
			'/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_providers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// GET  /users?search=...&limit=10
		register_rest_route(
			$this->namespace,
			'/users',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_users' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'search' => array(
						'description'       => __( 'Partial login, email, or display name to search for.', 'wpb-access-control' ),
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'  => array(
						'description'       => __( 'Maximum number of results to return (1–100).', 'wpb-access-control' ),
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission
	// -------------------------------------------------------------------------

	/**
	 * Permission callback shared by all endpoints.
	 *
	 * Returns true when the current user has `manage_options`. Use the
	 * `wpb_access_control_rest_permission` filter to widen or narrow access.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Current REST request.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		$default = current_user_can( 'manage_options' );

		/**
		 * Filter the base permission for all wpb-ac/v1 REST endpoints.
		 *
		 * @since 1.0.0
		 *
		 * @param bool            $can     Whether the request is permitted. Default: current_user_can('manage_options').
		 * @param WP_REST_Request $request The current REST request.
		 */
		$can = (bool) apply_filters( 'wpb_access_control_rest_permission', $default, $request );

		if ( ! $can ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage access control.', 'wpb-access-control' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Endpoint handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /rules/{namespace}/{key}
	 *
	 * Returns the stored rule for the given namespace/key pair.
	 * Returns `{"key":"","value":[]}` when no rule is configured.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_rule( WP_REST_Request $request ): WP_REST_Response {
		$rule = $this->manager->get_query()->get_rule(
			(string) $request->get_param( 'namespace' ),
			(string) $request->get_param( 'key' )
		);

		return rest_ensure_response( $rule );
	}

	/**
	 * PUT /rules/{namespace}/{key}
	 *
	 * Creates or replaces the rule for the given namespace/key pair.
	 * Responds with the rule as written.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_rule( WP_REST_Request $request ) {
		$namespace  = (string) $request->get_param( 'namespace' );
		$key        = (string) $request->get_param( 'key' );
		$ac_key     = (string) $request->get_param( 'ac_key' );
		$ac_options = (array) $request->get_param( 'ac_options' );
		$user_id    = get_current_user_id();

		$error = $this->authorize_write( $namespace, $key, $user_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$saved = $this->manager->get_query()->set_rule( $namespace, $key, $ac_key, $ac_options );

		if ( ! $saved ) {
			return new WP_Error(
				'wpb_ac_save_failed',
				__( 'Failed to save the access control rule.', 'wpb-access-control' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a rule is saved successfully.
		 *
		 * @since 1.0.0
		 *
		 * @param string   $namespace  Saved resource namespace.
		 * @param string   $key        Saved resource key.
		 * @param string   $ac_key     Rule type slug.
		 * @param string[] $ac_options Rule options.
		 * @param int      $user_id    Current WordPress user ID.
		 */
		do_action( 'wpb_access_control_saved', $namespace, $key, $ac_key, $ac_options, $user_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'rule'    => $this->manager->get_query()->get_rule( $namespace, $key ),
			)
		);
	}

	/**
	 * DELETE /rules/{namespace}/{key}
	 *
	 * Removes all rows for the given namespace/key pair, reverting the resource
	 * to the "no restriction configured" state.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function clear_rule( WP_REST_Request $request ) {
		$namespace = (string) $request->get_param( 'namespace' );
		$key       = (string) $request->get_param( 'key' );
		$user_id   = get_current_user_id();

		$error = $this->authorize_write( $namespace, $key, $user_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$this->manager->get_query()->clear_rule( $namespace, $key );

		/** This action is documented in src/RestApi/RulesController.php */
		do_action( 'wpb_access_control_saved', $namespace, $key, '', array(), $user_id );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * DELETE /namespaces/{namespace}
	 *
	 * Removes every rule row belonging to the given namespace.
	 * Intended for use during plugin uninstall.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge_namespace( WP_REST_Request $request ) {
		$namespace = (string) $request->get_param( 'namespace' );
		$user_id   = get_current_user_id();

		$error = $this->authorize_write( $namespace, '*', $user_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$deleted = $this->manager->get_query()->purge_namespace( $namespace );

		return rest_ensure_response( array( 'deleted' => $deleted ) );
	}

	/**
	 * GET /providers
	 *
	 * Returns the list of registered providers and their selectable options.
	 * Useful for dynamically building a rule-configuration UI.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_providers( WP_REST_Request $request ): WP_REST_Response {
		$data = array();

		foreach ( $this->manager->get_providers() as $provider ) {
			$data[] = array(
				'id'        => $provider->get_id(),
				'label'     => $provider->get_label(),
				'options'   => $provider->get_options(),
				'available' => $provider->is_available(),
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * GET /users?search=...&limit=10
	 *
	 * Searches WordPress users by login, email, or display name.
	 * Required when building a UI for the `wp_user` provider.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function search_users( WP_REST_Request $request ): WP_REST_Response {
		$results = WpUserProvider::search_users(
			(string) $request->get_param( 'search' ),
			(int) $request->get_param( 'limit' )
		);

		return rest_ensure_response( $results );
	}

	// -------------------------------------------------------------------------
	// Validation callbacks
	// -------------------------------------------------------------------------

	/**
	 * Validate that a namespace value is non-empty and within the column limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Namespace parameter value.
	 *
	 * @return true|WP_Error
	 */
	public function validate_namespace( string $value ) {
		if ( '' === $value ) {
			return new WP_Error(
				'wpb_ac_invalid',
				__( 'Namespace must not be empty.', 'wpb-access-control' )
			);
		}

		if ( strlen( $value ) > RuleTable::NAMESPACE_LENGTH ) {
			return new WP_Error(
				'wpb_ac_invalid',
				__( 'Namespace exceeds maximum length.', 'wpb-access-control' )
			);
		}

		return true;
	}

	/**
	 * Validate that a key value is non-empty and within the column limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Key parameter value.
	 *
	 * @return true|WP_Error
	 */
	public function validate_key( string $value ) {
		if ( '' === $value ) {
			return new WP_Error(
				'wpb_ac_invalid',
				__( 'Key must not be empty.', 'wpb-access-control' )
			);
		}

		if ( strlen( $value ) > RuleTable::KEY_LENGTH ) {
			return new WP_Error(
				'wpb_ac_invalid',
				__( 'Key exceeds maximum length.', 'wpb-access-control' )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Authorize a write operation via the wpb_access_control_can_save filter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key ('*' for namespace-level purge).
	 * @param int    $user_id   Current user ID.
	 *
	 * @return true|WP_Error True when authorized; WP_Error on denial.
	 */
	private function authorize_write( string $namespace, string $key, int $user_id ) {
		/**
		 * Filter whether the current request may write access control for a target.
		 *
		 * @since 1.2.0 (originally on AccessControlUI; moved to RulesController in 1.0.0)
		 *
		 * @param bool   $can_save  Whether the save is allowed. Default true.
		 * @param string $namespace Resource namespace.
		 * @param string $key       Resource key ('*' for namespace-level purge).
		 * @param int    $user_id   Current WordPress user ID.
		 */
		$can_save = (bool) apply_filters( 'wpb_access_control_can_save', true, $namespace, $key, $user_id );

		if ( ! $can_save ) {
			return new WP_Error(
				'wpb_ac_forbidden',
				__( 'Not permitted to modify this access control target.', 'wpb-access-control' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Return the shared route args for {namespace} and {key} URL parameters.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array>
	 */
	private function get_ns_key_args(): array {
		return array(
			'namespace' => array(
				'description'       => __( 'Resource namespace. URL-encode slashes as %2F when the namespace contains them (e.g. procureco%2Fv1).', 'wpb-access-control' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_namespace' ),
			),
			'key'       => array(
				'description'       => __( 'Resource key within the namespace. Slashes are allowed.', 'wpb-access-control' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_key' ),
			),
		);
	}
}
