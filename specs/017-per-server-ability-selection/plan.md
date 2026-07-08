# Implementation Plan: Per-server Ability Selection

**Branch**: `017-per-server-ability-selection` | **Date**: 2026-07-07 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification at `specs/017-per-server-ability-selection/spec.md`
**Memory Synthesis**: [memory-synthesis.md](memory-synthesis.md) (fresh, this session)
**Planning Doc**: [`docs/planings-tasks/017-per-server-ability-selection.md`](../../docs/planings-tasks/017-per-server-ability-selection.md)

## Summary

Add per-server exposure overrides for WordPress Abilities: a new BerlinDB module (`MCPServerAbility`) stores rows keyed by `(server_id, ability_slug)`; a new REST controller under `acrossai-mcp-manager/v1` reads + writes them; the read-only `Abilities` tab body is replaced by a `@wordpress/dataviews`-driven React app; and companion plugins (e.g. `acrossai-abilities-manager`) can extend the table with columns and per-row actions via `@wordpress/hooks` filters + one PHP row filter — with defensive boundaries that swallow third-party failures without white-screening the tab. Absence of a row falls back to the ability's own `meta[mcp][public]` — existing installs upgrade with zero visible behavior change. **Enforcement** at the MCP tool-call boundary rides on a new callback on `mcp_adapter_pre_tool_call` at priority 20 (SEC-001 closure — added 2026-07-07 Q4); list-time hiding of abilities from `mcp/tools/list` is deferred to a follow-up feature.

## Technical Context

**Language/Version**: PHP 8.1+ (constitution target); PHP 7.4 minimum runtime supported; JavaScript ES2020+ transpiled by `@wordpress/scripts`.
**Primary Dependencies**: `berlindb/core: ^3.0.0` (already installed via F010); `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/hooks`; existing WP Abilities API (optional at runtime — graceful degradation per FR-014/FR-015).
**Storage**: New custom BerlinDB table `{wpdb->prefix}acrossai_mcp_server_abilities` — six columns, three indexes (PK, `UNIQUE(server_id, ability_slug)`, `KEY(server_id)`). Version option `acrossai_mcp_server_abilities_db_version = 1.0.0`. Justification per constitution §Architecture: options/meta cannot model the two-dimensional (server × ability) relation efficiently.
**Testing**: PHPUnit (existing suite in `tests/phpunit/`) — new tests under `tests/phpunit/Database/MCPServerAbility/` and `tests/phpunit/REST/AbilitiesControllerTest.php`. Jest (existing suite in `tests/jest/`) for the React app's reducers + safeApplyFilters boundary — new tests under `tests/jest/abilities/`.
**Target Platform**: WordPress 6.9+, PHP 8.1+, InnoDB utf8mb4 MySQL 5.6+ or MariaDB equivalent. Multisite: supported (per-site table — `$global = false`).
**Project Type**: WordPress plugin (single deliverable). Uses the existing four-directory layout — `admin/Partials/`, `includes/`, `public/Partials/`, `src/`.
**Performance Goals**: SC-004 — GET returns merged list for 100 abilities in **under 1s** on a stock local WP install. SC-005 — bulk POST for 20 abilities in **one round-trip**.
**Constraints**: Bundle stays lean — no generic React libraries (SC-008 grep gate); enqueue scoped strictly to `?action=edit&tab=abilities` (SC-007); third-party filter failures MUST NOT white-screen (SC-011).
**Scale/Scope**: Feature adds ~11 files (5 new PHP classes + 1 new React entry + 1 new PHP-side filter site + 6 delta files). Estimated ~1,500 LOC additive across PHP + JS + docs.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Constitution version: **1.0.0** (ratified 2026-05-28, last amended 2026-05-29).

### Principle I — Modular Architecture — ✅ PASS
- `MCPServerAbility` is a self-contained module under `includes/Database/`, independently testable, with a stateless `ExposureResolver` service. No shared logic duplicated with F015 Access Control paths.

### Principle II — WordPress Standards Compliance — ✅ PASS
- PHPCS + PHPStan level 8 + ESLint gates are itemized in `spec.md` §Definition of Done and `docs/planings-tasks/017-...md` §Quality gates. Multisite-compatible via per-site table (`$global = false`).

### Principle III — Security First (NON-NEGOTIABLE) — ✅ PASS
- Both REST routes gate on `current_user_can( 'manage_options' )` (S2 / FR-012). No `__return_true`.
- REST inputs sanitized: `server_id` via `absint()`, `ability_slug` via `sanitize_text_field()`, `is_exposed` cast `(bool)` (FR-022).
- All DB access via BerlinDB's prepared layer (S4).
- No secrets stored — feature does not handle credentials.
- Nonce enforced via the standard `X-WP-Nonce` header + `apiFetch.createNonceMiddleware` (S1 via wp_rest scope).
- FR-027 (PHP `acrossai_mcp_ability_row` filter) was RETIRED Session 2026-07-08. Server-side row-filter reintroduction is tracked in a separate issue; the current F017 shipping surface is JS-only.
- **Call-time enforcement (FR-030)** — the SEC-001 gap surfaced by the plan-level security review is closed by a callback on `mcp_adapter_pre_tool_call` at priority 20 (F015 = 10). Deny-precedence enforced (early `WP_Error` never overridden); fail-open on unresolvable server id (matches D19 observability). List-time hiding is a documented follow-up (spec §Assumptions).

### Principle IV — User-Centric Design (NON-NEGOTIABLE) — ✅ PASS
- Interactive tab uses **`DataViews`** for the table (FR-016; A4). No custom table HTML.
- The tab is NOT a data-entry form — no `DataForm` needed; the mockup 1A UI is a listing + toggle surface.
- Falls under the `AbstractServerTab` template contract established by F013 (DEC-SERVER-TAB-CLASS-HIERARCHY); the pre-approved MCP Manager parent-menu `WP_List_Table` exception does NOT apply to F017.

### Principle V — Extensibility Without Core Modification — ✅ PASS (explicitly targeted)
- FR-026, FR-028, FR-029 are the concrete implementation of this principle for the Abilities tab. Three `@wordpress/hooks` JS filters + one PHP action (`acrossai_mcp_ability_exposure_changed`, FR-024) form the entire extension surface. (FR-027 PHP row-filter RETIRED Session 2026-07-08; server-side row-filter reintroduction tracked separately.)
- Companion plugin integrations remain optional and degrade gracefully: absence of any filter registration leaves the tab exactly as F017 ships it.

### Principle VI — Reusability & DRY — ✅ PASS
- `ExposureResolver::resolve()` is the single source of truth for effective exposure (DEC-ABILITY-OVERRIDE-RESOLUTION target).
- Tier 1 `@wordpress/*` packages used exclusively for the React entry (FR-016 explicitly forbids Tier 2+ generic React libs).
- `Admin\Main::maybe_enqueue_abilities_app()` mirrors the F015 `maybe_enqueue_access_control_app()` shape — no logic duplicated.

### Principle VII — Definition of Done — ✅ PASS (gates listed)
- All applicable DoD gates itemized in `spec.md` §Definition of Done Gates. `AGENTS.md` standards inherited.

**Constitution Check Result**: All 7 principles pass. **No violations require a Complexity Tracking entry.**

## Project Structure

### Documentation (this feature)

```text
specs/017-per-server-ability-selection/
├── plan.md                    # This file — /speckit-plan output
├── spec.md                    # Feature spec (already written)
├── memory-synthesis.md        # Memory context (fresh)
├── research.md                # Phase 0 output (this run)
├── data-model.md              # Phase 1 output (this run)
├── quickstart.md              # Phase 1 output (this run)
├── contracts/
│   ├── rest-api.md            # Phase 1 — REST route contracts
│   └── js-hooks.md            # Phase 1 — @wordpress/hooks filter contracts
├── checklists/
│   └── requirements.md        # Written during /speckit-specify
└── tasks.md                   # Phase 2 output (/speckit-tasks — NOT written by this command)
```

### Source Code (repository root)

WordPress plugin — single deliverable using the existing four-directory layout. F017 files marked with `**`:

```text
admin/
├── Main.php                   # ** DELTA — add maybe_enqueue_abilities_app()
└── Partials/
    └── ServerTabs/
        └── AbilitiesTab.php   # ** DELTA — replace render_body() body only

includes/
├── Main.php                   # ** DELTA — +1 line bootstrap + REST wiring
├── Activator.php              # ** DELTA — +1 line (Table maybe_upgrade)
├── Database/
│   └── MCPServerAbility/      # ** NEW MODULE — 5 files
│       ├── Schema.php
│       ├── Table.php
│       ├── Query.php
│       ├── Row.php
│       └── ExposureResolver.php
├── REST/
│   └── AbilitiesController.php  # ** NEW
└── MCP/
    └── AbilityExposureGate.php  # ** NEW — TASK-10 call-time enforcement callback (final placement decided at implementation; may fold into AbilitiesController instead)

src/
├── js/
│   └── abilities.js           # ** NEW — React app entry
└── scss/
    └── abilities.scss         # ** NEW (optional — omit if DataViews layout suffices)

tests/
├── phpunit/
│   ├── Database/
│   │   └── MCPServerAbility/  # ** NEW — Schema/Table/Query/Row/Resolver unit tests
│   └── REST/
│       └── AbilitiesControllerTest.php  # ** NEW — auth, 404, 400, upsert paths
└── jest/
    └── abilities/             # ** NEW — safeApplyFilters + reducer tests

docs/
└── extending-abilities-tab.md # ** NEW — companion-plugin author guide

# Delta-only files:
webpack.config.js              # ** DELTA — +1 entry line under `entry:`
uninstall.php                  # ** DELTA — +1 DROP TABLE line AFTER opt-in gate
package.json                   # ** DELTA — declare @wordpress/hooks if missing
README.txt                     # ** DELTA — Unreleased changelog bullet
docs/memory/{DECISIONS,WORKLOG,INDEX}.md  # ** DELTA — 2 new DEC-* + 1 WORKLOG row
docs/planings-tasks/README.md  # ** DELTA — append row 017
```

**Structure Decision**: Single WordPress-plugin repo. F017 adds one new BerlinDB module directory (`includes/Database/MCPServerAbility/`) and one new REST controller alongside the F011/F013 modules. React entry lives at `src/js/abilities.js` — mirrors the F015 `src/js/access-control.js` shape one-for-one. No new top-level directories. Everything else is a small delta to existing files, keeping the change surface auditable.

## Complexity Tracking

No constitution violations. Complexity table intentionally empty — every design choice traces to an active decision or constitutional principle documented in `memory-synthesis.md`.

## Phase 0 → Phase 1 Artifacts

- **Phase 0 — Research** (`research.md`): all Technical Context choices reviewed for open questions; every candidate `NEEDS CLARIFICATION` was resolved during `/speckit-clarify` (3 clarifications, 5 new FRs). Zero unknowns remain.
- **Phase 1 — Design** (`data-model.md`, `contracts/`, `quickstart.md`): produced in this same command run — see the sibling files under `specs/017-per-server-ability-selection/`.
- **Agent context update**: no `CLAUDE.md` exists at the plugin root; nothing to update between SPECKIT markers. The plan is discoverable via `specs/017-per-server-ability-selection/plan.md`. This is recorded in the governance summary.

## Next Command

`/speckit-tasks` (produces `tasks.md` from this plan). Governed variant: `/speckit-architecture-guard-governed-tasks`.
