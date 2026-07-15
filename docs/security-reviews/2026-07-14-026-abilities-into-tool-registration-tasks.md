---
document_type: security-review
review_type: tasks
assessment_date: 2026-07-14
codebase_analyzed: acrossai-mcp-manager (Feature 026 tasks.md)
total_files_analyzed: 9
total_findings: 2
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 2
owasp_categories: [A04]
cwe_ids: [CWE-754]
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

# Security Review — Feature 026 Task List

## Executive Summary

**Feature**: 026-abilities-into-tool-registration
**Task artifact under review**: `specs/026-abilities-into-tool-registration/tasks.md` (15 tasks across 6 phases)
**Plan reviews consulted**: v1 (3 INFO) + v2 (2 INFO)
**Overall risk**: **LOW** — 0 Critical / 0 High / 0 Moderate / 0 Low / 2 Informational.

All five prior plan-review findings have explicit task remediations in `tasks.md` §"Security-review remediation folded into tasks". Coverage matrix is present and complete (per `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX` durable pattern, captured 2026-07-14).

F026 has no subtractive edits to security-sensitive methods (all Controller changes are line-swaps replacing one composer method with another; docblock updates are comment-only). `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT` therefore does not apply to any F026 task — no PRESERVATION invariant sentence required.

F026 introduces no new REST routes, no new user input surfaces, no new secrets, no new writes to any storage layer, and no vendor edits. Constitution §III checkpoints are satisfied vacuously — nothing to modify at those boundaries.

Two INFORMATIONAL findings surface: (1) the fail-open branch has no PHPUnit test, only quickstart manual coverage (per SC-006); (2) the spec's SEC-026-v2-1 amendment (T010) should mirror to `data-model.md` for artifact consistency. Neither is blocking.

## Tasks Reviewed

All 15 tasks in `specs/026-abilities-into-tool-registration/tasks.md` traced against the five prior plan-review findings and Constitution §III checkpoints:

- **Phase 1 (Setup)**: T001 — branch state only, no security surface.
- **Phase 2 (Foundational)**: T002 — new `ToolPolicy::compose_effective_tools_for_row()` method; no auth/writes/secrets surface.
- **Phase 3 (US1)**: T003–T006 — two Controller call-site swaps (T003, T004), two test extensions (T005, T006).
- **Phase 4 (US2)**: T007 (Controller filter docblock update), T008 (`docs/extending-server-tools.md` update with all three v1 INFO findings + one v2 INFO finding folded in).
- **Phase 5 (US3)**: T009 — grep-only verification that `ToolsController::get_tools()` still calls `compose_for_row()`.
- **Phase 6 (Polish)**: T010–T015 — spec amendment (SEC-026-v2-1), quality gates, grep audits, quickstart walkthrough.

## Coverage matrix (plan-review findings → tasks)

| Finding | Task | Present? |
|---|---|---|
| SEC-026-INFO-1 (v1 confused-deputy surface widens) | T008 (`docs/extending-server-tools.md` cross-reference to SEC-025-INFO-1) | ✓ Documentation |
| SEC-026-INFO-2 (v1 `_reset_cache_for_tests` B23 test-suffix pattern) | Deferred to future F017 maintenance PR — noted in tasks.md §Notes | ✓ Deferral logged |
| SEC-026-INFO-3 (v1 `mcp.public = true` implicit opt-in) | T008 (`docs/extending-server-tools.md` opt-in note) | ✓ Documentation |
| SEC-026-v2-1 (v2 empty-set fallback semantic shift on default server) | T010 (spec §Edge Cases amendment) | ✓ Spec edit |
| SEC-026-v2-2 (v2 ability-registration timing constraint) | T008 (`docs/extending-server-tools.md` timing note) | ✓ Documentation |

All five findings represented. Two additional task-hygiene observations follow.

## Vulnerability Findings

### SEC-TASKS-026-1 — Fail-open branch has no PHPUnit test; covered by quickstart only

| Field | Value |
|---|---|
| **Finding ID** | SEC-TASKS-026-1 |
| **Location** | `specs/026-abilities-into-tool-registration/tasks.md` §Phase 3 T005 (test case list); `specs/026-abilities-into-tool-registration/spec.md` §SC-006 (quickstart-only coverage) |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (test-coverage gap; not a runtime vulnerability) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design (test-completeness lens) |
| **CWE** | CWE-754 — Improper Check for Unusual or Exceptional Conditions |
| **Spec-Kit task** | TASK-SEC-TASKS-026-1 |

**Observation**: The new `ToolPolicy::compose_effective_tools_for_row()` method contains a fail-open branch (per FR-003):

```php
if ( ! function_exists( 'wp_get_abilities' ) ) {
    return $tools; // Fail-open — Abilities API not bootstrapped.
}
```

T005's four PHPUnit cases (`test_compose_effective_*`) cover the branch that IS taken (Abilities API present) but do not exercise the `! function_exists()` branch directly. PHPUnit cannot easily shadow `function_exists()` without `runkit` or similar extension (not typically in WordPress test harness).

SC-006 explicitly acknowledges this: *"Verified by deactivating the Abilities API polyfill (if present) or running under a WP version predating the Abilities API and confirming server registration still succeeds with protocol + curated only."* The fail-open path is verified by the quickstart's manual "Fail-open (SC-006)" section, not by PHPUnit.

**Why this is not a real vulnerability**:
- The branch is one line: `if ( ! function_exists( 'wp_get_abilities' ) ) { return $tools; }` — obvious behavior, low regression surface.
- SC-006 documents the quickstart-only coverage decision.
- WordPress 6.9+ (the plugin's minimum) ships the Abilities API — the branch is defensive coverage for edge cases (unloaded API, prerelease builds).
- If the branch ever accidentally becomes unreachable (e.g., someone removes the `!` and inverts the check), integration tests + quickstart would surface it.

**Why v3 flags it**:
- Task-review hygiene: explicit acknowledgment of test-strategy gaps helps future maintainers know what's covered by unit vs. manual verification.

**Recommendation** (non-blocking):

Add a code-comment marker at the fail-open branch in T002's method body to make the coverage explicit:

```php
// SC-006: fail-open path verified by quickstart (§"Fail-open") — not unit-tested
// because PHPUnit cannot shadow function_exists() without runkit.
if ( ! function_exists( 'wp_get_abilities' ) ) {
    return $tools;
}
```

Apply during T002 authoring. One line of comment; no logic change.

**Blocking?** No.

---

### SEC-TASKS-026-2 — SEC-026-v2-1 spec amendment (T010) should mirror to `data-model.md`

| Field | Value |
|---|---|
| **Finding ID** | SEC-TASKS-026-2 |
| **Location** | `specs/026-abilities-into-tool-registration/tasks.md` §Phase 6 T010; `specs/026-abilities-into-tool-registration/data-model.md` §"Order-of-operations at server registration time" |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (artifact-drift concern; not a runtime issue) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design (documentation consistency) |
| **CWE** | (no direct CWE; documentation-drift avoidance) |
| **Spec-Kit task** | TASK-SEC-TASKS-026-2 |

**Observation**: T010 amends `spec.md §Edge Cases` per SEC-026-v2-1 (empty-set fallback semantic shift on default server). `data-model.md` §"Order-of-operations at server registration time" §"Default server" also describes the default-server flow — steps 3–4 mention "Plugin's `Controller::filter_default_server_config()` callback runs — looks up default server row by slug, calls `ToolPolicy::compose_effective_tools_for_row(...)`, replaces `$config['tools']` with the result." No mention of the empty-set fallback semantic shift or the "typically unreachable on installs with any `mcp.public = true` ability" observation.

If a maintainer reads `data-model.md` in isolation, they won't see the SEC-026-v2-1 clarification. Artifact drift risk.

**Why this is not a real vulnerability**:
- No runtime impact.
- `spec.md` is the authoritative artifact for edge-case documentation; `data-model.md` is a technical reference.
- Cross-reference from `data-model.md` to `spec.md §Edge Cases` is implicit via the `Companion docs` link at the top of `plan.md`.

**Why v3 flags it**:
- The tasks-review pattern (per captured `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX`) benefits from complete cross-artifact consistency. A one-line pointer from `data-model.md` §Default-server-flow to `spec.md §Edge Cases` keeps future readers on-path.

**Recommendation** (non-blocking):

Amend T010 to also update `data-model.md` §"Order-of-operations at server registration time" §"Default server" step 3 with a parenthetical: *"(replaces `$config['tools']` with the result; if empty, short-circuits and returns `$config` untouched — see `spec.md §Edge Cases` for the shifted fallback semantic under F026)."*.

Apply during T010 authoring. Two lines of diff.

**Blocking?** No.

## Confirmed Secure Patterns

The following aspects of the task list explicitly reinforce F017/F020/F025's + Constitution's security posture:

1. **Coverage matrix pattern honored** — `tasks.md` §"Security-review remediation folded into tasks" maps all five prior plan-review findings to task IDs, per captured `DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX`.
2. **PRESERVATION invariant pattern correctly OMITTED** — no F026 task performs subtractive edits on security-sensitive methods. Every Controller change is a method-name swap (not a delete); every docblock update is comment-only. The invariant pattern applies only to security-sensitive method deletions; F026 has none. Correctly not present in tasks.md.
3. **Foundational sequencing** — T002 (new method) → T003/T004 (call-site swaps) — every task that references the new method is preceded by the task that creates it. No "deferred" security work.
4. **B29 mitigation carried over** — T002's method uses `wp_get_abilities()` which was verified in v2 review to be safe for third-party abilities registered on `wp_abilities_api_init`. F026 does not need to re-solve B29's vendor-race pattern (already handled by F025's `ToolPolicy::PROTOCOL_TOOLS` columns).
5. **Read-only DB access** — T002's method reads via `ExposureResolver::resolve()` → `MCPServerAbility\Query::query()` (BerlinDB Kern prepared path). Zero writes. Zero raw `$wpdb` calls added. `data-model.md` §Storage layers explicitly declares "No write paths."
6. **`permission_callback` boundaries unchanged** — F026 modifies no REST route. T009 explicitly verifies `ToolsController` has NO code change. `AbilitiesController` (F017) is not touched.
7. **Grep audits enforce single-composer + single-filter contract** — T014 (a)(b)(c) with exact expected counts (3/3/1). Prevents accidental duplication or drift.
8. **Test-first hygiene** — Notes section explicitly says "Verify PHPUnit tests fail BEFORE implementation for the test-first cases (T005, T006)". Matches plugin Definition-of-Done.
9. **Fail-open pattern preserved** — T002's task text explicitly requires the `function_exists( 'wp_get_abilities' )` guard per FR-003. SC-006 documents quickstart-only coverage decision (see SEC-TASKS-026-1).
10. **Companion-plugin backwards compat documented** — T007 filter-docblock update + T008 docs update declare the widened pre-filter input as a strict superset. Existing F025 callbacks continue to work; new SEC-026-v2-2 timing note aids future extension authors.
11. **F017 call-time gate untouched** — no F026 task modifies `AbilityExposureGate` or `mcp_adapter_pre_tool_call` priority slots. Deny-precedence intact.
12. **Vendor code untouched** — no F026 task references anything under `vendor/wordpress/mcp-adapter/`. Verified by tasks.md file-path scan.
13. **Post-merge follow-ups documented** — tasks.md §Notes calls out (a) potential `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` annotation with "second use", (b) F017 `_reset_cache_for_tests()` → `clear_request_cache()` rename per B23. Neither blocks merge; both traceable to specific findings.
14. **v2 corrections propagated** — SEC-026-v2-1 (empty-set fallback shift) → T010 spec amendment; SEC-026-v2-2 (ability-registration timing) → T008 docs note. v1 findings + v2 findings all traceable.
15. **Parallel-safe task marking** — All `[P]` tasks touch different files. No `[P]` marker bypasses a security prerequisite (verified against the Dependencies section).

## Action Plan & Next Steps

### 1. Recommended non-blocking task edits (before implementation begins)

- **SEC-TASKS-026-1**: fold the SC-006 comment marker into T002's method body (one line of code comment).
- **SEC-TASKS-026-2**: extend T010's scope to also amend `data-model.md` §"Order-of-operations" (two lines of diff).

Both are one-line edits to `tasks.md`; do not require re-running `/speckit-tasks`.

### 2. Durable Memory Preservation

Neither finding introduces a new systemic pattern. The task-authoring patterns captured on 2026-07-14 (`DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX`) continue to apply and F026 tasks-review confirms their utility — this is a second observation, aligning with F020 WORKLOG's "at least one more feature to prove out" convention. Consider capturing this validation in a future WORKLOG entry post-implementation.

**No `/speckit-memory-md-capture` invocation at this turn.**

### 3. Remediation Planning

No CRITICAL or HIGH findings. `/speckit-security-review-followup` NOT required. Both INFO findings are one-line task edits.

### 4. Proceed to Architecture Refactor Generator

The task list is safe to hand off to `/speckit-architecture-guard-refactor-generator` (Step 5 of the parent `governed-tasks` workflow). No task-list refactor is expected — F026 introduces no new architectural drift.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-14-026-abilities-into-tool-registration-tasks.md | tasks | 2026-07-14 | LOW | C:0 H:0 M:0 L:0 I:2 | A04 |
```
