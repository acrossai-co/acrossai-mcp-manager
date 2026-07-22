---
document_type: security-review
review_type: plan
assessment_date: 2026-07-21
codebase_analyzed: acrossai-mcp-manager (F032 OAuth Per-Server Scoping — plan v2 verification after SEC-032-001/002/003/005 remediations)
total_files_analyzed: 9
total_findings: 1
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 1
owasp_categories: [A09]
cwe_ids: []
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

# SECURITY REVIEW REPORT — PLAN PHASE v2 (Post-Remediation Verification)

## Executive Summary

**v2 verification confirms all four v1 findings (SEC-032-001 MEDIUM, SEC-032-002 HIGH, SEC-032-003 MEDIUM, SEC-032-005 LOW) are closed.** SEC-032-004 (LOW — timing side-channel) auto-closed as a side effect of SEC-032-001's helper removal. SEC-032-006 (INFORMATIONAL — rollback path documentation gap) remains as documentation-only and is intentionally deferred (marked below).

Overall plan-phase risk drops from **HIGH → INFORMATIONAL** (down 4 severity tiers on one finding). Zero blocking findings remain. Plan is ready for `/speckit-tasks`.

## Verification Method

Reviewed the four remediation edits made 2026-07-21 across:
- `specs/032-oauth-per-server-scoping/spec.md` (FR-005 amended, FR-023 revised to 4-arg, FR-027 + FR-028 added, SC-010/011/012 added, Edge Cases + Security Checklist updated)
- `docs/planings-tasks/032-oauth-per-server-scoping.md` (TASK-1 backfill SQL, TASK-5 handler code, TASK-6 handler code, TASK-8 tests 9/10/11, CONSTRAINTS expanded, Manual Verification updated, Final full-repo audit grep gates added)
- `specs/032-oauth-per-server-scoping/data-model.md` (Query.php deltas — `find_by_client_id_any_server` removed with rationale note, upgrade callback Step 2 backfill SQL updated, validation rules updated)
- `specs/032-oauth-per-server-scoping/contracts/dcr-register.md` (Resource URL Resolution rewritten with 2-step check, 503 response added, DCR-007 + DCR-008 tests added)
- `specs/032-oauth-per-server-scoping/contracts/revoke-client-tokens.md` + `delete-client.md` (do_action signature updated 5→4 args; test cases updated)
- `specs/032-oauth-per-server-scoping/research.md` (R5 revised, R9 + R10 + R11 added, Consolidated Findings Summary updated)
- `specs/032-oauth-per-server-scoping/quickstart.md` (listener example updated, orphan-server verification check added, origin-mismatch listener added)
- `specs/032-oauth-per-server-scoping/plan.md` (Security Boundary Matrix updated with 4 new/revised rows)

## Verification Results

### ✅ SEC-032-001 (was MEDIUM) — CLOSED

**Remediation applied**: Option A (removal of `find_by_client_id_any_server` helper + reduction of `acrossai_mcp_oauth_cross_server_attempted` action from 5 args to 4 args). Verified:
- spec.md FR-023 updated with 4-arg signature + explicit rationale ("MUST NOT include the actual owning `server_id` — leaking that to any listener recreates a cross-server oracle")
- planning-doc TASK-5 code snippet updated (`find_by_client_id_any_server` call removed; 4-arg `do_action` fire)
- planning-doc CONSTRAINTS added explicit "Do NOT add `find_by_client_id_any_server` helper" rule + grep gate
- planning-doc Final full-repo audit added `grep -rn 'find_by_client_id_any_server'` MUST-be-zero gate
- planning-doc Final full-repo audit added `grep 'do_action.*acrossai_mcp_oauth_cross_server_attempted'` MUST-be-4-args gate
- data-model.md Query.php deltas table removes the helper row + adds explanatory note
- data-model.md validation rule #3 updated to reference 4-arg signature
- contracts/revoke-client-tokens.md action signature updated + test descriptions updated
- contracts/delete-client.md action signature reference updated
- research.md R5 rewritten with rationale for the 4-arg decision + explicit rejection of "5-arg with boolean" alternative
- quickstart.md listener example updated to 4-arg `add_action( ..., 10, 4 )` with self-resolution comment
- plan.md Security Boundary Matrix Observability action row explicitly notes "4-arg signature (owning server_id NOT included, per SEC-032-001)"

Verification: no residual references to `find_by_client_id_any_server` in any spec-track artifact. Every reference to the observability action signature is 4-arg throughout.

### ✅ SEC-032-002 (was HIGH) — CLOSED

**Remediation applied**: FR-027 added specifying two-step check (origin verification + path resolution) with fail-closed semantics. Verified:
- spec.md FR-027 added with explicit MUST language for origin (scheme + host + port) match against `home_url()`
- spec.md SC-010 added with concrete PHPUnit test procedure (DCR-007)
- spec.md Security Checklist item added
- planning-doc TASK-6 `resolve_server_id_from_resource_url` code snippet now includes explicit Step 1 origin check with `wp_parse_url` comparison + observability fire on mismatch
- planning-doc CONSTRAINTS added explicit "Do verify DCR resource URL origin" rule
- planning-doc Manual Verification TASK-6 added attacker-origin test bullet
- planning-doc Final full-repo audit added `grep -rEn 'resolve_server_id_from_resource_url'` gate requiring BOTH `wp_parse_url` + `home_url()` comparison in the definition
- planning-doc TASK-8 test 9 added (`test_dcr_rejects_attacker_origin_url`)
- data-model.md validation rule #2 amended to cover both FR-014 + FR-027
- contracts/dcr-register.md Resource URL Resolution section rewritten with explicit two-step code snippet + attack surface documentation
- contracts/dcr-register.md test case DCR-007 added
- research.md R9 added with rationale + alternatives
- quickstart.md observability listener example for `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` added
- plan.md Security Boundary Matrix row split into two: origin-check (Step 1) + path-resolution (Step 2)

Verification: URL origin verification is now a first-class MUST at every artifact level (FR + SC + Security Checklist + task code snippet + constraint + verification bullet + audit grep + test case + research decision + operator doc + plan matrix).

### ✅ SEC-032-003 (was MEDIUM) — CLOSED

**Remediation applied**: FR-005 amended with orphan-server guard on backfill UPDATE. Verified:
- spec.md FR-005 amended with explicit `IN (SELECT id FROM wp_acrossai_mcp_servers)` clause requirement
- spec.md SC-011 added with concrete `SELECT COUNT WHERE server_id NOT IN (SELECT id FROM oauth_servers)` verification query
- spec.md Security Checklist item added
- data-model.md upgrade callback ordering Step 2 shows the guarded SQL with `AND CAST(...) IN (SELECT id FROM {$wpdb->prefix}acrossai_mcp_servers)`
- data-model.md validation rule #6 added for post-upgrade invariant
- planning-doc TASK-1 upgrade callback Step 2 SQL updated with the IN-clause guard + comment
- planning-doc CONSTRAINTS added explicit "Do guard backfill UPDATE against phantom-server-id assignments" rule
- planning-doc Manual Verification TASK-1 checklist added `SELECT COUNT WHERE server_id NOT IN ...` bullet
- planning-doc TASK-8 test 10 added (`test_backfill_skips_orphan_server_ids`)
- research.md R10 added with rationale + rejected alternatives
- quickstart.md added post-migration orphan-count check (step 4b)
- plan.md Security Boundary Matrix Backfill UPDATE row added with orphan-guard callout

Verification: the invariant "no oauth_clients row has server_id pointing at a non-existent server" is enforced by SQL-level guard on backfill + PURGE step + verified by PHPUnit test + operator-facing SELECT + audit grep.

### ✅ SEC-032-004 (was LOW) — AUTO-CLOSED via SEC-032-001

**Rationale**: With `find_by_client_id_any_server` helper removed and the do_action signature reduced to 4 args (no `$server_id_actual` populated from an extra lookup), the 403 code path performs exactly one DB lookup (`find_by_client_id_and_server_id`) regardless of whether the client exists on another server. No timing differential remains between "client doesn't exist" and "client exists on other server" — both paths execute the same single lookup + same 4-arg fire.

Verification: research.md R5 explicitly notes the auto-close; no separate remediation required.

### ✅ SEC-032-005 (was LOW) — CLOSED

**Remediation applied**: FR-028 added specifying 503 response when `server_id` column absent. Verified:
- spec.md FR-028 added with explicit MUST language for `INFORMATION_SCHEMA` check + 503 `service_unavailable` response
- spec.md SC-012 added with concrete PHPUnit test procedure (DCR-008)
- spec.md Security Checklist item added
- spec.md Edge Case "Race condition — DCR request during upgrade" rewritten to reflect the 503 gate resolution
- planning-doc TASK-6 `handle_register` code snippet includes FR-028 check at top + `oauth_clients_server_id_column_exists()` helper implementation with per-request cache
- planning-doc CONSTRAINTS added explicit "Do reject DCR requests with 503" rule
- planning-doc Manual Verification TASK-6 added pre-migration race test bullet
- planning-doc Final full-repo audit added `grep -rn "'service_unavailable'|status.*503"` gate
- planning-doc TASK-8 test 11 added (`test_dcr_returns_503_when_column_absent`)
- data-model.md validation rule #5 added
- contracts/dcr-register.md 503 response section added + DCR-008 test case added
- research.md R11 added with rationale + rejected alternatives
- plan.md Security Boundary Matrix DCR pre-migration race guard row added

Verification: 503 gate is now specified at every artifact level. Prevents silent destruction of race-window registrations.

### ⚠️ SEC-032-006 (was INFORMATIONAL) — INTENTIONALLY DEFERRED

**Status**: NOT remediated. Not a blocker; documentation-only concern about rollback path leaving composite UNIQUE constraint. The quickstart.md §Rollback section already documents the manual `ALTER TABLE ... ADD UNIQUE KEY client_id (client_id), DROP INDEX client_id_server_id;` recovery SQL with the warning that it fails when the "same DCR client on two servers" scenario has produced duplicate `client_id` values.

**Justification for deferral**: (a) rollback is a manual operator operation, not a plugin-code path; (b) the failure mode (composer downgrade + rollback recovery SQL fails with duplicate-key error) surfaces IMMEDIATELY as a MySQL error on the operator's console — not silent, not exploitable; (c) F032 ships unconditionally per Q2, so rollback is a rare operational fallback, not an expected flow; (d) recording this in quickstart.md is the correct disposition for a documentation gap. May be revisited if F033 introduces a first-party rollback CLI command, but out of scope for F032.

### ✅ New finding (v2 pass) — SEC-032-007 (INFORMATIONAL)

**Location**: `contracts/dcr-register.md` §503 Service Unavailable; `planning-doc TASK-6` `handle_register` code snippet
**OWASP Category**: A09:2025-Security Logging and Monitoring Failures
**CWE**: N/A
**CVSS Score**: 0 (INFORMATIONAL)
**Description**: The 503 response added per FR-028 does not include an HTTP `Retry-After` header value. The response body message ("Server initialization in progress; please retry in a few seconds") implies retry semantics but leaves the timing choice to the client. RFC 7231 §7.1.3 defines `Retry-After` as the canonical mechanism for this signal; compliant HTTP clients (including well-behaved AI hosts) may implement exponential backoff based on this header. Without it, clients retry at their own cadence — some may retry too aggressively (spamming 503s), some too slowly (user-visible delay).

**Remediation**: Add `WP_REST_Response` header `Retry-After: 5` (5 seconds — safe upper bound on migration duration for typical installs) to the 503 response. Update `contracts/dcr-register.md` §503 to spell out the header. Update TASK-6 code snippet to include the header.

**Priority**: Very low. Deferrable to a follow-up nit-fix; not a blocker for `/speckit-tasks`. Recording here for completeness so it can be picked up if convenient.

**Spec-Kit Task**: TASK-SEC-032-007 (optional)

## Trust Boundary Analysis (Updated)

| Boundary | v1 Status | v2 Status |
|---|---|---|
| REST body → REST handler | ✅ Honored | ✅ Honored (unchanged) |
| REST handler → BerlinDB Query | ✅ Strengthened | ✅ Strengthened (unchanged) |
| RFC 8707 `resource` URL → Server ID | ⚠️ SEC-032-002 gap | ✅ **CLOSED** — two-step check (origin + path) with fail-closed |
| Upgrade callback → OAuth data | ⚠️ SEC-032-003 gap | ✅ **CLOSED** — orphan-server guard on backfill |
| do_action fire → any listener | ⚠️ SEC-032-001 gap | ✅ **CLOSED** — 4-arg signature, no owning-server disclosure |
| DCR endpoint → OAuth clients | ⚠️ SEC-032-005 gap | ✅ **CLOSED** — 503 gate on missing column |
| Rollback → OAuth tables | ℹ️ SEC-032-006 note | ℹ️ Documentation-only, intentionally deferred |

## Confirmed Secure Patterns (unchanged from v1)

All 15 secure patterns cataloged in v1 remain preserved. Additional patterns confirmed in v2:
- **Fail-closed on security check** (FR-027 origin verification): return 0 → 400 `invalid_target`, no INSERT attempted.
- **Fail-closed on race guard** (FR-028): return 503, no INSERT attempted.
- **Idempotency preservation** (SEC-032-003 remediation): the IN-clause guard preserves the D28 idempotency contract — repeated runs of the backfill UPDATE are no-ops for correctly-assigned rows and remain no-ops for rows already NULL after the initial run.
- **Observability signal without oracle disclosure** (SEC-032-001 remediation): the 4-arg action provides enough forensic detail (client_id + requested_server + user_id + timestamp) for operators to detect a cross-server bypass attempt WITHOUT revealing to hostile listeners which server the client actually belongs to.

## Action Plan & Next Steps

### Remediation Status (v2)

| Priority | v1 Findings | v2 Status |
|---|---|---|
| **Was must-fix** | SEC-032-002 (HIGH), SEC-032-003 (MEDIUM) | ✅ Both closed |
| **Was recommended** | SEC-032-001 (MEDIUM), SEC-032-005 (LOW) | ✅ Both closed |
| **Was auto-closed** | SEC-032-004 (LOW) | ✅ Closed via SEC-032-001 remediation |
| **Documentation-only** | SEC-032-006 (INFO) | ℹ️ Intentionally deferred (justified above) |
| **New (v2)** | SEC-032-007 (INFO) | ℹ️ Optional; can defer to a nit-fix pass |

### Plan-phase gate: **PASS**

Zero blocking findings. Overall risk **INFORMATIONAL**. Plan is ready for `/speckit-tasks`.

### Extension Hooks

**Recommended next**: `/speckit-tasks` (generate `tasks.md` breakdown). If operator wants the SEC-032-007 Retry-After header before task generation, apply as a 3-line edit first.

### Durable Memory Preservation (Mandatory Check)

**One repeatable security pattern surfaced during v2 verification**: the "observability action arg set MUST NOT include values that reconstruct the invariant the response body is protecting" principle (SEC-032-001 resolution). This generalizes beyond F032 to any future security-fix feature that adds a `do_action` on the failure path. Candidate durable-memory entry (post-implement, via `/speckit-memory-md-capture-from-diff`):

> **B-OBSERVABILITY-ARG-SET-CAN-LEAK-INVARIANT** (Active — Feature 032; generalizable)
> Any `do_action` fired on a security-fail path MUST NOT include arg values that would let a listener reconstruct the invariant the response body is protecting. F032's original 5-arg `acrossai_mcp_oauth_cross_server_attempted` included `$server_id_actual` — any WordPress plugin could hook the action and get the cross-server binding oracle the response body protects. 4-arg redesign closes the leak. Grep gate for future features: any `do_action` fired inside a WP_Error 4xx return path SHOULD be reviewed for arg-set disclosure. Companion to D19 (the fail-open observability pattern itself) — this is the "what NOT to put in the args" corollary.

Given this pattern has ONE occurrence so far (F032), it does NOT yet meet the D13 escalation threshold (≥2 features or forward-looking generalizable). Deferring proactive capture to the post-implement `/speckit-memory-md-capture-from-diff` step, where it can be evaluated alongside F032's confirmed durable lessons in a single memory hygiene pass. Not triggering `/speckit-memory-md-capture` this turn.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan-v2.md | plan | 2026-07-21 | INFORMATIONAL | C:0 H:0 M:0 L:0 I:1 | A09 |
```

## Reconciliation Note

The v1 report at `docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan.md` remains valid as the historical record of the pre-remediation state. Its findings SEC-032-001 through SEC-032-005 should be marked **CLOSED (v2 2026-07-21)** in any downstream tracking; SEC-032-006 remains **DEFERRED (documentation-only)**. Both v1 and v2 documents ship together in the security-reviews directory as the audit trail (mirrors F025 plan-review v1 + v2 pattern).
