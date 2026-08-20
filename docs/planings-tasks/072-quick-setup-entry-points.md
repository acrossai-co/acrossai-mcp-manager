# Planning: Quick Setup Entry Points (Feature 072)

Feature 069 shipped the Quick Setup wizard and wired a one-shot post-activation redirect to it, but the wizard has only **one** everyday entry point today (the admin bar chip added in `admin/Partials/QuickSetup/AdminBarEntry.php`). Operators who dismiss it can reach the wizard only via a URL they memorize. F072 adds four missing everyday entry points and repoints the activation redirect so first-run operators land on the wizard with the seeded default server preselected.

## Authoritative sources

- Spec: [`specs/072-quick-setup-entry-points/spec.md`](../../specs/072-quick-setup-entry-points/spec.md)
- Plan: [`specs/072-quick-setup-entry-points/plan.md`](../../specs/072-quick-setup-entry-points/plan.md)
- Tasks: [`specs/072-quick-setup-entry-points/tasks.md`](../../specs/072-quick-setup-entry-points/tasks.md)

## Five entry points (the architectural picture)

| # | Where | URL |
|---|---|---|
| 1 | Activation redirect target (`ActivationRedirect::maybe_redirect()`) | `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1` |
| 2 | Plugins-list row action link (`Menu::plugin_action_links()`), next to `Settings \| Deactivate \| Download` | `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1` |
| 3 | Second submenu under the shared **AcrossAI** menu (`Menu::register_submenu()`) — position 3, right after MCP @ 2 | `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1` |
| 4 | `page-title-action` link next to **Add New** on the MCP Servers list page (`Settings::render_servers_table()`) | `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1` |
| 5 | Per-row quicklink pill after **MCP Clients** in the Actions cell of the MCP Servers table (`MCPServerListTable::column_actions()`) | `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=<row-id>` |

**Rule for the `server` param.** Include it (with a concrete id) when the entry point is scoped to a specific server — activation redirect (default seeded id=1), plugins-list action (same first-run intent), per-row quicklink (that row's id). Omit it when the entry point is generic — submenu and list-page header link; the wizard opens on Step 1's server picker.

The wizard router already understands the `server` query arg (`src/js/quick-setup/hooks/useWizardRouter.js:67-74, 137-148`); no JS changes are needed for it to work.

## Final scope

Retained:
- One-line URL swap in `admin/Partials/QuickSetup/ActivationRedirect.php` (drop `first_run=1`, add `server=1`).
- New `Quick Setup` link in `admin/Partials/Menu.php::plugin_action_links()` — one canonical translation key.
- New `add_submenu_page()` call in `admin/Partials/Menu.php::register_submenu()` using a URL-literal menu-slug and empty render callback (no separate page — the submenu item links directly to the wizard).
- New `page-title-action` `<a>` in `admin/Partials/Settings.php::render_servers_table()`.
- New per-row `<a class="acrossai-quicklink">` (icon `dashicons-admin-tools`) in `admin/Partials/MCPServerListTable.php::column_actions()`.
- Optional: stale comment fix at `src/js/quick-setup/hooks/useWizardRouter.js:78` (`first_run` → `server`).

Not in scope:
- Changing the wizard's internal flow, step count, or step ordering (F069 owns that).
- Removing the existing admin bar chip in `AdminBarEntry.php` — it stays.
- A sixth entry point on the per-server-edit page's tab bar — revisit later.
- Any REST, DB, or React-bundle changes.

## Durable lesson

**Wizards need more than one discoverable entry point.** A first-run redirect plus an admin bar chip alone are not enough — operators who dismiss the redirect and don't habitually scan the admin bar will lose the wizard. Surface a wizard wherever the operator is likely to look for it: the plugins-list row action, the primary submenu, the list-page header, and per-row actions. All five entry points route to the same `?quick-setup=1&step=1` URL — one canonical destination behind many discoverable doors. Applicable to any future onboarding wizard shipped under the shared AcrossAI menu.

## Reference code

The URL-literal-as-menu-slug pattern in FR-003 avoids inventing a new render callback for a submenu that only navigates:

```php
add_submenu_page(
    SettingsPage::PARENT_SLUG,
    __( 'Quick Setup', 'acrossai-mcp-manager' ),
    __( 'Quick Setup', 'acrossai-mcp-manager' ),
    'manage_options',
    'admin.php?page=' . AdminPageSlugs::PARENT . '&quick-setup=1&step=1',
    '', // no render callback — menu item links directly
    3
);
```

Added: 2026-08-20 via branch `072-quick-setup-entry-points`.
