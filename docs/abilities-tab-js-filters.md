# Abilities Tab — JS Filters

**Screen**: `?page=acrossai_mcp_manager&action=edit&server=<id>&tab=abilities`
**Package**: `@wordpress/hooks` — the WordPress-standard filter system for JavaScript.
**Applies to**: AcrossAI MCP Manager v0.1.0+ (experimental).

Companion plugins can extend the Abilities tab by registering `@wordpress/hooks` filters. This document focuses on **adding new columns** via the `acrossaiMcpManager.abilities.fields` filter.

---

## The filter at a glance

```js
wp.hooks.addFilter(
    'acrossaiMcpManager.abilities.fields',  // filter name
    'my-plugin/my-column',                  // your unique handle
    ( fields, context ) => {                // callback
        return [ ...fields, myNewField ];   // return a new array
    }
);
```

**Fires**: once per render pass of the DataViews table.
**Context arg**: `{ serverId: number, serverSlug: string }`.
**Returns**: an array of field objects (built-ins + your additions).
**Additive-only**: your callback CAN append new columns; it CANNOT redefine, remove, or overwrite the built-in `slug`, `label`, `type`, `category`, `description`, or `is_exposed` columns. Attempts to do so are silently dropped by the merge reducer.

---

## Field object shape

Each field passed to `<DataViews>` is a plain object with the following keys:

```typescript
{
    id: string,                            // unique — cannot collide with built-in ids
    label: string,                         // localized column header
    enableGlobalSearch?: boolean,          // search input scans this field when true
    enableSorting?: boolean,               // clickable sort arrow on the header (default true)
    enableHiding?: boolean,                // user can hide the column via view options (default true)
    getValue?: ( { item } ) => any,        // optional value extractor for sort/filter/search
    render?: ( { item } ) => JSX,          // optional cell renderer — return React children
    elements?: Array<{ value, label }>,    // optional enum for filter dropdowns
    filterBy?: { operators: string[] },    // enables filtering (usually { operators: [ 'is' ] })
    width?: string,                        // e.g. "20%" or "180px"
    maxWidth?: string,
}
```

See `@wordpress/dataviews` documentation for the full field type reference.

---

## Setup — enqueue your JS on the Abilities tab

Your bundle must be enqueued AFTER the abilities app is registered so `wp.hooks` is ready. The idiomatic pattern: **declare `acrossai-mcp-manager-abilities` as a script dependency**.

```php
<?php
// my-plugin/my-plugin.php

add_action( 'admin_enqueue_scripts', function () {
    // Only enqueue on the Abilities tab.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $is_edit      = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
    $is_abilities = isset( $_GET['tab'] )    && 'abilities' === sanitize_key( wp_unslash( $_GET['tab'] ) );
    // phpcs:enable
    if ( ! $is_edit || ! $is_abilities ) {
        return;
    }

    wp_enqueue_script(
        'my-plugin-abilities-extension',
        plugins_url( 'assets/abilities-extension.js', __FILE__ ),
        array( 'acrossai-mcp-manager-abilities' ),  // <-- KEY: depend on the core bundle
        '0.1.0',
        true
    );
} );
```

**Handle name**: `acrossai-mcp-manager-abilities`. This is the enqueue handle for the plugin's own `abilities.js` bundle. Depending on it guarantees your JS runs AFTER `wp.hooks` and after the abilities app boots.

---

## Example 1 — the smallest possible column

Adds a static "Notes" column that reads a placeholder value.

```js
// my-plugin/assets/abilities-extension.js

wp.hooks.addFilter(
    'acrossaiMcpManager.abilities.fields',
    'my-plugin/notes-column',
    ( fields ) => [
        ...fields,
        {
            id: 'my_notes',                    // must be unique
            label: 'Notes',
            enableSorting: false,
            render: ( { item } ) =>
                wp.element.createElement( 'em', null, 'No notes yet' ),
        },
    ]
);
```

Reload the Abilities tab — you'll see a new **Notes** column at the end of every row.

---

## Example 2 — read data your plugin exposes

`item` is the row object. It always contains the built-in keys (`slug`, `label`, `type`, `category`, `description`, `is_exposed`, `has_override`). To read data your own plugin exposes, use the `acrossaiMcpManager.abilities.row` filter to decorate the row first, then read the extra key in your column's `render`.

```js
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';

// Decorate each row with an extra key.
addFilter(
    'acrossaiMcpManager.abilities.row',
    'my-plugin/tag-editable',
    ( item ) => ( {
        ...item,
        my_editable: /^my-plugin\//.test( item.slug ),
    } )
);

// Read the extra key in a column render.
addFilter(
    'acrossaiMcpManager.abilities.fields',
    'my-plugin/editable-column',
    ( fields ) => [
        ...fields,
        {
            id: 'my_editable',
            label: 'Editable',
            enableSorting: false,
            render: ( { item } ) =>
                createElement(
                    'span',
                    { style: { color: item.my_editable ? '#22c55e' : '#94a3b8' } },
                    item.my_editable ? 'Yes' : 'No'
                ),
        },
    ]
);
```

---

## Example 3 — sortable, filterable enum column

Adds a "Source" column with three fixed values (`core`, `plugin`, `theme`). DataViews will render a filter dropdown from `elements` when `filterBy` is set.

```js
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';

addFilter(
    'acrossaiMcpManager.abilities.row',
    'my-plugin/source-value',
    ( item ) => {
        // Derive 'source' from the slug prefix.
        let source = 'plugin';
        if ( item.slug.startsWith( 'core/' ) )      source = 'core';
        else if ( item.slug.startsWith( 'theme/' ) ) source = 'theme';
        return { ...item, my_source: source };
    }
);

addFilter(
    'acrossaiMcpManager.abilities.fields',
    'my-plugin/source-column',
    ( fields ) => [
        ...fields,
        {
            id: 'my_source',
            label: 'Source',
            getValue: ( { item } ) => item.my_source,
            elements: [
                { value: 'core',   label: 'Core'   },
                { value: 'plugin', label: 'Plugin' },
                { value: 'theme',  label: 'Theme'  },
            ],
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) =>
                createElement(
                    'code',
                    { style: { fontSize: 12 } },
                    item.my_source
                ),
        },
    ]
);
```

Sort works out of the box. If `filterBy` is set, DataViews adds the field to its filter surface.

---

## Example 4 — "Edit" button column

Adds a right-most action column with an "Edit" button that opens your own modal or navigates to another admin URL.

```js
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';

addFilter(
    'acrossaiMcpManager.abilities.fields',
    'my-plugin/edit-action-column',
    ( fields, { serverId } ) => [
        ...fields,
        {
            id: 'my_action',
            label: 'Action',
            enableSorting: false,
            enableHiding: false,
            render: ( { item } ) =>
                createElement(
                    Button,
                    {
                        variant: 'secondary',
                        size: 'small',
                        onClick: () => {
                            // Option A — open your own modal
                            window.myPluginOpenAbilityEditor( item.slug, serverId );

                            // Option B — navigate to an admin page
                            // window.location.href = 'admin.php?page=my-plugin-editor&ability=' + encodeURIComponent( item.slug );
                        },
                    },
                    'Edit'
                ),
        },
    ]
);
```

Note the second argument to the callback: `{ serverId, serverSlug }`. Use this to build server-scoped URLs or API calls.

---

## Invariants (what your callback CANNOT do)

1. **Built-in field ids are reserved.** Any field whose `id` matches a built-in (`slug`, `label`, `type`, `category`, `description`, `is_exposed`) is silently dropped. Pick a unique namespaced id (e.g. `my_action`, `my_source`, `myplugin_owner`).

2. **Non-array returns are ignored.** If your callback returns anything other than an array, the merge reducer discards it and uses the pre-filter fields. Always return an array.

3. **Thrown callbacks are caught.** If your callback throws, `safeApplyFilters` logs one `console.error` and falls back to the pre-filter fields. Your column disappears; built-ins keep rendering. Guard against throws inside your callback so operators don't see mysterious column disappearances.

4. **Extension additions land after built-ins.** The final field order is `[ ...builtins, ...your additions in registration order ]`. You cannot re-order the built-in columns.

---

## Trust contract — escape your own values

**Every value your extension adds is treated as untrusted at render time.** The core plugin does NOT sanitize your additions. Follow WordPress escaping conventions:

- **Prefer** `createElement( 'span', {}, value )` — React escapes children by default.
- **Avoid** `dangerouslySetInnerHTML` unless the value has already been sanitized (e.g. run through a whitelist client-side).

If you accept HTML from a WordPress option / user metadata / another plugin, escape it first:

```js
const safe = wp.escapeHtml.escapeHTML( untrustedString );
render: () => createElement( 'span', {}, safe );
```

---

## Performance — keep callbacks O(1)

The `.fields` filter fires **once per render**. The `.row` filter fires **once per ability, per render** — on a site with 199 abilities, that's 199 callback invocations per keystroke in the search box.

**Do NOT** make network requests, `wp.data.select().resolveSelect()` calls, or heavy computations inside these callbacks. If you need external data:

```js
// GOOD — prefetch once, read from cache inside the filter.
add_action( 'admin_init', function () {
    if ( ! ( isset( $_GET['tab'] ) && 'abilities' === $_GET['tab'] ) ) return;
    MyPlugin::warm_ability_metadata_cache();
} );

// Then in JS:
addFilter( 'acrossaiMcpManager.abilities.row', 'my-plugin/read-cache',
    ( item ) => ( { ...item, my_owner: window.myPluginCache?.[ item.slug ]?.owner || '' } )
);
```

---

## Verify your integration

Save this as `wp-content/plugins/hello-abilities-columns/hello.php` and `hello.js` alongside it. Activate — you'll see two new columns.

**`hello.php`**:
```php
<?php
/** Plugin Name: Hello Abilities Columns */

add_action( 'admin_enqueue_scripts', function () {
    if ( ! ( isset( $_GET['tab'] ) && 'abilities' === $_GET['tab'] ) ) return;
    wp_enqueue_script(
        'hello-abilities-columns',
        plugins_url( 'hello.js', __FILE__ ),
        array( 'acrossai-mcp-manager-abilities' ),
        '0.1.0',
        true
    );
} );
```

**`hello.js`**:
```js
wp.hooks.addFilter(
    'acrossaiMcpManager.abilities.fields',
    'hello/prefix-column',
    ( fields ) => [
        ...fields,
        {
            id: 'hello_prefix',
            label: 'Namespace',
            getValue: ( { item } ) => item.slug.split( '/' )[ 0 ],
            render: ( { item } ) =>
                wp.element.createElement(
                    'code',
                    { style: { background: '#f0f0f1', padding: '2px 6px', borderRadius: 3 } },
                    item.slug.split( '/' )[ 0 ]
                ),
        },
        {
            id: 'hello_action',
            label: 'Action',
            enableSorting: false,
            render: ( { item } ) =>
                wp.element.createElement(
                    'button',
                    {
                        className: 'button',
                        onClick: () => alert( 'Edit ' + item.slug ),
                    },
                    'Edit'
                ),
        },
    ]
);
```

Reload the Abilities tab — you should see a **Namespace** column (sortable, shows `ai`, `core`, `acrossai-core-abilities`, etc.) and an **Action** column (with an Edit button per row). Removing the plugin restores the original column set.

---

## Related filters

Three sibling JS filters extend the same tab. See `docs/extending-abilities-tab.md` for the full reference.

| Filter | Purpose |
|---|---|
| `acrossaiMcpManager.abilities.fields` | **← this document** — add columns |
| `acrossaiMcpManager.abilities.actions` | Add bulk actions (Expose/Hide siblings) |
| `acrossaiMcpManager.abilities.row` | Decorate each row with extra keys your columns can read |

## Stability

All three filter names carry `@since 0.1.0 @experimental May change without notice before 1.0.0`. Once the plugin tags 1.0.0, these names become semver-stable with a deprecation cycle for any change.
