# Debugging

## WordPress debug constants

Add these to `wp-config.php` in your development environment. **Never enable `WP_DEBUG_DISPLAY` or `SAVEQUERIES` in production.**

```php
// wp-config.php (development only)
define( 'WP_DEBUG',         true );   // Enable error reporting
define( 'WP_DEBUG_LOG',     true );   // Write errors to wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false );  // Do NOT show errors on screen (use debug.log instead)
define( 'SCRIPT_DEBUG',     true );   // Load unminified JS/CSS
define( 'SAVEQUERIES',      true );   // Log all DB queries (see below)
```

In production, `WP_DEBUG` should be `false`. If you must log errors in production, set only `WP_DEBUG_LOG` and ensure `display_errors` is off at the PHP level.

## Quick value dumps

```php
// Log any value to wp-content/debug.log
error_log( print_r( $some_array, true ) );
error_log( 'myplugin: user_id = ' . get_current_user_id() );

// Tag your logs so you can grep them:
error_log( '[myplugin] Activation hook fired' );
```

Prefix your log messages with the plugin slug so you can filter them:
```bash
tail -f wp-content/debug.log | grep '\[myplugin\]'
```

For production-safe profiling use **Query Monitor** plugin — it attaches to `admin_bar` and never outputs to the page body.

## Tracing all hooks

Log every hook that fires to identify execution order or find where a hook is missing:

```php
// Add temporarily in a must-use plugin or functions.php — remove before deployment
add_action( 'all', function ( string $hook ) {
    if ( str_starts_with( $hook, 'myplugin' ) ) {
        error_log( '[hook trace] ' . $hook );
    }
} );
```

Without the prefix filter, this logs hundreds of hooks per request — narrow it down to your plugin's hooks or a specific prefix.

## Diagnosing hook issues

```php
// Has a hook been registered?
if ( has_action( 'admin_menu', [ $my_object, 'register_menu' ] ) ) {
    error_log( '[myplugin] admin_menu callback is registered' );
}

// Has the hook already fired?
if ( did_action( 'init' ) ) {
    error_log( '[myplugin] init has already fired — too late to register CPTs' );
}

// Is this hook currently firing?
if ( doing_action( 'save_post' ) ) {
    error_log( '[myplugin] we are inside save_post right now' );
}

// How many times has a hook fired?
$count = did_action( 'save_post' );
error_log( "[myplugin] save_post has fired $count times" );
```

## Activation hook not firing

**Symptom:** Activation hook callback is never called.

**Root cause:** The hook must be registered at the plugin file root scope. If it is called inside a class `__construct()`, an `init` callback, or any deferred location, it will not fire.

```php
// WRONG — inside a class method called later
class MyPlugin {
    public function init(): void {
        register_activation_hook( __FILE__, [ $this, 'on_activate' ] ); // Will not fire.
    }
}

// CORRECT — root scope of the main plugin file
register_activation_hook( __FILE__, function () {
    // activation logic
} );
```

Note: `__FILE__` must refer to the main plugin file. If you call `register_activation_hook` from an included file, pass the main file path explicitly.

## Activation failure handling

If activation throws an exception, WordPress deactivates the plugin silently — no error message is shown by default. Wrap activation logic:

```php
register_activation_hook( __FILE__, function () {
    try {
        myplugin_create_tables();
        myplugin_seed_defaults();
    } catch ( \Exception $e ) {
        // Deactivate the plugin and show an error
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html( 'Plugin activation failed: ' . $e->getMessage() ),
            esc_html__( 'Activation Error', 'myplugin' ),
            [ 'back_link' => true ]
        );
    }
} );
```

## White screen / 500 errors

1. Check `wp-content/debug.log` (requires `WP_DEBUG_LOG = true`).
2. Check the PHP error log on your server (`/var/log/php-fpm/error.log` or similar).
3. Enable `WP_DEBUG_DISPLAY = true` temporarily on a non-public environment to see the error inline.
4. Disable plugins one by one (or rename the plugin directory) to isolate the cause.
5. Check for PHP syntax errors:
   ```bash
   php -l wp-content/plugins/myplugin/includes/Main.php
   ```

## Database query log

```php
// wp-config.php — development only
define( 'SAVEQUERIES', true );

// In a template or admin page, after queries have run:
global $wpdb;
if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
    error_log( print_r( $wpdb->queries, true ) );
}
```

Each entry in `$wpdb->queries` is `[ $sql, $elapsed_seconds, $backtrace ]`.

Using WP-CLI for query analysis:
```bash
# Count queries on the home page
wp profile url https://example.com --fields=query_count,cache_hits,time
```

## Upstream reference

https://developer.wordpress.org/plugins/plugin-basics/best-practices/#debugging
