# Architecture Guard — Violation Detection Report

**Reviewed plan**: `specs/010-composer-dependencies/plan.md`
**Reviewed spec**: `specs/010-composer-dependencies/spec.md`
**Constitution**: `.specify/memory/constitution.md`
**Memory synthesis**: `specs/010-composer-dependencies/memory-synthesis.md`
**Security review**: `specs/010-composer-dependencies/security-constraints.md`
**Date**: 2026-07-01
**Reviewer**: governed-plan orchestrator (architecture-guard pass)

---

## Scope

Framework-agnostic detection of architectural drift between the proposed plan and the project's constitutional principles + durable memory (A1–A15, S1–S9, B1–B13, D1–D14, DEV1–DEV3).

---

## Constitutional Principles Check

| Principle | Plan Compliance | Evidence |
|---|---|---|
| **I. Modular Architecture** | ✅ | Feature 010 preserves module boundaries. Menu.php remains an `admin/Partials/` class delegating to a vendor namespace; does not expand its own responsibilities. |
| **II. WordPress Standards** | ✅ | PHPCS WPCS strict + PHPStan L8 gates preserved on touched files. No deprecated fns. |
| **III. Security First** | ✅ | §III surfaces structurally null this feature (no forms/REST/DB/transient/consent surface). `class_exists` guards preserved (FR-025). Consent-surface exception (2026-06-30 amendment) untouched. |
| **IV. User-Centric Design (DataForm)** | ✅ N/A | No new admin UI screens; menu registration rewire only. |
| **V. Extensibility Without Core Modification** | ✅ | Hook wiring via Loader (unchanged shape or auto-hook per TASK-1 outcome); no core mod. |
| **VI. Reusability & DRY** | ✅ | Consumes vendor `\AcrossAI_Co\MainMenu\...` API instead of duplicating menu-registration boilerplate. `AdminPageSlugs::plugin_screen_ids()` remains canonical (A9). |
| **VII. Definition of Done** | ✅ | 10 DoD gates listed in spec §Success Criteria. |

---

## Architecture Constraints (A1–A15) Check

| Constraint | Status | Notes |
|---|---|---|
| **A1** — Hooks via Loader only | ✅ **NUANCED** | Two possible outcomes both A1-compliant: (a) if `acrossai-co/main-menu` auto-hooks `admin_menu` internally, we REMOVE the Loader entry for Menu.php in `define_admin_hooks()` — Menu.php becomes a config-provider class, not a hook target; (b) if the package expects manual hook wiring, we UPDATE the Loader entry to point at the correct method. TASK-1 output determines the shape. Neither is a violation. |
| **A2** — Singleton + private ctor | ✅ N/A | `admin/Partials/Menu.php` is Loader-injected instance pattern (not classic singleton). Not modified in this feature's scope. |
| **A3** — Admin partials in `admin/Partials/` | ✅ | `Menu.php` remains at `admin/Partials/Menu.php` with namespace `AcrossAI_MCP_Manager\Admin\Partials`. |
| **A4** — DataForm/DataViews | ✅ N/A | No new admin UI screens. |
| **A5** — MCP server listing via vendor pkg | ✅ N/A | Not in scope. |
| **A6** — `use` imports in `Admin\*` / `Includes\*` / `Public\*` | ✅ | Menu.php migration adds `use \AcrossAI_Co\MainMenu\...` at top of file per FR-018. Prevents B1 double-namespace bug. |
| **A7** — Plugin constants in `Main::define_constants()` | ✅ | No new plugin-wide constants. Existing `ACROSSAI_MCP_MANAGER_*` constants consumed as-is. |
| **A8** — AccessControl via vendor pkg | ✅ | Feature 010 codifies this by making `wpb-access-control` a hard require. |
| **A9** — Shared constants in `includes/Utilities/` when read by ≥2 modules | ✅ **LOAD-BEARING** | `AdminPageSlugs::plugin_screen_ids()` remains canonical whitelist. FR-022 mandates ADDITIVE updates only if TASK-6 detects screen ID prefix drift. Phase 8's admin asset enqueue guard invariant (in `admin/Main.php`) depends on this. Never remove existing IDs. |
| **A10** — WP_List_Table singleton exemption | ✅ N/A | Not modified. |
| **A11** — Pure service class singleton exemption | ⚠ **OPPORTUNITY (D14)** | If `acrossai-co/main-menu` publishes public-static predicates (e.g. `Registry::is_plugin_screen()`), those would fit A11 (pure, stateless, static). Consumer could import via `use` per D14 precedent. TASK-1 investigates. |
| **A12** — Pure-PHP modules with WP-free bootstrap | ✅ N/A | Not applicable. |
| **A13** — RFC-prescribed forms exempted from A4 | ✅ N/A | No RFC-prescribed forms. |
| **A14** — WP-CLI singleton exemption | ✅ N/A | Not a WP-CLI class. |
| **A15** — Audit-recorder singleton exemption | ✅ N/A | No audit surface. |

---

## Bug Pattern Defenses (B1–B13) Check

| Pattern | Status | Defense |
|---|---|---|
| **B1** — Namespace relative-path bug | ✅ | `use` import for `\AcrossAI_Co\MainMenu\...` per A6; PHPStan L8 catches any bare reference. |
| **B2** — `define_constants` null-property | ✅ N/A | Plugin constants unchanged. |
| **B3** — TODO stub FQN drift | ✅ N/A | No stubs modified. |
| **B4** — Unescaped dot in PCRE rewrite | ✅ N/A | No rewrite rules added. |
| **B5** — Public ctor on singleton | ✅ N/A | Menu.php uses Loader-injected instance pattern (not singleton with private ctor). |
| **B6** — `admin_url()` without `esc_url()` | ✅ | Admin URL references remain the plugin's existing pattern; Feature 010 does not add new `admin_url()` calls. |
| **B7** — Mass-assignment via forged POST keys | ✅ N/A | No POST handling. |
| **B8** — "esc_url'd above" comments | ✅ N/A | Not applicable. |
| **B9** — PHPUnit 13+ `@dataProvider` docblock | ✅ N/A | Per §Clarifications Q1, no new PHPUnit files this feature. |
| **B10** — Atomic CAS for one-shot credentials | ✅ N/A | No credentials. |
| **B11** — Defensive triple-check on structured reads | ✅ N/A | No `.asset.php` or transient consumption at runtime by Feature 010 code. Autoload manifest read is bootstrap-time, handled by jetpack-autoloader itself. |
| **B12** — `wp_enqueue_scripts` non-firing on `template_redirect` exit | ✅ N/A | No `template_redirect` handlers touched. |
| **B13** — `wp_redirect` filter throw-from-filter | ✅ N/A | No redirect paths tested. |

---

## Security Constraints (S1–S9) Check

| Constraint | Status | Notes |
|---|---|---|
| **S1** — Nonce on form/AJAX | ✅ N/A | No forms/AJAX. |
| **S2** — REST `permission_callback` explicit | ✅ N/A | No new REST routes. |
| **S3** — OAuth tokens hashed | ✅ N/A | No credential handling. |
| **S4** — `$wpdb->prepare()` | ✅ N/A | No DB queries. |
| **S5** — `admin_url()` wrapped with `esc_url()` | ✅ | Feature 010 does not add new `admin_url()` output paths. Existing paths in `admin/Partials/*.php` verified during TASK-7. |
| **S6** — Singleton private ctor | ✅ N/A | Menu.php not singleton. |
| **S7** — OAuth token endpoint S2 exception | ✅ N/A | Not an OAuth route. |
| **S8** — Body-authenticated mutating REST routes | ✅ N/A | Not a REST module. |
| **S9** — Consent-surface displayed-state from authoritative store | ✅ **Untouched** | Feature 010 does not render consent-surface state. Phase 7's `FrontendAuth::handle_cli_auth` sourcing from `CliController::peek_pending_server` preserved intact. |

---

## Decision Alignment (D1–D14) Check

| Decision | Alignment | Notes |
|---|---|---|
| **D5** — PHPCS baseline exceptions | ✅ Followed | Feature 010's Menu.php + composer.json edits do NOT introduce new PHPCS exclusions. Existing baseline preserved. |
| **D10** — Minimal-Port Deferral Pattern (Feature-002) | ✅ Applied | Feature 010 defers real-BerlinDB adoption to Feature 011 per FR-026 + FR-028. Two Query-class-migration effort points (small = Feature 010 composer add; large = Feature 011 refactor) are correctly separated. |
| **D11** — Phase X.0 Absorption Pattern | ✅ N/A | Not applied — no prereq absorbed into this phase. |
| **D13** — Constitution amend vs. Accepted Deviation | ✅ N/A | FR-012 constitution edit is docs (tech-stack refresh), not a §I–§VII principle change. No governance escalation trigger. |
| **D14** — Cross-phase state observation via public-static predicate | ⚠ **OPPORTUNITY** | TASK-1 investigates whether `acrossai-co/main-menu` publishes public-static predicates. If YES, `admin/Main.php` can consume via `use` import (mirrors Phase 6/7/8 pattern). If NO, `AdminPageSlugs::plugin_screen_ids()` remains canonical per A9. Both outcomes acceptable. |
| Other D-entries | ✅ N/A | D1–D4, D6–D9, D12 not triggered. |

---

## Accepted Deviation (DEV1–DEV3) Check

| Deviation | Status |
|---|---|
| **DEV1** — MCP Manager parent menu uses WP_List_Table | ✅ N/A — Menu.php migration does NOT touch the list-table implementation. The DEV1 exemption covers `MCPServerListTable.php`, which is a separate file consumed by the menu but not rewritten in Feature 010. |
| **DEV2** — `Compat.php` in `includes/` | ✅ N/A — not modified. |
| **DEV3** — Bidirectional Phase 6 ↔ Phase 7 coupling | ✅ **Context** — Feature 010 must NOT create a parallel bidirectional coupling. Menu.php is a leaf consumer of `\AcrossAI_Co\MainMenu\...` and (indirectly via class_exists guards) `\WPBoilerplate\AccessControl\...`. Zero bidirectional imports created. |

---

## Drift Findings

### Drift 1 — A1 auto-hook nuance for third-party package (informational)

**Type**: A1 wiring shape decision
**Severity**: P3 (research-resolved at TASK-1)
**Plan location**: §Constitution Check A1 row + §Phase 0 Output Plan R1
**Action**: TASK-1 research resolves whether `acrossai-co/main-menu` auto-hooks `admin_menu` internally. Both outcomes (remove Loader entry vs. update it) are A1-compliant. No new memory capture warranted — this is A1 in application, not a new lesson.

### Drift 2 — D14 opportunity for predicate consumption (informational)

**Type**: D14 pattern application opportunity
**Severity**: P3 (research-resolved at TASK-1)
**Plan location**: §Constitution Check D14 row + §Phase 0 Output Plan R1
**Action**: If `acrossai-co/main-menu` publishes public-static predicates, `admin/Main.php` can consume via `use` import (mirrors Phase 6/7/8 pattern). If NOT, `AdminPageSlugs::plugin_screen_ids()` remains canonical per A9. Both outcomes acceptable. No new memory capture warranted — this is D14 in application.

### Drift 3 — Post-cutover Feature 011 timing acknowledgment (informational)

**Type**: Cross-feature sequencing
**Severity**: P3 (spec-clarified 2026-07-01)
**Plan location**: spec §Clarifications Q2 + §Non-Goals
**Action**: Feature 011 (BerlinDB Query refactor) is non-blocking for `feature/issue-3 → main` cutover. Custom Query classes remain functional per Phase 2 test coverage. No governance implication.

---

## Security-Architecture Conflicts

**None.**

- No CRITICAL Constitution deviations (§III is structurally null-surfaced this feature).
- No HIGH boundary erosions.
- No MEDIUM pattern drift.
- The security review's Advisory 1 (vendor/-diff CI gate) is release-infrastructure concern, not architectural conflict.
- The security review's Advisory 2 (guard removal timeline) is documentation-level, not architectural conflict.
- Feature-007 §III Consent-surface exception preserved.
- Feature-008 admin asset enqueue guard invariant preserved via A9 + FR-022.
- Feature-009 MCP boot guard pattern preserved (this feature does NOT touch MCP/Controller.php).

---

## Consistency Risks

| Risk | Mitigation |
|---|---|
| `acrossai-co/main-menu` future version drift (v0.1.0 might introduce breaking changes) | Composer `^0.0.8` constraint allows only patch updates within 0.0.x. Post-1.0.0 the caret behavior expands to `<1.0`. Watch upstream release notes. |
| Jetpack autoloader ^5.0 shape drift | Verified at TASK-4 by plugin activation smoke test. If shape drifts, revert to ^4.0 or ^3.x pin. |
| `AdminPageSlugs::plugin_screen_ids()` whitelist grows unbounded across features | Currently 3–5 entries (per Phase 2/3 baseline). Feature 010 adds at most 1–2 (TASK-6 conditional). If whitelist exceeds 20 entries, refactor to config-driven approach — but that's a future concern, not this feature's. |
| Constitution tech-stack section becomes stale as packages update | FR-012 keeps constitution current. Future feature adding a package must also touch constitution. Pattern established by Feature 006 (phpstan/phpstan require-dev). |

---

## Final Verdict

✅ **Plan PASSES architecture review.** Zero blocking violations. Three informational drift findings documented above, all resolved at TASK-1 research or documented as informational.

Zero security-architecture conflicts. Zero new memory-capture candidates surfaced at plan-time. The A1 nuance + D14 opportunity are TASK-1 research outputs (not new lessons); Feature 011 timing is a spec-clarified sequencing note (not a new decision).

Proceed to `/speckit-tasks`.
