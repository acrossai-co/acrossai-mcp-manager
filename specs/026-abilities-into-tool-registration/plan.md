# Implementation Plan: Include F017-effective abilities in the composed tool list at server-registration time

**Branch**: `026-abilities-into-tool-registration` | **Date**: 2026-07-14 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/026-abilities-into-tool-registration/spec.md`
**Companion docs**: [memory-synthesis.md](./memory-synthesis.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md) · [pre-drafted planning doc](../../docs/planings-tasks/026-abilities-into-tool-registration.md)

## Summary

Close the gap between F017's per-server ability visibility overrides (Abilities tab at `?tab=abilities`) and MCP server tool advertising. Today, F017's visibility state is enforced only at call-time by `AbilityExposureGate` on `mcp_adapter_pre_tool_call` priority 20 — it never affects what the server registers as tools via F025's `ToolPolicy::compose_for_row()` pipeline. F026 closes that gap by introducing a new stateless helper `ToolPolicy::compose_effective_tools_for_row( Row ): string[]` that returns a superset of `compose_for_row()` extended with every ability where `ExposureResolver::resolve( $server_id, $slug, $meta )` returns true. Two F025 call sites in `Controller` (`register_database_servers()` at line 142 + `filter_default_server_config()` at line 247) swap to the new method; the REST GET at `ToolsController::get_tools()` line 201 keeps calling `compose_for_row()` unchanged so the Tools tab UX stays scoped to the operator's explicit picks. The F025 filter `acrossai_mcp_manager_server_tools` is reused with a widened pre-filter composed set — same signature, no new hook, no breaking change for companion plugins. Fail-open when `wp_get_abilities()` is unavailable.

## Technical Context

**Language/Version**: PHP 8.0+ (plugin baseline; F026 does not exercise 8.1+ syntax).
**Primary Dependencies**: WordPress Abilities API (`wp_get_abilities`, `wp_register_ability`, `wp_get_ability`) bundled since WP 6.9; existing F017 stack (`ExposureResolver`, `MCPServerAbility\Query`); F025's `ToolPolicy`. No new composer or npm dependencies.
**Storage**: Reads only. `wp_acrossai_mcp_server_abilities` (F017, unchanged) via `MCPServerAbility\Query::instance()->query()` inside `ExposureResolver::resolve()`. `wp_acrossai_mcp_server_tools` (F020, unchanged). `wp_acrossai_mcp_servers` (F011 + F025, unchanged).
**Testing**: PHPUnit — extend `tests/phpunit/Database/MCPServer/ToolPolicyTest.php` (+4 cases) and `tests/phpunit/MCP/ControllerToolsInjectionTest.php` (+1 case). Cache reset via `ExposureResolver::_reset_cache_for_tests()` in `setUp()`.
**Target Platform**: WordPress 6.9+ single-site admin (multisite out of scope per plugin baseline).
**Project Type**: WordPress plugin, single project.
**Performance Goals**: One request-cycle from operator ability-tab toggle to next `tools/list` reflecting the change. `ExposureResolver` per-request static cache keeps the O(N_abilities) iteration cheap after the first pass.
**Constraints**: No schema change. No new hooks. No REST route or JS changes. Fail-open when Abilities API unavailable. F017 storage layer and resolver are read-only from F026's perspective — F026 CONSUMES them via the canonical `ExposureResolver::resolve()` per `DEC-ABILITY-OVERRIDE-RESOLUTION`.
**Scale/Scope**: Typical install: 1–20 servers × < 200 abilities = < 4000 resolver calls per REST request. First call is O(1 BerlinDB query); subsequent calls in the same request hit the per-request static cache.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Constitution v1.1.0 (ratified 2026-05-28, last amended 2026-07-12).

| Principle | Gate | Status | Notes |
|---|---|---|---|
| **I. Modular Architecture** | Single-purpose module, no cross-module coupling, shared logic in `includes/Utilities/` | **PASS** | F026 extends `ToolPolicy` (F025 module) with one new method that consumes `ExposureResolver` (F017 module) via its public API. No new files, no new modules. The composer stays inside `Database/MCPServer/`; the resolver stays inside `Database/MCPServerAbility/`. Cross-module dependency is one-directional (`MCPServer::ToolPolicy → MCPServerAbility::ExposureResolver`) — no cycles, no leakage into `Utilities/`. |
| **II. WordPress Standards Compliance** | WPCS strict, PHPStan L8, ESLint clean, WP 6.9+ / PHP 8.1+, multisite unless justified | **PASS** | New method is standard PHP; `use` import follows A6. No PHPStan L8 pain points expected — the return type is `string[]` and every append is `strval`'d. Multisite out of scope inheriting plugin baseline (documented in spec §Assumptions). |
| **III. Security First** | Sanitization, escaping, nonces, capability checks, prepared statements, `permission_callback`, hashed secrets | **PASS** | No new REST route; no new user input; no new POST accept. F026 READS via BerlinDB Kern (`ExposureResolver::resolve()` → `MCPServerAbility\Query::query()` — prepared under the hood). No new escaping or nonce surface. `permission_callback` on the Tools tab endpoints (F025) and Abilities tab endpoints (F017) unchanged. |
| **IV. User-Centric Design** | New admin UI uses DataForm/DataViews unless pre-approved exception | **PASS** *(no new UI)* | F026 has ZERO admin UI changes. Both the Abilities tab (F017, `@wordpress/dataviews`) and the Tools tab (F025, `@wordpress/components`) are preserved verbatim. Constraint is satisfied vacuously. |
| **V. Extensibility Without Core Modification** | Actions/filters/extension points; graceful degradation for optional integrations | **PASS** | F025 filter `acrossai_mcp_manager_server_tools` is reused with widened pre-filter input — companion plugins keep working (strict superset of pre-F026 input). Vendor filter `mcp_adapter_default_server_config` continues to serve the default server. Vendor MCP adapter files under `vendor/wordpress/mcp-adapter/` untouched. |
| **VI. Reusability & DRY** | Shared logic centralized; `@wordpress/*` first, npm second; `validate-packages` runs pre-commit | **PASS** | `ExposureResolver::resolve()` is the single canonical decision-maker per `DEC-ABILITY-OVERRIDE-RESOLUTION`. F026 does not duplicate its fallback logic. `ToolPolicy::compose_effective_tools_for_row()` calls `compose_for_row()` internally to seed the union — no duplication of protocol-column iteration or curated-slug fetch. No npm changes. |
| **VII. Definition of Done** | PHPCS / PHPStan L8 / ESLint / security / tests / DataForm / DRY / prefix / AGENTS.md / validate-packages | **PASS at gate; implementation must deliver** | All gates addressable at implementation time; spec §Success Criteria enumerates them. |

**Post-check verdict**: No violations. No documented deviations. F026 extends F025's composer with a third source and reuses F017's canonical resolver — both extensions are strict additions with no memory conflicts (per `memory-synthesis.md` §"Conflict Warnings — None").

## Project Structure

### Documentation (this feature)

```text
specs/026-abilities-into-tool-registration/
├── plan.md                       # This file
├── spec.md                       # Feature specification
├── memory-synthesis.md           # Durable-memory constraints
├── research.md                   # Phase 0 output
├── data-model.md                 # Phase 1 output
├── quickstart.md                 # Phase 1 output
├── contracts/                    # Phase 1 output
│   └── filter-acrossai_mcp_manager_server_tools-widened.md
├── checklists/
│   └── requirements.md           # Spec-quality checklist (already produced)
└── tasks.md                      # Phase 2 output — NOT produced by /speckit-plan
```

### Source Code (repository root)

F026 is a WordPress plugin extension of F025 — single project, minimal file surface.

```text
acrossai-mcp-manager/
├── includes/
│   ├── Database/
│   │   └── MCPServer/
│   │       └── ToolPolicy.php               # MODIFY — add `use ExposureResolver;`; append new public static compose_effective_tools_for_row() method after compose_for_row()
│   ├── MCP/
│   │   └── Controller.php                   # MODIFY — line 142 + line 247 swap compose_for_row → compose_effective_tools_for_row; update filter docblock at line ~155 to describe three composition sources
│   └── REST/
│       └── ToolsController.php              # NO CODE CHANGE — grep-verified only (still calls compose_for_row at line 201)
├── docs/
│   └── extending-server-tools.md            # MODIFY — extend §Filter contract with third source; extend §Arguments → $tools; add new §Interaction with the Abilities tab
└── tests/
    └── phpunit/
        ├── Database/MCPServer/
        │   └── ToolPolicyTest.php           # MODIFY (extend) — +4 test_compose_effective_* cases; add ExposureResolver::_reset_cache_for_tests() to setUp()
        └── MCP/
            └── ControllerToolsInjectionTest.php  # MODIFY (extend) — +1 test_register_database_servers_produces_f017_widened_composed_set() case
```

**Structure Decision**: No new files. Every touched path already exists. Total delta: 2 modified PHP files (source), 2 modified test files, 1 modified doc, 1 grep-only verification.

## Complexity Tracking

> Fill ONLY if Constitution Check has violations that must be justified.

**None to declare.** No violations. No deviations from durable memory. The design is a strict addition — an extension of `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` (adds a third composition source) and a REUSE of `DEC-ABILITY-OVERRIDE-RESOLUTION` (calls the canonical resolver). Both align with the standing memory hub.

## Post-Design Constitution Re-Check

*Re-evaluate after Phase 1 artifacts (`data-model.md`, `contracts/`, `quickstart.md`) are drafted below.*

- **Principle I** — Design keeps single-purpose scope per module. `compose_effective_tools_for_row()` stays on `ToolPolicy`; `ExposureResolver::resolve()` stays on `MCPServerAbility`. One-directional dependency, no cycles. **PASS**.
- **Principle II** — Method signature (`public static function compose_effective_tools_for_row( Row $row ): array`) is PHPStan L8 friendly; explicit `is_array($meta) ? $meta : array()` guard passes strict type analysis; return `array_values( array_unique( array_map( 'strval', $tools ) ) )` produces a normalized `string[]`. **PASS**.
- **Principle III** — Contract for the filter's pre-filter set widens but no new user input surface introduced. Companion plugin callbacks that already validated their return arrays continue to work; the F025 defensive re-normalize (`array_values( array_unique( array_map( 'strval', (array) $return ) ) )`) at `Controller.php:163` post-filter handles hostile filter returns as before. **PASS**.
- **Principle IV** — No new admin UI. F017's Abilities tab (DataViews) and F025's Tools tab (React) both preserved verbatim. **PASS**.
- **Principle V** — Filter reuse preserves the extensibility contract. Docblock updated to declare the wider input. Vendor filter unchanged. **PASS**.
- **Principle VI** — `ExposureResolver` remains the single-source-of-truth for effective ability exposure per `DEC-ABILITY-OVERRIDE-RESOLUTION`. F026 introduces no duplicate resolver, no shortcut, no inline `mcp.public` check. **PASS**.
- **Principle VII** — Definition-of-Done gates enumerated in spec §Success Criteria and applied at implementation. **PASS**.

No new violations introduced by the Phase 1 design.

## Phases

### Phase 0 — Outline & Research *(complete — see [`research.md`](./research.md))*

The plan-mode conversation on 2026-07-14 (two-question round: sibling method vs. overload; filter reuse) resolved every `[NEEDS CLARIFICATION]` before this plan was drafted. `research.md` records the decisions taken and the alternatives considered.

### Phase 1 — Design & Contracts *(complete — see [`data-model.md`](./data-model.md), [`contracts/`](./contracts/), [`quickstart.md`](./quickstart.md))*

Artifacts produced:

- **`data-model.md`** — No schema delta. Describes the composed tool list as the runtime primary object, its three sources (F025 protocol columns + F020 curated rows + F017-effective abilities), and the composition order (non-contractual but stable per FR-011).
- **`contracts/filter-acrossai_mcp_manager_server_tools-widened.md`** — Widened contract for the F025 filter: same signature, same call site, wider pre-filter input. Documents the three composition sources, the resolver precedence, and companion-plugin backwards compat.
- **`quickstart.md`** — Reviewer walkthrough: register a public ability via a scratch mu-plugin, curl `tools/list`, toggle in Abilities tab, re-curl to see it disappear, verify Tools tab GET unchanged, verify F017 call-time gate still enforces on `tools/call`.

### Phase 2 — Task Generation *(deferred to `/speckit-tasks`)*

Not produced by `/speckit-plan`. `docs/planings-tasks/026-abilities-into-tool-registration.md` §Speckit Workflow already lists TASK-1 through TASK-6 in a form that `/speckit-tasks` can consume with minor normalization.

## Agent Context Update

`CLAUDE.md` at the plugin root does not carry `<!-- SPECKIT START -->` / `<!-- SPECKIT END -->` markers — the plugin's agent-context file is `AGENTS.md`, which `CLAUDE.md` `@`-references. No pointer update is required; the active plan file is discoverable via `.specify/feature.json` (`{"feature_directory": "specs/026-abilities-into-tool-registration"}`), which now points to this `plan.md`.
