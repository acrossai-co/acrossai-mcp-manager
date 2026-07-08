# Quickstart — Feature 019 Per-Server Tabs Filter

Ways to exercise the `acrossai_mcp_manager_server_tabs` filter on the developer's local install. Suitable copy-paste for a companion-plugin author.

## Prerequisites

- `acrossai-mcp-manager` on the Feature 019 branch or later.
- Admin user with `manage_options`.
- One MCP server registered — either database-source (via the Add MCP Server flow) or the plugin-seeded default at `?server=1`.
- Optional: `WP_DEBUG` on so `_doing_it_wrong` from malformed entries surfaces during development.

## Scratch companion plugin

Drop this file at `wp-content/plugins/acrossai-scratch-tabs/acrossai-scratch-tabs.php` and activate it via `wp plugin activate acrossai-scratch-tabs`:

```php
<?php
/**
 * Plugin Name: AcrossAI Scratch Tabs
 * Description: Feature 019 quickstart scaffolding.
 * Version: 0.0.1
 */

defined( 'ABSPATH' ) || exit;

add_filter(
    'acrossai_mcp_manager_server_tabs',
    static function ( array $tabs, array $server ): array {
        // (§Add a tab / §Remove a tab / §Break a tab go here — see below.)
        return $tabs;
    },
    10,
    2
);
```

Each section below is a drop-in replacement for the `// (…) return $tabs;` body.

## Add a tab

Adds a "Notes" tab at priority 45 — slots between the built-in `WpCliTab` (40) and `ToolsTab` (50).

```php
$tabs[] = [
    'slug'            => 'notes',
    'label'           => __( 'Notes', 'acrossai-scratch-tabs' ),
    'priority'        => 45,
    'capability'      => 'manage_options',
    'render_callback' => static function ( array $server ): void {
        printf(
            '<div class="mcp-tab-panel"><h3>%s</h3><p>%s</p></div>',
            esc_html__( 'Notes', 'acrossai-scratch-tabs' ),
            esc_html(
                sprintf(
                    /* translators: %s: server name */
                    __( 'Hello from a third-party tab. Editing server: %s', 'acrossai-scratch-tabs' ),
                    (string) ( $server['server_name'] ?? '' )
                )
            )
        );
    },
];
return $tabs;
```

**Verify**: Reload `/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1`. The Notes tab appears between WP-CLI and Tools. Click Notes → the URL becomes `?page=acrossai_mcp_manager&action=edit&server=1&tab=notes` and the panel renders "Hello from a third-party tab. Editing server: ...".

## Remove a tab

Removes the MCP Tracker tab.

```php
return array_values(
    array_filter(
        $tabs,
        static fn ( array $entry ): bool => 'mcp-tracker' !== ( $entry['slug'] ?? '' )
    )
);
```

**Verify**: Reload the Edit page. The MCP Tracker tab is absent from the nav bar. Manually navigate to `?…&tab=mcp-tracker` — the page falls through to `overview` with no fatal.

## Gate a tab by user role

Only shows the tab to users with the `edit_others_posts` capability (approximately Editor+). Uses `visible_callback` as a supplement to `capability`.

```php
$tabs[] = [
    'slug'             => 'editor-notes',
    'label'            => __( 'Editor Notes', 'acrossai-scratch-tabs' ),
    'priority'         => 47,
    'capability'       => 'manage_options',  // still required for admin_menu access
    'visible_callback' => static function ( array $server ): bool {
        return current_user_can( 'edit_others_posts' );
    },
    'render_callback'  => static function ( array $server ): void {
        echo '<div class="mcp-tab-panel"><p>Editor-only notes here.</p></div>';
    },
];
return $tabs;
```

**Verify**: Log in as a user with `edit_others_posts` — tab appears. Log in as a user without it — tab is absent from the nav bar and its callback is never invoked (confirm via `error_log()` in the callback: it never fires).

## Break a tab

Verifies the throw-safety guarantee — a broken third-party callback does NOT white-screen the page.

```php
$tabs[] = [
    'slug'            => 'broken',
    'label'           => __( 'Broken', 'acrossai-scratch-tabs' ),
    'priority'        => 999,
    'render_callback' => static function ( array $server ): void {
        throw new \RuntimeException( 'boom' );
    },
];
return $tabs;
```

**Verify**: Click the Broken tab. The page loads with a `<div class="notice notice-error inline">` message in the tab body — the rest of the Edit page renders normally. `wp-content/debug.log` contains a line: `[acrossai-mcp-manager] Feature 019 — third-party render_callback for tab "broken" threw: boom in .../acrossai-scratch-tabs.php:XX`.

Other tabs (Overview / Abilities / etc.) still render when navigated to. The Edit page is not WSOD'd.

## Reorder built-ins

Move Abilities to the very front (priority 5, ahead of Overview at 10). Uses mutation of the existing entry in place.

```php
foreach ( $tabs as &$entry ) {
    if ( 'abilities' === ( $entry['slug'] ?? '' ) ) {
        $entry['priority'] = 5;
    }
}
unset( $entry );
return $tabs;
```

**Verify**: Reload the Edit page. Abilities is now the leftmost tab.

## Gates that must pass

```bash
# From the plugin root.
composer phpcs      # zero errors on Registry.php, FilteredServerTab.php, AbstractServerTab.php, ten built-ins, both test files
composer phpstan    # level 8, zero errors, no new baseline
composer test       # existing tests PASS + 11 new methods PASS

grep -rn "apply_filters( 'acrossai_mcp_manager_server_tabs'" admin/  # expected: exactly one match — Registry.php
```

## Rollback

Remove the scratch plugin (`wp plugin deactivate acrossai-scratch-tabs`), then the Edit page reverts to the built-in-only view immediately — the filter has no persistent side effects.
