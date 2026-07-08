# Implementation Plan: Per-Server Tool Selection

**Branch**: `020-per-server-tool-selection` | **Date**: 2026-07-09 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification at `specs/020-per-server-tool-selection/spec.md`
**Memory Synthesis**: [memory-synthesis.md](memory-synthesis.md) (fresh, this session)
**Planning Doc**: [`docs/planings-tasks/020-per-server-tool-selection.md`](../../docs/planings-tasks/020-per-server-tool-selection.md)

## Summary

Retire the read-only static reference table currently rendered by `ToolsTab::render_body()` and replace it with a React-mounted **shuttle picker** that lets a site administrator curate exactly which registered WordPress abilities each MCP server exposes as callable MCP tools. Selection is stored in a new BerlinDB module `MCPServerTool` under `includes/Database/MCPServerTool/` — presence of a row for `(server_id, ability_slug)` **is** the "added" flag (no `is_exposed` boolean, no fallback layer, no ExposureResolver). A new REST controller under `acrossai-mcp-manager/v1` at `/servers/{server_id}/tools` reads (GET) and replace-all writes (POST) the set with server-side diff (transactional to guarantee last-committer-wins under concurrent saves), all-or-nothing validation, and a `acrossai_mcp_tools_changed` action per applied add/remove (fires wrapped in try/catch to isolate observer failures from the REST response). The React app uses **optimistic-per-toggle POST** — each Add / Remove / Reset click commits immediately to the server with local rollback on failure (matches F017's DataViews-grid workflow; the originally-planned explicit Save / Cancel batch commit was withdrawn post-implementation). **Runtime enforcement** rides on a `mcp_adapter_pre_tool_call` filter callback at priority 30 (F015 = 10, F017 = 20) that returns `WP_Error( 'acrossai_mcp_tool_not_added', 403 )` for slugs not present in the operator-curated set — closes SEC-020-001, mirrors F017 FR-030 shape. Server-deletion cascades by hooking BerlinDB's built-in `mcp_server_deleted` action (fired by `MCPServer\Query::delete_item()`); the new table is dropped on uninstall **below** the F012 opt-in gate. F017's `MCPServerAbility` module, `AbilitiesController`, and `src/js/abilities.js` are architecturally untouched — the one exception is a shared render-body UX change (both `AbilitiesTab` and `ToolsTab` now keep the picker editable when the server is disabled, per user request post-implementation).

## Technical Context

**Language/Version**: PHP 8.1+ (constitution target); PHP 7.4 minimum runtime supported; JavaScript ES2020+ transpiled by `@wordpress/scripts`.
**Primary Dependencies**: `berlindb/core: ^3.0.0` (already installed via F010/F011); `@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/hooks` (all already declared for F017); existing WP Abilities API (optional at runtime — graceful degradation per FR-019). **NOT** added: `@wordpress/dataviews` for this bundle (shuttle picker is not a DataViews grid — see Constitution Check §IV).
**Storage**: New custom BerlinDB table `{wpdb->prefix}acrossai_mcp_server_tools` — five columns, three indexes (PK, `UNIQUE(server_id, ability_slug)`, `KEY(server_id)`). Version option `acrossai_mcp_server_tools_db_version = 1.0.0`. Justification per constitution §Architecture: WordPress options/meta cannot efficiently model a many-to-many (server × ability) relation, particularly with the `UNIQUE(server_id, ability_slug)` correctness constraint enforced at the DB level.
**Testing**: PHPUnit (existing suite in `tests/phpunit/`) — new tests under `tests/phpunit/Database/MCPServerTool/` and `tests/phpunit/REST/ToolsControllerTest.php`. Jest (existing suite in `tests/jest/`) for the React app's Save/Cancel diff helper + safeApplyFilters boundary — new tests under `tests/jest/tools/`.
**Target Platform**: WordPress 6.9+, PHP 8.1+, InnoDB utf8mb4 MySQL 5.6+ or MariaDB equivalent. Multisite: supported (per-site table — `$global = false`).
**Project Type**: WordPress plugin (single deliverable). Uses the existing four-directory layout — `admin/Partials/`, `includes/`, `public/Partials/`, `src/`.
**Performance Goals**: SC-009 — POST save for a 20-slug tool set completes in **under 1 s** from click to UI refresh on a stock local WP install. SC-001 — site admin can add 3 tools and save in **under 30 s** starting from empty state.
**Constraints**: Bundle stays lean — grep gate on `src/js/tools.js` for external React libraries (react-query, redux, mobx, @tanstack, react-table, @mui, styled-components) MUST return zero matches per DEC-WP-DATAVIEWS-OVER-REACT's forbidden list; enqueue scoped strictly to `?page=acrossai_mcp_manager&action=edit&tab=tools` (FR-020); third-party filter failures MUST NOT white-screen the mount (safeApplyFilters boundary from F017).
**Scale/Scope**: Feature adds ~10 files (4 new BerlinDB PHP classes + 1 REST controller + 1 React entry + 1 optional SCSS + 3 test files) plus small deltas to 6 existing files. Estimated ~1,400 LOC additive across PHP + JS + docs. F017's ~1,500 LOC baseline is the closest analog.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Constitution version: **1.0.0** (ratified 2026-05-28, last amended 2026-05-29).

### Principle I — Modular Architecture — ✅ PASS (with one documented F017 exception)

- `MCPServerTool` is a self-contained BerlinDB module under `includes/Database/`, independently testable. No shared logic duplicated with F011 (four existing tables) or F017 (`MCPServerAbility`). No cross-module method calls — F020 code paths never invoke F017 code paths and vice versa.
- `ToolsController` is a self-contained REST controller under `includes/REST/`, parallel to F017's `AbilitiesController` — no inheritance, no shared abstractions between them.
- **F017 UX-parity exception (2026-07-09 post-implementation, user-authorized override of the original "zero edits to F017" constraint)**: F017's `AbilitiesTab.php` `render_body()` received the same disabled-server treatment as F020's `ToolsTab` — the warning notice no longer hides the picker; operators can pre-configure ability exposure while the server is off. Rationale: symmetric UX between the two per-server tabs is more important than the original "no F017 edits" constraint. F017's REST controller, BerlinDB module, and React bundle remain untouched.

### Principle II — WordPress Standards Compliance — ✅ PASS

- PHPCS + PHPStan level 8 + ESLint gates are itemized in `spec.md` §Definition of Done Gates. Multisite-compatible via per-site table (`$global = false`).
- All new PHP text uses text domain `acrossai-mcp-manager` per AGENTS.md.

### Principle III — Security First (NON-NEGOTIABLE) — ✅ PASS

- Both REST routes gate on `current_user_can( 'manage_options' )` (S2 / FR-021). No `__return_true`.
- REST inputs sanitized: `server_id` via `absint()`, each `ability_slug` via `sanitize_text_field()` (S1-adjacent). Explicit `args` schema on POST (`type=array`, `items.type=string`, `sanitize_callback`, `validate_callback`) rejects malformed bodies at REST middleware BEFORE controller code runs.
- POST validates every slug against `wp_get_abilities()` catalog before persisting; unknown slug rejects the whole batch (FR-022, guards against B7 mass-assignment).
- All DB access via BerlinDB's prepared layer (S4). `Query::replace_set()` wrapped in explicit `START TRANSACTION` / `COMMIT` / `ROLLBACK` (FR-030) — concurrent overlapping saves produce deterministic last-committer-wins state, not set-union superset (SEC-020-002 closure).
- No secrets stored — feature does not handle credentials.
- Nonce enforced via the standard `X-WP-Nonce` header + `apiFetch.createNonceMiddleware` (S1 via wp_rest scope).
- Server-id boundary: every REST call validates `server_id` resolves to a real row in `wp_acrossai_mcp_servers` — return 404 otherwise. Prevents cross-server data disclosure.
- Uninstall destructive teardown (`DROP TABLE` + option delete) sits **below** the F012 opt-in gate per DEC-UNINSTALL-OPT-IN-GATE (FR-028). No second gate added.
- **Runtime enforcement (FR-029)** — the SEC-020-001 gap surfaced by the plan-level security review is closed by a callback on `mcp_adapter_pre_tool_call` at **priority 30** — stacking after F015 access control (10) and F017 ability exposure (20). Deny-precedence enforced (early `WP_Error` never overridden); fail-open on unresolvable server_id (matches D19 observability + F017 shape). Protocol tools bypass (FR-025 inversion). Absence in `wp_acrossai_mcp_server_tools` returns `WP_Error( 'acrossai_mcp_tool_not_added', ..., [ 'status' => 403 ] )`. See `contracts/enforcement.md` for the full 7-scenario contract. List-time hiding of unadded abilities from `mcp/tools/list` is deferred to a follow-up feature (matches F017 shape).
- **Observer isolation (FR-031)** — each `do_action( 'acrossai_mcp_tools_changed', ... )` fire is individually wrapped in `try/catch`. Throwing observers are `error_log`'d and swallowed; REST response remains HTTP 200 after a successful DB commit. Prevents a broken mu-plugin from 500'ing a legitimate save (SEC-020-004 closure).

### Principle IV — User-Centric Design (NON-NEGOTIABLE) — ⚠ SOFT DEVIATION (logged)

- **The rendered surface is neither a `DataForm` nor a `DataViews` grid.** It is a two-column shuttle picker per the mockup at `tools-ui.zip → Tools Selection.dc.html`. Constitution IV mandates DataForm for data-entry forms and DataViews for data listings; the shuttle picker is arguably neither (it's a *selection* widget, not a listing or entry form), but the pattern language of the constitution + F017's DEC-WP-DATAVIEWS-OVER-REACT reads DataViews as the default for *any* new admin JS surface.
- **Deviation justification** (see Complexity Tracking below): (a) the mockup design is the visual contract from the user; (b) DataViews cannot express the two-panel add/remove UX without visible layout gymnastics (either two separate DataViews grids — which lose the "one screen shows both sides + moving between them" affordance — or one grid with a boolean column — which is exactly F017's pattern and destroys the shuttle metaphor); (c) the shuttle picker uses only Tier 1 `@wordpress/*` packages (element, components, api-fetch, i18n, hooks) — no external React libraries. The DEC-WP-DATAVIEWS-OVER-REACT forbidden-list grep (`react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components`) MUST return zero matches on `src/js/tools.js`. **NOTE (2026-07-09 post-implementation)**: an earlier justification leg — that Save/Cancel provided a batch-undo affordance DataViews lacks — was withdrawn when the workflow pivoted to optimistic-per-toggle POST at operator request. The surviving rationale is the two-panel-selection metaphor alone (see §Complexity Tracking row 1 for the current wording).
- **Post-implement**: capture new `DEC-TOOL-SHUTTLE-PICKER-OVER-DATAVIEWS` via `/speckit-memory-md-capture` to formalize the boundary for future non-tabular selection UX.
- **Pre-approved exceptions**: DEV1 (WP_List_Table on the MCP Manager parent menu) does NOT apply — F020 lives on a per-server tab.

### Principle V — Extensibility Without Core Modification — ✅ PASS

- Three `@wordpress/hooks` JS filters exposed for third-party decoration (`acrossaiMcpManager.tools.fields`, `.actions`, `.row`) — all wrapped in `safeApplyFilters()` so a broken third-party callback cannot white-screen the tab (FR-023-adjacent). Mirrors the F017 pattern.
- One PHP action fired per save (`acrossai_mcp_tools_changed`) with `{ server_id, ability_slug, operation }` payload — the public extensibility surface for audit logs, metrics, notifications.
- Absence of any filter/action registration leaves F020 exactly as shipped — extensibility is fully optional.
- F019's `acrossai_mcp_manager_server_tabs` filter is unchanged. The Tools tab retains slug `tools` and priority 50.

### Principle VI — Reusability & DRY — ✅ PASS

- `EXCLUDED_SLUGS` (three `mcp-adapter/*` protocol tools) is duplicated in `src/js/tools.js` and `ToolsController.php` — matches F017's `src/js/abilities.js:73` convention. A9 (shared constants → `includes/Utilities/`) is deferred because the JS-side constant cannot be sourced from PHP without a localize round trip; F017's precedent stands. If F020 or a future feature needs a third consumer, an extraction task lands then.
- Tier 1 `@wordpress/*` packages used exclusively for the React entry.
- `Admin\Main::maybe_enqueue_tools_app()` mirrors the F017 `maybe_enqueue_abilities_app()` shape one-for-one — enqueue guard, silent-bail on missing manifest, localize payload.

### Principle VII — Definition of Done — ✅ PASS (gates listed)

- All applicable DoD gates itemized in `spec.md` §Definition of Done Gates. `AGENTS.md` standards inherited.

**Constitution Check Result**: 6 principles pass. **1 soft deviation** (Principle IV — shuttle picker over DataViews) is justified in Complexity Tracking. No hard violations require re-planning.

## Project Structure

### Documentation (this feature)

```text
specs/020-per-server-tool-selection/
├── plan.md                    # This file — /speckit-plan output
├── spec.md                    # Feature spec (written 2026-07-09; SC-011..014 added post-security-review)
├── memory-synthesis.md        # Memory context (written 2026-07-09)
├── research.md                # Phase 0 output (this run)
├── data-model.md              # Phase 1 output (this run; runtime enforcement section added post-security-review)
├── quickstart.md              # Phase 1 output (this run)
├── contracts/
│   ├── rest-api.md            # Phase 1 — REST route contracts (args schema + try/catch added post-security-review)
│   ├── js-hooks.md            # Phase 1 — @wordpress/hooks filter contracts + PHP action
│   └── enforcement.md         # Phase 1 — mcp_adapter_pre_tool_call callback contract (FR-029, SEC-020-001 closure)
├── checklists/
│   └── requirements.md        # Written during /speckit-specify
└── tasks.md                   # Phase 2 output (/speckit-tasks — NOT written by this command)
```

### Source Code (repository root)

WordPress plugin — single deliverable using the existing four-directory layout. F020 files marked with `**`:

```text
admin/
├── Main.php                   # ** DELTA — add maybe_enqueue_tools_app() + wire into enqueue_scripts()
└── Partials/
    └── ServerTabs/
        └── ToolsTab.php       # ** DELTA — rewrite render_body(); delete get_core_tools() + render_tools_table()

includes/
├── Main.php                   # ** DELTA — +1 bootstrap line + +2 REST wiring lines + +1 mcp_server_deleted cascade wire + +1 mcp_adapter_pre_tool_call priority-30 wire
├── Activator.php              # ** DELTA — +1 line (Table maybe_upgrade)
├── Database/
│   └── MCPServerTool/         # ** NEW MODULE — 4 files
│       ├── Schema.php
│       ├── Table.php
│       ├── Query.php          # transactional replace_set + get_added_slugs + delete_items_for_server
│       └── Row.php
├── REST/
│   └── ToolsController.php    # ** NEW — GET + POST on /servers/{id}/tools + try/catch observer isolation
├── MCP/
│   └── ToolExposureGate.php   # ** NEW — mcp_adapter_pre_tool_call filter callback (FR-029, SEC-020-001 closure)
└── Utilities/
    └── (none — EXCLUDED_SLUGS duplicated per F017 precedent, extraction deferred)

src/
├── js/
│   └── tools.js               # ** NEW — React shuttle-picker entry
└── scss/
    └── tools.scss             # ** NEW (optional — inline styles per mockup may suffice)

tests/
├── phpunit/
│   ├── Database/
│   │   └── MCPServerTool/     # ** NEW — QueryReplaceSetTest (+ concurrent-race test for FR-030) + PhantomVersionGuardTest
│   ├── MCP/
│   │   └── ToolExposureGateTest.php  # ** NEW — 7 scenarios per contracts/enforcement.md §Test Coverage
│   └── REST/
│       └── ToolsControllerTest.php  # ** NEW — auth, 404, 400, GET, POST, action-fire counters, observer-throws-swallowed
└── jest/
    └── tools/                 # ** NEW — diffDraftAgainstAdded + safeApplyFilters tests

# Delta-only files:
webpack.config.js              # ** DELTA — +1 entry line ('js/tools')
uninstall.php                  # ** DELTA — +2 lines (DROP TABLE + delete_option) AFTER opt-in gate
README.txt                     # ** DELTA — Unreleased changelog bullet
docs/memory/{DECISIONS,WORKLOG,INDEX}.md  # ** DELTA — 2 new DEC-* + 1 WORKLOG row (captured post-implement)
docs/planings-tasks/README.md  # ** DELTA — append row 020
```

**Structure Decision**: Single WordPress-plugin repo. F020 adds one new BerlinDB module directory (`includes/Database/MCPServerTool/`) alongside F011's four modules and F017's `MCPServerAbility`. React entry lives at `src/js/tools.js` — mirrors the F017 `src/js/abilities.js` enqueue shape one-for-one. No new top-level directories. Everything else is a small delta to existing files (Main.php x2, Activator.php, ToolsTab.php, admin/Main.php, webpack.config.js, uninstall.php), keeping the change surface auditable.

## Complexity Tracking

One soft deviation from Principle IV / DEC-WP-DATAVIEWS-OVER-REACT is justified below. No hard violations.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Shuttle-picker UX not using `@wordpress/dataviews` | The mockup at `tools-ui.zip → Tools Selection.dc.html` defines a two-column selection widget where operators visualize both sides simultaneously — "available on the left, curated on the right" — and move rows across the boundary. This is a *selection* affordance for a set-membership decision, not a *listing* affordance for a record-inspection decision. The two-panel spatial metaphor is the core UX value; DataViews cannot express it. **NOTE (2026-07-09 post-implementation)**: the earlier "explicit Save changes / Cancel undo affordance" rationale was withdrawn when the workflow pivoted to optimistic-per-toggle POST at operator request; the surviving rationale is the two-panel-selection affordance itself. | (a) Two separate `DataViews` grids — loses the "one screen shows both sides + moving between them" affordance; the picker becomes two disconnected tables and the shuttle metaphor is destroyed. (b) One `DataViews` grid with a boolean-toggle column — this is exactly F017's pattern (`src/js/abilities.js`); after the workflow pivot (FR-009 rewrite) F020 is functionally equivalent to F017 in commit semantics, but the visual "left column + right column + move-across" metaphor is materially distinct from F017's row-toggle grid and is the reason F020 exists as a separate feature. |
| Duplicating `EXCLUDED_SLUGS` in `src/js/tools.js` and `ToolsController.php` (DRY tension with A9) | JS-side constant cannot be sourced from PHP without a localize round trip (and that would mean the JS side depends on a PHP-defined value that changes per page load — worse coupling than duplication). F017 already made this trade-off; F020 mirrors it. | Extracting to `includes/Utilities/ExcludedSlugs.php` and localizing to JS — adds one file, one localize entry, one build-time constant surface, and creates a fake abstraction. F017 established the convention; changing it now would require F017 refactoring too, which is explicitly out of scope. |

## Phase 0 → Phase 1 Artifacts

- **Phase 0 — Research** (`research.md`): all Technical Context choices reviewed for open questions; every candidate `NEEDS CLARIFICATION` was resolved during `/speckit-clarify` (3 clarifications, 3 new FRs). Zero unknowns remain. See `research.md` for the resolution log.
- **Phase 1 — Design** (`data-model.md`, `contracts/`, `quickstart.md`): produced in this same command run — see the sibling files under `specs/020-per-server-tool-selection/`.
- **Agent context update**: no `CLAUDE.md` exists at the plugin root; nothing to update between `<!-- SPECKIT START -->` and `<!-- SPECKIT END -->` markers. The plan is discoverable via `specs/020-per-server-tool-selection/plan.md`. Recorded in the governance summary.

## Next Command

`/speckit-tasks` (produces `tasks.md` from this plan). Governed variant used by the orchestrating command: `/speckit-architecture-guard-governed-tasks`.
