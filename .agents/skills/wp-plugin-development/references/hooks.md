# Hooks: actions and filters

## Naming convention

Use the three-segment pattern `{plugin-prefix}/{context}/{action}` for every custom hook the plugin exposes publicly.

```
myplugin/admin/before_render
myplugin/settings/after_save
myplugin/order/status_changed
```

- `plugin-prefix` — the plugin's short identifier (e.g. `myplugin`, `procureco`)
- `context` — the subsystem or class responsible (e.g. `admin`, `public`, `api`, `order`)
- `action` — a past-tense or imperative verb phrase describing the event

For internal hooks not intended for third-party use, prefix with an underscore:
`myplugin/_internal/cache_warmed`

## Always register through the Loader

**Never** call `add_action()` or `add_filter()` directly inside a constructor or at module scope.
All hooks must be registered in `includes/Main.php` through `$this->loader->add_action()` / `$this->loader->add_filter()`.

```php
// includes/Main.php  — correct pattern
private function define_admin_hooks(): void {
    $admin = new \MyPlugin\Admin\Main( $this->plugin_name, $this->version );

    $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
    $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
    $this->loader->add_action( 'admin_menu',            $admin, 'register_menu' );
}
```

```php
// WRONG — bare add_action inside a constructor
class MyAdmin {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] ); // Never do this.
    }
}
```

The Loader pattern keeps all hook registrations in one place, making it trivial to audit and test.

## Hook timing reference

| Hook | When it fires | Typical use |
|---|---|---|
| `plugins_loaded` | After all active plugins are loaded, before theme | Cross-plugin compatibility checks; load text domain (legacy approach) |
| `init` | After WP, plugins, and theme are all loaded | Register CPTs, taxonomies, load text domain (recommended), rewrite rules |
| `admin_init` | Start of every admin request, after `init` | Settings registration, capability checks, redirect logic |
| `wp_enqueue_scripts` | Front-end asset enqueueing phase | Enqueue public CSS/JS |
| `admin_enqueue_scripts` | Admin asset enqueueing phase | Enqueue admin CSS/JS; receives `$hook_suffix` |
| `admin_menu` | After admin menu structure is registered | `add_menu_page()`, `add_submenu_page()` |
| `save_post` | After a post is saved to the DB | Save custom meta; fires for every post type |
| `wp_ajax_{action}` | AJAX request for logged-in user | Authenticated AJAX handlers |
| `wp_ajax_nopriv_{action}` | AJAX request for logged-out user | Public AJAX handlers |
| `rest_api_init` | REST API is initialised | `register_rest_route()` |

## Filter vs action: when to use which

**Use an action** when you want to allow code to run at a point in time but do not need a return value:
```php
// Defining the action
do_action( 'myplugin/order/status_changed', $order_id, $new_status, $old_status );

// Hooking into it
$this->loader->add_action( 'myplugin/order/status_changed', $this, 'send_status_email' );

public function send_status_email( int $order_id, string $new_status, string $old_status ): void {
    // Side effect only — no return value needed.
}
```

**Use a filter** when you want to give other code the opportunity to modify a value before it is used:
```php
// Defining the filter
$label = apply_filters( 'myplugin/order/status_label', __( 'Pending', 'myplugin' ), $order_id );

// Hooking into it — always return the value
$this->loader->add_filter( 'myplugin/order/status_label', $this, 'override_status_label', 10, 2 );

public function override_status_label( string $label, int $order_id ): string {
    if ( $order_id > 1000 ) {
        return __( 'Priority Pending', 'myplugin' );
    }
    return $label; // Always return — even when not modifying.
}
```

## Priority guidelines

| Priority | Use case |
|---|---|
| `5` | Must run before the default (e.g. override data before core processes it) |
| `10` | Default — use unless there is a specific ordering requirement |
| `20` | Must run after the default (e.g. clean up after core has processed) |
| `100`+ | Last resort — explicitly late, e.g. `plugin_action_links` at 1000 |

Always document a non-default priority with an inline comment explaining why.

```php
// Priority 5: we must register our CPT before 'init' priority 10 flushes rewrite rules.
$this->loader->add_action( 'init', $this, 'register_post_types', 5 );
```

## Never echo inside a filter callback

A filter callback must only return a value. Echoing inside a filter corrupts page output.

```php
// WRONG
public function bad_filter( string $content ): string {
    echo '<p>Debug</p>'; // This corrupts the page — never do this.
    return $content;
}

// CORRECT — use error_log for debugging; return the value
public function good_filter( string $content ): string {
    // error_log( 'content length: ' . strlen( $content ) );
    return $content . '<p class="myplugin-footer">Powered by MyPlugin</p>';
}
```

## Removing third-party hooks safely

When you need to remove a hook registered by another class instance, keep a reference or use a priority that matches exactly:

```php
// Store the instance when registering
$third_party = new ThirdParty\Widget();
$this->loader->add_action( 'init', $third_party, 'register', 10 );

// Later, to remove it you need the same instance and exact priority:
remove_action( 'init', [ $third_party, 'register' ], 10 );
```

## Upstream reference

https://developer.wordpress.org/plugins/hooks/
