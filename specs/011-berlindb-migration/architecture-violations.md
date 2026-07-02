# Architecture Guard — Violation Detection Report

**Reviewed plan**: `specs/011-berlindb-migration/plan.md`
**Reviewed spec**: `specs/011-berlindb-migration/spec.md`
**Constitution**: `.specify/memory/constitution.md`
**Memory synthesis**: `specs/011-berlindb-migration/memory-synthesis.md`
**Security review**: `specs/011-berlindb-migration/security-constraints.md`
**Date**: 2026-07-02
**Reviewer**: governed-plan orchestrator (architecture-guard inline pass)

---

## Scope

Framework-agnostic detection of architectural drift between the proposed plan and the project's constitutional principles + durable memory (A1–A15, S1–S9, B1–B14, D1–D15, DEV1–DEV4).

---

## Constitutional Principles Check

| Principle | Plan Compliance | Evidence |
|---|---|---|
| **I. Modular Architecture** | ✅ | Four `includes/Database/<Module>/` subdirs remain self-contained; no cross-module reach; `DefaultServerSeeder` is single-consumer to MCPServer module (per A15 not A9/§VI Utilities). |
| **II. WordPress Standards** | ✅ | PHPCS WPCS strict + PHPStan L8 gates preserved per task (§VII per-task gating). PHP 8.1+ target unchanged from Feature 010. No deprecated fns. |
| **III. Security First** | ✅ | SEC-001 atomic-CAS + SHA-256/PKCE column widths preserved (spec FR-006, FR-010). `$wpdb->prepare()` on every DB path (S4). No new REST/forms/nonce surface. |
| **IV. User-Centric Design (DataForm)** | ✅ | No new admin UI. DEV1 `WP_List_Table` exception on `admin/Partials/CliAuthLogListTable.php` explicitly preserved (spec FR-021 review-time rejection of drive-by DataViews conversion). |
| **V. Extensibility Without Core Modification** | ✅ | Hook wiring unchanged; no core mod; `berlindb/core` is a declared composer dep (Feature 010), not an optional integration. |
| **VI. Reusability & DRY** | ✅ | No candidate for `includes/Utilities/` promotion. `DefaultServerSeeder` is per-module (A15 family), not shared. |
| **VII. Definition of Done** | ✅ | Spec §Success Criteria enumerates 8 DoD gates; plan §Task Groups binds each task to its gate. |

---

## Architecture Constraints (A1–A15) Check

| Constraint | Status | Notes |
|---|---|---|
| **A1** — Hooks via Loader only | ✅ | Feature adds zero hooks. `register_activation_hook` wiring stays in plugin bootstrap; `Main::define_*_hooks()` unchanged. Caller sweep MUST NOT introduce a new `add_action` on any callsite (spec §Module Placement). |
| **A2** — Singleton + private ctor | ✅ | Four new Table subclasses + four new Query subclasses expose `public static function instance(): self` with `protected static $instance = null;` per sibling plugin shape. Row subclasses are BerlinDB base-Row descendants (no singleton — Row is instantiated per row). |
| **A3** — Admin partials in `admin/Partials/` | ✅ N/A | No admin partials created or moved. Caller sweep touches `admin/Partials/CliAuthLogListTable.php` under DEV1 preservation only. |
| **A4** — DataForm/DataViews | ✅ N/A | No new admin UI screens. DEV1 explicitly preserved (spec FR-021). |
| **A5** — MCP server listing via `wpb-mcp-servers-list` | ✅ N/A | Not in scope for this feature. |
| **A6** — `use` imports in `Admin\*` / `Includes\*` / `Public\*` | ✅ **LOAD-BEARING** | Every rewritten Table/Query/Row/Schema file uses `use BerlinDB\Database\Kern\{Table,Schema,Query,Row};` at file head. `Activator.php` uses `use ... Table as XxxTable;` per D6. Prevents B1 double-namespace silent failure. |
| **A7** — Plugin constants in `Main::define_constants()` | ✅ | No new plugin-wide constants. `DEFAULT_SERVER_SLUG` relocation is a per-module class constant (`DefaultServerSeeder::SLUG`), not a plugin constant. |
| **A8** — AccessControl via vendor pkg | ✅ N/A | Not touched by this feature. |
| **A9** — Shared constants in `includes/Utilities/` when read by ≥2 modules | ✅ | `DefaultServerSeeder::SLUG` is single-module (read only by MCPServer paths + Activator seeding call). Doesn't cross the ≥2-module threshold that would trigger A9 promotion. |
| **A10** — `WP_List_Table` singleton exemption | ✅ | `admin/Partials/CliAuthLogListTable.php` sweep preserves the class shape; DEV1 boundary check is a T7 DoD gate (spec FR-021). |
| **A11** — Pure service class singleton exemption | ✅ **LOAD-BEARING** | `DefaultServerSeeder` is a stateless static-helper class — no `instance()`, no ctor args, no hook registration. Correct placement per A11/A15 family. |
| **A12** — Pure-PHP modules with WP-free bootstrap | ✅ N/A | New Database classes depend on `$wpdb`, `wp_cache_delete`, `delete_option` — not WP-free. Plan's PHPUnit tests use WordPress test-case fixtures, not the pure-PHP harness. |
| **A13** — RFC-prescribed forms exempted from A4 | ✅ N/A | No RFC-prescribed forms. |
| **A14** — WP-CLI singleton exemption | ✅ N/A | Not a WP-CLI class. |
| **A15** — Database-namespace static helpers follow A11/A14 family | ✅ **LOAD-BEARING** | `DefaultServerSeeder` sits at `includes/Database/MCPServer/DefaultServerSeeder.php` (Database namespace), is stateless, wraps `$wpdb->insert()`. Exact A15 shape. |

---

## Active Decisions (D1–D15) Check

| Decision | Status | Impact on Plan |
|---|---|---|
| **D6** — Activator uses `use` imports for DB class references | ✅ | Plan §Project Structure adds `use ... Table as XxxTable;` for all four modules. |
| **D4** — `class_exists()` guards in Activator are silent no-op | ✅ **SCOPE-NARROWED** | Plan spec FR-015 explicitly forbids `class_exists( XxxTable::class )` guards around the four `maybe_upgrade()` calls (after FR-011 the FQNs autoload cleanly). D4's rationale (silent no-op on fail) is preserved for other patterns; this feature narrows scope to the four Table calls. |
| **D7** — Activator does NOT call `insert_default_server()` | 🔴 **SUPERSEDED** | Plan explicitly reverses this. Spec FR-016 + FR-017 mandate the extraction to `DefaultServerSeeder::seed()` and the Activator call. TASK-8 memory hygiene will mark D7 as Superseded (Feature 011) per FR-023. |
| **D9** — BerlinDB-style Query interface hand-rolled, no `berlindb/core` vendor dep | 🔴 **SUPERSEDED** | Plan explicitly reverses this. The four Query classes now extend `\BerlinDB\Database\Kern\Query`. TASK-8 memory hygiene will mark D9 as Superseded (Feature 011) per FR-023. |
| **D15** — Shared package bootstrap on `plugins_loaded` P0 + pre-activation vendor guard on `activate_<plugin>` P1 | ✅ **EXTENDED** | FR-012 preserves the P1 pre-guard verbatim; FR-011 extends the same family with an in-callback autoload require. Same defense-in-depth pattern applied to the same problem class. |

---

## Bug Patterns (B1–B14) Guard Check

| Pattern | Status | Guard |
|---|---|---|
| **B1** — Namespace double-Includes silent failure | ✅ | Every rewritten class uses `use \BerlinDB\Database\Kern\{Table,Schema,Query,Row};` at file head with leading backslash. `use ... Table as XxxTable;` for the four in-plugin references in `Activator.php`. Prevents `AcrossAI_MCP_Manager\Includes\Database\Includes\...` drift. |
| **B7** — Mass-assignment via forged POST keys to `$wpdb->update/insert` | ⚠ **NEW-FEATURE-RISK** | Caller sweep may introduce a `add_item( $_POST )` shortcut. Plan §Task Groups T7 gate + security-constraints.md T7 recommendation call this out; explicit B7 audit at T7 DoD. |
| **B10** — Check-then-act on one-shot creds under concurrency | ✅ **LOAD-BEARING** | Spec FR-006 makes the atomic-CAS semantic contract non-negotiable. PHPUnit gate at plan `AtomicCasTest.php`. |
| **B14** — `register_activation_hook` P10 fatals before higher-priority guards | ✅ **LOAD-BEARING** | Spec FR-011 + FR-012 layer both defenses (P1 pre-guard + P10-callback in-line `require_once`). Direct B14 satisfaction. |

Other B-patterns (B2, B3, B4, B5, B6, B8, B9, B11, B12, B13) do not surface in this feature's scope.

---

## Accepted Deviations (DEV1–DEV4) Check

| Deviation | Status | Notes |
|---|---|---|
| **DEV1** — MCP Manager parent menu `WP_List_Table` exception | ✅ **NON-WIDENING GATE** | Sweep touches `admin/Partials/CliAuthLogListTable.php`. Spec FR-021 codifies that the sweep MUST NOT convert to DataViews. T7 DoD includes `grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php` == 1 check. |
| **DEV2** — `includes/Compat.php` boot-time placement | ✅ N/A | Not touched. |
| **DEV3** — Bidirectional `FrontendAuth` ↔ `CliController` import (Feature 007) | ✅ N/A | Not touched. |
| **DEV4** — FR-030 pre-activation vendor guard (Feature 010, D15 family) | ✅ **PRESERVED + EXTENDED** | FR-012 preserves the P1 guard verbatim; FR-011 extends the same family. |

---

## Security Constraints (S1–S9) Check

| Constraint | Status | Notes |
|---|---|---|
| **S1** — Nonce on forms/AJAX | ✅ N/A | No form/AJAX added. |
| **S2** — REST `permission_callback` explicit | ✅ N/A | No REST route added; existing routes' contracts unchanged (governed by Feature 006). |
| **S3** — OAuth tokens SHA-256 hashed | ✅ **LOAD-BEARING** | Spec FR-010 fixes `char(64)` on `access_token_hash` and `auth_code_hash`. |
| **S4** — `$wpdb->prepare()` mandatory | ✅ **LOAD-BEARING** | Spec FR-006 mandates for bespoke redeem/purge methods; BerlinDB base classes use prepare internally. |
| **S5** — `admin_url()` → `esc_url()` | ✅ N/A | No admin URL rendering added. |
| **S6** — Singleton `__construct()` private | ✅ | New Table/Query singletons declare private constructors per sibling pattern. |
| **S7** — OAuth token endpoint `__return_true` exception | ✅ N/A | Not touched. |
| **S8** — Body-authenticated CLI device-code-grant exception | ✅ N/A | Not touched. |
| **S9** — Consent-surface displayed-state from server-authoritative store | ✅ N/A | No consent surface added or modified. |

---

## Security-Architecture Conflicts

None detected. The security review's three RECOMMEND items (production-install audit, FR-010 code-review gate, B7 audit at T7) are consistent with the architecture guard's DEV1 non-widening gate and A6/B1 load-bearing gates — all four gates land at T7's DoD as complementary checks.

---

## Drift & Consistency Risks

- **NONE HARD** — plan is internally consistent with spec + memory-synthesis + constitution.
- **SOFT — memory-synthesis Conflict Warnings row on DEV1**: still active as a plan-phase reminder that spec FR-021 codifies but T7's DoD must automate.

---

## Recommendations for `/speckit-tasks`

1. Preserve the T1..T8 task-group structure from plan.md §Task Groups; add a T0 (pre-flight production-install audit — from security-constraints.md).
2. T7 DoD MUST include:
   - PHPStan L8 + PHPCS green across the whole plugin (not just swept files)
   - `grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php` == 1 (DEV1 boundary)
   - `grep -rn 'add_item\s*(\s*\$_POST' --include='*.php' includes/ admin/ public/` returns zero (B7 audit)
3. T8 MUST update INDEX.md AND DECISIONS.md AND WORKLOG.md in the same commit — memory-synthesis relies on all three being coherent.
4. Every task's DoD MUST include the constitution §VII per-task gate (PHPCS + PHPStan green before the next task starts).

---

## Status

**PASS** — no HARD architectural conflicts. Plan is ready for `/speckit-tasks`.
