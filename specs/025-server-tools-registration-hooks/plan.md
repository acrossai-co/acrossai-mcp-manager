# Implementation Plan: Wire per-server tool selection into MCP registration + let operators remove built-in defaults

**Branch**: `025-server-tools-registration-hooks` | **Date**: 2026-07-13 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/025-server-tools-registration-hooks/spec.md`
**Companion docs**: [memory-synthesis.md](./memory-synthesis.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md) · [pre-drafted planning doc](../../docs/planings-tasks/023-server-tools-registration-hooks.md)

## Summary

Close the loop between F020's Tools tab UI and MCP-adapter server registration by moving each server's advertised tool list to a hybrid DB-authoritative model: three `tinyint(1) NOT NULL DEFAULT 1` columns on `wp_acrossai_mcp_servers` for the fixed protocol tool set, plus F020's existing presence-based `wp_acrossai_mcp_server_tools` rows for the open-ended curated set. A new stateless helper `ToolPolicy::compose_for_row()` unions the two storage layers into the single canonical tool list; both the vendor `mcp_adapter_default_server_config` filter callback (default-server path) and `Controller::register_database_servers()` (database-server path) read through it before passing tools to `$adapter->create_server()`. A new plugin-owned filter `acrossai_mcp_manager_server_tools` fires on the database-server path so companion plugins can add or remove tools. The Tools tab UI is rewritten so the three built-in defaults render with a `Remove` button (gated by a `@wordpress/components` `ConfirmDialog`), and the `Reset` button POSTs a payload that flips all three column flags back to `1` and clears every curated row via `MCPServerToolQuery::replace_set()` in one round-trip. The schema change ships via BerlinDB's `maybe_upgrade()` on the next request-time boot after `MCPServer\Table::$version` bumps from `1.0.0` to `1.1.0` — the `ALTER TABLE ADD COLUMN ... DEFAULT 1` IS the migration; no separate backfill helper is written.

## Technical Context

**Language/Version**: PHP 8.0+ (plugin baseline supports PHP 7.4 minimum, but Feature 025 does not exercise 7.4-blocking syntax; JS is transpiled by `@wordpress/scripts` to browser-baseline).
**Primary Dependencies**: `berlindb/core` ^3.0.0 (via composer); `wordpress/mcp-adapter` (vendored); `@wordpress/components` (`ConfirmDialog`, `Button`); `@wordpress/api-fetch`; `@wordpress/element`; `@wordpress/i18n`.
**Storage**: WordPress `$wpdb` via BerlinDB Kern layer. Two tables involved:
- `wp_acrossai_mcp_servers` (existing, F011) — schema-extended by F025 with three new `tinyint(1) NOT NULL DEFAULT 1` columns.
- `wp_acrossai_mcp_server_tools` (existing, F020) — schema unchanged; F025 reuses its presence-based model verbatim.
**Testing**: PHPUnit for PHP (BerlinDB-aware bootstrap already established by F011); Jest for JS is not exercised in F025 because `src/js/tools.js` is edited by unit-level touch only — end-to-end coverage is via manual smoke plus the F020 REST integration tests updated for the new payload shape.
**Target Platform**: WordPress 6.9+ single-site admin (multisite out of scope per plugin baseline). Modern evergreen browsers for the Tools tab UI (Chrome/Firefox/Safari/Edge current + one back).
**Project Type**: WordPress plugin, single project (no separate frontend/backend split — plugin ships PHP + a compiled JS bundle).
**Performance Goals**: One request-cycle from operator Save to next `tools/list` response reflecting the change (no additional cache layer; `ToolExposureGate`'s per-request cache is flushed on save per F020's existing wiring).
**Constraints**: Existing installs on upgrade MUST retain all three built-in defaults enabled by default (the `DEFAULT 1` on the ALTER is the sole mechanism); the schema-version bump on `MCPServer\Table` must not require a plugin-version bump, since BerlinDB's `maybe_upgrade()` triggers on the option-vs-property mismatch alone.
**Scale/Scope**: Small — one server row per registered MCP server (typically 1–20 per install); presence rows total O(picks × servers), typically < 200. No scale change from F020.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Constitution v1.1.0 (ratified 2026-05-28, last amended 2026-07-12).

| Principle | Gate | Status | Notes |
|---|---|---|---|
| **I. Modular Architecture** | Single-purpose module, no cross-module coupling, shared logic in `includes/Utilities/` | **PASS** | F025 extends two existing modules (`Database/MCPServer`, `MCP`) plus one existing REST controller (`REST/ToolsController`). New `Database/MCPServer/ToolPolicy.php` is stateless per A11 — mirrors `DefaultServerSeeder`. No new `Utilities/` entries needed. |
| **II. WordPress Standards Compliance** | WPCS strict, PHPStan L8, ESLint clean, WP 6.9+ / PHP 8.1+, multisite unless justified | **PASS** *(with scoped exception)* | New PHP is namespaced per the plugin convention. Schema change is a MINOR BerlinDB version bump (`1.0.0` → `1.1.0`). Multisite remains out-of-scope inheriting the plugin's single-site baseline (documented in spec §Assumptions). |
| **III. Security First** | Sanitization, escaping, nonces, capability checks, prepared statements, `permission_callback`, hashed secrets | **PASS** | No new REST routes; POST/GET `permission_callback` retained. New column writes go through BerlinDB's `MCPServerQuery::update_item()` which prepares. Tools tab nonces inherited from F020. New tinyint flags sanitized via `absint()` at the REST boundary before the `update_item()` call. No secret handling in this feature. |
| **IV. User-Centric Design** | New admin UI uses DataForm/DataViews unless pre-approved exception | **PASS** *(existing surface, not new)* | F025 does NOT create a new admin surface — it edits the existing Tools tab (F020) whose two-pane layout was accepted at F020. The `Remove` + `ConfirmDialog` addition uses `@wordpress/components` primitives, aligned with DEC-WP-DATAVIEWS-OVER-REACT. No new Principle-IV exception is created. |
| **V. Extensibility Without Core Modification** | Actions/filters/extension points; graceful degradation for optional integrations | **PASS** | F025 adds the new plugin-owned filter `acrossai_mcp_manager_server_tools` (per FR-008) and consumes the vendor's `mcp_adapter_default_server_config` filter (per FR-009). Vendor MCP adapter files under `vendor/wordpress/mcp-adapter/` are untouched. |
| **VI. Reusability & DRY** | Shared logic centralized; `@wordpress/*` first, npm second; `validate-packages` runs pre-commit | **PASS** | `ToolPolicy::PROTOCOL_TOOLS` is the single canonical PHP source for the three slugs (per FR-015 + memory constraint). `ToolPolicy::compose_for_row()` is the sole composer — both server-registration paths + the REST GET handler route through it (mirrors F017's `ExposureResolver::resolve()` per DEC-ABILITY-OVERRIDE-RESOLUTION). No new npm dependencies. |
| **VII. Definition of Done** | PHPCS / PHPStan L8 / ESLint / security / tests / DataForm / DRY / prefix / AGENTS.md / validate-packages | **PASS at gate; implementation must deliver** | All gates addressable at implementation time; spec §Success Criteria enumerates them. |

**Post-check verdict**: No unjustified violations. **One soft conflict** carried forward from memory synthesis (documented in Complexity Tracking below): `DEC-TOOL-SELECTION-PRESENCE-MODEL` (F020) prefers presence-based storage for two-state set-membership; F025 uses boolean columns for the fixed protocol set. Justified via the DEC's own boolean-with-fallback carve-out precedent (F017's `ExposureResolver`), the fixed cardinality-3 nature of the protocol set, and the atomic-Reset requirement.

## Project Structure

### Documentation (this feature)

```text
specs/025-server-tools-registration-hooks/
├── plan.md                       # This file
├── spec.md                       # Feature specification
├── memory-synthesis.md           # Durable-memory constraints
├── research.md                   # Phase 0 output
├── data-model.md                 # Phase 1 output
├── quickstart.md                 # Phase 1 output
├── contracts/                    # Phase 1 output
│   ├── filter-acrossai_mcp_manager_server_tools.md
│   └── rest-tools-endpoint-semantics.md
├── checklists/
│   └── requirements.md           # Spec-quality checklist (already produced)
└── tasks.md                      # Phase 2 output — NOT produced by /speckit-plan
```

### Source Code (repository root)

Feature 025 is a WordPress plugin — single project. Real paths (not template placeholders):

```text
acrossai-mcp-manager/
├── includes/
│   ├── Database/
│   │   └── MCPServer/
│   │       ├── Schema.php               # MODIFY — append 3 tinyint(1) columns after server_version, before created_at
│   │       ├── Table.php                # MODIFY — bump $version '1.0.0' → '1.1.0'
│   │       ├── Row.php                  # MODIFY — 3 new public $tool_* int properties; ctor int-casts; to_array() adds 3 keys
│   │       └── ToolPolicy.php           # NEW — stateless helper: PROTOCOL_TOOLS, COLUMN_MAP, compose_for_row(), split_payload()
│   ├── MCP/
│   │   └── Controller.php               # MODIFY — delete inline protocol-tools literal; register_database_servers() calls ToolPolicy::compose_for_row + fires new filter; add filter_default_server_config() method
│   ├── REST/
│   │   └── ToolsController.php          # MODIFY — delete EXCLUDED_SLUGS + guard; post_tools() calls ToolPolicy::split_payload + writes both layers; get_tools() reads via ToolPolicy::compose_for_row
│   └── Main.php                         # MODIFY — one new $this->loader->add_filter( 'mcp_adapter_default_server_config', ... ) line in define_admin_hooks() after the F009 initialize_adapter wiring
├── src/
│   └── js/
│       └── tools.js                     # MODIFY — delete EXCLUDED_SLUGS; keep BUILTIN_ABILITIES + '#fef7e0'/'#8a6d00' color; render Remove on protocol tools; @wordpress/components ConfirmDialog for remove-protocol + Reset flows; new empty-state warning banner (FR-017); Reset POSTs the 3 protocol slugs; count text tweak
├── docs/
│   └── extending-server-tools.md        # NEW — filter-authors doc (FR-014)
└── tests/
    └── phpunit/
        ├── Database/MCPServer/
        │   └── ToolPolicyTest.php       # NEW — 8 cases per spec §TASK-8
        ├── MCP/
        │   └── ControllerToolsInjectionTest.php  # NEW — 8 cases per spec §TASK-8
        └── REST/
            └── ToolsControllerTest.php  # MODIFY (existing) — remove 400-for-protocol assertion; add split-and-compose assertions
```

**Structure Decision**: Single-project WordPress plugin layout, per the plugin's baseline (§Architecture & UI Standards in the Constitution). No new top-level directories. Every touched path already exists except `includes/Database/MCPServer/ToolPolicy.php`, `docs/extending-server-tools.md`, and the two new test files.

## Complexity Tracking

> Fill ONLY if Constitution Check has violations that must be justified.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| **Hybrid tool storage** — boolean columns for the 3 protocol slugs alongside F020's presence rows for curated abilities, deviating from `DEC-TOOL-SELECTION-PRESENCE-MODEL`'s "presence-based when UX has two states" preference | (1) Backwards compat mechanism is `DEFAULT 1` on the ALTER — no backfill helper, no `INSERT IGNORE` per-server. MySQL populates every existing row atomically during the ALTER. (2) The protocol set has fixed cardinality 3 with a well-known slug list; presence-based storage would need three reserved slugs in the open-ended curated table, diluting its semantic. (3) `Reset` needs to atomically flip all three back to enabled while touching zero curated rows — one `UPDATE` vs three `DELETE+INSERT` round-trips. (4) The curated half stays presence-based per F020 — the deviation is scoped to the fixed set. | Presence-based (F020 pattern) for protocol tools — rejected because (a) the operator-facing behavior "the three built-in defaults ship enabled" would require the Activator to seed three rows per server row on every activation, complicating the F011 self-healing invariant; (b) the F020 UNIQUE index on `(server_id, ability_slug)` would still fire correctly, but there's no natural way to prevent a companion plugin from writing arbitrary "protocol" slugs into the same column (loss of type safety); (c) the DEC's own carve-out explicitly cites F017's boolean-with-resolver pattern when a fallback layer exists — `DEFAULT 1` on the ALTER IS the fallback. |

Post-plan action: capture as a new decision `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` **supplementing (not superseding)** `DEC-TOOL-SELECTION-PRESENCE-MODEL`, per D22's fold-in convention. Deferred until AFTER the security-review + architecture-guard passes complete, per the F020 WORKLOG lesson: "defer UX-facing ADR capture until AFTER security-review runs cleanly, not pre-plan speculatively".

## Post-Design Constitution Re-Check

*Re-evaluate after Phase 1 artifacts (`data-model.md`, `contracts/`, `quickstart.md`) are drafted below.*

- **Principle I** — Design keeps single-purpose scope per module. `ToolPolicy` is co-located with the module whose row it composes for (`Database/MCPServer/`), not scattered into a Utilities entry (per Constitution's A11 pure-service exception; no cross-module utility introduced). **PASS**.
- **Principle II** — Schema entries in `data-model.md` map 1:1 to BerlinDB `$columns`; PHPStan-friendly declarations required for the three new Row properties (int with default 1). **PASS**.
- **Principle III** — Contract for `POST /tools` now accepts any slug; the deleted `EXCLUDED_SLUGS` guard was a validation rule, not authorization — `permission_callback` retained. The new column update path uses `absint()` at the REST boundary before hitting `MCPServerQuery::update_item()` (which prepares under the hood). **PASS**.
- **Principle IV** — The `ConfirmDialog` addition to `src/js/tools.js` uses `@wordpress/components` — no new npm dependency, no Principle-IV violation. **PASS**.
- **Principle V** — Filter contract in `contracts/filter-acrossai_mcp_manager_server_tools.md` demonstrates the extensibility surface; the vendor's `mcp_adapter_default_server_config` filter is consumed without modification. **PASS**.
- **Principle VI** — `ToolPolicy::PROTOCOL_TOOLS` is the single canonical PHP source for the three slugs; F025 will delete the pre-existing inline literal in `Controller.php` (currently lines 151-153) and the mirroring literal in `ToolsController::EXCLUDED_SLUGS`. Grep gate at implementation time: `grep -rn "mcp-adapter/discover-abilities" includes/` returns exactly one match, inside `ToolPolicy`. **PASS**.
- **Principle VII** — Definition-of-Done gates enumerated in spec §Success Criteria and applied at implementation. **PASS**.

No new violations introduced by the Phase 1 design.

## Phases

### Phase 0 — Outline & Research *(complete — see [`research.md`](./research.md))*

The three-round plan-clarification conversation (2026-07-13) plus `/speckit-clarify` Q1 (observability event routing) and Q2 (empty-tool-list UX) resolved every `[NEEDS CLARIFICATION]` before this plan was drafted. `research.md` records the decisions taken and the alternatives considered, with sources.

### Phase 1 — Design & Contracts *(complete — see [`data-model.md`](./data-model.md), [`contracts/`](./contracts/), [`quickstart.md`](./quickstart.md))*

Artifacts produced:

- **`data-model.md`** — Schema deltas on `wp_acrossai_mcp_servers` (three new columns) and `MCPServer\Row` (three new public int properties + `to_array()` extension); zero-delta callout on `wp_acrossai_mcp_server_tools`; description of the composed tool list as the runtime primary object with its two storage-layer sources; migration mechanism (ALTER-via-`maybe_upgrade`); race-window note on the two-write POST path.
- **`contracts/filter-acrossai_mcp_manager_server_tools.md`** — Signature, invocation site, argument shape, return contract, defensive normalization; interaction with the vendor `mcp_adapter_default_server_config` filter for the default-server path.
- **`contracts/rest-tools-endpoint-semantics.md`** — GET/POST request/response shape (unchanged from F020 on the wire); internal split of the POST payload across the two storage layers; internal compose of the GET response; F020 EXCLUDED_SLUGS-guard removal.
- **`quickstart.md`** — Reviewer walkthrough: enable a server, pick abilities, hit the endpoint, remove a built-in default, verify the change, click Reset, verify defaults restored + curated cleared, hook the new filter from a scratch plugin, verify the effect.

### Phase 2 — Task Generation *(deferred to `/speckit-tasks`)*

Not produced by `/speckit-plan`. `docs/planings-tasks/023-server-tools-registration-hooks.md` §Speckit Workflow already lists TASK-1 through TASK-10 in a form that `/speckit-tasks` can consume with minor normalization.

## Agent Context Update

`CLAUDE.md` at the plugin root does not currently carry `<!-- SPECKIT START -->` / `<!-- SPECKIT END -->` markers — the plugin's agent-context file is `AGENTS.md`, which `CLAUDE.md` `@`-references. No pointer update is required; the active plan file is discoverable via `.specify/feature.json` (`{"feature_directory": "specs/025-server-tools-registration-hooks"}`), which points to this `plan.md`.
