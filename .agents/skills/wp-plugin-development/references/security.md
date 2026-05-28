# Security

Every item in this checklist must be applied. No exceptions.

## Nonce verification

Use `wp_nonce_field()` in forms, `check_admin_referer()` in form handlers, and `check_ajax_referer()` in AJAX handlers.

```php
// In the form template:
<form method="post" action="options.php">
    <?php wp_nonce_field( 'myplugin_save_settings', 'myplugin_nonce' ); ?>
    <!-- fields -->
</form>

// In the form handler (admin-post.php or admin_init):
public function handle_settings_save(): void {
    check_admin_referer( 'myplugin_save_settings', 'myplugin_nonce' );
    // Proceeds only if nonce is valid. wp_die() on failure.
}

// In an AJAX handler:
public function handle_ajax(): void {
    check_ajax_referer( 'myplugin_ajax_action', 'nonce' );
    // ...
    wp_send_json_success( $data );
}
```

Nonces expire in 12–24 hours. Generate a fresh nonce per form render; do not hard-code or cache them.

## Capability checks

Always verify the current user has permission before performing any admin action or data mutation.

```php
public function delete_item(): void {
    check_admin_referer( 'myplugin_delete_item', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'myplugin' ) );
    }

    $item_id = absint( $_POST['item_id'] ?? 0 );
    // proceed with deletion...
}
```

Use the least-privileged capability that makes sense:
- `manage_options` — plugin settings
- `edit_posts` — content creation/editing
- `upload_files` — file uploads
- Custom capabilities — for plugin-specific roles via `add_cap()`

## Input handling: sanitize early

Apply `wp_unslash()` before any `sanitize_*()` function. Never trust raw `$_POST` or `$_GET`.

```php
// Text field
$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

// Email
$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

// URL
$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );

// Integer
$count = absint( $_POST['count'] ?? 0 );

// Textarea / rich content (allowed HTML tags)
$description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );

// Slug / key
$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
```

`wp_unslash()` is necessary because WordPress adds slashes to all superglobals on some server configurations (magic quotes behaviour).

## Output escaping: escape late, close to output

Escape immediately before echoing. Never store pre-escaped values in the database.

```php
// HTML text content
echo esc_html( $user_name );

// HTML attribute
echo '<input value="' . esc_attr( $saved_value ) . '">';

// URL in href / src
echo '<a href="' . esc_url( $redirect_url ) . '">';

// Rich HTML content (post content, editor output)
echo wp_kses_post( $post_content );

// JavaScript string context
echo '<script>var name = ' . esc_js( $user_name ) . ';</script>';

// JSON in a data attribute or inline script — use wp_json_encode
echo 'var config = ' . wp_json_encode( $config_array ) . ';';
```

Never do `echo __( 'String', 'domain' )` — use `esc_html_e()` or `esc_html__()` instead (see i18n.md).

## SQL safety: always use `$wpdb->prepare()`

Never concatenate user input into a SQL string.

```php
global $wpdb;

// WRONG — SQL injection risk
$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}items WHERE status = '" . $_GET['status'] . "'" );

// CORRECT — placeholders: %d (int), %s (string), %f (float)
$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}items WHERE status = %s AND user_id = %d",
        $status,
        get_current_user_id()
    ),
    ARRAY_A
);
```

For `IN()` clauses, build the placeholder string dynamically:

```php
$ids       = array_map( 'absint', $_POST['ids'] ?? [] );
$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
$results   = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}items WHERE id IN ($placeholders)", ...$ids ),
    ARRAY_A
);
```

## CSRF: nonce on every state-changing form and AJAX handler

Every form that creates, updates, or deletes data must include a nonce field. Every AJAX handler that mutates state must verify a nonce before acting. Read-only AJAX endpoints should still include a nonce to prevent request forgery that leaks data to third parties.

```php
// In wp_localize_script:
wp_localize_script( 'myplugin-admin', 'MyPluginData', [
    'nonce'   => wp_create_nonce( 'myplugin_ajax' ),
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
] );

// AJAX handler (PHP):
add_action( 'wp_ajax_myplugin_save', [ $this, 'ajax_save' ] );
public function ajax_save(): void {
    check_ajax_referer( 'myplugin_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }
    // ... process ...
    wp_send_json_success( $result );
}
```

## XSS: escape all user-controlled output

Never echo a `$_GET` or `$_POST` value without escaping. This includes values retrieved from the database if they were originally stored from user input.

```php
// WRONG
echo $_GET['tab'];

// CORRECT
$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
echo esc_html( $tab );
```

Attributes require `esc_attr()`, not `esc_html()`:

```php
// WRONG (esc_html allows quotes through in attribute context)
echo '<input class="' . esc_html( $class ) . '">';

// CORRECT
echo '<input class="' . esc_attr( $class ) . '">';
```

## File uploads

Never move files manually. Use `wp_handle_upload()`.  
Never trust the file extension — validate MIME type with `wp_check_filetype_and_ext()`.

```php
public function handle_upload(): void {
    check_admin_referer( 'myplugin_upload', 'nonce' );
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'myplugin' ) );
    }

    if ( empty( $_FILES['myplugin_file'] ) ) {
        wp_die( esc_html__( 'No file uploaded.', 'myplugin' ) );
    }

    // Restrict allowed types before calling wp_handle_upload
    $allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'application/pdf' ];

    $overrides = [
        'test_form' => false,
        'mimes'     => [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'gif'      => 'image/gif',
            'pdf'      => 'application/pdf',
        ],
    ];

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $upload = wp_handle_upload( $_FILES['myplugin_file'], $overrides );

    if ( isset( $upload['error'] ) ) {
        wp_die( esc_html( $upload['error'] ) );
    }

    // Validate MIME type on the moved file
    $file_info = wp_check_filetype_and_ext( $upload['file'], $upload['file'] );
    if ( ! in_array( $file_info['type'], $allowed_types, true ) ) {
        wp_delete_file( $upload['file'] );
        wp_die( esc_html__( 'Invalid file type.', 'myplugin' ) );
    }
}
```

Never allow PHP, `.phtml`, `.phar`, or executable files through the `upload_mimes` filter.

## REST API: always provide `permission_callback`

Every `register_rest_route()` call must include a `permission_callback`. Never use `__return_true` on routes that mutate data.

```php
register_rest_route( 'myplugin/v1', '/items', [
    'methods'             => 'GET',
    'callback'            => [ $this, 'get_items' ],
    'permission_callback' => function () {
        // Read endpoint: at minimum verify user is logged in
        return is_user_logged_in();
    },
] );

register_rest_route( 'myplugin/v1', '/items/(?P<id>\d+)', [
    'methods'             => 'POST',
    'callback'            => [ $this, 'update_item' ],
    'permission_callback' => function () {
        return current_user_can( 'manage_options' );
    },
    'args' => [
        'id' => [
            'validate_callback' => fn( $v ) => is_numeric( $v ),
            'sanitize_callback' => 'absint',
        ],
    ],
] );
```

## Object injection: never unserialize untrusted data

`unserialize()` on attacker-controlled data enables PHP object injection, which can lead to remote code execution. Use JSON for structured data storage and transport.

```php
// WRONG
$data = unserialize( get_option( 'myplugin_data' ) );

// CORRECT — store as JSON
update_option( 'myplugin_data', wp_json_encode( $data ) );
$data = json_decode( get_option( 'myplugin_data', '{}' ), true ) ?? [];
```

If you must read legacy serialized data from your own database rows (not user input), use `maybe_unserialize()` on values you stored yourself — never on data that arrived from a request.

## Directory traversal: never concatenate user input into file paths

```php
// WRONG — user can pass ../../wp-config.php
$file = MYPLUGIN_TEMPLATES_DIR . '/' . $_GET['template'] . '.php';
include $file;

// CORRECT — allowlist approach
$allowed_templates = [ 'dashboard', 'settings', 'report' ];
$requested = sanitize_key( wp_unslash( $_GET['template'] ?? '' ) );
if ( ! in_array( $requested, $allowed_templates, true ) ) {
    wp_die( 'Invalid template.' );
}
include MYPLUGIN_TEMPLATES_DIR . '/' . $requested . '.php';
```

When an allowlist is not practical, use `realpath()` and verify the resolved path starts with the expected directory:

```php
$base_dir  = realpath( MYPLUGIN_UPLOADS_DIR );
$requested = realpath( MYPLUGIN_UPLOADS_DIR . '/' . sanitize_file_name( $filename ) );

if ( $requested === false || strpos( $requested, $base_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
    wp_die( 'Invalid file path.' );
}
readfile( $requested );
```

## Upstream reference

https://developer.wordpress.org/apis/security/
