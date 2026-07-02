---
document_type: security-review
review_type: plan
assessment_date: 2026-07-02
codebase_analyzed: acrossai-mcp-manager (Feature 011 — BerlinDB adoption, no backward compatibility)
total_files_analyzed: 9
total_findings: 8
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 3
informational_count: 5
owasp_categories: [A02, A04, A05, A08, A09]
cwe_ids: [CWE-89, CWE-311, CWE-362, CWE-434, CWE-778, CWE-829, CWE-915, CWE-1284]
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

# Security Review — Plan Artifact (Feature 011 / berlindb-migration)

## Executive Summary

Feature 011 is a DB-layer refactor + caller sweep that migrates the four internal DB modules (`MCPServer`, `CliAuthLog`, `OAuthToken`, `OAuthAudit`) from hand-rolled `dbDelta` wrappers to BerlinDB Core 3.0 subclasses. Because the plugin ships to **zero live installs** (spec Clarifications Q4), the feature is authorized to break backward compatibility across table names, `db_version_key` option keys, columns, indexes, public Query API, and Row property names in the same feature branch. The caller sweep touches `includes/OAuth/**`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, and `admin/Partials/CliAuthLogListTable.php`.

Two pre-existing security invariants explicitly survive the rename and are enforced at the plan-phase gate level:

1. **SEC-001 atomic-CAS** for one-shot CLI auth-code redemption (spec FR-006) — the `WHERE id = %d AND <completed_at> IS NULL` predicate and `1 === (int) $wpdb->rows_affected` return contract are preserved regardless of method rename.
2. **SHA-256 hashed-column widths** — every column holding a hashed one-shot credential (`char(64)`) or a PKCE S256 challenge (`char(43)`) retains its exact length even under schema rewrite (spec FR-010). A width-narrowing rename would leak plaintext bits and undermine both credential-uniqueness and PKCE binding.

Three orthogonal boot-time defenses layer at activation: the pre-existing DEV4/D15/B14 `activate_<plugin>` priority-1 pre-guard (`wp_die` on missing `vendor/autoload_packages.php`), a new in-callback `require_once` inside `acrossai_mcp_manager_activate()` (loads the autoloader before the Activator itself loads), and a phantom-version guard on every Table subclass (silent self-heal when a `db_version_key` option is stamped but the physical table is missing).

**Overall risk: LOW** — three LOW findings and five INFORMATIONAL findings. Zero CRITICAL, HIGH, or MEDIUM findings. All three LOW findings are consequences of a single design choice — the compat drop relies on operator attestation ("no live installs exist") that the codebase cannot self-verify — and can be closed with a one-line confirmation from the maintainer before merge.

The plan is implementable as-written. Two findings (SEC-011-002 atomic-CAS PHPUnit gate, SEC-011-006 DEV1 non-widening automation) recommend concrete DoD-gate additions at `/speckit-tasks` T6/T7.

## Plan Artifacts Reviewed

| Path | Notes |
|---|---|
| `specs/011-berlindb-migration/spec.md` | 3 US + 27 FRs + 6 SCs + §Clarifications (4 Q/A from 2026-07-02 including Q4 compat-drop directive) |
| `specs/011-berlindb-migration/plan.md` | Implementation plan; 8 task groups T1..T8; concrete DB naming + column decisions; constitution check PASS |
| `specs/011-berlindb-migration/memory-synthesis.md` | 5 decisions + 5 arch constraints + 2 deviations + 3 security constraints + 3 bug patterns within budget |
| `specs/011-berlindb-migration/security-constraints.md` | Governed-plan orchestrator output (2026-07-02) — this document supersedes with formal review |
| `specs/011-berlindb-migration/architecture-violations.md` | Zero blocking arch violations; A6/A11/A15/B10/B14 flagged LOAD-BEARING |
| `specs/011-berlindb-migration/checklists/requirements.md` | Spec quality checklist — all items pass |
| `docs/planings-tasks/011-berlindb-migration.md` | Source planning doc — 8 TASKs sketched, replaced by plan.md |

Cross-referenced: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE); `docs/memory/INDEX.md` (D9, D7, D4, D6, D15, DEV1, DEV4, S3, S4, S6, B7, B10, B14 selected); sibling plugin reference `../acrossai-abilities-manager/includes/Modules/Abilities/Database/AcrossAI_Abilities_{Table,Schema,Query,Row}.php`; prior security review `docs/security-reviews/2026-07-02-010-composer-dependencies-plan.md` for the SEC-010-001 vendor/-diff gate that Feature 011 inherits.

## Vulnerability Findings

### SEC-011-001 — FR-010 column-width invariant is code-review-only; no static-analysis gate

- **Severity**: LOW
- **CVSS v3.1**: 3.7 (`AV:L/AC:H/PR:H/UI:N/S:U/C:L/I:L/A:N`)
- **OWASP**: A02:2025-Cryptographic Failures
- **CWE**: CWE-311 (Missing Encryption of Sensitive Data — analog for "insufficient hash width")
- **Location**: `specs/011-berlindb-migration/plan.md` §Concrete column decisions (CliAuthLog `auth_code_hash char 64`; OAuthToken `access_token_hash char 64`; CliAuthLog `code_challenge char 43`)
- **Spec-Kit task**: TASK-SEC-011-001

**Problem.** Spec FR-010 fixes cryptographically load-bearing column widths (`char(64)` on both SHA-256 hash columns, `char(43)` on the PKCE S256 challenge) but PHPStan level 8 and PHPCS cannot inspect Schema-array literals for width drift. A refactor that silently narrows `auth_code_hash` to `char(48)` would (a) truncate stored hashes so `WHERE auth_code_hash = %s` misses actual matches and (b) reduce the per-hash entropy from 256 bits to 192 bits, weakening collision resistance in the one-shot-code redemption path. Same class of failure applies to the PKCE `code_challenge` — a narrowed column truncates the client-provided challenge and breaks OAuth PKCE binding entirely.

**Plan mitigations.** Spec FR-010 declares the invariant; plan §Concrete column decisions repeats the widths at plan level; the sibling plugin's Schema arrays serve as byte-for-byte reference.

**Residual risk.** A reviewer who does not know the invariant could rubber-stamp a width change during T2..T5 code review.

**Remediation.**
1. Add a PHPUnit assertion at plan `ColumnWidthInvariantTest.php` (or extend `AtomicCasTest.php`) that reads the Schema `$columns` array and asserts the byte-level widths of the four cryptographically-bound columns.
2. Add a reviewer-visible callout in each of the T2..T5 task descriptions when `/speckit-tasks` writes them: "verify `char(64)` on hashed columns per FR-010 before approving."

### SEC-011-002 — SEC-001 atomic-CAS semantic contract has no plan-time PHPUnit spec beyond a filename

- **Severity**: LOW
- **CVSS v3.1**: 3.3 (`AV:N/AC:H/PR:L/UI:N/S:U/C:N/I:L/A:N`)
- **OWASP**: A04:2025-Insecure Design
- **CWE**: CWE-362 (Concurrent Execution using Shared Resource with Improper Synchronization — "Race Condition")
- **Location**: `specs/011-berlindb-migration/plan.md` §Task Groups T3 gate ("above + `AtomicCasTest` passes"); spec FR-006
- **Spec-Kit task**: TASK-SEC-011-002

**Problem.** The plan names a test file `AtomicCasTest.php` but does not specify the test's minimum assertion set. `/speckit-tasks` might write it as a single-caller "assert `true` returned once" test, which would pass even if the underlying method drops the `IS NULL` guard and simply toggles the column unconditionally. The BUGS.md B10 threat model is "two concurrent HTTP hits redeem the same one-shot code" — a test that does not simulate concurrent invocation cannot catch a check-then-act regression.

**Plan mitigations.** Spec FR-006 codifies the semantic contract in prose. Constitution §III mandates `$wpdb->prepare()`. Sibling plugin reference has no equivalent test to crib from (their abilities table has no one-shot redemption path).

**Residual risk.** A test that seeds one row and invokes the method twice sequentially would pass against a broken `check-then-act` implementation (the second call would just be a no-op because `completed_at` is already stamped). Only a test that simulates concurrent-branch invocation OR that intercepts the SQL predicate can catch the regression.

**Remediation.**
1. When `/speckit-tasks` writes T3's DoD, add the following minimum assertions to `AtomicCasTest.php`:
   - **A**: Seed one row with `completed_at = NULL`. Invoke the redeem method. Assert `true` returned AND row's `completed_at IS NOT NULL` in DB.
   - **B**: On the same row, invoke redeem AGAIN. Assert falsy returned (idempotent no-op).
   - **C**: SQL-predicate assertion — use `$wpdb->last_query` or a `dbDelta`-log capture to assert the executed statement matches the pattern `UPDATE %i SET completed_at = %s WHERE id = %d AND completed_at IS NULL` verbatim (the `AND completed_at IS NULL` clause is the atomic-CAS guarantee).
2. Cite BUGS.md B10 in the test-file docblock so a future refactor sees the durable rationale.

### SEC-011-003 — Caller sweep is a mass-assignment (B7) regression window; grep gate `add_item\s*(\s*\$_POST` is heuristic-only

- **Severity**: LOW
- **CVSS v3.1**: 4.3 (`AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:L/A:N`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-915 (Improperly Controlled Modification of Dynamically-Determined Object Attributes — "Mass Assignment")
- **Location**: `specs/011-berlindb-migration/architecture-violations.md` §Recommendations for `/speckit-tasks` (T7 grep gate)
- **Spec-Kit task**: TASK-SEC-011-003

**Problem.** The caller sweep touches `includes/OAuth/**`, `includes/REST/CliController.php`, `includes/MCP/Controller.php`, `includes/Database/CliAuthLog/Recorder.php`, and `admin/Partials/CliAuthLogListTable.php`. BerlinDB Core's base `Query::add_item()` and `Query::update_item()` do NOT filter their input arrays against the Schema `$columns` list — an unsanitized `$_POST` or `$request->get_params()` array passed directly to `add_item` allows a client to write arbitrary columns (mass-assignment per BUGS.md B7). The plan's T7 grep gate `grep -rn 'add_item\s*(\s*\$_POST' ...` catches the most literal form of this bug but does NOT catch:

- Intermediate variables: `$data = $_POST; $query->add_item( $data );`
- `WP_REST_Request::get_params()` handed straight through: `$query->add_item( $request->get_params() );`
- `$request->get_json_params()`, `$_REQUEST`, etc.

**Plan mitigations.** Sibling plugin uses a column-allowlist filter before `add_item`; constitution §III mandates sanitization at boundaries. This is a durable pattern the plugin has already internalized in the pre-migration Query classes (verified via `includes/Database/CliAuthLog/Recorder.php`).

**Residual risk.** A T7 caller-edit that removes the pre-migration column-filter wrapper "because BerlinDB will handle it" would silently introduce B7 without tripping the grep gate.

**Remediation.**
1. Broaden T7's grep gate to catch more forms:
   ```
   grep -rEn '(add_item|update_item)\s*\(\s*(\$_POST|\$_REQUEST|\$_GET|\$request->get_(json_)?params\s*\(\s*\))' \
       --include='*.php' includes/ admin/ public/
   ```
   Expected: zero matches.
2. Add an assertion to the T7 code-review checklist: "every add_item/update_item call was reviewed for column-allowlist filtering — cite the filter helper used or explain why the input is trusted."
3. Consider adding a WPCS `WordPress.DB.PreparedSQL` custom sniff OR a project-local PHPStan extension that flags `add_item($_POST)` patterns at static-analysis time. Defer to a follow-up feature — not blocking Feature 011.

### SEC-011-004 — Compat-drop premise is an operator attestation; no codebase self-verification

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.7 (`AV:L/AC:H/PR:H/UI:R/S:U/C:L/I:L/A:N`)
- **OWASP**: A09:2025-Security Logging and Monitoring Failures (analog for "no operator visibility into scope premise")
- **CWE**: CWE-1284 (Improper Validation of Specified Quantity in Input) — analog for "premise validated by attestation, not measurement"
- **Location**: `specs/011-berlindb-migration/spec.md` §Clarifications Q4 + §Assumptions ("No live installs of the plugin exist.")
- **Spec-Kit task**: TASK-SEC-011-004

**Problem.** Feature 011's authorization to rename tables, options, columns, and public API depends entirely on the premise that no site has installed and populated the pre-migration schema in production. This premise is captured as an operator attestation in the spec's Clarifications and Assumptions sections. If the attestation is wrong (a developer installed the plugin on a client staging site months ago and populated real OAuth tokens for testing), the Feature 011 activation on that site will:

- Leave the pre-migration `wp_acrossai_mcp_*` tables in place (unowned by any Query class after the rename) — orphaned rows persist indefinitely, potentially containing hashed OAuth access tokens
- Create parallel new-schema tables — state divergence between "old orphaned data" and "new fresh install"
- Silently, with no operator-visible warning

**Plan mitigations.** Spec §Clarifications Q4 records the attestation with a date. Governed-plan security-constraints.md already flagged this as RECOMMEND #1.

**Residual risk.** Attestations decay. A developer who spun up a test install six weeks ago and forgot may not recall to unwind their site before Feature 011 ships.

**Remediation.**
1. Merge-gate ask (one-liner to the maintainer): "confirm before merge that no site under the AcrossAI team's control has this plugin activated against real MCP server data or real OAuth-issued tokens outside `~/local-sites/`."
2. Optional operator-safety belt: add a one-time WP-CLI command `wp acrossai-mcp:pre-011-scan` that scans an install for the pre-migration table names + option keys and prints a summary. Zero result = safe to activate Feature 011. Defer to a follow-up feature IF the operator confirms uncertainty about existing installs.

### SEC-011-005 — OAuthToken `active_only` PHP-filter is applied AFTER LIMIT; paginated audits may miss active tokens

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.7 (`AV:N/AC:H/PR:H/UI:N/S:U/C:L/I:N/A:N`)
- **OWASP**: A09:2025-Security Logging and Monitoring Failures
- **CWE**: CWE-1284 (Improper Validation of Specified Quantity in Input) — analog for "filter counts diverge from LIMIT-implied counts"
- **Location**: `specs/011-berlindb-migration/spec.md` §Clarifications Q3 + FR-008; sibling plan `specs/011-berlindb-migration/plan.md` §Task Groups T4
- **Spec-Kit task**: TASK-SEC-011-005

**Problem.** Spec FR-008 (locked in by Clarification Q3) implements the OAuthToken `active_only` filter as a post-`parent::query()` PHP `array_filter()` on the returned Row set. BerlinDB's LIMIT clause is applied inside `parent::query()` — so a caller invoking `Query::query( [ 'active_only' => true, 'per_page' => 20 ] )` receives up to 20 rows AFTER the PHP filter drops revoked/expired ones, meaning the effective result set may be arbitrarily smaller than 20. If an operator writes a paginated audit view that expects "20 active tokens per page," pages may look sparse or empty for revoked-heavy datasets, and cross-page counts will diverge from operator expectations.

**Plan mitigations.** Spec Assumption documents that the pre-migration caller matrix does not combine `active_only` with pagination; the caller sweep is expected to preserve this. The Assumption explicitly notes that a future paginated `active_only` caller is a follow-up feature.

**Residual risk.** A future feature that adds paginated `active_only` (e.g., an admin token-audit screen) will silently under-count.

**Remediation.**
1. Add a docblock warning on the OAuthToken `Query::query()` override that explicitly forbids combining `active_only` with `per_page` / `paged` / `number` until the filter is migrated to a BerlinDB `Where`-operator push-down. Cite spec Assumption + Clarification Q3.
2. Optional: a `wp_trigger_error()` fallback that fires when both `active_only` and any pagination-bearing arg are present, in `WP_DEBUG` builds only.

### SEC-011-006 — DEV1 non-widening grep gate is coarse (extends-count = 1 does not preclude DataViews import drift)

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 2.0 (`AV:L/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:N`)
- **OWASP**: A05:2025-Security Misconfiguration
- **CWE**: CWE-778 (Insufficient Logging — analog for "gate does not catch a partial-boundary widening")
- **Location**: `specs/011-berlindb-migration/architecture-violations.md` §Recommendations for `/speckit-tasks` T7 DoD ("grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php == 1")
- **Spec-Kit task**: TASK-SEC-011-006

**Problem.** The plan's DEV1 non-widening gate on `admin/Partials/CliAuthLogListTable.php` is a single grep for the `extends WP_List_Table` string. A drive-by refactor that:

- keeps the class extending `WP_List_Table` (grep still returns 1), AND
- adds `use WP_Block_Editor_Context;` or `use @wordpress/dataviews;` or wires new DataViews-adjacent React glue at the top of the file

would evade the grep and silently widen DEV1's scope by mixing DataViews infrastructure into a `WP_List_Table` file — the constitution §IV DEV1 exemption authorizes the current shape, not a hybrid.

**Plan mitigations.** Spec FR-021 codifies the review-time rejection of DataViews imports in this file. Constitution §IV explicitly names DEV1 as "not extending to any future screen" — DEV1 widening is a governance escalation.

**Residual risk.** A caller-sweep commit that imports a DataViews helper "just for a small feature" would land under the grep gate.

**Remediation.**
1. Broaden T7's DEV1 gate to check for DataViews and DataForm imports as well:
   ```
   grep -c 'extends WP_List_Table' admin/Partials/CliAuthLogListTable.php   # expected: 1
   grep -Ec 'use\s+.*\\?(DataViews|DataForm|dataviews)' admin/Partials/CliAuthLogListTable.php   # expected: 0
   ```
2. Add a T7 code-review callout: "this file is under DEV1 exception; any new import statement must be justified in the commit message."

### SEC-011-007 — Phantom-version guard silent-fires give operators no signal for a slow-corruption bug

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 1.9 (`AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:L`)
- **OWASP**: A09:2025-Security Logging and Monitoring Failures
- **CWE**: CWE-778 (Insufficient Logging)
- **Location**: `specs/011-berlindb-migration/spec.md` §Clarifications Q1 + FR-018 (silent guard)
- **Spec-Kit task**: TASK-SEC-011-007

**Problem.** Clarification Q1 mandates silent guard operation (no `error_log`, no admin notice, no transient). This matches the sibling plugin's canonical shape and is the correct default for a rare self-heal event. However, if a future bug causes the guard to fire on EVERY activation (e.g., some other code path deletes the table between activations), operators have no signal. The guard silently recreates the table each time, mask the underlying bug, and expose downstream users to intermittent "no server list" behaviour that's hard to diagnose.

**Plan mitigations.** No explicit mitigation in the plan; Clarification Q1 was a deliberate choice matching sibling shape.

**Residual risk.** Ops philosophy question, not a security failure. If the plugin scales to many operators / support tickets, revisit.

**Remediation.**
1. No plan-phase action — silent operation is the correct default per Clarification Q1.
2. If operator support requests emerge post-release, revisit: a WP-CLI `wp acrossai-mcp:table-health-check` command could enumerate stamped-but-missing table pairs on demand. Follow-up feature.

### SEC-011-008 — BerlinDB Core 3.0 supply-chain integrity is inherited from Feature 010's vendor-diff gate

- **Severity**: INFORMATIONAL
- **CVSS v3.1**: 3.0 (`AV:L/AC:H/PR:H/UI:N/S:U/C:L/I:L/A:N`)
- **OWASP**: A08:2025-Software and Data Integrity Failures
- **CWE**: CWE-829 (Inclusion of Functionality from Untrusted Control Sphere) + CWE-1104 (Use of Unmaintained Third Party Components)
- **Location**: Feature 010's `composer.json` + `composer.lock` (`berlindb/core: ^3.0.0`); Feature 011 does not touch these files
- **Spec-Kit task**: TASK-SEC-011-008

**Problem.** Feature 011 runtime-depends on `berlindb/core: ^3.0.0` for every DB path in the plugin. Compromise of the package would poison every `add_item`/`update_item`/`query` call. Feature 010 (composer-dependencies) established the plugin's supply-chain gate (`composer.lock` pin + `git diff --exit-code vendor/` in CI per SEC-010-001). Feature 011 does not weaken or bypass those gates but also does not strengthen them; the inherited gate remains the sole line of defense.

**Plan mitigations.** Feature 010's SEC-010-001 gate. Sibling plugin `acrossai-abilities-manager` also depends on the same package version, providing an independent-project sanity check.

**Residual risk.** Same as SEC-010-001; scope inherited, not new to Feature 011.

**Remediation.** None specific to Feature 011. See Feature 010's SEC-010-001 for the vendor-diff gate.

## Confirmed Secure Patterns

The following pre-existing security patterns are explicitly preserved by Feature 011 and are verified against the plan/spec:

1. **SEC-001 atomic-CAS** for one-shot CLI auth-code redemption — spec FR-006 preserves the `WHERE id = %d AND <completed_at> IS NULL` predicate and `1 === (int) $wpdb->rows_affected` return contract. Gate: `AtomicCasTest.php` (SEC-011-002 recommends assertion set).
2. **SHA-256 hashed-column widths** — spec FR-010 fixes `char(64)` on `auth_code_hash` and `access_token_hash`; hashed columns remain the only representation of stored credentials, never plaintext (constitution §III S3).
3. **PKCE S256 challenge-column width** — spec FR-010 fixes `char(43)` on `code_challenge` — preserves PKCE client-server binding integrity.
4. **`$wpdb->prepare()` on every DB path** — memory-synthesis S4 preserved; bespoke methods (redeem, purge) explicitly declare prepared statements; BerlinDB base class uses prepare internally.
5. **`register_activation_hook` P1 pre-guard on missing vendor** — DEV4/D15/B14 chain preserved verbatim by spec FR-012; extended with the new in-callback autoload require (FR-011). Defense in depth against activation-time missing-vendor fatals.
6. **REST `permission_callback` explicit on every mutating route** — governed by Feature 006 (`includes/REST/CliController.php`), unchanged. Sweep touches only Query/Row identifier references inside the file, not the routes themselves.
7. **`current_user_can( 'manage_options' )` on admin page renders** — governed by `admin/Partials/Menu.php` / `admin/Partials/Settings.php`, unchanged.
8. **DEV1 `WP_List_Table` boundary** — spec FR-021 codifies review-time rejection of DataViews imports in `admin/Partials/CliAuthLogListTable.php`; SEC-011-006 recommends grep-gate broadening.
9. **Singleton `__construct()` private** — memory-synthesis S6 preserved; new Table/Query singletons follow sibling plugin's private-ctor shape.
10. **`class_exists( '\WP\MCP\Plugin' )` guard** — Feature 009's MCP Controller guard preserved (caller sweep touches only Query/Row identifiers inside the file, not the guard).

## Action Plan & Next Steps

### Durable Memory Preservation

One insight from this review is capture-worthy but staged for a later point:

- **The compat-drop attestation-based-scope pattern (SEC-011-004)** — Feature 011 authorizes destructive rename operations based on an operator attestation that no live installs exist. This is a reusable pattern for future features that consider compat-drop refactors. A memory entry along the lines of:

  > *"When a feature's authorization to break backward compatibility depends on an operator attestation about install-base state, add a merge-gate confirmation from the maintainer AND consider a one-time WP-CLI scan command as the operator-safety belt. Attestations decay; codebase self-verification does not."*

is worth capturing as a future memory decision (e.g. **DEC-COMPAT-DROP-ATTESTATION-GATE**).

**Deferring `/speckit-memory-md-capture` invocation to Feature 011's TASK-8 (post-implementation memory hygiene)** — same reasoning as governed-plan Step 6. The capture point is after the feature ships; capturing at plan-phase risks recording an insight that gets rejected during implementation. Spec FR-023 codifies the TASK-8 capture step.

### Remediation Planning

No CRITICAL or HIGH findings. Three LOW findings (SEC-011-001, -002, -003) recommend concrete gate additions at `/speckit-tasks` T3, T4, T5, and T7. Five INFORMATIONAL findings are advisory.

**Recommended next command**: `/speckit-tasks` — the task decomposition should encode:
- **T3 DoD**: assertion set for `AtomicCasTest.php` per SEC-011-002 (three-assertion minimum: idempotent no-op, DB-side `completed_at IS NOT NULL`, predicate assertion via `$wpdb->last_query`).
- **T4 DoD**: docblock warning on OAuthToken `Query::query()` override per SEC-011-005.
- **T7 DoD**: broadened B7 mass-assignment grep per SEC-011-003; broadened DEV1 non-widening grep per SEC-011-006; reviewer callout for FR-010 column-width verification per SEC-011-001.
- **T0 (new — pre-flight)**: merge-gate ask for the compat-drop attestation per SEC-011-004.

If any of these findings escalate during implementation (e.g., an actual live install is discovered), run `/speckit-security-review-followup` to open remediation tasks.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-02-011-berlindb-migration-plan.md | plan | 2026-07-02 | LOW | C:0 H:0 M:0 L:3 | A02,A04,A05,A08,A09 |
```
