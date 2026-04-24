# AGENTS.md — wpb-access-control

> Full reference for AI coding agents working on this repository.

---

## Package identity

| Field           | Value                                              |
|-----------------|----------------------------------------------------|
| Package name    | `wpboilerplate/wpb-access-control`                 |
| Type            | `library`                                          |
| PHP NS root     | `WPBoilerplate\AccessControl\`                     |
| PSR-4 root      | `src/`                                             |
| Current version | `1.0.0` (dev-main)                                 |
| Min PHP         | 7.4                                                |
| Min WP          | 5.9                                                |
| License         | GPL-2.0-or-later                                   |
| Repo            | `github.com/WPBoilerplate/wpb-access-control`      |

---

## Purpose

Answers one question: **"Does this user have access to this resource?"**

The library:
- Owns a standalone `{prefix}wpb_access_control` database table
- Provides a provider registry (WordPress roles built-in; extensible for any back-end)
- Exposes `AccessControlManager::user_has_access(int $user_id, string $namespace, string $key): bool`

The library does **not**:
- Hook `rest_pre_dispatch` or any other WordPress action
- Do route matching
- Know about REST API, MCP, procurement, or any product
- Decide what to do when access is denied

All of that is the consuming plugin's responsibility.

---

## Repository layout

```
src/
  AccessControlTable.php    Custom DB table: {prefix}wpb_access_control.
                             CRUD helpers, sanitization, object-cache integration.
                             Consuming plugins call maybe_create_table() on
                             activation and plugins_loaded.

  AccessControlManager.php  Provider registry + user_has_access().
                             No REST hooks. No fetcher. No mapper.
                             Consuming plugin decides when/where to call it.

  AbstractProvider.php      Abstract base class for all providers.
                             Every provider must extend this.

  WpRoleProvider.php        Built-in provider: restricts by WordPress user role.
                             Administrator role excluded (always bypassed in manager).

README.md                   Usage documentation for consuming plugins.
AGENTS.md                   This file.
composer.json               Package manifest.
```

---

## Database table

Table: `{prefix}wpb_access_control`
Class: `WPBoilerplate\AccessControl\AccessControlTable`
Current schema version: `1.0.0` (option: `wpb_access_control_db_version`)

| Column           | Type          | Notes                                              |
|------------------|---------------|----------------------------------------------------|
| `id`             | BIGINT PK AI  |                                                    |
| `namespace`      | VARCHAR(100)  | Product-scoped prefix, e.g. `mcp`, `procureco/v1` |
| `key`            | VARCHAR(255)  | Resource identifier within the namespace           |
| `access_control` | TEXT          | JSON config or `''` (everyone)                     |
| `created_at`     | DATETIME      | Set on INSERT                                      |
| `updated_at`     | DATETIME      | Auto-updated on UPDATE                             |

Unique constraint: `(namespace, key)` — one rule per resource.

### Public API

| Method | Description |
|--------|-------------|
| `maybe_create_table()` | No-op unless stored version differs. Call on activation + plugins_loaded. |
| `create_table()` | Runs dbDelta unconditionally. |
| `get(ns, key)` | Returns JSON string or `''`. Result is object-cached. |
| `update(ns, key, value)` | Upsert via INSERT … ON DUPLICATE KEY UPDATE. Sanitizes before storing. |
| `delete(ns, key)` | Deletes one row and flushes its cache entry. |
| `delete_all_for_namespace(ns)` | For plugin uninstall — removes all rows for a namespace. |
| `sanitize(raw)` | Static. Validates JSON and returns clean string or `''`. |

---

## AccessControlManager

Constructor: `__construct( string $providers_filter = 'wpb_access_control_providers' )`

**Always pass a plugin-specific filter tag** to avoid provider leakage between plugins installed on the same site.

### Public API

| Method | Description |
|--------|-------------|
| `load_providers()` | Fires the providers filter and rebuilds the registry. Called on init:5 or immediately if init has fired. |
| `get_providers()` | Returns `array<string, AbstractProvider>` keyed by provider ID. |
| `get_provider(id)` | Returns one provider or null. |
| `user_has_access(user_id, namespace, key)` | Core method. Reads from AccessControlTable and applies access hierarchy. |

### Access hierarchy

1. `access_control` empty or `type = 'everyone'` → **allow**
2. User has `manage_options` (administrator) → **always allow**
3. User ID = 0 (unauthenticated) → **deny** + fires `wpb_access_control_denied`
4. No provider registered for the configured type → **deny** + fires `wpb_access_control_denied`
5. `provider->user_has_access()` returns false → **deny** + fires `wpb_access_control_denied`

### `wpb_access_control_denied` action

Fires on every denial (steps 3–5 above).

```php
do_action( 'wpb_access_control_denied', int $user_id, string $namespace, string $key, array $ac_config );
```

---

## Provider contract (`AbstractProvider`)

| Method | Required | Purpose |
|--------|----------|---------|
| `get_id(): string` | Yes | Unique machine-readable ID stored in JSON `type` field |
| `get_label(): string` | Yes | Human-readable label shown in admin UI dropdown |
| `get_options(): array` | Yes | Returns `[['id'=>'slug','label'=>'Name'], ...]` for checkboxes |
| `user_has_access(int $user_id, array $selected_options): bool` | Yes | Core access check |
| `is_available(): bool` | No | Return false when a required plugin is inactive |

### Registering a custom provider

```php
add_filter( 'my_plugin_access_control_providers', function( array $providers ) {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

The filter tag **must** match the string passed to the `AccessControlManager` constructor.
The filter fires on `init` at priority 5. Providers added after that are ignored.

---

## Jetpack Autoloader — mandatory

**This library must be used with `automattic/jetpack-autoloader`.**

Without it, two plugins that both install this library at different versions will cause
a fatal "class already declared" error. Jetpack Autoloader scans all installed plugins,
finds every copy of the library, and loads only the newest version.

Every consuming plugin's `composer.json` must include:

```json
"require": {
    "automattic/jetpack-autoloader": "^2.0",
    "wpboilerplate/wpb-access-control": "dev-main"
},
"config": {
    "allow-plugins": {
        "automattic/jetpack-autoloader": true
    }
}
```

---

## Key invariants for agents

- **`AccessControlManager` has no REST hooks.** Do not add `rest_pre_dispatch` or any other WP action/filter inside this class. Route matching and enforcement belong in the consuming plugin.
- **`user_has_access()` is the only entry point** for access decisions. Do not read from `AccessControlTable` directly in the manager — always go through `user_has_access()`.
- **`AccessControlTable::update()` always sanitizes.** Do not call `sanitize()` separately before calling `update()` — it is called internally.
- **Never write to `{prefix}wpb_access_control` via raw `$wpdb`.** Always use `AccessControlTable::update()` so the object cache stays consistent.
- **Administrator bypass is unconditional.** The `manage_options` check in `user_has_access()` must not be removed or made configurable — it is a security guarantee of the library.
- **Providers are loaded at `init` priority 5.** Any provider registered after that point is silently ignored. Third-party code must hook at priority 4 or earlier.
- **Filter tag isolation is mandatory.** Never use the default `'wpb_access_control_providers'` tag in a product plugin. Always pass a plugin-specific tag to the constructor to prevent provider leakage.
- **`delete_all_for_namespace()` is for uninstall only.** Calling it during normal operation will erase all rules for that namespace permanently.
- **The table is per-site on multisite.** Uses `$wpdb->prefix` — each sub-site has its own table. Network-wide rules must be handled by the consuming plugin.
- **`maybe_create_table()` must be called on both activation AND `plugins_loaded`.** Activation alone misses library version upgrades deployed without plugin reactivation.
