# Implementation Plan — F072 Quick Setup Entry Points

**Branch**: `072-quick-setup-entry-points` | **Date**: 2026-08-20 | **Spec**: [spec.md](./spec.md)

## Summary

Small additive UX change. Four PHP files touched, one optional JS comment fix. No new classes, no new hook registrations, no schema, no REST, no vendor bump, no React bundle change. Every entry point routes to the same `?quick-setup=1&step=1` URL family; the wizard router already understands the `server` deep-link (see `src/js/quick-setup/hooks/useWizardRouter.js:67-74, 137-148`), so no JS work is needed for the preselection semantics either.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline).
**Primary Dependencies**: none new — reuses `\AcrossAI_Main_Menu\SettingsPage::PARENT_SLUG` (already imported in `Menu.php`), `AdminPageSlugs::PARENT`, `admin_url()`, `add_query_arg()`, `esc_url()`.
**Storage**: none — no DB changes, no options, no transients (the existing activation transient stays untouched; only its consumer's destination URL changes).
**Testing**: PHPCS only. No PHPUnit — pure config/UX wiring, nothing to assert.
**Constraints**: Strictly additive. All new user-visible strings share the single canonical translation key `__( 'Quick Setup', 'acrossai-mcp-manager' )`.
**Scale/Scope**: ~30 LOC net addition across four PHP files + one optional one-word JS comment update.

## Constitution Check

*Every principle evaluated. All pass without deviations.*

**I. Modular Architecture** — Every touched class stays in its existing module. No cross-module coupling, no new files, no new singletons.

**II. Additive** — No existing behavior removed. The activation-redirect URL param `first_run=1` is dropped, but it was already unreferenced in every consumer (only the URL literal itself and a stale JS doc-comment mention it — see FR-010).

**III. Security** — All URLs escaped at boundary via `esc_url()`. All callbacks gated on `manage_options` (matches existing surface). No user input consumed, no output rendered from external sources.

**IV. UI Components** — No React changes. The wizard bundle is untouched.

**V. Extensibility** — No new filters or hooks needed. F072 consumes existing WP hooks (`plugin_action_links_<basename>`, `admin_menu` for `add_submenu_page`) and existing render paths.

**VI. DRY** — Single canonical translation key `__( 'Quick Setup', 'acrossai-mcp-manager' )` shared by FR-002 / FR-003 / FR-004 / FR-005.

**VII. Tests First** — No unit-testable branching logic — pure config/UX wiring. PHPCS gates the change; SC-001 → SC-005 cover manual smoke.

## Constitution-adjacent memory guidance

- **Admin Partials Rule** — Every touched file already lives under `admin/Partials/`. No files migrate. No enqueue calls added (all links are `<a href>` — no scripts or styles).
- **Singleton pattern** — No new singletons. Existing `Menu::instance()`, `Settings::instance()`, `MCPServerListTable` all follow the pattern; F072 only adds output in their existing methods.
- **Hook registration** — Zero new `add_action`/`add_filter` calls. `Menu::register_submenu()` and `Menu::plugin_action_links()` are already wired in `Includes\Main::define_admin_hooks()` (see `includes/Main.php:349-360`). `Settings::render_servers_table()` and `MCPServerListTable::column_actions()` are called downstream from those existing wire-ups.
- **UI Contract** — F072 does not touch any `@wordpress/dataforms` / `@wordpress/dataviews` surface. The MCP Servers list is a WP-native `WP_List_Table` (predates the UI contract for tables); F072 preserves that shape and only appends one column-render item.

## Project Structure

### Documentation (this feature)

```
specs/072-quick-setup-entry-points/
├── spec.md                    # Feature specification (this feature's user stories + FRs)
├── plan.md                    # This file
└── tasks.md                   # Ordered task list with per-task file/verification
```

### Source Code Changes

```
admin/Partials/QuickSetup/ActivationRedirect.php   # MODIFIED — line 106 URL swap
admin/Partials/Menu.php                            # MODIFIED — 2 methods (plugin_action_links, register_submenu)
admin/Partials/Settings.php                        # MODIFIED — render_servers_table()
admin/Partials/MCPServerListTable.php              # MODIFIED — column_actions()
src/js/quick-setup/hooks/useWizardRouter.js        # MODIFIED — optional line 78 comment
```

No new files. No deletions.

## Per-file change map

### `admin/Partials/QuickSetup/ActivationRedirect.php`

```php
// line 106 — before
$target = admin_url( 'admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&first_run=1' );

// after
$target = admin_url( 'admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1' );
```

Guards above the `$target` line (transient consume, bulk-activation skip, network-activation skip, `manage_options` check) are unchanged.

### `admin/Partials/Menu.php::plugin_action_links()`

Add a second link with the same shape as the existing Settings link. Prepend both so the final row-link order is `Settings | Quick Setup | Deactivate | Download`.

```php
public function plugin_action_links( $links ): array {
    $settings_url  = esc_url( admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT ) );
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        $settings_url,
        esc_html__( 'Settings', 'acrossai-mcp-manager' )
    );

    $quick_setup_url  = esc_url(
        admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT . '&quick-setup=1&step=1&server=1' )
    );
    $quick_setup_link = sprintf(
        '<a href="%s">%s</a>',
        $quick_setup_url,
        esc_html__( 'Quick Setup', 'acrossai-mcp-manager' )
    );

    // Prepend Quick Setup first, then Settings, so Settings ends up first.
    array_unshift( $links, $quick_setup_link );
    array_unshift( $links, $settings_link );

    return $links;
}
```

### `admin/Partials/Menu.php::register_submenu()`

Append a second `add_submenu_page()` call. Menu slug is a URL literal — WP renders the submenu item as a direct link to that URL, no page-render callback needed. Position 3 sits right after `MCP` (position 2).

```php
public function register_submenu(): void {
    $settings = Settings::instance();

    add_submenu_page(
        SettingsPage::PARENT_SLUG,
        __( 'MCP', 'acrossai-mcp-manager' ),
        __( 'MCP', 'acrossai-mcp-manager' ),
        'manage_options',
        AdminPageSlugs::PARENT,
        array( $settings, 'render_list_page' ),
        2
    );

    add_submenu_page(
        SettingsPage::PARENT_SLUG,
        __( 'Quick Setup', 'acrossai-mcp-manager' ),
        __( 'Quick Setup', 'acrossai-mcp-manager' ),
        'manage_options',
        'admin.php?page=' . AdminPageSlugs::PARENT . '&quick-setup=1&step=1',
        '',
        3
    );
}
```

### `admin/Partials/Settings.php::render_servers_table()`

Build a second URL alongside `$create_url` and add a second `page-title-action` `<a>` in the header `printf()`.

```php
$create_url = esc_url(
    add_query_arg(
        array(
            'page'   => AdminPageSlugs::PARENT,
            'action' => 'create',
        ),
        admin_url( 'admin.php' )
    )
);

$quick_setup_url = esc_url(
    add_query_arg(
        array(
            'page'        => AdminPageSlugs::PARENT,
            'quick-setup' => '1',
            'step'        => '1',
        ),
        admin_url( 'admin.php' )
    )
);

echo '<div class="wrap">';
printf(
    '<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end" />',
    esc_html__( 'MCP Servers', 'acrossai-mcp-manager' ),
    esc_url( $create_url ),
    esc_html__( 'Add New', 'acrossai-mcp-manager' ),
    esc_url( $quick_setup_url ),
    esc_html__( 'Quick Setup', 'acrossai-mcp-manager' )
);
```

### `admin/Partials/MCPServerListTable.php::column_actions()`

Append one more `<a class="acrossai-quicklink">` after the existing `$quick_links` loop. Emitted outside the loop so the loop stays tab-only (every existing loop entry carries `?tab=<slug>`; Quick Setup is not a tab).

```php
// ... existing $quick_links foreach loop unchanged ...

$quick_setup_url = add_query_arg(
    array(
        'page'        => AdminPageSlugs::PARENT,
        'quick-setup' => '1',
        'step'        => '1',
        'server'      => (int) $item['id'],
    ),
    admin_url( 'admin.php' )
);

$links_html .= sprintf(
    '<a href="%s" class="acrossai-quicklink"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span><span class="acrossai-quicklink-label">%s</span></a>',
    esc_url( $quick_setup_url ),
    esc_html__( 'Quick Setup', 'acrossai-mcp-manager' )
);

// existing return sprintf(...) is unchanged.
```

### `src/js/quick-setup/hooks/useWizardRouter.js` (optional, FR-010)

```js
// line 78 — before
// Preserve existing query params (page, quick-setup, first_run) — only

// after
// Preserve existing query params (page, quick-setup, server) — only
```

## Migration Concerns

None. F072 is entirely additive UI wiring. Operators on prior versions get the new entry points immediately once the plugin updates — no DB migration, no cache flush, no rewrite-rule flush needed.

## Rollback

Revert the branch commits. No side effects.
