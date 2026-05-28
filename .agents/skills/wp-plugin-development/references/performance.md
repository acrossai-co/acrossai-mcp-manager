# Performance

## Autoloaded options

Every option stored with `autoload = yes` (the default) is loaded on **every page request** — even 404s. Keep total autoloaded data under 1 MB.

```php
// Check current autoload size (run in Query Monitor or WP-CLI):
// SELECT SUM(LENGTH(option_value)) FROM wp_options WHERE autoload = 'yes';

// When adding an option only used on settings pages — disable autoload:
add_option( 'myplugin_import_log', [], '', 'no' );      // 'no' = do not autoload
update_option( 'myplugin_import_log', $log, false );    // false = do not autoload (WP 6.0+)

// Options needed on every request (e.g. the plugin enabled/disabled flag):
update_option( 'myplugin_enabled', true, true );        // true = autoload (explicit)
```

Audit autoloaded options with WP-CLI:
```bash
wp db query "SELECT option_name, LENGTH(option_value) AS bytes FROM wp_options WHERE autoload='yes' ORDER BY bytes DESC LIMIT 20;"
```

## Transients

Use transients to cache expensive computations or remote API results. Always handle `false` (expired or missing):

```php
public function get_report_data(): array {
    $cache_key = 'myplugin_report_' . date( 'Ymd' );
    $cached    = get_transient( $cache_key );

    if ( $cached !== false ) {
        return $cached;  // Cache hit
    }

    // Cache miss — compute the expensive value
    $data = $this->fetch_report_from_db();

    set_transient( $cache_key, $data, HOUR_IN_SECONDS );

    return $data;
}

// Invalidate on save:
public function on_data_change(): void {
    delete_transient( 'myplugin_report_' . date( 'Ymd' ) );
}
```

On sites using a persistent object cache (Redis, Memcached), transients are stored in the object cache instead of `wp_options` — this is faster and avoids database writes.

Time constants: `MINUTE_IN_SECONDS`, `HOUR_IN_SECONDS`, `DAY_IN_SECONDS`, `WEEK_IN_SECONDS`.

## Object cache

Use the object cache for per-request caching (non-persistent) or site-wide caching when a persistent cache backend is installed.

```php
// Write
wp_cache_set( 'user_items_' . $user_id, $items, 'myplugin', 5 * MINUTE_IN_SECONDS );

// Read
$items = wp_cache_get( 'user_items_' . $user_id, 'myplugin' );
if ( $items === false ) {
    $items = $this->query_user_items( $user_id );
    wp_cache_set( 'user_items_' . $user_id, $items, 'myplugin', 5 * MINUTE_IN_SECONDS );
}

// Invalidate
wp_cache_delete( 'user_items_' . $user_id, 'myplugin' );
```

Always pass a **group** (`'myplugin'`) so cache keys do not collide with core or other plugins.

**Persistent vs non-persistent:** Without a cache backend (Redis/Memcached), `wp_cache_*` stores data only for the current PHP request (non-persistent). `set_transient()` writes to the database and persists across requests regardless of cache backend.

## Database queries

**Never query inside a loop.** Fetch all needed rows in a single query, then process in PHP.

```php
// WRONG — N+1 queries
foreach ( $user_ids as $user_id ) {
    $orders = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}orders WHERE user_id = $user_id" );
    // process...
}

// CORRECT — one query, process in PHP
$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
$all_orders = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}orders WHERE user_id IN ($placeholders)",
        ...$user_ids
    ),
    ARRAY_A
);

// Group by user_id in PHP
$by_user = [];
foreach ( $all_orders as $order ) {
    $by_user[ $order['user_id'] ][] = $order;
}
```

Prefer `WP_Query` for post queries — it integrates with the object cache and fires standard hooks:

```php
$query = new WP_Query( [
    'post_type'      => 'myplugin_product',
    'posts_per_page' => 20,
    'post_status'    => 'publish',
    'no_found_rows'  => true,   // Skip the COUNT(*) query if pagination is not needed
] );
```

Never use `query_posts()` — it replaces the main query and causes unpredictable side effects. Use `WP_Query` or `get_posts()` instead.

Use `$wpdb->get_results( $sql, ARRAY_A )` for custom table queries; `ARRAY_A` returns associative arrays which are safer than `OBJECT` when serializing.

## Asset loading

Only enqueue scripts and styles on the pages that need them.

```php
public function enqueue_admin_assets( string $hook_suffix ): void {
    // Only load on this plugin's settings page
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_myplugin-settings' ) {
        return;
    }

    wp_enqueue_style(
        'myplugin-settings',
        MYPLUGIN_PLUGIN_URL . 'build/css/settings.css',
        [],
        MYPLUGIN_VERSION
    );

    wp_enqueue_script(
        'myplugin-settings',
        MYPLUGIN_PLUGIN_URL . 'build/js/settings.js',
        [ 'wp-api-fetch', 'wp-components' ],
        MYPLUGIN_VERSION,
        true   // Load in footer
    );
}
```

Defer non-critical scripts to avoid render-blocking:

```php
wp_enqueue_script( 'myplugin-frontend', MYPLUGIN_PLUGIN_URL . 'build/js/frontend.js', [], MYPLUGIN_VERSION, true );
wp_script_add_data( 'myplugin-frontend', 'defer', true );
```

Note: `defer` support requires a `script_loader_tag` filter to actually emit the `defer` attribute in WP < 6.3. From WP 6.3+, `wp_script_add_data( $handle, 'strategy', 'defer' )` is the canonical approach.

## Remote HTTP requests

Always cache remote API responses with transients. Always check `is_wp_error()`.

```php
public function fetch_exchange_rate( string $currency ): float {
    $key    = 'myplugin_rate_' . sanitize_key( $currency );
    $cached = get_transient( $key );
    if ( $cached !== false ) {
        return (float) $cached;
    }

    $response = wp_remote_get( 'https://api.example.com/rates/' . rawurlencode( $currency ), [
        'timeout' => 5,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) ) {
        // Log and return a safe fallback — do not crash the page
        error_log( 'myplugin: rate fetch failed: ' . $response->get_error_message() );
        return 1.0;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        error_log( 'myplugin: rate API returned HTTP ' . $code );
        return 1.0;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $rate = (float) ( $body['rate'] ?? 1.0 );

    set_transient( $key, $rate, 15 * MINUTE_IN_SECONDS );
    return $rate;
}
```

## Cron: only schedule if background work is truly needed

```php
// On activation — schedule the event
register_activation_hook( __FILE__, function () {
    if ( ! wp_next_scheduled( 'myplugin_daily_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'myplugin_daily_sync' );
    }
} );

// On deactivation — remove the event
register_deactivation_hook( __FILE__, function () {
    $timestamp = wp_next_scheduled( 'myplugin_daily_sync' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'myplugin_daily_sync' );
    }
} );

// The cron callback
$this->loader->add_action( 'myplugin_daily_sync', $this, 'run_daily_sync' );

public function run_daily_sync(): void {
    // Keep cron callbacks fast — offload heavy work to a queue or batching system.
}
```

Always provide a WP-CLI command for manual triggering:

```bash
wp eval 'do_action("myplugin_daily_sync");'
# Or with a custom WP-CLI command:
wp myplugin sync
```

Document cron jobs in the plugin readme so server administrators know what background work runs.

## Upstream reference

https://developer.wordpress.org/plugins/performance/
