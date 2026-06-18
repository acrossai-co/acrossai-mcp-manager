# Specification Quality Checklist: MCP Client Classes — Pure Service Layer

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-06-17
**Feature**: [Link to spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

This is a **pure service-layer** feature. Two interpretations of the
"implementation details" rule are intentional:

1. **Class names, method names, and namespaces in the spec** are
   contractually load-bearing — the user's request explicitly named
   `AbstractMCPClient`, `get_config_snippet(string $server_url, string
   $auth_token)`, etc. These are the public API the consumer (Phase 2's
   `ApplicationPasswords::render_for_server`) will call. They are
   functional requirements, not implementation choices.

2. **Per-client envelope shapes** (e.g. Claude Desktop's `mcpServers`
   key, Codex's args format) are documented externally by the AI tool
   vendors. The spec defers to those external documents and uses
   golden-fixture testing to lock the canonical shape at implementation
   time. The spec does NOT inline the JSON shape of every snippet —
   that would over-constrain the spec and require an amendment every
   time a vendor changes a config key.

Two intentional scope decisions captured in Assumptions:

- **No Registry class in this phase** — file-scan + `class_exists()` +
  `is_subclass_of()` is sufficient. A Registry would be premature
  abstraction for a directory of 7 sibling files.
- **No consumer amendment in this phase** — wiring the Tokens-tab UI to
  enumerate the registry is a Phase 2 follow-up (RT-3 from the Phase 2
  architecture review), not 004's job.

Two prerequisite dependencies surfaced in Assumptions for the
implementer's situational awareness:

- **PHPUnit harness** — DoD gates depend on it; same dependency model
  Phase 2 used.
- **Phase 2 ApplicationPasswords amendment** — separate task; will
  consume this module's classes.
