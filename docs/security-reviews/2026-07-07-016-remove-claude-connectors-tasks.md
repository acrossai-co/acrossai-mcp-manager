---
document_type: security-review
review_type: tasks
assessment_date: 2026-07-07
codebase_analyzed: acrossai-mcp-manager (Feature 016 tasks.md)
total_files_analyzed: 8
total_findings: 2
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 2
owasp_categories: [A09]
cwe_ids: [CWE-778]
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

# Security Review — Feature 016 Tasks (Remove Claude Connectors)

## Executive Summary

Feature 016's tasks.md decomposes the retirement into 37 well-scoped tasks across 7 phases. All 4 plan-level security findings (SEC-001..004 from `docs/security-reviews/2026-07-07-016-remove-claude-connectors-plan.md`) are converted to concrete tasks or tracked as post-merge reminders:

- **SEC-001** (plaintext-secret disk residue) → folded into T036's manual retirement recipe as the pre-DROP `UPDATE ... SET ''` overwrite instruction for the README.txt Unreleased entry.
- **SEC-002** (audit trail discard advisory) → folded into T036's operator advisory ("revoke connector tokens on claude.ai first").
- **SEC-003** (bearer-header silent removal advisory) → folded into T036's behavior-change note.
- **SEC-004** (orphaned S7 / DEC-CLIENT-RENDERER-PUBLIC-API / A13 / Constitution annotations) → tracked as T037, marked as a post-merge memory-hygiene reminder.

Task ordering is sound: Phase 1 setup (baseline capture + B15 grep hygiene) precedes ALL user-story phases; T016 (Main.php) is documented as a critical-path task that must land in the same commit as T013–T015 (class deletions) to avoid a broken intermediate state; T028 (`npm run build`) is a barrier after the SCSS/webpack edits; T031 (FR-015 grep audit) is a hard gate before the quality gates T032–T035.

No CRITICAL/HIGH/MEDIUM/LOW findings. Two INFORMATIONAL findings surface: (a) the negative-test surface at T030 could be expanded to cover 3 additional runtime 404 assertions already documented in `spec.md` SC-007 and `quickstart.md`, and (b) T037 (post-merge memory hygiene) has no forcing function to ensure it actually executes. Both are advisory, non-blocking follow-ups foldable into a T030 edit or a PR-checklist item.

## Tasks Reviewed

1. `specs/016-remove-claude-connectors/tasks.md` — 37 tasks / 7 phases.
2. `specs/016-remove-claude-connectors/security-constraints.md` — 4 follow-up items from the plan review.
3. `specs/016-remove-claude-connectors/spec.md` — 19 FRs + 8 SCs cross-referenced against task coverage.
4. `specs/016-remove-claude-connectors/plan.md` — Constitution Check verdict + project-structure edit matrix.
5. `specs/016-remove-claude-connectors/research.md` — 7 Phase-0 decisions.
6. `specs/016-remove-claude-connectors/quickstart.md` — verification recipes per user story.
7. `specs/016-remove-claude-connectors/contracts/retired-artifacts.md` — machine-checkable retirement list.
8. `specs/016-remove-claude-connectors/memory-synthesis.md` — durable-memory context (DEC-BERLINDB-*, D6/A6/B1, DEC-UNINSTALL-OPT-IN-GATE).

## Vulnerability Findings

### SEC-005 — Task T030 negative-test list omits runtime 404 assertions for retired public surfaces (INFORMATIONAL)

- **Location**: `specs/016-remove-claude-connectors/tasks.md` Phase 6, T030 (US4 manual smoke).
- **OWASP Category**: A09:2025 — Security Logging and Monitoring Failures (test-coverage gap for a security-relevant negative case).
- **CWE**: CWE-778: Insufficient Logging.
- **CVSS**: 0.0 (INFORMATIONAL — the retirement is verified positively by the grep audit at T031; the missing runtime assertions are duplicative confidence, not a security regression).
- **Spec-Kit Task**: TASK-SEC-016-005.

**Finding**: T030's manual smoke checklist covers CLI auth positive path (approval, App Password issuance, audit log row) and the negative bearer-header assertion, but does NOT enumerate the three runtime 404 assertions that `spec.md` SC-007 and `quickstart.md` §US2 step 5 both prescribe:

- `curl -sI https://LOCAL/.well-known/oauth-authorization-server/mcp/1` → expect 404
- `curl -sI https://LOCAL/.well-known/oauth-protected-resource/mcp/1` → expect 404
- `curl -sI -X POST https://LOCAL/wp-json/acrossai-mcp/v1/token` → expect 404

T031's grep audit proves the retirement in CODE (no references remain). These curls prove the retirement at RUNTIME (URLs actually return 404 rather than a stale rewrite-rule 200 response). The runtime assertions are the primary evidence that `flush_rewrite_rules()` in Activator (which was PRESERVED per T017) actually flushed on reactivation.

**Impact**: If any of the three retired public surfaces silently continue to respond (e.g., due to rewrite-cache miss, plugin update deployed without deactivation cycle, or reverse-proxy caching), the grep audit still passes but the retirement is incomplete at runtime. Attacker-visible.

**Recommendation**: Amend T030 to add a step (f):

> (f) Run the three retirement runtime assertions:
> ```
> curl -sI https://LOCAL/.well-known/oauth-authorization-server/mcp/1  # expect 404
> curl -sI https://LOCAL/.well-known/oauth-protected-resource/mcp/1    # expect 404
> curl -sI -X POST https://LOCAL/wp-json/acrossai-mcp/v1/token         # expect 404
> ```
> If any of the three returns a non-404 status, the operator MUST run `wp rewrite flush` (or deactivate+reactivate to trigger the plugin's own flush) and re-verify.

**Verdict**: Minor coverage gap. Fold into the T030 edit before the implementation PR closes.

---

### SEC-006 — T037 post-merge memory hygiene lacks a forcing function (INFORMATIONAL)

- **Location**: `specs/016-remove-claude-connectors/tasks.md` Phase 7, T037 (memory-hygiene reminder).
- **OWASP Category**: A09:2025 — Security Logging and Monitoring Failures (durable-memory drift becomes an unchecked audit debt).
- **CWE**: N/A (memory-hygiene process gap, not a runtime weakness).
- **CVSS**: 0.0 (INFORMATIONAL).
- **Spec-Kit Task**: TASK-SEC-016-006.

**Finding**: T037 explicitly says "This task is a REMINDER, not a code edit. After the PR merges, run `/speckit-memory-md-capture-from-diff`..." — the annotations queued (S7 orphaning, DEC-CLIENT-RENDERER-PUBLIC-API surface shrink, A13 orphaning, Constitution Principle I Rationale + Directory Layout edit) are important for future-code-reviewer awareness, but there is no mechanism ensuring the reminder executes. Post-merge follow-ups notoriously drop off the checklist unless explicitly gated.

**Impact**: If T037 is skipped:
- `PROJECT_CONTEXT.md::S7` will describe an `__return_true` exception with zero consumers. A future maintainer adding a new REST route could cite S7 as precedent for `__return_true`, thinking the exception is still valid.
- `DECISIONS.md::DEC-CLIENT-RENDERER-PUBLIC-API` still references 3 shortcodes; a future contributor could add a new shortcode "to match the pattern" without realizing the surface shrank.
- Constitution Principle I active-area list continues to name `OAuth / Claude Connectors` as a live module.
- `ARCHITECTURE.md` Directory Layout still lists `includes/OAuth/`.

Slow-burn drift; each item alone is a nit; cumulative effect over months is real.

**Recommendation** (advisory, not blocking implementation):

1. Rewrite T037 to make execution part of the merge criteria:

> Post-merge, within 7 days OR before the next feature branch is cut (whichever is sooner), execute `/speckit-memory-md-capture-from-diff` and apply the 4 queued annotations (S7, DEC-CLIENT-RENDERER-PUBLIC-API, A13, Constitution Principle I + Directory Layout). If the annotations cannot be executed in the same PR (they cross constitution boundaries and warrant human review), open a follow-up issue titled "Feature 016 memory-hygiene follow-up" AND link it from the merge commit message.

2. Alternatively, promote T037 to a pre-merge checklist item and land the annotations in the same Feature 016 PR. Cost: 5–10 minutes of memory edits; benefit: no follow-up drift risk.

**Verdict**: Documentation-process gap. Recommend option 2 (fold into same PR) if bandwidth allows; otherwise option 1 (issue-link forcing function) is acceptable.

## Confirmed Secure Patterns in tasks.md

The task list explicitly honors these sequencing/security patterns:

- ✅ **Pre-flight grep baseline** (T001) — creates the reference against which the FR-015 final audit (T031) diffs. Prevents "we thought we deleted everything" false-PASS scenarios.
- ✅ **B15 grep-hygiene guard** (T002) — verifies the FR-015 regex catches both bare-`use` and leading-`\` FQN forms BEFORE trusting the audit. Direct application of the `docs/memory/INDEX.md::B15` bug pattern.
- ✅ **Critical-path commit boundary documentation** — T013+T014+T015+T016 explicitly noted as "must land in same commit" to avoid Main.php referencing non-existent classes. Prevents intermediate-broken-state bisect landings.
- ✅ **T018 documents the DEC-UNINSTALL-OPT-IN-GATE analog** — the plugin's `Deactivator::deactivate()` no longer references the retired cron; operator handles unschedule manually (per updated `research.md` Decision 5).
- ✅ **T017 explicit "Do NOT add destructive SQL" guardrail** — surfaces the operator-directive scope reduction directly in the task description; guards against a maintainer misreading the task and adding "belt and suspenders" cleanup code.
- ✅ **T029 negative-space verification** (`git diff --name-only main -- <CLI stack paths>`) — proves CLI auth stack was NOT accidentally touched by the retirement. Explicit isolation-boundary assertion at the task level.
- ✅ **T031 (FR-015 grep audit)** is a hard gate before the quality gates T032–T035. Any lingering retirement symbol fails the merge before PHPCS/PHPStan/PHPUnit even run.
- ✅ **T036 folds SEC-001..003 into the operator-facing README** — the plan-level security findings become documentation deliverables the operator sees, not internal notes.
- ✅ **T037 tracks SEC-004** — the plan-level memory-hygiene finding has a task; the only question is whether execution is gated (see SEC-006 above).
- ✅ **Suggested commit boundaries** (tasks.md §Implementation Strategy) — 5-commit sequence keeps each git-bisect landing on a workable state. Aligns with the reference F011 retirement pattern.

## Constitution Alignment (Principle III — Security First, Task Coverage)

| Constitution rule | Feature 016 task coverage | Verdict |
|---|---|---|
| Input sanitized at boundary | N/A — no new inputs; retired `save_claude_connector` handler (which sanitized) removed by T007 | PASS |
| Output escaped at rendering | N/A — no new outputs | PASS |
| Nonce on forms/AJAX | N/A — retired nonce-verifying handler removed by T007 | PASS |
| Capability check on admin actions | N/A — retired admin action removed by T007; no new admin action added | PASS |
| `$wpdb->prepare()` on DB queries | N/A — no new DB queries added (fresh-install-only scope) | PASS |
| Explicit `permission_callback` on REST | N/A — retired REST route removed by T017 (indirect via `TokenController` deletion in T013) | PASS |
| OAuth tokens / App Passwords hashed | N/A — OAuth token storage retired; App Passwords untouched (T029 verifies) | PASS |
| Consent-surface exception (2026-06-30) | T012/T029 verify `FrontendAuth` untouched; retired OAuth consent form removed via T013/T017 | PASS |
| S7 exception count invariant | T037 queues the annotation for post-merge (see SEC-006) | Deferred |
| S9 consent-surface authoritative-store rule | T029 verifies CLI auth's use of this rule is untouched | PASS |

## Action Plan & Next Steps

1. **Durable Memory Preservation**: No new sequencing rules or reusable patterns emerged from this review. SEC-005 is a coverage nit; SEC-006 is a process reminder. The `docs/memory/INDEX.md::B15` pattern is already codified and correctly applied at T002. **No new memory capture warranted.** Skipping the mandatory `/speckit-memory-md-capture` call.
2. **Remediation Planning**: No CRITICAL/HIGH/MEDIUM findings. `/speckit-security-review-followup` is not required. Recommend the T030 amendment (SEC-005) be included in the same PR that lands the retirement; recommend T037 be promoted to a same-PR checklist item OR gated on an explicit follow-up issue (SEC-006).
3. **Handoff to Architecture Guard**: This review's output is stored in `docs/security-reviews/` per project convention; the tasks.md changes are the actionable outputs. Architecture Guard's `/speckit-architecture-guard-refactor-generator` (parent-workflow Step 5) can now run against the current tasks.md without waiting for changes from this review — the two INFO findings are documentation/coverage nits, not architectural.

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-07-016-remove-claude-connectors-tasks.md | tasks | 2026-07-07 | INFORMATIONAL | C:0 H:0 M:0 L:0 | A09 |
```
