---
document_type: security-review
review_type: tasks
assessment_date: 2026-07-10
codebase_analyzed: acrossai-mcp-manager (Feature 021 — oauth-2-1-implementation tasks.md)
total_files_analyzed: 9
total_findings: 6
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 3
low_count: 3
informational_count: 0
owasp_categories: [A03, A05, A07, A09]
cwe_ids: [CWE-79, CWE-345, CWE-778, CWE-1188]
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

# Security Review — Feature 021: OAuth 2.1 + PKCE Authorization Server (Tasks)

**Reviewer**: `/speckit-security-review-tasks` (via governed-tasks orchestration)
**Task list hash**: `tasks.md` @ 2026-07-10

## Executive Summary

The `tasks.md` file for Feature 021 has strong security coverage. Every SEC-021-* finding from the plan-phase review is bound to at least one implementation or documentation task. Baseline security invariants — PKCE S256 (T026/T027), atomic single-use auth codes (T024), refresh-token rotation with SEC-021-001 family revocation (T014/T017/T076/T085), rate limiting (T028/T029/T093), hashed-at-rest storage (T025 + T119/T120 grep verification), user-deletion cascade (T064/T068/T069), audience-binding (T062/T066) — all have both implementation AND test tasks.

Six task-level concerns remain: three MEDIUM findings (one sequencing hazard, two missing sanitization/validation tests) and three LOW findings (negative-space assertions and observability gaps). None are blocking on their own. The sequencing finding (SEC-021-T01) is the most urgent since it creates a fatal-error window between Phase 2 and Phase 7 activation if operators run intermediate `activate()` between checkpoints.

**Overall risk**: MODERATE — no HIGH/CRITICAL gaps; MEDIUM findings are all missing tests or task re-ordering, not missing safety controls.

## Tasks Reviewed

| Path | Purpose |
|---|---|
| `specs/021-oauth-2-1-implementation/tasks.md` | ~104 implementation tasks across 8 phases |
| `specs/021-oauth-2-1-implementation/spec.md` | FRs referenced by task descriptions |
| `specs/021-oauth-2-1-implementation/plan.md` | Architectural constraints per task |
| `specs/021-oauth-2-1-implementation/data-model.md` | Column-width invariants + bespoke Query methods |
| `specs/021-oauth-2-1-implementation/contracts/rest-api.md` | Endpoint contracts referenced by test tasks |
| `specs/021-oauth-2-1-implementation/contracts/php-hooks.md` | Action/filter contracts referenced by observability tasks |
| `specs/021-oauth-2-1-implementation/contracts/connector-profile.md` | AbstractConnectorProfile contract |
| `specs/021-oauth-2-1-implementation/memory-synthesis.md` | Baseline security constraints B10, B24, DEC-* set |
| `docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-plan.md` | Plan-phase review (source of SEC-021-001…009) |

---

## Vulnerability Findings (Task-Level)

### SEC-021-T01 — Cron scheduled before its handler class exists (Phase 2 vs Phase 7 sequencing)

- **finding_id**: SEC-021-T01
- **location**: `specs/021-oauth-2-1-implementation/tasks.md:T041` (schedules cron) vs `T106` / `T107` (Cleanup class + wire, in Phase 7)
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-1188: Initialization of a Resource with an Insecure Default
- **cvss_score**: 4.4 (MEDIUM — vector: `AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:L`)
- **spec_kit_task**: TASK-SEC-021-T01

**Description**

T041 (Phase 2 Foundational) schedules the daily cron `acrossai_mcp_manager_oauth_cleanup` at activation via `wp_schedule_event`. However, `T106` creates the `Cleanup::run()` handler and `T107` wires it as the callback for that action — both in Phase 7 (US5, priority P3).

**Failure mode**: An operator who activates the plugin between Phase 2 checkpoint and Phase 7 completion will have a scheduled cron with no registered handler. When WP-Cron fires, `do_action( 'acrossai_mcp_manager_oauth_cleanup' )` executes with zero callbacks — WordPress silently succeeds but no cleanup happens. Not a security *breach*, but an availability/hygiene defect: expired auth codes + expired-and-revoked tokens accumulate forever until Phase 7 is deployed. If T106 has a bug that fatally errors, the missed-callback case actually masks it.

More subtly: partial deploys (dev branch, staging, feature flag rollout) will produce an inconsistent runtime — the cron *appears* to be running per `wp_next_scheduled()` but actually does nothing.

**Recommendation**

Reorder the tasks so the Cleanup class ships with the cron schedule. Move `T106` (create `Cleanup.php`) + `T107` (wire cron action) from Phase 7 into Phase 2 (as new tasks T044 + T045). Keep the uninstall-related tasks (T105, T100, T101) in Phase 7 — those genuinely depend on US5's opt-in gate + the tables to be dropped.

**Alternative**: Guard the schedule call in T041 with `if ( class_exists( '\AcrossAI_MCP_Manager\Includes\OAuth\Cleanup' ) )` — but this adds implicit ordering coupling that the current plan doesn't document.

**Not blocking** in the pure security sense — no vulnerability. But this is a straightforward task-ordering fix worth doing before implement.

---

### SEC-021-T02 — Missing test that `setup_instructions_html` is sanitized against XSS

- **finding_id**: SEC-021-T02
- **location**: `tasks.md:T056` (returns raw HTML), `T058` (innerHTML injection client-side)
- **owasp_category**: A03:2025-Injection
- **cwe**: CWE-79: Improper Neutralization of Input During Web Page Generation ('Cross-site Scripting')
- **cvss_score**: 5.4 (MEDIUM — vector: `AV:N/AC:L/PR:H/UI:R/S:C/C:L/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-021-T02

**Description**

T056 says the admin generate-client controller returns `setup_instructions_html` (from `$profile->get_setup_instructions()`). T058 says the vanilla JS handler injects that HTML into the DOM. The task text notes "server-escaped via `wp_kses_post` on the PHP side before returning" — but no test task verifies this. A companion plugin author who forgets escaping in their profile's `get_setup_instructions()` implementation could inject `<script>` tags that would fire on every subsequent admin visit to the AI Connectors tab (stored XSS, admin-context — worst class).

Trust boundary: the profile is contributed by a third-party companion plugin. Third-party PHP code is *not* untrusted the way third-party HTTP input is (they run in-process, with full plugin capabilities), but `wp_kses_post` is nonetheless the standard belt-and-suspenders for admin-rendered content.

**Recommendation**

Add task **T058b [P] [US1]**: `tests/phpunit/OAuth/AdminGenerateClientHtmlSanitizationTest.php` — register a stub connector profile whose `get_setup_instructions()` returns `<script>alert(1)</script><pre>ok</pre>`; POST to generate-client; assert response body's `setup_instructions_html` field passes through `wp_kses_post` (the `<script>` is stripped, `<pre>` survives).

Also add an explicit line to T056: "MUST pass `$profile->get_setup_instructions()` output through `wp_kses_post` before assembling the response."

**Not blocking** — the task text hints at this. Making the guarantee testable prevents a Phase 3 developer from silently dropping the sanitization.

---

### SEC-021-T03 — Missing dedicated test for RFC 8707 `resource`-on-this-site validation

- **finding_id**: SEC-021-T03
- **location**: `tasks.md:T071` (AuthorizeGetTest) mentions "missing `resource` → `invalid_target`" but not "resource on a different site → `invalid_target`"
- **owasp_category**: A07:2025-Identification and Authentication Failures
- **cwe**: CWE-345: Insufficient Verification of Data Authenticity
- **cvss_score**: 5.0 (MEDIUM — vector: `AV:N/AC:H/PR:N/UI:R/S:C/C:L/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-021-T03

**Description**

FR-006 requires the `/authorize` endpoint to reject a `resource` param that names a URL *not on this WordPress site*. The Edge Cases section calls this out explicitly: "**`resource` not on this site**: Same treatment — `invalid_target`. Prevents confused-deputy attacks where a client tries to bind a token to a resource on a different site."

T082's implementation description says "validate `resource` (URL on this site or loopback)". But `T071 (AuthorizeGetTest)` only lists these cases: PKCE method != S256, missing `resource`, unknown `client_id`, redirect_uri byte mismatch, not-logged-in, all-valid. There is no explicit case for **`resource` on a DIFFERENT site**. This is the load-bearing case for confused-deputy defense.

If T082's implementation naively checks only presence + URL-parses cleanly (skipping the "on this site" check), the security review of the branch might miss it because the plan-phase review already lists RFC 8707 audience-binding at call time (T062) — but Q1's clarification made call-time enforcement the primary defense, ISSUE-time still needs the same check so the wrong-audience token never gets issued in the first place.

**Recommendation**

Add task **T071b [P] [US4]**: `tests/phpunit/OAuth/AuthorizeResourceCrossSiteTest.php` — GET `/authorize` with `resource=https://different-site.example.com/wp-json/mcp/v1/server-A`; assert redirect with `error=invalid_target&iss=<this-site-issuer>`. Also assert loopback resources still work (`resource=http://127.0.0.1:33333/mcp`) with the current site's `home_url()` OR loopback semantics.

**Not blocking**. Symmetric to T062 (call-time audience test) but at authorization time — closes the confused-deputy defense earlier in the flow.

---

### SEC-021-T04 — Missing negative test that no consent-memoization surfaces exist

- **finding_id**: SEC-021-T04
- **location**: `tasks.md:T072` and elsewhere — verifies consent renders on Approve/Deny, but no test asserts consent renders EVERY time
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-1188: Initialization of a Resource with an Insecure Default
- **cvss_score**: 3.1 (LOW — vector: `AV:N/AC:H/PR:H/UI:R/S:U/C:L/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-021-T04

**Description**

Q3 clarification is explicit: "**Always show consent.** Every `/authorize` request renders the consent screen — no memoization of prior approvals, no `approved_at` timestamp column, no `OAuthConsents` companion table." The tests verify the Approve/Deny mechanics. But no test verifies the *negative* — that a developer 6 months from now hasn't quietly added `if ( $this->has_consented_before( $user_id, $client_id ) ) skip_consent();` to boost UX. Q3 was a security choice; drift toward memoization is easy to miss in code review.

**Recommendation**

Add task **T072b [P] [US4]**: `tests/phpunit/OAuth/ConsentAlwaysRendersTest.php` — call `/authorize` twice with the SAME `(user_id, client_id)` combination; assert BOTH calls render the consent template (HTTP 200 HTML), neither issues an implicit auth code. Also: reflect on the OAuth database schema to assert no `OAuthConsents` table exists AND `OAuthClients` has no `approved_at`-like column.

**Not blocking** — invariant is currently correctly implemented per T082; test guards against drift.

---

### SEC-021-T05 — Missing test for SC-011 "zero overhead on non-OAuth pages"

- **finding_id**: SEC-021-T05
- **location**: `tasks.md:T060`–`T064` (TokenValidator tests) — no explicit "no header → short-circuit" test
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-1188: Initialization of a Resource with an Insecure Default
- **cvss_score**: 2.7 (LOW — vector: `AV:L/AC:H/PR:L/UI:N/S:U/C:N/I:N/A:L`)
- **spec_kit_task**: TASK-SEC-021-T05

**Description**

SC-011 is a Success Criterion: "With no companion plugins installed and no OAuth flows exercised, the plugin's activation adds three tables + one cron and adds zero to per-request PHP execution time on non-OAuth pages (measured by `Server-Timing` header or `debug.log` timestamps)."

T065 mentions "return early if `$user_id` is already a positive int (short-circuit non-OAuth pages per SC-011)" and "if `read_authorization_header()` returns null, return `$user_id` unchanged before any DB call." But no test enforces the DB isn't hit when the header is absent. A future refactor that moves the header-read behind a per-request cache primer would silently violate SC-011 — no test would fail.

**Recommendation**

Add task **T063b [P] [US3]**: `tests/phpunit/OAuth/TokenValidatorNoHeaderShortCircuitTest.php` — mock the DB layer (via a spy on `wpdb::get_row` or the `Query::instance` reflection); call `TokenValidator::authenticate( 42 )` in a request context with NO `Authorization` header; assert the DB spy was NOT invoked AND the return value is `42` unchanged.

**Not blocking** — performance invariant, low security impact.

---

### SEC-021-T06 — No runtime observability test that raw secrets never leak into logs / error bodies

- **finding_id**: SEC-021-T06
- **location**: `tasks.md:T120` (grep task, static-only)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 3.1 (LOW — vector: `AV:L/AC:H/PR:H/UI:N/S:U/C:L/I:N/A:N`)
- **spec_kit_task**: TASK-SEC-021-T06

**Description**

T120 does a grep-based static check: `grep -rEn '(random_bytes|random_token)\s*\(' includes/OAuth/` and asks reviewers to eyeball whether the output ever reaches logs/transients/options. This catches direct leaks but misses indirect ones:
- A raw token concatenated into an exception message that later gets logged by WordPress's default error handler.
- A raw secret included in a `wp_debug_log` call inside a not-yet-triggered error branch.
- A raw secret in a `$this->debug_state` array that gets serialized to a transient by a third-party observability plugin listening on `token_issued`.

**Recommendation**

Add task **T120b**: `tests/phpunit/OAuth/RawSecretsNeverLeakTest.php` — issue an access token via `TokenController::handle_authorization_code`; then trigger `handle_authorization_code` with a MALFORMED body; capture: (a) the response body, (b) any `error_log` output (via `set_error_handler` capture), (c) any transient set during the request. Assert the raw token string (captured post-issue) NEVER appears in any of the three capture surfaces.

Also add: assert that on a 500 error path (force a `\Throwable` by mocking a dependency to throw), the response body's `error_description` field does NOT contain the exception's `getMessage()` output verbatim — controllers must catch + rewrite to generic messages (mirrors F020's SEC-020-010 pattern).

**Not blocking** — grep task already exists.

---

## Confirmed Secure Patterns in Tasks

- **SEC-021-001 refresh-token family revocation** is fully task-instrumented: T014 (schema), T017 (Query), T036 (Repository), T076 (test), T085 (TokenController impl). No implementation-path bypass possible without visibly skipping a task.
- **PKCE S256 mandatory** — T027 uses RFC 7636 Appendix B canonical vectors; T077 explicitly tests wrong verifier → `invalid_grant`.
- **Atomic auth code single-use** — T024 tests the CAS pattern directly (mirrors B10 canonical); T074 tests it at the controller layer under concurrent POST.
- **RFC 8707 audience-binding at call time** — T062 explicit test; T066 implementation with byte-exact `resource` comparison.
- **Nonce enforcement** — T072 (consent POST → 403 on missing nonce); T051 (admin generate-client → 403 on missing nonce). Every mutating endpoint has a test.
- **Rate limiting with 429 shape + `Retry-After`** — T029 (RateLimiter unit); T093 (DCR limit); acceptance criteria in spec.md drives T097.
- **Hashed at rest, `hash_equals` at compare** — T025 (SecretsVault only path); T119 (column-width grep); T120 (raw-secret static grep). Runtime test added by SEC-021-T06.
- **User-deletion cascade (Q4)** — T064 test asserts every revoked token fires observability action with reason `'user_deleted'`.
- **DCR fingerprint dedup** — T091 asserts identical body → same `client_id`, zero new rows, zero `token_issued` fires. Prevents client-fingerprint drift + observability noise.
- **Strict redirect URI scheme validation (SEC-021-004)** — T092 explicitly tests `javascript:` and `data:` rejection; T096 implements the strict scheme helper.
- **Phantom-version guard preservation** — T022 tests all three tables inherit F011's SEC-011-002 fix.
- **Column-width invariants (FR-040)** — T023 reflects on Schema to assert `char(64)` and `char(43)` widths. Any narrowing in a future migration will fail this test.
- **DCR permission via rate-limiter permission_callback** — T097 explicitly notes `WP_Error`/`true` return; NO `__return_true` on mutating routes.
- **Admin generate-client capability check** — T057 requires `manage_options` + `wp_verify_nonce`.
- **Ordered dependency graph** — Foundational blocks all US phases; SEC-021-001 family_id column shipped in T014 before any TokenController test (T076) or impl (T085) can be written.
- **Task-story traceability** — every US-phase task carries `[USn]` label; grep-derivable coverage matrix.

---

## Action Plan & Next Steps

### Blocking before `/speckit-implement`

None. All findings are MEDIUM or LOW.

### Recommended before `/speckit-implement`

1. **SEC-021-T01** — Reorder T106/T107 into Phase 2 (as T044/T045) to close the cron-no-callback window.
2. **SEC-021-T02** — Add T058b test + tighten T056 wording.
3. **SEC-021-T03** — Add T071b test for cross-site `resource` rejection.

### Recommended during `/speckit-implement`

4. **SEC-021-T04** — Add T072b consent-always-renders test.
5. **SEC-021-T05** — Add T063b no-header-short-circuit test.
6. **SEC-021-T06** — Add T120b raw-secrets-never-leak runtime test.

### Durable Memory Preservation

**Deferred**. No new systemic security patterns identified. The task-sequencing rule "cron handler class MUST ship in the same phase that schedules the cron" is a candidate for `DEC-CRON-HANDLER-SAME-PHASE-AS-SCHEDULE` post-implement, once we confirm the reorder holds through actual delivery.

### Remediation Planning

Run `/speckit-security-review-followup` after either: (a) all six T-findings are added inline into `tasks.md`, or (b) the user opts to leave them for `/speckit-analyze` to sweep pre-merge.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-tasks.md | tasks | 2026-07-10 | MODERATE | C:0 H:0 M:3 L:3 | A03,A05,A07,A09 |
```
