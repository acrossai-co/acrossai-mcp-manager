# File handling

## Always use `wp_handle_upload()`

Never move uploaded files manually with `move_uploaded_file()`. Use `wp_handle_upload()` — it validates, moves, and returns the final URL and path.

```php
public function handle_upload(): void {
    check_admin_referer( 'myplugin_upload', 'nonce' );

    if ( ! current_user_can( 'upload_files' ) ) {
        wp_die( esc_html__( 'You do not have permission to upload files.', 'myplugin' ) );
    }

    if ( empty( $_FILES['myplugin_csv'] ) || $_FILES['myplugin_csv']['error'] !== UPLOAD_ERR_OK ) {
        wp_die( esc_html__( 'Upload error.', 'myplugin' ) );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';

    $overrides = [
        'test_form' => false,   // We already verified the nonce
        'mimes'     => [
            'csv' => 'text/csv',
            'txt' => 'text/plain',
        ],
    ];

    $upload = wp_handle_upload( $_FILES['myplugin_csv'], $overrides );

    if ( isset( $upload['error'] ) ) {
        wp_die( esc_html( $upload['error'] ) );
    }

    // $upload['file'] = absolute server path
    // $upload['url']  = public URL
    // $upload['type'] = detected MIME type
    $this->process_csv( $upload['file'] );
}
```

## Validate MIME type with `wp_check_filetype_and_ext()`

File extensions are easily spoofed. The function checks the actual file content (using `finfo` or `getimagesize` depending on file type) against the reported extension.

```php
require_once ABSPATH . 'wp-admin/includes/file.php';

$file_path = $upload['file'];
$file_name = basename( $file_path );

$file_info = wp_check_filetype_and_ext( $file_path, $file_name );

$allowed_mime_types = [ 'image/jpeg', 'image/png', 'image/gif', 'application/pdf' ];

if ( ! in_array( $file_info['type'], $allowed_mime_types, true ) ) {
    wp_delete_file( $file_path );   // Clean up
    wp_die( esc_html__( 'Invalid file type. Only JPEG, PNG, GIF, and PDF are allowed.', 'myplugin' ) );
}
```

## Restrict allowed MIME types via `upload_mimes`

Use the `upload_mimes` filter to restrict what WordPress accepts globally (for your plugin's upload contexts) or to add custom types:

```php
// Remove PHP and executable files from allowed uploads (extra safety)
add_filter( 'upload_mimes', function ( array $mimes ): array {
    // These should never be in the default list, but remove defensively
    unset( $mimes['php'], $mimes['phtml'], $mimes['phar'] );
    return $mimes;
} );

// Add a custom MIME type (e.g. SVG — only if you have a sanitizer in place)
add_filter( 'upload_mimes', function ( array $mimes ): array {
    if ( current_user_can( 'manage_options' ) ) {
        $mimes['svg'] = 'image/svg+xml';
    }
    return $mimes;
} );
```

**Never allow PHP, `.phtml`, `.phar`, `.exe`, `.sh`, or any executable file extension.**

## Store uploads in `wp_upload_dir()` paths

Never store user-uploaded files inside the plugin directory — it is web-accessible and may be overwritten on plugin update.

```php
$upload_dir = wp_upload_dir();   // Returns basedir, baseurl, path, url, subdir, error

// Plugin-specific subdirectory inside uploads
$plugin_upload_dir = $upload_dir['basedir'] . '/myplugin-imports/';
if ( ! file_exists( $plugin_upload_dir ) ) {
    wp_mkdir_p( $plugin_upload_dir );
    // Protect directory listing
    file_put_contents( $plugin_upload_dir . 'index.php', '<?php // Silence is golden.' );
}
```

Use `wp_mkdir_p()` instead of `mkdir()` — it handles recursive directory creation and is cross-platform.

## `WP_Filesystem` API for plugin-generated files

Never use raw `file_put_contents()` on paths that could be influenced by user input. Use the `WP_Filesystem` API for writing plugin-generated files.

```php
public function write_config_file( array $config ): bool {
    global $wp_filesystem;

    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    WP_Filesystem();

    $content   = wp_json_encode( $config, JSON_PRETTY_PRINT );
    $file_path = WP_CONTENT_DIR . '/uploads/myplugin/config.json';

    if ( ! $wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE ) ) {
        error_log( '[myplugin] Failed to write config file.' );
        return false;
    }

    return true;
}
```

`WP_Filesystem` abstracts over direct file access, FTP, and SSH — required for compatibility with all hosting environments.

## Directory traversal prevention

Never pass `$_GET` or `$_POST` values directly to `include`, `require`, `readfile`, `file_get_contents`, or `fopen`.

```php
// WRONG — allows ../../wp-config.php traversal
$template = $_GET['tpl'];
include MYPLUGIN_TEMPLATES_DIR . '/' . $template . '.php';

// CORRECT — allowlist approach
$allowed = [ 'invoice', 'receipt', 'summary' ];
$tpl = sanitize_key( wp_unslash( $_GET['tpl'] ?? '' ) );
if ( ! in_array( $tpl, $allowed, true ) ) {
    wp_die( 'Invalid template.' );
}
include MYPLUGIN_TEMPLATES_DIR . '/' . $tpl . '.php';
```

When an allowlist is not practical, use `realpath()` and verify the resolved path is within the expected directory:

```php
public function read_export_file( string $filename ): string {
    $base      = realpath( WP_CONTENT_DIR . '/uploads/myplugin/exports/' );
    $requested = realpath( $base . '/' . sanitize_file_name( $filename ) );

    if ( $requested === false || strpos( $requested, $base . DIRECTORY_SEPARATOR ) !== 0 ) {
        wp_die( esc_html__( 'Access denied.', 'myplugin' ) );
    }

    return file_get_contents( $requested );
}
```

`realpath()` returns `false` if the file does not exist — always check for `false` before using the result.

## Nonce and capability check before any upload handler

Every upload entry point must verify both a nonce and the user's capability before processing the file:

```php
// Form: include both nonce and file input
<form method="post" enctype="multipart/form-data">
    <?php wp_nonce_field( 'myplugin_upload', 'myplugin_upload_nonce' ); ?>
    <input type="file" name="myplugin_csv">
    <?php submit_button( __( 'Import', 'myplugin' ) ); ?>
</form>

// Handler:
public function process_upload_form(): void {
    if ( ! isset( $_POST['myplugin_upload_nonce'] ) ) {
        return;
    }
    check_admin_referer( 'myplugin_upload', 'myplugin_upload_nonce' );
    if ( ! current_user_can( 'upload_files' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'myplugin' ) );
    }
    // process $_FILES['myplugin_csv']...
}
```

## Upstream reference

https://developer.wordpress.org/apis/filesystem/
