# Specification Quality Checklist: Migrate AI Connectors + OAuth Stack to Companion Plugin

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-07-31
**Last updated**: 2026-07-31 (after Clarify session — 6 Q&A applied; Q5 and Q6 were significant scope corrections that drove the spec to pure code-removal with zero new code)
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
  - Note: this is a code-removal feature, so file paths and class names are inherent to the requirement (deleting `includes/OAuth/**` cannot be expressed without naming it). The spec keeps discussion of *how* to delete outside scope; *what* to delete is the requirement.
- [x] Focused on user value and business needs — two user stories frame (P1) premium OAuth continuity and (P2) free/premium user population differentiation
- [x] Written for non-technical stakeholders — user stories are prose; file lists live in Functional Requirements where they belong
- [x] All mandatory sections completed (User Scenarios & Testing, Requirements, Success Criteria)

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain — 6 clarifications applied in `## Clarifications → Session 2026-07-31` (all 5-question quota consumed, plus Q6 as scope-corrective follow-up)
- [x] Requirements are testable and unambiguous — every FR names a specific file path, symbol, or observable behaviour
- [x] Success criteria are measurable — SC-001..SC-004 each specify what to observe and how (SC-005, SC-006, SC-007 removed via clarifications)
- [x] Success criteria are technology-agnostic where possible — all surviving SCs are user-observable
- [x] All acceptance scenarios are defined — each of the two surviving user stories has 4 Given/When/Then scenarios
- [x] Edge cases are identified — 7 edge cases covering: free-user standalone, premium-user seamless, premium-user degraded, uninstall paths, cached OAuth discovery, BerlinDB version drift, add-on uninstall
- [x] Scope is clearly bounded — FR-017 preserves tab framework + mcp_servers table; FR-013/FR-014 removals rule out the admin notice and compat shim; Q5 rules out the hard-dependency header
- [x] Dependencies and assumptions identified — Assumptions section documents the three-connection-paths architecture (npm/clients/ai-connectors), one-way dependency direction, and the audited companion PASS 23/23

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — FR-001..FR-012, FR-015..FR-018 each map to at least one acceptance scenario, edge case, or success criterion (FR-013 and FR-014 intentionally removed placeholders)
- [x] User scenarios cover primary flows — P1 covers happy-path zero-disruption for premium users; P2 covers the differentiated free vs premium-degraded populations
- [x] Feature meets measurable outcomes defined in Success Criteria — the DoD gates plus SC-001..SC-004 are directly tied to FRs
- [x] No implementation details leak into specification beyond what the removal-scope requires — the spec deliberately does NOT prescribe WHICH order file deletions happen; that is a planning concern

## Clarify Session Summary (2026-07-31)

Six clarifications applied (Q1..Q5 in the sequential loop, plus Q6 as a scope-corrective follow-up when the user provided new context that this is a single-operator plugin):

1. **Version bump target** → `0.2.0` (SemVer-minor bump during 0.x). Applied to FR-012.
2. **Cron-clear on deactivation** → always clear unconditionally. Rationale added to FR-004.
3. **Fate of untracked `specs/039-...` directory** → delete in this feature. Added as FR-018.
4. **Compat shim for third-party plugins** → dropped from scope (no third-party ecosystem exists). FR-014 marked as a removed placeholder.
5. **`Requires Plugins:` direction / hard dependency** → **major scope correction.** ai-connectors is a paid add-on for the OAuth click-to-connect flow, NOT a hard dependency. FR-012 no longer adds the header; SC-006 removed.
6. **Conditional admin-notice safety net (FR-013)** → **dropped from scope.** Plugin has no external users yet (sole operator is the plugin author); the notice was defense-in-depth for a user population that doesn't exist. FR-013 marked as removed placeholder; User Story 2 further simplified; SC-007 removed; security-checklist item for `esc_html__()` removed; Admin UI Requirements + Module Placement sections updated to reflect zero new hooks / zero new UI. This feature now adds **ZERO NEW CODE** — pure deletions + header version bump.

## Net Feature Shape After All 6 Clarifications

**What this feature does:**
1. Deletes ~35+ files (OAuth stack, Connectors framework, BerlinDB modules, admin tab, JS/CSS, template, all associated tests, plus the abandoned `specs/039-...` directory).
2. Modifies ~7 existing files (Activator, Deactivator, Main.php, admin/Main.php, Registry.php, uninstall.php, webpack.config.js) to unwire the deleted code.
3. Bumps `Version:` in the plugin header from `0.1.9` to `0.2.0`.

**What this feature does NOT do:**
- Does NOT add any new PHP file.
- Does NOT add any new hook (no admin notice).
- Does NOT add any `Requires Plugins:` header to mcp-manager.
- Does NOT drop any database table, option, or cron event.
- Does NOT change the REST namespace.
- Does NOT modify the tab framework or the `mcp_servers` table.

## Companion Plugin Audit (2026-07-31)

The companion plugin at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-ai-connectors` v0.5.0 was audited against 23 coordination invariants derived from the spec. Result: **23 PASS / 0 FAIL / 0 UNCLEAR**. Details:

- Plugin header declares `Requires Plugins: acrossai-mcp-manager` (add-on-to-parent direction — the ONLY direction after Q5 correction).
- Self-disable probe (`mcp_manager_still_owns_oauth()`) wired into 6 bootstrap paths.
- All 4 BerlinDB Table subclasses byte-identical to mcp-manager's (`$name`, `$version`, `$db_version_key`).
- REST namespace preserved as `acrossai-mcp-manager/v1`.
- Cron hook name preserved as `acrossai_mcp_manager_oauth_cleanup`.
- AI Connectors tab wired via `add_filter( 'acrossai_mcp_manager_server_tabs', ..., 35, 2 )`.
- All 17 OAuth classes present under new namespace.
- Frontend assets + `consent.php` template migrated.

The coordination invariant in the spec's Assumptions section ("companion PR must be deployable before merge") is satisfied.

## Notes

- The Input document was unusually detailed for a `/speckit-specify` invocation — it enumerated tasks, constraints, and manual verification steps. Three important requests in that Input were subsequently **rescinded during clarification**:
  1. Input asked for `class_alias` compat shim in TASK-8 — rescinded by Q4 (no third-party ecosystem).
  2. Input asked to add `Requires Plugins: acrossai-ai-connectors` to mcp-manager's header — rescinded by Q5 (would break free users).
  3. Input asked for an admin-notice safety net (TASK-1) — rescinded by Q6 (no external users to defend).

  These reversals are documented in the Clarifications section so the final spec accurately reflects the corrected requirements. Reviewers should treat the Input as historical and read the FRs + Clarifications as authoritative.

- The pre-flight callers grep and the manual verification checklist from the Input are captured in FR-016 and the Definition of Done gates respectively. They will re-appear in `tasks.md` as concrete task steps.

- The relationship to the abandoned `specs/039-migrate-ai-connectors-to-companion/` directory is captured in FR-018 (in-scope deletion).
