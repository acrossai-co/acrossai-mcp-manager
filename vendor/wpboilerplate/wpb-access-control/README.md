# wpb-access-control

Extensible per-resource access control library for WordPress plugins.

Answers one question: **"Does this user have access to this resource?"**

The library owns its own database table, ships a WordPress role provider
out of the box, and exposes a single method your plugin calls wherever it
needs to gate access — REST API, form submission, WP-CLI, anywhere.

---

## Requirements

- PHP 7.4+
- WordPress 5.9+
- `automattic/jetpack-autoloader` **^2.0** (mandatory — see below)

---

## Installation

```bash
composer require wpboilerplate/wpb-access-control
```

Your `composer.json` must include Jetpack Autoloader and the correct
stability settings:

```json
{
    "require": {
        "automattic/jetpack-autoloader": "^2.0",
        "wpboilerplate/wpb-access-control": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/WPBoilerplate/wpb-access-control.git"
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {
            "automattic/jetpack-autoloader": true
        }
    }
}
```

> **Why Jetpack Autoloader is mandatory**
>
> If two plugins both install this library at different versions, PHP will
> throw a fatal "class already declared" error. Jetpack Autoloader scans
> every installed plugin, finds all copies of the library, and loads only
> the newest version. Without it this library is unsafe to ship.

---

## Setup

### 1. Create the table

Call `maybe_create_table()` in **two** places so both fresh installs and
library upgrades are handled:

```php
// On activation hook
register_activation_hook( __FILE__, function () {
    WPBoilerplate\AccessControl\AccessControlTable::maybe_create_table();
} );

// On plugins_loaded
add_action( 'plugins_loaded', function () {
    WPBoilerplate\AccessControl\AccessControlTable::maybe_create_table();
} );
```

### 2. Boot the manager

Instantiate `AccessControlManager` early (e.g. in your `plugins_loaded`
callback). Always pass a **plugin-specific filter tag** to prevent your
providers from leaking into other plugins that also use this library.

```php
use WPBoilerplate\AccessControl\AccessControlManager;

$manager = new AccessControlManager( 'my_plugin_access_control_providers' );
```

---

## Checking access

Call `user_has_access()` wherever your plugin needs to gate access.
The library reads the stored rule from its own table and enforces it.

```php
$allowed = $manager->user_has_access(
    get_current_user_id(),   // int  — 0 = unauthenticated
    'my-namespace',          // string — your plugin's namespace
    'my-resource'            // string — the specific resource
);

if ( ! $allowed ) {
    // Decide what to do: WP_Error, wp_die, redirect, etc.
    wp_die( 'Access denied.', 403 );
}
```

### Access hierarchy

| Step | Condition | Result |
|------|-----------|--------|
| 1 | `access_control` empty or `type = everyone` | **Allow** |
| 2 | User has `manage_options` (administrator) | **Always allow** |
| 3 | User ID = 0 (unauthenticated) | **Deny** |
| 4 | No provider registered for the configured type | **Deny** |
| 5 | `provider->user_has_access()` | Allow or **Deny** |

---

## Storing and reading rules

Use `AccessControlTable` to read and write rules from your admin UI.

```php
use WPBoilerplate\AccessControl\AccessControlTable;

// Read the current rule (returns JSON string or '').
$raw = AccessControlTable::get( 'my-namespace', 'my-resource' );

// Save a rule — value is sanitized internally.
AccessControlTable::update(
    'my-namespace',
    'my-resource',
    '{"type":"wp_role","options":["editor","author"]}'
);

// Remove a rule (resource reverts to "everyone").
AccessControlTable::delete( 'my-namespace', 'my-resource' );

// Plugin uninstall — remove all rows for your namespace.
AccessControlTable::delete_all_for_namespace( 'my-namespace' );
```

### JSON config format

```json
{ "type": "wp_role", "options": ["editor", "author"] }
```

An empty string `""` means **no restriction** — all users pass.

---

## Registering a custom provider

```php
add_filter( 'my_plugin_access_control_providers', function( array $providers ) {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

The filter tag must match the string you passed to `AccessControlManager`.
The filter fires on `init` at priority 5 — register at priority 4 or earlier.

Each provider must extend `WPBoilerplate\AccessControl\AbstractProvider`:

| Method | Required | Description |
|--------|----------|-------------|
| `get_id(): string` | Yes | Unique key stored in JSON `type` field |
| `get_label(): string` | Yes | Label shown in admin UI dropdown |
| `get_options(): array` | Yes | `[['id'=>'slug','label'=>'Name'], ...]` |
| `user_has_access(int $user_id, array $selected): bool` | Yes | Core check |
| `is_available(): bool` | No | Return false when a dependency is missing |

### Example provider

```php
namespace My\Plugin;

use WPBoilerplate\AccessControl\AbstractProvider;

class MembershipProvider extends AbstractProvider {

    public function get_id(): string {
        return 'my_membership';
    }

    public function get_label(): string {
        return __( 'Membership Level', 'my-plugin' );
    }

    public function get_options(): array {
        return [
            [ 'id' => 'gold',   'label' => 'Gold'   ],
            [ 'id' => 'silver', 'label' => 'Silver' ],
        ];
    }

    public function user_has_access( int $user_id, array $selected_options ): bool {
        $level = my_get_membership_level( $user_id );
        return in_array( $level, $selected_options, true );
    }

    public function is_available(): bool {
        return function_exists( 'my_get_membership_level' );
    }
}
```

---

## Reacting to denied access

```php
add_action( 'wpb_access_control_denied', function( $user_id, $namespace, $key, $ac_config ) {
    // Log, notify, increment a counter, etc.
    error_log( "Access denied — user:{$user_id} {$namespace}/{$key}" );
}, 10, 4 );
```

---

## Built-in providers

| Provider ID | Class | Description |
|-------------|-------|-------------|
| `wp_role` | `WpRoleProvider` | Restricts by WordPress user role. Administrator excluded from options (always bypassed). |

### `WpRoleProvider` filters

| Filter | Description |
|--------|-------------|
| `wpb_access_control_wp_role_options` | Modify the list of selectable role options |
| `wpb_access_control_wp_role_has_access` | Override the final role-based access decision |

---

## Important notes

### Filter tag isolation
Always pass a plugin-specific tag to `AccessControlManager`. If two plugins
both use the default `'wpb_access_control_providers'`, their providers will
bleed into each other's admin UIs and enforcement logic.

### Table creation timing
Call `maybe_create_table()` on **both** the activation hook and
`plugins_loaded`. Activation alone misses upgrades delivered when the library
version bumps without the consuming plugin being reactivated.

### Object cache
`AccessControlTable::get()` results are cached. Always use `update()` and
`delete()` — never write to the table via raw `$wpdb`. Direct writes bypass
the cache and will serve stale data until the next page load.

### Administrator bypass is unconditional
Any user with `manage_options` always returns `true` from `user_has_access()`
regardless of the stored rule. This cannot be overridden per-plugin.

### Uninstall cleanup
The library never drops its own table. Each consuming plugin is responsible
for cleaning up its own rows on uninstall:

```php
// In uninstall.php
AccessControlTable::delete_all_for_namespace( 'my-namespace' );
```

Only drop the entire table if you are certain no other plugin is using it.

### Multisite
The table uses `$wpdb->prefix` — each sub-site has its own
`{prefix}wpb_access_control` table. Network-wide rules are not supported
by the library and must be handled by the consuming plugin.

---

## Database table reference

Table: `{prefix}wpb_access_control`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT PK AI | |
| `namespace` | VARCHAR(100) | Plugin-scoped prefix |
| `key` | VARCHAR(255) | Resource identifier |
| `access_control` | TEXT | JSON config or `''` |
| `created_at` | DATETIME | Set on INSERT |
| `updated_at` | DATETIME | Auto-updated on UPDATE |

Unique constraint: `(namespace, key)`
