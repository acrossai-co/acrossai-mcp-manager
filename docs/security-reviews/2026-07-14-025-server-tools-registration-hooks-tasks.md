---
document_type: security-review
review_type: tasks
assessment_date: 2026-07-14
codebase_analyzed: acrossai-mcp-manager (Feature 025 tasks.md)
total_files_analyzed: 9
total_findings: 3
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 3
owasp_categories: [A04, A05]
cwe_ids: [CWE-441, CWE-754]
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

# Security Review — Feature 025 Task List

## Executive Summary

**Feature**: 025-server-tools-registration-hooks
**Task artifact under review**: `specs/025-server-tools-registration-hooks/tasks.md` (28 tasks across 7 phases)
**Plan reviews consulted**: v1 (3 INFO) + v2 (1 LOW, 2 INFO)
**Overall risk**: **LOW** — 0 Critical / 0 High / 0 Medium / 0 Low / 3 Informational.

Every plan-review finding (SEC-025-INFO-1 / -INFO-2 / -INFO-3 / -v2-1 / -v2-2 / -v2-3) has an explicit remediation task with the finding ID cited in the task description. Sequencing is safe: schema + `ToolPolicy` land in Foundational (Phase 2) before any user-story work; the delete of `EXCLUDED_SLUGS` (T012) happens strictly after `ToolPolicy` exists (T005) so the split path is available; the FR-016 observability event emission (in T012) fires from the same commit that authorizes protocol-slug POSTs, preventing an intermediate audit-blind state.

Three INFO-level task-coverage gaps surface: (1) a specific confused-deputy behavior asserted in SEC-025-INFO-1 lacks an explicit PHPUnit assertion in T011, (2) T016 does not test the truly-empty tools array as a legal state, and (3) T012's task description could add an explicit "MUST NOT modify permission_callback binding" affirmation to prevent inadvertent regression via zealous cleanup.

## Tasks Reviewed

- **All 28 tasks** in `specs/025-server-tools-registration-hooks/tasks.md` traced against the six plan-review findings and Constitution §III checkpoints.
- **Phase 1 (Setup)**: T001 — branch state confirmation only, no security surface.
- **Phase 2 (Foundational)**: T002 → T007 — schema + Row + ToolPolicy + foundational tests.
- **Phase 3 (US1)**: T008 → T011 — Controller changes + Main.php wiring + injection test.
- **Phase 4 (US2)**: T012 → T016 — ToolsController REST changes + tools.js Remove + empty banner + count text + test updates.
- **Phase 5 (US3)**: T017 — Reset button.
- **Phase 6 (US4)**: T018 — extension author docs.
- **Phase 7 (Polish)**: T019 → T028 — docblock + spec amendment + planning-doc header + quality gates + grep audits + quickstart.

## Coverage matrix (plan-review findings → tasks)

| Finding | Task | Present? |
|---|---|---|
| SEC-025-INFO-1 (v1) — Filter can override operator's protocol-tool removal | T018 (filter-authors advisory sentence in `docs/extending-server-tools.md`) | ✓ Documentation |
| SEC-025-INFO-2 (v1) — Two-write POST race | T012 (`// SEC-025-INFO-2: accepted race window ...` code comment marker between the two writes) | ✓ Code comment |
| SEC-025-INFO-3 (v1) — `ToolExposureGate::EXCLUDED_SLUGS` vestigial | T019 (docblock update marking the constant vestigial post-F025) | ✓ Docblock |
| SEC-025-v2-1 (v2) — Spec-vs-plan inconsistency on empty-set default-server fallback | T020 (spec §Edge Cases amendment, Option A — distinguishes DB vs default paths) | ✓ Spec edit |
| SEC-025-v2-2 (v2) — POST validation timing hardening | T016 (`test_post_accepts_all_three_protocol_slugs()` PHPUnit case) | ✓ Test |
| SEC-025-v2-3 (v2) — `server_slug` KEY-not-UNIQUE integrity note | T009 (code comment above `MCPServerQuery::query()` in `filter_default_server_config()`) | ✓ Code comment |

All six findings are represented. Below are three additional task-hygiene observations.

## Vulnerability Findings

### SEC-TASKS-025-1 — T011 test coverage for the confused-deputy scenario (SEC-025-INFO-1) is only indirectly present

| Field | Value |
|---|---|
| **Finding ID** | SEC-TASKS-025-1 |
| **Location** | `specs/025-server-tools-registration-hooks/tasks.md` §Phase 3 T011 |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (test-coverage gap; not a runtime vulnerability) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design (test-completeness lens) |
| **CWE** | CWE-441 — Confused Deputy (the vulnerability class the test would guard) |
| **Spec-Kit task** | TASK-SEC-TASKS-025-1 |

**Observation**: T011's specified PHPUnit cases include:
- Case 8: "remove-a-protocol-tool test, add-a-slug test, return-null degrades to `[]`."

These cover the FILTER MECHANICS in isolation. But the confused-deputy scenario the security review v1 flagged is more specific:

**Setup**: Operator has flipped `tool_execute_ability` to `0` via the Tools tab (their explicit "no execute" intent). Companion plugin's filter callback returns `array_merge( $tools, [ 'mcp-adapter/execute-ability' ] )`.

**Expected outcome under the plan design**: the filter can override — the composed set INCLUDES `execute-ability` even though the column is `0`.

**Why an explicit test matters**: this behavior is INTENTIONAL per the plan (per FR-008 "callbacks MUST be able to add or remove any slug freely") but SURPRISING to an operator (per SEC-025-INFO-1). An explicit PHPUnit case that asserts this behavior serves two purposes: (a) documents the intentional override in executable form, and (b) protects against a future well-meaning change that adds "if operator explicitly removed via column, block filter re-add" — which would be a real behavior regression.

**Recommendation**: Extend T011's case list to include a 9th case:

```
public function test_filter_can_readd_protocol_slug_operator_removed_via_column(): void {
    // Setup: server row with tool_execute_ability = 0.
    // Register callback: appends 'mcp-adapter/execute-ability' unconditionally.
    // Assert: final composed set (passed to create_server) includes the slug.
    // Assert: observability action was NOT fired (filter-side changes are silent — only POST-side flips fire acrossai_mcp_tools_changed).
}
```

**Blocking?** No.

---

### SEC-TASKS-025-2 — T016 does not test the truly-empty tools array as a legal state

| Field | Value |
|---|---|
| **Finding ID** | SEC-TASKS-025-2 |
| **Location** | `specs/025-server-tools-registration-hooks/tasks.md` §Phase 4 T016 |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (test-coverage gap) |
| **OWASP Top 10 2025** | A05:2025 — Security Misconfiguration (empty state is a legal but unusual configuration) |
| **CWE** | CWE-754 — Improper Check for Unusual or Exceptional Conditions |
| **Spec-Kit task** | TASK-SEC-TASKS-025-2 |

**Observation**: T016's specified PHPUnit assertions include:
- (a) POST `[3 protocol slugs + 1 curated]` sets all three columns to `1` AND writes the curated;
- (b) POST `[1 curated only]` sets all three columns to `0` AND writes the curated;
- (c) GET returns the composed union;
- (d) SEC-025-v2-2 explicit-protocol-slugs POST returns 200.

The truly-empty state (all three columns `0` and zero curated rows) is a **legal configuration** per spec §Edge Cases + FR-017. But it is not explicitly asserted in T016. The v2 finding SEC-025-v2-1 also touched the empty-state behavior at the default-server path but did not cover the database-server test coverage.

**Why this matters**: FR-017 mandates a warning banner in this state. FR-011 mandates existing installs' behavior is preserved on upgrade. Both invariants intersect at "server with empty tools = legal state, does not fatal, GET returns `[]`". Without an explicit PHPUnit case, a future regression (e.g., a well-meaning validator that rejects empty `tools`) could silently break the state.

**Recommendation**: Extend T016 with one additional case:

```
public function test_post_empty_tools_array_produces_empty_composed_set(): void {
    // Setup: server row with prior curated rows and default columns.
    // POST { tools: [] }.
    // Assert: 200 response.
    // Assert: all three tool_* columns become 0.
    // Assert: MCPServerToolQuery::get_added_slugs() returns [].
    // Assert: subsequent GET returns { tools: [] } (composed union of nothing).
}
```

**Blocking?** No.

---

### SEC-TASKS-025-3 — T012 lacks explicit non-regression affirmation for `permission_callback` binding

| Field | Value |
|---|---|
| **Finding ID** | SEC-TASKS-025-3 |
| **Location** | `specs/025-server-tools-registration-hooks/tasks.md` §Phase 4 T012 |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (task-hygiene affirmation) |
| **OWASP Top 10 2025** | A01:2025 — Broken Access Control (guarded against) |
| **CWE** | (defensive documentation, no direct CWE) |
| **Spec-Kit task** | TASK-SEC-TASKS-025-3 |

**Observation**: T012's description directs the implementer to "DELETE the `EXCLUDED_SLUGS` constant" and "DELETE the validation branch in `post_tools()` that rejects submissions containing protocol slugs." These are targeted, precise edits. However, they involve deleting code from a security-sensitive method (`post_tools()`). A zealous cleanup could accidentally sweep in adjacent code — for example, deleting the surrounding `if` block that contains the `permission_callback` check binding on `register_rest_route()`, or the nonce middleware setup.

**Why an explicit affirmation matters**: task-review-time affirmations are cheap and prevent an entire class of "I thought I was just deleting X but I actually removed Y" defects. F011 documented this pattern (D6 → `use` imports MUST NOT be deleted during subtractive edits). F016 codified D21 → subtractive-edit PRs need explicit preservation lists.

**Recommendation**: Amend T012's description to add one sentence at the end:

> "PRESERVATION invariant: this task MUST NOT modify `register_rest_route()` `permission_callback` binding on either the GET or POST route, MUST NOT modify the nonce middleware setup, and MUST NOT modify the `manage_options` capability check inside `permission_check()`. Only the `EXCLUDED_SLUGS` constant and its consuming validation branch inside `post_tools()` are deleted."

This preserves the F020 authorization boundary explicitly in the task text, giving code reviewers a specific checklist item.

**Blocking?** No.

## Confirmed Secure Patterns

The following aspects of the task list explicitly reinforce F020's + Constitution's security posture:

1. **Foundational sequencing**: T002 (schema) → T004 (Row cast) → T005 (ToolPolicy) → T008 (Controller) — every task that reads `$row->tool_*` fields is preceded by the task that adds the properties with `(int)` casts (B18). No "deferred" security work.
2. **B18 explicit call-out**: T005's description names the bug pattern ("MUST use `! empty()` or `(int)`-cast to check column values, NOT `=== 1` (B18)"), embedding the memory-hub anti-pattern guard into the task text.
3. **Grep audits enforce single-source protocol slugs**: T027 (d) — `grep -rn "mcp-adapter/discover-abilities" includes/` returns exactly ONE match, inside `ToolPolicy::PROTOCOL_TOOLS`. This prevents future maintainers from re-introducing an inline duplicate literal.
4. **Grep audits enforce filter single-site**: T027 (a) — `apply_filters( 'acrossai_mcp_manager_server_tools'` returns exactly ONE match. Prevents accidental double-fire.
5. **Grep audits enforce `EXCLUDED_SLUGS` deletion**: T027 (c) — zero matches in `includes/` + `src/js/`. Machine-verifiable that the constant is truly gone.
6. **Test-first for foundational surface**: T006 (ToolPolicyTest) and T007 (SchemaMigrationTest) run in Foundational, before any user story consumes the helper. This matches the plugin's Definition-of-Done "verify tests fail before implementing" rule.
7. **F020 sequencing preserved**: T012 does not disable F020's call-time enforcement (`ToolExposureGate`) — the priority 30 gate on `mcp_adapter_pre_tool_call` stays wired via `Main.php` unchanged. Regression test T028 (quickstart §Regression) explicitly re-validates the F020 behavior post-implementation.
8. **F016 observability preserved**: T012 emits `acrossai_mcp_tools_changed` for column flips (FR-016), keeping the audit surface uniform with F020's curated-side event stream. Existing subscribers continue to work unchanged.
9. **Data integrity guardrails at REST boundary**: The plan (referenced by T012) specifies `absint()` on the three column flags at the REST boundary + `strval` + `wp_get_abilities()` validation on slugs. F020 baseline sanitization preserved.
10. **Docblock affirms F017 orthogonality**: T019's docblock update explicitly names the F017 `AbilityExposureGate` layer as unchanged, preventing a future dev from confusing the two enforcement layers.
11. **Parallel-safe task marking**: All `[P]` tasks in `tasks.md` touch different files or non-overlapping test suites. No `[P]` marker bypasses a security prerequisite (verified against the Dependencies section).
12. **Post-merge follow-ups documented**: The tasks.md Notes section calls out three future maintenance tickets — remove `EXCLUDED_SLUGS` bypass, promote `server_slug` to UNIQUE, and capture the two proposed decisions — none blocking merge but each traceable to a specific finding.

## Action Plan & Next Steps

### 1. Recommended non-blocking task edits (before implementation begins)

- **SEC-TASKS-025-1**: extend T011's case list from 8 to 9 cases; add the confused-deputy assertion.
- **SEC-TASKS-025-2**: extend T016 with the truly-empty tools array test case.
- **SEC-TASKS-025-3**: append the preservation-invariant sentence to T012's description.

These are one-line edits to `tasks.md`; do not require re-running `/speckit-tasks`.

### 2. Durable Memory Preservation

Two systemic patterns surfaced from the tasks review — both extend existing memory decisions rather than proposing net-new ones:

- **Extend F011 D6** ("use imports MUST NOT be deleted during subtractive edits") to a broader pattern: **DEC-TASKS-025-PRESERVATION-INVARIANT-PATTERN** — subtractive-edit tasks that touch security-sensitive methods MUST include an explicit list of preserved invariants (permission_callback bindings, nonce setup, capability checks). This is a task-authoring pattern, not a per-feature finding.
- **Extend F020 WORKLOG lesson** ("run security-review v2 for close-in-substance verification") to a **DEC-TASKS-025-COVERAGE-MATRIX-PATTERN** — every task-review MUST produce a coverage matrix mapping each plan-review finding to its remediation task ID. The presence of a matrix is the fastest way to detect a dropped finding.

Both are systemic patterns worth capturing via `/speckit.memory-md.capture`. However, per the F020 "defer capture until soaked" guidance, capture is deferred to after F025 implementation lands and the pattern's utility is proven at least once end-to-end.

### 3. Remediation Planning

No CRITICAL or HIGH findings. `/speckit.security-review.followup` NOT required. The three INFO findings are one-line task edits.

### 4. Proceed to Architecture Refactor Generator

The task list is safe to hand off to `/speckit-architecture-guard-refactor-generator` (Step 5 of the parent `governed-tasks` workflow). No task-list refactor is needed — this feature does not introduce architectural drift that requires refactor tasks.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-14-025-server-tools-registration-hooks-tasks.md | tasks | 2026-07-14 | LOW | C:0 H:0 M:0 L:0 I:3 | A04,A05 |
```
