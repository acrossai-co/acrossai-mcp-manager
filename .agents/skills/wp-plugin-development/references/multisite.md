# Multisite

## Detect multisite

```php
if ( is_multisite() ) {
    // Running as a WordPress Network
}
```

Call `is_multisite()` at runtime — never hard-code or cache the value in a class property, because tests may change the constant.

## Network admin vs site admin menus and notices

Use `network_admin_menu` to add pages that appear only in the Network Admin (`/wp-admin/network/`). Use the standard `admin_menu` for per-site pages.

```php
// Per-site admin menu (works on single-site and multisite)
$this->loader->add_action( 'admin_menu', $settings_page, 'register_menu' );

// Network-only admin menu
if ( is_multisite() ) {
    $this->loader->add_action( 'network_admin_menu', $network_settings_page, 'register_network_menu' );
}
```

Similarly for notices:

```php
// Fires on per-site admin pages
$this->loader->add_action( 'admin_notices', $this, 'show_site_notice' );

// Fires on the Network Admin dashboard
$this->loader->add_action( 'network_admin_notices', $this, 'show_network_notice' );
```

## Network-wide options vs per-site options

| Function | Scope | Storage table |
|---|---|---|
| `get_option()` / `update_option()` | Current site only | `{prefix}options` |
| `get_site_option()` / `update_site_option()` | Entire network | `wp_sitemeta` |
| `get_blog_option()` / `update_blog_option()` | Specific site by ID | `{prefix}options` |

```php
// Store a network-wide API key
update_site_option( 'myplugin_api_key', $api_key );
$api_key = get_site_option( 'myplugin_api_key', '' );

// Read a setting for a specific site without switching context
$setting = get_blog_option( $blog_id, 'myplugin_settings', [] );
```

## `switch_to_blog()` / `restore_current_blog()`

Always pair these calls. Always restore in a `finally` block so restoration happens even if an exception is thrown.

```php
public function process_all_sites(): void {
    if ( ! is_multisite() ) {
        $this->process_current_site();
        return;
    }

    $sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );

    foreach ( $sites as $blog_id ) {
        switch_to_blog( $blog_id );
        try {
            $this->process_current_site();
        } finally {
            restore_current_blog(); // Always executes, even on exception.
        }
    }
}
```

Never `switch_to_blog()` inside a REST API callback — the REST API runs in the context of the requested site.

## Activation on multisite

`register_activation_hook` fires once, for the site that activated the plugin. If the plugin is network-activated, it does **not** fire at all — you must handle network activation separately.

```php
// In the main plugin file:
register_activation_hook( __FILE__, function ( bool $network_wide ) {
    if ( $network_wide && is_multisite() ) {
        // Network activation: iterate all sites
        $sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
        foreach ( $sites as $blog_id ) {
            switch_to_blog( $blog_id );
            try {
                myplugin_activate_for_site();
            } finally {
                restore_current_blog();
            }
        }
    } else {
        // Single-site or per-site activation
        myplugin_activate_for_site();
    }
} );

function myplugin_activate_for_site(): void {
    // Create tables, seed options, etc. for the current blog.
    add_option( 'myplugin_settings', [], '', 'no' );
    myplugin_create_tables();
}
```

When a new site is added to the network after network-activation, hook `wpmu_new_blog` to activate for the new site:

```php
$this->loader->add_action( 'wpmu_new_blog', $this, 'activate_for_new_blog', 10, 6 );

public function activate_for_new_blog( int $blog_id ): void {
    if ( is_plugin_active_for_network( plugin_basename( MYPLUGIN_FILE ) ) ) {
        switch_to_blog( $blog_id );
        try {
            myplugin_activate_for_site();
        } finally {
            restore_current_blog();
        }
    }
}
```

## Uninstall: clean up site-level and network-level options

```php
// uninstall.php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 1. Delete network-level option
if ( is_multisite() ) {
    delete_site_option( 'myplugin_api_key' );
    delete_site_option( 'myplugin_network_settings' );
}

// 2. Delete per-site options on every site
if ( is_multisite() ) {
    $sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
    foreach ( $sites as $blog_id ) {
        switch_to_blog( $blog_id );
        try {
            delete_option( 'myplugin_settings' );
            delete_option( 'myplugin_import_log' );
            // Drop plugin tables if applicable
            myplugin_drop_tables();
        } finally {
            restore_current_blog();
        }
    }
} else {
    delete_option( 'myplugin_settings' );
    delete_option( 'myplugin_import_log' );
    myplugin_drop_tables();
}
```

## Cron on multisite

WordPress Cron spawns via an HTTP request to `wp-cron.php` on the **main site** by default. If your cron job needs to run for every site independently (e.g. syncing site-specific data), you must iterate sites manually from your cron callback:

```php
public function cron_sync(): void {
    if ( is_multisite() ) {
        $sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
        foreach ( $sites as $blog_id ) {
            switch_to_blog( $blog_id );
            try {
                $this->sync_current_site();
            } finally {
                restore_current_blog();
            }
        }
    } else {
        $this->sync_current_site();
    }
}
```

Document this behaviour in the plugin readme: "On multisite, this cron job iterates all sites. Execution time scales with the number of sites."

Consider providing a WP-CLI command for manual per-site triggers:

```bash
wp --url=https://site1.example.com myplugin sync
wp --url=https://site2.example.com myplugin sync
```

## Upstream reference

https://developer.wordpress.org/plugins/multisite/
