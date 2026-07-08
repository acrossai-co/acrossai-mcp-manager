# Extending the per-server Edit page

**Feature 019** (`acrossai-mcp-manager >= 0.0.7`) — companion plugins can add, remove, reorder, or re-gate tabs on the Edit MCP Server page (`?page=acrossai_mcp_manager&action=edit&server=N`) via a single WordPress filter.

The extension surface is intentionally symmetric with the vendor `acrossai-co/main-menu` `acrossai_settings_tabs` filter on Settings → AcrossAI. If you've written a Settings tab plugin, this is the same mental model at a different URL.

## TL;DR

```php
add_filter(
    'acrossai_mcp_manager_server_tabs',
    static function ( array $tabs, array $server ): array {
        $tabs[] = [
            'slug'            => 'notes',
            'label'           => __( 'Notes', 'my-plugin' ),
            'priority'        => 45,          // slots between wp-cli (40) and tools (50)
            'render_callback' => 'my_plugin_render_notes_tab',
        ];
        return $tabs;
    },
    10,
    2
);

function my_plugin_render_notes_tab( array $server ): void {
    printf( '<div class="mcp-tab-panel"><p>Editing: %s</p></div>', esc_html( $server['server_name'] ) );
}
```

That's it. Your Notes tab now appears in the nav bar for every MCP server's Edit page. Clicking it preserves `action=edit&server=N` in the URL.

## Filter contract

Filter name: **`acrossai_mcp_manager_server_tabs`**

Signature: **`apply_filters( 'acrossai_mcp_manager_server_tabs', array $tabs, array $server ): array`**

- **`$tabs`** — pre-seeded with the ten built-ins (Overview, npm, Clients, WP-CLI, Tools, Abilities, Access Control, MCP Tracker, Update Server, Danger Zone). Your callback receives them and can add, remove, reorder, or mutate any entry. Built-ins are marked with an internal `_builtin => true` key — you don't need to set that for your own entries.
- **`$server`** — the current server row array (`id`, `server_name`, `server_slug`, `registered_from`, `is_enabled`, …). Use it to conditionally add / hide a tab based on the server's state.

Return an array of entries. Entries missing required keys are dropped silently in production and with `_doing_it_wrong` under `WP_DEBUG`.

## Entry shape

| Key | Type | Required | Default | Notes |
|---|---|---|---|---|
| `slug` | `string` | ✅ | — | `sanitize_key()` applied. Cannot clash with a built-in — first-registration wins, and built-ins are always seeded first. |
| `label` | `string` | ✅ | — | Shown in the nav bar. `esc_html()` applied at render time. Should be i18n'd. |
| `render_callback` | `callable` | ✅ | — | `function ( array $server ): void { … }`. Echoes tab body HTML. Errors caught — see [Throw safety](#throw-safety). |
| `priority` | `int` | ❌ | `100` | Lower renders further left. Built-in slots: 10, 20, 30, 40, 50, 60, 70, 80, 90, 100. Ties break by insertion order (stable sort). |
| `capability` | `string` | ❌ | `'manage_options'` | Checked via `current_user_can()`. If false, the tab is not shown and `render_callback` is never invoked. |
| `visible_callback` | `?callable` | ❌ | `null` | `function ( array $server ): bool { … }`. Runs after the capability check. Return `false` to hide the tab. Errors caught — see [Throw safety](#throw-safety). |

## Priority slots

Built-in tabs are pinned at:

| Slug | Priority |
|---|---|
| `overview` | 10 |
| `npm` | 20 |
| `clients` | 30 |
| `wp-cli` | 40 |
| `tools` | 50 |
| `abilities` | 60 |
| `access-control` | 70 |
| `mcp-tracker` | 80 |
| `update-server` | 90 |
| `danger-zone` | 100 |

Choose a priority to slot your tab where you want it in the nav bar. Omit the key and it defaults to 100 (adjacent to Danger Zone).

## Worked examples

### Add a tab

```php
add_filter(
    'acrossai_mcp_manager_server_tabs',
    static function ( array $tabs ): array {
        $tabs[] = [
            'slug'            => 'my-plugin-audit',
            'label'           => __( 'Audit', 'my-plugin' ),
            'priority'        => 65,           // between abilities (60) and access-control (70)
            'capability'      => 'manage_options',
            'render_callback' => static function ( array $server ): void {
                $audit_rows = my_plugin_get_audit_rows_for_server( (int) $server['id'] );
                if ( empty( $audit_rows ) ) {
                    printf( '<p>%s</p>', esc_html__( 'No audit entries yet.', 'my-plugin' ) );
                    return;
                }
                echo '<table class="widefat">';
                echo '<thead><tr><th>When</th><th>Actor</th><th>Action</th></tr></thead><tbody>';
                foreach ( $audit_rows as $row ) {
                    printf(
                        '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                        esc_html( $row['when'] ),
                        esc_html( $row['actor'] ),
                        esc_html( $row['action'] )
                    );
                }
                echo '</tbody></table>';
            },
        ];
        return $tabs;
    }
);
```

### Remove a built-in

```php
add_filter(
    'acrossai_mcp_manager_server_tabs',
    static function ( array $tabs ): array {
        return array_values(
            array_filter(
                $tabs,
                static fn ( array $entry ): bool => 'mcp-tracker' !== ( $entry['slug'] ?? '' )
            )
        );
    }
);
```

### Gate a tab by role via `visible_callback`

The `capability` field handles the common case (any `current_user_can()` string). For more nuanced logic — role composition, per-server ACL, feature flag — use `visible_callback`:

```php
$tabs[] = [
    'slug'             => 'editor-notes',
    'label'            => __( 'Editor Notes', 'my-plugin' ),
    'capability'       => 'manage_options',       // still enforced first
    'visible_callback' => static function ( array $server ): bool {
        // Only show for servers still in draft state to users with edit_others_posts.
        return 'draft' === ( $server['workflow_state'] ?? '' )
            && current_user_can( 'edit_others_posts' );
    },
    'render_callback'  => 'my_plugin_render_editor_notes',
];
```

The capability check runs first; the callback second. If either fails, the tab is not shown and `render_callback` is never invoked.

### Rename or re-slot a built-in

Mutate the entry in place:

```php
add_filter(
    'acrossai_mcp_manager_server_tabs',
    static function ( array $tabs ): array {
        foreach ( $tabs as &$entry ) {
            if ( 'abilities' === ( $entry['slug'] ?? '' ) ) {
                $entry['label']    = __( 'Ability Selector', 'my-plugin' );
                $entry['priority'] = 5;   // move leftmost
            }
        }
        unset( $entry );
        return $tabs;
    }
);
```

You cannot replace a built-in's `render_callback` — the built-in class instance handles rendering, and `render_callback` on a built-in entry is `null` by design. To replace a built-in body outright, remove the built-in and add your own tab with the same slug (though be aware of the first-registration-wins rule below).

## Rules that keep the page safe

### Slugs are first-registration-wins

Built-ins are seeded first, so a third-party entry with `slug === 'overview'` is dropped. Under `WP_DEBUG` you'll see a `_doing_it_wrong` notice; in production it's silently ignored. Use unique slugs — prefix with your plugin's namespace to be safe:

```php
'slug' => 'my-plugin-audit',   // ✅ prefixed
'slug' => 'audit',             // ⚠ risks future collision if a built-in "audit" tab lands
```

### Capability check comes before your code runs

A user who cannot satisfy `capability` (default `manage_options`) does not see the tab and does not trigger `render_callback` or `visible_callback`. This is enforced at the `visible_for()` boundary — you don't need to duplicate the check inside your callback.

### Throw safety

Both `render_callback` and `visible_callback` are invoked inside `try { … } catch ( \Throwable $t ) { … }` blocks:

- A throw from `render_callback` is caught, logged via `error_log()` (`[acrossai-mcp-manager] Feature 019 — third-party render_callback for tab "<slug>" threw <ExceptionClass>: <message> in <file>:<line>`), and rendered as an inline `<div class="notice notice-error inline">` in the tab body area. The other tabs on the page continue to render. **The Edit page does not white-screen.**
- A throw from `visible_callback` is caught, logged the same way, and treated as "return false" — the tab is hidden for this request.

This mirrors the `safeApplyFilters` JS pattern from Feature 017 (`src/js/abilities.js`) applied to PHP.

### Non-callable render_callback

Your entry MUST have a callable `render_callback`. Anything else (missing key, string that's not a valid function name, closure that's been garbage collected) will cause the entry to be dropped at `Registry::normalize_entries()` time.

## Symmetry with the vendor Settings tab filter

`acrossai-co/main-menu` ships `acrossai_settings_tabs` for Settings → AcrossAI. The two filters share the same shape:

| Aspect | `acrossai_settings_tabs` (vendor) | `acrossai_mcp_manager_server_tabs` (this filter) |
|---|---|---|
| Where | Settings → AcrossAI, `?page=acrossai-settings` | Edit MCP Server, `?page=acrossai_mcp_manager&action=edit&server=N` |
| Entry shape | `[slug, label, priority, capability]` | `[slug, label, priority, capability, render_callback, visible_callback]` |
| Rendering | via WP Settings API (`add_settings_section()` + `add_settings_field()`) | via `render_callback` in the entry |
| Dedup | first-registration wins | first-registration wins, built-ins seeded first |
| Throw safety | not specified — Settings API doesn't invoke your code | ✅ caught in `FilteredServerTab::render_body()` / `visible_for()` |

The extra keys on this filter (`render_callback`, `visible_callback`) exist because per-server tabs are heterogeneous — not every tab is a Settings API form. See [`docs/planings-tasks/019-per-server-tabs-filter.md`](planings-tasks/019-per-server-tabs-filter.md) for the design rationale.

## See also

- **Companion filter** — [`acrossai_settings_tabs`](../vendor/acrossai-co/main-menu/README.md) on the vendor's Settings page.
- **JS-side extensibility** — [`docs/extending-abilities-tab.md`](extending-abilities-tab.md) for extending the Abilities React app via `@wordpress/hooks`.
- **Feature planning** — [`docs/planings-tasks/019-per-server-tabs-filter.md`](planings-tasks/019-per-server-tabs-filter.md) for the design rationale + constraints.
