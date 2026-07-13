# Specification Quality Checklist: Server Tools Registration Hooks

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-07-13
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — *Note: PHP class paths and column names appear in the Module Placement / Database sections per the plugin's spec template, which explicitly asks for them; the user-facing sections (Scenarios, Requirements, Success Criteria) stay technology-agnostic.*
- [x] Focused on user value and business needs — user stories are ordered by operator value, filter surface is the last priority.
- [x] Written for non-technical stakeholders — Scenarios and Requirements sections speak in operator/AI-client terms.
- [x] All mandatory sections completed — User Scenarios, Requirements, Success Criteria, Assumptions all present.

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain.
- [x] Requirements are testable and unambiguous — each FR-### maps to at least one acceptance scenario or success criterion.
- [x] Success criteria are measurable — SC-001 through SC-006 are all click-count, percent, or boolean gates.
- [x] Success criteria are technology-agnostic — SCs speak in operator clicks, request cycles, or "the composed tool list". No PHP/MySQL/JS references.
- [x] All acceptance scenarios are defined — every user story lists Given/When/Then triples.
- [x] Edge cases are identified — first-request-after-upgrade, empty tool set, missing default row, filter throws, concurrent save, missing adapter.
- [x] Scope is clearly bounded — Assumptions section lists what's out (multisite, outer transaction).
- [x] Dependencies and assumptions identified — F009 (adapter), F017 (ability gate), F020 (curated storage) explicitly named.

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — trace matrix (below).
- [x] User scenarios cover primary flows — save picks, remove a built-in, reset, filter customization.
- [x] Feature meets measurable outcomes defined in Success Criteria — each SC has a corresponding US or acceptance scenario.
- [x] No implementation details leak into specification — user-facing sections are clean; module-placement / DB sections are template-required and clearly labeled as architectural constraints.

## Requirement → Story / Success Criterion Trace

| Requirement | Covered by |
|---|---|
| FR-001 | US1 §Acceptance 1–3; SC-001, SC-003 |
| FR-002 | US2 §Acceptance 1, 3 |
| FR-003 | US2 §Acceptance 1 |
| FR-004 | US2 §Acceptance 3 (persistence across requests); SC-003 |
| FR-005 | US2 §Acceptance 3 |
| FR-006 | US2 §Acceptance 4 |
| FR-007 | US3 §Acceptance 1, 3; SC-002 |
| FR-008 | US4 §Acceptance 1, 2; SC-004 |
| FR-009 | US4 §Acceptance 3 |
| FR-010 | US4 §Acceptance 4; SC-005 |
| FR-011 | Edge case §1; SC-003 |
| FR-012 | US2 §Acceptance 3 (POST path); US3 §Acceptance 3 (Reset POST) |
| FR-013 | US1 §Acceptance 1–3 |
| FR-014 | US4 §Acceptance 1–3 (implicit — the doc IS the surface) |
| FR-015 | Definition of Done gate ("no code duplication of the three built-in default slugs") |
| FR-016 | Clarifications §Q1 (2026-07-13 session); implicit test — existing F020 subscribers observe protocol column flips as `acrossai_mcp_tools_changed` bullets. |
| FR-017 | Clarifications §Q2 (2026-07-13 session); Edge Cases §"What if the operator removes all three built-in defaults AND has no curated picks?"; US3 (Reset flow surfaces as recovery from this state). |
| FR-018 | Added 2026-07-14 post-`/speckit-analyze` (findings C1/C2). Traces to `contracts/rest-tools-endpoint-semantics.md` §1 (POST validation bypass) + §3 (GET catalog fallback), `security-constraints.md` §SEC-025-v2-2 corrected row, T012 (POST validation bypass code), T016 (`test_post_accepts_all_three_protocol_slugs` assertion), and the T005 helper `ToolPolicy::PROTOCOL_TOOL_METADATA` addition. |

## Notes

All checklist items pass. Clarification session on 2026-07-13 asked 2 questions (observability event routing, empty-tool-list UX); both integrated as FR-016 and FR-017. Remaining candidate ambiguities (left-pane grouping order, Reset-when-already-default state, ConfirmDialog accessibility fine-tuning) are low-impact implementation-polish items deferred to `/speckit-plan`. Ready to proceed to `/speckit-plan`.
