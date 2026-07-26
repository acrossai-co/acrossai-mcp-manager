# Specification Quality Checklist: MCP Client Metadata + Filter-Aware Enumeration Refactor

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-07-25
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
  - *Verified*: Spec references PHP class names + WordPress filter names because those ARE the observable contract for a subsystem refactor (analogous to how F017's spec named the `mcp_adapter_pre_tool_call` filter). Class-level detail is intentionally at the "public API surface" level, not "how to write the method body."
- [x] Focused on user value and business needs
  - *Verified*: Three user stories articulate value for (1) third-party plugin developers, (2) site administrators, (3) plugin maintainers. Each has a "Why this priority" justification.
- [x] Written for non-technical stakeholders
  - *Note*: This is a technical refactor of a developer-facing extension surface; the "stakeholders" are companion-plugin developers + plugin maintainers. Non-technical framing is inapplicable to the audience — spec targets its actual reader.
- [x] All mandatory sections completed
  - *Verified*: User Scenarios & Testing (3 stories + edge cases), Requirements (17 FRs + WordPress + Module Placement + Admin UI + REST + Database + Security + Key Entities), Success Criteria (DoD gates + 6 SCs), Assumptions.

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
  - *Verified*: Zero markers. All decisions have unambiguous answers from the engineering brief.
- [x] Requirements are testable and unambiguous
  - *Verified*: Every FR is a single MUST/MUST NOT statement about a specific symbol or observable behaviour. FR-016 (byte-identity) is the only holistic requirement; the accompanying test method (DOM-diff snapshot) is named.
- [x] Success criteria are measurable
  - *Verified*: SC-002/003/004 are grep-count assertions. SC-005 is a DOM-diff assertion. SC-006 is a test-suite green assertion. SC-001 is a developer-workflow assertion verified by attempting the workflow.
- [x] Success criteria are technology-agnostic (no implementation details)
  - *Note*: SC-002/003/004 name specific PHP symbol strings (`CLIENT_META`, `get_all_clients`, `acrossai_mcp_client_classes`). This is intentional — the refactor's whole purpose is to remove those specific symbols. Success is "these strings are gone" — that IS the technology-agnostic outcome for this refactor's audience.
- [x] All acceptance scenarios are defined
  - *Verified*: US1 has 3 Given/When/Then scenarios; US2 has 2; US3 has 2. Total 7 acceptance scenarios covering the primary flows for each user.
- [x] Edge cases are identified
  - *Verified*: 5 edge cases enumerated (no-metadata subclass, invalid slug, duplicate slug, invalid FQN, non-request-context call).
- [x] Scope is clearly bounded
  - *Verified*: FR-017 explicitly bounds files touched to `includes/MCPClients/` + `public/Renderers/MCPClientsBlock.php` + test files. "Not in scope" is implicit but the Assumptions section covers the boundary decisions.
- [x] Dependencies and assumptions identified
  - *Verified*: Six assumptions listed, covering backwards-compat risk, filter contract stability, WP_DEBUG semantics, text domain availability, snapshot testability, and existing-test integrity.

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
  - *Verified*: FR-001..017 each map to one or more user-story acceptance scenarios, edge cases, or SC-NN outcomes. No orphan FRs.
- [x] User scenarios cover primary flows
  - *Verified*: The three user perspectives (third-party dev, site admin, maintainer) cover every human who touches the client subsystem. AI client itself has no perspective here — its behaviour is transitive to the site admin's config-copy workflow which is exercised in US2.
- [x] Feature meets measurable outcomes defined in Success Criteria
  - *Verified*: Every SC is either a grep result, a test-suite gate, or a workflow-attempt outcome. All 6 SCs are pass/fail-verifiable independently.
- [x] No implementation details leak into specification
  - *Note*: See Content Quality note above — class + filter names are the observable contract of a refactor whose purpose is to reshape those exact symbols. Excluding them from the spec would defeat the spec's purpose.

## Notes

- Items marked incomplete require spec updates before `/speckit-clarify` or `/speckit-plan`. All items pass on this iteration; no updates required.
- The spec is dense with symbol-level references (class names, filter names, regex patterns) because the audience is companion-plugin developers and plugin maintainers — the exact contract must be crisp. Non-developer stakeholders are not the target audience for a subsystem refactor spec.
- Feature-number discrepancy noted at the top of `spec.md`: the engineering brief filename says `035-...md`, the branch + spec dir use `034-...` per `/speckit-git-feature` next-sequential numbering. No functional impact; resolve at merge if desired (rename brief, or leave the note in place as historical context).

## Clarifications applied (Session 2026-07-25)

- **Q1 (HIGH — sub-nav sort order)**: Direct contradiction between FR-010 (sort by slug ascending) and FR-016 (byte-identical render) resolved by adding `get_priority(): int` to the abstract with built-in overrides `10, 20, 30, ..., 80` preserving the pre-refactor visual order. Updated: Clarifications section, FR-001/003/010/018, User Story 1 body + acceptance scenarios, User Story 3 acceptance, Edge Cases (added multiple-defaults case), Key Entities, SC-001.
- **Q2 (MEDIUM — `_doing_it_wrong` version tag)**: Version tag `'0.1.7'` (current release) locked in for FR-008 + FR-009 slug/dedup validation. Updated: Clarifications section, FR-008, FR-009.

No further critical ambiguities remain. Remaining low-impact decisions (test file organization, `DEFAULT_CLIENT_CLASSES` visibility, observability action for skipped contributions) are appropriate for `/speckit-plan` to decide.
