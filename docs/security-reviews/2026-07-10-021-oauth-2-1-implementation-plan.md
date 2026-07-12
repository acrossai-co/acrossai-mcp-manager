---
document_type: security-review
review_type: plan
assessment_date: 2026-07-10
codebase_analyzed: acrossai-mcp-manager (Feature 021 — oauth-2-1-implementation)
total_files_analyzed: 8
total_findings: 9
overall_risk: HIGH
critical_count: 0
high_count: 1
medium_count: 4
low_count: 2
informational_count: 2
owasp_categories: [A01, A02, A04, A05, A07, A09]
cwe_ids: [CWE-352, CWE-287, CWE-770, CWE-601, CWE-200, CWE-778, CWE-693]
field_summaries:
  document_type: "Always 'security-review'. Allows indexers to skip non-review documents."
  review_type: "Which command generated this document: audit, branch, staged, plan, tasks, or followup."
  assessment_date: "ISO 8601 date the review was performed (YYYY-MM-DD)."
  overall_risk: "Highest severity tier with active findings (CRITICAL, HIGH, MODERATE, LOW, INFORMATIONAL)."
  critical_count: "Number of Critical findings (CVSS 9.0-10.0)."
  high_count: "Number of High findings (CVSS 7.0-8.9)."
  medium_count: "Number of Medium findings (CVSS 4.0-6.9)."
  low_count: "Number of Low findings (CVSS 0.1-3.9)."
  informational_count: "Number of Informational findings."
  owasp_categories: "OWASP Top 10 2025 categories (A01-A10) that have at least one finding."
  cwe_ids: "CWE identifiers referenced in this document."
  finding_id: "Unique finding identifier (SEC-NNN) for cross-referencing and task linkage."
  location: "File path and line number of the vulnerable code (path/to/file.ext:line)."
  owasp_category: "OWASP Top 10 2025 category for this finding (AXX:2025-Name)."
  cwe: "Common Weakness Enumeration identifier with short name (CWE-NNN: Name)."
  cvss_score: "CVSS v3.1 base score (0.0-10.0). 9.0+=Critical, 7.0-8.9=High, 4.0-6.9=Medium, 0.1-3.9=Low."
  spec_kit_task: "Spec-Kit task ID for backlog tracking and remediation follow-up (TASK-SEC-NNN)."
---

# Security Review — Feature 021: OAuth 2.1 + PKCE Authorization Server (Plan)

**Feature branch**: `021-oauth-2-1-implementation`
**Reviewer**: `/speckit-security-review-plan` (automated)
**Plan artifact hash**: `plan.md` @ 2026-07-10

## Executive Summary

Feature 021's plan is fundamentally sound at the boundary controls — S3 (SHA-256 hashing at rest with `char(64)` widths), S9 (consent surface state re-validation from DB), PKCE S256 mandatory, RFC 8707 audience-binding enforced at call time (Q1), and F011 phantom-guard / SUBCLASS-NO-USE-COLLISION conventions all correctly applied. The plan explicitly rejects `plain` PKCE, mandates the `resource` parameter, requires WordPress nonce on the consent form, and honors DEC-UNINSTALL-OPT-IN-GATE.

However, the plan has **one HIGH-severity gap**: refresh-token *reuse detection* is described (SEC-020-adjacent replay concept), but the shipped-refresh-token-revocation approach only revokes the presented token, NOT the whole token family. Under RFC 9700 (OAuth 2.1 Security BCP §2.2.2), a stolen refresh token that races the legitimate client to `/token` obtains a fresh access+refresh pair; the legitimate client's next refresh fails but the attacker retains access until the new access token expires (up to 3600s) AND the attacker's own refresh token stays valid (30 days). Family-scoped revocation on reuse detection is the standard mitigation.

Four MEDIUM-severity gaps address: (1) `state` parameter optionality under PKCE (RFC 9700 §2.1 SHOULD → BCP-adjacent CSRF exposure on callback endpoints); (2) IP determination strategy for rate-limiting not documented (X-Forwarded-For handling behind reverse proxies could reduce 60/min to site-wide); (3) `redirect_uri` validation scheme rules under-specified (need explicit `https:` or loopback with strict scheme match, not "not HTTP"); (4) DCR dedup response leaks whether a specific metadata fingerprint is already registered.

Two LOW findings note operational gaps (no user-facing revocation endpoint, non-discovery CORS not documented). Two INFO findings note observability + hashing algorithm rationale gaps.

**None are blocking on their own.** Fixing HIGH before `/speckit-tasks` is strongly recommended.

## Plan Artifacts Reviewed

| Path                                              | Purpose                                              |
|---------------------------------------------------|------------------------------------------------------|
| `specs/021-oauth-2-1-implementation/plan.md`      | Implementation plan (Constitution check + Complexity + Project structure) |
| `specs/021-oauth-2-1-implementation/spec.md`      | Feature spec with 4 clarifications                   |
| `specs/021-oauth-2-1-implementation/research.md`  | Design decisions + alternatives                       |
| `specs/021-oauth-2-1-implementation/data-model.md`| 3 BerlinDB schemas + bespoke Query methods            |
| `specs/021-oauth-2-1-implementation/contracts/rest-api.md` | 6 endpoints across two routing mechanisms      |
| `specs/021-oauth-2-1-implementation/contracts/php-hooks.md` | 1 filter + 4 actions                           |
| `specs/021-oauth-2-1-implementation/contracts/connector-profile.md` | `AbstractConnectorProfile` contract    |
| `specs/021-oauth-2-1-implementation/memory-synthesis.md` | Memory context                                 |

## Vulnerability Findings

---

### SEC-021-001 — Refresh-token family revocation missing (RFC 9700 §2.2.2 gap)

- **finding_id**: SEC-021-001
- **location**: `spec.md` FR-017 + spec.md Edge Cases §"Refresh token reuse detection"; `contracts/rest-api.md` §Grant type refresh_token; `data-model.md` §OAuthTokens
- **owasp_category**: A07:2025-Identification and Authentication Failures
- **cwe**: CWE-287: Improper Authentication
- **cvss_score**: 7.4 (HIGH — vector: `AV:N/AC:H/PR:N/UI:R/S:U/C:H/I:H/A:N`)
- **spec_kit_task**: TASK-SEC-021-001

**Description**

FR-017 states: "For refresh_token grants, the plugin MUST look up the refresh token by SHA-256 hash, verify client credentials, revoke the presented refresh token (single-use rotation), and issue a fresh access + refresh pair carrying forward the original resource and scope." The plan revokes only the presented token. The Edge Cases section §"Refresh token reuse detection" says: "If the SAME refresh token is presented again (would only happen if a rogue actor intercepted it), the second call returns invalid_grant because the row is already `revoked=1`."

This partially addresses replay but does NOT implement **token family revocation**, which is the standard mitigation under RFC 9700 (OAuth 2.1 Security BCP §2.2.2). Threat scenario:

1. Attacker exfiltrates refresh token `RT_1` from a legitimate client's storage.
2. Attacker races the legitimate client to `/token` and wins (or attacker is offline while client is online — same outcome).
3. `/token` revokes `RT_1`, issues `RT_2` + `AT_2` to whoever presented `RT_1`.
4. Legitimate client's next refresh attempt with `RT_1` fails (`invalid_grant`) — but the attacker now holds `RT_2` + `AT_2` and can continue calling MCP tools for up to 3600s + 30 days.

The attacker's window is bounded by `RT_2`'s TTL (30 days) unless they in turn get exfiltrated. Family revocation stops this: if `RT_1` is presented after being revoked (or `RT_2` is presented in a way that indicates it descended from a stolen ancestor), **the entire lineage** — every token issued from the original auth code — is revoked. The user must re-authenticate, but the attacker's access is capped at the access-token TTL (3600s).

Concretely, this requires either:
- A `parent_token_id` column on `OAuthTokens` (or a separate `token_family_id` column with a fresh UUID per auth-code chain) so a family can be identified.
- A boolean flag on the family indicating whether reuse has been detected.
- On reuse detection (presented token → row where `revoked=1`), the plugin issues NO new tokens AND bulk-revokes every non-revoked token in the same family.

**Threat model**

Attacker: any party who exfiltrates a legitimate client's refresh token (via disk read, memory dump, XSS, or backup theft).

Currently, the attacker can extend their window to the refresh-token TTL (30 days). With family revocation, the attacker's window collapses to the access-token TTL (3600s).

**Recommendation**

Before `/speckit-tasks`: extend `data-model.md` §OAuthTokens with a `token_family_id char(36)` column (UUIDv4) — every token issued from the same auth code shares a family_id; every refresh rotation carries the parent's family_id forward. Add FR-043 to spec: "On refresh-token reuse detection (presented refresh token whose row has `revoked=1`), the plugin MUST bulk-revoke every non-revoked token sharing the same `token_family_id` AND fire `acrossai_mcp_manager_oauth_token_revoked` per revoked token with reason `family_reuse_detected`. The presented refresh MUST return `invalid_grant`." Add `TokensQuery::revoke_by_family_id( string $family_id, string $reason ): int`.

**Blocking**: YES (recommended). This is a well-known OAuth security invariant that MCP connectors' threat model justifies. Not addressing it before implementation makes retrofitting expensive (schema change + all issuance paths must set family_id).

---

### SEC-021-002 — `state` parameter treated as optional under PKCE (RFC 9700 §2.1 gap)

- **finding_id**: SEC-021-002
- **location**: `contracts/rest-api.md` §`/authorize` GET query params §`state`; `spec.md` FR-004
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-352: Cross-Site Request Forgery
- **cvss_score**: 5.4 (MEDIUM — vector: `AV:N/AC:H/PR:N/UI:R/S:U/C:L/I:L/A:L`)
- **spec_kit_task**: TASK-SEC-021-002

**Description**

The `contracts/rest-api.md` §GET query params entry for `state` says "Recommended, not required. Echoed back." Under RFC 9700 (OAuth 2.1 BCP §2.1): "clients using the authorization code grant MUST use PKCE and SHOULD use state parameter for CSRF protection". F021 requires PKCE (S256 mandatory), so state is not strictly required — but PKCE alone protects against code-injection, not against CSRF on the callback endpoint. A malicious page can trigger a victim's browser to complete an authorize flow with attacker-controlled state, allowing the attacker to bind the victim's WordPress session to attacker-known context.

For MCP connectors that access sensitive tool surfaces (F015 access control + F017 abilities + F020 tools), the CSRF surface should be minimized even under PKCE.

**Recommendation**

Update `contracts/rest-api.md` and FR-004 to specify: `state` is RECOMMENDED per RFC 9700 §2.1. The AS MUST reject `/authorize` requests without `state` with a warning header/log entry under `WP_DEBUG` but MUST NOT reject for missing `state` in production (breaking older MCP clients that omit it is worse than the CSRF surface). Alternatively, offer a settings toggle: `acrossai_mcp_manager_require_state` default `false` (compatible), can be set to `true` for stricter deployments.

**Not blocking** but worth resolving before tasks.

---

### SEC-021-003 — IP determination strategy for rate limits undefined (X-Forwarded-For handling)

- **finding_id**: SEC-021-003
- **location**: `spec.md` FR-027, FR-028; `contracts/rest-api.md` §Rate limit
- **owasp_category**: A04:2025-Insecure Design
- **cwe**: CWE-770: Allocation of Resources Without Limits or Throttling
- **cvss_score**: 5.3 (MEDIUM — vector: `AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:L`)
- **spec_kit_task**: TASK-SEC-021-003

**Description**

FR-027 and FR-028 specify rate limits at 10/IP/60s and 60/IP/60s. The plan does not specify how the "IP" is determined. If `$_SERVER['REMOTE_ADDR']` is used behind Cloudflare / nginx / any reverse proxy, every request appears to come from the proxy's IP — the 60/min budget effectively becomes site-wide, and legitimate concurrent connectors from different regions could exceed it. Conversely, blindly trusting `X-Forwarded-For` allows attackers to bypass rate limits by spoofing that header.

**Recommendation**

Add FR-044 to spec: "The plugin MUST determine the client IP for rate limiting as follows: (a) if the WordPress `wpb_trusted_proxies` filter is set (or the plugin's own `acrossai_mcp_manager_trusted_proxies` filter returns a non-empty list), use the leftmost X-Forwarded-For entry that is NOT in the trusted-proxy list (RFC 7239 forwarded-for semantics); (b) otherwise use `$_SERVER['REMOTE_ADDR']`. The plugin MUST NOT trust `X-Forwarded-For` unconditionally." Document this in `contracts/rest-api.md`.

**Not blocking** but the "correct behind a proxy" case is a common operator gotcha.

---

### SEC-021-004 — `redirect_uri` validation scheme rules under-specified

- **finding_id**: SEC-021-004
- **location**: `spec.md` FR-021, FR-007; `contracts/rest-api.md` §DCR §Validation
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-601: URL Redirection to Untrusted Site ('Open Redirect')
- **cvss_score**: 4.8 (MEDIUM — vector: `AV:N/AC:H/PR:L/UI:R/S:U/C:L/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-021-004

**Description**

The plan says "every URI must be either loopback ... OR HTTPS" (FR-021). This is directionally correct but doesn't explicitly reject dangerous schemes like `javascript:`, `data:`, or `file:`. `parse_url()` on `javascript:alert(1)` returns `scheme=javascript, path=alert(1)` — a naive "starts with https" check would fail-open on a URL like `httpsX://evil.com` (starts with lowercase `https` if attacker crafts it right).

**Recommendation**

Update FR-021 and `contracts/rest-api.md` to require **strict scheme match**: the parsed `scheme` MUST equal exactly `https` OR the parsed `host` MUST be exactly one of `127.0.0.1`, `localhost`, `::1`. Reject `javascript:`, `data:`, `file:`, `ftp:`, `mailto:`, `about:` explicitly (case-insensitive). Verify with `parse_url( $uri, PHP_URL_SCHEME )` and `parse_url( $uri, PHP_URL_HOST )` separately. Add `is_valid_redirect_uri()` helper on `ClientRegistrationController` with the exact logic.

**Not blocking** — likely resolved during implementation, but explicit contract prevents regression.

---

### SEC-021-005 — DCR dedup response leaks metadata registration status

- **finding_id**: SEC-021-005
- **location**: `spec.md` FR-022; `contracts/rest-api.md` §DCR §Idempotency + §Response 200 OK (dedup)
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-200: Exposure of Sensitive Information to an Unauthorized Actor
- **cvss_score**: 3.1 (MEDIUM — vector: `AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N`)
- **spec_kit_task**: TASK-SEC-021-005

**Description**

FR-022 says "the pre-existing client's metadata MUST be returned without a new secret issuance and NO observability event fires." This means an unauthenticated attacker can submit arbitrary client metadata combinations to `/register` and, on 200 (dedup hit), learn that a specific metadata fingerprint (e.g., specific `redirect_uris` + `client_name` combo) is already registered on this site. Attackers can enumerate the registered client population by iterating known-MCP-connector metadata combos.

The information disclosed is low-sensitivity (`client_name`, `redirect_uris`) but combined with the connector list on `AIConnectorsTab`, reveals the plugin's active MCP integrations to any observer.

**Recommendation**

Two options:
- **(a) Return 429-shaped response on dedup hit**: pretend the request was rate-limited even when it was a dedupe. Higher friction for legitimate DCR clients that re-register after cache clears.
- **(b) Return the same 201 response with a fresh client_id and secret each time**: allows infinite DCR client creation for the same metadata. Simpler but breaks the dedupe optimization.
- **(c) Keep current dedup behavior**: accept the low-sensitivity information disclosure as a trade-off for the operational simplicity of idempotent registrations.

Recommend (c) — document as accepted trade-off in `spec.md` §Assumptions. The disclosed information is metadata the AI client already shares publicly (redirect URIs and client_name are usually publishable).

**Not blocking** — INFORMATIONAL / documented trade-off.

---

### SEC-021-006 — No user-facing token revocation endpoint (RFC 7009 not implemented)

- **finding_id**: SEC-021-006
- **location**: `spec.md` §Assumptions §"No introspection or revocation REST endpoints"
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 3.7 (LOW — vector: `AV:N/AC:H/PR:L/UI:R/S:U/C:N/I:L/A:L`)
- **spec_kit_task**: TASK-SEC-021-006

**Description**

The spec accepts the omission of RFC 7009 (revocation) as out-of-scope for v1. Users cannot proactively invalidate a compromised access token — they must wait for TTL expiry (up to 3600s) or ask the admin to run `wp_ajax` or manually delete the row. On the plus side, refresh tokens are revocable via any admin regen; on the down side, mobile-app users who lose their device have no self-service revocation.

**Recommendation**

Add a follow-up feature note in `spec.md` §Assumptions: "Self-service token revocation (RFC 7009 `/revoke`) is planned as a follow-up feature. In v1, users must contact an admin to manually revoke tokens via the AIConnectorsTab's Regenerate action or via wp-cli."

**Not blocking** — documented deferral.

---

### SEC-021-007 — CORS policy not documented for non-discovery endpoints

- **finding_id**: SEC-021-007
- **location**: `contracts/rest-api.md` §`/authorize` + §`/token` + §DCR
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-693: Protection Mechanism Failure
- **cvss_score**: 3.1 (LOW — vector: `AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-021-007

**Description**

Discovery endpoints explicitly set `Access-Control-Allow-Origin: *`. The contracts don't document CORS for `/authorize`, `/token`, or `/register`. Absent explicit policy, WordPress core's default REST CORS behavior applies to `/register` (typically no CORS). `/authorize` is a browser-navigation endpoint (redirect target) so CORS is irrelevant there. `/token` should NOT have CORS wildcard — POST from arbitrary origins with client credentials would allow XSS-hosted token theft.

**Recommendation**

Update `contracts/rest-api.md` to explicitly note:
- `/authorize` — no CORS headers (browser navigation, not fetch).
- `/token` — no CORS headers (blocks browser JS from posting).
- `/register` (DCR) — no CORS headers by default. Companion plugins wanting to enable browser-based DCR clients can filter via a documented hook (out of scope for v1).

**Not blocking** — accepted defaults.

---

### SEC-021-008 — Successful-consent audit action not fired

- **finding_id**: SEC-021-008
- **location**: `contracts/php-hooks.md` §4 actions
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 2.4 (INFORMATIONAL — vector: `AV:N/AC:H/PR:N/UI:N/S:U/C:N/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-021-008

**Description**

The plan fires `acrossai_mcp_manager_oauth_authorization_denied` for denials but NOT `..._authorization_approved` for approvals. Audit consumers can't distinguish "user consented" from silent state changes (Approve → auth code issued → user browsers away before /token exchange fires). This is a mild logging asymmetry — approvals are inferrable from the eventual `token_issued` fire, but denials are only visible via the explicit action.

**Recommendation**

Consider adding `acrossai_mcp_manager_oauth_authorization_approved` (args: `$client_id, $user_id, $redirect_uri`) alongside the deny action. Symmetric event structure aids audit reconciliation. Or note in `php-hooks.md` §Design Notes that approvals are audible via the subsequent `token_issued` fire (reason: pending auth codes are always eventually consumed, denied, or expired).

**Not blocking** — INFORMATIONAL.

---

### SEC-021-009 — Client-secret hashing algorithm rationale not documented

- **finding_id**: SEC-021-009
- **location**: `spec.md` FR-039, FR-040; `data-model.md` §OAuthClients §Data invariants
- **owasp_category**: A02:2025-Cryptographic Failures
- **cwe**: CWE-916: Use of Password Hash With Insufficient Computational Effort
- **cvss_score**: 2.0 (INFORMATIONAL — vector: `AV:N/AC:H/PR:H/UI:N/S:U/C:L/I:N/A:N`)
- **spec_kit_task**: TASK-SEC-021-009

**Description**

The plan uses SHA-256 for `client_secret_hash`. For user-chosen passwords, this would be a MAJOR finding (SHA-256 is not memory-hard; brute-force is feasible). For server-issued 256-bit random secrets (`bin2hex(random_bytes(32))` = 64 hex chars = 256 bits of entropy), SHA-256 is standard practice — the entropy floor makes offline brute-force computationally infeasible. Same reasoning as OAuth libraries like Doorkeeper (Ruby), django-oauth-toolkit (Python), and league/oauth2-server (PHP) all use SHA-256 or SHA-512 for full-entropy server-issued secrets.

**Recommendation**

Add a sentence to `spec.md` §Assumptions or `data-model.md` §Data invariants: "Client secrets are 256-bit random values (`random_bytes(32)`), so SHA-256 provides adequate at-rest protection without the memory-hard cost of argon2id. Argon2id would be required if secrets were user-chosen." Prevents a well-meaning future reviewer from proposing a needless migration.

**Not blocking** — accepted trade-off documentation.

---

## Confirmed Secure Patterns

These design choices are explicitly correct and should be preserved through implementation:

- **PKCE S256 mandatory** — `plain` explicitly rejected regardless of what metadata advertises. Compliant with RFC 6749 + Anthropic's connector spec.
- **RFC 8707 audience-binding at call time** — Q1 clarification codified `TokenValidator` enforces token's `resource` matches request target URL. Pre-empts F017's storage-vs-enforcement decoupling class of bug.
- **Auth code single-use via atomic CAS** — Follows B10 canonical pattern (`CliAuthLog\Query::redeem_atomic`). `1 === $wpdb->rows_affected` semantics; no SELECT-then-UPDATE race.
- **All secrets SHA-256 hashed at rest** — `char(64)` invariants enforced; `hash_equals` on every comparison; no raw persistence anywhere.
- **Consent surface state re-validation** — S9 constraint honored: `handle_post` re-loads client + redirect + resource from DB, never trusts hidden inputs.
- **WordPress nonce on consent form** — S1 constraint; `wp_verify_nonce` on POST or 403.
- **Explicit `permission_callback` on admin generate-client** — S2 constraint; `manage_options` + nonce.
- **DCR permission via rate-limiter** — S8 constraint (body-authenticated exception); RFC 7591 allows unauthenticated registration under rate-limit.
- **`/token` `__return_true`** — S7 constraint (RFC 6749 §2.3.1); token endpoint handles its own body-based auth.
- **HTTPS or loopback for redirect URIs** — S9-adjacent; validated at both registration + authorize time.
- **BerlinDB `char(64)` widths** — B20 constraint; cryptographic invariants preserved from F011.
- **Phantom-version guard** — F011 DEC-BERLINDB-TABLE-REQUEST-BOOT.
- **User deletion cascade** — Q4 / FR-042; hooks `deleted_user` @ 10; bulk-revokes tokens + deletes codes with `token_revoked` action per row.
- **`resource` parameter mandatory + validated on-site or loopback** — prevents cross-site audience binding attacks.
- **No JWT, no signing keys** — reduces cryptographic complexity + attack surface.
- **Zero external OAuth library** — Constitution VI Tier 1 + DEC-OAUTH-NO-LIBRARY; native PHP crypto only.
- **Refresh token rotation on every use** — single-use pattern; combined with SEC-021-001 remediation, becomes fully compliant with RFC 9700 §2.2.2.
- **Rate limits with RFC-6749-shaped 429 body + `Retry-After` header** — compliant with FR-027, FR-028.

---

## Action Plan & Next Steps

### Blocking before `/speckit-tasks`

1. **SEC-021-001 (HIGH)**: Add FR-043 (refresh-token family revocation) + `token_family_id` column on `OAuthTokens` + `TokensQuery::revoke_by_family_id()` method. Update Edge Cases §Refresh token reuse detection to describe family revocation.

### Recommended before `/speckit-implement`

2. **SEC-021-002 (MEDIUM)**: Document `state` parameter policy (RECOMMENDED under PKCE per RFC 9700; log warning under `WP_DEBUG`; optional strictness toggle).
3. **SEC-021-003 (MEDIUM)**: Add FR-044 (IP determination strategy) with `acrossai_mcp_manager_trusted_proxies` filter for reverse-proxy handling.
4. **SEC-021-004 (MEDIUM)**: Update FR-021 with strict-scheme + host-based redirect URI validation (reject `javascript:`, `data:`, `file:`, etc.).

### Recommended during implementation

5. **SEC-021-005 (MEDIUM)**: Document DCR dedup information disclosure as accepted trade-off in `spec.md` §Assumptions.
6. **SEC-021-006 (LOW)**: Add follow-up feature note for RFC 7009 revocation endpoint.
7. **SEC-021-007 (LOW)**: Document CORS policy in `contracts/rest-api.md` for `/token`, `/authorize`, `/register`.
8. **SEC-021-008 (INFO)**: Add `acrossai_mcp_manager_oauth_authorization_approved` action for audit symmetry.
9. **SEC-021-009 (INFO)**: Document SHA-256 rationale for server-issued secrets.

### Durable Memory Preservation

**Deferred**. No new systemic security patterns identified that don't already exist in memory. The refresh-token-family-revocation pattern (SEC-021-001) is worth capturing post-implement as a new DEC-* entry (candidate: `DEC-OAUTH-REFRESH-FAMILY-REVOCATION`) or added to BUGS.md as a preventive pattern for future OAuth features.

### Remediation Planning

**Recommendation**: run `/speckit-security-review-followup` after SEC-021-001 is addressed in the spec/plan/data-model. That command materializes each finding above into a `TASK-SEC-021-NNN` task in `tasks.md` so `/speckit-tasks` can sequence remediation alongside feature work.

---

## Memory Hub INDEX.md Row

Paste into `docs/memory/INDEX.md` §Security Reviews:

```text
| docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-plan.md | plan | 2026-07-10 | HIGH | C:0 H:1 M:4 L:2 | A01,A02,A04,A05,A07,A09 |
```
