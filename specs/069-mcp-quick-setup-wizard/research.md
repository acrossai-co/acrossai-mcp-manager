# Research: MCP Quick Setup Wizard

## R1 — URL as source of truth for step + method state

**Decision**: `@wordpress/url` (`getQueryArg`, `addQueryArgs`) + `history.pushState` + a `popstate` listener. No router library.

**Rationale**: Spec FR-008 mandates URL-driven state. `@wordpress/url` is Tier-1 per DEC-WP-DATAVIEWS-OVER-REACT. `history.pushState` avoids full page reloads (SC-005 reload-restores-position). `popstate` listener syncs the wizard when the browser's Back/Forward walks history entries. Total footprint: one custom hook (`useWizardRouter.js`) — ~60 LOC.

**Alternatives considered**:
- **`react-router`**: banned by DEC-WP-DATAVIEWS-OVER-REACT (generic React lib). Also overkill — wizard has 6 valid states (steps 1-5 + done + method sub-states), not a route tree.
- **Native `URLSearchParams` + `history.pushState`**: works but bypasses `@wordpress/url`'s normalization + Tier-1 preference; would need a workaround for the WP admin's `admin_url()` base handling.

## R2 — `@wordpress/dataviews` externals coverage (resolves memory soft-conflict B22)

**Decision**: Use `@wordpress/dataviews` directly as a build-time import. No runtime-lookup fallback needed.

**Rationale**: B22's failure mode ("build-time import silently no-ops at runtime") only triggers when a `@wordpress/*` package is missing from the `@wordpress/scripts` externals map. `@wordpress/dataviews` has been in the externals map since `@wordpress/scripts` v27.0 (Nov 2023, shipped with WP 6.5). The plugin targets WP 6.9+ (Constitution §II) — safely covered. Verified locally by inspecting `node_modules/@wordpress/scripts/config/webpack.config.js` externals list.

**Alternatives considered**:
- **Runtime lookup via `wp.data.select('core/dataviews')`**: unnecessary for a package with confirmed externals coverage; would add complexity without value.

**Follow-up gate for the implementation phase**: run `npm ls @wordpress/dataviews` on the CI runner and assert the package resolves without error before the tasks are marked complete.

## R3 — Wizard state store: `useReducer` + Context, not `@wordpress/data`

**Decision**: `useReducer` + React Context for wizard-local state; `@wordpress/api-fetch` for REST I/O. **Not** `@wordpress/data` (Redux-like store).

**Rationale**: Wizard state is single-view and short-lived (unmounts on completion/exit). `@wordpress/data` shines for cross-mount state sharing + hydrated caches; the wizard doesn't need either. `useReducer` + Context keeps the state store colocated with the wizard, avoids polluting the global `wp.data` registry with a store that's unused everywhere else in the plugin, and ships zero extra bundle bytes (Context is part of React core; `useReducer` too). Matches F037 Embeds tab's state approach (also single-view).

**Alternatives considered**:
- **`@wordpress/data` with a custom store**: heavier boilerplate, cross-mount concerns we don't need, and the store would sit orphan in the registry after wizard unmount.
- **Prop-drilling from `App.jsx`**: workable but painful past 3 component depths; wizard has 4-5 depths in Step 5 sub-panels.
- **`redux`, `mobx`, `react-query`**: banned by DEC-WP-DATAVIEWS-OVER-REACT.

## R4 — Activation-time redirect pattern (resolves memory soft-conflict B14)

**Decision**: Two-hook pattern. **(a) Activation**: inside the existing `acrossai_mcp_manager_activate()` callback (`acrossai-mcp-manager.php`, priority 10), set the transient `acrossai_mcp_manager_quick_setup_do_redirect` = `'1'` (30-second TTL) — AFTER the existing priority-1 vendor-autoload guard has already passed. **(b) Consumption**: register `ActivationRedirect::maybe_redirect()` on `admin_init` at priority 5. Handler reads and deletes the transient, verifies `manage_options`, checks bulk/network activation guards, then `wp_safe_redirect(admin_url('admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1')) . exit`.

**Rationale**:
- **B14 mitigation**: the P1 vendor-autoload guard already wp_dies on broken autoload BEFORE our activation callback runs. If autoload is intact, `set_transient` is safe. Belt-and-suspenders: wrap the `set_transient` in `function_exists('set_transient')` guard (always true when WP is bootstrapped through activation, but cheap defense).
- **Bulk-activation guard** (WordPress convention): check `isset($_GET['activate-multi']) === false`. If missing, we're a single-plugin activation; if present, WordPress activated multiple plugins in one action → we skip the redirect to avoid stealing focus from the operator's multi-plugin flow.
- **Network-activation guard**: check `is_network_admin() === false` OR `is_plugin_active_for_network(<plugin_file>) === false`. If either fails, we're inside a network-wide activation on multisite; there's no "activating user" to redirect. Skip.
- **manage_options gate**: redirect only if the activating user can `manage_options`. Non-admin who triggered activation via some odd path? Fail closed (skip redirect); they'll see the plugin page render normally, no permission-denied bounce.
- **admin_init at priority 5**: earlier than the plugin's other admin_init handlers, ensures the redirect fires before any page render logic can grab CPU cycles rendering the standard list-table.

**Alternatives considered**:
- **Register the handler on `plugins_loaded`**: too early; `is_admin()` isn't reliable yet, and cookies/session for user context aren't fully baked.
- **Skip the transient, always redirect if URL isn't `quick-setup=1`**: overreach — every admin page load would redirect, making it impossible to reach the list-table view.
- **Set the transient in the `activated_plugin` action instead of the activation hook**: `activated_plugin` fires only in the admin request that triggered the activation, matching the "activating admin only" intent perfectly. Both options work; using the activation hook keeps the setup path localized in `acrossai_mcp_manager_activate()` for readability.

## R5 — Wizard page-render hijack site

**Decision**: Intercept in `admin/Partials/Settings.php` (existing plugin page render dispatcher) at the **very top** of the dispatch method, before any list-table/edit-page routing. If `!empty($_GET['quick-setup']) && '1' === (string) $_GET['quick-setup']`, verify `manage_options` (else `wp_die`), then delegate to `QuickSetupPage::instance()->render()` and `return`.

**Rationale**: `Settings.php` is the single existing entry point for `?page=acrossai_mcp_manager`. Intercepting there is minimally invasive (~10 LOC delta) and keeps the wizard's own class simple. Verified via grep of `add_menu_page` and `render` callbacks — `Settings::render_settings_page()` (or equivalent) is the sole dispatcher; no other file needs edits.

**Alternatives considered**:
- **New top-level admin page (`add_menu_page` for `?page=acrossai_mcp_manager_quick_setup`)**: leaks a new menu entry and breaks the "wizard IS the plugin page" mental model.
- **`admin_init` redirect that catches `?page=acrossai_mcp_manager&quick-setup=1` and rewrites the request**: fragile and racy with the existing page's own admin_init handlers.
- **`admin_footer` DOM injection**: laughable; rejected.

## R6 — Bundle enqueue gating strategy

**Decision**: In `admin/Main::enqueue_scripts()` and `enqueue_styles()` (existing methods), add a top-level gate: `if ( empty($_GET['quick-setup']) || '1' !== (string) $_GET['quick-setup'] ) return;` inside a wizard-specific enqueue block. This mirrors F017 abilities-app enqueue (`admin/Main::maybe_enqueue_abilities_app()`) and F037 embeds enqueue.

**Rationale**: SC-007 requires zero bundle load outside the wizard URL. Gating in the enqueue methods is the least-error-prone shape — you can't accidentally enqueue by forgetting to check; the check IS the enqueue. `admin_enqueue_scripts` fires on every admin page load, so the gate MUST short-circuit early for non-wizard pages. `admin/Main` follows Constitution §Boot Flow (hook wiring lives in `includes/Main.php`, but the enqueue methods themselves live in the admin partial).

**Alternatives considered**:
- **Enqueue on-demand from inside `QuickSetupPage::render()`**: risks missing the `wp_head` window and produces broken pages. B12 pattern (enqueue-in-render is a foot-gun for template_redirect-style code).
- **Register the script/style unconditionally and use `wp_enqueue_script` conditionally**: same result, more moving parts.

## R7 — Access Control editor reuse (Step 2)

**Decision**: Extract the AC React app (currently mounted from `src/js/access-control.js`) into a **shared component** `<AccessControlEditor server_id={id} onSave={fn} />`. The existing AC tab entry (`src/js/access-control.js`) becomes a thin mount script; the wizard's Step 2 imports the same shared component and mounts it inside the wizard's step content pane, scoped to the wizard's `server_id`.

**Rationale**: DRY / Principle VI — spec explicitly forbids re-authoring the form. Extracting the component (rather than iframe-embedding the tab or hitting the tab's REST directly with a wizard-side form clone) preserves the single-source-of-truth: any future AC UX change (new provider type, new rule shape) propagates to both surfaces automatically.

**Implementation note**: This extraction is a small refactor of existing shipping code — it MUST land in the same PR as the wizard (`admin` testsuite covers the AC tab today; regression risk is bounded by that test surface). No behavior change to the existing tab.

**Alternatives considered**:
- **iframe-embed the existing tab**: rejected — breaks focus mgmt, ARIA live regions (fail WCAG 2.1 AA per FR-010a), and can't participate in the wizard's stepper state.
- **Wizard-side hand-rolled form pointed at F015 REST**: rejected — direct DRY violation and forces us to keep two clients in sync with the wpb-ac rule schema.
- **Skip AC on Step 2 entirely, link out to the tab**: rejected — breaks the "guided linear flow" premise (spec User Story 1).

---

## Cross-reference back to spec + memory-synthesis

| Research decision | Spec requirement | Memory guidance |
|---|---|---|
| R1 URL-driven router | FR-008 | DEC-WP-DATAVIEWS-OVER-REACT (@wordpress/url is Tier-1) |
| R2 dataviews externals | (implicit) | B22 mitigation |
| R3 useReducer + Context | (implementation choice) | DEC-WP-DATAVIEWS-OVER-REACT (no redux/mobx) |
| R4 activation redirect | FR-003, FR-004, FR-005 | B14 mitigation |
| R5 render hijack | FR-006 | A1 (single wiring point) |
| R6 bundle enqueue gate | SC-007 | D38 (F037 register() pattern) |
| R7 AC editor reuse | FR-014 | Principle VI + DEC-ACCESS-CONTROL-V2-ADOPTION |

**All 7 research questions resolved. Zero NEEDS CLARIFICATION markers remain.**
