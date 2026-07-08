---
document_type: security-review
review_type: tasks
assessment_date: 2026-07-09
codebase_analyzed: acrossai-mcp-manager (Feature 020 — per-server-tool-selection, tasks phase)
total_files_analyzed: 8
total_findings: 5
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 3
informational_count: 2
owasp_categories: [A04, A05, A09]
cwe_ids: [CWE-693, CWE-754, CWE-1188]
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

# Security Review — Feature 020: Per-Server Tool Selection (Tasks Phase)

**Feature branch**: `020-per-server-tool-selection`
**Reviewer**: `/speckit-security-review-tasks` (automated)
**Tasks artifact hash**: `tasks.md` @ 2026-07-09 — 65 tasks across 6 phases (Setup 3, Foundational 7, US1 27, US2 4, US3 3, Polish 21)

## Executive Summary

The task list is **substantially security-complete**. Every finding from the two prior plan-phase security reviews (SEC-020-001..011) traces to a named, file-scoped task with acceptance criteria. Sequencing is correct — Foundational lands BerlinDB module before any user story starts; the enforcement gate + cascade cleanup live inside US1 (not deferred to Polish) because US1's UX promise requires them; TDD ordering places test scaffolds T011..T016 ahead of the implementation tasks they exercise; grep gates in T051..T057 cover every architectural invariant.

Coverage tally: 18 explicit references to SEC-020-* / FR-026..031 / SC-011..014 identifiers across the 65 tasks; 10 references to core security primitives (`manage_options`, nonce, `wp_get_abilities`, `sanitize_text_field`, `esc_html*`, opt-in gate, try/catch, `error_log`).

Five minor findings remain — all LOW or INFORMATIONAL — clustered around task granularity, missing dependency edges, and manual-vs-automated verification. None block implementation. Each is addressable with a small tasks.md edit before `/speckit-implement` runs, or absorbed into the implementation phase without impact.

## Tasks Reviewed

| Path                                              | Purpose                                              |
|---------------------------------------------------|------------------------------------------------------|
| `specs/020-per-server-tool-selection/tasks.md`    | 65 implementation tasks (this review)                |
| `specs/020-per-server-tool-selection/plan.md`     | Cross-referenced for architecture decisions          |
| `specs/020-per-server-tool-selection/spec.md`     | Cross-referenced for FR / SC coverage                |
| `specs/020-per-server-tool-selection/data-model.md` | Cross-referenced for entity + query shape            |
| `specs/020-per-server-tool-selection/contracts/rest-api.md` | REST contract coverage in T017..T020       |
| `specs/020-per-server-tool-selection/contracts/enforcement.md` | Enforcement gate coverage in T021..T024, T014 |
| `specs/020-per-server-tool-selection/contracts/js-hooks.md` | Extensibility contract coverage in T032         |
| `docs/security-reviews/2026-07-09-020-per-server-tool-selection-plan-v2.md` | Cross-referenced for SEC-020-* task traceability |

## Verification Sweep — Prior Findings

| Prior Finding | Task(s) Providing Closure | Verification Status |
|---------------|---------------------------|--------------------|
| **SEC-020-001** (runtime enforcement missing) | T021, T022, T023, T024, T014 (10-scenario coverage) | Named as line-for-line copy of F017 `AbilityExposureGate.php:98-119`; priority 30 hard-coded in T024 |
| **SEC-020-002** (concurrent race) | T007 (`replace_set` TX+FOR UPDATE), T011 (concurrent-race guard test) | Test explicitly spawns two parallel `replace_set` calls with fresh DB connections |
| **SEC-020-003** (deletion-hook name deferred) | T035 (wire `mcp_server_deleted`), T036 (cascade test) | Concrete action name in tasks.md; both admin caller paths covered |
| **SEC-020-004** (observer 500 risk) | T013 (observer-throws test), T020 (controller try/catch loop) | Explicit "register a mu-plugin observer that throws, POST returns 200" test |
| **SEC-020-005** (POST args schema) | T018 (explicit args schema on both server_id + tools) | Both path + body params in one args block |
| **SEC-020-006** (actor identity omission) | Accepted trade-off per plan.md; T060 captures decision in DECISIONS.md | No task change required |
| **SEC-020-007** (wrong vendor class) | T014 Test 10 (SEC-020-007 regression guard), T022 (method_exists shape), T056 (grep gate against `instanceof \WP\MCP\Server`) | Three-layer defense: contract + test + CI grep gate |
| **SEC-020-008** (TX isolation) | T007 (FOR UPDATE row-range lock), T011 (concurrent-race guard) | SELECT ... FOR UPDATE explicit in task body |
| **SEC-020-009** (server_id args schema) | T018 (server_id in args block with positive-int validate_callback) | Merged into same args block as body param |
| **SEC-020-010** (500 body leak) | T013 (assert response body has no exception detail), T020 (generic 500 message) | Body composition documented in contract + tested |
| **SEC-020-011** (cascade per-row loop) | T007 (`delete_items_for_server` = single `$wpdb->delete()` + `wp_cache_flush_group`) | Explicit in task body |

**All 11 findings closed at the task level.** Implementation-phase quality gates in T045..T057 (PHPCS + PHPStan L8 + ESLint + Jest + PHPUnit + 8 grep gates) will catch any drift during implementation.

## Vulnerability Findings

---

### SEC-020-T-001 — T009 (request-time boot) has no explicit dependency edge on T008 (activation boot)

- **finding_id**: SEC-020-T-001
- **location**: `specs/020-per-server-tool-selection/tasks.md` §Task-Level Dependencies within Phase 3 — lists T004→T006 and T004→T007 but not T008 ↔ T009
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-1188: Insecure Default Initialization of Resource
- **cvss_score**: 3.7 (LOW — vector: `AV:N/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:L`)
- **spec_kit_task**: TASK-SEC-020-T-001

**Description**

T008 wires `MCPServerTool\Table::instance()->maybe_upgrade()` inside `Activator::activate()`. T009 wires `\AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table::instance();` inside `Main::bootstrap_database_tables()`. Per DEC-BERLINDB-TABLE-REQUEST-BOOT (Active F011), **both** are required — activation-only boot leaves BerlinDB's DB interface empty on subsequent requests, causing Query to fall back to `$table_alias` as FROM and produce "Table doesn't exist" errors. The dependency edge is bidirectional: if a developer commits T008 without T009 (or vice versa), the plugin ships in a broken state.

The task list correctly names both tasks and references DEC-BERLINDB-TABLE-REQUEST-BOOT, but the Task-Level Dependencies section does not explicitly bind them. A time-slicing developer could push T008 in one commit and defer T009, exposing the failure mode between commits.

**Impact**

Not a security exploit — this is a reliability / correctness sequencing issue with security surface: a broken boot means the enforcement gate T024 also fails (falls open on every call because MCPServerQuery reads return empty). The Tools tab silently degrades to UI-only theater.

**Recommendation**

Add to `tasks.md` §Task-Level Dependencies within Phase 3:

> **T008 ↔ T009**: MUST land in the same commit. Committing T008 without T009 leaves BerlinDB's DB interface empty at request time (DEC-BERLINDB-TABLE-REQUEST-BOOT). Committing T009 without T008 fails on fresh activation because the table hasn't been created.

Not blocking — but a small clarification prevents a well-known regression class.

---

### SEC-020-T-002 — No test for `wp_get_abilities()` graceful degradation in `ToolsTab::render_body`

- **finding_id**: SEC-020-T-002
- **location**: `specs/020-per-server-tool-selection/tasks.md` T028 (tab render swap); no companion test task
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-754: Improper Check for Unusual or Exceptional Conditions
- **cvss_score**: 3.1 (LOW — vector: `AV:N/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:L`)
- **spec_kit_task**: TASK-SEC-020-T-002

**Description**

Spec FR-019 mandates that the Tools tab MUST render an inline error notice when `wp_get_abilities()` is unavailable, and MUST NOT fatal. T028 correctly implements this — the render_body flow checks `function_exists( 'wp_get_abilities' )` and renders `notice notice-error`. Quickstart Step 9 Test B exercises this manually.

**But there is no automated PHPUnit test.** T011..T014 cover the query layer, REST controller, and enforcement gate; no task covers the render_body degradation path. If a future refactor accidentally removes the guard, no CI signal fires.

**Recommendation**

Add a new task T028a (or extend T028's acceptance criteria) to include:

> Create `tests/phpunit/Admin/Partials/ServerTabs/ToolsTabTest.php` covering: (1) render_body outputs mount div when server is enabled + wp_get_abilities exists, (2) render_body outputs disabled-server notice when `$server['is_enabled']` is falsy, (3) render_body outputs "Abilities API unavailable" error notice when `wp_get_abilities` doesn't exist (using a runkit or namespaced function stub for isolation).

Not blocking — but a small test task prevents FR-019 from silently regressing.

---

### SEC-020-T-003 — T057 uses "manual inspection" for uninstall.php ordering gate

- **finding_id**: SEC-020-T-003
- **location**: `specs/020-per-server-tool-selection/tasks.md` T057 (uninstall grep gate)
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-693: Protection Mechanism Failure
- **cvss_score**: 2.4 (LOW — vector: `AV:L/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-020-T-003

**Description**

T057 says: *"Manual inspection confirms placement."* The task verifies that the `DROP TABLE` statement exists in `uninstall.php` (grep produces 1 match) but relies on a human to visually confirm the statement lives BELOW the `acrossai_mcp_uninstall_delete_data` opt-in gate. If a future refactor moves the gate or the DROP, no automated signal fires.

DEC-UNINSTALL-OPT-IN-GATE is a mandatory Constitution-adjacent invariant (WordPress.org guideline #5). Manual verification is a weak gate for a strong invariant.

**Recommendation**

Replace T057 with a scripted grep that verifies line ordering:

```sh
# Extract line number of the opt-in gate:
GATE_LINE=$(grep -n "'acrossai_mcp_uninstall_delete_data'" uninstall.php | head -1 | cut -d: -f1)
# Extract line number of the DROP TABLE:
DROP_LINE=$(grep -n 'DROP TABLE.*acrossai_mcp_server_tools' uninstall.php | head -1 | cut -d: -f1)
# Assert DROP > GATE:
test "$DROP_LINE" -gt "$GATE_LINE" || exit 1
```

Or run PHPStan with a custom rule that flags any destructive SQL statement above the gate. Even a simple `awk` script comparing line numbers is preferable to human eyeballing.

Not blocking — but automatable and worth doing before implementation.

---

### SEC-020-T-004 — T007 combines four responsibilities into one task

- **finding_id**: SEC-020-T-004
- **location**: `specs/020-per-server-tool-selection/tasks.md` T007 (Query class, get_added_slugs, replace_set, delete_items_for_server all combined)
- **owasp_category**: N/A (process / TDD granularity)
- **cwe**: N/A (not a vulnerability)
- **cvss_score**: 0.0 (INFORMATIONAL — no security impact directly)
- **spec_kit_task**: TASK-SEC-020-T-004

**Description**

T007 combines: (a) Query class scaffold with BerlinDB properties, (b) singleton + private constructor, (c) `get_added_slugs()`, (d) `replace_set()` with FOR UPDATE + TX + try/catch + ROLLBACK, (e) `delete_items_for_server()` with single-statement + cache flush. Five distinct pieces of security-relevant logic in one task.

TDD would normally split these — each helper has an independent test in T011, and each has an independent failure mode. Combining them means one commit lands a lot of code with entangled review scope.

**Impact**

Not a vulnerability. Increases the risk that a code reviewer misses a subtle transaction-shape bug (e.g., forgetting `ROLLBACK` on catch, wrong FOR UPDATE placement) because the diff is large enough that TX vs non-TX methods aren't easy to distinguish visually. Similar concern for T020 (which combines validation + replace_set + action fire + try/catch + flush_cache).

**Recommendation**

Optionally split T007 into T007a (Query class scaffold + singleton), T007b (`get_added_slugs`), T007c (`replace_set` with TX), T007d (`delete_items_for_server`). Same for T020.

Or accept the current granularity and enforce review discipline: reviewers of the T007/T020 commits MUST verify the TX shape + observer isolation + cache flush independently, not as blocks.

Not blocking — process-level suggestion.

---

### SEC-020-T-005 — No task for verifying B22 runtime store lookup fallback

- **finding_id**: SEC-020-T-005
- **location**: `specs/020-per-server-tool-selection/tasks.md` T030 (mount + fetch abilities); no explicit test of the runtime-store fallback path
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures (via missing test coverage)
- **cwe**: CWE-754: Improper Check for Unusual or Exceptional Conditions
- **cvss_score**: 0.0 (INFORMATIONAL — no security impact)
- **spec_kit_task**: TASK-SEC-020-T-005

**Description**

T030 implements the F017-parallel pattern: prefer `wp.data.select('core/abilities').getAbilities()` at runtime, fall back to REST-provided `abilities` array. Bug pattern B22 (from memory INDEX) warns that `@wordpress/*` v0.x packages need runtime string-key lookup because they're not yet in `@wordpress/scripts` externals map.

There is no explicit test verifying the fallback fires when the runtime store returns undefined. If a future WP core refactor changes the `core/abilities` store key, the fallback path exists but is never actually exercised in CI — only in production. The test in T015 covers `diffDraftAgainstAdded` (pure helper), and T016 covers `safeApplyFilters` — neither exercises the mount fetch path.

**Recommendation**

Optionally add a Jest test to T015 or a new task:

> Test: mock `useSelect` to return an empty array; assert the mount falls through to the REST-provided `abilities` array and hydrates from it. Second test: mock `useSelect` to return an array with entries; assert the mount prefers the store over the REST payload.

Not blocking — coverage-completeness item.

---

## Confirmed Secure Patterns

- **REST authorization** — T018 pins `current_user_can( 'manage_options' )` on both routes; T013 negative test for 403 without capability.
- **REST input validation** — T018 explicit `args` schema for both `server_id` (positive-integer) and `tools` (array of sanitized strings); T020 catalog-membership check via `wp_get_abilities()`; T013 negative test for 400 on unknown slug.
- **Excluded slugs defense-in-depth** — T017 defines `EXCLUDED_SLUGS` on controller, T021 mirrors on gate; T020 rejects excluded slugs in POST; T014 tests gate-side bypass; T029 mirrors on JS.
- **DB safety** — T004 defines schema with UNIQUE constraint; T007 wraps `replace_set` in TX with FOR UPDATE; T011 concurrency test verifies determinism.
- **Prepared statements** — T004..T007 use BerlinDB inherited prepared layer; T007 `delete_items_for_server` uses `$wpdb->delete( table, where, format )` (prepared).
- **Uninstall gate honored** — T010 explicitly places DROP + option-delete BELOW the F012 opt-in short-circuit; T057 grep gate verifies placement.
- **Cascade cleanup** — T035 wires `mcp_server_deleted`; T036 tests the cascade AND the no-op-on-failed-delete semantic.
- **Runtime enforcement (SEC-020-001 substantive closure)** — T021..T024 land the gate; T014 covers 10 scenarios including SEC-020-007 anti-regression; T024 hard-codes priority 30.
- **Observer isolation** — T020 wraps each `do_action` in try/catch with `error_log`; T013 tests observer-throws-swallowed.
- **500 response body** — T020 returns generic `acrossai_mcp_tools_save_failed` with no exception detail; T013 tests the leak-prevention invariant.
- **Nonce enforcement** — T026 seeds nonce middleware in localize; T029 wires it via `apiFetch.createNonceMiddleware`.
- **B17 rest_url trailing slash** — T026 explicitly wraps `rest_url()` with `untrailingslashit`.
- **B21 BerlinDB flag** — T004 uses `'modified' => true`; T052 grep gate against `'date_updated'`.
- **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION** — T004..T007 extend Kern parents via leading-`\` FQN; T054 grep gate.
- **DEC-WP-DATAVIEWS-OVER-REACT forbidden libs** — T053 grep gate over `src/js/tools.js`.
- **F017 architectural independence** — T055 grep gate ensures F020 code doesn't reference F017 module/controller.
- **Retired-helper cleanup** — T028 deletes `get_core_tools` + `render_tools_table`; T051 grep gate.
- **TDD ordering** — T011..T016 test scaffolds precede T017..T037 implementation tasks in Phase 3 sequencing.
- **Quality gates in Polish** — T045..T050 run PHPCS + PHPStan L8 + ESLint + Jest + PHPUnit against the whole feature.
- **Quickstart validation** — T058 executes all 10 steps end-to-end covering SC-001..014.

---

## Action Plan & Next Steps

### Blocking before `/speckit-implement`

**None.** All prior HIGH+MEDIUM findings from the plan phase are closed at the task level. Task-phase findings are all LOW or INFORMATIONAL.

### Recommended before `/speckit-implement`

1. **SEC-020-T-001 (LOW)**: Add explicit T008 ↔ T009 dependency binding to `tasks.md §Task-Level Dependencies`. One sentence — "MUST land in the same commit". Prevents a well-known regression class from re-entering.
2. **SEC-020-T-002 (LOW)**: Add a companion test task for T028 covering the `wp_get_abilities()` graceful-degradation branch. Small addition.
3. **SEC-020-T-003 (LOW)**: Replace T057's "manual inspection" language with a scripted grep+awk that verifies DROP line > gate line. Automatable in ~10 lines of shell.

### Recommended during implementation

4. **SEC-020-T-004 (INFO)**: Reviewer discipline — when T007 / T020 land, verify TX + observer isolation + cache flush independently. Or split into subtasks.
5. **SEC-020-T-005 (INFO)**: Add Jest test for runtime-store-vs-REST fallback branch in T030. Small addition.

### Durable Memory Preservation

**Deferred**. No new systemic security patterns identified in this task-phase review that don't already exist in memory. The one candidate — SEC-020-T-001's "table subclass activation-boot vs request-boot must land together" — is already captured by DEC-BERLINDB-TABLE-REQUEST-BOOT. No new decision required.

### Remediation Planning

Recommend running `/speckit-security-review-followup` if the recommendations above are not folded into `tasks.md` directly. Otherwise, `/speckit-implement` can proceed after applying the three LOW-severity fixes (SEC-020-T-001..T-003) with minimal churn.

---

## Memory Hub INDEX.md Row

Paste into `docs/memory/INDEX.md` §Security Reviews:

```text
| docs/security-reviews/2026-07-09-020-per-server-tool-selection-tasks.md | tasks | 2026-07-09 | LOW | C:0 H:0 M:0 L:3 | A04,A05,A09 |
```
