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
  AccessControlTable.php       Custom DB table: {prefix}wpb_access_control.
                                CRUD helpers, sanitization, object-cache integration.
                                Exposes target-length constants used by AJAX save validation.
                                Consuming plugins call maybe_create_table() on
                                activation and plugins_loaded.

  AccessControlManager.php     Provider registry + user_has_access().
                                No REST hooks. No fetcher. No mapper.
                                Consuming plugin decides when/where to call it.

  AbstractProvider.php         Abstract base class for all providers.
                                Includes concrete render_options() with default
                                checkbox rendering; override for custom controls.

  WpRoleProvider.php           Built-in provider: restricts by WordPress user role.
                                Administrator role excluded (always bypassed in manager).
                                Uses default AbstractProvider::render_options().

  WpUserProvider.php           Built-in provider: restricts to specific WordPress users.
                                Overrides render_options() to emit AJAX search input +
                                multi-select user tags. Stores user IDs as strings.
                                Static helpers: search_users(), get_users_by_ids().

  Admin/
    AccessControlUI.php        Ready-to-use admin panel renderer. Ships CSS + JS.
                                Consuming plugin bootstraps it once, then calls
                                render() + enqueue_assets().
                                Registers the shared user-search + save AJAX actions.
                                extract_posted_config() is optional for custom saves.

assets/
  css/admin.css                Panel styles: search dropdown, user tags, remove button.
  js/admin.js                  Toggle logic + user search/save AJAX. Scoped per form via
                                data-wpb-ac-form attribute. No dependencies.

README.md                      Usage documentation for consuming plugins.
AGENTS.md                      This file.
composer.json                  Package manifest.
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

Constants:
- `AccessControlTable::NAMESPACE_LENGTH` = `100`
- `AccessControlTable::KEY_LENGTH` = `255`

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

## Built-in providers

| Provider ID | Class | Since | Description |
|-------------|-------|-------|-------------|
| `wp_role` | `WpRoleProvider` | 1.0.0 | Restricts by WordPress user role. |
| `wp_user` | `WpUserProvider` | 1.1.0 | Restricts to specific users, multi-select, AJAX search. |

### `WpUserProvider` — storage rules

- Options are **user IDs stored as strings** (`"42"`, `"1"`), not usernames or emails.
  - Reason: `AccessControlTable::sanitize()` runs `sanitize_key()` on every option.
    Email addresses contain `@` and `.` which that function strips. Numeric ID strings
    (`"42"`) survive unchanged.
- `get_options()` returns `[]` — no static checkbox list.
- `render_options()` emits the AJAX search input and selected-user tags.
  `AccessControlUI` registers the AJAX action and enqueues assets — consuming plugin
  does not need to add any user-search code.
- Static helpers (available for advanced use):
  - `WpUserProvider::search_users( string $search, int $limit = 10 ): array`
  - `WpUserProvider::get_users_by_ids( string[] $ids ): array`

## AccessControlUI

Class: `WPBoilerplate\AccessControl\Admin\AccessControlUI`
Since: 1.2.0

Ships the complete admin panel (PHP rendering, CSS, JS, AJAX) so consuming plugins
need zero UI code for access control.

### Public API

| Method | Description |
|--------|-------------|
| `__construct( AccessControlManager $manager )` | Registers the shared user-search and save AJAX actions (idempotent). |
| `static bootstrap()` | Registers the shared AJAX actions without needing a UI instance yet. |
| `set_assets_url( string $url )` | Override auto-detected asset base URL. |
| `render( string $ns, string $key, array $args )` | Render the panel. Always wraps in a `<form>` with library-owned AJAX save wiring. |
| `enqueue_assets()` | Enqueue library CSS + JS. Call from `admin_enqueue_scripts`. |
| `static extract_posted_config( array $post ): string` | Extract sanitized JSON from `$_POST`; used internally for AJAX save and reusable for custom flows. |

### `$args` for `render()`

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `submit_label` | string | "Save Access Control" | Submit button label. |
| `description` | string | Generic copy | Paragraph below heading. |

### AJAX action

Action: `wpb_access_control_search_users` (shared, library-owned)
Nonce: `wpb_access_control_search_users`
Capability check: `manage_options`

Action: `wpb_access_control_save` (shared, library-owned)
Nonce field: `wpb_ac_nonce` using action `wpb_access_control_save`
Capability check: `manage_options`
Save authorization filter: `wpb_access_control_can_save`
Post-save action: `wpb_access_control_saved`

The action is registered exactly once per request via a static `$ajax_registered` flag,
even when multiple plugins instantiate `AccessControlUI`.

### Asset URL resolution

Auto-detection: computes the package root from `__DIR__`, strips `WP_CONTENT_DIR`,
prepends `WP_CONTENT_URL`. Works whether the package lives at
`wp-content/wpb-access-control/` or inside a plugin's `vendor/`.

Override when auto-detection is wrong (symlinks, non-standard layout):

```php
$ui->set_assets_url( plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets', __FILE__ ) );
```

---

## Key invariants for agents

### AccessControlManager
- **No REST hooks inside the manager.** `rest_pre_dispatch` and all enforcement belong in the consuming plugin.
- **`user_has_access()` is the only entry point** for access decisions. Do not read from `AccessControlTable` directly in the manager.
- **Administrator bypass is unconditional.** The `manage_options` check in `user_has_access()` must not be removed or made configurable.
- **Providers are loaded at `init` priority 5.** Third-party code must hook at priority 4 or earlier.
- **Filter tag isolation is mandatory.** Never use the default `'wpb_access_control_providers'` tag in a product plugin.

### AccessControlTable
- **`update()` always sanitizes.** Do not call `sanitize()` separately before calling `update()`.
- **Never write via raw `$wpdb`.** Always use `AccessControlTable::update()` so the object cache stays consistent.
- **`delete_all_for_namespace()` is for uninstall only.**
- **The table is per-site on multisite** (`$wpdb->prefix`). Network-wide rules must be handled by the consuming plugin.
- **`maybe_create_table()` must be called on both activation AND `plugins_loaded`.**

### AccessControlUI
- **Bootstrap AJAX handlers on requests that do not render the panel.** Either instantiate `AccessControlUI` once during plugin bootstrap or call `AccessControlUI::bootstrap()` early so `admin-ajax.php` requests have the shared callbacks registered.
- **Shared AJAX actions** `wpb_access_control_search_users` and `wpb_access_control_save` are registered exactly once via the `$ajax_registered` static flag. Do not add duplicate registrations.
- **The UI class owns its AJAX save path.** `ajax_save()` must persist through `AccessControlTable::update()`; consuming plugins do not need a separate save handler for the standard panel.
- **Validate submitted targets before writing.** `ajax_save()` must reject empty or overlong namespace/key values using `AccessControlTable::NAMESPACE_LENGTH` and `AccessControlTable::KEY_LENGTH`.
- **Built-in save extensibility lives in hooks.** Use `wpb_access_control_can_save` to authorize a submitted namespace/key and `wpb_access_control_saved` for post-save side effects.
- **`extract_posted_config()` does NOT call `sanitize_key()` on options** — `AccessControlTable::sanitize()` does that on `update()`. Avoid double-processing.
- **JS scopes by `data-wpb-ac-form` attribute, never `getElementById`.** Required so two panels can coexist on one page.
- **Ignore stale live-search responses.** User search requests may return out of order; the active query must win.
- **Asset URL auto-detection uses `WP_CONTENT_DIR`/`WP_CONTENT_URL`.** Call `set_assets_url()` when the package is in a non-standard location.

### WpUserProvider
- **Stores user IDs as strings, not usernames or emails.** `sanitize_key()` strips `@` and `.` — email addresses would be corrupted.
