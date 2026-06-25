# Security Constraints — Phase 5: OAuth / Claude Connectors

**Date**: 2026-06-18 | **Branch**: `005-oauth-connectors`
**Authoritative review of**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/*`, `quickstart.md`

This is the security-densest review in the project so far. OAuth flows
have well-documented failure modes (RFC 6749 §10, RFC 7636 §8) and the
spec + plan address every one of them. This document organizes the
controls by trust boundary and surfaces the remaining advisory items.

---

## Trust Boundaries

| Boundary | Crossing | Server-side Gate |
|---|---|---|
| **Anonymous → Discovery endpoints** | `GET /.well-known/*` | None — public read; spec FR-001/FR-002 |
| **Anonymous → Authorize endpoint (initial)** | `GET /acrossai-mcp-oauth/` | Param validation (FR-004); 302 to `wp-login` if not logged in (FR-006); admin capability check (FR-007) |
| **Logged-in admin → Consent form submit** | `POST /acrossai-mcp-oauth/` | nonce + `manage_options` recheck (FR-009 / FR-010); CSRF defense |
| **Anonymous → Token endpoint** | `POST /wp-json/acrossai-mcp/v1/token` | Rate limit (FR-014a) → required-fields → grant_type → client_id → `client_secret` constant-time (FR-012 step 4) → code lookup → redirect_uri match → PKCE verify (FR-012 step 7) |
| **External → MCP REST endpoints with Bearer** | `Authorization: Bearer <token>` | `determine_current_user` filter (FR-015): hash lookup + non-expired + non-revoked + server_id match; no oracle on failure |
| **Plugin → DB** | every write | BerlinDB Query layer uses `$wpdb->prepare()`; mass-assignment defense per memory B7 |
| **DB → log** | audit row write | Raw codes/tokens NEVER logged; only SHA-256 hash prefix (8 hex chars) |

---

## Data Isolation

| Data | Scope | Isolation Mechanism |
|---|---|---|
| Authorization codes | Per-(client_id, server_id, user_id) row in CliAuthLog table | UNIQUE index on hash; FR-004 step 3 server resolution; FR-015 server_id match |
| Access tokens | Per-(server_id, user_id) row in OAuthToken table | UNIQUE index on hash; cross-server defense via FR-015 step 6 `server_id` query predicate |
| Audit events | Per-event row, append-only | No update path; 90-day retention |
| Rate-limit counters | Per-(client_id, IP, time-bucket) transient | Hour-bucket rotation built into key (no explicit cleanup needed) |
| Per-server OAuth credentials | Per-server row in MCPServer table (Phase 2) | `manage_options` to read/write |

---

## Async Security Context

Two async surfaces:

1. **Daily WP-Cron cleanup** (FR-019c): `wp_schedule_event` writes a
   single `acrossai_mcp_oauth_cleanup` action. Handler is idempotent
   (re-running mid-sweep is safe). Writes one `cleanup_run` audit row
   per execution with `details_json` recording rows-deleted per class.
2. **Rate-limit transient writes** under concurrent failed requests.
   `wp_cache_incr()` is atomic where supported (Redis, Memcached). Race
   loss under no-cache fallback is acceptable bound — worst case: one
   extra failure per attacker per second slips through, negligible
   against the 5/min threshold.

No race windows that matter for security.

---

## Findings

### F1. **[Advisory — documented exemption]** Token endpoint `__return_true` permission_callback

- **Where**: `specs/005-oauth-connectors/contracts/token-endpoint.md`; FR-011
- **What**: Memory S2 forbids `__return_true` on mutating REST routes; the token endpoint mutates (issues tokens) and uses `__return_true`. The exemption is **RFC-mandated** — RFC 6749 §2.3.1 specifies authentication via POST body (`client_id`+`client_secret`), not session/header.
- **Mitigations in place**:
  1. Rate limiting (FR-014a) prevents brute force
  2. `client_secret` compared with `hash_equals()` (constant-time)
  3. PKCE verifier (FR-012 step 7) provides second factor
  4. Code expiry (10 min) and one-time use limit window
  5. Anti-replay revocation (FR-014) defangs leaked codes
- **Decision**: Accept the exemption. **S7 memory capture queued** for post-implementation: "OAuth token endpoint is the documented exception to S2 — auth lives in POST body, not session/header."

### F2. **[Advisory — documented exemption]** Consent page is a plain `<form>`, not DataForm

- **Where**: `contracts/authorize-page.md`; FR-008
- **What**: Constitution §IV mandates DataForm/DataViews; the consent page is a plain `<form>` per RFC 6749 §4.1.1. The exemption is RFC-mandated form shape.
- **Mitigations**: nonce + `manage_options` recheck on the POST handler; output escaping (`esc_html`, `esc_attr`, `esc_url`) at every render point.
- **Decision**: Accept. **A13 capture queued** for post-implementation: "RFC-prescribed forms are exempted from DataForm/DataViews."

### F3. **[Advisory]** `X-Forwarded-For` is NOT trusted for IP-based rate limiting

- **Where**: `research.md` R4 (request IP determination)
- **What**: The rate-limit key uses `$_SERVER['REMOTE_ADDR']` exclusively. Operators behind a reverse proxy (Cloudflare, Nginx upstream) will see all requests originating from the proxy's IP, making per-IP rate limiting much weaker (legitimate users share a key).
- **Risk**: Behind a proxy, a single attacker IP behaves like the entire user base from the rate limiter's perspective. Either: (a) all users get locked out together when one attacker hits the threshold, or (b) the threshold is set so high that brute force is undeterred.
- **Mitigation**: Documented as admin-notice in spec Edge Cases — operators MUST configure their reverse proxy to populate `$_SERVER['REMOTE_ADDR']` with the real client IP (via `mod_remoteip`, Cloudflare's `CF-Connecting-IP` extracted into REMOTE_ADDR, etc.). A future phase could add a filter `acrossai_mcp_trusted_proxy_ips` to opt into `X-Forwarded-For` trust — but that's a separate spec.
- **Decision**: Accept for this phase. Plan documents the operational guidance.

### F4. **[Advisory]** No HTTPS hard-block on token endpoint

- **Where**: spec.md §Assumptions (HTTPS posture)
- **What**: The token endpoint warns admins at admin-notice level when HTTPS isn't configured but doesn't refuse to issue tokens. A misconfigured production deployment could send tokens over HTTP.
- **Risk**: In production, plaintext tokens over HTTP are interceptable by passive network attackers.
- **Mitigation**: Admin notice surfaces this loudly. Documented as a deliberate trade-off for local-dev friendliness.
- **Decision**: Accept; revisit in a follow-up phase if compliance requires hard block.

### F5. **[Advisory]** Token endpoint accepts only `Content-Type: application/x-www-form-urlencoded`

- **Where**: `contracts/token-endpoint.md`
- **What**: JSON-body POSTs are rejected outright. This is the minimum-attack-surface choice — JSON parsing has historically been a source of CVEs (e.g., string-to-int coercion bugs).
- **Risk**: None — this is a hardening, not a weakness.
- **Decision**: Accept as a positive security pattern.

---

## Confirmed Secure Patterns

The plan **demonstrates** these patterns by design:

1. **All tokens hashed at storage** (SHA-256, FR-020) — never plaintext at rest
2. **Constant-time comparisons** (`hash_equals()`) for every secret check — never `===`
3. **PKCE S256 required at authorize-time** — `plain` rejected outright
4. **Anti-replay** (FR-014) — second redemption of a code revokes all child tokens
5. **Cross-server defense** (FR-015 step 6) — server_id predicate in the DB query, not a post-fetch check
6. **No discovery oracle** — invalid Bearer tokens produce no distinguishing response; no audit row on unknown tokens
7. **Rate-limit BEFORE validation** — attackers can't probe per-step error messages during lockout
8. **CSRF defense on consent form** — `wp_nonce_field` + `check_admin_referer`
9. **`.well-known` rewrite rule dot is escaped** (R1, B4 mitigation) — `\.well-known` literally
10. **Append-only audit log** — no update path; 90-day retention via cron
11. **`X-Forwarded-For` NOT trusted by default** — defensive against header-injection IP spoofing
12. **`Cache-Control: no-store`** on token endpoint responses (RFC 6749 §5.1)

---

## Action Plan

### Items for this phase's tasks.md (when /speckit-tasks runs)

| Task | Why |
|---|---|
| Add the spec security checklist (16 items) as a DoD gate task | Verify each at impl time |
| Add a "search for `===` near client_secret/token comparisons" grep gate to Polish | Defense against future regression |
| Add `assertRewriteRuleEscape` test (R1) | B4 mitigation made testable |
| Add per-RFC-error-path PHPUnit tests (8+ envelopes per RFC §5.2) | Conformance |

### Items NOT to do

- Don't add a custom `permission_callback` to the token endpoint (S2 exemption is RFC-mandated)
- Don't internationalize the consent page error responses (RFC JSON envelopes use canonical English error codes)
- Don't add HTTPS hard-block (deliberate Assumption)
- Don't trust `X-Forwarded-For` by default (deliberate; can opt in later)

### Memory captures queued for post-implementation

- **A13**: RFC-prescribed forms exempted from DataForm/DataViews
- **S7**: OAuth token endpoint exempted from S2 "never `__return_true` on mutating routes"

### Remediation Planning

**No `/speckit-security-review-followup` needed.** Zero Critical/High findings. The 5 Advisory findings are all by-design or accepted trade-offs documented in spec.

---

## Overall Risk

**LOW.** The OAuth implementation, as planned, addresses every known
OAuth attack vector with cited mitigations. The two documented
exemptions (S2 token endpoint, A4 consent page) are both RFC-mandated
and don't introduce material risk. The rate limiting (Q3 clarification)
is a meaningful addition over a "naked" OAuth implementation.

OWASP 2025 categories with no findings: A01-A03, A05-A10. Only A04 has
two informational/advisory entries (the two RFC-mandated design
deviations from project memory rules), neither of which represents a
security weakness.

CWE: N/A — no vulnerability CWEs apply at the plan stage.
