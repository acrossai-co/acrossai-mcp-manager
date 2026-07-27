# Implementation Plan: Public Connection-Method Discovery API

**Branch**: `035-connection-method-discovery-api` | **Date**: 2026-07-26 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/035-connection-method-discovery-api/spec.md`
**Memory Synthesis**: [memory-synthesis.md](./memory-synthesis.md) (produced via `/speckit-memory-md-plan-with-memory`)

## Summary

Add `ConnectionMethodRegistry` under `public/Discovery/` (namespace `AcrossAI_MCP_Manager\Public\Discovery`) as a singleton programmatic surface that exposes every registered NPM method + MCP client + AI connector as a unified list of plain-associative-array DTOs. Third-party plugins (motivating consumer: planned BuddyBoss add-on) get one canonical `get_all()` call instead of three separate registries with three different shapes. Two new extensibility filters are defined + fired inside the class: `acrossai_mcp_npm_methods` (NPM extension seam — new; NPM had no extension surface before F035) and `acrossai_mcp_connection_methods` (cross-category curation — fires once on the assembled result). F035 delegates transparently to F034's `AbstractMCPClient::get_all_registered_clients()` and F021's `ConnectorProfileRegistry::get_profiles()`; it MUST NOT re-fire either underlying filter. One light touch on the existing plugin: `NpmClientBlock` grows a new `public static function get_default_npm_method(): array` helper so the NPM template + option gate has a single source of truth (byte-identical NPM tab render preserved). New `discovery` PHPUnit suite registered in `phpunit.xml.dist` + CI step. Zero admin UI, zero REST routes, zero DB writes, zero hook registrations in `Main.php`.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin minimum per AGENTS.md; constitution §II).
**Primary Dependencies**: None new. WordPress core (`apply_filters()`, `_doing_it_wrong()`, `__()`, `wp_json_encode()`), plugin-internal (`AbstractMCPClient` from F034, `ConnectorProfileRegistry` from F021, `NpmClientBlock` from F013).
**Storage**: N/A. Zero persistent state — no DB writes, no option reads, no transients. F035 reads the NAME of the `acrossai_mcp_npm_login_enabled` option (embeds it in the NPM DTO's `meta`) but never reads the option's VALUE.
**Testing**: PHPUnit via `vendor/bin/phpunit --testsuite=discovery`. New suite registered in `phpunit.xml.dist` + `.github/workflows/phpunit.yml` per F030 precedent. Suite uses `tests/bootstrap-wp.php` (WP bootstrap) because F035 delegates transitively into `ConnectorProfileRegistry` (`_doing_it_wrong` + abilities-API touch) and `NpmClientBlock` (`home_url()`, `get_option()`) — stubbing these would blow past A18's ~10-symbol ceiling.
**Target Platform**: WordPress 6.9+ single-site. No admin-UI touch, no frontend touch, no request context requirement (works in WP-CLI + cron contexts identically to admin).
**Project Type**: WordPress plugin subsystem addition — new `public/Discovery/` layer in an existing feature module family. No new project boundaries.
**Performance Goals**: In-memory only, no I/O. `get_all()` memoized per-request (single call cost regardless of how many consumers query). Typical registry sizes: 1–3 NPM + 8 clients (F034 built-ins) + 0–2 AI connectors (companion plugins) = ≤13 DTOs.
**Constraints**: Byte-identical rendered HTML for the NPM tab post-refactor (SC-007). Backwards-compatible with every existing consumer of `NpmClientBlock` (no public method signature changes; only adds a new static helper). No re-firing of the two underlying extension filters (SC-005 grep gate). `public/` MUST NOT be imported into `includes/` (SC-006 grep gate). `_doing_it_wrong` version tag pinned to `'0.1.9'` (see research.md decision R1).
**Scale/Scope**: One new class (~180 lines PHP), one new PHPUnit suite directory, one new static helper on `NpmClientBlock` (~15 lines), one `phpunit.xml.dist` suite entry, one CI job step. Fresh test surface: ~8 test methods across 2 test files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Assessment of each of the seven principles (constitution v1.1.0):

| Principle | Status | Assessment |
|---|---|---|
| **I. Modular Architecture** | ✅ Pass | Adds one new file to `public/Discovery/`; light touch on one existing file (`public/Renderers/NpmClientBlock.php`) for a new static helper only. No sibling module touched — `MCPClientsBlock`, `AIConnectorsTab`, `ConnectorProfileRegistry`, `AbstractMCPClient` (and every concrete client), and every `admin/Partials/` file are untouched (FR-014). |
| **II. WordPress Standards Compliance** | ✅ Pass | All new methods use strict PHP 8.1+ return types (`array`, `?array`, `self`). PHPCS + PHPStan level 8 gates apply unchanged. `__()` wraps translatable strings with `'acrossai-mcp-manager'` text domain. No deprecated WP functions. Single-site unchanged. |
| **III. Security First (NON-NEGOTIABLE)** | ✅ Pass | No new user input, no forms, no AJAX, no REST routes, no DB queries, no token/secret handling. F035 emits data structures; consumers own render-time escaping. Two new filters (`acrossai_mcp_npm_methods`, `acrossai_mcp_connection_methods`) are trust-level developer contributions — same trust anchor as the existing `acrossai_mcp_client_classes` and `acrossai_mcp_manager_connector_profiles` seams. Filter callbacks that return malformed contributions are silently dropped + `_doing_it_wrong` under `WP_DEBUG` (FR-009b + FR-012a — SEC-013-008 pattern inheritance). Security Checklist in spec confirms every applicable item N/A with justification. |
| **IV. User-Centric Design (NON-NEGOTIABLE)** | ✅ Pass | No new admin UI. Existing NPM tab render byte-identical (SC-007). Neither §IV exception (MCP Manager parent menu; AI Connectors card layout) is triggered — F035 doesn't add UI at all. |
| **V. Extensibility Without Core Modification** | ✅ Pass — improved | Adds TWO NEW extensibility seams (`acrossai_mcp_npm_methods` closes the NPM gap; `acrossai_mcp_connection_methods` enables cross-category curation without three separate filter registrations). Preserves BOTH underlying extension seams via transparent delegation (SC-005 grep gate enforces "never re-fire"). Pure additive extension surface. |
| **VI. Reusability & DRY Principle** | ✅ Pass — improved | Delegation-not-re-implementation is the whole design. `FR-010` + `FR-011` require reading canonical registries; SC-005 grep gate enforces the constraint at review time. `NpmClientBlock::get_default_npm_method()` helper (FR-015) establishes a single source of truth for the NPM template + option key — SC-007 ensures the refactor is byte-identical. |
| **VII. Definition of Done** | ✅ Pass gates listed in spec | PHPCS + PHPStan level 8 + PHPUnit (new `discovery` suite) + `acrossai_mcp_` prefix + no DRY + AGENTS.md alignment + package validation — all applicable per spec's SC-001..SC-008. DataForm/DataViews checkboxes N/A (no new UI). |

**Result**: All 7 principles pass without deviation. **No entries required in Complexity Tracking.**

Additional constraints from memory synthesis (see `memory-synthesis.md`):

- **A1** — F035 fires `apply_filters()` inside static methods but registers no new hooks. Zero `add_filter` / `add_action` calls in `public/Discovery/` (SC-005 sibling grep gate). Verified by `grep -rn "add_filter\|add_action" public/Discovery/` returning zero hits.
- **A2** — `ConnectionMethodRegistry` implements singleton `instance()` pattern with `private __construct()` (FR-002). Memoization state (per-request `get_all()` cache) makes it NOT eligible for A11 pure-service exemption.
- **A6** — cross-namespace references from `Public\Discovery` to `Includes\MCPClients\AbstractMCPClient` and `Includes\Connectors\ConnectorProfileRegistry` MUST use explicit `use` imports (bare relative names silently fail per B1).
- **A18** — WP function stubs in `tests/bootstrap.php` are NOT sufficient for this suite (transitive dependencies would push past the ~10-symbol ceiling). F035 uses `tests/bootstrap-wp.php` per spec Assumptions.
- **D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT** — F035 IS the canonical consumer of `AbstractMCPClient::get_all_registered_clients()`. Delegation, not re-implementation.
- **DEC-CLIENT-RENDERER-PUBLIC-API** — F035 IS a new `public/` layer entry; inherits the `@experimental` freeze-at-1.0.0 policy verbatim (FR-001).
- **B32** — F035's FR-010/FR-011 (delegation) + FR-012a (fallback to pre-filter result on malformed callback return) are direct applications of "filter defaults MUST express canonical resolver output."

## Project Structure

### Documentation (this feature)

```text
specs/035-connection-method-discovery-api/
├── plan.md                          # This file (/speckit-plan output)
├── spec.md                          # Feature specification (with Clarifications section — 3 Q&A)
├── memory-synthesis.md              # Pre-plan gate artifact (/speckit-memory-md-plan-with-memory output)
├── research.md                      # Phase 0 output (this run) — 3 decisions
├── data-model.md                    # Phase 1 output — DTO shape + category-specific meta contracts
├── quickstart.md                    # Phase 1 output — third-party consumer walkthrough
├── contracts/
│   └── ConnectionMethodRegistry.contract.md   # Phase 1 output — post-refactor public API contract
├── checklists/
│   └── requirements.md              # /speckit-specify output
└── tasks.md                         # Phase 2 output (/speckit-tasks; NOT created here)
```

### Source Code (repository root)

```text
public/Discovery/
└── ConnectionMethodRegistry.php     # NEW — singleton, 6 public methods, 2 new filters fired, delegation-only

public/Renderers/
└── NpmClientBlock.php               # LIGHT TOUCH — add public static get_default_npm_method(): array; optionally refactor render_body() to consume it (byte-identical output — SC-007)

phpunit.xml.dist                     # ADD — <testsuite name="discovery"><directory>tests/phpunit/Public/Discovery/</directory></testsuite>

.github/workflows/phpunit.yml        # ADD — one matrix step invoking --testsuite=discovery (mirrors F030's abilities/database/mcp precedent)

tests/phpunit/Public/Discovery/
├── ConnectionMethodRegistryTest.php # NEW — get_all() shape, per-category getters, filter integration, memoization, find(), unified-DTO-shape parameterized assertion, dedup, malformed-filter fallback
└── NpmDefaultHelperTest.php         # NEW — asserts NpmClientBlock::get_default_npm_method() returns the expected DTO shape (guards SC-007 template drift)
```

**Structure Decision**: In-place addition of a new `public/Discovery/` sibling directory to the existing `public/Renderers/` layer. Follows constitution §"Architecture & UI Standards" directory-layout rule (namespace mirrors path). Test file organization: one primary test class for the registry + one focused helper test for the extracted NpmClientBlock helper. See `research.md` R2 for the layout decision rationale.

## Complexity Tracking

> **No violations to justify — all 7 constitution principles pass without deviation.** This section intentionally empty.

---

## Phase 0 — Outline & Research

*Prerequisites: Constitution Check ✅ passed above. Spec Clarifications section (Q1 later-wins dedup, Q2 SEC-013-008 malformed-drop, Q3 malformed-return fallback) resolved all ambiguities identified during `/speckit-clarify`.*

Three implementation-detail decisions the spec deferred to plan-phase — all documented in [`research.md`](./research.md):

1. **R1** — `_doing_it_wrong` version-tag value: `'0.1.9'` (this feature's target release, matching F034's `'0.1.7'` precedent).
2. **R2** — Memoization implementation: static instance property `$assembled_cache` reset via `public function flush_cache()` — production-shape naming per B23 (avoids `_reset_for_tests()` smell). PHPUnit `setUp()` calls `flush_cache()` for isolation between tests; production code never calls it.
3. **R3** — Test file layout: one primary `ConnectionMethodRegistryTest.php` (data-provider-parameterized where appropriate) + one focused `NpmDefaultHelperTest.php`. Two files, not eight micro-files.

**Output**: `research.md` (created this run).

---

## Phase 1 — Design & Contracts

*Prerequisites: Phase 0 research complete.*

Three Phase 1 artifacts produced in the same run:

1. **[`data-model.md`](./data-model.md)** — the DTO shape as a data contract (not a database data model — F035 has no persistent state). Documents the six top-level keys, category-specific `meta` field contents, JSON-serialization invariant, dedup semantics, malformed-drop semantics. Establishes the invariant a consumer can rely on: "every DTO across every category has the same six top-level keys."

2. **[`contracts/ConnectionMethodRegistry.contract.md`](./contracts/ConnectionMethodRegistry.contract.md)** — the class's public API contract: six method signatures with pre/post-conditions, filter-firing invariants ("`acrossai_mcp_connection_methods` fires ONCE inside `get_all()` — never on per-category getters"), delegation invariants ("MUST NOT re-fire `acrossai_mcp_client_classes` or `acrossai_mcp_manager_connector_profiles`"). Written as a normative reference so `/speckit-tasks` can enumerate one task per contract clause.

3. **[`quickstart.md`](./quickstart.md)** — a 5-minute walkthrough for a companion-plugin developer to consume the discovery API: (a) enumerate all methods, (b) find a specific method, (c) add a custom NPM method via `acrossai_mcp_npm_methods`, (d) customize the assembled result via `acrossai_mcp_connection_methods`. Directly exercises User Stories 1 + 2 + 3 as documentation.

**Agent context update**: `CLAUDE.md` at the plugin root has an `Active plan` line pointing at `specs/034-mcp-client-metadata-refactor/plan.md`. Update it to point at this plan file. Handled inline in tasks.md.

**Output**: `research.md`, `data-model.md`, `contracts/ConnectionMethodRegistry.contract.md`, `quickstart.md`, updated `CLAUDE.md` reference.

## Post-Design Constitution Re-check

Phase 1 artifacts introduce zero new constraints or complexity beyond what the pre-Phase-0 gate assessed. All 7 principles still pass. Complexity Tracking remains empty.
