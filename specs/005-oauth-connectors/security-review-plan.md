---
document_type: security-review
review_type: plan
assessment_date: 2026-06-21
codebase_analyzed: acrossai-mcp-manager-new (specs/005-oauth-connectors plan artifacts)
total_files_analyzed: 11
total_findings: 6
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 1
low_count: 1
informational_count: 4
owasp_categories: [A01, A02, A04]
cwe_ids: [CWE-200, CWE-312, CWE-319, CWE-362, CWE-664, CWE-841]
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

# Security Review — Plan: OAuth / Claude Connectors (specs/005-oauth-connectors)

**Branch**: `005-oauth-connectors` | **Assessment Date**: 2026-06-21

---

## Executive Summary

Phase 5 specifies a hand-rolled OAuth 2.0 Authorization Code + PKCE
flow under `includes/OAuth/`. The plan is unusually thorough on the
RFC-conformance side (per-RFC-section validation chain, golden
fixtures, anti-replay revocation, rate limiting, audit logging) and
substantially harder to find weakness in than a typical "we'll add
OAuth" spec.

This review went beyond the inline `security-constraints.md` pass and
identified **one new MEDIUM finding** (SEC-001) that the inline review
missed: a TOCTOU race in code redemption that defeats the FR-014
anti-replay defense unless the redeem step uses an atomic
compare-and-swap. Also bumped F4 (HTTPS posture) from Informational to
LOW per CVSS scoring.

**Overall risk: MODERATE.** One MEDIUM (SEC-001 race condition) is the
overall-risk driver. SEC-002 (HTTPS warning-not-block) is LOW and
captured as a documented Assumption. The 4 Informational findings are
all by-design RFC-mandated exemptions or accepted operational
trade-offs.

The plan's documented exemptions (A4 consent form, S2 token endpoint
`__return_true`) are RFC-mandated and not security weaknesses; they
are framework / project memory tensions to elevate to A13/S7 memory
captures post-implementation.

**Required pre-implementation change**: spec FR-013 + contracts/token-endpoint.md
MUST be amended to specify atomic compare-and-swap on the redeem step
(SEC-001). Without that change, the FR-014 anti-replay defense fails
under concurrent requests.

---

## Plan Artifacts Reviewed

| Path | Role |
|---|---|
| `specs/005-oauth-connectors/spec.md` | 24 base FRs + FR-014a (rate limit) + FR-019a (audit) + FR-019c (cleanup); 3 clarifications |
| `specs/005-oauth-connectors/plan.md` | Constitution Check with 2 documented soft exemptions; 6 OAuth classes + 3 DB Query layers |
| `specs/005-oauth-connectors/research.md` | R1 dot-escape · R2 token entropy · R3 PKCE test vectors · R4 rate-limit key scheme · R5 audit event enum |
| `specs/005-oauth-connectors/data-model.md` | E1 codes (CliAuthLog extended) · E2 tokens · E3 audit · E4 rate-limit transients |
| `specs/005-oauth-connectors/contracts/discovery-as-metadata.md` | RFC 8414 metadata + golden fixture |
| `specs/005-oauth-connectors/contracts/discovery-resource-metadata.md` | RFC 9728 metadata + golden fixture |
| `specs/005-oauth-connectors/contracts/authorize-page.md` | Consent page HTML form + Approve/Deny flow |
| `specs/005-oauth-connectors/contracts/token-endpoint.md` | RFC 6749 §4.1.3 + §5.1 + §5.2 — 8-step validation chain |
| `specs/005-oauth-connectors/contracts/bearer-auth-filter.md` | `determine_current_user` algorithm + 4 invariants |
| `specs/005-oauth-connectors/quickstart.md` | 8-step RFC-conformant manual walk |
| `specs/005-oauth-connectors/security-constraints.md` | Trust boundary + data isolation table + 5 advisory findings + 12 confirmed patterns |

Also consulted: `docs/memory/INDEX.md` (memory state), `.specify/memory/constitution.md` v1.0.0, and prior security reviews (`002-admin-ui/security-review-plan.md`, `002-admin-ui/security-review-staged.md`, `004-mcp-clients/security-review-plan.md`).

---

## Vulnerability Findings

### SEC-001 — [MEDIUM — NEW] Race condition in authorization-code redemption defeats FR-014 anti-replay

- **Severity**: MEDIUM
- **Location**: `specs/005-oauth-connectors/contracts/token-endpoint.md` Step 5 + Step 8; `specs/005-oauth-connectors/spec.md` FR-013 + FR-014
- **OWASP Category**: A01:2025 — Broken Access Control
- **CWE**: CWE-362 — Concurrent Execution using Shared Resource with Improper Synchronization; CWE-841 — Improper Enforcement of Behavioral Workflow
- **CVSS v3.1**: 6.3 (AV:N/AC:H/PR:N/UI:N/S:U/C:H/I:H/A:N) — requires precise concurrent timing but no privilege; impact is double-spend of a stolen code
- **Spec-Kit Task**: TASK-SEC-001

**Description**: The validation chain in `contracts/token-endpoint.md`
describes Step 5 ("SELECT code where hash=X and redeemed_at IS NULL")
then Step 8 ("mark code as redeemed; issue access token"). Under
concurrent requests with the same code, the race window is:

```
T0  Request A: SELECT * FROM codes WHERE hash=X AND redeemed_at IS NULL → row found
T0  Request B: SELECT * FROM codes WHERE hash=X AND redeemed_at IS NULL → row found (concurrent)
T1  Request A: UPDATE codes SET redeemed_at=NOW() WHERE id=N           → 1 row updated, token A issued
T1  Request B: UPDATE codes SET redeemed_at=NOW() WHERE id=N           → 1 row updated (overwrites!), token B issued
```

Both requests pass Step 5 (the SELECT-based "not redeemed" check) at
T0 before either has set `redeemed_at` at T1. Both then issue tokens.
The FR-014 anti-replay defense (which triggers when `redeemed_at IS
NOT NULL` at Step 5) DOES NOT FIRE because at the moment both requests
checked, both saw NULL.

Result: **a stolen authorization code can be redeemed twice during the
race window**, producing two valid Bearer tokens. The FR-014
"second-redemption triggers revoke-all" defense only fires for a
TRULY-sequential third attempt — by which time the attacker has two
working tokens.

**Mitigating factors** (real but not sufficient):
- Race window is small (milliseconds on a fast DB)
- PKCE verifier (Step 7) prevents random attackers from succeeding
  without the verifier — only attackers with BOTH the leaked code AND
  the leaked verifier can execute this race
- Code expiry (10 min) bounds the window
- Audit log records both redemptions (visible post-hoc)

**Required remediation** (BEFORE implementation):

Amend FR-013 Step 8 + contracts/token-endpoint.md Step 8 to mandate
**atomic compare-and-swap**:

```sql
UPDATE wp_acrossai_mcp_cli_auth_logs
   SET completed_at = NOW()
 WHERE id = :code_row_id
   AND completed_at IS NULL;
```

Then check `$wpdb->rows_affected`:
- `1` → this request won the race; proceed to issue token
- `0` → another request already redeemed; treat as REPLAY (FR-014
  fires: revoke all tokens from this code, return `invalid_grant`,
  audit `failed_replay_attempt`)

The atomic UPDATE-with-condition is the standard SQL CAS pattern. No
performance cost; MySQL/MariaDB handle this in a single row-lock.

**Test coverage**: PHPUnit test should fork two threads (or simulate
via two sequential transactions in a TRANSACTION-READ-COMMITTED
isolation) and assert only one succeeds.

---

### SEC-002 — [LOW] No HTTPS hard-block on the token endpoint in production

- **Severity**: LOW
- **Location**: `specs/005-oauth-connectors/spec.md` §Assumptions ("HTTPS warning-not-block"); `contracts/token-endpoint.md`
- **OWASP Category**: A02:2025 — Cryptographic Failures
- **CWE**: CWE-319 — Cleartext Transmission of Sensitive Information
- **CVSS v3.1**: 3.7 (AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N) — requires network-position adversary AND a misconfigured production deployment
- **Spec-Kit Task**: TASK-SEC-002

**Description**: The plan documents that the token endpoint warns
admins at admin-notice level when HTTPS isn't configured but
deliberately does NOT refuse to issue tokens (to preserve local-dev
friendliness). A misconfigured production WordPress install with the
plugin active over HTTP will:
- Accept the token request over HTTP
- Issue a Bearer token in plaintext
- Be vulnerable to passive network attackers (any router on the path) capturing the token

The token is then valid for 1 hour; the attacker can use it as the
granting user against any MCP endpoint.

**Mitigating factors**:
- Admin notice surfaces the misconfiguration loudly
- Production deployments running through Cloudflare / nginx termination
  will typically have HTTPS at the edge regardless of the plugin's check
- 1-hour token lifetime limits the damage window
- Tokens are scoped to the originating server (FR-015 cross-server defense)

**Decision per spec Assumptions**: Accepted trade-off for local-dev
friendliness. Documented as a known limitation.

**Optional follow-up remediation** (post-this-phase):
Add a constant `ACROSSAI_MCP_REQUIRE_HTTPS = true` that operators
opt-into via `wp-config.php` — when true, the token endpoint returns
HTTP 426 Upgrade Required for non-HTTPS POSTs. Defaults to false for
local-dev backward compatibility.

---

### SEC-INFO-001 — [INFORMATIONAL] Token endpoint `permission_callback: __return_true`

- **Severity**: INFORMATIONAL (documented S2 exemption)
- **Location**: `specs/005-oauth-connectors/contracts/token-endpoint.md`; `spec.md` FR-011
- **OWASP Category**: A04:2025 — Insecure Design (boundary documentation, not weakness)
- **CWE**: CWE-664 — Improper Control of a Resource Through its Lifetime
- **CVSS v3.1**: 0.0 (informational — RFC-mandated design)
- **Spec-Kit Task**: N/A

**Description**: Memory rule **S2** forbids `__return_true` on
mutating REST routes. The token endpoint mutates (issues tokens) but
uses `__return_true` because RFC 6749 §2.3.1 specifies that
authentication for the token endpoint happens via the POST body
(`client_id` + `client_secret` + `code`), not session/header.

The exemption is well-defended: the FR-012 validation chain provides
the equivalent of a `permission_callback`'s gate — no token is issued
without valid client_id, valid client_secret (`hash_equals`), valid
code, matching PKCE verifier, and matching redirect_uri. The
remediation for the SEC-001 race condition closes the last gap.

**Decision**: Accept. **S7 memory capture queued** for post-implementation: "OAuth token endpoint is the documented exception to S2 — auth lives in POST body, not session/header."

---

### SEC-INFO-002 — [INFORMATIONAL] Consent page is a plain `<form>`, not DataForm

- **Severity**: INFORMATIONAL (documented A4 exemption)
- **Location**: `contracts/authorize-page.md`; `spec.md` FR-008 + §Admin UI Requirements
- **OWASP Category**: A04:2025 — Insecure Design (boundary documentation, not weakness)
- **CWE**: N/A
- **CVSS v3.1**: 0.0
- **Spec-Kit Task**: N/A

**Description**: Constitution §IV mandates DataForm/DataViews. The
consent page is a plain `<form>` per RFC 6749 §4.1.1's prescribed
consent UX. The exemption is RFC-mandated, not project preference.

Security defenses on the form remain robust: `wp_nonce_field` +
`check_admin_referer` for CSRF; `manage_options` recheck on POST
handler; output escaping (`esc_html` / `esc_attr` / `esc_url`) at
every render point per `contracts/authorize-page.md`.

**Decision**: Accept. **A13 memory capture queued** for post-implementation: "RFC-prescribed forms exempted from DataForm/DataViews."

---

### SEC-INFO-003 — [INFORMATIONAL] `X-Forwarded-For` is not trusted for rate-limit IP

- **Severity**: INFORMATIONAL (operational trade-off)
- **Location**: `specs/005-oauth-connectors/research.md` R4
- **OWASP Category**: A04:2025 — Insecure Design
- **CWE**: CWE-345 (Insufficient Verification of Data Authenticity) at the network layer; not a vulnerability in the plugin itself
- **CVSS v3.1**: 0.0 (informational — operator-configurable)
- **Spec-Kit Task**: N/A

**Description**: The rate-limit transient key uses
`$_SERVER['REMOTE_ADDR']` exclusively. Operators behind reverse
proxies (Cloudflare, nginx upstream) MUST configure their proxy to
populate `$_SERVER['REMOTE_ADDR']` with the real client IP — otherwise
all rate limiting collapses onto a single (proxy) IP and provides no
per-attacker isolation.

**Why this isn't a vulnerability in the plan**: trusting
`X-Forwarded-For` by default would let an attacker send any
`X-Forwarded-For` header to make the rate limiter associate their
attacks with someone else's IP. The plan's choice is the secure
default. The operator-configuration responsibility is documented.

**Decision**: Accept as-is. Optional future: `acrossai_mcp_trusted_proxy_ips` filter for opt-in `X-Forwarded-For` trust.

---

### SEC-INFO-004 — [INFORMATIONAL] Carried forward from Phase 2: Claude Connector Secret stored plaintext at rest

- **Severity**: INFORMATIONAL (CARRY-FORWARD)
- **Location**: This phase consumes `MCPServer.claude_connector_client_secret` (Phase 2's `data-model.md` E1)
- **OWASP Category**: A02:2025 — Cryptographic Failures
- **CWE**: CWE-312 — Cleartext Storage of Sensitive Information
- **CVSS v3.1**: 5.3 (AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:N/A:N) — Phase 2 baseline, unchanged
- **Spec-Kit Task**: TASK-SEC-001 from `002-admin-ui/security-review-plan.md` (already tracked)

**Description**: Phase 2's SEC-001 finding (Claude Connector Client
Secret stored plaintext in the DB) carries forward to Phase 5 because
the OAuth token endpoint's FR-012 Step 4 reads this column for
`hash_equals` comparison. Phase 5 introduces no new plaintext secret
storage — it consumes the existing one.

**Status**: Already tracked in `002-admin-ui/data-model.md` follow-up
notes for Phase 6 (this phase) to extend Constitution §III bullet 7 to
cover outbound client secrets. Phase 5 implementation, in passing,
could add a one-line filter `acrossai_mcp_oauth_secret_at_rest` that
operators apply to wrap their own encryption, but that's a follow-up
nice-to-have, not a Phase 5 deliverable.

**Decision**: Accept; no new action required in this phase's remediation
plan.

---

## Confirmed Secure Patterns (15)

The plan **demonstrates** these patterns by design:

1. **All codes + tokens hashed at storage** (SHA-256; FR-020) — never plaintext at rest
2. **Constant-time comparisons** (`hash_equals()`) for every secret check — `client_secret`, code hash, PKCE verifier (R3)
3. **PKCE S256 required at authorize-time** — `plain` rejected outright (Assumption)
4. **Anti-replay revocation** (FR-014) — second redemption revokes all child tokens (caveat: requires SEC-001 fix to be race-free)
5. **Cross-server token defense** (FR-015 step 6) — `server_id` predicate in the DB query, atomic
6. **No discovery oracle** (bearer-auth-filter.md) — invalid tokens produce no audit row + no distinguishable response from "no token at all"
7. **Rate-limit BEFORE validation** (FR-014a) — attackers can't probe per-step error messages during lockout
8. **`X-Forwarded-For` NOT trusted by default** (R4) — defends against header-injection IP spoofing
9. **CSRF defense on consent form** (FR-009 + FR-010) — `wp_nonce_field` + `check_admin_referer`
10. **`.well-known` rewrite rule dot escaped** (R1, B4 mitigation) — `\.well-known` literally
11. **Append-only audit log** (FR-019a) — no update path; 90-day retention via cron
12. **`Cache-Control: no-store`** on token endpoint responses (RFC 6749 §5.1 conformance)
13. **Form-encoded only** on token endpoint — JSON body rejected (narrower attack surface; positive hardening per SEC-INFO-005 in inline review)
14. **`token_hash_prefix` (8 hex chars) only in audit log** — preserves bulk of hash entropy as a secret
15. **`details_json` field forbidden from logging raw codes/tokens/secrets** (data-model.md E3 privacy boundary)

---

## Action Plan & Next Steps

### MANDATORY: Pre-implementation spec amendments

| Task | Action |
|---|---|
| **TASK-SEC-001** | Amend `spec.md` FR-013 Step 8 + `contracts/token-endpoint.md` Step 8 to mandate atomic `UPDATE … WHERE id=N AND completed_at IS NULL` with rows-affected check. Treat 0-rows-affected as REPLAY (FR-014 path). Add PHPUnit test that forks concurrent redeem attempts and asserts exactly one succeeds. |

### Items for this phase's tasks.md (when /speckit-tasks runs)

| Task | Action |
|---|---|
| **TASK-SEC-001 (impl)** | Atomic CAS redeem in `Storage::redeem_authorization_code()`; failing the CAS triggers FR-014 anti-replay path |
| **TASK-SEC-001 (test)** | PHPUnit test that simulates concurrent redeem via 2 sequential transactions in REPEATABLE-READ isolation; assert one returns token, one returns `invalid_grant` |
| **Spec security checklist (16 items)** | Add as DoD gate; verify each at impl time |
| **`assertRewriteRuleEscape` test (R1)** | B4 mitigation made testable |
| **Per-RFC-error-path PHPUnit tests** | 8+ envelopes per RFC §5.2 |

### Items for follow-up phases (out of Phase 5 scope)

| Task | Action |
|---|---|
| **TASK-SEC-002 (Phase 5+1)** | Optional HTTPS hard-block via `ACROSSAI_MCP_REQUIRE_HTTPS` constant in `wp-config.php` |
| **SEC-INFO-004 (Phase 6+)** | Wrap `claude_connector_client_secret` at rest with `WP_SECURE_AUTH_KEY`-derived AES-GCM; extend Constitution §III bullet 7 |

### Items NOT to do

- **Don't** add `permission_callback` to the token endpoint — S2 exemption is RFC-mandated
- **Don't** internationalize the consent page error responses — RFC envelopes use canonical English error codes
- **Don't** trust `X-Forwarded-For` by default — defensive default
- **Don't** add HTTPS hard-block in this phase — deliberate Assumption

### Durable Memory Preservation

Reviewed for new security patterns or recurring vulnerabilities:

- **SEC-001 (race condition)** — generalizable lesson: any "check-then-act" sequence on a one-shot credential MUST use atomic CAS. This is reusable beyond OAuth — applies to any one-shot tokens, idempotency keys, etc. **Strong candidate for memory capture** post-implementation:

  > **B10 candidate** — "Check-then-act on one-shot credentials (auth codes, idempotency keys, single-use tokens) MUST use atomic compare-and-swap (UPDATE … WHERE … AND not_consumed_column IS NULL with rows-affected check), not SELECT-then-UPDATE. SELECT-then-UPDATE under concurrent access defeats anti-replay defenses."

- **S2/A4 RFC-mandated exemptions** — pre-queued A13 + S7 captures from the governance-summary remain valid

**Decision**: I'll **not** auto-trigger `/speckit-memory-md-capture`
from this review. B10 is the most durable lesson but it deserves to be
captured AFTER implementation confirms the CAS pattern works in
practice + has a code reference. If you want B10 captured now anyway,
say so explicitly.

### Remediation Planning

**One MEDIUM finding (SEC-001) requires spec amendment before
implementation.** Recommend running `/speckit-security-review-followup`
to generate a TASK-SEC-001 remediation task that lands in tasks.md
before any other Phase 5 work.

For SEC-002 (LOW) and the 4 Informational findings: no immediate
action required.

---

## Memory Hub INDEX.md Row

Proposed routing row to paste into `docs/memory/INDEX.md` under a
`## Security Reviews` section (or append to the existing one started
in Phase 2):

```text
| specs/005-oauth-connectors/security-review-plan.md | plan | 2026-06-21 | MODERATE | C:0 H:0 M:1 L:1 | A01,A02,A04 |
```
