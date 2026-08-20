# Feature 072 — Quick Setup Entry Points

**Status**: Planned
**Branch**: `072-quick-setup-entry-points`
**Date**: 2026-08-20

## Summary

Feature 069 shipped the Quick Setup wizard and wired a one-shot post-activation redirect to it, but the wizard has only one everyday entry point today (the admin bar chip). Operators who dismiss that chip can only reach the wizard by typing the URL. F072 adds four missing everyday entry points — plugins-list row action, primary submenu under **AcrossAI**, list-page header button, and per-row quicklink pill — and repoints the activation redirect so first-run operators land on the wizard with the seeded default server preselected instead of a still-empty servers list.

## User Stories

**US1 — First-run operator (top priority)** — As an operator activating the plugin for the first time, I want to land directly on the wizard with the default server preselected so I can complete Quick Setup without hunting for the entry point.

**US2 — Returning operator (plugins list)** — As an operator on `/wp-admin/plugins.php`, I want to reopen the wizard directly from the plugin's row without clicking through to the plugin's main page.

**US3 — Returning operator (admin menu)** — As an operator navigating the AcrossAI submenu, I want a dedicated **Quick Setup** submenu item so I can reopen the wizard from anywhere in wp-admin without knowing the URL.

**US4 — Returning operator (servers list header)** — As an operator on the MCP Servers list page, I want a **Quick Setup** button next to **Add New** so I can reopen the wizard from the same page I use to manage servers.

**US5 — Returning operator (per-row)** — As an operator on the MCP Servers list, I want a per-row **Quick Setup** quicklink pill so I can rerun the wizard scoped to a specific existing server (server id preselected via `?server=<id>`) rather than the generic Step 1 picker.

## Functional Requirements

**FR-001** — MUST change the activation-redirect target in `admin/Partials/QuickSetup/ActivationRedirect.php` (line 106) to include `&server=1` (id of the seeded `DefaultServerSeeder` row) and drop `&first_run=1`. Final URL: `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1`.

**FR-002** — MUST add a `Quick Setup` link to `plugin_action_links_<basename>` output in `admin/Partials/Menu.php::plugin_action_links()`, pointing at `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1`. Final row-link order: `Settings | Quick Setup | Deactivate | Download`.

**FR-003** — MUST register a second submenu under `\AcrossAI_Main_Menu\SettingsPage::PARENT_SLUG` in `admin/Partials/Menu.php::register_submenu()`, labelled `Quick Setup`, at position 3 (right after `MCP` @ position 2). Menu slug is a URL literal (`admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1`) and the render callback is an empty string — the item links directly to the wizard, no dedicated page is created.

**FR-004** — MUST add a second `page-title-action` link labelled `Quick Setup` next to `Add New` in `admin/Partials/Settings.php::render_servers_table()`. Target URL: `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1` (no `server` param — this is a generic entry).

**FR-005** — MUST add a fifth per-row quicklink pill labelled `Quick Setup` (icon `dashicons-admin-tools`) appended after the existing four (`Connectors`, `Access Control`, `Abilities`, `MCP Clients`) in `admin/Partials/MCPServerListTable.php::column_actions()`. Target URL: `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=<row-id>`. The pill is emitted **outside** the existing `$quick_links` loop (Option A in the plan) so the loop stays tab-only and Quick Setup's non-tab URL doesn't leak `?tab=...` noise.

**FR-006** — Every entry point MUST use `manage_options` as its capability gate — matches the existing submenu, plugin-action-link, and `ActivationRedirect` capability behavior. No new capability constants introduced.

**FR-007** — All admin URLs MUST be built through `admin_url()` + `add_query_arg()` (or, for FR-001, a plain string that `admin_url()` wraps) and rendered through `esc_url()` at the boundary. Matches sibling code and S5/B6 defense-in-depth.

**FR-008** — All user-visible labels MUST use the single canonical translation key `__( 'Quick Setup', 'acrossai-mcp-manager' )`. No label variants (`__( 'Setup Wizard', ... )`, `__( 'Quick-Setup', ... )`, etc.).

**FR-009** — MUST NOT introduce any new hook registration files, new singletons, or new `add_action`/`add_filter` calls. All wiring flows through the existing `Includes\Main::define_admin_hooks()` — unchanged, because the existing `Menu::instance()` singleton already covers FR-002/FR-003, and `Settings` / `MCPServerListTable` already render on their existing hooks.

**FR-010** (optional) — MAY update the stale comment at `src/js/quick-setup/hooks/useWizardRouter.js:78` from `first_run` to `server` so the doc-comment lists the actually-preserved query params. Not a blocker.

## Success Criteria

**SC-001** — After deactivating + reactivating the plugin, the browser lands on `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1` and Step 1 opens with the default server (id 1) preselected. Navigating to Step 2+ preserves `&server=1` (already handled by `useWizardRouter.setServer`).

**SC-002** — `/wp-admin/plugins.php` row for AcrossAI MCP Manager reads `Settings | Quick Setup | Deactivate | Download`, and clicking **Quick Setup** opens the same URL as SC-001.

**SC-003** — The AcrossAI top-level admin menu shows `Quick Setup` directly below `MCP`, and clicking it opens `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1` (no `server` param) — the wizard opens on the Step 1 server picker.

**SC-004** — The MCP Servers list page header reads `MCP Servers   [Add New]   [Quick Setup]`, and clicking **Quick Setup** opens the same URL as SC-003.

**SC-005** — Every row in the MCP Servers table shows a fifth `Quick Setup` quicklink after `MCP Clients`, and clicking it opens `…&quick-setup=1&step=1&server=<that-row's-id>` with that server preselected in Step 1.

**SC-006** — PHPCS clean on all four modified PHP files (`ActivationRedirect.php`, `Menu.php`, `Settings.php`, `MCPServerListTable.php`).

**SC-007** — Bulk-activation, network-activation, and `manage_options`-fail guards in `ActivationRedirect::maybe_redirect()` remain intact (unchanged code above the `$target` assignment).

## Out of Scope

- Changing the wizard's internal flow, step count, or step ordering — F069 owns that.
- Adding a sixth entry point on the per-server-edit page's tab bar — not requested; revisit later if operators ask.
- Removing the existing admin bar chip in `AdminBarEntry.php` — it stays as a fifth channel; F072 is strictly additive relative to F069's surface.
- Removing the `first_run` URL param from `AdminBarEntry.php` — the chip already uses `&step=1` without it, so nothing to remove.
- Server-side handling of a new `first_run_dismissed` flag or one-shot banner — F072 does not track dismissal state; every entry point is available every time.
- WP-CLI or REST-driven activation flows — the transient-based redirect handles the interactive-admin case; a WP-CLI activation without a follow-up admin request lets the transient TTL expire naturally.
- Unit tests — the change is pure config/UX wiring with no branching logic worth asserting in PHPUnit; PHPCS + manual smoke check cover it.
