# Implementation Plan: Per-Server Ability Permission-Callback Override

**Branch**: `030-per-server-permission-override` | **Date**: 2026-07-20 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/030-per-server-permission-override/spec.md`
**Memory Synthesis**: [memory-synthesis.md](./memory-synthesis.md)
**Engineering Brief**: `docs/planings-tasks/030-per-server-permission-override.md`

**Governance note**: Planning was performed inline under `/speckit-architecture-guard-governed-plan`. The `/speckit-plan` skill is available but was not invoked as a subskill to keep this turn scoped; the plan below incorporates all constitution, memory-synthesis, and engineering-brief inputs the standalone `/speckit-plan` would consume.

---

## Summary

Extend the server-edit **Access Control** tab with a second, `<hr>`-separated section beneath the existing `wpb-access-control` React panel that persists a per-server `override_abilities_permission tinyint(1)` boolean on `wp_acrossai_mcp_servers`. When ON for the server serving an in-flight MCP request, every ability exposed via `wp_acrossai_mcp_server_abilities` gets its `permission_callback` swapped for a `CurrentServerHolder`-scoped closure that returns `true` unconditionally. Ship an ability-editing promo card that offers one-click Install/Activate of the sibling `acrossai-abilities-manager` plugin via the existing `acrossai_addons` filter + `main-menu` package `AddonsAjaxHandlers`. Provide a "Prefer to use code?" `<details>` fallback documenting the WP core filter (`wp_register_ability_args`, P999999) for devs who don't want the sibling plugin.

Runtime override registers at `wp_register_ability_args` priority **999999** — strictly higher than sibling `acrossai-abilities-manager`'s P100000 and this plugin's own `CallbackReplacer` P10 — so the operator's opt-in wins deterministically. Column is added via D28 3-part BerlinDB contract (bump `$version` 1.1.1 → 1.1.2, register `$upgrades['1.1.2' => 'upgrade_to_1_1_2']`, `INFORMATION_SCHEMA.COLUMNS` idempotency guard). No new REST routes. No JS build step — a single inline `<script>` fires `confirm()` on submit-to-ON.

---

## Technical Context

**Language/Version**: PHP 8.1+ (constitution §II WordPress Standards; plugin `composer.json` requires 8.1). WordPress 6.9+.
**Primary Dependencies**:
- BerlinDB Core 3.0 (already vendored; used for `MCPServer\Table` column-add via D28)
- WP Abilities API (already required; the `wp_register_ability_args` filter is the override attachment point)
- MCP Adapter (already required; `CurrentServerHolder` captures its request context via A17)
- `acrossai-co/main-menu` composer package (already required; provides `acrossai_addons` filter + `AddonsAjaxHandlers::install/activate` + `AddonsPageRenderer::button_state_for()` public helper for the promo card)
**Storage**: Custom BerlinDB table `wp_acrossai_mcp_servers` (existing) — one new `tinyint(1)` column added. No new tables. Junction table `wp_acrossai_mcp_server_abilities` (existing) read at request time via `ExposureResolver::resolve()`.
**Testing**: PHPUnit for the runtime closure fall-through matrix + the save handler nonce/capability paths. No new Jest tests (no JS build entry added; inline `<script>` for `confirm()` is not test-worthy on its own).
**Target Platform**: WordPress admin (server-side rendered admin page) + wp-json MCP REST route (server-side ability permission check).
**Project Type**: WordPress plugin (single-site; `multisite_support: false` per AGENTS.md).
**Performance Goals**: Closure runs on every ability `permission_callback` invocation inside an in-flight MCP request. Target: ≤ 1 DB query per unique `server_id` per request via per-request static cache (mirrors F017 `ExposureResolver::resolve()` cache shape). No measurable latency impact on non-MCP callers (closure short-circuits on `null === $server_id`).
**Constraints**: Filter priority 999999 is load-bearing — must NOT be lowered. `CurrentServerHolder` fall-through is load-bearing — must NOT be removed. `ExposureResolver::resolve()` gate is load-bearing per DEC-ABILITY-OVERRIDE-RESOLUTION — MUST NOT re-derive exposure inline.
**Scale/Scope**: ~10 files touched (3 for schema, 1 tab render, 1 save handler, 1 new processor class, 1 new promo-card partial, 1 addon-filter extension, 1 Main.php wiring, 1 changelog + memory). Estimated 350–500 LOC net addition, minimal deletions. Plus 14 `.wordpress-org/` marketing assets committed alongside.

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Evaluated against constitution v1.1.0 (2026-07-12):

### I. Modular Architecture — PASS

- New `PermissionOverrideProcessor` is a self-contained module in `includes/Abilities/` with singular purpose (permission-callback override injection).
- No sibling-module coupling: the processor depends only on `CurrentServerHolder` (existing, shared via A17), `MCPServerQuery` (existing shared DB accessor), and `ExposureResolver` (existing single-resolver per DEC-ABILITY-OVERRIDE-RESOLUTION).
- Shared logic (`call_original()` fallback helper) lives inside the new class, not duplicated. The pattern is a sibling of `CallbackReplacer` (D25); if a third callback-swap consumer emerges later, the fallback helper can be extracted to `includes/Utilities/`.

### II. WordPress Standards Compliance — PASS

- PHPCS + PHPStan L8 gates enforced by AGENTS.md Before Commit Checklist.
- All output escaped (`esc_html__`, `checked()`, `esc_url()`, `esc_attr()`).
- All input sanitized (`absint`, `wp_unslash`, `! empty()` for the checkbox tinyint coercion).
- Uses `wp_remote_*` — N/A (no outbound HTTP in this feature).
- WordPress 6.9+, PHP 8.1+ — matches constitution baseline.
- Multisite: single-site only per `multisite_support: false` (documented in spec Assumptions).

### III. Security First (NON-NEGOTIABLE) — PASS with acknowledged design intent

- **Input sanitized** at boundary (save handler): `absint()` on `server_id`, `! empty()` on checkbox.
- **Output escaped** at point of render (form + warning banner + promo card + `<details>` snippet): every string wrapped in `esc_html__` / `esc_attr` / `esc_url`.
- **Nonce**: `check_admin_referer('acrossai_mcp_manager_permission_override_' . $server_id, …)` on save handler; `acrossai_addons` nonce for the promo-card AJAX (reused from `main-menu` package).
- **Capability check**: `manage_options` on save handler; `install_plugins` + `activate_plugins` gate the promo card's Install button (main-menu package enforces).
- **DB queries**: BerlinDB `Query::update_item()` (parameterized); `upgrade_to_1_1_2()` ALTER uses backtick-quoted `{$table}` identifier (matches D28 reference impl in `MCPServer\Table::upgrade_to_1_1_1`).
- **REST routes**: no new routes added; existing routes unchanged.
- **OAuth/App Passwords**: N/A (feature does not touch tokens).
- **File uploads**: N/A.
- **Consent-surface exception**: N/A — this is an admin-only `manage_options` toggle, not a consent surface.

**Acknowledged design intent** (surfaced in memory-synthesis Conflict Warnings): the runtime closure explicitly bypasses each ability's `permission_callback` when the operator opts in via the toggle. This is a *soft conflict* with D24's "exposure ≠ authorization" corollary. Not a §III violation because: (a) the operator explicitly requests the bypass via a `manage_options`-gated form + per-server nonce + persistent warning banner + native `confirm()` prompt; (b) scope is narrowed by `CurrentServerHolder` (in-flight MCP request only) + `ExposureResolver` (exposed-to-this-server abilities only); (c) non-MCP callers see the original callback unchanged. Documented in spec §Assumptions + memory-synthesis §Conflict Warnings. Will be captured as `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS` post-implement.

### IV. User-Centric Design (NON-NEGOTIABLE) — PASS with pre-approved exception

- The Access Control tab is a per-server sub-tab under the MCP Manager parent menu, covered by the **pre-approved WP_List_Table exception** (constitution §IV first exception paragraph). Existing server-edit tabs (Overview, Update Server, Danger Zone, etc.) use hand-rolled admin forms; F030's new form section follows the same precedent.
- The new form is a **single boolean toggle** — using DataForm/DataViews for a single checkbox + save button would be layout gymnastics without user benefit.
- The promo card uses inline hand-rolled markup (card + button) reusing the state-detection logic from `main-menu`'s `AddonsPageRenderer::button_state_for()`. This is a display of static configuration (one card per addon entry), not a filterable/sortable data grid — analogous rationale to constitution §IV's second exception paragraph (Connector picker card layout).
- No `@wordpress/dataviews` bundle is added or extended by this feature.

### V. Extensibility Without Core Modification — PASS

- Implementation is entirely via WordPress action/filter hooks (`wp_register_ability_args`, `admin_init`, `acrossai_addons`).
- No existing core plugin files are modified beyond narrow additions (`Main.php` gets one filter wiring line; `AccessControlTab::render_body()` gets 3 additional render calls; `Schema.php` + `Table.php` + `Row.php` get one column entry each per D28).
- Sibling `acrossai-abilities-manager` plugin is optional — override closure works whether it is installed or not.

### VI. Reusability & DRY Principle — PASS

- `CurrentServerHolder` reused (not duplicated).
- `ExposureResolver::resolve()` reused (not duplicated) — enforces DEC-ABILITY-OVERRIDE-RESOLUTION single-resolver invariant.
- `MCPServerQuery` reused (BerlinDB base API).
- Promo-card state detection reuses `main-menu` package's `AddonsPageRenderer::button_state_for()` — no reimplementation.
- Promo-card install/activate AJAX handlers reuse `main-menu` package's `AddonsAjaxHandlers::install` + `::activate` — no reimplementation.
- Existing `AddonsFilter` singleton (F028 pattern per D26) is extended for the "add abilities-manager to addons list" registration OR a sibling singleton is added if F028's class is scoped strictly to self-exclusion. Choice pinned in Phase 1 below.

### VII. Definition of Done — GATE ENFORCED

Per constitution §VII, feature is only complete when all DoD items pass. Mapped to F030:

- PHPCS + PHPStan L8: enforced by CI (existing gates).
- ESLint: N/A (no new JS surface).
- Security review: this document + spec §Security Checklist + memory-synthesis §Conflict Warnings.
- Unit tests: PHPUnit covering `PermissionOverrideProcessor::inject_override()` fall-through matrix (null server, override off, not exposed, override on + exposed) + save handler rejection paths (bad nonce, missing capability).
- DataForm/DataViews: N/A — pre-approved exception (see §IV above).
- No code duplication: verified (see §VI).
- Prefix `acrossai_mcp_`: nonce, form field, redirect flag, new class namespace all match.
- AGENTS.md standards: covered by Before Commit Checklist.
- `npm run validate-packages`: unchanged (no new npm packages).

---

## Project Structure

### Documentation (this feature)

```text
specs/030-per-server-permission-override/
├── spec.md                     # /speckit-specify + /speckit-clarify output
├── memory-synthesis.md         # /speckit-memory-md-plan-with-memory output
├── plan.md                     # THIS FILE — /speckit-architecture-guard-governed-plan output
├── checklists/
│   └── requirements.md         # /speckit-specify output
└── tasks.md                    # (next) /speckit-tasks output — NOT created here
```

Optional Phase-1 artifacts (`research.md`, `data-model.md`, `quickstart.md`, `contracts/`) are deliberately omitted — the engineering brief at `docs/planings-tasks/030-per-server-permission-override.md` already carries every implementation detail (exact code snippets, verification checklists, constraint list, speckit invocation prompts) that those artifacts would duplicate. If reviewers request them, they can be generated from the brief with zero net-new decisions.

### Source Code (repository root)

Concrete files touched by this feature — no placeholders:

```text
includes/
├── Abilities/
│   └── PermissionOverrideProcessor.php   # NEW — singleton; boot() hooks wp_register_ability_args P999999
├── Database/
│   └── MCPServer/
│       ├── Schema.php                    # EDIT — append override_abilities_permission to $columns
│       ├── Table.php                     # EDIT — bump $version 1.1.1→1.1.2; register upgrade_to_1_1_2()
│       └── Row.php                       # EDIT — add public property + (int) cast
└── Main.php                              # EDIT — one line to boot PermissionOverrideProcessor

admin/
└── Partials/
    ├── Settings.php                      # EDIT — handle_save_permission_override() + POST router branch
    ├── AddonsFilter.php                  # EDIT (or NEW sibling) — register acrossai-abilities-manager
    └── ServerTabs/
        ├── AccessControlTab.php          # EDIT — render_body() now: wpb-ac panel → <hr> → form → promo card → <details>
        └── Partials/                     # NEW subdir
            └── AbilitiesManagerPromoCard.php  # NEW — inline addon card reusing AddonsPageRenderer::button_state_for()

docs/
├── planings-tasks/
│   └── 030-per-server-permission-override.md  # already committed (engineering brief)
├── memory/
│   ├── DECISIONS.md                      # EDIT (post-implement) — add DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS
│   ├── INDEX.md                          # EDIT (post-implement) — add row for the new decision
│   └── WORKLOG.md                        # EDIT (post-implement) — F030 milestone entry

.wordpress-org/                           # 14 new marketing assets — committed in same PR
README.txt                                # EDIT — Unreleased changelog bullet
```

**Structure Decision**: WordPress plugin (Option 1 in template — single-project layout mapped to plugin directories). Rationale: matches the constitution's Architecture & UI Standards §Directory Layout exactly; every file has a natural home under `includes/`, `admin/Partials/`, `docs/`, `specs/`.

---

## Phase 1 — Design Decisions Pinned Here

Three open questions from `/speckit-memory-md-plan-with-memory` §"Next step" — pinned:

1. **Closure per-request caching** — mirror F017's `ExposureResolver::resolve()` pattern: `PermissionOverrideProcessor` holds a static `private static ?array $server_row_cache = null;` keyed by `int $server_id`. First lookup runs one `MCPServerQuery::instance()->query(['id' => $id, 'number' => 1])`; subsequent lookups within the same request return the cached row. Cache is cleared in `rest_post_dispatch` P999 alongside `CurrentServerHolder::clear()` (via a hook in `Main.php`, symmetry with A17). Rationale: guarantees ≤1 DB query per unique server per request even when a single MCP request calls many exposed abilities in sequence.

2. **`acrossai-abilities-manager` addon entry** — `download_url` pinned to the latest tagged GitHub release ZIP at implement time (grep `https://api.github.com/repos/acrossai-co/acrossai-abilities-manager/releases/latest` and use the `.zipball_url` OR pin a specific `v0.X.Y` tag URL). `source: 'github'`, `install_folder: 'acrossai-abilities-manager'` (ZIP extracts to a hash-suffixed dir by default; the explicit `install_folder` forces normalized placement per main-menu's `AddonsInstaller::find_plugin_file()` contract). Card entry:
   ```php
   [
       'slug'           => 'acrossai-abilities-manager',
       'name'           => __( 'AcrossAI Abilities Manager', 'acrossai-mcp-manager' ),
       'description'    => __( 'Edit ability permission_callback, labels, categories, and access rules via a UI.', 'acrossai-mcp-manager' ),
       'icon'           => 'https://raw.githubusercontent.com/acrossai-co/acrossai-abilities-manager/main/.wordpress-org/icon.svg',
       'more_url'       => 'https://github.com/acrossai-co/acrossai-abilities-manager/',
       'source'         => 'github',
       'download_url'   => '<PIN-AT-IMPLEMENT>',
       'install_folder' => 'acrossai-abilities-manager',
   ]
   ```

3. **`AddonsPageRenderer::button_state_for()` publicity** — confirmed public via the Explore agent report. Feature 030 consumes it directly; no upstream extraction PR needed. If a future main-menu release makes it private, F030 has a fallback: implement a minimal state-detection helper inline (`AddonsInstaller::find_plugin_file()` + `is_plugin_active()`) — both are also public.

---

## Phase 2 — Task Decomposition (defer to `/speckit-tasks`)

The engineering brief at `docs/planings-tasks/030-per-server-permission-override.md` already enumerates 5 TASK blocks with per-file breakdowns, code snippets, and per-task verification checklists. `/speckit-tasks` will project those blocks into concrete T### task IDs suitable for the `governed-tasks` pipeline. No net-new decomposition is required at this plan phase.

---

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Explicit `permission_callback` bypass (soft conflict with D24 corollary) | Product decision: operator opt-in security-relevant toggle for MCP requests to a specific server, gated by capability + nonce + warning banner + confirm() prompt | Alternative (hook `mcp_adapter_pre_tool_call` at slot 40 as a pre-gate rather than override) rejected because it does NOT actually bypass the ability's callback — the callback still runs downstream, defeating the "bypass" contract the operator toggle promises. Additionally, DEC-F020-TOOL-ENFORCEMENT-PRIORITY warns that 4th-gate additions should trigger extraction of a `McpAdapterGateRegistry` — out of scope for F030. |
| `PermissionOverrideProcessor::boot()` inline `add_filter` (A1 partial deviation) | Mirrors sibling plugin's `AcrossAI_Ability_Override_Processor::boot()` shape verbatim; simpler than Loader-object-method wiring for a static entry point | Alternative (`$this->loader->add_filter( 'wp_register_ability_args', $processor, 'inject_override', 999999, 2 )` in `Main::define_public_hooks()`) is acceptable AND compliant with A1 strictly. Reviewer choice at implement time — both shapes ship in adjacent plugins; either compiles the same permission trace. Plan defaults to A1-strict Loader wiring UNLESS reviewer prefers the inline sibling-mirror shape. |

---

## Governance Notes

- **Constitution v1.1.0** governs this plan; no amendment triggered.
- **Memory synthesis** identified one soft conflict (D24 exposure ≠ authorization corollary). Documented; slated for post-implement capture as `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS`.
- **Security review** integrated inline (§Constitution Check §III). A dedicated `/speckit-security-review-plan` run is available if reviewers want a formal `security-constraints.md` artifact; not blocking because plan-level security posture is already captured here + in the spec's §Security Checklist.
- **Architecture-guard violation detection** run inline against constitution §I–VII: no hard drift, one soft deviation on A1 (justified in Complexity Tracking table above).
- **Next step**: `/speckit-tasks` to project the 5 engineering-brief task blocks into concrete T### IDs, then `/speckit-architecture-guard-governed-tasks` for architecture-aware task validation, then `/speckit-architecture-guard-governed-implement` for the execution loop.
