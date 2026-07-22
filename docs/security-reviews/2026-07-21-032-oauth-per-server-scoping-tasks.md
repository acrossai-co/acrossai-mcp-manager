---
document_type: security-review
review_type: tasks
assessment_date: 2026-07-21
codebase_analyzed: acrossai-mcp-manager (F032 OAuth Per-Server Scoping — tasks.md review)
total_files_analyzed: 10
total_findings: 4
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 1
low_count: 1
informational_count: 2
owasp_categories: [A09]
cwe_ids: [CWE-841]
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

# SECURITY REVIEW REPORT — TASKS PHASE

## Executive Summary

The F032 tasks.md (67 tasks across 7 phases) provides comprehensive security coverage for the P1 security fix objective. All 4 plan-review v2 security remediations (SEC-032-001 through SEC-032-005) are explicitly reflected as tasks with matching test tasks + grep-gate governance. Cross-server bypass prevention is enforced at multiple layers (schema-level composite UNIQUE + query-layer validation + REST-layer 403 + observability action + registration-order coordination).

Findings are LIMITED to task-sequencing concerns, not missing coverage. **One MEDIUM finding** identifies an implicit runtime dependency between T023 (OAuthClients upgrade callback body — which fires the aggregate observability signal) and T024 (Main.php registration order fix — which controls the runtime firing order of per-Table callbacks); if T024 is deferred or forgotten, the aggregate observability signal fires with incorrect purge counts on the first upgrade run. **One LOW finding** identifies that the T032-T034 breaking Query signature changes lack an inline "grep all callers first" checkpoint — the grep-gate lives in T062 (polish phase), which is far downstream from the breaking change; a missed call site becomes a runtime PHP fatal instead of a lint failure.

Overall tasks-phase risk: **MODERATE.** Recommended remediations are small tasks.md edits (< 15 minutes total) — none require design changes. The tasks list is otherwise ready for `/speckit-architecture-guard-governed-implement`.

## Tasks Reviewed

| Artifact | Path | Notes |
|---|---|---|
| Tasks | `specs/032-oauth-per-server-scoping/tasks.md` | 67 tasks, 7 phases, tests-included per spec §DoD |
| Plan | `specs/032-oauth-per-server-scoping/plan.md` | Constitution Check all PASS + Security Boundary Matrix v2 |
| Spec | `specs/032-oauth-per-server-scoping/spec.md` | 28 FRs, 12 SCs, 4 clarifications + 4 SEC remediations |
| Research | `specs/032-oauth-per-server-scoping/research.md` | 11 research decisions R1-R11 |
| Data model | `specs/032-oauth-per-server-scoping/data-model.md` | 3 entities, 6-step upgrade callback |
| Contracts | `specs/032-oauth-per-server-scoping/contracts/*.md` | 4 REST route contracts |
| Plan-review v1 | `docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan.md` | HIGH — all findings remediated |
| Plan-review v2 | `docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan-v2.md` | INFORMATIONAL — 5 findings closed, 1 deferred, 1 optional |
| Memory synthesis | `specs/032-oauth-per-server-scoping/memory-synthesis.md` | 5 decisions + 5 architecture + 3 security + 3 bugs |
| Memory index | `docs/memory/INDEX.md` | D28, D19, D27, B34, B36, S1-S9 all applied |

## Coverage Matrix — Task ↔ Plan-Level Security Requirement

| Plan-level Security Item | Tasks Covering | Verdict |
|---|---|---|
| Cross-server privilege escalation prevention (FR-016/017/018) | T032-T037 (implementation), T027-T029 + T031 (tests), T044 (SC-005 grep), T062 (a) (grep gate) | ✅ Multi-layer |
| 4-arg observability action, no owning-server leak (FR-023 / SEC-032-001) | T031 (test asserts arg count == 4), T035-T037 (fire), T062 (d)+(e) (grep gates) | ✅ Multi-layer |
| DCR URL origin verification (FR-027 / SEC-032-002) | T046 (test), T048 (helper Step 1), T062 (f) (grep gate) | ✅ Multi-layer |
| Backfill orphan-server guard (FR-005 amendment / SEC-032-003) | T017 (test), T023 (backfill SQL), T064 (quickstart step 4b) | ✅ Multi-layer |
| DCR pre-migration 503 race guard (FR-028 / SEC-032-005) | T047 (test), T049 (helper), T050 (handle_register FIRST check), T051 (handle_admin_generate ALSO), T062 (g) (grep gate) | ✅ Multi-layer |
| Auto-purge legacy DCR rows (FR-007) + aggregate observability (FR-024) | T016 (test), T023 (implementation Step 3 + Step 6 fire) | ✅ Covered |
| NOT NULL invariant (FR-026 / SC-009) | T013-T015 (tests assert IS_NULLABLE = 'NO'), T021-T023 (Step 5 MODIFY), T063 (SHOW CREATE TABLE match) | ✅ Multi-layer |
| Composite UNIQUE(client_id, server_id) (FR-004) | T004 (Schema.php), T023 (upgrade callback Step 4 swap order) | ✅ Covered |
| Site-wide user-deletion cascade regression (FR-042 / US4) | T053 (regression test), T054 (grep verification) | ✅ Covered |
| OAuth token SHA-256 hashing preserved (S3) | Implicit — F032 doesn't touch token hashing; unchanged from F021 | ✅ Preserved |
| REST permission_callback preserved (S2) | Implicit — F032 modifies existing routes (unchanged auth); DCR retains S8 exception | ✅ Preserved |
| Nonce coverage preserved (S1) | Implicit — F024 nonce unchanged; no new admin form | ✅ Preserved |

**Verdict**: Every plan-level security requirement maps to at least one implementation task + at least one test task + (for the four SEC remediations) at least one grep-gate governance check. No security requirement is missing coverage.

## Vulnerability Findings

### [MEDIUM] SEC-032-T-001 — T023 aggregate observability signal has implicit runtime dependency on T024 registration-order fix

**Location**: `specs/032-oauth-per-server-scoping/tasks.md` T023 (Phase 3 US3) + T024
**OWASP Category**: A09:2025-Security Logging and Monitoring Failures
**CWE**: CWE-841: Improper Enforcement of Behavioral Workflow (task-sequencing variant)
**CVSS Score**: 4.3 (MEDIUM) — observability-integrity concern; wrong counts silently mislead operators
**Description**: T023's Step 6 aggregate observability signal reads purge counts from cross-Table helpers: `OAuthTokens\Table::instance()->get_last_purge_count()` + `OAuthAuthCodes\Table::instance()->get_last_purge_count()`. These helpers only return non-zero AFTER the respective Table's upgrade callback has executed its Step 4 PURGE. BerlinDB fires per-Table `$upgrades` callbacks in registration order, so `OAuthClients` MUST be registered LAST for its aggregate signal to see accurate counts from the other two.

T024 fixes this: "ensure OAuthTokens + OAuthAuthCodes Table subclasses are registered in the reconcile loop BEFORE OAuthClients (per R2)". But T024 is listed AFTER T023 in the tasks list. A developer implementing T023 first, running local upgrade tests, and seeing them pass (because tests set up all three tables in a controlled order that doesn't depend on `Main::reconcile_database_schemas()` registration) may defer T024 as a cleanup item — creating a production bug where the aggregate `acrossai_mcp_oauth_legacy_dcr_purged` signal fires with wrong (likely `(N, 0, 0)`) counts on the first live upgrade run.

**Runtime effect if T024 is forgotten**: legitimate purges of tokens + auth codes happen correctly (data-integrity unaffected), but the observability signal that operators rely on to confirm the purge magnitude reports 0 for those two categories. Operators may (a) believe the upgrade purged only clients and not their descendants (undermining the aggressive-purge Q3 decision), or (b) fail to detect that a substantial purge occurred at all.

**Remediation**:
1. Reorder T024 to precede T023 in tasks.md OR add an explicit "MUST-BE-PAIRED-WITH-T024" note at the top of T023's body.
2. Add a boot-time assertion in `Main::reconcile_database_schemas()` that verifies OAuthClients is registered LAST among the three OAuth Tables — throw a `_doing_it_wrong()` notice (WordPress dev-mode) or `wp_die()` (production if strictly guarding) if a future refactor reorders them.
3. Add a NEW test to T016 (`test_legacy_dcr_purge_on_upgrade_fires_observability_action`): assert that the aggregate signal's tokens+auth_codes counts are NON-ZERO when the seeded fixture includes tokens/auth codes bound to legacy DCR clients. Current test seeds `(M, P, Q)` but doesn't specifically verify the ordering-dependence — a reordered registration would still see `M > 0` on the clients count and might pass the shape check.

**Spec-Kit Task**: TASK-SEC-032-T-001

---

### [LOW] SEC-032-T-002 — T032-T034 breaking Query signature changes not paired with an inline callers-grep checkpoint

**Location**: `specs/032-oauth-per-server-scoping/tasks.md` T032, T033, T034 (Phase 4 US1)
**OWASP Category**: A09:2025-Security Logging and Monitoring Failures (adjacent to CWE-841)
**CWE**: CWE-841: Improper Enforcement of Behavioral Workflow
**CVSS Score**: 2.4 (LOW) — impact bounded to runtime PHP fatal (visible failure), not silent success
**Description**: T032 (OAuthClients/Query.php `find_by_client_id` gains required `int $server_id`), T033 (OAuthTokens/Query.php `revoke_by_client_id` gains required `int $server_id` + `get_active_user_ids_by_client_id` rename), T034 (OAuthAuthCodes/Query.php same shape) each apply BREAKING signature changes to public Query methods. Every existing caller of these methods MUST be updated in the same commit — otherwise PHP throws `ArgumentCountError` at runtime.

The task list currently sequences these as: T032-T034 (breaking changes) → T035-T037 (REST handler updates that call the new signatures). The implicit assumption is that a developer will update handlers immediately after signature changes. However:
- If T032-T034 land in one commit and T035-T037 in a follow-up commit, the intermediate build is broken at runtime.
- The Final full-repo audit grep (T062 (a)) catches missed callers, but T062 runs in the Polish phase — potentially hours or days after the breaking change.
- No explicit inline task says "before committing T032, grep for all `find_by_client_id` callers and update in same commit".

This isn't a security vulnerability per se (PHP will surface the error loudly) but it violates the tasks-phase best practice of "task dependencies do not hide security work in later phases" — the caller-verification IS security work (ensuring every mutating call site has the new server_id validation), and it's currently deferred to T062.

**Remediation**: Add an inline mini-grep instruction to each of T032/T033/T034:
```
Before committing T032: grep -rn 'find_by_client_id\s*(' includes/OAuth/ — every hit MUST be
updated in this same commit to pass the new $server_id arg. Zero remaining callers should
use the pre-F032 1-arg signature.
```
Same shape for T033 (`revoke_by_client_id`, `get_active_user_ids_by_client_id`) and T034 (auth_codes helpers). This moves caller-verification into the same task as the signature change, closing the "broken commit interval" window.

**Spec-Kit Task**: TASK-SEC-032-T-002

---

### [INFORMATIONAL] SEC-032-T-003 — SEC-032-007 (Retry-After header on 503) from plan-review v2 not captured in tasks.md

**Location**: `specs/032-oauth-per-server-scoping/tasks.md` T050 (FR-028 503 response); missing task for optional `Retry-After: 5` header
**OWASP Category**: A09:2025-Security Logging and Monitoring Failures (nit)
**CWE**: N/A
**CVSS Score**: 0 (INFORMATIONAL)
**Description**: Plan-review v2 identified SEC-032-007 as an optional nit — the 503 response emitted by `handle_register` when the `server_id` column is absent (per FR-028) does not include an HTTP `Retry-After` header. The v2 report noted this as "deferrable to a follow-up nit-fix; not a blocker for `/speckit-tasks`."

Now that we ARE at `/speckit-tasks`, the finding is either (a) explicitly deferred with a note in tasks.md, or (b) captured as a task. Currently neither is present — the finding is simply absent from the task list. Not a blocker, but leaves the disposition ambiguous.

**Remediation** (pick one):
- **Option A (add task)**: Add T050-b (or renumber): "Add `Retry-After: 5` header to the 503 response in `handle_register`. Rationale: RFC 7231 §7.1.3 signals compliant HTTP clients (including well-behaved AI hosts) to implement exponential backoff; without it, some clients may retry too aggressively (spamming 503s), some too slowly (user-visible delay). Update contracts/dcr-register.md §503 to spell out the header." Estimated 3-line edit.
- **Option B (defer explicitly)**: Add a `NOTE` under T050: "SEC-032-007 (v2 plan review) identified an optional `Retry-After: 5` header. Deferred to F033 or follow-up nit-fix. Rationale: AI hosts observed in practice retry at 5-30s intervals without header guidance; explicit header is optimization, not correctness."

Either is acceptable; leaving unresolved is not.

**Spec-Kit Task**: TASK-SEC-032-T-003

---

### [INFORMATIONAL] SEC-032-T-004 — Parallel test-authoring on shared file (PerServerIsolationTest.php) can produce merge conflicts

**Location**: `specs/032-oauth-per-server-scoping/tasks.md` T016, T017, T027, T028, T029, T030, T031, T045, T046, T047, T053 — all append test methods to `tests/phpunit/OAuth/PerServerIsolationTest.php`
**OWASP Category**: A09 (marginal — this is a process concern, not a code concern)
**CWE**: N/A
**CVSS Score**: 0 (INFORMATIONAL)
**Description**: The tasks list marks all 11 tests appending to `PerServerIsolationTest.php` as `[P]` (parallelizable). While the methods within the file are logically independent, PHYSICALLY they all append to the same file — a parallel dev team writing all 11 tests concurrently will produce merge conflicts on the file's method-block region.

The tasks.md §Parallel Opportunities note acknowledges this: "US1 tests: T027-T031 all [P] — full parallel test-authoring (append to same file — coordinate via git)". Good — the caveat is documented. But the individual task lines still show `[P]` without a coordination footnote.

**Remediation** (pick one):
- **Option A (documentation)**: Add a footnote in each affected task: `[P]† — appends to shared file PerServerIsolationTest.php; coordinate via git branch strategy per §Parallel Opportunities`.
- **Option B (single-owner)**: Change the tasks from `[P]` to non-parallel and note that a single developer should author all PerServerIsolationTest tests in one sitting for the branch, mixing them by phase into a single commit or per-phase commits.
- **Option C (accept as-is)**: Leave the tasks marked `[P]` on the understanding that solo development (this project's pattern per §Implementation Strategy §Solo Development Notes) makes the parallel concern moot.

Solo dev context (per tasks.md §Solo Development Notes) makes Option C acceptable. The finding is INFORMATIONAL because it doesn't affect security, only team velocity if F032 were to shift to multi-developer work.

**Spec-Kit Task**: TASK-SEC-032-T-004 (optional; solo dev makes this a non-issue)

---

## Confirmed Secure Patterns

The following secure task-sequencing patterns are correctly applied:

- **Tests-first per §II WordPress Standards**: Every user story has its tests listed BEFORE its implementation tasks (T013-T017 before T018-T026; T027-T031 before T032-T044; T045-T047 before T048-T052). ✅
- **US4 is regression-only**: No new implementation, just T053 regression test + T054 grep verification. Correctly protects FR-042 site-wide cascade. ✅
- **FR-028 race guard is FIRST** in T050: the `oauth_clients_server_id_column_exists()` gate runs BEFORE any DB work or URL parsing. Correct fail-closed ordering. ✅
- **T035 handler task explicitly requires 4-arg do_action fire BEFORE the WP_Error return**: preserves the observability invariant + prevents any listener from seeing a response before the action fires. ✅
- **Grep-gate governance in T062**: 7 explicit grep gates cover every SEC remediation (T062 (d) find_by_client_id_any_server = zero; T062 (e) 4-arg observability action; T062 (f) URL origin verification helper shape; T062 (g) 503 column-existence guard). ✅
- **NOT NULL enforcement is verified at 3 layers**: T013-T015 tests assert `IS_NULLABLE = 'NO'` + assert INSERT without server_id fails with constraint violation; T021-T023 code paths execute the MODIFY; T063 SHOW CREATE TABLE match verifies byte-for-byte alignment. ✅
- **US3 upgrade tests include mid-migration crash simulation** (T013-T015 assertion (e)): verifies D28 idempotency contract survives PHP fatal between callback steps. ✅
- **T054 grep verification for US4 regression protection**: explicit MUST-be-zero check that `revoke_by_user_id` / `delete_by_user_id` signatures unchanged AND `UserLifecycle.php` has no server-scoping code introduced. Locks in FR-042 semantic. ✅
- **T063 SHOW CREATE TABLE byte-for-byte match**: prevents future BerlinDB diff-engine ALTERs on production installs (avoids B34 silent write-loss regression). ✅
- **Memory hygiene (T065-T067) deferred to post-implement**: correct sequencing — memory captures LESSONS from implementation, not before. ✅

## Task-Sequencing Analysis

| Sequencing Property | Status |
|---|---|
| Secure foundations before risky code | ✅ Foundational (schema + row + query helpers) precedes REST validation + DCR handling |
| Tests before implementation within each user story | ✅ Every story lists tests before implementation |
| Cross-story dependencies documented | ✅ tasks.md §User Story Dependencies is explicit |
| Parallel tasks don't bypass security prerequisites | ✅ All [P] tasks touch different files OR are grouped under a coordination note |
| Negative tests + abuse cases included | ✅ T046 attacker-origin test; T029/T030 mismatched-server tests; T053 regression |
| Sensitive features have explicit security checkpoints | ✅ Every SEC remediation has a matching grep gate in T062 |
| No security work hidden in later phases | ⚠️ **SEC-032-T-002 gap** — caller-verification for T032-T034 deferred to T062 (polish); recommend inline mini-grep in each breaking-change task |
| Registration-order coordination explicit | ⚠️ **SEC-032-T-001 gap** — T024 registration order documented but sequenced after T023 body; recommend inline pairing note or boot-time assertion |

## Action Plan & Next Steps

### Remediation Priority

| Priority | Findings | Action |
|---|---|---|
| **Should-fix before implement** | SEC-032-T-001 (MEDIUM) | Add explicit T023↔T024 pairing note OR reorder + add boot-time assertion. ~5-line tasks.md edit. |
| **Nice-to-have before implement** | SEC-032-T-002 (LOW) | Add inline callers-grep line to T032/T033/T034. ~3-line-per-task edit. |
| **Documentation-only** | SEC-032-T-003 (INFO) | Decide: add T050-b for Retry-After header OR defer explicitly with note. ~1-3-line edit. |
| **Solo dev makes this moot** | SEC-032-T-004 (INFO) | Accept as-is; solo dev pattern (per tasks.md §Solo Development Notes) makes parallel-file concern non-applicable. |

### Tasks-phase gate: **PASS with recommendations**

Tasks list is ready for `/speckit-architecture-guard-governed-implement`. The MEDIUM SEC-032-T-001 finding is worth remediating (small edit; prevents an observability-integrity bug on first upgrade run) but does NOT block implementation. The LOW SEC-032-T-002 is a code-hygiene improvement that PHP will surface loudly at runtime if forgotten.

### Extension Hooks

**Recommended next**: `/speckit-architecture-guard-refactor-generator` (next step of parent `governed-tasks` workflow) OR `/speckit-architecture-guard-governed-implement` (proceed to implementation) after applying SEC-032-T-001 remediation.

### Durable Memory Preservation (Mandatory Check)

**One task-sequencing pattern surfaced that may be generalizable**: SEC-032-T-001 illustrates a NEW class of task-review concern — "cross-task runtime dependency" where a task-A body assumes a task-B state exists at runtime even though tasks-list ordering doesn't reflect the dependency. Applies whenever a feature adds cross-Table callbacks + aggregation logic (F029's `Main::reconcile_database_schemas()` established this pattern; F032 extends it with 3-Table coordination).

Candidate durable-memory entry (post-implement, via `/speckit-memory-md-capture-from-diff`):

> **B-CROSS-TASK-RUNTIME-DEPENDENCY-HIDDEN-BY-LIST-ORDER** (Active — Feature 032; generalizable)
> When a task's code body reads runtime state from another task's execution (e.g., F032 T023 reads `OAuthTokens\Table::instance()->get_last_purge_count()` populated by T021), the tasks list ordering MUST reflect the runtime dependency, not just the code-authoring dependency. If task-A's runtime correctness requires task-B's registration change, task-B goes FIRST in the list. Alternative: enforce the dependency at boot via `_doing_it_wrong()` assertion. Applies to any BerlinDB multi-Table upgrade coordination or Loader hook-priority coordination pattern. Companion to D28 (schema-drift reconciliation) + DEC-F020-TOOL-ENFORCEMENT-PRIORITY (hook priority slot map) — this is the task-sequencing corollary.

Single occurrence so far (F032) — DOES NOT yet meet D13 escalation threshold (≥2 features or forward-looking generalizable). Deferring proactive capture to post-implement `/speckit-memory-md-capture-from-diff` step, evaluated alongside F032's confirmed durable lessons. **Not triggering `/speckit-memory-md-capture` this turn.**

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-tasks.md | tasks | 2026-07-21 | MODERATE | C:0 H:0 M:1 L:1 | A09 |
```
