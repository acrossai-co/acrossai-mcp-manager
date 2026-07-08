# JS Extensibility Contract — Feature 017

**Package**: `@wordpress/hooks` — the WordPress-standard filter/action system for client-side JavaScript. `wp.hooks.applyFilters` and `wp.hooks.addFilter` are the ONLY public entry points; no custom `window.acrossaiMcpAbilities.register(...)` API exists (FR-028).

## Filter Points

All three filters are invoked once per render pass by the React app in `src/js/abilities.js`. Each call is wrapped in a `safeApplyFilters()` boundary (see §Failure Modes) so a throwing consumer never white-screens the tab.

### `acrossaiMcpManager.abilities.fields`

Register additional column definitions for the `<DataViews>` table.

**Signature (input → output)**:
```typescript
applyFilters(
    'acrossaiMcpManager.abilities.fields',
    fields: Field[],                    // Current fields array (built-in + prior filters)
    context: {
        serverId: number,
        serverSlug: string
    }
): Field[]
```

**Field shape** (matches `@wordpress/dataviews` field type):
```typescript
{
    id: string,                          // Must be unique; cannot collide with built-in ids
    label: string,                       // Localized column header text
    enableSorting?: boolean,             // Default: true
    enableHiding?: boolean,              // Default: true
    enableGlobalSearch?: boolean,        // Default: false
    getValue?: ({ item }) => any,        // Optional value extractor
    render?: ({ item }) => JSX,          // Optional cell renderer
    elements?: Array<{ value, label }>,  // Optional filter enum
    filterBy?: { operators: string[] },  // Optional filter config
}
```

**Built-in field ids** (extensions MAY NOT redefine, remove, or overwrite):
- `slug`, `label`, `type`, `category`, `description`, `is_exposed`

**Additive-only enforcement**: after the filter fires, the app reduces to `[ ...builtins, ...extras.filter( f => ! builtinIds.has( f.id ) ) ]`. Extensions that try to remove a built-in silently have their removal ignored (FR-029 invariant).

**Example** — Companion plugin adds an "Action" column with an Edit button:

```js
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

addFilter(
    'acrossaiMcpManager.abilities.fields',
    'acrossai-abilities-manager/action-column',
    ( fields, { serverId } ) => [
        ...fields,
        {
            id: 'ability_action',
            label: __( 'Action', 'acrossai-abilities-manager' ),
            enableSorting: false,
            enableHiding: false,
            render: ( { item } ) => createElement(
                'button',
                {
                    className: 'button',
                    onClick: () => openEditModal( item.slug, serverId ),
                },
                __( 'Edit', 'acrossai-abilities-manager' )
            ),
        },
    ]
);
```

### `acrossaiMcpManager.abilities.actions`

Register additional bulk actions.

**Signature**:
```typescript
applyFilters(
    'acrossaiMcpManager.abilities.actions',
    actions: Action[],
    context: { serverId: number, serverSlug: string }
): Action[]
```

**Action shape** (matches `@wordpress/dataviews` action type):
```typescript
{
    id: string,                          // Must be unique; cannot collide with built-in ids
    label: string,                       // Localized menu text
    supportsBulk?: boolean,              // Default: true for bulk actions
    isPrimary?: boolean,                 // Default: false — shown outside kebab menu
    callback: ( selectedItems ) => Promise<void> | void,
}
```

**Built-in action ids** (extensions MAY NOT redefine):
- `expose`, `hide`

### `acrossaiMcpManager.abilities.row`

Decorate a single row before it reaches `<DataViews>`. Useful when an extension's field `render` needs additional data that only the extension knows.

**Signature**:
```typescript
applyFilters(
    'acrossaiMcpManager.abilities.row',
    item: Object,                        // One row — includes built-in keys + any keys added by PHP row filter
    context: { serverId: number, serverSlug: string }
): Object
```

**Built-in row keys** (extensions MAY NOT overwrite):
- `slug`, `label`, `type`, `category`, `description`, `is_exposed`, `has_override`

**Additive-only enforcement**: on the JS side, the app trusts the filter's return for its own purposes but the read-side REST controller has already re-asserted the built-in keys server-side via `array_merge( $filtered, $row )` (FR-027), so a JS row filter that tries to overwrite a built-in only affects the current render — it does NOT change what the server returns.

## Failure Modes (FR-029)

Every filter call inside `src/js/abilities.js` is wrapped in `safeApplyFilters()`:

```js
function safeApplyFilters( name, value ) {
    try {
        const out = applyFilters( name, value, filterContext );
        if ( name.endsWith( '.fields' ) || name.endsWith( '.actions' ) ) {
            return Array.isArray( out ) ? out : value;
        }
        return out && typeof out === 'object' ? out : value;
    } catch ( err ) {
        console.error( `[acrossai-mcp-manager] filter "${ name }" threw:`, err );
        return value;
    }
}
```

Consequences of a broken third-party filter:

| Symptom | Result |
|---|---|
| Callback throws synchronously | Caught; `console.error` logged; input value returned; tab renders normally. |
| Callback returns `null` / non-array (for `.fields`/`.actions`) | Return discarded; input value used; tab renders normally. |
| Callback returns non-object (for `.row`) | Return discarded; input row used. |
| Callback returns an array missing a built-in field/action | Ignored by additive-merge reducer — built-ins re-asserted. |
| Callback returns a field with an id that collides with a built-in | Silently dropped by additive-merge reducer. |

**Never**: white-screen the tab, break the REST endpoint, corrupt persisted data, or affect other tabs.

## Consumer Enqueue Pattern

Companion plugins that need to register filters MUST enqueue their JS after the abilities bundle. Two supported patterns:

### Pattern A — Declare a script dependency

```php
add_action( 'admin_enqueue_scripts', function () {
    if ( ! /* on the abilities tab */ ) {
        return;
    }
    wp_enqueue_script(
        'my-plugin-abilities-extension',
        plugins_url( 'assets/abilities-extension.js', __FILE__ ),
        array( 'acrossai-mcp-manager-abilities' ), // <-- key: depend on the core bundle
        MY_PLUGIN_VERSION,
        true
    );
} );
```

### Pattern B — Register on `wp.domReady`

Useful when the companion plugin enqueues on `admin_enqueue_scripts` unconditionally. The filter registration lands after `@wordpress/hooks` boots, and `applyFilters` will pick it up on the next render pass.

```js
import { addFilter } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';

domReady( () => {
    addFilter(
        'acrossaiMcpManager.abilities.fields',
        'my-plugin/columns',
        ( fields ) => [ ...fields, /* extra field */ ]
    );
} );
```

## Stability Marker

Every filter and action introduced by F017 carries the F013 stability marker on its docblock:

```
@since 0.0.10 @experimental May change without notice before 1.0.0
```

Promotion to semver-stable will happen at the plugin's 1.0.0 tag with a deprecation notice cycle for any change.

## Frozen Names (Contract)

Once this feature merges, these identifiers become part of the plugin's public contract with companion plugins:

- Filter names: `acrossaiMcpManager.abilities.fields`, `acrossaiMcpManager.abilities.actions`, `acrossaiMcpManager.abilities.row`
- Filter context keys: `serverId`, `serverSlug`
- Built-in field ids: `slug`, `label`, `type`, `category`, `description`, `is_exposed`
- Built-in action ids: `expose`, `hide`
- Built-in row keys: `slug`, `label`, `type`, `category`, `description`, `is_exposed`, `has_override`
- Localized global: `window.acrossaiMcpAbilities` — `{ serverId, serverSlug, restApiRoot, nonce, namespace }`
- Enqueue handle: `acrossai-mcp-manager-abilities` (declare as script dep from companion plugins)
