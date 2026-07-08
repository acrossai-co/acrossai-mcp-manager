# Feature Specification: Per-server Ability Selection

**Feature Branch**: `017-per-server-ability-selection`
**Created**: 2026-07-07
**Status**: Draft
**Input**: User description (see `docs/planings-tasks/017-per-server-ability-selection.md` for the full planning shape and reference material)

## Clarifications

### Session 2026-07-07

- Q: Should exposure toggles emit an audit trail, and if so, where? → A: Fire an action `acrossai_mcp_ability_exposure_changed` on every effective change, no built-in storage — operators + third-party plugins can subscribe to log.
- Q: How should orphan rows (per-server rows whose `ability_slug` is no longer registered on the site) behave? → A: Preserve orphan rows silently — READ response lists only currently-registered abilities; orphan rows lie dormant and reactivate if the ability is re-registered. No cleanup UI, no admin notice.
- Q: How should third-party plugins (e.g., `acrossai-abilities-manager`) add their own columns and per-row actions to the Abilities table? → A: Expose `@wordpress/hooks` filter points (`acrossaiMcpManager.abilities.fields`, `acrossaiMcpManager.abilities.actions`, `acrossaiMcpManager.abilities.row`) plus a symmetric server-side `apply_filters( 'acrossai_mcp_ability_row', ... )` so extensions can inject columns / actions on the client AND add matching row data on the server. No custom API — WP's canonical hooks system. Example: an abilities-manager plugin adds an "Action" column containing an "Edit" button that opens its own modal.
- Q: How should F017 close the SEC-001 enforcement gap (stored overrides that don't reach the MCP tool boundary)? → A: Add call-time enforcement via a second `mcp_adapter_pre_tool_call` callback that consults `ExposureResolver::resolve()` and returns `WP_Error( ..., array( 'status' => 403 ) )` when exposure is false. Priority runs later than F015's callback so a "hidden" decision supersedes any AccessControl "allow." List-time hiding (removing hidden abilities from `mcp/tools/list`) is deferred to a follow-up feature once the vendor `mcp-adapter`'s per-server ability-collection hook is confirmed — hidden abilities may still appear in listings, but calls to them are rejected 403.

### Session 2026-07-08 — Post-implement scope adjustments

The following changes were made during implementation and are ratified here so the spec matches the shipped code:

- Q: The client-side implementation moved to sourcing the ability list from `@wordpress/abilities` (via `wp.data.select('core/abilities')`), and the REST GET response was slimmed to `{ overrides: [{ slug, is_exposed }] }` with an optional `?include_abilities=1` fallback envelope. Confirm this new shape as the shipped contract? → **A: Yes** — see FR-009, FR-014, FR-035 for the new contract.
- Q: The `acrossai_mcp_ability_row` PHP filter was removed during the same refactor (server-side row merge no longer runs). Companion plugins extend via JS filters only. Confirm? → **A: Yes** — FR-027 retired; extensibility is JS-only via `acrossaiMcpManager.abilities.{fields,actions,row}`.
- Q: The following features were added that were not in the initial spec: `Enable All` / `Disable All` buttons (whole-list writes), an exposure filter dropdown, a custom pagination footer with cross-page selection persistence, and a hard-coded `EXCLUDED_SLUGS` set that hides three `mcp-adapter/*` protocol-plumbing tools from the operator UI. Confirm all four as shipping requirements? → **A: Yes** — captured as FR-031..034.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Choose which abilities a specific MCP server exposes (Priority: P1)

A site administrator opens the Edit MCP Server screen for a given server, switches to the **Abilities** tab, and sees an interactive table of every ability registered on the site via the WordPress Abilities API. They can search by name / label / description, filter by category or type, sort any column, toggle exposure on any single ability, or select many at once and use "Expose selected" / "Hide selected" bulk actions. A live "N of M exposed" counter reflects the current state. All changes persist per server and are immediately visible to connected AI clients.

**Why this priority**: This is the entire user-facing point of the feature. Without it, admins cannot make per-server exposure decisions — the current tab is read-only. Every other story is a supporting capability.

**Independent Test**: Enable at least one MCP server, install any plugin that registers an ability via the Abilities API, visit `admin.php?page=acrossai_mcp_manager&action=edit&server=<id>&tab=abilities`, toggle a row's exposure switch, reload the page, and confirm the new state persists. A connected AI client that attempts to CALL a toggled-off ability MUST receive a `403` MCP error at the tool-call boundary (call-time enforcement — FR-030). The ability may still appear in the client's `mcp/tools/list` response until list-time hiding lands in a follow-up feature; this is an accepted UX gap for F017.

**Acceptance Scenarios**:

1. **Given** an enabled MCP server with the Abilities API present and no prior overrides, **When** the admin loads the Abilities tab, **Then** every registered ability is listed with its current default exposure (derived from the ability's own `meta[mcp][public]`) and a `has_override` flag of `false`.
2. **Given** the Abilities tab is loaded, **When** the admin toggles a single row's exposure switch, **Then** a per-server override is persisted, the row's `has_override` becomes `true`, the counter updates, and reloading the page reflects the change.
3. **Given** the Abilities tab is loaded, **When** the admin selects two or more rows (using the row checkboxes or the footer's "Select all" — selection persists across pagination pages), and invokes "Expose selected" or "Hide selected" from the custom bulk-actions bar, **Then** all selected abilities are marked exposed / hidden on this server in one request, and the refreshed list is displayed without a second round-trip. Additionally, the "Enable All" and "Disable All" buttons (see FR-031) MUST fire whole-list writes after operator confirmation regardless of selection or filter state.
4. **Given** the Abilities tab is loaded, **When** the admin types a term into the search box, **Then** rows are filtered live against the ability slug, label, and description.
5. **Given** the Abilities tab is loaded, **When** the admin picks a category or type from the filter dropdowns, **Then** the visible rows are restricted to that category/type.
6. **Given** the Abilities tab is loaded, **When** the admin clicks a sortable column header, **Then** the rows re-sort ascending on first click and descending on the second click.

---

### User Story 2 — Fall back to the ability's global default when no per-server override exists (Priority: P1)

For any (server, ability) pair with no explicit row in the new per-server storage, the effective exposure follows the ability's own `meta[mcp][public]` flag. This means existing installs upgrade to this feature with **zero visible behavior change** — every ability that was exposed before is still exposed after, and every hidden ability stays hidden.

**Why this priority**: Without this fallback, activating the feature would silently hide every ability across every server (empty table = nothing exposed), which would break every connected AI client on upgrade. This is a P1 correctness requirement, not a nice-to-have.

**Independent Test**: On a site with the Abilities API and at least one public ability, upgrade to the feature version without touching the Abilities tab, then confirm the MCP endpoint still exposes every ability whose `meta[mcp][public]` is truthy — identical to the pre-upgrade behavior.

**Acceptance Scenarios**:

1. **Given** a server with no rows in the per-server table, **When** the effective exposure is computed for any ability, **Then** it equals the ability's own `meta[mcp][public]` value.
2. **Given** the admin sets a row's exposure to explicitly `true` for an ability whose `meta[mcp][public]` is `false`, **When** the effective exposure is queried, **Then** it is `true` (override wins over default).
3. **Given** the admin sets a row's exposure to explicitly `false` for an ability whose `meta[mcp][public]` is `true`, **When** the effective exposure is queried, **Then** it is `false` (override wins over default).

---

### User Story 3 — REST endpoints power the UI and are safely gated (Priority: P1)

The interactive tab is driven by two REST endpoints scoped to the plugin's existing namespace. Read returns the merged view of every registered ability with its effective exposure; write accepts a batch of `{ slug, is_exposed }` pairs and returns the refreshed merged view. Both endpoints require administrator capability.

**Why this priority**: Without the REST layer, the tab has no data flow. Correct authorization + input validation is non-negotiable — anything less would let a low-privilege user rewrite server exposure.

**Independent Test**: Log in as an administrator, `curl` the GET endpoint with a valid `X-WP-Nonce` and confirm a 200 with the merged shape; log out and repeat — the same request must return 403.

**Acceptance Scenarios**:

1. **Given** an authenticated administrator, **When** GET is called for an existing server, **Then** the response is 200 with `{ has_abilities_api: true, abilities: [ ... ] }`.
2. **Given** an authenticated administrator, **When** GET is called for a non-existent server id, **Then** the response is 404 with a distinct error code.
3. **Given** an unauthenticated caller or a caller without `manage_options`, **When** either endpoint is called, **Then** the response is 403.
4. **Given** an authenticated administrator, **When** POST is called with a batch containing a slug that is not currently registered, **Then** the response is 400 and NO rows are written.
5. **Given** an authenticated administrator, **When** POST is called with a valid batch, **Then** rows are upserted per pair and the response body is the SAME merged shape as GET so the client can re-render without a follow-up request.
6. **Given** the site does not have the Abilities API installed, **When** GET is called for an existing server, **Then** the response is 200 with `{ has_abilities_api: false, abilities: [] }`.

---

### User Story 4 — Bundle stays lean by using WordPress-provided packages only (Priority: P2)

The interactive tab uses only WordPress-provided JavaScript packages for its UI (DataViews, Components, Element, ApiFetch, I18n). No generic React libraries (react-query, redux, mobx, react-table, MUI, styled-components) are introduced.

**Why this priority**: The plugin already ships JS via `@wordpress/scripts` which externalizes WP packages. Adding a generic React grid or state library would bloat every admin page and diverge from core admin UI. Enforcing "WP packages only" is a durable architectural constraint, not just a build-size preference.

**Independent Test**: Run `npm run build`, inspect the emitted bundle for the abilities tab, and confirm no bytes from any of the forbidden libraries are present.

**Acceptance Scenarios**:

1. **Given** the abilities tab source, **When** the source is grepped for `react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components`, **Then** there are zero matches.
2. **Given** the emitted bundle, **When** its size is compared with the current access-control bundle, **Then** it is of comparable order-of-magnitude (no runaway growth from unbundled dependencies).

---

### User Story 5 — Feature is scoped to the Abilities tab only (Priority: P2)

The interactive UI ships to the browser only when the admin is on `?page=acrossai_mcp_manager&action=edit&server=<id>&tab=abilities`. No other admin screen (including the sibling Access Control tab, Overview, or the plugin list page) enqueues the new script.

**Why this priority**: Loading admin JS on unrelated pages is a common source of "why is my JS console noisy" and "why does Site Health say my admin bundle is huge" complaints. Explicit scoping matches the existing F015 access-control-tab precedent.

**Independent Test**: With WP Debug Bar or the browser Network tab, navigate to Overview, Access Control, and the plugin list. Confirm the abilities bundle URL is NOT requested on any of those. Then navigate to the Abilities tab and confirm it IS requested exactly once.

**Acceptance Scenarios**:

1. **Given** the admin is on any tab other than Abilities, **When** the page is loaded, **Then** the abilities script tag is absent and `window.acrossaiMcpAbilities` is `undefined`.
2. **Given** the admin is on the Abilities tab, **When** the page is loaded, **Then** the abilities script tag is present exactly once and `window.acrossaiMcpAbilities` is populated with `serverId`, `serverSlug`, `restApiRoot`, `nonce`, and `namespace`.

---

### User Story 6 — Third-party plugin adds columns and per-row actions (Priority: P2)

A companion plugin (e.g. `acrossai-abilities-manager`) enqueues its own admin JavaScript on the Abilities tab, registers a `@wordpress/hooks` filter, and appends a new "Action" column whose cell renders an "Edit" button. Clicking the button opens the sibling plugin's own modal to edit that ability's registration metadata. The core Abilities tab UI code does NOT change — only the filter registrations differ. The sibling plugin may also add server-side data to each row via a PHP filter so its column can read from real data, not a client-side lookup.

**Why this priority**: This is what turns the Abilities tab from a single-plugin feature into a shared surface. Without it, every future sibling plugin either has to fork the tab or negotiate an ad-hoc addition — both of which produce drift.

**Independent Test**: Author a tiny helper plugin that:
1. Enqueues a JS file with a declared dependency on `acrossai-mcp-manager-abilities`.
2. Calls `wp.hooks.addFilter( 'acrossaiMcpManager.abilities.fields', 'my-plugin/actions', ( fields ) => [ ...fields, { id: 'my_action', label: 'Action', enableSorting: false, render: () => <button>Edit</button> } ] );`.
3. Optionally hooks `acrossaiMcpManager.abilities.row` to decorate rows with data its column's `render` reads.
4. Verify the new column appears with its button, and clicking Edit fires the plugin's handler. Verify the core POST/GET endpoints still return the `{ overrides: [...] }` shape unchanged — the extension surface is JS-only per FR-027 retirement.

**Acceptance Scenarios**:

1. **Given** a sibling plugin has hooked `acrossaiMcpManager.abilities.fields`, **When** the Abilities tab loads, **Then** the extra column appears at the position returned by the filter and its `render` callback is invoked once per row.
2. **Given** a sibling plugin has hooked `acrossaiMcpManager.abilities.actions`, **When** the admin selects rows, **Then** the extension's bulk action is available alongside the built-in Expose / Hide.
3. **Given** a sibling plugin has hooked `acrossaiMcpManager.abilities.row` (JS), **When** the DataViews table renders, **Then** each item in the visible row set includes the extra keys the filter added, and the sibling's column `render` callback can read them.
4. **Given** the built-in Exposed toggle column, **When** a sibling plugin's `fields` filter runs, **Then** the plugin may NOT redefine or remove the built-in `is_exposed`, `slug`, `label`, `type`, `category`, or `description` fields — the filter merges additive-only.
5. **Given** the sibling plugin sends bad data through its filter (e.g., a `render` that throws), **When** the tab loads, **Then** the core UI still renders the built-in columns and the extension's column falls back to an empty cell — no white-screen.

---

### Edge Cases

- What happens when the Abilities API is not installed? Both the tab and the REST endpoint surface a graceful "not available" state — no fatal error, no empty table without explanation.
- What happens when the server row does not exist? REST returns 404; the tab is unreachable (list page never links there).
- What happens when the admin toggles an ability and another admin has already toggled the same one? Last-write-wins on the per-(server, ability) row. Not a lost-update risk because the payload is idempotent per ability slug.
- What happens when a plugin unregisters an ability between the client's GET and POST? The POST-side validation rejects the batch with 400 rather than writing an orphan row that would grow the table unbounded over time.
- What happens to rows whose `ability_slug` was valid at write-time but is no longer registered (plugin deactivated, ability renamed upstream)? The rows are preserved silently — the READ endpoint filters them out of its response, no admin notice is emitted, and the row silently reactivates if the ability is re-registered under the same slug (FR-025).
- What happens when a sibling plugin's client-side filter throws? Each filter registration runs inside a try/catch and its failure is swallowed with a `console.error` — the core columns still render and the sibling's column falls back to an empty cell (FR-029). No white-screen; no site-breakage.
- What happens when an AI client invokes a hidden ability? F017's `mcp_adapter_pre_tool_call` callback (FR-030) short-circuits the invocation with `WP_Error( 'acrossai_mcp_ability_not_exposed', ..., array( 'status' => 403 ) )`. The MCP adapter surfaces this as a standard MCP error to the client. The ability may still appear in listings until list-time hiding lands in a follow-up feature.
- What happens when both F015 AccessControl AND F017 exposure verdicts apply to the same tool call? F017's callback runs LATER (priority 20 vs F015's 10). If F015 denied, F017 receives an already-`WP_Error` `$result` — the callback returns the error unchanged (never overrides a deny with an allow). If F015 allowed and F017 says `false`, F017's `WP_Error` supersedes. Both allow → tool call proceeds.
- What happens when the physical table is dropped but the version option remains? The Table's `maybe_upgrade()` guard deletes the stale option before the next install runs, restoring the table silently — no admin notice, no debug log noise.
- What happens when the site is a multisite subsite? Each site has its own per-site table (BerlinDB `$global = false`), so per-server overrides are per-site by construction.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Site administrators MUST be able to view every registered WordPress Ability alongside its current effective exposure for a specific MCP server, on the per-server Abilities tab.
- **FR-002**: Site administrators MUST be able to toggle exposure per ability, per server — the choice MUST persist across page reloads and requests.
- **FR-003**: Site administrators MUST be able to bulk-set exposure (Expose or Hide) for a selection of two or more abilities in a single request.
- **FR-004**: Site administrators MUST be able to search abilities by slug, label, and description, and filter by category, type, **or effective exposure state** (via a three-option dropdown: `All exposure` / `Only exposed` / `Only hidden`).
- **FR-005**: Site administrators MUST be able to sort by slug, label, type, category, and description.
- **FR-006**: The Abilities tab MUST display a live "N of M exposed" count that reflects the current in-memory state (with plural-safe wording).
- **FR-007**: When no per-(server, ability) row exists, effective exposure MUST fall back to the ability's own `meta[mcp][public]` flag — existing installs upgrade with zero visible behavior change.
- **FR-008**: The effective-exposure resolution logic MUST be centralized in one place so every consumer (REST controller, PHP fallback callers, future integrations) obtains the same answer for the same inputs.
- **FR-009**: The read REST endpoint MUST return the per-server override rows as `{ overrides: [ { slug: string, is_exposed: bool } ] }`. It MUST NOT re-serialize the ability metadata by default. When the client sends the `?include_abilities=1` query parameter (fallback path — see FR-035), the response additionally includes an `abilities` array containing the full ability list sourced from PHP `wp_get_abilities()`.
- **FR-010**: The write REST endpoint MUST accept a batch of `{ slug, is_exposed }` pairs, upsert per pair (a `false` value MAY be persisted as an explicit `is_exposed=0` row for future reversibility), and respond with the refreshed `{ overrides: [...] }` shape — never require a follow-up GET.
- **FR-011**: The write REST endpoint MUST reject any batch containing a slug that is not currently registered on the site, returning 400 with a distinct error code and writing zero rows.
- **FR-012**: Both REST endpoints MUST require `manage_options` capability. No `__return_true` permission callback is permitted.
- **FR-013**: Both REST endpoints MUST return 404 for a non-existent `server_id`.
- **FR-014**: When the WordPress Abilities API is not installed AND the client requested `?include_abilities=1` (fallback path), the read endpoint MUST return `{ overrides: [], abilities: [] }` — never a fatal error. Without `include_abilities=1`, the endpoint returns `{ overrides: [] }` regardless of Abilities API availability — the client discovers API absence via the client-side `@wordpress/abilities` store lookup.
- **FR-015**: The Abilities tab MUST retain its existing graceful degradation branches: a "server is disabled" notice and an "Abilities API not available" notice. Neither branch may attempt to load the interactive UI.
- **FR-016**: The interactive UI MUST use only WordPress-provided JavaScript packages. The full runtime dependency set is: `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/hooks`, `@wordpress/data`, `@wordpress/abilities`. No generic React libraries (`react-query`, `@tanstack/*`, `redux`, `mobx`, `react-table`, `@mui/*`, `styled-components`) may be imported or bundled.
- **FR-017**: The interactive UI's JavaScript MUST be enqueued only on the per-server-edit page with `tab=abilities`. It MUST NOT load on any other admin screen.
- **FR-018**: The per-(server, ability) storage MUST self-heal when the physical table is dropped without the version option — the next install run MUST recreate the table without emitting any log line, admin notice, or transient.
- **FR-019**: Feature activation MUST NOT insert default rows into the per-(server, ability) table — the empty state IS the correct backwards-compatible initial state.
- **FR-020**: On plugin uninstall, the per-(server, ability) table MUST be dropped alongside the existing plugin tables.
- **FR-021**: All hook wiring for the new REST controller MUST happen in `includes/Main.php` via the loader — never inside a class constructor.
- **FR-022**: All user input MUST be sanitized at the REST boundary using the most-specific WordPress function available (`absint()`, `sanitize_text_field()`).
- **FR-023**: All output rendered by the (unchanged) tab body PHP MUST be escaped at the point of rendering with the most-specific `esc_*` function.
- **FR-024**: For every write that results in an **effective change** to a per-(server, ability) exposure value, the plugin MUST fire an action `acrossai_mcp_ability_exposure_changed` with the arguments `( int $server_id, string $ability_slug, bool $was, bool $now, int $user_id )`. Writes that leave the effective value unchanged MUST NOT fire the action. Feature 017 does not introduce any built-in persistence for these events — operators and third-party plugins may subscribe to record them.
- **FR-025**: When a per-(server, ability) row exists whose `ability_slug` is not currently returned by `wp_get_abilities()` (an "orphan row"), the row MUST be preserved as-is in storage. The READ endpoint MUST NOT include orphan rows in its `abilities` array. No admin notice, log line, or cleanup UI is emitted. If the ability is later re-registered under the same slug, the row silently becomes effective again on the next resolver call.
- **FR-026**: The Abilities React app MUST expose three `@wordpress/hooks` filter points, invoked once per render pass with the current server context `{ serverId, serverSlug }` as the trailing argument:
  - `acrossaiMcpManager.abilities.fields` — receives the array of `fields` passed to `<DataViews>`, returns a (possibly extended) array.
  - `acrossaiMcpManager.abilities.actions` — receives the array of `actions`, returns a (possibly extended) array.
  - `acrossaiMcpManager.abilities.row` — receives one row object, returns the row (extended with extra keys the extension's column reads).
- **FR-027**: **RETIRED (Session 2026-07-08).** The originally-planned PHP filter `acrossai_mcp_ability_row` was removed during the `@wordpress/abilities` client-store refactor — the server no longer merges ability metadata into rows, so there is no server-side per-row payload for a PHP filter to decorate. Companion plugins extend the tab via the three JS filters listed in FR-026 (client-side row decoration is done via `acrossaiMcpManager.abilities.row`). See `docs/abilities-tab-js-filters.md` for the JS-only extensibility guide.
- **FR-028**: The client-side extensibility surface MUST use the WordPress-standard `@wordpress/hooks` package. No custom `window.acrossaiMcpAbilities.register(...)` API is introduced. The abilities script MUST declare `wp-hooks` in its asset manifest so consumers can safely register filters before or after this bundle loads.
- **FR-029**: Every third-party filter callback (both JS and PHP) MUST run inside a defensive boundary — a thrown JS filter callback surfaces a `console.error` and the row falls back to the last-known-good field list; a PHP filter that returns a non-array is discarded and a `_doing_it_wrong()` notice is emitted. Sibling plugin failure MUST NOT white-screen the tab or break the READ REST endpoint.
- **FR-030**: The plugin MUST register a callback on the vendor's `mcp_adapter_pre_tool_call` filter that consults `ExposureResolver::resolve()` for the (server, ability slug) pair. When exposure is `false`, the callback MUST return `WP_Error( 'acrossai_mcp_ability_not_exposed', ..., array( 'status' => 403 ) )` to short-circuit the tool call. The callback MUST be wired at a hook priority LATER than F015's `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` so a hidden-on-this-server decision supersedes any AccessControl "allow" verdict. Wire in `includes/Main.php::define_admin_hooks()` (or `define_public_hooks()` — pick the same wiring surface F015 uses) per A1. Existing installs continue to expose every ability whose `meta[mcp][public]` is truthy because the resolver's default fallback preserves prior behavior — the enforcement only bites when the admin has explicitly toggled a row to `is_exposed=0`.
- **FR-031**: The interactive UI MUST provide **Enable All** and **Disable All** buttons that write the target exposure value for every registered ability on the current server in one round-trip. Both buttons MUST prompt operator confirmation (via `window.confirm` or an equivalent WordPress-standard confirmation surface) because they are wide-effect writes. Enable All / Disable All MUST operate on the full ability list regardless of any active filter / search state — filters affect only the visible rows, not the whole-list actions.
- **FR-032**: The interactive UI MUST paginate the ability list (default 50 rows per page) and expose the following footer controls: (a) a "Select all" checkbox that toggles selection for the current page and merges the toggle with any existing cross-page selection, (b) Previous / Next page buttons with visible `Page X of Y` counter, (c) an "N of M Items" count reflecting the filtered row count vs. the paginated row count. Cross-page selection MUST persist when the operator navigates between pages so a bulk action fired after selecting across pages writes every selected row.
- **FR-033**: The interactive UI MUST provide an exposure filter dropdown with three options (`All exposure` / `Only exposed` / `Only hidden`) — see FR-004. The filter MUST run against the client-side merged row set (before pagination) and MUST NOT change the header counter ("N of M exposed on this server"), which always reflects the full registered ability set.
- **FR-034**: The interactive UI MUST exclude the following ability slugs from the operator-facing table because they are the MCP adapter's protocol-plumbing tools and are always exposed to any connected MCP client regardless of any toggle state: `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`. The exclusion applies only to the client display — the enforcement gate (FR-030) still evaluates these slugs the same way as any other ability. Third-party plugins that need to hide additional slugs can do so via the `acrossaiMcpManager.abilities.row` filter (return `null` for the row).
- **FR-035**: The client SHOULD prefer the `@wordpress/abilities` data store (`wp.data.select('core/abilities')`) for the ability list — this avoids re-serializing metadata through this plugin's REST endpoint. When the store is not registered at runtime (`select('core/abilities')` returns undefined or its `getAbilities` method is missing), the client MUST fall back to the plugin's REST endpoint by appending `?include_abilities=1` to the GET request. The server MUST honor this fallback query parameter per FR-009. The client-side detection is required so companion installs that ship the Abilities API only in PHP still work.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (constitution target — matches `.specify/memory/constitution.md` §II).
**WordPress Version**: 6.9+
**Multisite**: Supported — per-site table (BerlinDB `$global = false`) matches every other plugin table.
**Required Plugins / Packages**: `berlindb/core: ^3.0.0` (already installed via Feature 010).
**Optional Integrations**: The WordPress Abilities API (via `wp-abilities-api` package or the sibling `acrossai-abilities-manager` plugin) — must degrade gracefully if absent (FR-014, FR-015).

### Module Placement

**PHP Class(es)**:
- `includes/Database/MCPServerAbility/Schema.php` → namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility` — BerlinDB Schema subclass (context-neutral, DB-only).
- `includes/Database/MCPServerAbility/Table.php` → same namespace — BerlinDB Table subclass owning install lifecycle.
- `includes/Database/MCPServerAbility/Query.php` → same namespace — BerlinDB Query subclass with an `upsert()` helper.
- `includes/Database/MCPServerAbility/Row.php` → same namespace — BerlinDB Row subclass.
- `includes/Database/MCPServerAbility/ExposureResolver.php` → same namespace — stateless pure service (`resolve( int $server_id, string $ability_slug, array $meta ): bool`).
- `includes/REST/AbilitiesController.php` → namespace `AcrossAI_MCP_Manager\Includes\REST` — singleton REST controller for the two routes.

**Existing files modified** (deltas only):
- `admin/Partials/ServerTabs/AbilitiesTab.php` — replace `render_body()` with a mount div + preserved guards; delete the three private helpers.
- `admin/Main.php` — add `maybe_enqueue_abilities_app()` and call it from `enqueue_scripts()`.
- `includes/Activator.php` — one line to install the new table.
- `includes/Main.php` — one line in `bootstrap_database_tables()` + REST controller wiring in `define_admin_hooks()`.
- `uninstall.php` — add the new table to the DROP list.
- `webpack.config.js` — add one entry mapping.
- `package.json` — declare `@wordpress/*` UI packages under dependencies (if not already present).

**Hook Registration**: All `add_action`/`add_filter` calls for this feature MUST be wired in `includes/Main.php` via `define_admin_hooks()`. New REST routes register on `rest_api_init`; new script enqueue rides the existing `admin_enqueue_scripts` chain. The FR-030 call-time enforcement filter wires on `mcp_adapter_pre_tool_call` at priority 20 (F015's `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` runs at priority 10 — F017's hidden-on-this-server verdict runs later so it supersedes any AccessControl allow).

### Admin UI Requirements

**Existing screen — Edit MCP Server / Abilities tab** (per-server-tabs refactor F013):
- The tab body (currently PHP-rendered) is replaced with a `<div id="acrossai-mcp-abilities-root">` mount point plus the existing graceful-degradation notices.
- The interactive UI MUST use `DataViews` (from `@wordpress/dataviews`) for the table, filters, search, and bulk actions — no custom table HTML.
- The interactive UI MUST use `@wordpress/components` primitives (`ToggleControl`, `Notice`, `Spinner`) for the per-row toggle, error state, and loading state — no custom form HTML.
- The interactive UI MUST call `@wordpress/hooks` `applyFilters()` at three named extension points before rendering — see FR-026 for the filter names and payload shape.

**Pre-approved WP_List_Table exception** (MCP Manager parent menu only): unchanged by this feature; the `?page=acrossai_mcp_manager` list screen keeps its `WP_List_Table`.

### REST API Contract

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities` | `manage_options` | Return the per-server override rows as `{ overrides: [ { slug, is_exposed } ] }`. With `?include_abilities=1` (fallback path — FR-035), additionally includes an `abilities` array containing the full ability list from PHP `wp_get_abilities()`. |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/(?P<server_id>\d+)/abilities` | `manage_options` | Upsert a batch of `{ slug, is_exposed }` pairs for the target server. Returns the refreshed `{ overrides: [...] }` shape. Rejects unregistered slugs with 400. |

**`permission_callback` rule**: Both routes gate on `current_user_can( 'manage_options' )`. `__return_true` is forbidden — neither route is public.

### Database / Storage

**Custom DB table** (BerlinDB module, per Feature 011 precedent):

- Table: `{wpdb->prefix}acrossai_mcp_server_abilities`
- Columns:
  - `id` bigint(20) unsigned auto_increment (PK)
  - `server_id` bigint(20) unsigned (indexed)
  - `ability_slug` varchar(191) — 191 chars to fit the `UNIQUE(server_id, ability_slug)` composite key under InnoDB utf8mb4 767-byte key limit
  - `is_exposed` tinyint(1) default 0
  - `created_at` datetime (BerlinDB `created` flag)
  - `updated_at` datetime (BerlinDB `date_updated` flag)
- Indexes: PRIMARY on `id`; UNIQUE `server_ability` on `(server_id, ability_slug)`; KEY on `server_id`.
- Version option: `acrossai_mcp_server_abilities_db_version`, current version `1.0.0`.
- Install: activation hook + request-time boot in `includes/Main.php::bootstrap_database_tables()`.
- Justification: WordPress options / usermeta cannot model a two-dimensional (server × ability) relationship at the query volume expected here (potentially hundreds of pairs, filtered per server). BerlinDB matches the plugin's existing DB modules.

### Security Checklist

- [x] All REST routes have explicit `permission_callback` — `manage_options` gate on BOTH read and write. No `__return_true`.
- [x] All user input sanitized at the REST boundary: `server_id` via `absint()`, `ability_slug` via `sanitize_text_field()`, `is_exposed` cast to `(bool)`.
- [x] All output escaped at point of rendering with most-specific function — the (unchanged) tab body uses `esc_html__()`, `esc_attr()`; REST responses are `wp_json_encode()`-serialized by core.
- [x] All DB queries via BerlinDB's prepared-statement layer — no raw interpolated queries in the new module.
- [x] Batch write rejects unregistered slugs (400) to prevent unbounded row growth from a plugin unregistering an ability.
- [x] Nonce verification: the REST layer's default `X-WP-Nonce` middleware handles this; the JS registers `apiFetch.createNonceMiddleware` once at boot.
- [x] All hooks wired in `includes/Main.php` — none in class constructors (A1).
- [n/a] OAuth tokens / Application Passwords — feature does not handle credentials.
- [n/a] File uploads — feature does not accept uploads.

### Key Entities

- **ServerAbilityOverride**: A per-(server, ability) exposure decision. Attributes: which MCP server it applies to, which ability slug it targets, whether the ability is exposed on that server, and when it was created / last updated. Existence of the row indicates an explicit admin choice; absence means "inherit from the ability's own default."

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] ESLint: zero errors on `src/js/abilities.js` (`npm run lint:js`)
- [ ] PHPUnit tests written and passing for the resolver, the Query helper, and the REST controller
- [ ] Security checklist above: all applicable items verified
- [ ] All hooks wired in `Main.php` — none in class constructors
- [ ] All new admin UI uses DataViews / DataForm equivalents from `@wordpress/dataviews` — no custom table HTML
- [ ] No code duplication — the effective-exposure resolution lives in exactly one place
- [ ] All functions, hooks, and classes prefixed with `acrossai_mcp_` (or namespaced under `AcrossAI_MCP_Manager\`)
- [ ] `npm run validate-packages` passes

### Measurable Outcomes

- **SC-001**: A site administrator can flip a single ability's exposure on a specific MCP server in **at most three interactions** from the Edit MCP Server screen: click the Abilities tab → click the toggle → observe the counter update.
- **SC-002**: On a fresh install with no admin toggles, effective ability exposure matches the ability's own `meta[mcp][public]` for **100% of registered abilities** — no ability disappears or appears without an explicit admin action.
- **SC-003**: Both REST endpoints reject **100% of unauthenticated or under-privileged requests** with a 403 status.
- **SC-004**: On a WP-Env default LEMP stack (`@wordpress/env` defaults, PHP 8.1, MySQL 8, no object cache) with 100 registered abilities and an empty overrides table, the READ endpoint's p95 latency over 10 sequential GETs (with `?include_abilities=1` — the worst case) is **under 1 second**. Verified via a lightweight benchmark task recording measurements in `specs/017-per-server-ability-selection/perf.txt` before merge.
- **SC-005**: Bulk-exposing 20 selected abilities completes in **one request** and refreshes the client view without any additional round-trip.
- **SC-006**: Dropping the physical table without deleting the version option and reactivating the plugin restores the table with **zero admin notices** and **zero log lines** — the self-heal is silent.
- **SC-007**: The abilities-tab JavaScript bundle is enqueued on the Abilities tab **only**. Every other admin page in the plugin shows **zero** references to the bundle URL.
- **SC-008**: A grep for `react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components` under `src/js/` returns **zero matches**.
- **SC-009**: An operator upgrading from a pre-017 install sees **zero visible change** to which abilities each MCP server exposes until they explicitly toggle one.
- **SC-010**: A companion plugin can add a new column with a per-row "Edit" button to the Abilities tab using **only** `wp.hooks.addFilter` + `add_filter` — **zero** changes to this plugin's PHP or JS source. Adding the column requires **no more than three code touch points** in the companion plugin (JS filter registration, PHP row filter, optional REST route the button targets).
- **SC-011**: If a companion plugin's JS filter callback throws, the Abilities tab still renders the built-in columns and the extension's column falls back to an empty cell in **100%** of test cases — no white-screen.
- **SC-012**: A connected AI client that invokes a tool for a `(server, ability)` pair whose stored `is_exposed=0` MUST receive a `403` response at the MCP tool-call boundary in **100%** of attempts. Pre-toggle behavior (no row present) MUST return **zero 403s** on abilities whose `meta[mcp][public]` is truthy — i.e., the enforcement only activates for explicitly-hidden pairs.
- **SC-013**: The three `mcp-adapter/*` protocol-plumbing slugs (`discover-abilities`, `get-ability-info`, `execute-ability`) MUST NOT appear in the operator-facing DataViews table in **100%** of renders, even with all filters cleared. A `grep -n EXCLUDED_SLUGS src/js/abilities.js` MUST show the three slugs verbatim.
- **SC-014**: When `@wordpress/abilities` client store is unavailable at runtime (`select('core/abilities')` returns undefined), the client MUST successfully fall back to `GET /servers/{id}/abilities?include_abilities=1` in **100%** of test runs and render the full ability list from the server-provided `abilities` array — no visible degradation for the operator.

---

## Assumptions

- The WordPress Abilities API (`wp_get_abilities()`, `\WP_Ability`) is available at runtime when the tab is used. When absent, the tab and REST endpoint surface the "not available" state — this is an expected degradation, not an error.
- `berlindb/core: ^3.0.0` is installed and autoloaded (Feature 010 contract); the phantom-version-guard pattern from Feature 011 is available and the memory decision `DEC-BERLINDB-TABLE-REQUEST-BOOT` still applies.
- The plugin's existing REST namespace `acrossai-mcp-manager/v1` is reserved for this plugin's routes; adding two new routes under it does not conflict with other plugins.
- `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, and `@wordpress/i18n` are either already declared as dependencies or will be added by TASK-8; `@wordpress/scripts` externalizes them at build time so the runtime bundle stays small.
- Administrators (`manage_options` capability) are the sole audience — no role-based sub-permission (e.g., "editor can see the tab but not toggle") is in scope for this increment.
- Multisite subsites each maintain their own per-site table (BerlinDB `$global = false`); no network-admin surface is added.
- Deletion cascade when an MCP server row is deleted is a **known follow-up** — Feature 017 does not add a listener that purges orphan per-(server, ability) rows. This is deferred to a subsequent ticket and documented as such in the planning doc's non-goals list.
- The extensibility surface is `@wordpress/hooks` + a single PHP `apply_filters()`. Feature 017 does NOT ship any UI components meant for extensions to reuse (no shared "Edit modal", no shared button styling contract). Extensions bring their own UI. Similarly, F017 does NOT own the "Edit" flow described in User Story 6 — that flow is owned entirely by the companion plugin (e.g. `acrossai-abilities-manager`), which enqueues its JS after the abilities bundle and registers filters.
- Enforcement is **call-time only** in F017. Hidden abilities may still appear in the AI client's `mcp/tools/list` / `mcp/prompts/list` / `mcp/resources/list` responses — invoking them returns `403` at the tool-call boundary via FR-030. **List-time hiding** (filtering hidden abilities out of the per-server listing before the client sees them) is deferred to a follow-up feature, gated on discovering the vendor `wordpress/mcp-adapter`'s per-server ability-collection hook (or on that hook being added upstream). Documented as a known UX gap; SEC-001 (call-time gate) covers the security risk.
- The UI implementation depends on `@wordpress/dataviews@^17.x` DOM conventions. Several CSS selectors target vendor-internal class names and `aria-label` values (`.dataviews__view-actions`, `[aria-label*="Add filter" i]`, etc.). Upgrading the vendor package to a major version requires manual re-verification of these selectors.
- The extensibility surface is **JS-only** (FR-026, FR-028) as of Session 2026-07-08. The previously-planned PHP `acrossai_mcp_ability_row` filter is retired (see FR-027). Companion plugins that need to add per-row data server-side must either persist that data in their own tables and read it via the `.row` JS filter (via `wp.data.select()`) or ship an admin-scoped REST route their `render` callback fetches from.
