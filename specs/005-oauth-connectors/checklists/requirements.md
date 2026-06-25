# Specification Quality Checklist: OAuth / Claude Connectors Integration

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-06-18
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

This is the security-densest spec in the project so far. Two interpretations
of the "no implementation details" rule are intentional:

1. **RFC 6749 / RFC 7636 / RFC 8414 / RFC 9728 references** are NOT
   implementation details. They are external standards the OAuth flow must
   conform to; the spec MUST reference them by section number so reviewers
   and implementers know the exact normative source. PKCE S256, the
   one-time-use auth code, the `redirect_uri` exact match, the 10-min code
   expiry, and the JSON error envelope are all RFC-mandated; not project
   choices.

2. **Per-server credential columns** (`claude_connector_client_id` etc.) are
   load-bearing facts about the existing data model from Phase 2. The spec
   names them because the OAuth flow MUST consume those exact columns;
   inventing fresh column names here would silently disconnect the OAuth
   module from the data Phase 2 already collects.

Two intentional scope decisions captured in Assumptions:

- **No refresh tokens** — the 1-hour access-token lifetime + re-authorize
  flow is the entire token-renewal story this phase.
- **Single scope `mcp`** — no per-tool / per-resource scope hierarchy this
  phase.

Two intentional security postures captured in Assumptions:

- **PKCE S256 mandatory** — `plain` rejected outright (OAuth 2.1 hardening).
- **HTTPS warning-not-block** — the token endpoint warns admins at admin
  notice level when HTTPS isn't configured but doesn't refuse to issue
  tokens (would break local dev). Production MUST run HTTPS.

Three intentional cross-phase dependencies:

- Phase 2 (002-admin-ui): consumes the `claude_connector_*` columns on the
  MCP server row.
- Phase 2 (002-admin-ui): consumes the BerlinDB Query layer from Phase 2.0;
  adds a new `OAuthToken` Query alongside the existing `MCPServer` and
  `CliAuthLog` queries.
- Phase 1 (001-core-boot-flow): consumes the Activator + Loader. Adds the
  OAuth rewrite registration to `Activator::activate()` and the OAuth hook
  wiring to `Main::define_admin_hooks()` / `define_public_hooks()`.

The spec deliberately enumerates ALL RFC-mandated error responses in FR-012
because OAuth interop is uniquely sensitive to wrong-shape error JSON.
Vague text like "return an error on validation failure" would fail the
"testable and unambiguous" checklist item for every conformance test.
