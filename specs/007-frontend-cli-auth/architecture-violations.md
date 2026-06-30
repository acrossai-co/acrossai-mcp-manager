# Architecture Guard — Violation Detection Report

**Reviewed plan**: `specs/007-frontend-cli-auth/plan.md`
**Reviewed spec**: `specs/007-frontend-cli-auth/spec.md`
**Constitution**: `.specify/memory/constitution.md`
**Memory synthesis**: `specs/007-frontend-cli-auth/memory-synthesis.md`
**Security review**: `specs/007-frontend-cli-auth/security-constraints.md`
**Date**: 2026-06-26
**Reviewer**: governed-plan orchestrator (architecture-guard pass)

## Scope

Framework-agnostic detection of architectural drift between the proposed plan and the project's constitutional principles + durable memory entries (A1–A15, S1–S8, B1–B11, D1–D12, DEV1–DEV2).

## Constitutional Principles Check

| Principle | Plan Compliance | Evidence |
|---|---|---|
| **I. Modular Architecture** | ✅ | Single-file class with one purpose; shared utilities not needed (class-local constants only). |
| **II. WordPress Standards** | ✅ | PHPCS WPCS strict + PHPStan L8 mandated in DoD gates. FR-016 adds `WordPress.WP.I18n` rule compliance. |
| **III. Security First** | ⚠ **1 documented deviation** | `manage_options` broadened to any-logged-in-user. Captured in plan §Complexity Tracking with threat-model rationale. Reviewed + approved in `security-constraints.md`. |
| **IV. User-Centric Design** | ✅ N/A | No admin UI in this phase. DataForm mandate does not apply. |
| **V. Extensibility Without Core Modification** | ✅ | One hard dep (`CliController::approve_auth_code`); all hooks via Loader. |
| **VI. Reusability & DRY** | ✅ | `PAGE_SLUG`/`QUERY_VAR` class constants deduplicate magic strings. `get_base_url()` is the single URL source for downstream consumers. |
| **VII. Definition of Done** | ✅ | All 8 DoD gates listed in spec §Success Criteria. |

## Architecture Constraints (A1–A15) Check

| Constraint | Status | Notes |
|---|---|---|
| **A1** — Hooks via Loader only | ✅ | FR-014 mandates 4 hooks wired in `Main::define_public_hooks()`. Verified existing impl already has lines 417–421. Constructor remains empty. |
| **A2** — Singleton + private ctor | ✅ | FR-002 mandates exact pattern. Not exempt under A11/A14/A15 — class is hook-wired and holds rendering logic. |
| **A3** — Admin partials in `admin/Partials/` | ✅ N/A | This is a `public/Partials/` class (FrontendAuth). A3 governs `admin/`, not `public/`. |
| **A4** — DataForm/DataViews on new admin UI | ✅ N/A | No admin UI. |
| **A5** — MCP server listing via `wpb-mcp-servers-list` | ✅ N/A | Not in scope. |
| **A6** — `use` imports or leading `\` FQN in `Includes\*` / `Public\*` | ✅ | Plan + memory-synthesis both call out `use AcrossAI_MCP_Manager\Includes\REST\CliController;` at top of file. Prevents B1. |
| **A7** — All 6 plugin constants in `Main::define_constants()` | ✅ | `PAGE_SLUG` / `QUERY_VAR` are CLASS constants (`const`), not `define()`-style — A7 applies only to plugin-wide globals. |
| **A8** — AccessControl via vendor package | ✅ N/A | Not in scope. |
| **A9** — Shared constants in `includes/Utilities/` when read by ≥2 modules | ⚠ **Promotion-deferred** | `PAGE_SLUG` is read by 3 sites: FrontendAuth (owner), Activator (rewrite-rule call), and Phase 6 `CliController::auth_start()` (indirectly via `get_base_url()`). The two external consumers reach the value via `FrontendAuth::PAGE_SLUG` directly OR via `FrontendAuth::get_base_url()` indirectly. **Plan keeps the constant class-local**; A9 promotion would require introducing `includes/Utilities/Constants.php` with a `FRONTEND_AUTH_PAGE_SLUG` const, adding indirection for a single value. **Acceptable as-is**; revisit if a 4th consumer ever reads the slug. |
| **A10** — WP_List_Table singleton exemption | ✅ N/A | Not in scope. |
| **A11** — Pure service class singleton exemption | ✅ N/A | FrontendAuth is NOT pure (holds rendering logic + is hook-wired). A2 applies; A11 does not. |
| **A12** — Pure-PHP modules with WP-free bootstrap | ✅ N/A | FrontendAuth is WP-coupled (needs `wp_verify_nonce`, `home_url`, `template_redirect`). Tests require WP-PHPUnit bootstrap (already exists). |
| **A13** — RFC-prescribed forms exempted from A4 | ✅ N/A | The consent page is NOT RFC-prescribed (this is CLI device-code-grant style, not OAuth2). Plus, A4 doesn't apply anyway (no admin UI). |
| **A14** — WP-CLI dispatch singleton exemption | ✅ N/A | Not a WP-CLI class. |
| **A15** — Database-namespace audit-recorder singleton exemption | ✅ N/A | Not an audit-recorder class. Audit writes flow through Phase 6's `CliAuthLog\Recorder` (which IS A15-exempt). |

## Bug Pattern Defenses (B1–B11) Check

| Pattern | Status | Defense |
|---|---|---|
| **B1** — Namespace relative-path bug | ✅ | `use` import for `CliController` per A6; PHPStan L8 catches any bare reference. |
| **B2** — `define_constants` null-property | ✅ N/A | Plugin constants already defined; not modified by this phase. |
| **B3** — TODO stub FQN drift | ✅ N/A | No TODO stubs being uncommented. |
| **B4** — Unescaped dot in PCRE rewrite | ✅ | Pattern `'^acrossai-mcp-manager/?$'` contains no literal dot. |
| **B5** — Public ctor → double registration | ✅ | FR-002 mandates `private __construct`. |
| **B6** — `admin_url()` without `esc_url()` | ✅ | Plan uses `home_url()` (not `admin_url()`); all URL rendering goes through `esc_url()` per FR-012. |
| **B7** — Mass-assignment via forged POST keys | ✅ N/A | No DB writes from this module. |
| **B8** — "esc_url'd above" comments | ✅ | FR-012 mandates escape at point of render; no defer-to-comment pattern in the plan. |
| **B9** — PHPUnit 13+ `@dataProvider` | ⚠ **Implementation-time check** | Plan lists test files; the implementer MUST use `#[DataProvider]` PHP attributes (not docblock). Add to task description. |
| **B10** — Atomic CAS for one-shot credentials | ✅ N/A | No one-shot credential issuance in this module. Phase 6's `approve_auth_code` is the relevant call site; B10 deferral is documented in Phase 6 plan, not Phase 7. |
| **B11** — Transient defensive triple-check | ✅ N/A | This module does NOT read transients directly. All transient reads happen inside Phase 6's `CliController::approve_auth_code()`. |

## Security Constraints (S1–S8) Check

| Constraint | Status | Notes |
|---|---|---|
| **S1** — Nonce on form/AJAX | ✅ | FR-009 verifies nonce on the only state-mutating branch (`cli_auth_approve`). |
| **S2** — REST `permission_callback` explicit | ✅ N/A | No REST routes in this phase. |
| **S3** — OAuth tokens / App Passwords stored hashed | ✅ N/A | This module does not store credentials. |
| **S4** — `$wpdb->prepare()` on DB queries | ✅ N/A | No raw DB queries. |
| **S5** — `admin_url()` wrapped with `esc_url()` | ✅ | Plan uses `home_url()` (not `admin_url()`); all output URLs escaped. |
| **S6** — Singleton `__construct` private | ✅ | FR-002 enforced. |
| **S7** — OAuth token endpoint S2 exception | ✅ N/A | Not an OAuth route. |
| **S8** — Body-authenticated mutating REST routes broader than S7 | ✅ N/A | No REST routes. |

## Decision Alignment (D1–D12) Check

| Decision | Alignment | Notes |
|---|---|---|
| **D2** — Rewrite rules at activation with placeholder vars | ✅ Followed | Activator already calls `FrontendAuth::instance()->register_rewrite_rule(); flush_rewrite_rules();`. Plan does not modify this. |
| **D6** — `use` imports in Activator for DB refs | ✅ Followed (transitive) | Activator already has `use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;` — verified at the top of `includes/Activator.php`. |
| **D11** — Phase X.0 Absorption | ⚠ **Inverse application** | Memory-synthesis notes the inversion: Phase 6.0 already absorbed FrontendAuth; Phase 7 now *replaces* it. This is the first time D11 has been applied in the reverse direction. No new pattern emerges — the original D11 still holds for forward absorption. Capture-worthiness: **none** (this is a one-off, not a recurring pattern). |
| Other D-entries | ✅ N/A | D1, D3, D4, D5, D7, D8, D9, D10, D12 — not triggered. |

## Accepted Deviation (DEV1–DEV2) Check

| Deviation | Status |
|---|---|
| **DEV1** — MCP Manager parent menu uses WP_List_Table | ✅ N/A — no admin UI |
| **DEV2** — `Compat.php` in `includes/` | ✅ N/A — not modified |

## Drift Findings

### Drift 1 — `manage_options` broadening (already documented)

**Type**: Constitutional deviation (Constitution §III)
**Severity**: P2 (documented + bounded)
**Plan location**: §Complexity Tracking row 1
**Security review verdict**: APPROVED (`security-constraints.md` §Authorization Assumptions)
**Action**: NONE — already captured in spec §Assumptions + plan §Complexity Tracking. Not a reusable pattern, so no memory entry warranted.

### Drift 2 — A9 promotion deferred for `PAGE_SLUG`

**Type**: Architecture constraint (A9)
**Severity**: P3 (acceptable as-is)
**Plan location**: implicit (class-local constants)
**Action**: NONE for this phase. Add a watchlist note: "if a 4th consumer ever reads `PAGE_SLUG`, promote to `includes/Utilities/Constants.php`."

### Drift 3 — B9 PHPUnit attribute reminder

**Type**: Bug pattern defense at implementation time
**Severity**: P3 (PHPCS-catchable)
**Action**: include in `/speckit-tasks` description for each test file: "Use `#[DataProvider]` PHP attributes, not `@dataProvider` docblock annotations."

## Security-Architecture Conflicts

**None.** The one documented security deviation (broadened auth) does NOT conflict with any architecture constraint. The two are orthogonal: security says "any logged-in user"; architecture says nothing about which capability is required (constitution §III says "manage_options minimum" but the explicit deviation overrides that for this surface).

## Consistency Risks

| Risk | Mitigation |
|---|---|
| FR-016 (i18n mandate) + RTL CSS step were added at clarify time AFTER the plan was written | Plan §Constitution Check has been updated implicitly by the FR additions; tasks-phase MUST regenerate task descriptions with i18n + RTL coverage included. |
| `manage_options` broadening could be misread as a pattern others should adopt | Plan + memory-synthesis explicitly call out the rationale as feature-local. No A-numbered exception is added. Future authors who try to copy the pattern without the matching threat-model documentation should be caught at code review. |

## Final Verdict

✅ **Plan PASSES architecture review.** Zero blocking violations. Three minor non-blocking drift findings documented above with explicit actions. No security-architecture conflicts.

Proceed to `/speckit-tasks`.
