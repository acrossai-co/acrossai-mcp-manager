# wpb-access-control

Extensible per-resource access control library for WordPress plugins.

Answers one question: **"Does this user have access to this resource?"**

The library owns its own database table (managed by **BerlinDB**), ships WordPress role and user providers out of the box, exposes a REST API for managing rules from any client, and provides a ready-to-drop-in **React component** so consuming plugins get a full admin UI without writing any front-end code.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [PHP Setup](#php-setup)
4. [Complete Integration Example](#complete-integration-example)
5. [Checking Access](#checking-access)
6. [React Component UI](#react-component-ui)
7. [Reading & Writing Rules (PHP)](#reading--writing-rules-php)
8. [REST API](#rest-api)
9. [Events](#events)
10. [Custom Providers](#custom-providers)
11. [Built-in Providers](#built-in-providers)
12. [Important Notes](#important-notes)
13. [Database Table Reference](#database-table-reference)

---

## Requirements

| | |
|---|---|
| PHP | 7.4+ |
| WordPress | 5.9+ |
| Node.js | 18+ *(only needed if you rebuild the JS assets)* |
| `automattic/jetpack-autoloader` | **^5.0** (mandatory — see below) |
| `berlindb/core` | **^2.0** (DB layer) |

---

## Installation

```bash
composer require wpboilerplate/wpb-access-control
```

Your `composer.json` must include Jetpack Autoloader:

```json
{
    "require": {
        "automattic/jetpack-autoloader": "^5.0",
        "berlindb/core": "^2.0",
        "wpboilerplate/wpb-access-control": "^1.0"
    },
    "config": {
        "allow-plugins": {
            "automattic/jetpack-autoloader": true
        }
    }
}
```

> **Why Jetpack Autoloader is mandatory**
>
> If two plugins install this library at different versions, PHP throws a
> fatal "class already declared" error. Jetpack Autoloader scans every
> installed plugin, finds all copies, and loads only the newest one.

In your plugin's main file, require the Jetpack Autoloader entry point —
**not** the standard `vendor/autoload.php`:

```php
require_once __DIR__ . '/vendor/autoload_packages.php';
```

---

## PHP Setup

### 1. Boot the manager

Declare `$manager` at **file scope** (outside any closure) so every subsequent
hook can capture it via `use`. Always pass a **plugin-specific filter tag** to
prevent your providers bleeding into other plugins that also use this library.

```php
use WPBoilerplate\AccessControl\AccessControlManager;

// File scope — available to all hooks below via `use ( $manager )`.
$manager = new AccessControlManager( 'my_plugin_access_control_providers' );
```

`AccessControlManager` owns a `RuleQuery` internally. Instantiating it
registers `RuleTable` via BerlinDB, which creates or upgrades the
`{prefix}wpb_access_control` table automatically on `admin_init`.

> **Need to wait for other plugins first?** Use a reference capture instead:
>
> ```php
> $manager = null;
> add_action( 'plugins_loaded', function () use ( &$manager ) {
>     $manager = new AccessControlManager( 'my_plugin_access_control_providers' );
> } );
> // All subsequent hooks must also use `&$manager`.
> ```

### 2. Register the REST API

Call `register_rest_api()` from `rest_api_init` to expose the `wpb-ac/v1`
endpoints. The consuming plugin decides whether to enable them.

```php
add_action( 'rest_api_init', function () use ( $manager ) {
    $manager->register_rest_api();
} );
```

---

## Complete Integration Example

Below is a self-contained `my-plugin.php` showing **all pieces wired together**:
initialising the manager, registering the REST API, enqueueing the React UI,
rendering the mount point, and checking access.

```php
<?php
/**
 * Plugin Name: My Plugin
 */

use WPBoilerplate\AccessControl\AccessControlManager;

// 1. Require Composer autoloader.
require_once __DIR__ . '/vendor/autoload_packages.php';

// 2. Create the manager at file scope — captured by all hooks via `use ( $manager )`.
$manager = new AccessControlManager( 'my_plugin_access_control_providers' );

// 3. Expose the REST API.
add_action( 'rest_api_init', function () use ( $manager ) {
    $manager->register_rest_api();
} );

// 4. Register an admin settings page and capture its hook suffix.
$settings_hook = null;
add_action( 'admin_menu', function () use ( &$settings_hook ) {
    // add_submenu_page() returns the hook suffix needed in admin_enqueue_scripts.
    $settings_hook = add_submenu_page(
        'options-general.php',         // parent menu slug
        'My Plugin Settings',          // page title
        'My Plugin',                   // menu title
        'manage_options',              // capability
        'my-plugin-settings',          // menu slug
        function () {
            echo '<div class="wrap">';
            echo '<h1>My Plugin Settings</h1>';
            // 5. Mount point — the React component attaches here automatically.
            echo '<div id="wpb-access-control"></div>';
            echo '</div>';
        }
    );
} );

// 6. Enqueue the built React UI assets only on the settings page.
add_action( 'admin_enqueue_scripts', function ( string $hook ) use ( &$settings_hook ) {
    if ( $hook !== $settings_hook ) {
        return;
    }

    $asset_file = require __DIR__ . '/vendor/wpboilerplate/wpb-access-control/assets/build/index.asset.php';

    wp_enqueue_script(
        'wpb-ac-ui',
        plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets/build/index.js', __FILE__ ),
        $asset_file['dependencies'],
        $asset_file['version'],
        true
    );

    wp_enqueue_style(
        'wpb-ac-ui',
        plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets/build/index.css', __FILE__ ),
        [],
        $asset_file['version']
    );

    // Pass config to the component via window.wpbAcConfig.
    wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
        'namespace'   => 'my-plugin',
        'resourceKey' => 'settings-page',
        'restApiRoot' => get_rest_url(),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        'title'       => 'Access Control',
        'saveLabel'   => 'Save',
    ] );
} );

// 7. Gate a resource — call anywhere you need to check access.
add_action( 'template_redirect', function () use ( $manager ) {
    if ( is_page( 'protected' ) && ! $manager->user_has_access( get_current_user_id(), 'my-plugin', 'settings-page' ) ) {
        wp_die( 'Access denied.', '', [ 'response' => 403 ] );
    }
} );
```

> **Key points:**
> - `$manager` is declared once at file scope; all hooks capture it with `use ( $manager )`.
> - `add_submenu_page()` (or `add_menu_page()`) returns the hook suffix — store it and compare in `admin_enqueue_scripts` to load assets only on your page.
> - `vendor/autoload_packages.php` is the Jetpack Autoloader entry point, **not** the standard `vendor/autoload.php`.

---

## Checking Access

```php
$allowed = $manager->user_has_access(
    get_current_user_id(),   // int  — 0 = unauthenticated
    'my-namespace',          // string — your plugin's namespace
    'my-resource'            // string — the specific resource key
);

if ( ! $allowed ) {
    wp_die( 'Access denied.', 403 );
}
```

### Access hierarchy

| Step | Condition | Result |
|------|-----------|--------|
| 1 | `access_control_key` is empty or `'everyone'` | **Allow** |
| 2 | User has `manage_options` (administrator) | **Always allow** |
| 3 | User ID = 0 (unauthenticated) | **Deny** |
| 4 | No provider registered for the configured key | **Deny** |
| 5 | `provider->user_has_access()` | Allow or **Deny** |

---

## React Component UI

The library ships a pre-built React component that renders a complete
Access Control settings panel. Drop it into any WordPress admin page and it
wires itself to the `wpb-ac/v1` REST API automatically.

### What it looks like

The component has four states driven by a single **"Who can access"** dropdown:

| Dropdown option | Extra UI |
|---|---|
| **No user access added by admin** | Nothing — resource is locked (except admins) |
| **Everyone (no restriction)** | Nothing — all users can access |
| **WordPress Role** | Checkboxes for each WordPress role |
| **Users** | Search-as-you-type field + selected-user tags |

Custom providers registered via the filter also appear in the dropdown. If
they expose `options`, checkboxes are rendered automatically.

### Enqueue the built assets

The compiled assets live in `assets/build/`. The `.asset.php` file declares
all required WordPress script dependencies so you never need to list them manually.

> **Getting the right hook suffix**: `add_menu_page()` and `add_submenu_page()`
> both **return** a hook suffix string (e.g. `"settings_page_my-plugin"`).
> Capture that return value and compare it in `admin_enqueue_scripts` so assets
> load only on your page.

```php
// Capture the hook suffix when registering the page.
$page_hook = add_submenu_page( /* … */ );

add_action( 'admin_enqueue_scripts', function ( string $hook ) use ( $page_hook ) {

    // Only load on the page where you need it.
    if ( $hook !== $page_hook ) {
        return;
    }

    $asset_file = require plugin_dir_path( __FILE__ )
        . 'vendor/wpboilerplate/wpb-access-control/assets/build/index.asset.php';

    wp_enqueue_script(
        'wpb-ac-ui',
        plugin_dir_url( __FILE__ )
            . 'vendor/wpboilerplate/wpb-access-control/assets/build/index.js',
        $asset_file['dependencies'],   // ['react-jsx-runtime', 'wp-api-fetch', 'wp-element']
        $asset_file['version'],
        true
    );

    wp_enqueue_style(
        'wpb-ac-ui',
        plugin_dir_url( __FILE__ )
            . 'vendor/wpboilerplate/wpb-access-control/assets/build/index.css',
        [],
        $asset_file['version']
    );

    // Pass configuration to the component via window.wpbAcConfig.
    wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
        'namespace'   => 'my-namespace',
        'resourceKey' => 'my-resource',
        'restApiRoot' => get_rest_url(),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        // Optional overrides:
        'title'       => 'Access Control',
        'description' => 'Control which users may access this feature.',
        'saveLabel'   => 'Save Access Control',
    ] );
} );
```

### Render target

Add an empty `<div>` with the id `wpb-access-control` anywhere in your admin
page template. The component mounts itself automatically.

```php
add_action( 'my_plugin_settings_page', function () {
    echo '<div id="wpb-access-control"></div>';
} );
```

### Component props reference

| Prop | Type | Required | Default | Description |
|------|------|----------|---------|-------------|
| `namespace` | `string` | ✅ | — | Access-control namespace, e.g. `"mcp"` |
| `resourceKey` | `string` | ✅ | — | Resource key within the namespace |
| `restApiRoot` | `string` | ✅ | — | WP REST API root URL (`get_rest_url()`) |
| `nonce` | `string` | ✅ | — | `wp_create_nonce('wp_rest')` |
| `title` | `string` | | `"Access Control"` | Card heading |
| `description` | `string` | | *(MCP-server copy)* | Subtitle paragraph |
| `saveLabel` | `string` | | `"Save Access Control"` | Save button label |
| `onSave` | `Function` | | — | Callback `(acKey, acOptions)` after a successful save |

### Using the component as a JS import

If your plugin has its own webpack build, import the component directly:

```js
import apiFetch from '@wordpress/api-fetch';
import { AccessControl } from '@wpb/access-control'; // or relative path

// Set up the nonce once before rendering.
apiFetch.use( apiFetch.createNonceMiddleware( wpbAcConfig.nonce ) );

// Render into any DOM node.
import { createRoot } from '@wordpress/element';
createRoot( document.getElementById( 'my-ac-panel' ) ).render(
    <AccessControl
        namespace="my-namespace"
        resourceKey="my-resource"
        restApiRoot={ wpbAcConfig.restApiRoot }
        nonce={ wpbAcConfig.nonce }
        onSave={ ( acKey, acOptions ) => console.log( 'Saved', acKey, acOptions ) }
    />
);
```

> **Note:** When importing directly the nonce middleware must be registered
> before the first `apiFetch` call. The auto-render path (`index.js`) handles
> this automatically.

### Namespace slashes

Namespaces containing slashes (e.g. `procureco/v1`) are handled automatically
by the component — each segment is `encodeURIComponent`-encoded so they reach
the REST API as `%2F`.

---

## Reading & Writing Rules (PHP)

Use `RuleQuery` when you need to read or write rules from PHP directly.

```php
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

$query = new RuleQuery();

// Read the current rule.
$rule = $query->get_rule( 'my-namespace', 'my-resource' );
// → ['key' => 'wp_role', 'value' => ['editor', 'author']]
// → ['key' => '',        'value' => []]   when no rule is set

// Save a rule (inputs are sanitized internally).
$query->set_rule( 'my-namespace', 'my-resource', 'wp_role', ['editor', 'author'] );

// Allow everyone.
$query->set_rule( 'my-namespace', 'my-resource', 'everyone', [] );

// Clear a rule (reverts to "no restriction configured").
$query->clear_rule( 'my-namespace', 'my-resource' );

// Plugin uninstall — delete all rows for your namespace.
$query->purge_namespace( 'my-namespace' );
```

You can also access the same instance through the manager:

```php
$rule = $manager->get_query()->get_rule( 'my-namespace', 'my-resource' );
```

---

## REST API

REST namespace: **`wpb-ac/v1`**

All endpoints require `manage_options` (administrator) by default.
Use the `wpb_access_control_rest_permission` filter to override.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/rules/{namespace}/{key}` | Read the current rule |
| `PUT` | `/rules/{namespace}/{key}` | Create or replace a rule |
| `DELETE` | `/rules/{namespace}/{key}` | Clear a rule (revert to unrestricted) |
| `DELETE` | `/namespaces/{namespace}` | Purge all rules for a namespace |
| `GET` | `/providers` | List registered providers and their options |
| `GET` | `/users?search=...&limit=10` | Search WordPress users |

> **Slashes in namespace**: The `{namespace}` URL segment cannot contain
> literal slashes — encode them as `%2F`:
> `.../rules/procureco%2Fv1/my-key`.
> The `{key}` segment allows literal slashes.

### Request / response shapes

**GET /rules/{namespace}/{key}**
```json
{ "key": "wp_role", "value": ["editor", "author"] }
{ "key": "", "value": [] }
```

**PUT /rules/{namespace}/{key}** — body:
```json
{ "ac_key": "wp_role", "ac_options": ["editor", "author"] }
```
Response:
```json
{ "success": true, "rule": { "key": "wp_role", "value": ["editor", "author"] } }
```

**DELETE /rules/{namespace}/{key}**
```json
{ "success": true }
```

**DELETE /namespaces/{namespace}**
```json
{ "deleted": 5 }
```

**GET /providers**
```json
[
  { "id": "wp_role", "label": "WordPress Role", "options": [{"id":"editor","label":"Editor"}, ...], "available": true },
  { "id": "wp_user", "label": "Users",          "options": [],                                       "available": true }
]
```

**GET /users?search=jane&limit=10**
```json
[
  { "id": "5", "login": "jane", "email": "jane@example.com", "display_name": "Jane Doe" }
]
```

---

### Authentication

**WordPress admin (nonce)**

Include the `wp_rest` nonce in the `X-WP-Nonce` header:

```php
$nonce = wp_create_nonce( 'wp_rest' );
```

**Application Passwords (external clients)**

```
Authorization: Basic base64(username:application_password)
```

---

### Code examples

#### cURL

```bash
# Read
curl -H "X-WP-Nonce: <nonce>" \
  https://example.com/wp-json/wpb-ac/v1/rules/my-namespace/my-resource

# Set
curl -X PUT \
  -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"ac_key":"wp_role","ac_options":["editor","author"]}' \
  https://example.com/wp-json/wpb-ac/v1/rules/my-namespace/my-resource

# Namespace with slashes
curl -X PUT \
  -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"ac_key":"wp_role","ac_options":["editor"]}' \
  https://example.com/wp-json/wpb-ac/v1/rules/procureco%2Fv1/endpoints%2Flist

# Clear
curl -X DELETE \
  -H "X-WP-Nonce: <nonce>" \
  https://example.com/wp-json/wpb-ac/v1/rules/my-namespace/my-resource
```

#### PHP (`wp_remote_request`)

```php
// Read
$response = wp_remote_get(
    rest_url( 'wpb-ac/v1/rules/my-namespace/my-resource' ),
    [ 'headers' => [ 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ] ]
);
$rule = json_decode( wp_remote_retrieve_body( $response ), true );

// Set
wp_remote_request(
    rest_url( 'wpb-ac/v1/rules/my-namespace/my-resource' ),
    [
        'method'  => 'PUT',
        'headers' => [
            'Content-Type' => 'application/json',
            'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
        ],
        'body' => wp_json_encode( [ 'ac_key' => 'wp_role', 'ac_options' => [ 'editor' ] ] ),
    ]
);
```

#### `@wordpress/api-fetch`

```js
import apiFetch from '@wordpress/api-fetch';

// Read
const rule = await apiFetch( { path: '/wpb-ac/v1/rules/my-namespace/my-resource' } );

// Set
await apiFetch( {
    path:   '/wpb-ac/v1/rules/my-namespace/my-resource',
    method: 'PUT',
    data:   { ac_key: 'wp_role', ac_options: [ 'editor', 'author' ] },
} );

// Search users (for the wp_user provider UI)
const users = await apiFetch( { path: '/wpb-ac/v1/users?search=jane&limit=10' } );

// List providers (for building a custom UI)
const providers = await apiFetch( { path: '/wpb-ac/v1/providers' } );
```

#### Vanilla `fetch`

```js
const nonce  = document.querySelector( 'meta[name="wp-rest-nonce"]' )?.content;
const apiUrl = '/wp-json/wpb-ac/v1';

// Read
const rule = await fetch( `${apiUrl}/rules/my-namespace/my-resource`, {
    headers: { 'X-WP-Nonce': nonce },
} ).then( r => r.json() );

// Set
await fetch( `${apiUrl}/rules/my-namespace/my-resource`, {
    method:  'PUT',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body:    JSON.stringify( { ac_key: 'wp_role', ac_options: [ 'editor' ] } ),
} );
```

---

### Permission filter

Override who may call any endpoint:

```php
add_filter( 'wpb_access_control_rest_permission', function ( bool $can, WP_REST_Request $request ): bool {
    // Allow editors to read rules, but only admins to write.
    if ( 'GET' === $request->get_method() ) {
        return current_user_can( 'edit_posts' );
    }
    return $can;
}, 10, 2 );
```

### Write authorization filter

Restrict which namespace/key pairs may be modified:

```php
add_filter( 'wpb_access_control_can_save', function ( bool $can, string $namespace, string $key, int $user_id ): bool {
    return 'my-namespace' === $namespace;
}, 10, 4 );
```

---

## Events

### `wpb_access_control_denied`

Fires whenever `user_has_access()` returns `false` (steps 3–5 of the hierarchy).

```php
add_action( 'wpb_access_control_denied', function (
    int    $user_id,
    string $namespace,
    string $key,
    string $ac_key,
    array  $options
): void {
    error_log( "Access denied — user:{$user_id} {$namespace}/{$key}" );
}, 10, 5 );
```

### `wpb_access_control_saved`

Fires after any successful write via the REST API (PUT rule, DELETE rule,
DELETE namespace). `$ac_key` is `''` on a clear.

```php
add_action( 'wpb_access_control_saved', function (
    string $namespace,
    string $key,
    string $ac_key,
    array  $ac_options,
    int    $user_id
): void {
    // Audit log, cache bust, etc.
}, 10, 5 );
```

---

## Custom Providers

### Register

```php
add_filter( 'my_plugin_access_control_providers', function ( array $providers ): array {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

The filter tag must match the string passed to `AccessControlManager`.
Register at `init` priority ≤ 4 (the filter fires at priority 5).

### Contract (`AbstractProvider`)

| Method | Required | Description |
|--------|----------|-------------|
| `get_id(): string` | ✅ | Unique slug stored as `access_control_key` |
| `get_label(): string` | ✅ | Human-readable label shown in the UI dropdown |
| `get_options(): array` | ✅ | `[['id'=>'slug','label'=>'Name'], ...]`; return `[]` for dynamic providers |
| `user_has_access(int $user_id, array $selected): bool` | ✅ | Core access check |
| `is_available(): bool` | | Return `false` when a required dependency is inactive |

### Example provider

```php
namespace My\Plugin;

use WPBoilerplate\AccessControl\AbstractProvider;

class MembershipProvider extends AbstractProvider {

    public function get_id(): string    { return 'my_membership'; }
    public function get_label(): string { return __( 'Membership Level', 'my-plugin' ); }

    public function get_options(): array {
        return [
            [ 'id' => 'gold',   'label' => 'Gold'   ],
            [ 'id' => 'silver', 'label' => 'Silver' ],
        ];
    }

    public function user_has_access( int $user_id, array $selected_options ): bool {
        return in_array( my_get_membership_level( $user_id ), $selected_options, true );
    }

    public function is_available(): bool {
        return function_exists( 'my_get_membership_level' );
    }
}
```

Providers and their options are surfaced by `GET /wpb-ac/v1/providers`, so
any front-end UI (including the built-in React component) can render the
correct controls dynamically without hard-coding provider IDs.

---

## Built-in Providers

| Provider ID | Class | Description |
|-------------|-------|-------------|
| `wp_role` | `WpRoleProvider` | Restricts by WordPress user role. Administrator is always bypassed. |
| `wp_user` | `WpUserProvider` | Restricts to specific WordPress users by ID. |

### `WpRoleProvider` filters

| Filter | Signature | Description |
|--------|-----------|-------------|
| `wpb_access_control_wp_role_options` | `(array $options): array` | Add or remove selectable role options |
| `wpb_access_control_wp_role_has_access` | `(bool $result, int $user_id, array $selected): bool` | Override the final role-based decision |

### `WpUserProvider`

Options are **user IDs stored as strings** (`"42"`), not usernames or emails —
`sanitize_key()` strips `@` and `.`, so email addresses would be corrupted.

```php
use WPBoilerplate\AccessControl\WpUserProvider;

// Search by login, email, or display name.
$results = WpUserProvider::search_users( 'jane', 10 );
// → [['id'=>'5','login'=>'jane','email'=>'jane@example.com','display_name'=>'Jane Doe'], ...]

// Hydrate stored IDs → display data (useful for custom UIs).
$users = WpUserProvider::get_users_by_ids( ['5', '42'] );
```

| Filter | Signature | Description |
|--------|-----------|-------------|
| `wpb_access_control_wp_user_has_access` | `(bool $result, int $user_id, array $selected): bool` | Override the final per-user decision |

---

## Important Notes

### Filter tag isolation
Always pass a plugin-specific tag to `AccessControlManager`. Two plugins
sharing the same filter tag will bleed providers into each other's checks.

### Table management
BerlinDB handles all table creation and upgrades automatically on `admin_init`.
No activation hook is needed — instantiating `new AccessControlManager(...)` is sufficient.

### Caching
Always use `RuleQuery::set_rule()` and `clear_rule()`. Direct `$wpdb` writes
bypass BerlinDB's object cache and leave it stale.

### Administrator bypass is unconditional
Any user with `manage_options` always passes `user_has_access()` regardless of
the stored rule. This cannot be disabled.

### Uninstall cleanup
Each consuming plugin removes its own rows:

```php
// uninstall.php
( new \WPBoilerplate\AccessControl\Database\Rule\RuleQuery() )
    ->purge_namespace( 'my-namespace' );
```

### Multisite
The table uses `$wpdb->prefix` — each sub-site has its own
`{prefix}wpb_access_control` table. Network-wide rules must be handled by
the consuming plugin.

---

## Database Table Reference

Table: `{prefix}wpb_access_control`  ·  DB layer: BerlinDB `^2.0`  ·  Schema version: `202605120001`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `namespace` | VARCHAR(100) NOT NULL | Plugin-scoped prefix, e.g. `mcp`, `procureco/v1` |
| `key` | VARCHAR(255) NOT NULL | Resource identifier within the namespace |
| `access_control_key` | VARCHAR(100) NOT NULL | Rule type slug — same for every row of a `(ns, key)` pair |
| `access_control_value` | VARCHAR(255) NOT NULL | One option per row; `''` for the `everyone` sentinel |
| `created_at` | DATETIME | BerlinDB-managed on INSERT |
| `updated_at` | DATETIME | BerlinDB-managed on UPDATE |

Indexes: `PRIMARY KEY (id)` · `UNIQUE (namespace, key(191), access_control_value)` · `KEY (namespace, key(191))`

### Rule storage convention

| Logical state | Rows in table |
|---|---|
| No rule configured | **No rows** for that `(namespace, key)` |
| `everyone` | One row: `access_control_key='everyone'`, `access_control_value=''` |
| `wp_role` + `['editor','author']` | Two rows, both `access_control_key='wp_role'`; values `'editor'`, `'author'` |
| `wp_user` + `['1','42']` | Two rows, both `access_control_key='wp_user'`; values `'1'`, `'42'` |
