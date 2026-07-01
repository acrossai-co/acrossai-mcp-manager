# Architecture Guard — Violation Detection Report

**Reviewed plan**: `specs/008-asset-build-pipeline/plan.md`
**Reviewed spec**: `specs/008-asset-build-pipeline/spec.md`
**Constitution**: `.specify/memory/constitution.md`
**Memory synthesis**: `specs/008-asset-build-pipeline/memory-synthesis.md`
**Security review**: `specs/008-asset-build-pipeline/security-constraints.md`
**Date**: 2026-07-01
**Reviewer**: governed-plan orchestrator (architecture-guard pass)

## Scope

Framework-agnostic detection of architectural drift between the proposed plan and the project's constitutional principles + durable memory (A1–A15, S1–S9, B1–B13, D1–D13, DEV1–DEV3).

## Constitutional Principles Check

| Principle | Plan Compliance | Evidence |
|---|---|---|
| **I. Modular Architecture** | ✅ | Single narrow-purpose file edit (`public/Main.php`); no new abstractions; preserves Phase 7 boundary explicitly. |
| **II. WordPress Standards** | ✅ | PHPCS WPCS strict + PHPStan L8 in DoD; no deprecated fns. |
| **III. Security First** | ✅ | §III's normal surfaces structurally absent (no forms/REST/DB/transient). "No hardcoded version strings" clause enforced via SC-008. Consent-surface exception preserved intact (Feature-007 territory). |
| **IV. User-Centric Design (DataForm)** | ✅ N/A | No admin UI. |
| **V. Extensibility Without Core Modification** | ✅ | Hooks via Loader (unchanged); no core mod. |
| **VI. Reusability & DRY** | ✅ | Consumes `AdminPageSlugs::plugin_screen_ids()` (Phase 2 A9); consumes Phase 7's `get_query_var('acrossai_mcp_auth')` predicate for CLI-surface guard; consumes Phase 5's OAuth predicate (per R1 research). No duplication. |
| **VII. Definition of Done** | ✅ | 10 DoD gates listed in spec §Success Criteria; 4 pre-ship validation scripts as release gate. |

## Architecture Constraints (A1–A15) Check

| Constraint | Status | Notes |
|---|---|---|
| **A1** — Hooks via Loader only | ✅ | Zero new `add_action`/`add_filter`. Existing wiring in `Includes\Main::define_public_hooks()` unchanged. |
| **A2** — Singleton + private ctor | ✅ N/A | `public/Main.php` uses the Loader-injected instance pattern from Phase 1, not classic singleton. Not modified. |
| **A3** — Admin partials in `admin/Partials/` | ✅ N/A | This is `public/`, not `admin/`. |
| **A4** — DataForm/DataViews | ✅ N/A | No admin UI. |
| **A5** — MCP server listing via vendor pkg | ✅ N/A | Not in scope. |
| **A6** — `use` imports in `Public\*` | ✅ | Any cross-namespace refs added to `public/Main.php` will use `use` imports per plan §Technical Context constraints. Prevents B1. |
| **A7** — Plugin constants in `Main::define_constants()` | ✅ | No new plugin-wide constants. `ACROSSAI_MCP_MANAGER_PLUGIN_FILE` + `_VERSION` consumed from existing definitions. |
| **A8** — AccessControl via vendor pkg | ✅ N/A | Not in scope. |
| **A9** — Shared constants in `includes/Utilities/` when read by ≥2 modules | ✅ | `AdminPageSlugs::plugin_screen_ids()` remains canonical; NOT duplicated. OAuth predicate is per-surface (Phase 5 owns it) — not shared, so A9 promotion trigger does not fire. |
| **A10** — WP_List_Table singleton exemption | ✅ N/A | Not in scope. |
| **A11** — Pure service class singleton exemption | ✅ N/A | `public/Main.php` holds instance state (`$plugin_name`, `$version`, `$js_asset_file`, `$css_asset_file`); not pure. |
| **A12** — Pure-PHP modules with WP-free bootstrap | ✅ N/A | Not a pure-PHP module. |
| **A13** — RFC-prescribed forms exempted from A4 | ✅ N/A | No forms. |
| **A14** — WP-CLI singleton exemption | ✅ N/A | Not WP-CLI. |
| **A15** — Audit-recorder singleton exemption | ✅ N/A | No audit surface. |

## Bug Pattern Defenses (B1–B13) Check

| Pattern | Status | Defense |
|---|---|---|
| **B1** — Namespace relative-path bug | ✅ | `use` imports per A6; PHPStan L8 catches. |
| **B2** — `define_constants` null-property | ✅ N/A | Not modified. |
| **B3** — TODO stub FQN drift | ✅ N/A | No stubs. |
| **B4** — Unescaped dot in PCRE rewrite | ✅ N/A | No rewrite rules added. |
| **B5** — Public ctor on singleton | ✅ N/A | Loader-injection pattern; not singleton. |
| **B6** — `admin_url()` without `esc_url()` | ✅ | Not using `admin_url` — uses `plugins_url()` for enqueue src. `wp_enqueue_style` handles the URL emit; not a direct output-escape surface. |
| **B7** — Mass-assignment via forged POST keys | ✅ N/A | No POST handling. |
| **B8** — "esc_url'd above" comments | ✅ N/A | Not applicable. |
| **B9** — PHPUnit 13+ `@dataProvider` | ⚠ **Implementation-time check** | If `MainEnqueueTest.php` is written (plan §Test surface option A), the implementer MUST use `#[DataProvider]` PHP attributes. Add to `/speckit-tasks` output. |
| **B10** — Atomic CAS for one-shot credentials | ✅ N/A | No credentials. |
| **B11** — Transient defensive triple-check | ✅ **GENERALIZED APPLICATION** | Applied to `require build/*.asset.php` return value in `public/Main.php`. Plan §Technical Context / §Constitution Check explicit. Same shape guard as `admin/Main.php::read_asset_manifest()`. This is the load-bearing runtime defense. |
| **B12** — `wp_enqueue_scripts` non-firing on `template_redirect` exit | ✅ **LOAD-BEARING** | Direct application: informs FR-020 option (b) reconciliation. Plan §Summary / §Constitution Check both cite it. Without this pattern being captured, the plan would have taken option (a) and shipped dead code. |
| **B13** — `wp_redirect` filter throw-from-filter for tests | ⚠ **Implementation-time check** | If `MainEnqueueTest.php` tests any redirect path (unlikely — enqueue methods don't redirect), the pattern applies. Likely N/A for Phase 8 tests. |

## Security Constraints (S1–S9) Check

| Constraint | Status | Notes |
|---|---|---|
| **S1** — Nonce on form/AJAX | ✅ N/A | No forms/AJAX. |
| **S2** — REST `permission_callback` explicit | ✅ N/A | No REST routes. |
| **S3** — OAuth tokens hashed | ✅ N/A | No credential handling. |
| **S4** — `$wpdb->prepare()` | ✅ N/A | No DB queries. |
| **S5** — `admin_url()` wrapped with `esc_url()` | ✅ N/A | Not using `admin_url`. |
| **S6** — Singleton private ctor | ✅ N/A | Loader-injected. |
| **S7** — OAuth token endpoint S2 exception | ✅ N/A | Not an OAuth route. |
| **S8** — Body-authenticated mutating REST routes | ✅ N/A | Not a REST module. |
| **S9** — Consent-surface displayed-state from authoritative store | ✅ **Adjacent invariant preserved** | Phase 8 does NOT render consent-surface displayed state. It DOES consume Phase 7's authoritative predicate (`get_query_var('acrossai_mcp_auth')`) for the CLI-surface guard — matching S9's spirit of consuming the authoritative source rather than re-inspecting inputs. |

## Decision Alignment (D1–D13) Check

| Decision | Alignment | Notes |
|---|---|---|
| **D5** — PHPCS baseline exceptions preserved | ✅ | Phase 8's `public/Main.php` edit MUST NOT introduce new PHPCS exclusions. |
| **D11** — Phase X.0 absorption | ✅ N/A | Not applied. |
| **D13** — Constitution vs. Accepted Deviation escalation | ✅ N/A | Phase 8 has zero deviations; no escalation decision needed. |
| Other D-entries | ✅ N/A | D1–D4, D6–D10, D12 not triggered. |

## Accepted Deviation (DEV1–DEV3) Check

| Deviation | Status |
|---|---|
| **DEV1** — MCP Manager parent menu WP_List_Table | ✅ N/A — no admin UI |
| **DEV2** — `Compat.php` in `includes/` | ✅ N/A — not modified |
| **DEV3** — Bidirectional Phase 6 ↔ Phase 7 coupling | ✅ **CONTEXT** | Phase 8 explicitly avoids creating a parallel Phase 7 ↔ Phase 8 bidirectional coupling. `public/Main.php` does NOT import `FrontendAuth`. Plan §Summary + §Constitution Check + memory-synthesis §Conflict-Warnings all cite this as the second reason for FR-020 option (b). |

## Drift Findings

### Drift 1 (INFO — non-blocking) — Handle naming consistency

**Type**: Naming hygiene (security-constraints.md Advisory 2)
**Severity**: P3 (documentation)
**Plan location**: implicit — the plan preserves the existing `$this->plugin_name` (`acrossai-mcp-manager`) as the enqueue handle for `public/Main.php`
**Recommendation**: In `contracts/public-main-enqueue.md` (Phase 1), consider renaming the handle to `acrossai-mcp-frontend-oauth` for clarity now that the enqueue is OAuth-scope-only. The rename would align with Phase 7's `acrossai-mcp-frontend` handle naming and eliminate the historical global-scope implication of the plugin-name handle.
**Action**: NONE required for merge. Task-level decision at `/speckit-tasks`.

### Drift 2 (INFO — non-blocking) — B9 / B13 test-pattern reminders

**Type**: Bug pattern defense at implementation time
**Severity**: P3 (PHPCS-catchable or test-runtime-catchable)
**Recommendation**: `/speckit-tasks` output should include reminders — if writing `MainEnqueueTest.php`, use `#[DataProvider]` PHP attributes (B9) and, if any redirect path is exercised, the throw-from-filter pattern (B13). B13 is likely N/A for enqueue-only tests.

## Security-Architecture Conflicts

**None.**

- No CRITICAL Constitution deviations (§III is structurally null-surfaced this phase).
- No HIGH boundary erosions (Phase 7 boundary preserved by design).
- No MEDIUM pattern drift (all memory patterns applied).
- The security review's Advisory 1 (RTL data) and Advisory 2 (handle naming) are documentation/DoD concerns, not architectural conflicts.

## Consistency Risks

| Risk | Mitigation |
|---|---|
| Phase 5 OAuth predicate signature might change in the future (Phase 8 hard-codes today's shape) | Plan §Phase 0 R1 explicitly resolves the predicate as a research question; if it later evolves, `public/Main.php` needs the corresponding update. Not a blocker for shipping. |
| `admin/Main.php` FR-012/FR-015 could regress in a future refactor without notice | `MainEnqueueTest.php` (Option A) provides a regression net for both admin AND public paths; recommend including light admin coverage even though Phase 2 tests exist. |
| Build-artifact commit policy is inconsistent across the plugin family (some projects gitignore `build/`) | R2 Phase 0 research confirms the local convention (commit build/). If ever changed, Phase 8's tasks need adjustment. |

## Final Verdict

✅ **Plan PASSES architecture review.** Zero blocking violations. Two INFO-severity drift findings (naming, test-pattern reminders) — both documentation-only, resolved at task-generation time.

Zero security-architecture conflicts. Zero new memory-capture candidates surfaced (Phase 8 applies existing patterns; the memory-informed FR-020 option (b) is the pattern in action, not a new lesson).

Proceed to `/speckit-tasks`.
