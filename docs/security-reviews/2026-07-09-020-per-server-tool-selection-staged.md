---
document_type: security-review
review_type: staged
assessment_date: 2026-07-09
codebase_analyzed: acrossai-mcp-manager (Feature 020 — per-server-tool-selection, staged/working-tree scope)
total_files_analyzed: 21
total_findings: 1
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 1
owasp_categories: [A05]
cwe_ids: [CWE-693]
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

# SECURITY REVIEW REPORT — STAGED CHANGES

**Feature branch**: `020-per-server-tool-selection`
**Reviewer**: `/speckit-security-review-staged` (automated)
**Scope**: 10 modified + 11 new files in working tree (nothing yet `git add`'d — falling back to working-tree scope as the effective pre-commit set)

## Executive Summary

The staged code is **clean**. Every finding from the two prior plan-phase security reviews (SEC-020-001..006 + SEC-020-007..011) and the tasks-phase review (SEC-020-T-001..T-005) has verified implementation in the working tree. A single **INFORMATIONAL** finding remains: an `apiFetch` middleware wire uses a URL-with-trailing-slash pattern that may produce `//`-double-slash requests depending on how `createRootURLMiddleware` handles the trailing slash. The middleware is redundant with WordPress's `wpApiSettings` fallback that already fires for admin scripts, so the safest fix is deletion, not correction.

**No CRITICAL / HIGH / MEDIUM / LOW findings.** Post-implementation UX pivots (Add-all removal, Reset rename, Save/Cancel → optimistic-per-toggle, disabled-server picker unlock, always-visible built-ins) introduced **zero new attack surface** — REST auth, nonce, catalog validation, and enforcement gate paths are unchanged.

## Staged Diff Reviewed

| Category | Path | Nature |
|---|---|---|
| New PHP — BerlinDB module | `includes/Database/MCPServerTool/{Schema,Row,Table,Query}.php` | 4 files |
| New PHP — REST controller | `includes/REST/ToolsController.php` | 1 file |
| New PHP — Enforcement gate | `includes/MCP/ToolExposureGate.php` | 1 file |
| New JS — React app | `src/js/tools.js` | 1 file (+ 2 build artifacts) |
| Modified PHP — Tab render swap | `admin/Partials/ServerTabs/ToolsTab.php` | rewrite |
| Modified PHP — F017 tab UX parity | `admin/Partials/ServerTabs/AbilitiesTab.php` | disabled-server render unlock |
| Modified PHP — Wiring | `includes/Main.php`, `includes/Activator.php`, `admin/Main.php` | delta hook wire + enqueue |
| Modified PHP — Uninstall | `uninstall.php` | +1 table in drop-list |
| Modified config | `webpack.config.js` | +1 entry |
| Modified docs | `README.txt`, `docs/planings-tasks/README.md` | changelog + index |
| Modified spec-kit | `specs/020-per-server-tool-selection/*` (10 files) | spec/plan/tasks/contracts/quickstart/research/data-model/memory-synthesis/checklists |
| New security-review artifacts | `docs/security-reviews/2026-07-09-020-*.md` | 3 files |

## Vulnerability Findings

---

### INFORMATIONAL — SEC-020-STG-001 — Redundant `createRootURLMiddleware` with trailing-slash pattern

- **finding_id**: SEC-020-STG-001
- **location**: `src/js/tools.js:810-812`
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-693: Protection Mechanism Failure (adjacent — silent-failure regression class)
- **cvss_score**: 2.0 (INFORMATIONAL — no direct security impact)
- **spec_kit_task**: TASK-SEC-020-STG-001

**Description**

The mount function wires:

```javascript
if ( config.restApiRoot ) {
    apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) );
}
```

`config.restApiRoot` is set from `admin/Main.php:791` via `esc_url_raw( untrailingslashit( rest_url() ) )` — the value is intentionally without a trailing slash (B17 mitigation). Appending `'/'` inside the middleware call combines with `apiFetch` paths that start with `/` (e.g., `/${config.namespace}/servers/${serverId}/tools`) which — depending on `createRootURLMiddleware`'s internal concatenation strategy — could produce `https://site/wp-json//acrossai-mcp-manager/...` (double slash). WordPress typically routes double-slash paths as 404 (B17 exact failure mode).

**Two concerns**:

1. **Correctness risk**: If the middleware naively concatenates `base + path`, requests fail with 404 instead of hitting the REST controller. The user hasn't reported a POST failure after the middleware wire was added, which suggests either the middleware strips the leading `/` from `path` OR WordPress admin's `wpApiSettings.root` fallback fires for the failing requests. Neither is deterministic across `@wordpress/scripts` versions.
2. **Redundancy**: WordPress admin already sets a global `wpApiSettings.root` value that `apiFetch` uses by default. Calling `createRootURLMiddleware` explicitly is unnecessary in admin context — F017's `src/js/abilities.js:95` correctly only wires `createNonceMiddleware` and leaves the URL rooting to WordPress core.

**Recommendation**

Delete the block. `apiFetch` will fall back to the WordPress admin's `wpApiSettings.root` correctly:

```diff
if ( config.nonce ) {
    apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}
- if ( config.restApiRoot ) {
-     apiFetch.use( apiFetch.createRootURLMiddleware( config.restApiRoot + '/' ) );
- }
```

Test after deletion by clicking Add on the Tools tab and confirming the POST resolves 200 (not 404).

**Rationale for INFORMATIONAL rating**: The user's most recent Tools-tab interaction ("Reset" rename) was tested successfully implying POSTs work with the current middleware wire. Correctness may be fine due to `@wordpress/scripts` version specifics. But the redundancy is real, and matching F017's proven pattern eliminates the risk class entirely with a one-line deletion.

---

## Confirmed Secure Patterns

Each pattern verified by targeted grep against the staged code.

### Authentication & Authorization

- **REST permission callbacks explicit** — `includes/REST/ToolsController.php:174-176` returns `current_user_can( 'manage_options' )`. NEVER `__return_true`. S2 compliant. **Verified**.
- **Nonce middleware wired at client mount** — `src/js/tools.js:807` calls `apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) )` using `wp_create_nonce( 'wp_rest' )` from `admin/Main.php:788`. S1 compliant. **Verified** (this was the "add + reload = removed" fix from earlier in the session).

### Input Validation

- **Explicit REST args schema on both routes** — `ToolsController.php:104-160` declares `server_id` (positive-integer validate_callback) and `tools` (array-of-string with per-element `sanitize_text_field` + array validate). Middleware rejects malformed bodies before controller code executes. **Verified** (5 references to `sanitize_callback` / `validate_callback`).
- **POST payload catalog validation** — `ToolsController.php:246-280` filters every submitted slug against `wp_get_abilities()`; all-or-nothing rejection with 400 + `invalid_slugs` on any unknown. Prevents B7 mass-assignment. **Verified**.
- **Excluded slugs defense-in-depth** — Both `ToolsController::EXCLUDED_SLUGS` and `ToolExposureGate::EXCLUDED_SLUGS` hard-code the three `mcp-adapter/*` protocol tools. POST rejects them; enforcement gate bypasses them. Mirrored on client `src/js/tools.js:36-40`. **Verified**.

### Data Safety

- **Prepared statements throughout** — All BerlinDB operations delegate to `$wpdb->prepare()` via the Kern layer. `delete_items_for_server` at `Query.php:217-227` uses `$wpdb->delete( $table, [ 'server_id' => $server_id ], [ '%d' ] )` — parameterized. **Verified** (S4 compliant).
- **`SELECT ... FOR UPDATE` serialization** — `Query::replace_set()` at `Query.php:145-176` opens `START TRANSACTION`, acquires exclusive row-range lock via `SELECT id FROM %i WHERE server_id = %d FOR UPDATE`, applies diff, `COMMIT`. Catch → `ROLLBACK`. Concurrent overlapping POSTs on the same server_id serialize cleanly. **Verified** (5 references to `FOR UPDATE`).
- **UNIQUE(server_id, ability_slug)** — Schema.php:107-112 enforces at DB level. Prevents duplicate rows under any race. **Verified**.

### Error Handling & Observability

- **500 response body is generic** — `ToolsController::post_tools()` at `ToolsController.php:334-348` catches `\Throwable` from `replace_set()`, `error_log`'s the exception message + server_id + count, returns `WP_Error( 'acrossai_mcp_tools_save_failed', <generic-i18n-message>, [ 'status' => 500 ] )`. Exception details NEVER surface to REST response — no schema-hint leak (CWE-209 mitigated). **Verified** (3 refs to `acrossai_mcp_tools_save_failed`).
- **Observer isolation** — `ToolsController::fire_change_action()` at `ToolsController.php:388-410` wraps each `do_action( 'acrossai_mcp_tools_changed', ... )` in its own try/catch; a throwing observer is `error_log`'d and swallowed; DB commit stands; REST returns 200; other observers still fire. **Verified** (2 references to `\Throwable`).
- **`error_log` payload safety** — Both `error_log` sites log server_id (integer), count (integer), and exception message. Slug (line 405) is validated against `wp_get_abilities()` catalog before reaching the observer path. No user-controllable log injection. No PII (user IDs, IPs, sessions omitted). **Verified**.

### Runtime Enforcement (SEC-020-001 closure)

- **`ToolExposureGate::gate_tool_call_by_curation`** — Duck-typed feature detection using `method_exists( $server, 'get_server_id' )` (NOT `instanceof`) at `ToolExposureGate.php:114`. Mirrors F017's `AbilityExposureGate.php:98-119` line-for-line. Deny-precedence at line 109 (`is_wp_error( $args ) → return`). Fail-open on empty slug + missing server row. Protocol-tool bypass at line 148. Absence-deny at line 158-162 (`WP_Error( 'acrossai_mcp_tool_not_added', 403 )`). **Verified**.
- **Priority 30 stacking** — `Main.php:441` wires callback at priority 30 after F015 (10) + F017 (20). Deny-precedence honored. **Verified**.
- **Per-request cache + explicit flush** — `ToolExposureGate::get_added_slugs_cached()` (line 173) memoizes; `ToolExposureGate::flush_cache()` (line 184) unsets. Called from `ToolsController::post_tools()` at line 353 after successful `replace_set()`. Same-request tool call after a save sees fresh state. **Verified**.

### Uninstall & Cascade

- **Uninstall gate honored** — `uninstall.php:60` adds `acrossai_mcp_server_tools` to the shared drop-list below the F012 opt-in gate at line 33. T057 automated line-order check exits 0. **Verified**.
- **Cascade cleanup on server delete** — `Query::on_mcp_server_deleted( $server_id, $result )` at `Query.php:249-254` no-ops when `$result === false`. Wired via `Main.php:445-450` to BerlinDB-native `mcp_server_deleted` action. Both admin caller paths (`Settings.php:129` + `:223`) route through `MCPServer\Query::delete_item()` which fires the action. **Verified**.

### Client-Side Safety

- **No external React libraries** — Grep gate `react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components` in `src/js/tools.js` returns only doc-comment mentions (2 hits, both explicitly listing them as forbidden). No actual imports. Only Tier-1 `@wordpress/*` packages used. **Verified**.
- **Optimistic-per-toggle rollback** — `persistSet( nextSet, prevSet )` at `src/js/tools.js:398-425` optimistically updates local state, POSTs, and on catch reverts `added` to `prevSet` before displaying error. No stale state persists across failed POSTs. **Verified**.
- **Button disable during POST** — Add / Remove / Reset buttons all pass `busy: saving` to `AbilityRow` at `tools.js:562, 723`; Reset button `disabled: added.size === 0 || saving` at `tools.js:637`. Prevents double-click race conditions producing overlapping POSTs. **Verified**.

### Post-implementation UX Pivots — Security Impact Analysis

None of the 6 post-implementation UX pivots introduce new attack surface:

| Pivot | Security Delta |
|---|---|
| Add all → button removed | Fewer surface points → smaller attack surface. **Reduces** risk. |
| Remove all → renamed Reset | Cosmetic. Zero delta. |
| Save/Cancel → optimistic-per-toggle POST | Same auth path (manage_options + nonce). Each POST validates independently. **Zero delta**. |
| Server-disabled state now permits editing | Same auth (manage_options). Enforcement gate still applies once server is enabled. Persistence via same REST endpoint. **Zero delta**. |
| Built-in tools always visible in right column | Slugs are UI-only; never sent in POST payload; excluded-slugs guard rejects them defensively at controller. **Zero delta**. |
| Nonce middleware wire fix | **Reduces** risk — closes the "silent-403-on-POST" bug pattern. |

### AbilitiesTab.php Edit — Security Impact

The disabled-server render unlock in `AbilitiesTab.php` (F017 UX-parity exception, documented in `plan.md §Principle I`) does not modify F017's REST controller, BerlinDB module, or enforcement gate. It only removes the early-return that was hiding the picker when `is_enabled` was false. Same auth, same nonce, same enforcement. **Zero security delta** on F017's shipped surface.

---

## Action Plan

### Immediate (before commit)

1. **SEC-020-STG-001 (INFORMATIONAL)**: Delete the `createRootURLMiddleware` block in `src/js/tools.js:810-812`. Retest one Add click. If Add still commits and reload confirms, the fix is complete.

### Not Blocking

Everything else. No CRITICAL / HIGH / MEDIUM / LOW findings.

### Durable Memory Preservation

**Deferred to `/speckit-memory-md-capture-from-diff`** (post-commit). Systemic lessons from this feature that are worth capturing:

1. **`DEC-TOOL-SELECTION-PRESENCE-MODEL`** — presence-based storage over boolean columns when UX has no third state.
2. **`DEC-TOOL-SHUTTLE-PICKER-OVER-DATAVIEWS`** — hand-rolled UX when DataViews cannot express the metaphor; requires reconsider clause + new DEC.
3. **`DEC-F020-TOOL-ENFORCEMENT-PRIORITY`** — priority-30 slot on `mcp_adapter_pre_tool_call`, updates D18's slot map (10=F015, 20=F017, 30=F020).
4. **Withdrawn** — `DEC-TOOL-EDIT-EXPLICIT-BATCH-COMMIT` (superseded by optimistic-per-toggle pivot).
5. **`B24 — Vendor accessor assumption without feature-detection`** (optional, per T065) — the SEC-020-007 lesson: `method_exists` over `instanceof` for vendor accessors that survive vendor namespace refactors.
6. **`B25 — Redundant apiFetch middleware may double-slash URLs`** (optional, from this review) — a WordPress admin JS should NOT wire `createRootURLMiddleware` when `wpApiSettings.root` is already set; the redundant wire risks silent 404s.

I'll not proactively invoke `/speckit-memory-md-capture` here because the memory-capture step is scheduled for the post-commit workflow — capturing from the pre-commit diff would speculate on wording that only stabilizes after review.

### Next Command

`/speckit-git-commit` (or `/speckit-architecture-guard-architecture-verify` first if you want the final constitution gate). If applying SEC-020-STG-001's fix, `git add` after the edit then commit.

---

## Memory Hub INDEX.md Row

Paste into `docs/memory/INDEX.md` §Security Reviews:

```text
| docs/security-reviews/2026-07-09-020-per-server-tool-selection-staged.md | staged | 2026-07-09 | INFORMATIONAL | C:0 H:0 M:0 L:0 | A05 |
```
