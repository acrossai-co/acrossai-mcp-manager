# Specification Quality Checklist: REST API — CLI Authentication Controller

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-06-25
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

Two interpretations of the "no implementation details" rule are intentional and align with prior phases:

1. **Endpoint URLs, transient key prefixes, class constants, and the `bin2hex(random_bytes(16))` codegen formula** are documented in FRs because they are LOAD-BEARING facts about the cross-module interface. Phase 3 (FrontendAuth) must call `CliController::approve_auth_code()` STATICALLY — the static method signature is a contract, not an implementation detail. Same for the `acrossai_cli_auth_` / `acrossai_session_` transient prefixes: Phase 5's OAuth flow read transients with conflicting prefixes; deliberately namespacing them here avoids a key collision regression.

2. **WP_Application_Passwords / AccessControlManager / CliAuthLogTable references** are LOAD-BEARING dependency declarations on existing classes from Phases 1, 2, and WordPress core. The spec names them because the integration contract MUST consume those exact classes; inventing fresh abstractions would silently disconnect this controller from the modules it's meant to bridge.

Three intentional scope decisions captured in §Assumptions:

- **No new database tables** — audit destination is the existing `acrossai_mcp_cli_auth_logs` from Phase 2.
- **Single-site only** — multisite explicitly out of scope.
- **No HTTPS hard-block on `/auth/exchange`** — matches Phase 5's "warning-not-block" posture; production deployments MUST run HTTPS.

Two intentional security postures captured in §Assumptions:

- **128 bits of entropy per opaque credential** (auth_code + session_token) — short-lived (5-10 min) opaque values, not persistent secrets. Not increased to 256 bits because they're transit-only.
- **`hash_equals` on `server_id` in `/auth/status`** — defense-in-depth against code-existence oracle (the `server_id` itself is not secret).

One intentional cross-phase dependency:

- **Phase 3 FrontendAuth has not yet shipped**. This phase's static `approve_auth_code()` method exists to be called by FrontendAuth's `handle_approve()`. P0 gate at planning time MUST flag the dependency. If FrontendAuth is not present when planning begins, D11 "Phase X.0 absorption" applies — absorb the minimal `FrontendAuth::get_base_url()` static helper as Phase 6.0 to unblock.

The spec deliberately enumerates ALL `/auth/exchange` failure response shapes in FR-006 because CLI tooling is uniquely sensitive to wrong-shape error JSON — every failure code in the FR list is also asserted in a §Acceptance Scenario of US5.
