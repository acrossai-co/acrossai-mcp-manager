# Implementation Plan: Per-Server Shortcode + Block Embeds Tab

**Branch**: `036-shortcode-block-embeds` | **Date**: 2026-07-27 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/036-shortcode-block-embeds/spec.md`
**Memory Synthesis**: [memory-synthesis.md](./memory-synthesis.md) (produced via `/speckit-memory-md-plan-with-memory`)

> **Pivot notice (2026-07-27)**: This plan documents the pre-implementation intent. Three material architectural pivots landed post-`/speckit-implement`:
> - **Pivot A** — Per-DTO gate redesign (`is_enabled_for_server` widened to 3 args; observability action widened to 5 args)
> - **Pivot B** — Storage moved from junction table + column to a WP-canonical meta blob (`wp_acrossai_mcp_servers_meta` at `_embeds_clients`)
> - **Pivot C** — `AbstractReactMountServerTab` intermediate base extracted; `EmbedsController` deleted; `admin/Main.php::maybe_enqueue_embeds_app()` deleted
>
> This plan file is preserved as-is (design history); see [spec.md `## Post-Plan Pivots`](./spec.md#post-plan-pivots) for the canonical narrative, [tasks.md `## Post-Q4 Pivot Tasks`](./tasks.md#post-q4-pivot-tasks-retrospective--record-of-iterations-post-speckit-implement) for the retrospective task ledger, [data-model.md](./data-model.md) §§ 1–3 for the shipped storage + class shape, and [contracts/AbstractReactMountServerTab.contract.md](./contracts/AbstractReactMountServerTab.contract.md) for the new reusable primitive.

## Summary

Add a per-server "Embeds" admin tab under `admin/Partials/ServerTabs/EmbedsTab.php` (extends F013 `AbstractServerTab`, registered in `Registry`) that lets site administrators control which connection methods surface via a new `[acrossai_mcp_embed]` shortcode on the WordPress frontend. Tab UX: master toggle default OFF; when ON, per-transport sub-toggles for NPM / MCP Clients / AI Connectors become interactive. Persistence: new BerlinDB junction table `wp_acrossai_mcp_server_embed_transports` (presence model per DEC-TOOL-SELECTION-PRESENCE-MODEL) + new `embeds_enabled TINYINT(1)` column on `wp_acrossai_mcp_servers` (D28 3-part contract, `$version` bump `1.1.2` → `1.1.3`). New `AbstractEmbedTransport` base class under `includes/Embeds/` — direct application of D35 self-contained-subsystem-contract, mirrors F034 `AbstractMCPClient::get_all_registered_clients()` line-for-line. Three built-in concrete transports (`NpmEmbedTransport`, `ClientEmbedTransport`, `AiConnectorEmbedTransport` — singular keys per Clarifications Q1 for zero-translation alignment with F035 DTO `category` field). Filter `acrossai_mcp_embed_transports` for third-party extension. Frontend renderer under `public/Renderers/EmbedBlock/EmbedBlockRenderer.php` (`final class` per D36) gated by three-check cascade: master toggle → per-transport toggle → F015 access control (fail-open per D19 when F015 absent). Two observability actions per Clarifications Q3 (`acrossai_mcp_embed_master_toggled`, `acrossai_mcp_embed_transport_toggled`). Optional GC helper per Clarifications Q2. Consumes `ConnectionMethodRegistry::get_all()` (F035, shipped in 0.1.9-target as commit `b9f0029` on `main`) as the DTO source. New `embeds` PHPUnit suite + CI step.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin minimum per AGENTS.md; constitution §II).
**Primary Dependencies**: None new. `berlindb/core` already installed (used by every DB module). Plugin-internal: F013 `AbstractServerTab`/`Registry`, F015 `AcrossAI_MCP_Access_Control` wrapper (optional integration; fails open when absent), F035 `ConnectionMethodRegistry`, existing `MCPServer\Query::get_by_slug()`.
**Storage**:
- **NEW BerlinDB module** `includes/Database/ServerEmbedTransports/{Schema, Table, Row, Query}.php` — junction table `wp_acrossai_mcp_server_embed_transports` with `UNIQUE(server_id, transport_key)`.
- **MODIFIED** `wp_acrossai_mcp_servers.embeds_enabled TINYINT(1) DEFAULT 0` via D28 3-part contract (`$version` bump `1.1.2` → `1.1.3` + `upgrade_to_1_1_3` callback + reconciliation on `admin_init@3`).
**Testing**: PHPUnit via `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite=embeds`. New suite registered in `phpunit.xml.dist` + `.github/workflows/phpunit.yml` per F030/F035 precedent. Suite uses `tests/bootstrap-wp.php` (WP bootstrap) because F037 touches BerlinDB, WP options, shortcode API, F015 wrapper — transitive deps exceed A18 stub ceiling.
**Target Platform**: WordPress 6.9+ single-site. Admin surface for site administrators (`manage_options`); frontend shortcode for anonymous + authenticated visitors (F015 access control cascade decides).
**Project Type**: WordPress plugin subsystem addition — new domain module `includes/Embeds/`, new admin tab, new frontend renderer, new BerlinDB table + column bump. No new project boundaries.
**Performance Goals**: In-memory + one indexed DB read per `is_enabled_for_server()` call. Typical registry sizes: 3 built-in transports + 0–3 companion-plugin transports. Master + per-transport lookups memoized per-request via a lightweight static cache on `AbstractEmbedTransport` (mirrors F035 `flush_cache()` shape).
**Constraints**:
- SC-005 grep gate: no re-firing of F034/F021/F035 filters inside `includes/Embeds/` + `admin/Partials/ServerTabs/EmbedsTab.php` + `public/Renderers/EmbedBlock/`.
- SC-006 grep gate: `ConnectionMethodRegistry` MUST NOT be imported in `includes/Embeds/` (only the `public/Renderers/EmbedBlock/` layer imports F035 — one-way layering).
- B18 defense: `TINYINT(1)` returned as string; `is_enabled_for_server()` MUST cast to `(int)` before strict compare.
- B21 defense: BerlinDB `modified` flag (NOT `date_updated`) on `date_modified` column.
- B34 defense: D28 3-part contract mandatory for the new column + the new table.
- `_doing_it_wrong` version tag pinned to `'0.1.10'` (target release; matches F035 `'0.1.9'` precedent — see research.md R1).
**Scale/Scope**: One new domain module (~5 files, ~500 LOC), one new BerlinDB module (~4 files, ~250 LOC), one new admin tab (~200 LOC hand-rolled form + save handler), one new frontend renderer (~150 LOC shortcode + output template), 1 new column on existing table, 1 new junction table, 1 new PHPUnit suite, 1 new CI step. New tests: ~15 test methods across ~4 test files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Assessment of each of the seven principles (constitution v1.1.0):

| Principle | Status | Assessment |
|---|---|---|
| **I. Modular Architecture** | ✅ Pass | New self-contained module `includes/Embeds/` (domain), `includes/Database/ServerEmbedTransports/` (persistence), `admin/Partials/ServerTabs/EmbedsTab.php` (admin UI), `public/Renderers/EmbedBlock/EmbedBlockRenderer.php` (frontend). Zero cross-module coupling except sanctioned delegation to F035 `ConnectionMethodRegistry` (via the frontend renderer only per SC-006). |
| **II. WordPress Standards Compliance** | ✅ Pass | All new methods use strict PHP 8.1+ return types. PHPCS + PHPStan level 8 gates apply unchanged. `__()` wraps translatable strings with `'acrossai-mcp-manager'` text domain. BerlinDB Query classes handle `$wpdb->prepare()` natively via typed placeholders. Single-site (unchanged). |
| **III. Security First (NON-NEGOTIABLE)** | ✅ Pass | Admin save handler: nonce + `manage_options` cap (FR-018). Frontend shortcode: no cap required (FR-019, public-read by design) — security enforced by F015 access control cascade (FR-013). No forms in `includes/Embeds/`; the domain layer is pure state + logic. Escape at render boundary via `esc_html()` / `esc_attr()` in `public/Renderers/EmbedBlock/` per SEC-035-002 preservation invariant. Two observability actions per FR-024 for audit trail. |
| **IV. User-Centric Design (NON-NEGOTIABLE)** | ✅ Pass — post-pivot (Q4) | **REVISED 2026-07-27 post-Q4 pivot**: EmbedsTab now renders a React app under `src/js/embeds.js` using `@wordpress/components` (`ToggleControl`, `Button`, `Notice`, `Spinner`). Saves via REST endpoint per D37 / DEC-ADMIN-UI-REACT-FIRST + F017/F020 pattern. DataForm not required for a 3-field toggle panel; `ToggleControl` is the more appropriate WP-idiomatic choice for boolean switches. **DEV5 hand-rolled form exception NO LONGER APPLIES** to F037 — the exception's consumer count drops from 4 back to 3 (F013 Update Server + F013 Danger Zone + F030 Access Control override); D13 escalation candidate retracted. Consumer per DEC-WP-DATAVIEWS-OVER-REACT — uses ONLY sanctioned `@wordpress/*` packages (element, api-fetch, i18n, components); NO react-query / redux / mobx / styled-components. |
| **V. Extensibility Without Core Modification** | ✅ Pass — improved | Adds new extensibility seam `acrossai_mcp_embed_transports` (D35 canonical enumeration). Third-party plugins add transport categories in ~15 LOC. Adds two observability actions (Q3) for audit-log + reactor plugins. Existing extension seams (F034/F021/F035 filters) preserved via delegation. |
| **VI. Reusability & DRY Principle** | ✅ Pass — improved | Delegates entirely: to F013 `AbstractServerTab` (tab shell), F015 wrapper (access control), F035 `ConnectionMethodRegistry` (DTOs), F034 pattern (canonical enumeration mirror). Adds one new abstract (`AbstractEmbedTransport`) that concrete transport contributors inherit — 3 built-in classes are ~15 LOC each. `npm run validate-packages` unchanged (no JS added). |
| **VII. Definition of Done** | ✅ Pass gates listed in spec | PHPCS + PHPStan level 8 + PHPUnit (new `embeds` suite) + `acrossai_mcp_` prefix + no DRY + AGENTS.md alignment + package validation — all applicable per spec's SC-001..SC-010. DataForm/DataViews checkboxes N/A via pre-approved DEV5 exception. |

**Result**: All 7 principles pass; §IV pass depends on pre-approved DEV5 exception invoked as the 4th consumer. **No entries required in Complexity Tracking.** DEV5 D13 escalation flagged as a governance follow-up, non-blocking.

Additional constraints from memory synthesis (see `memory-synthesis.md`):

- **A1** — F037 wires `add_shortcode('acrossai_mcp_embed', …)` in `define_public_hooks()`, `EmbedsTab` registration in `define_admin_hooks()`, schema reconciliation callback in `define_admin_hooks()`, new BerlinDB Table instantiation in `load_hooks()` (per DEC-BERLINDB-TABLE-REQUEST-BOOT). Zero `add_*` calls inside `includes/Embeds/`, `EmbedsTab.php`, or `EmbedBlockRenderer.php`. Verified by SC-005 sibling grep gate.
- **A2** — `EmbedsTab` inherits singleton shape from `AbstractServerTab`; `EmbedBlockRenderer` implements own singleton per D36; `AbstractEmbedTransport` subclasses are stateless value producers → A11 pure-service exemption applies (no `instance()`, direct `new self()` inside `get_all_registered_transports()`).
- **A6** — cross-namespace refs (`Includes\Embeds` → `Includes\Database\ServerEmbedTransports` + `Includes\Database\MCPServer`; `Public\Renderers\EmbedBlock` → `Public\Discovery\ConnectionMethodRegistry` + `Includes\Embeds\AbstractEmbedTransport`) use explicit `use` imports.
- **A18** — bootstrap-wp chosen for the `embeds` suite (BerlinDB + WP option storage + shortcode API + F015 wrapper transitive deps exceed the ~10-symbol stub ceiling).
- **DEC-BERLINDB-TABLE-REQUEST-BOOT** — new `ServerEmbedTransports\Table` instantiated at request time via `Main::load_hooks()` (activation-time-only leaves BerlinDB's DB interface empty on subsequent requests).
- **D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT** — F037 IS the direct application to a fourth subsystem after F021 (connectors), F034 (MCP clients), F035 (discovery-facing NPM methods). `AbstractEmbedTransport::get_all_registered_transports()` mirrors F034's line-for-line.
- **D36 / DEC-F035-PUBLIC-API-FINAL-CLASS-FILTER-ONLY-EXTENSION** — `EmbedBlockRenderer` under `public/Renderers/` MUST be `final class`. Every `AbstractEmbedTransport` subclass MUST be `final` per FR-012.
- **B18** — `TINYINT(1)` string-return defense applied in `is_enabled_for_server()` — cast to `(int)` OR use `!empty()` for boolean semantics.
- **B21** — BerlinDB `modified` flag (NOT `date_updated`) on the new junction table's `date_modified` column. Grep gate: `grep -rn "'date_updated'" includes/Database/ServerEmbedTransports/` MUST return zero hits.
- **B34** — D28 3-part contract mandatory for both changes (column bump + new table). Sanity SQL post-release: `SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME LIKE 'wp_acrossai_mcp_server%'`.

## Project Structure

### Documentation (this feature)

```text
specs/036-shortcode-block-embeds/
├── plan.md                          # This file (/speckit-plan output)
├── spec.md                          # Feature specification (with Clarifications section — 3 Q&A)
├── memory-synthesis.md              # Pre-plan gate artifact
├── research.md                      # Phase 0 output (this run) — 4 decisions
├── data-model.md                    # Phase 1 output — junction table + column + AbstractEmbedTransport contract
├── quickstart.md                    # Phase 1 output — third-party transport walkthrough
├── contracts/
│   └── AbstractEmbedTransport.contract.md   # Phase 1 output — post-refactor class contract
├── checklists/
│   └── requirements.md              # /speckit-specify output
└── tasks.md                         # Phase 2 output (/speckit-tasks; NOT created here)
```

### Source Code (repository root)

```text
includes/Embeds/
├── AbstractEmbedTransport.php       # NEW — abstract base + get_all_registered_transports() + is_enabled_for_server() + garbage_collect_orphans()
├── NpmEmbedTransport.php            # NEW — key 'npm', priority 10, label 'NPM Methods'
├── ClientEmbedTransport.php         # NEW — key 'client', priority 20, label 'MCP Clients'
└── AiConnectorEmbedTransport.php    # NEW — key 'ai_connector', priority 30, label 'AI Connectors'

includes/Database/ServerEmbedTransports/
├── Schema.php                       # NEW — BerlinDB Schema (columns + indexes + UNIQUE)
├── Table.php                        # NEW — BerlinDB Table subclass (v1.0.0, no upgrades yet)
├── Row.php                          # NEW — BerlinDB Row with typed properties
└── Query.php                        # NEW — is_enabled_for_server / set_enabled_for_server / get_all_for_server / delete_by_server_id

includes/Database/MCPServer/
├── Schema.php                       # MODIFIED — add embeds_enabled TINYINT(1) DEFAULT 0 column
└── Table.php                        # MODIFIED — $version 1.1.2 → 1.1.3 + register upgrade_to_1_1_3 callback

admin/Partials/ServerTabs/
├── EmbedsTab.php                    # NEW — extends AbstractServerTab; DEV5 hand-rolled form
└── Registry.php                     # MODIFIED — one-line addition registering EmbedsTab in all_tabs()

public/Renderers/EmbedBlock/
└── EmbedBlockRenderer.php           # NEW — final class, singleton; shortcode registration receiver + render

includes/Main.php                    # MODIFIED — 4 wire points: add_shortcode (define_public_hooks), EmbedsTab registration (define_admin_hooks), schema reconciliation callback (define_admin_hooks), new Table instantiation (load_hooks)

phpunit.xml.dist                     # ADD — <testsuite name="embeds"><directory>tests/phpunit/Embeds/</directory></testsuite>

.github/workflows/phpunit.yml        # ADD — one matrix step invoking --testsuite=embeds with tests/bootstrap-wp.php

tests/phpunit/Embeds/
├── AbstractEmbedTransportTest.php   # NEW — canonical enumeration + validation + dedup + priority sort + GC helper
├── ConcreteTransportsTest.php       # NEW — data-provider parameterized over 3 built-ins (key + label + priority)
├── EmbedsTabSaveHandlerTest.php     # NEW — nonce + cap + observability action firing matrix (4 scenarios per SC-010)
└── EmbedBlockRendererShortcodeTest.php  # NEW — gate cascade (4 combinations for SC-004) + F015-absent fail-open + hostile DTO escape
```

**Structure Decision**: Adds one new domain module (`includes/Embeds/`), one new BerlinDB module (`includes/Database/ServerEmbedTransports/`), one new admin tab (`admin/Partials/ServerTabs/EmbedsTab.php`), one new frontend renderer subdirectory (`public/Renderers/EmbedBlock/`). Follows constitution §"Architecture & UI Standards" directory-layout rule (namespace mirrors path). Modifies 4 existing files (Main.php + Registry.php + MCPServer/{Schema,Table}.php) — all changes strictly additive per FR-021. Test file organization: one primary test class per SUT concern (transport enumeration, concrete metadata, tab save handler, shortcode cascade) — 4 files, not one monolith.

## Complexity Tracking

> **No violations to justify — all 7 constitution principles pass without deviation (§IV pass depends on pre-approved DEV5 exception).** This section intentionally empty.

---

## Phase 0 — Outline & Research

*Prerequisites: Constitution Check ✅ passed above. Spec Clarifications section (Q1 singular keys, Q2 persist-silently + GC, Q3 two granular observability actions) resolved all ambiguities identified during `/speckit-clarify`.*

Four implementation-detail decisions the spec deferred to plan-phase — all documented in [`research.md`](./research.md):

1. **R1** — `_doing_it_wrong` version-tag value: `'0.1.10'` (F037's target release, matching F035's `'0.1.9'` precedent — matches F034's `'0.1.7'` shape).
2. **R2** — Memoization implementation on `AbstractEmbedTransport::is_enabled_for_server()`: static per-request cache keyed on `"{$server_id}:{$transport_key}"` with a public `flush_cache(): void` reset helper (matches F035's R2 pattern; production-shape name per B23).
3. **R3** — Observability action firing timing: fire AFTER the DB commit, INSIDE a try/catch per-listener (fail-forward per D19 — one broken listener MUST NOT block others OR roll back the DB write). Test coverage per SC-010.
4. **R4** — Uninstall.php behavior: on opt-in DELETE, DROP the new `wp_acrossai_mcp_server_embed_transports` table BUT keep the `embeds_enabled` column on `wp_acrossai_mcp_servers` (safer: additive-default-OFF stays; column will be untouched by future installs). Matches D21 fresh-install-only retirement pattern's inverse.

**Output**: `research.md` (created this run).

---

## Phase 1 — Design & Contracts

*Prerequisites: Phase 0 research complete.*

Three Phase 1 artifacts produced in the same run:

1. **[`data-model.md`](./data-model.md)** — schema definitions for the new junction table + column bump; DTO shape for the `AbstractEmbedTransport` public surface; `garbage_collect_orphans()` behavior contract; observability action payload shapes; memoization key/reset invariants.
2. **[`contracts/AbstractEmbedTransport.contract.md`](./contracts/AbstractEmbedTransport.contract.md)** — post-shipping class contract: 4 method signatures (2 abstract + 2 concrete-with-default), one enumeration static, one gate-lookup static, one GC helper static; filter-firing invariants; delegation invariants; grep-gate enforcement clauses.
3. **[`quickstart.md`](./quickstart.md)** — 5-minute walkthrough for a companion-plugin developer to (a) add a custom transport category, (b) gate their own frontend renderer on `is_enabled_for_server()`, (c) subscribe to the two observability actions for audit logging, (d) invoke the GC helper from their `uninstall.php`.

**Agent context update**: `CLAUDE.md` at the plugin root has an `Active plan` line pointing at `specs/035-connection-method-discovery-api/plan.md`. Update it to point at this plan file. Handled inline in tasks.md.

**Output**: `research.md`, `data-model.md`, `contracts/AbstractEmbedTransport.contract.md`, `quickstart.md`, updated `CLAUDE.md` reference.

## Post-Design Constitution Re-check

Phase 1 artifacts introduce zero new constraints or complexity beyond what the pre-Phase-0 gate assessed. All 7 principles still pass (§IV via pre-approved DEV5 exception; D13 escalation still flagged for post-implementation review). Complexity Tracking remains empty.
