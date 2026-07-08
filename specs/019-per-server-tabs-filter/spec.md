# Feature Specification: Third-party filter for per-server tabs

**Feature Branch**: `019-per-server-tabs-filter`
**Created**: 2026-07-08
**Status**: Draft
**Input**: User description: "add a filter to add,remove some tabs from ?page=acrossai_mcp_manager&action=edit&server=1&tab=mcp-tracker so that third party plugin can do it"

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Companion plugin adds a per-server tab (Priority: P1)

A companion plugin author hooks the new `acrossai_mcp_manager_server_tabs` filter, appends a new entry with `slug`, `label`, and a `render_callback` that echoes tab body HTML, and their tab appears in the Edit MCP Server page's nav bar between the built-ins they slot around (via `priority`). Clicking the new tab preserves `action=edit&server=N` in the URL and dispatches to the render callback with the current `$server` array.

**Why this priority**: This is the primary motivation for Feature 019. Without it, companion plugins have no way to extend the per-server admin surface — every extension attempt today would require monkey-patching `Registry::all_tabs()`, which the plugin's autoloader precludes.

**Independent Test**: Install a scratch companion plugin that hooks the filter with a "Notes" entry (slug `notes`, label "Notes", priority 45, render_callback echoing `<p>hello from third-party</p>`). On any MCP server's Edit page, verify the Notes tab appears between `ToolsTab` (priority 50) and `WpCliTab` (priority 40). Click Notes — verify the URL is `?page=acrossai_mcp_manager&action=edit&server=N&tab=notes` and the callback output renders in the tab body area.

**Acceptance Scenarios**:

1. **Given** a companion plugin has hooked the filter with a valid entry, **When** an admin loads the Edit MCP Server page for any server, **Then** the tab nav bar shows the entry's label at the position determined by its `priority`.
2. **Given** the admin clicks the third-party tab, **When** the page loads, **Then** the URL query string retains `page=acrossai_mcp_manager&action=edit&server=N` and adds `tab=<third-party-slug>`, and the render callback's output is displayed inside the plugin's `<div class="wrap">` chrome.
3. **Given** the third-party entry omits `priority`, **When** the tab list is computed, **Then** the entry defaults to `priority = 100` and sorts adjacent to the last built-in (`DangerZoneTab`, priority 100) using insertion-order as the tie-breaker.
4. **Given** the current user lacks the entry's `capability` (default `manage_options`), **When** the Edit page loads, **Then** the third-party tab is omitted from the nav bar and its render callback is never invoked.

---

### User Story 2 — Companion plugin removes a built-in tab (Priority: P1)

A companion plugin author hooks the filter and returns a list with one of the built-in entries removed (e.g. filters out `mcp-tracker`). The Edit MCP Server page's nav bar renders without the removed tab. Navigating to `&tab=mcp-tracker` in the URL falls through to the first surviving tab (`overview`) rather than fataling.

**Why this priority**: Companion plugins that superset or replace a built-in feature must be able to hide the built-in — otherwise the admin UI shows duplicated / stale surface. Vendor's `acrossai_settings_tabs` model supports this; parity with Feature 019 keeps the two extension surfaces symmetric.

**Independent Test**: Install a scratch companion plugin that hooks the filter and `unset()`s the entry whose slug is `mcp-tracker`. On any MCP server's Edit page, verify the MCP Tracker tab is absent from the nav bar. Navigate to `?…&tab=mcp-tracker` explicitly — verify the page renders `overview` (fallback) with no fatal.

**Acceptance Scenarios**:

1. **Given** the filter callback removes the `mcp-tracker` entry, **When** the Edit page renders, **Then** the tab nav bar does NOT include an MCP Tracker link.
2. **Given** the `mcp-tracker` entry is removed and the admin loads `?…&tab=mcp-tracker` directly, **When** `Registry::render()` dispatches, **Then** it falls back to the first available tab (`overview`) with no PHP fatal.
3. **Given** the filter removes a DB-only tab (e.g. `danger-zone`), **When** the page loads for a database-source server, **Then** the tab is absent even though the built-in's `visible_for()` would have returned true.

---

### User Story 3 — Broken companion plugin does not white-screen the Edit page (Priority: P1)

A companion plugin author's `render_callback` throws an exception (or fatal PHP error). The Edit MCP Server page continues to render — the other 10 tabs are unaffected — and an inline `notice notice-error` explains that the third-party tab could not render. The exception is logged via `error_log()` for the operator to investigate.

**Why this priority**: Feature 017 established the `safeApplyFilters` JS pattern for exactly this reason (a broken `@wordpress/hooks` filter must not white-screen the Abilities React app). The PHP tab surface needs the same guarantee. Without it, a single buggy companion plugin can WSOD the entire wp-admin for every MCP server operator.

**Independent Test**: Install a scratch companion plugin whose `render_callback` unconditionally `throw new \RuntimeException( 'boom' );`. On the Edit MCP Server page, click the broken tab. Verify:
- No PHP fatal reaches the browser.
- An inline `<div class="notice notice-error">` renders in the tab body area.
- `wp-content/debug.log` contains an `error_log` line naming the tab slug and the exception message.
- Other tabs continue to render normally.

**Acceptance Scenarios**:

1. **Given** the current tab's `render_callback` throws a `\Throwable`, **When** `FilteredServerTab::render_body()` invokes it, **Then** the throw is caught, an inline error notice renders, `error_log()` is called with the tab slug + exception details, and the request completes with a 200.
2. **Given** the current tab's `visible_callback` throws, **When** `FilteredServerTab::visible_for()` invokes it, **Then** the throw is caught, the tab is treated as not visible (returns `false`), and `error_log()` records the failure. The tab does not appear in the nav bar for this request.
3. **Given** a `render_callback` is missing / non-callable, **When** `Registry::normalize_entries()` processes the raw filter output, **Then** the entry is dropped, `_doing_it_wrong` is called under `WP_DEBUG`, and the tab does NOT appear in the nav bar — no runtime error.

---

### User Story 4 — Built-in tabs are unchanged for operators (Priority: P1)

A site with zero companion plugins active — or all companion plugins that do NOT hook the new filter — sees the Edit MCP Server page exactly as it renders on the `main` branch pre-Feature-019: 10 built-in tabs in the same order (Overview, npm, Clients, WP-CLI, Tools, Abilities, Access Control, MCP Tracker, Update Server, Danger Zone), the same labels, the same URL scheme, the same behaviour of `UpdateServerTab` / `DangerZoneTab` visible-for-database-source-only gating.

**Why this priority**: Feature 019 is a NEW-surface feature. Any observable regression to the existing operator experience is a defect. The spec must guard against accidental changes to slug, label, ordering, or visibility rules.

**Independent Test**: On a site with only `acrossai-mcp-manager` (and, for the test's reproducibility, `acrossai-abilities-manager`) active — no third-party filter callbacks — load `/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=1`. Compare the tab nav bar byte-for-byte against the same URL on a checkout of `main` at commit `46d214b` (the post-018 merge). No difference is acceptable.

**Acceptance Scenarios**:

1. **Given** no callback is registered on `acrossai_mcp_manager_server_tabs`, **When** the Edit page renders, **Then** the tab nav bar shows exactly 10 tabs on a database-source server (all built-ins) and exactly 8 tabs on a plugin-source server (built-ins minus `UpdateServerTab` and `DangerZoneTab`).
2. **Given** the tab list is computed for either server source, **When** the effective list is sorted by priority, **Then** the resulting slug order matches: `overview, npm, clients, wp-cli, tools, abilities, access-control, mcp-tracker[, update-server, danger-zone]`.
3. **Given** the same server is edited, **When** the URL for any tab is constructed, **Then** it includes `page=acrossai_mcp_manager&action=edit&server=N&tab=<slug>` in that exact order (matches the pre-019 `SettingsRenderer::render_tab_nav()` output).

---

### Edge Cases

- **Third-party slug matches a built-in.** First-registration wins. Built-ins are seeded first, so the third-party entry is dropped with `_doing_it_wrong` under `WP_DEBUG`. The built-in's identity is preserved.
- **Third-party entry with `priority = 5`.** Sorts before `OverviewTab` (priority 10). Nav bar shows the third-party tab in the leftmost position.
- **Third-party entry with a non-callable `render_callback`.** Entry is dropped in `normalize_entries()`, `_doing_it_wrong` fires under `WP_DEBUG`.
- **Third-party entry with empty `slug` after `sanitize_key()`.** Entry is dropped in `normalize_entries()`.
- **Filter callback returns non-array.** Coerced to empty list; the built-ins-only view is preserved (matches vendor's `TabbedPageRenderer::resolve_tabs()` behaviour).
- **`visible_callback` returns non-bool.** Coerced via `(bool)`. `null` return counts as `false`.
- **Third-party removes ALL built-ins.** The first surviving third-party tab renders. If no third-party tabs remain either, `Registry::render()` no-ops and `Settings::render_edit_page()` renders an empty tab area — no fatal.
- **`WP_DEBUG` off + malformed entry.** Entry is silently dropped without `_doing_it_wrong`. Runtime is unaffected. Matches vendor's non-debug behaviour.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `Registry::for_server( array $server ): AbstractServerTab[]` MUST fire `apply_filters( 'acrossai_mcp_manager_server_tabs', array $entries, array $server )` exactly once per invocation. The initial `$entries` argument MUST be the ten built-ins already converted to entry-array shape, seeded with `_builtin => true`.
- **FR-002**: `Registry::normalize_entries()` MUST drop entries missing `slug`, `label`, or callable `render_callback`. Under `WP_DEBUG`, dropped entries MUST trigger `_doing_it_wrong` with the filter name, the reason, and the module version.
- **FR-003**: `Registry::normalize_entries()` MUST dedup by `slug` on a first-registration-wins basis, matching vendor `TabbedPageRenderer::resolve_tabs()`. Built-ins seeded first are never displaced by a third-party entry with the same slug.
- **FR-004**: The effective tab list MUST sort by ascending `priority`. Ties MUST be broken by insertion order (stable sort).
- **FR-005**: Built-in tabs MUST retain their default priority slot (10, 20, 30, 40, 50, 60, 70, 80, 90, 100) via a `priority(): int` override on each concrete `AbstractServerTab` subclass.
- **FR-006**: `AbstractServerTab::priority()` MUST default to 100 (non-abstract). Every third-party entry omitting `priority` is treated as 100.
- **FR-007**: `FilteredServerTab::visible_for( array $server )` MUST return `false` when the current user cannot satisfy the entry's `capability` (default `manage_options`). It MUST also short-circuit to `false` when a set `visible_callback` returns `false`. Order: capability check first; callback second.
- **FR-008**: `FilteredServerTab::render_body( array $server )` MUST wrap the entry's `render_callback` in `try { … } catch ( \Throwable $t ) { … }`. On catch: `error_log()` is called with the tab slug + `$t->getMessage()` + `$t->getFile()` + `$t->getLine()`, and an inline `<div class="notice notice-error inline">` is echoed in the tab body area. The exception MUST NOT propagate.
- **FR-009**: `Registry::render( string $tab_slug, array $server )` MUST dispatch to third-party tabs when the requested slug matches a filtered entry. Fallback to the first tab in `for_server()` when the slug is unknown, as today.
- **FR-010**: `Registry::visible_tabs( array $server )` MUST use `for_server( $server )` internally, then apply the per-tab `visible_for( $server )` filter. Third-party tabs are subject to both the capability check (inside `FilteredServerTab::visible_for()`) and the visible_callback.
- **FR-011**: `Registry::all_tabs()` MUST retain its current signature and behaviour — returns the ten built-in `AbstractServerTab` instances in their canonical class-list order. Feature 019 does NOT invoke the filter from here.
- **FR-012**: Existing built-in tab classes MUST NOT have their slug, label, or `visible_for()` behaviour changed by Feature 019.
- **FR-013**: `SettingsRenderer::render_tab_nav()` MUST NOT be changed. The URL scheme it emits (`page=acrossai_mcp_manager&action=edit&server=N&tab=SLUG`) MUST work for third-party tabs identically to built-ins.
- **FR-014**: The filter MUST fire with the current `$server` array as the second argument, so callbacks can decide per-server whether to contribute a tab without wrapping every entry in a callback.
- **FR-015**: A third-party entry MUST NOT need to load `AbstractServerTab` — the extension surface is array-based, and a plugin author can hook the filter from a pure-function callback.
- **FR-016**: Post-patch grep — `grep -rn "apply_filters( 'acrossai_mcp_manager_server_tabs'" admin/` returns EXACTLY one match (the fire inside `Registry::for_server()`). No duplicate fires from any other file.

### Key Entities *(mandatory)*

**Entry shape (normalized)**:

```
[
    'slug'             => string,      // required, sanitize_key applied
    'label'            => string,      // required, escape at render time
    'priority'         => int,         // default 100
    'capability'       => string,      // default 'manage_options'
    'render_callback'  => callable,    // required, ($server) => void
    'visible_callback' => ?callable,   // optional, ($server) => bool
    '_builtin'         => bool,        // internal — true for built-ins seeded from the class list
]
```

No new database tables. No new WordPress options. No REST route additions.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A companion plugin hooking `acrossai_mcp_manager_server_tabs` with a valid Notes entry produces a Notes tab in the Edit MCP Server page's nav bar at the priority-determined position on 5/5 attempts across two different browsers.
- **SC-002**: The Notes tab's click preserves `action=edit&server=N` in the URL — verified against `window.location.href` in the browser console.
- **SC-003**: A companion plugin filter that removes `mcp-tracker` produces a nav bar with 9 tabs (database source) and 7 tabs (plugin source) — verified by DOM count.
- **SC-004**: A companion plugin `render_callback` that throws a `\RuntimeException` results in a rendered inline error notice + a `wp-content/debug.log` line + zero white-screens across 10 refreshes.
- **SC-005**: With zero companion filter callbacks, the tab list byte-for-byte matches the pre-Feature-019 render on `main@46d214b` — verified by DOM diff.
- **SC-006**: `composer phpcs` reports zero errors on `Registry.php`, `AbstractServerTab.php`, `FilteredServerTab.php`, the ten built-in tab class files, and both test files.
- **SC-007**: `composer phpstan` reports zero errors at level 8, no new baseline entries.
- **SC-008**: Post-patch grep `grep -rn "apply_filters( 'acrossai_mcp_manager_server_tabs'" admin/` returns exactly one match.
- **SC-009**: `composer test` runs the seven new `RegistryTest` cases + the new `FilteredServerTabTest` file green.
- **SC-010**: The extension author doc `docs/extending-per-server-tabs.md` contains a copy-paste-ready worked example that a third-party plugin author can drop into their own plugin without further modification.
