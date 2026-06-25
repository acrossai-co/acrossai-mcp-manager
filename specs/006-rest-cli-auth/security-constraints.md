# Security Constraints — Phase 6: REST CLI Auth Controller + Phase 6.0 FrontendAuth

**Date**: 2026-06-25 | **Branch**: `006-rest-cli-auth`
**Authoritative review of**: `plan.md`, `spec.md` (post-Q1–Q4 clarifications), `research.md`, `data-model.md`, `contracts/*`, `quickstart.md`

This document organises security controls by trust boundary, surfaces planning-stage findings, and documents the secure patterns the plan demonstrates by design. Phase 6 inherits two load-bearing lessons from Phase 5 (SEC-002 Content-Type strict-allow-list via Q2; FR-015 cross-server defense via Q4) without re-litigating them.

---

## Trust Boundaries

| Boundary | Crossing | Server-side Gate |
|---|---|---|
| **Anonymous → `/health`** | `GET` | None — public read; FR-001 returns plugin metadata only (no secrets) |
| **Anonymous → `/auth/start`** | `POST` | Content-Type allow-list (FR-015 / Q2); REST param presence (`server_id`); short-lived transient write with no user identity |
| **Anonymous → `/auth/status`** | `GET` | None — but the endpoint returns `{"approved": false}` on every cold lookup including server-id mismatch (Q4 oracle-defense) |
| **Anonymous → `/auth/exchange`** | `POST` | FR-006 8-step chain (Q2 Content-Type → Q1 audit recorder calls). Body's `code` IS the authentication credential. |
| **Bearer session token → `/servers`** | `GET Authorization: Bearer` | `verify_session_token()` permission callback. Q4 binds the lookup to the consented `server_id`; cross-server enumeration impossible. |
| **Browser admin → FrontendAuth approval page** | `GET /acrossai-mcp-manager/?action=cli_auth_*` | `is_user_logged_in()` redirect to `wp-login`; `current_user_can('manage_options')` recheck; `check_admin_referer('cli_auth_approve_<code>')` on the state-mutating approve action |
| **FrontendAuth → CliController static call** | `CliController::approve_auth_code( $code, $user_id )` | No interim trust gate — FrontendAuth already validated user/nonce/code shape. The static method itself re-validates the transient (`status === 'pending'`). |
| **CliController → CliAuthLog\Recorder** | static method calls | Internal — both classes ship in this phase. Recorder is `class_exists`-guarded at call sites; absent class → `error_log()` + continue (audit failure is non-fatal). |
| **Plugin → WP core App Passwords** | `WP_Application_Passwords::create_new_application_password()` | `class_exists` guard (FR-006 step 4) → HTTP 501 `not_supported` when absent. Result wrapped in `is_wp_error()` check. |
| **Plugin → transient API** | `set_transient` / `get_transient` / `delete_transient` | TTL bounded (300 s / 600 s); single-use cleanup on `/auth/exchange` success. |

---

## Data Isolation

| Data | Scope | Isolation Mechanism |
|---|---|---|
| Authorization codes (E1 transient) | Per-(auth_code) state machine | Key prefix `acrossai_cli_auth_`; 32-hex random suffix; TTL 5 min auto-eviction |
| Session tokens (E2 transient) | Per-(session_token, server_id) tuple | Key prefix `acrossai_session_`; **value bound to `server_id` per Q4**; TTL 10 min |
| Application Passwords | Per-(user_id, server_id, code_prefix) — name uniqueness via Q3 | WP core hashes at storage; per-app revocation; 30-day default expiry |
| Audit rows | Append-only — Phase 2 `acrossai_mcp_cli_auth_logs` table | `status` column discriminates: `'approved'`, `'success'` (this phase) vs `'oauth_code_issued'` (Phase 5). No schema change. |

---

## Async Security Context

**Zero async surfaces this phase.** No cron events, no background workers, no message queues. Transient TTLs are managed by WordPress core (`wp-cron` for the database fallback; object cache for Redis/Memcached). No race-condition surface this phase introduces beyond the documented B10 deferral on `/auth/exchange`.

The Phase 5 cleanup cron (`acrossai_mcp_oauth_cleanup`) does NOT touch this phase's audit rows (the Phase 5 cleanup only deletes `status='oauth_code_issued'` rows; this phase writes `status='approved'` and `status='success'`).

---

## Findings

### F1. **[Advisory — documented exemption + Q2/Q4 mitigations]** `__return_true` on 4 of 5 REST routes

- **Where**: `contracts/health.md`, `contracts/auth-start.md`, `contracts/auth-status.md`, `contracts/auth-exchange.md`; FR-012
- **What**: Memory S2 forbids `__return_true` on mutating REST routes; `/auth/start` (mutating: writes transient) and `/auth/exchange` (mutating: creates Application Password) both use `__return_true`. The exemption is BROADER than Phase 5 S7 (OAuth-specific).
- **Mitigations in place** (post-Q2/Q4):
  1. **FR-015 Content-Type allow-list** (Q2) — rejects missing/unknown Content-Type with HTTP 400 BEFORE any field validation, inheriting Phase 5 SEC-002 lesson.
  2. **Q4 session-token server-binding** — eliminates the server-enumeration vector that a leaked session token would otherwise enable. Matches Phase 5 FR-015 cross-server defense pattern.
  3. **128-bit random `auth_code`** (32 hex chars from `random_bytes(16)`) — brute-force infeasible.
  4. **Short TTL** (5 min for auth_code, 10 min for session_token) — narrow attack window.
  5. **Single-use enforcement** on `/auth/exchange` — both transients deleted on success.
- **Decision**: Accept the exemption with the Q2 + Q4 hardenings. **S8 memory capture queued** for post-implementation: "Body-authenticated mutating REST routes are exempt from S2 when (a) Content-Type strict allow-list rejects missing/unknown headers BEFORE field validation AND (b) downstream credential is bound to the consented resource scope."

### F2. **[Advisory — B10 deferral]** `/auth/exchange` redemption is NOT atomic

- **Where**: `research.md` R5, `plan.md` §Complexity Tracking row 3
- **What**: The `/auth/exchange` redemption sequence is non-atomic: `get_transient` → validation chain → `WP_Application_Passwords::create_new_application_password` → `delete_transient`. Under concurrent requests with the same `code`, both could pass the validation chain before either deletes the transient — both would issue separate App Passwords. Memory B10 mandates atomic single-statement CAS for one-shot credential redemption.
- **Risk**: Two App Passwords issued for the same authorization session. Both scoped to the SAME server (Q4 binding), both belonging to the SAME user. Neither escalates privilege beyond what the admin consented to.
- **Mitigation**: Plan §Complexity Tracking row 3 documents the deferral rationale. The transient API surface does not support atomic CAS without bypassing the WP transient abstraction (going direct to `wp_options` with a `consumed_at` flag would work but adds layering complexity that the threat model doesn't warrant). Q4's server-binding further narrowed the impact.
- **Decision**: Accept the deferral. Revisit in any future hardening pass if WP-Apps grows revocation-on-create semantics or if a `consumed_at` field migration becomes attractive for other reasons.

### F3. **[Advisory]** Audit writes are best-effort — failure is silent to the caller

- **Where**: `data-model.md` E3, FR-014
- **What**: `Recorder::record_approved()` and `Recorder::record_success()` are called inside `try/catch` blocks. Any exception (DB write fail, `CliAuthLogTable` class absent, schema migration in flight) is logged via `error_log()` but does NOT propagate to the CLI caller. Successful auth flows that lose their audit trail look identical to caller-perspective normal success.
- **Risk**: Forensic blind spot — admins inspecting "who consented when?" cannot distinguish "no consent happened" from "consent happened but audit write failed".
- **Mitigation**: `error_log()` writes to `wp-content/debug.log` (when `WP_DEBUG_LOG=true`) for operator-side detection. Future hardening: emit a `do_action('acrossai_mcp_cli_audit_failed', $context)` hook for downstream monitoring.
- **Decision**: Accept for this phase — the spec explicitly chose best-effort audit because blocking the user-visible flow on audit failure would create worse UX. Document the monitoring gap as a known follow-up.

### F4. **[Advisory]** Feature flag `acrossai_mcp_npm_login_enabled` defaults to `false` — no admin UI to toggle

- **Where**: `data-model.md` E5, `contracts/frontend-auth-page.md`
- **What**: New installs MUST run `wp option update acrossai_mcp_npm_login_enabled 1` from WP-CLI to enable the CLI flow. The REST endpoints remain registered regardless of the flag — the kill-switch is purely on the FrontendAuth browser approval page. Admins who hit `/auth/start` without enabling the flag will get a `auth_url` that lands on a 503 disabled-notice page.
- **Risk**: Operational confusion — CLI tools may report "auth_url returned 503" with no clear indication that an admin-side toggle is missing. Documentation gap, not a security risk.
- **Mitigation**: Quickstart.md step 4 documents the WP-CLI toggle. A future Phase 2 RT-N can add an admin checkbox under MCP Manager → Settings.
- **Decision**: Accept. Document in the PR description that operators MUST run the WP-CLI command.

### F5. **[Advisory]** `/health` endpoint discloses plugin version + site_slug to anonymous callers

- **Where**: FR-001, `contracts/health.md`
- **What**: An anonymous caller can learn the plugin version (useful for targeting vulnerability scans against specific versions) and the site_slug (useful for spear-phishing payloads that reference the site by name).
- **Risk**: Minor information disclosure. The plugin version is also discoverable via `Composer.json` if exposed, the plugin readme, or behavior fingerprinting. The site_slug is derived from `get_bloginfo('name')` which is admin-public anyway.
- **Mitigation**: None — the endpoint is intentionally discoverable to support CLI tooling. Site admins concerned about version disclosure can run behind a WAF that strips the response.
- **Decision**: Accept by design. Add a §Privacy note in the public release notes.

---

## Confirmed Secure Patterns

The plan **demonstrates** these patterns by design:

1. **128-bit CSPRNG opaque credentials** — both `auth_code` and `session_token` are `bin2hex(random_bytes(16))` (R1, R8).
2. **`hash_equals` constant-time comparison** on the `server_id` in `/auth/status` (oracle defense per Q4 + FR-003).
3. **Bearer header fallback (HTTP_AUTHORIZATION → REDIRECT_HTTP_AUTHORIZATION)** mirrors Phase 5's BearerHeaderParsingTest pattern (R2).
4. **Q2 Content-Type allow-list** on `POST /auth/start` and `POST /auth/exchange` — rejects missing header + bogus values BEFORE field validation (FR-015 / R9).
5. **Q4 session-token server-binding** — `/servers` returns ONLY the consented server; eliminates cross-server enumeration via leaked token.
6. **Single-use enforcement** — `/auth/exchange` deletes BOTH transients on success (R5).
7. **WP-Apps absence gracefully handled** — HTTP 501 `not_supported` instead of 500; no audit row on this path.
8. **AccessControlManager optional** — `class_exists()` guard; absent → graceful degrade to "consented server returned unfiltered".
9. **No `unserialize()`** anywhere in feature classes (Phase 5 pattern).
10. **No `$wpdb->prepare`-skipping interpolation** — `Recorder` goes through `Query::add_item()`; `/auth/exchange` server lookup goes through `Query::query()`.
11. **`render_page_shell` does NOT call `wp_head()`** — standalone authentication page; themes / page-builders cannot inject markup into the consent flow.
12. **CSRF defense on FrontendAuth approve action** — `check_admin_referer('cli_auth_approve_<code>')` + `current_user_can('manage_options')` recheck (frontend-auth-page.md).
13. **`session_token` deletion ordering** — App Password created BEFORE transient deletion (R5). Failed creation leaves transients live so legitimate CLI can retry; transient self-expiry caps the recovery window.
14. **Raw secrets never persisted** — `auth_code` and `session_token` are transient KEYS (their suffix is part of the lookup key, not stored as a value); raw `app_password` is returned exactly once in the `/auth/exchange` response body and never logged.
15. **`auth_code_hash` in audit rows is SHA-256** of the raw code (FR-014) — forensically correlatable, irreversible.

---

## Action Plan

### Items for this phase's tasks.md (when /speckit-tasks runs)

| Task | Why |
|---|---|
| Add an `assertContentTypeRejected` PHPUnit gate per FR-015 | Q2 hardening made testable |
| Add an `assertSingleServerInResponse` PHPUnit gate per Q4 | Cross-server defense made testable |
| Add the canonical security checklist (15 items from "Confirmed Secure Patterns" above) as a DoD walk-through task in Polish phase | Verify each at impl time |
| Add a `===-near-secrets` constant-time regression grep gate (mirror of Phase 5 T089) | Defense against future regression |
| Add a "search for `add_action`/`add_filter` in `includes/REST/`, `public/Partials/`, `includes/Database/CliAuthLog/Recorder.php`" Loader-contract grep gate | A1 / FR-021 / Q1 enforcement |

### Items NOT to do

- Don't add a custom `permission_callback` to `/auth/exchange` (S2 exemption is body-as-credential; mirrors Phase 5 S7 exactly).
- Don't internationalize the JSON error envelopes (`"error":"invalid_request"` etc. — clients parse them).
- Don't add an HTTPS hard-block on `/auth/exchange` (matches Phase 5 — admin notice only; deliberate "warning-not-block" assumption).
- Don't bind the session token to `server_id` PLUS `user_id` (already bound to `server_id`; user_id is the value; double-binding is redundant).

### Memory captures queued for post-implementation

- **S8**: Body-authenticated mutating REST routes broader than OAuth-token-endpoint S7 — needs Content-Type strict allow-list + downstream credential bound to consented resource scope.
- **A15** (candidate): Database-namespace audit-recorder static helpers follow A11/A14 family — Recorder pattern validated by Phase 6 impl.

### Remediation Planning

**No `/speckit-security-review-followup` needed.** Zero Critical/High findings. The 5 Advisory findings are all by-design or accepted trade-offs documented in spec, plan, or the Q1–Q4 clarifications.

---

## Overall Risk

**LOW.** The CLI authentication flow, as planned post-Q1–Q4, addresses every credible attack vector with cited mitigations. The S2 exemption (F1) is materially mitigated by Q2 + Q4 — both add defense-in-depth beyond what a naive `__return_true` deployment would provide. The B10 deferral (F2) is documented and Q4-narrowed. The remaining advisory items (F3 audit-best-effort, F4 default-off flag, F5 health-info-disclosure) are operational notes, not security weaknesses.

OWASP 2025 categories with no findings: A01-A03, A05-A10. A04 has two informational entries (F1 + F2 — both documented design trade-offs with non-blocking mitigations). A09 has one entry (F3 — best-effort audit). Neither represents a security weakness.

CWE: N/A — no vulnerability CWEs apply at the plan stage.
