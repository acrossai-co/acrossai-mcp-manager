# Specification Quality Checklist: Per-Server Shortcode + Block Embeds Tab

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-07-27
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — spec names WordPress + BerlinDB + plugin-internal classes because these ARE the contract surface (D28 3-part schema contract, `AbstractServerTab` hierarchy, `ConnectionMethodRegistry` API) — not implementation choices but published constitutional/decision-references
- [x] Focused on user value and business needs — 3 user stories frame WHY (site admin gates output, third-party extensibility, security cascade)
- [x] Written for non-technical stakeholders where possible — admin UX + third-party extensibility framed in plain terms; DB shape + gate cascade unavoidably technical
- [x] All mandatory sections completed — User Scenarios, Requirements, Success Criteria, Assumptions all present

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous — 21 FRs each expressible as a passing/failing PHPUnit test or grep gate
- [x] Success criteria are measurable — 8 SCs each with a concrete verification (click count, byte-count, grep, matrix combination coverage)
- [x] Success criteria are technology-agnostic where possible — SC-001/002/003 describe observable behaviour; SC-005/006 name grep gates as the actual enforcement mechanism (matches F035 precedent + Constitution SC-003 pattern)
- [x] All acceptance scenarios are defined — 3 user stories × 2–4 scenarios each; Given/When/Then form
- [x] Edge cases are identified — 6 edge cases covering new-server default state, server deletion cleanup, all-off state, orphan third-party rows, admin-preview gate, anonymous visitor
- [x] Scope is clearly bounded — FR-020 + FR-021 explicitly enumerate files NOT to touch; Assumptions section names BuddyBoss add-on + block-editor block as out-of-scope
- [x] Dependencies and assumptions identified — F035 dependency, F015 optional integration + fail-open, DTO category naming translation (`client` vs `clients`), all called out in Assumptions

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — every FR maps to at least one SC or Given/When/Then scenario
- [x] User scenarios cover primary flows — US1 (P1 admin gate), US2 (P2 third-party extensibility), US3 (P1 security cascade)
- [x] Feature meets measurable outcomes defined in Success Criteria — SC-001 through SC-008 collectively cover every FR
- [x] No implementation details leak into specification — file paths named as placement rules per Constitution §Architecture; class names cited as contract identifiers, not as required implementations

## Notes

- The dir-vs-brief numbering divergence (spec dir `036-shortcode-block-embeds`, brief `037-shortcode-block-embeds.md`) is documented in spec.md's Input paragraph. Same pattern as F034/F035 lineage divergence — no functional impact, resolve at merge if desired.
- The `embeds` PHPUnit suite bootstrap choice (bootstrap-wp) is documented in FR-015 — F037 requires WP context (BerlinDB, WP options, F015 wrapper, shortcode API).
- Pre-approved DEV5 hand-rolled form exception applies to the Embeds tab. F037 is the 4th consumer under DEV5 — spec Assumptions section flags this as a D13 escalation candidate (potential constitution §IV third exception paragraph promotion).
- All checklist items pass; spec is ready for `/speckit-clarify` (recommended — non-trivial storage design + gate cascade design deserve a clarification pass) or `/speckit-memory-md-plan-with-memory`.
