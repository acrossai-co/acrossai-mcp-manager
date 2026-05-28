# Admin UX

## Admin notices

Use the `admin_notices` action hook. Apply the correct class for the severity. Always include `is-dismissible` unless the notice is truly critical and must not be hidden.

```php
public function show_activation_notice(): void {
    $screen = get_current_screen();
    // Only show on relevant pages
    if ( ! $screen || $screen->id !== 'toplevel_page_myplugin-settings' ) {
        return;
    }

    if ( get_option( 'myplugin_show_welcome_notice' ) ) : ?>
        <div class="notice notice-success is-dismissible" data-notice-id="myplugin_welcome">
            <p>
                <?php esc_html_e( 'My Plugin is active. Configure it below.', 'myplugin' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=myplugin-settings' ) ); ?>">
                    <?php esc_html_e( 'Go to Settings', 'myplugin' ); ?>
                </a>
            </p>
        </div>
    <?php endif;
}
```

| Class | When to use |
|---|---|
| `notice-success` | Operation succeeded |
| `notice-warning` | Potential issue; action may still have completed |
| `notice-error` | Operation failed |
| `notice-info` | Neutral information |

Always add `is-dismissible` so the user can close the notice. WordPress JS handles the dismiss animation; persistence requires AJAX (see below).

## Persistent (dismissible) notices via AJAX

Store the dismissed state in user meta so the notice does not reappear after the user closes it.

```php
// Show notice only if user has not dismissed it
public function maybe_show_setup_notice(): void {
    if ( get_user_meta( get_current_user_id(), 'myplugin_setup_notice_dismissed', true ) ) {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible" id="myplugin-setup-notice">
        <p><?php esc_html_e( 'Complete setup to get started.', 'myplugin' ); ?></p>
    </div>
    <script>
    jQuery('#myplugin-setup-notice').on('click', '.notice-dismiss', function () {
        wp.ajax.post('myplugin_dismiss_notice', {
            nonce:     '<?php echo esc_js( wp_create_nonce( "myplugin_dismiss_notice" ) ); ?>',
            notice_id: 'setup_notice'
        });
    });
    </script>
    <?php
}

// AJAX handler
public function ajax_dismiss_notice(): void {
    check_ajax_referer( 'myplugin_dismiss_notice', 'nonce' );
    $notice_id = sanitize_key( wp_unslash( $_POST['notice_id'] ?? '' ) );
    update_user_meta( get_current_user_id(), 'myplugin_' . $notice_id . '_dismissed', true );
    wp_send_json_success();
}
```

## What not to do

- **No `position: fixed` full-screen overlays.** Do not cover the WP admin interface.
- **No forced redirects on every admin page load.** Redirect only directly after save or on the first-run setup flow.
- **No modal on every admin page.** Modals are acceptable on plugin pages; never show them on core admin pages.
- **No aggressive upsell.** One upsell placement per plugin settings page maximum. No upsell banners on Posts, Users, or other core admin pages.

## Redirect after save: `wp_safe_redirect()` + `exit`

Never use `header( 'Location: ...' )` directly.

```php
public function handle_form_submit(): void {
    check_admin_referer( 'myplugin_save', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Forbidden' );
    }

    // ... save logic ...
    update_option( 'myplugin_settings', $sanitized_data );

    // Redirect back to the settings page with a success message
    wp_safe_redirect(
        add_query_arg( [ 'page' => 'myplugin-settings', 'updated' => '1' ], admin_url( 'admin.php' ) )
    );
    exit;  // Always call exit after wp_safe_redirect
}

// In the settings page render method — show the notice after redirect
public function render_page(): void {
    if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', 'myplugin' ); ?></p>
        </div>
    <?php endif;
    // ... rest of page ...
}
```

`wp_safe_redirect()` validates that the destination is on the same host or an allowed list. For external redirects, use `wp_redirect()` and document why.

## Screen options and help tabs

Add screen options (e.g. items-per-page) and contextual help on the `load-{page-hook}` action:

```php
public function register_menu(): void {
    $page_hook = add_menu_page(
        __( 'My Plugin', 'myplugin' ),
        __( 'My Plugin', 'myplugin' ),
        'manage_options',
        'myplugin-settings',
        [ $this, 'render_page' ],
        'dashicons-admin-plugins'
    );

    add_action( 'load-' . $page_hook, [ $this, 'add_screen_options' ] );
    add_action( 'load-' . $page_hook, [ $this, 'add_help_tabs' ] );
}

public function add_screen_options(): void {
    add_screen_option( 'per_page', [
        'label'   => __( 'Items per page', 'myplugin' ),
        'default' => 20,
        'option'  => 'myplugin_items_per_page',
    ] );
}

public function add_help_tabs(): void {
    $screen = get_current_screen();
    $screen->add_help_tab( [
        'id'      => 'myplugin-overview',
        'title'   => __( 'Overview', 'myplugin' ),
        'content' => '<p>' . esc_html__( 'This page lets you configure My Plugin settings.', 'myplugin' ) . '</p>',
    ] );
    $screen->set_help_sidebar(
        '<p><strong>' . esc_html__( 'For more information:', 'myplugin' ) . '</strong></p>' .
        '<p><a href="https://example.com/docs" target="_blank">' . esc_html__( 'Documentation', 'myplugin' ) . '</a></p>'
    );
}

// Save the screen option value
add_filter( 'set_screen_option_myplugin_items_per_page', function ( $status, $option, $value ) {
    return absint( $value );
}, 10, 3 );
```

## Menu structure: keep it minimal

Use **one top-level menu item** per plugin. All additional pages go as sub-pages under that item:

```php
public function register_menu(): void {
    // One top-level entry
    add_menu_page(
        __( 'My Plugin', 'myplugin' ),
        __( 'My Plugin', 'myplugin' ),
        'manage_options',
        'myplugin',
        [ $this, 'render_dashboard' ],
        'dashicons-admin-plugins',
        80
    );

    // Sub-pages
    add_submenu_page( 'myplugin', __( 'Settings', 'myplugin' ), __( 'Settings', 'myplugin' ), 'manage_options', 'myplugin-settings', [ $settings_page, 'render' ] );
    add_submenu_page( 'myplugin', __( 'Import', 'myplugin' ),   __( 'Import', 'myplugin' ),   'manage_options', 'myplugin-import',   [ $import_page, 'render' ] );
}
```

Never add multiple top-level menu items unless the plugin has clearly separate, independent administration areas (e.g. WooCommerce, which manages products, orders, analytics, etc.).

## Upstream reference

https://developer.wordpress.org/plugins/administration-menus/
