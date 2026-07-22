# Specification Quality Checklist: OAuth Per-Server Scoping

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-07-21
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
  - Note: Per plugin convention, the spec template includes explicit Module Placement + REST API Contract + Database sections with file paths. These are constitution-required and permitted. Deep implementation detail (SQL DDL snippets, upgrade callback bodies) lives in the planning doc at `docs/planings-tasks/032-oauth-per-server-scoping.md`, not the spec.
- [x] Focused on user value and business needs — user stories frame the security-fix outcomes from operator + AI-host perspective
- [x] Written for non-technical stakeholders — FRs are testable statements not code; technical terms (RFC 8707, D28, BerlinDB) are used where unavoidable but scoped to the Requirements section
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain — feature is fully specified by the planning brief (audit finding + user decisions on scope + DCR per-server behaviour pinned during plan phase)
- [x] Requirements are testable and unambiguous — each FR is a single verifiable predicate
- [x] Success criteria are measurable — SC-001..SC-006 all include concrete verification procedures with SELECT COUNT queries + grep audits
- [x] Success criteria are technology-agnostic — user-observable outcomes (403 response codes, backfill row counts, cross-server isolation) rather than framework metrics
- [x] All acceptance scenarios are defined — 4 user stories × 3–5 scenarios each = 14+ scenarios
- [x] Edge cases are identified — 6 edge cases enumerated including race conditions + mid-migration crash + RFC 8707 URL normalisation
- [x] Scope is clearly bounded — explicit "do not touch" constraints on `wp_acrossai_mcp_oauth_audit`, `UserLifecycle::on_user_deleted`, `TokensQuery::revoke_by_user_id`; legacy DCR row preservation
- [x] Dependencies and assumptions identified — Assumptions section documents D28 pattern reliance, RFC 8707 resource parsing reuse, legacy DCR preservation rationale

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — FRs cross-reference user stories
- [x] User scenarios cover primary flows — P1 (cross-server isolation invariant), P1 (DCR per-server registration), P2 (safe upgrade), P1 (user deletion regression)
- [x] Feature meets measurable outcomes defined in Success Criteria — SC-001..SC-006 map to FR-001..FR-022
- [x] No implementation details leak into specification — SQL DDL + upgrade callback bodies + REST handler code snippets live in the planning doc, not the spec

## Notes

- No [NEEDS CLARIFICATION] markers created — all critical decisions (fix scope, DCR per-server semantics, legacy DCR preservation) were resolved during the plan-phase clarification. See `docs/planings-tasks/032-oauth-per-server-scoping.md` §Speckit Workflow for the pinned decisions.
- Spec deliberately does NOT reproduce the planning doc's TASK-1 through TASK-9 breakdown, code snippets, or DDL — those drive `/speckit-plan` + `/speckit-tasks` outputs, not `/speckit-specify`.
- Security-critical framing: this feature is a fix for a cross-server privilege-escalation gap surfaced during audit. Per SC-001 + SC-005, the acceptance criteria are structured to prove the fix works via automated tests + grep sweeps rather than manual review only.
- Items marked incomplete would require spec updates before `/speckit-clarify` or `/speckit-plan`.
