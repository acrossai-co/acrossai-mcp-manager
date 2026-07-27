# Security Constraints — F035 Public Connection-Method Discovery API

**Feature**: F035 | **Date**: 2026-07-26 | **Plan**: [plan.md](./plan.md) | **Reviewer**: Inline plan-phase review (no external `spec-kit-security-review` skill invoked — see Governance Summary)

## Assessment Summary

- **Overall risk**: INFORMATIONAL
- **Trust boundaries**: 1 boundary crossed (developer-contributed filter callbacks). Same trust anchor as F021 + F034 existing extension seams.
- **Threat surface**: Zero user-facing surface. Zero authentication surface. Zero data-at-rest surface. Only programmatic API for third-party plugins.

## Trust Boundaries

### Boundary 1 — Filter contribution channel

**Data flow**:
1. Third-party plugin (installed by site admin) registers callback on `acrossai_mcp_npm_methods` or `acrossai_mcp_connection_methods`.
2. Callback returns DTO or assembled-result array.
3. F035 validates shape (FR-009b / FR-012a) and drops malformed contributions with `_doing_it_wrong` under `WP_DEBUG`.
4. Validated DTOs flow out through `get_*()` methods to arbitrary consumers.

**Trust anchor**: Site admin who installed the plugin — same anchor as existing `acrossai_mcp_client_classes` (F034) and `acrossai_mcp_manager_connector_profiles` (F021) seams. F035 does NOT introduce a new trust anchor; it symmetrizes the existing shape by adding NPM to the family.

**Threat model**: A malicious companion plugin could:
1. **Inject XSS payloads in DTO string fields** (`name`, `description`, `icon`). Mitigation: consumers own render-time escaping. F035's contract explicitly emits data, not rendered HTML. This mirrors F034's SEC-034-001 preservation invariant — the escape happens at the render boundary, not at data-emit. No F035 code emits any DTO field to HTML.
2. **Include closures / objects in DTO `meta`**. Mitigation: `wp_json_encode()` round-trip test (SC-001) validates all DTOs remain JSON-serializable; malformed contributions fail the FR-009b validation gate and are dropped.
3. **Override the built-in NPM DTO** via slug collision. Mitigation: Q1 dedup is intentional later-wins semantic — companion plugins CAN override built-ins. This is a feature, not a vulnerability. Site admin remains in control by choosing which plugins to install.

## Constitutional Security Rules Applied

| Rule | Applicable? | Verification |
|---|---|---|
| S1 — All forms and AJAX endpoints MUST verify a nonce | No | F035 has no forms, no AJAX. |
| S2 — All REST routes MUST have explicit `permission_callback` | No | F035 registers no REST routes. |
| S3 — OAuth tokens + App Passwords hashed | No | F035 handles no tokens. |
| S4 — All DB queries MUST use `$wpdb->prepare()` | No | F035 issues zero DB queries. |
| S5 — `admin_url()` MUST be wrapped with `esc_url()` | No | F035 emits no HTML. |
| S6 — Singleton `__construct()` MUST be private | Yes | Enforced by FR-002; verified by contract |
| S7 — OAuth token endpoint `__return_true` exception | No | No relevance to F035. |
| S8 — Body-authenticated mutating REST exception | No | No relevance to F035. |
| S9 — Consent-surface displayed-state from authoritative store | No | F035 renders no consent surface. |

## Bug-Pattern Guard Rails Applied

| ID | Pattern | Prevention |
|---|---|---|
| B32 | Filter defaults that gate security MUST be canonical resolver output, NEVER partial derivation | Directly enforced: FR-010/FR-011 mandate delegation; FR-012a mandates fallback-to-pre-filter-canonical-result on malformed callback return; SC-005 grep gate proves no re-firing. |
| B26 | Governance grep gates that hard-code allow-list silently skip newly-added layers | SC-005 (`public/Discovery/` scope) + SC-006 (`includes/` scope) are both allow-list gates. Documented risk: if a future refactor adds `public/DiscoveryV2/` or moves the class, gates silently stop enforcing. Plan-side mitigation: `/speckit-tasks` output MUST list the grep gates as verification tasks so they run against the actual shipped file paths. |
| B23 | Test-suffix method names (`_reset_for_tests()`) are silent-regression smells | Prevented by R2 decision: `flush_cache()` (production-shape name); documented as supported surface. |
| B1 / A6 | Bare relative namespace names silently fail inside `AcrossAI_MCP_Manager\...` | Prevented by explicit `use` imports for `AbstractMCPClient`, `ConnectorProfileRegistry`, `NpmClientBlock`. Enforced at PHPCS level. |

## Security Checklist (per spec §Security Checklist)

Every applicable item verified during clarification + plan phase:

- [x] All form/AJAX handlers verify nonce — N/A (no forms).
- [x] All admin page renders check capability — N/A (no admin pages).
- [x] All REST routes have explicit `permission_callback` — N/A (no REST routes).
- [x] All user input sanitized at system boundary — N/A. Filter contributions are trusted developer input at admin-install trust level.
- [x] All output escaped at point of rendering — N/A. F035 emits data, not HTML; consumers own render-time escaping.
- [x] All DB queries use `$wpdb->prepare()` — N/A (no DB queries).
- [x] OAuth tokens stored hashed — N/A (no token handling).
- [x] File uploads validated — N/A (no file uploads).

## Async / Concurrency Considerations

None. F035 has no async work, no cron, no queues, no locks. All operations are synchronous in-request PHP.

## Data Isolation

Zero cross-tenant / cross-user / cross-site concerns. `ConnectionMethodRegistry` state is scoped to a single PHP request; `$assembled_cache` is per-instance memoization that resets between requests naturally.

## Recommendations (Advisory)

1. **Pre-merge**: Run `grep -rn "add_filter\|add_action" public/Discovery/` (A1 verification) — MUST return zero hits.
2. **Pre-merge**: Run SC-005 grep gate — MUST return zero hits.
3. **Pre-merge**: Run SC-006 grep gate — MUST return zero hits.
4. **Staged-review time**: Rerun `/speckit-security-review-staged` after implementation to catch any drift between plan and code. F035 threat model expects zero findings; a HIGH or CRITICAL from staged review would indicate an unplanned surface was added.

## Conclusion

F035 introduces **zero** new authentication, authorization, data-at-rest, or user-input surface. The single trust boundary (filter contribution) mirrors two pre-existing seams and inherits their SEC-013-008 validation semantic verbatim. **Approved for implementation.**
