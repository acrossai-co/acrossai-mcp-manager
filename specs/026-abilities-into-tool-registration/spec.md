# Feature Specification: Include F017-effective abilities in the composed tool list at server-registration time

**Feature Branch**: `026-abilities-into-tool-registration`
**Created**: 2026-07-14
**Status**: Draft
**Input**: User description: "Pass all abilities enabled at the Abilities tab (F017) as MCP tools, plus every globally-enabled MCP ability (`mcp.public = true`) unless per-server disabled. Reuse F025's `mcp_adapter_default_server_config` hook for the default server and F025's `acrossai_mcp_manager_server_tools` filter for database servers. No new schema, no new filter, no REST GET changes."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operator's Abilities-tab visibility choices reach AI clients (Priority: P1) 🎯 MVP

Today an operator can mark abilities visible or hidden per-server on the Abilities tab (F017, `?tab=abilities`), but that choice only affects call-time enforcement — the abilities never appear in the server's `tools/list` output because the Tools tab (F025 composer) doesn't know about them. Operators believe they've enabled an ability for AI clients but it's invisible.

**Why this priority**: This is the whole feature. Every other story is a supporting detail. Without this, F017's per-server overrides remain silent gates instead of visible advertisements.

**Independent Test**: On the Abilities tab, mark an ability with `mcp.public = true` as visible for a specific server. Call `tools/list` on that server's MCP endpoint. Response includes the ability. Toggle it off in the tab; re-call `tools/list`; ability is gone.

**Acceptance Scenarios**:

1. **Given** an ability registered with `mcp.public = true` and no per-server override row, **When** the AI client calls `tools/list` on any enabled server, **Then** the response `tools` array includes the ability slug alongside protocol + curated slugs.
2. **Given** the same ability, **When** the operator opens the Abilities tab, toggles the ability OFF for server X (writes `is_exposed = 0` row), and the AI client re-calls `tools/list` on server X, **Then** the ability slug is absent from the response.
3. **Given** an ability registered with `mcp.public = false` and no per-server override, **When** the AI client calls `tools/list` on any server, **Then** the ability slug is NOT in the response (matches F017 fallback precedence).
4. **Given** the same non-public ability, **When** the operator toggles it ON for server X (writes `is_exposed = 1` row), and the AI client re-calls `tools/list`, **Then** the ability slug appears in the response for server X but not on other servers.

---

### User Story 2 - Companion plugins keep working via the existing F025 filter (Priority: P2)

Companion plugins that hook `acrossai_mcp_manager_server_tools` (introduced in F025) receive the composed tool list per database-server registration. F026 widens the pre-filter list from `(protocol + curated)` to `(protocol + curated + F017-effective abilities)` — same signature, wider input, no new hook.

**Why this priority**: Extension surface preservation. F025 shipped this filter with a documented contract; F026 must not break existing consumers.

**Independent Test**: In a scratch mu-plugin, hook `acrossai_mcp_manager_server_tools` and log the received `$tools` array. After F026 lands, the log includes F017-effective ability slugs alongside the F025 protocol + curated slugs. The mu-plugin's `array_diff`/`array_merge` return continues to work as before — no callback changes required.

**Acceptance Scenarios**:

1. **Given** a companion mu-plugin registers `add_filter( 'acrossai_mcp_manager_server_tools', $callback, 10, 2 )`, **When** the plugin registers a database server, **Then** `$callback` receives a `$tools` array containing protocol + curated + F017-effective ability slugs (strict superset of pre-F026).
2. **Given** a companion callback returns `array_diff( $tools, [ 'some-slug' ] )`, **When** the server registers, **Then** `'some-slug'` is stripped from the final `tools/list` — same behavior as before F026.
3. **Given** the vendor default-server callback (`Controller::filter_default_server_config`), **When** it runs on `mcp_adapter_init`, **Then** the vendor's `$config['tools']` is REPLACED with the F026-widened composed set (protocol + curated + F017-effective).

---

### User Story 3 - Tools tab UI is unchanged (Priority: P2)

The Tools tab REST GET response (`GET /wp-json/acrossai-mcp-manager/v1/servers/{id}/tools`) continues to return only what the operator explicitly picked in the Tools tab (protocol + curated). The F017-effective abilities are NOT surfaced in the Tools tab count, list, or add/remove UI. Operators keep their existing mental model: Tools tab = my explicit picks; Abilities tab = per-server visibility overrides.

**Why this priority**: UX consistency. The two tabs have distinct mental models; conflating them (e.g., showing F017 abilities in the Tools tab count) would confuse operators.

**Independent Test**: On an enabled server, mark a public ability visible on the Abilities tab. Open the Tools tab. The count text is unchanged (still `%1$d of %2$d abilities added as tools`); the "Added as tools" pane does NOT show the F017-visible ability. `curl GET /tools` returns only protocol + curated slugs.

**Acceptance Scenarios**:

1. **Given** a public ability visible on the Abilities tab (no override or `is_exposed = 1`), **When** the operator opens the Tools tab for the same server, **Then** the ability does NOT appear in the "Added as tools" pane and the count text does NOT include it.
2. **Given** the Tools tab GET endpoint, **When** the operator hits `GET /wp-json/acrossai-mcp-manager/v1/servers/{id}/tools`, **Then** the response `tools` array contains only protocol + curated slugs (no F017-effective abilities).
3. **Given** the operator saves the Tools tab (POST), **When** the POST completes, **Then** the F017 tables are unchanged — no bleed-through.

---

### Edge Cases

- **What if `wp_get_abilities()` is unavailable at server-registration time?** Skip the F017 pass silently — the server still registers with protocol + curated. Matches F017's `AbilityExposureGate` fail-open pattern and F025's `filter_default_server_config` defensive short-circuits. Documented behavior; not a bug.
- **What if a curated ability has `mcp.public = false` AND no per-server override?** It appears in `tools/list` because F020 curation is an explicit operator pick (higher intent than the F017 default). Curated wins over F017 fallback.
- **What if a curated ability is toggled OFF on the Abilities tab (`is_exposed = 0` row)?** F017's call-time gate (priority 20) still blocks `tools/call`, so the tool advertisement is misleading. Documented as an accepted inconsistency — the operator's Tools tab pick is honored at advertisement time; the Abilities tab override is honored at call time. Future work may reconcile via UX (e.g., a warning banner on the Tools tab). Out of scope for F026.
- **What if the same slug appears in BOTH `wp_acrossai_mcp_server_tools` (curated) AND resolves to `true` via F017?** Dedup collapses to one entry in the composed set. No duplication in `tools/list`.
- **What if `ExposureResolver::resolve()` throws?** It doesn't — the resolver is a pure BerlinDB query + boolean fallback. Defensive: iterate is wrapped so an unexpected throw on one ability skips it, not the whole pass. (Actually the resolver never throws; noted for completeness.)
- **What if 1000 abilities are registered?** `ExposureResolver::resolve()` uses a per-request static cache. First iteration does N DB queries; subsequent calls in the same request are O(1). Not a scale concern for typical installs (<200 abilities).
- **What if `Controller::filter_default_server_config()`'s empty-set fallback path changes shape?** (SEC-026-v2-1) The F025 empty-set fallback (`if ( empty( $tools ) ) { return $config; }`) previously fired when protocol + curated were both empty; post-F026 it fires only when protocol + curated + F017-effective are all empty. On any install with at least one ability where `meta.mcp.public = true` (or one `is_exposed = 1` override row), the fallback becomes unreachable. This is a deliberate expansion of the composed set's coverage, NOT a behavioral regression — the fallback's original purpose (fail-open when the operator explicitly emptied every source) is preserved.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST include every WordPress ability where `ExposureResolver::resolve( $server_id, $ability_slug, $meta )` returns `true` in the composed tool list passed to `\WP\MCP\Core\McpAdapter::create_server()` for every enabled MCP server.
- **FR-002**: The F017 pass MUST honor the row-in-`wp_acrossai_mcp_server_abilities` beats `meta.mcp.public` precedence as encoded by `ExposureResolver` (single canonical resolver per `DEC-ABILITY-OVERRIDE-RESOLUTION`).
- **FR-003**: When `wp_get_abilities()` is unavailable (`! function_exists( 'wp_get_abilities' )`), the F017 pass MUST be skipped silently — the server still registers with the F025-composed set (protocol + curated). No `WP_Error`, no admin notice, no `error_log`.
- **FR-004**: The default MCP server (slug `mcp-adapter-default-server`) MUST receive the F017-widened composed set via the vendor `mcp_adapter_default_server_config` filter (F025 hook, callback in `Controller::filter_default_server_config`).
- **FR-005**: Every plugin-created (database, `registered_from = 'database'`) MCP server MUST receive the F017-widened composed set inside `Controller::register_database_servers()` before the `acrossai_mcp_manager_server_tools` filter fires.
- **FR-006**: The F025 filter `acrossai_mcp_manager_server_tools` MUST fire with the widened pre-filter composed set — same signature `(string[] $tools, MCPServer\Row $server)`, same call site, no double-firing. Companion plugins hooking this filter receive a strict superset of what they saw before F026.
- **FR-007**: The REST GET `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` response MUST continue to return the F025 `compose_for_row()` output (protocol + curated only) — NOT include F017-effective abilities in its `tools` array. The Tools tab UI is unchanged in every user-visible respect.
- **FR-008**: The REST POST `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` MUST remain byte-for-byte identical to F025 — no new payload fields, no new response fields, no new validation rules.
- **FR-009**: The composed tool list MUST be deduped and `array_values()`-normalized. If a curated slug (F020 presence row) matches an F017-effective slug, exactly one entry appears in the composed set.
- **FR-010**: The `ExposureResolver::resolve()` per-request static cache MUST be respected — the F026 iteration calls `resolve()` at most once per (server_id, ability_slug) pair per request cycle.
- **FR-011**: The composed set order (protocol → curated → F017-effective) is stable within a single call but not part of the public contract. Callers MUST NOT depend on ordering.
- **FR-012**: Companion-plugin filter callbacks receive strings-only in the `$tools` array — the F017 pass appends `(string) $ability->get_name()`, matching F025's `strval` normalization.
- **FR-013**: The composed set MUST NOT contain empty strings. Abilities returning empty `get_name()` are skipped.

### WordPress Requirements

**PHP Version**: PHP 8.0+ (plugin baseline; F026 does not exercise 8.1+ syntax).
**WordPress Version**: 6.9+
**Multisite**: Single-site only (matches plugin baseline).
**Required Plugins / Packages**: `wordpress/mcp-adapter` (vendored, unchanged); WordPress Abilities API (bundled since WP 6.9 rollout window).
**Optional Integrations**: `wpboilerplate/wpb-access-control` (F015 baseline, orthogonal).

### Module Placement

**PHP Class(es)** — extending existing files only, no new files:
- `includes/Database/MCPServer/ToolPolicy.php` — add one new `public static function compose_effective_tools_for_row( Row $row ): array` method alongside the existing F025 methods. Stateless per A11.
- `includes/MCP/Controller.php` — two call-site swaps (line 142 + line 247) plus a filter-docblock update; no new methods.
- `includes/REST/ToolsController.php` — NO CODE CHANGE. Grep-verified only.

**JavaScript Module(s)**: none. F026 has zero React / JS surface.

**Hook Registration**: no new hooks. F025's existing `add_action( 'rest_api_init', ... )` for `initialize_adapter` and `add_filter( 'mcp_adapter_default_server_config', ... )` for `filter_default_server_config` continue to wire the same callbacks — those callbacks now internally call the new composer method.

### Admin UI Requirements

**No admin UI changes.**

- Tools tab (`?tab=tools`): unchanged. Count text, list, add/remove UI, ConfirmDialog behavior — all identical to F025 shipping.
- Abilities tab (`?tab=abilities`): unchanged. The tab's REST endpoints, React app, and per-server override toggle behavior are untouched.
- Both tabs' user-visible surfaces are preserved verbatim.

### REST API Contract

Route shapes are preserved.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` | `manage_options` | Unchanged. Response `tools` array continues to reflect protocol + curated only (F025 contract). F017-effective abilities do NOT appear here. |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` | `manage_options` | Unchanged. Same payload, same response, same split-write behavior (F025 contract). |
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers/{id}/abilities` | `manage_options` | Unchanged (F017 contract). |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/servers/{id}/abilities` | `manage_options` | Unchanged (F017 contract). |

**`permission_callback` rule**: unchanged. Both endpoints check `manage_options`.

### Database / Storage

**No schema changes.**

- `wp_acrossai_mcp_servers` — unchanged (F025 columns intact).
- `wp_acrossai_mcp_server_tools` — unchanged (F020 presence rows).
- `wp_acrossai_mcp_server_abilities` — unchanged (F017 overrides). F026 READS via `ExposureResolver::resolve()` but never writes.

### Security Checklist

- [ ] All form/AJAX handlers verify nonce — unchanged from F017/F020/F025.
- [ ] All admin page renders check `current_user_can( 'manage_options' )` — unchanged.
- [ ] All REST routes have explicit `permission_callback` — unchanged.
- [ ] All user input sanitized at system boundary — no new user input surfaces in F026.
- [ ] All output escaped at point of rendering — no new HTML output in F026.
- [ ] All DB queries use `$wpdb->prepare()` — F026 reads via BerlinDB Kern; no raw SQL added.
- [ ] OAuth tokens / Application Passwords stored hashed — orthogonal.
- [ ] File uploads — N/A.

### Key Entities

- **MCP Server**: unchanged (F025 columns + F020 curated rows apply).
- **Ability**: WordPress ability registered via `wp_register_ability()`. Has a `name`, a `label`, a `description`, and `meta` (with `meta.mcp.public` used by the fallback).
- **Effective exposure for (server, ability)**: computed by `ExposureResolver::resolve()`. Row in `wp_acrossai_mcp_server_abilities` wins; else `meta.mcp.public` fallback.
- **Composed tool list (F026)**: union of (a) F025's `compose_for_row()` output = enabled protocol columns + F020 curated slugs, and (b) every ability with effective exposure `true` for the server. Deduped and `array_values`-ed.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [ ] PHPCS validation: zero errors on `ToolPolicy.php` and `Controller.php`.
- [ ] PHPStan level 8: zero errors, no new baseline entries.
- [ ] ESLint: N/A (no JS changes).
- [ ] PHPUnit tests written and passing for the new composer method (4 cases) and the widened controller composition (1 case).
- [ ] Security checklist above: all applicable items verified.
- [ ] No new hooks in `Main.php` — F025's existing wiring covers both call sites.
- [ ] `ExposureResolver` — untouched (verify via `git diff --stat includes/Database/MCPServerAbility/`).
- [ ] `AbilityExposureGate` — untouched.
- [ ] `AbilitiesController` — untouched.
- [ ] `src/js/tools.js` and `src/js/abilities.js` — untouched.
- [ ] `ToolsController::get_tools()` — untouched (grep `compose_effective_tools_for_row` in `includes/REST/ToolsController.php` returns zero matches).
- [ ] Grep audits: exactly 3 matches for `compose_effective_tools_for_row` in `includes/`; exactly 3 matches for `compose_for_row` (definition + REST GET + internal seed call); exactly 1 match for `apply_filters( 'acrossai_mcp_manager_server_tools'`.
- [ ] `npm run validate-packages` — N/A (no npm changes).

### Measurable Outcomes

- **SC-001**: On any enabled server, marking an ability visible on the Abilities tab makes it appear in `tools/list` on the next MCP request-cycle (no cache invalidation delay beyond what F017 already handles).
- **SC-002**: Marking an ability hidden on the Abilities tab (`is_exposed = 0` row) removes it from `tools/list` on the next MCP request-cycle.
- **SC-003**: 100% of existing installs' pre-F026 `tools/list` behavior is preserved for the case where NO abilities have `mcp.public = true` and NO Abilities tab overrides exist — the composed set equals F025's output byte-for-byte.
- **SC-004**: The Tools tab UI (count text, list, add/remove UX) is byte-for-byte identical before and after F026 in every operator-visible respect. Verified by manual comparison against F025 screenshots.
- **SC-005**: Companion plugins hooking `acrossai_mcp_manager_server_tools` continue to work without modification — the filter signature is unchanged; the received `$tools` array is a strict superset of what they saw pre-F026.
- **SC-006**: The fail-open path (Abilities API unavailable) does not fatal, log, or produce any admin notice. Verified by deactivating the Abilities API polyfill (if present) or running under a WP version predating the Abilities API and confirming server registration still succeeds with protocol + curated only.

---

## Assumptions

- The WordPress Abilities API (`wp_get_abilities`, `wp_get_ability`, `wp_register_ability`) is available at REST time on any install running the plugin's declared WP minimum (6.9+). The fail-open branch (FR-003) is defensive coverage for edge cases (unloaded Abilities API, prerelease WP builds).
- F017's `ExposureResolver::resolve()` implements the row-in-table > `mcp.public` precedence correctly — F026 REUSES it verbatim and does not re-derive the fallback logic.
- Companion plugin authors who hook `acrossai_mcp_manager_server_tools` will discover the widened pre-filter set on the next diff read of `docs/extending-server-tools.md` (updated as part of F026). Backwards compatibility of the filter's signature guarantees callbacks continue to work; adjusting them for the widened input is optional.
- No install currently depends on the Tools tab GET response including F017-effective abilities — that has never been the contract. F026 explicitly preserves this.
- Multisite support is out of scope for this increment (plugin baseline).
- The current F025 filter documentation at `docs/extending-server-tools.md` is authoritative and up-to-date; F026 amends it in place rather than authoring a new doc.

---

## Scope expansion: F026 v2 (2026-07-14, folded during governed-implement)

**Trigger**: During post-implementation review of F026 v1 (tools-only), user extended scope to include resources + prompts + a type-filter correction on the tools composer. Confirmed via AskUserQuestion answer "Fold into F026" + "F017-effective only, no curation". No new tables, no new UI.

### Bug discovered in F026 v1

F026 v1's `ToolPolicy::compose_effective_tools_for_row()` iterated every ability where `ExposureResolver::resolve()` returned true, regardless of the ability's `mcp.type`. A resource-typed public ability would leak into the tools composed set and get advertised in `tools/list` — and then `tools/call` on it would fail because `AbilityExposureGate` sits on `mcp_adapter_pre_tool_call` (tool-scoped hook). This is corrected in v2 by adding a `mcp.type === 'tool'` filter (defaulting to 'tool' when unset, matching vendor `DefaultServerFactory::discover_abilities_by_type()` semantic). No tests in v1 caught this because scratch abilities were registered without a `type` key.

### User Story 4 — Resources tab visibility choices reach AI clients (Priority: P1)

Same shape as US1 but for `mcp.type === 'resource'` abilities. Operator marks a resource-typed ability visible on the Abilities tab; the ability appears in `tools/list` under the `resources` array on the server's MCP endpoint. Toggle off; ability disappears. Applies to both the default server (via `mcp_adapter_default_server_config` REPLACE of `$config['resources']`) and DB servers (via new `acrossai_mcp_manager_server_resources` filter).

**Acceptance Scenario 4.1**: A resource-typed ability with `mcp.public = true`, no override → appears in `resources` array for every enabled server. Toggle `is_exposed = 0` via Abilities tab → disappears from that server only.

### User Story 5 — Prompts tab visibility choices reach AI clients (Priority: P1)

Same shape as US4 but for `mcp.type === 'prompt'` abilities and the `prompts` array on the server's MCP endpoint. Filter: `acrossai_mcp_manager_server_prompts`.

### New Functional Requirements

- **FR-014**: `ToolPolicy::compose_effective_tools_for_row()` MUST filter F017-effective abilities by `mcp.type === 'tool'` (treating missing/non-string `mcp.type` as `'tool'`). Resource/prompt-typed abilities MUST NOT leak into the tools composed set.
- **FR-015**: A new stateless class `AbilityDiscovery` MUST expose `for_server( int $server_id, string $type ): string[]` at `includes/Database/MCPServer/AbilityDiscovery.php`. Semantics: iterate `wp_get_abilities()`, keep those where `mcp.type` (default `'tool'`) matches `$type` AND `ExposureResolver::resolve()` is true. Fail-open returns `[]` when Abilities API absent.
- **FR-016**: `Controller::register_database_servers()` MUST pass the F017-effective resource + prompt composed sets to `$adapter->create_server()`'s 11th and 12th args (previously `array(), array()`).
- **FR-017**: Two new sibling filters MUST fire per DB server registration alongside `acrossai_mcp_manager_server_tools`: `acrossai_mcp_manager_server_resources` and `acrossai_mcp_manager_server_prompts`. Same signature `(string[], MCPServer\Row): string[]`. Same defensive re-normalization applied to return value. NOT fired for the default server.
- **FR-018**: `Controller::filter_default_server_config()` MUST REPLACE `$config['resources']` and `$config['prompts']` unconditionally when the default server row exists (no empty-set fallback for these two keys). Rationale: the vendor's `DefaultServerFactory::discover_abilities_by_type()` already sets these to the `mcp.public = true` set — if an operator toggles a public resource/prompt OFF via the Abilities tab, we MUST remove it from the default server, otherwise the Abilities-tab control is a no-op for the default server.
- **FR-019**: The `tools` key on `$config` retains the F025 empty-set fallback (unchanged from F026 v1) — the "starter tool set" concept doesn't have a resource/prompt analogue.

### Additional Edge Cases

- **What if a companion plugin previously depended on database servers having empty `resources` / `prompts` arrays?** Behavioral change: DB servers now advertise F017-effective, type-filtered abilities. Companion plugins can hook `acrossai_mcp_manager_server_resources` / `..._server_prompts` at any priority and `return array();` to restore the old empty-array behavior per-server.
- **What if two abilities share a slug across different types?** Ability slugs are globally unique per WordPress core's `wp_register_ability()`. If a hypothetical duplicate did exist, `AbilityDiscovery::for_server()` would return only the type-matched one on each call, and dedup within the array is preserved.
