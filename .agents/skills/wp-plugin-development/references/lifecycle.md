# Lifecycle: activation, deactivation, uninstall, i18n

## Activation — `Includes\Activator::activate()`

Called via `register_activation_hook` in the bootstrap. Currently an empty static stub.

> **Important:** The bootstrap wires activation via a **namespaced function**, not a class method:
> ```php
> function wordpress_plugin_boilerplate_activate() {
>     require_once plugin_dir_path( __FILE__ ) . 'includes/Activator.php';
>     Includes\Activator::activate();
> }
> register_activation_hook( __FILE__, 'WordPress_Plugin_Boilerplate\wordpress_plugin_boilerplate_activate' );
> ```
> The Autoloader is not yet registered at hook-registration time, so the class file is
> `require_once`d manually. Do not refactor this to an instance method or rely on the autoloader here.

Add here: database table creation, default option values, custom role/capability setup,
flush rewrite rules (only if CPTs or rewrite rules are registered on activation).

```php
public static function activate() {
    global $wpdb;
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}my_plugin_data (...)" );
    add_option( 'my_plugin_version', MY_PLUGIN_VERSION );
}
```

### Database version migrations

Use an option to track the installed schema version and run upgrades idempotently:

```php
public static function activate() {
    $installed = get_option( 'my_plugin_db_version', '0.0.0' );
    if ( version_compare( $installed, '1.1.0', '<' ) ) {
        // run migration SQL
    }
    update_option( 'my_plugin_db_version', MY_PLUGIN_VERSION );
}
```

## Deactivation — `Includes\Deactivator::deactivate()`

Called via `register_deactivation_hook`. Currently an empty static stub.

Add here: clear scheduled events (`wp_clear_scheduled_hook`), flush rewrite rules.
**Do not delete data here** — the user may reactivate the plugin.

## Uninstall — `uninstall.php`

Called by WordPress when the user clicks "Delete" on a plugin. The guard is already present:

```php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
```

Add here: `delete_option()`, `delete_site_option()`, custom table drops, user meta cleanup.
This is the **only** place for destructive data removal.

## i18n — `Includes\I18n::do_load_textdomain()`

```php
load_plugin_textdomain(
    'wordpress-plugin-boilerplate',
    false,
    plugin_basename( dirname( WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_FILE ) ) . '/languages/'
);
```

Hooked on `init` (not `plugins_loaded`). This is intentional per WP 6.7+ guidance — the
`init` hook ensures late-loading language packs from the translations API are available.
Do not move this to `plugins_loaded`.

The text domain matches the `Text Domain:` header in the bootstrap. If you renamed the plugin
with `init-plugin.sh`, both the header and the `load_plugin_textdomain()` call will have been
updated automatically.

- Upstream reference: `https://github.com/WPBoilerplate/wordpress-plugin-boilerplate/blob/main/includes/Activator.php`
