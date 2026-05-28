# REST API

## Register routes on `rest_api_init`

Always hook `register_rest_route()` to the `rest_api_init` action. Never call it on `init` or earlier.

```php
// In includes/Main.php — registered through the Loader:
$api = new \MyPlugin\Api\ItemsController( $this->plugin_name, $this->version );
$this->loader->add_action( 'rest_api_init', $api, 'register_routes' );
```

## Namespace format: `{plugin-prefix}/v1`

```php
// Correct namespace
register_rest_route( 'myplugin/v1', '/items', [ ... ] );

// Wrong — no version segment
register_rest_route( 'myplugin', '/items', [ ... ] );
```

Increment the version (`v2`, `v3`) when introducing breaking changes, not for additive additions.

## Full controller example

```php
namespace MyPlugin\Api;

class ItemsController extends \WP_REST_Controller {

    protected string $namespace = 'myplugin/v1';
    protected string $rest_base = 'items';

    public function register_routes(): void {
        // Collection: GET /myplugin/v1/items
        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_items' ],
                'permission_callback' => [ $this, 'get_items_permissions_check' ],
                'args'                => $this->get_collection_params(),
            ],
            // Collection: POST /myplugin/v1/items
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_item' ],
                'permission_callback' => [ $this, 'create_item_permissions_check' ],
                'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
            ],
            'schema' => [ $this, 'get_public_item_schema' ],
        ] );

        // Single item: GET|PUT|PATCH|DELETE /myplugin/v1/items/(?P<id>\d+)
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_item' ],
                'permission_callback' => [ $this, 'get_item_permissions_check' ],
                'args'                => [ 'context' => $this->get_context_param( [ 'default' => 'view' ] ) ],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_item' ],
                'permission_callback' => [ $this, 'update_item_permissions_check' ],
                'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_item' ],
                'permission_callback' => [ $this, 'delete_item_permissions_check' ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Permission callbacks — never omit, never __return_true on write endpoints
    // -------------------------------------------------------------------------

    public function get_items_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
        return is_user_logged_in();
    }

    public function create_item_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'You cannot create items.', 'myplugin' ), [ 'status' => 403 ] );
        }
        return true;
    }

    public function update_item_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
        return current_user_can( 'manage_options' );
    }

    public function delete_item_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
        return current_user_can( 'manage_options' );
    }

    public function get_item_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
        return is_user_logged_in();
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        global $wpdb;
        $per_page = absint( $request->get_param( 'per_page' ) ?? 10 );
        $page     = absint( $request->get_param( 'page' ) ?? 1 );
        $offset   = ( $page - 1 ) * $per_page;

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}myplugin_items ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return rest_ensure_response( array_map( [ $this, 'prepare_item_for_response' ], $items, array_fill( 0, count( $items ), $request ) ) );
    }

    public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $title  = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
        $status = sanitize_key( $request->get_param( 'status' ) ?? 'draft' );

        if ( empty( $title ) ) {
            return new \WP_Error( 'rest_invalid_param', __( 'Title is required.', 'myplugin' ), [ 'status' => 400 ] );
        }

        $wpdb->insert(
            $wpdb->prefix . 'myplugin_items',
            [ 'title' => $title, 'status' => $status, 'created_at' => current_time( 'mysql' ) ],
            [ '%s', '%s', '%s' ]
        );

        if ( ! $wpdb->insert_id ) {
            return new \WP_Error( 'rest_db_error', __( 'Could not create item.', 'myplugin' ), [ 'status' => 500 ] );
        }

        $response = rest_ensure_response( [ 'id' => $wpdb->insert_id ] );
        $response->set_status( 201 );
        return $response;
    }

    public function prepare_item_for_response( mixed $item, \WP_REST_Request $request ): \WP_REST_Response {
        return rest_ensure_response( [
            'id'         => absint( $item['id'] ),
            'title'      => $item['title'],
            'status'     => $item['status'],
            'created_at' => $item['created_at'],
        ] );
    }

    // Full get_item, update_item, delete_item follow the same pattern.
}
```

## Sanitize args in route schema

Define `sanitize_callback` and `validate_callback` in the route `args` — the REST API will call them before your handler runs.

```php
'args' => [
    'title' => [
        'description'       => __( 'Item title.', 'myplugin' ),
        'type'              => 'string',
        'required'          => true,
        'sanitize_callback' => 'sanitize_text_field',
        'validate_callback' => function ( $value ) {
            return is_string( $value ) && strlen( $value ) <= 200;
        },
    ],
    'status' => [
        'description'       => __( 'Item status.', 'myplugin' ),
        'type'              => 'string',
        'enum'              => [ 'draft', 'active', 'archived' ],
        'default'           => 'draft',
        'sanitize_callback' => 'sanitize_key',
    ],
],
```

## Return `WP_REST_Response` or `WP_Error`

```php
// Success
return new \WP_REST_Response( $data, 200 );

// Created
return new \WP_REST_Response( $data, 201 );

// Error — use WP_Error; REST API converts it to a JSON error response
return new \WP_Error( 'not_found', __( 'Item not found.', 'myplugin' ), [ 'status' => 404 ] );
```

Use `rest_ensure_response()` when you are unsure whether the value is already a `WP_REST_Response`:
```php
return rest_ensure_response( $data );
```

## Authentication

**Cookie authentication (browser requests):**
```php
// Pass a nonce to JavaScript via wp_localize_script:
wp_localize_script( 'myplugin-app', 'MyPluginApi', [
    'root'  => esc_url_raw( rest_url() ),
    'nonce' => wp_create_nonce( 'wp_rest' ),
] );

// In the JS fetch call, include the X-WP-Nonce header:
// fetch( MyPluginApi.root + 'myplugin/v1/items', {
//     headers: { 'X-WP-Nonce': MyPluginApi.nonce }
// } );
```

**Application passwords (external / server-to-server):**
Available since WP 5.6. Users generate credentials at `Profile → Application Passwords`. Clients send `Authorization: Basic base64(user:app-password)`.

**OAuth 2.0:**
Use an OAuth plugin (e.g. WP OAuth Server) for third-party delegated access. Never implement OAuth from scratch.

## Exposing a CPT via the REST API

Set `show_in_rest => true` when registering the post type. Optionally override the REST base:

```php
register_post_type( 'myplugin_product', [
    'label'        => __( 'Products', 'myplugin' ),
    'show_in_rest' => true,
    'rest_base'    => 'myplugin-products',         // /wp/v2/myplugin-products
    'supports'     => [ 'title', 'editor', 'custom-fields' ],
    // ...
] );
```

Custom meta fields must also opt in:
```php
register_post_meta( 'myplugin_product', 'price', [
    'show_in_rest'  => true,
    'single'        => true,
    'type'          => 'number',
    'auth_callback' => fn() => current_user_can( 'edit_posts' ),
] );
```

## Rate limiting and abuse prevention

WordPress core does not provide built-in rate limiting for REST endpoints. Apply these mitigations:

- **Nonce authentication** for all browser-initiated requests — nonces are single-use within their 12-hour window.
- **Require authentication** (`is_user_logged_in()` at minimum) on any endpoint that is not genuinely public.
- **Throttle expensive endpoints** using transients: store a request count keyed by user ID + time window; return a `429` `WP_Error` when the limit is exceeded.
- **Server-level rate limiting** (NGINX `limit_req_zone`, Cloudflare rate-limiting rules) is more reliable than PHP-level throttling for true DoS scenarios.

```php
public function check_rate_limit( int $user_id, string $action, int $limit = 30 ): bool {
    $key     = "myplugin_ratelimit_{$user_id}_{$action}";
    $current = (int) get_transient( $key );
    if ( $current >= $limit ) {
        return false; // Over limit
    }
    set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
    return true;
}
```

## Upstream reference

https://developer.wordpress.org/rest-api/
