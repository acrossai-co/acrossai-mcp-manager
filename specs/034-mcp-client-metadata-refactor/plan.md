# Implementation Plan: MCP Client Metadata + Filter-Aware Enumeration Refactor

**Branch**: `034-mcp-client-metadata-refactor` | **Date**: 2026-07-25 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/034-mcp-client-metadata-refactor/spec.md`
**Memory Synthesis**: [memory-synthesis.md](./memory-synthesis.md) (produced via `/speckit-memory-md-plan-with-memory`)

## Summary

Refactor `includes/MCPClients/` and `public/Renderers/MCPClientsBlock.php` so every concrete MCP client class becomes self-describing — six new metadata methods on `AbstractMCPClient` (`get_icon`, `get_description`, `get_config_file`, `get_top_level_key`, `get_instructions`, `get_priority`) with backwards-compatible defaults, migrated from the private `MCPClientsBlock::CLIENT_META` const. A single canonical enumeration path `AbstractMCPClient::get_all_registered_clients()` replaces three competing paths (glob-based `get_all_clients()` + inline render-loop + private metadata const), mirroring the `ConnectorProfileRegistry::get_profiles()` shape already used for AI connector profiles. Priority-based sort order (default 100; built-ins pre-assigned 10–80) preserves byte-identical sub-nav render. Zero new hooks, zero DB changes, zero admin-UI changes. Purely an internal architectural realignment that unlocks proper third-party MCP client contributions (per User Story 1) and eliminates the class of drift bug documented in Bug Pattern B32.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin minimum per AGENTS.md; constitution §II)
**Primary Dependencies**: None new. WordPress core (`__()`, `_doing_it_wrong()`, `apply_filters()`), plugin-internal (`AbstractMCPClient`, `MCPClientsBlock`, `ConnectorProfileRegistry` as pattern reference).
**Storage**: N/A. Zero persistent state; no DB writes, no option reads, no transients.
**Testing**: PHPUnit via `vendor/bin/phpunit --testsuite=mcpclients`. New tests live under `tests/phpunit/MCPClients/` (pure-service, no WP bootstrap needed per A11 + `tests/bootstrap.php` config) plus one WP-bootstrap test under `tests/phpunit/Public/Renderers/` for the render snapshot regression.
**Target Platform**: WordPress 6.9+ single-site admin (server-edit → Clients tab). No frontend impact.
**Project Type**: WordPress plugin subsystem refactor — no new project boundaries; modification to an existing feature module (`MCPClients`).
**Performance Goals**: In-memory operations only; no I/O added. Enumeration cost is O(n) where n = built-in count + filter-contributed count (typically ≤10). Called at most once per admin request when the Clients tab renders.
**Constraints**: Byte-identical rendered HTML on the server-edit → Clients tab for all eight built-in clients (FR-016). Backwards-compatible with existing third-party `AbstractMCPClient` subclasses (FR-002). `_doing_it_wrong` version tag pinned to `'0.1.7'` (per Clarifications Q2).
**Scale/Scope**: Eight built-in clients today; typical filter-contributed additions ≤5 per site. Zero-registrations edge case documented.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Assessment of each of the seven principles (constitution v1.1.0):

| Principle | Status | Assessment |
|---|---|---|
| **I. Modular Architecture** | ✅ Pass | Changes stay inside two directories: `includes/MCPClients/` (owned by the MCPClients module) and `public/Renderers/MCPClientsBlock.php` (owned by the Public Renderers module — DEC-CLIENT-RENDERER-PUBLIC-API). No sibling module touched. Improves modularity by moving display metadata off the Renderer and onto the module that owns each client. |
| **II. WordPress Standards Compliance** | ✅ Pass | All new methods use `string` / `int` return types (PHP 8.1+). Existing PHPCS + PHPStan level 8 gates apply unchanged. `__()` wraps translatable strings with `'acrossai-mcp-manager'` text domain (FR-004). No deprecated WP functions. Multisite unchanged (single-site plugin per AGENTS.md). |
| **III. Security First (NON-NEGOTIABLE)** | ✅ Pass | No new user input, no forms, no AJAX, no REST routes. Preserves SEC-013-008 (invalid FQNs silently skipped). Existing render-time escaping in `MCPClientsBlock` render helpers is unchanged. No token/secret handling. Security Checklist in spec confirms every item N/A with justification. |
| **IV. User-Centric Design (NON-NEGOTIABLE)** | ✅ Pass | No new admin UI. Existing sub-nav + client panels render byte-identical (FR-016). The MCP Manager parent menu + AI Connectors card layout exceptions (§IV) are irrelevant — F034 doesn't add UI. |
| **V. Extensibility Without Core Modification** | ✅ Pass — improved | Preserves the `acrossai_mcp_client_classes` filter contract verbatim (FR-014). Introduces `get_priority(): int` as a NEW extensibility seam for sub-nav slot control. Third-party client subclasses can now declare ALL their metadata via method overrides — a strict expansion of extension surface with no removal. |
| **VI. Reusability & DRY Principle** | ✅ Pass — improved | Directly resolves the DRY violation the refactor exists to fix: three competing enumeration paths → one canonical path. Zero code duplication introduced; existing duplication eliminated. `npm run validate-packages` unchanged (no JS added). |
| **VII. Definition of Done** | ✅ Pass gates listed in spec | PHPCS + PHPStan level 8 + PHPUnit + `acrossai_mcp_` prefix + no DRY + AGENTS.md alignment + package validation — all applicable, spec's SC-002..006 cover measurement. DataForm/DataViews checkboxes N/A (no new UI). |

**Result**: All 7 principles pass without deviation. **No entries required in Complexity Tracking.**

Additional constraints from memory synthesis (see `memory-synthesis.md`):

- **A1** (hook registration in Main.php) — F034 fires `apply_filters('acrossai_mcp_client_classes', ...)` inside a static method but registers no new hooks; A1 preserved.
- **A2 + A11** — `MCPClientsBlock` singleton preserved; `AbstractMCPClient` remains an A11 pure-service exemption (stateless, no ctor args, no hook registration).
- **DEC-CLIENT-RENDERER-PUBLIC-API** — `MCPClientsBlock` public API (`instance()`, `slug()`) preserved; `@experimental` policy honoured.
- **Bug Pattern B32** — F034 is the exact application of "filter defaults MUST express the plugin's canonical semantic; never partial derivation."

## Project Structure

### Documentation (this feature)

```text
specs/034-mcp-client-metadata-refactor/
├── plan.md              # This file (/speckit-plan output)
├── spec.md              # Feature specification (with Clarifications section)
├── memory-synthesis.md  # Pre-plan gate artifact (/speckit-memory-md-plan-with-memory output)
├── research.md          # Phase 0 output (this run)
├── data-model.md        # Phase 1 output — AbstractMCPClient contract shape
├── quickstart.md        # Phase 1 output — third-party developer walkthrough
├── contracts/
│   └── AbstractMCPClient.contract.md  # Phase 1 output — the post-refactor class contract
├── checklists/
│   └── requirements.md  # /speckit-specify output
└── tasks.md             # Phase 2 output (/speckit-tasks; NOT created here)
```

### Source Code (repository root)

```text
includes/MCPClients/
├── AbstractMCPClient.php        # Add 6 methods, add DEFAULT_CLIENT_CLASSES const, add get_all_registered_clients(), DELETE get_all_clients()
├── ClaudeDesktopClient.php      # Add 5 metadata + priority=10 overrides
├── ClaudeCodeClient.php         # Add 5 metadata + priority=20 overrides
├── VSCodeClient.php             # Add 5 metadata + priority=30 overrides
├── GitHubCopilotClient.php      # Add 5 metadata + priority=40 overrides
├── CodexClient.php              # Add 5 metadata + priority=50 overrides
├── CursorClient.php             # Add 5 metadata + priority=60 overrides
├── GeminiClient.php             # Add 5 metadata + priority=70 overrides
└── CustomClient.php             # Add 5 metadata + priority=80 overrides

public/Renderers/
└── MCPClientsBlock.php          # Delete CLIENT_META, delete inline default-classes array + filter loop, call get_all_registered_clients() and read metadata via instance method calls

tests/phpunit/MCPClients/
├── AbstractMCPClientTest.php                    # Existing — add default-return assertions for new methods
├── GetAllRegisteredClientsTest.php              # NEW — enumeration + validation + filter + priority sort tests
└── ConcreteClientMetadataTest.php               # NEW — data-provider test asserting each of the 8 built-ins returns migrated metadata values

tests/phpunit/Public/Renderers/
└── MCPClientsBlockRenderTest.php                # NEW — DOM-marker regression test for at least one representative client (claude-desktop)
```

**Structure Decision**: In-place refactor of the existing `includes/MCPClients/` and `public/Renderers/MCPClientsBlock.php` modules. No new module boundaries, no new package layout. Follows the constitution §"Architecture & UI Standards" directory layout exactly as documented. Tests join the pre-existing `mcpclients` phpunit suite (pure-service bootstrap) and add one test to `renderers` (WP bootstrap) — no new suite registration in `phpunit.xml.dist` required.

## Complexity Tracking

> **No violations to justify — all 7 constitution principles pass without deviation.** This section intentionally empty.

---

## Phase 0 — Outline & Research

*Prerequisites: Constitution Check ✅ passed above. Spec Clarifications section resolved both ambiguities identified during `/speckit-clarify`.*

The Technical Context has zero unresolved `NEEDS CLARIFICATION` markers. Research reduces to two decision points the spec left as implementation detail for the plan to resolve — both low-impact and covered in [`research.md`](./research.md):

1. **Test file organization**: single data-provider-parameterized `ConcreteClientMetadataTest.php` vs. per-client `<Client>Test.php` files.
2. **Render regression test strategy**: full DOM snapshot vs. hand-authored key-marker assertions (spec allows either).

Neither decision affects the shipped code; both belong to the test-file layout. Research consolidated in `research.md`.

**Output**: `research.md` (created this run).

---

## Phase 1 — Design & Contracts

*Prerequisites: Phase 0 research complete.*

Three Phase 1 artifacts produced in the same run:

1. **[`data-model.md`](./data-model.md)** — the post-refactor `AbstractMCPClient` contract shape: full method signature list, return type invariants, default values, priority ordering rules, validation rules for slugs and dedup semantics. Not a database data model (F034 has no persistent state); documents the class contract other code depends on.

2. **[`contracts/AbstractMCPClient.contract.md`](./contracts/AbstractMCPClient.contract.md)** — the pseudo-interface a third-party subclass MUST honour + what the abstract provides by default. Documented as a normative reference so a subsequent `/speckit-tasks` phase can enumerate one task per contract method.

3. **[`quickstart.md`](./quickstart.md)** — a 5-minute walkthrough for a companion-plugin developer to add a new MCP client (register via the filter + override the six metadata methods). Directly exercises User Story 1 as documentation.

**Agent context update**: `CLAUDE.md` at the plugin root has an `Active plan` line pointing at the previous plan (`specs/032-oauth-per-server-scoping/plan.md`). Update it to point at this plan file. Handled inline below since a dedicated agent-context script isn't wired for this plugin.

**Output**: `research.md`, `data-model.md`, `contracts/AbstractMCPClient.contract.md`, `quickstart.md`, updated `CLAUDE.md` reference.

## Post-Design Constitution Re-check

Phase 1 artifacts introduce zero new constraints or complexity beyond what the pre-Phase-0 gate assessed. All 7 principles still pass. Complexity Tracking remains empty.
