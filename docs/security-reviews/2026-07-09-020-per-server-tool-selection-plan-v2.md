---
document_type: security-review
review_type: plan
assessment_date: 2026-07-09
codebase_analyzed: acrossai-mcp-manager (Feature 020 — per-server-tool-selection, post-remediation second pass)
total_files_analyzed: 7
total_findings: 5
overall_risk: HIGH
critical_count: 0
high_count: 1
medium_count: 0
low_count: 2
informational_count: 2
owasp_categories: [A01, A05, A09]
cwe_ids: [CWE-285, CWE-732, CWE-693]
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

# Security Review — Feature 020: Per-Server Tool Selection (Plan v2, Post-Remediation)

**Feature branch**: `020-per-server-tool-selection`
**Reviewer**: `/speckit-security-review-plan` (second pass — first pass at `2026-07-09-020-per-server-tool-selection-plan.md`)
**Plan artifact hash**: post-remediation state (FR-029, FR-030, FR-031 added; `contracts/enforcement.md` created; `data-model.md` §Runtime Enforcement Consumer added; `contracts/rest-api.md` args schema + observer isolation; `contracts/js-hooks.md` isolation guarantee)

## Executive Summary

**Five of six original findings are properly closed by the remediation.** SEC-020-001 (runtime enforcement path), SEC-020-002 (concurrent race), SEC-020-003 (deletion-hook name), SEC-020-004 (observer isolation), and SEC-020-005 (args schema) all have concrete FR + contract + implementation-guidance triples in the updated artifacts.

**However, one CRITICAL closure regression re-opens SEC-020-001 in effect.** The new `contracts/enforcement.md` at line 40 encodes `$server instanceof \WP\MCP\Server` and `$server->get_id()` — but the actual vendor class is `\WP\MCP\Core\McpServer`, and its accessor is `get_server_id(): string` (returning a server *slug*, not an integer). As written, the `instanceof` check fails for every real request emanating from mcp-adapter's `ToolsHandler.php:182` filter fire, `$server_id` stays `0`, the fail-open branch triggers, and the callback returns `$result` unchanged. **Enforcement is a no-op — same effective outcome as the original SEC-020-001 finding this remediation was meant to close.**

This is a "close-in-form, gap-in-substance" regression. It is easy to fix — F017's `AbilityExposureGate.php:99-119` already implements the correct pattern (`method_exists`, `get_server_id`, slug→id resolution via `MCPServerQuery`) which F020 should mirror one-for-one.

Four remaining findings are minor (2 LOW isolation-level + args-schema completeness, 2 INFORMATIONAL error body + query optimization). None are blocking on their own, but SEC-020-007 must be closed before `/speckit-tasks` proceeds — otherwise F020 ships storage + UI + REST + observability without a functional enforcement gate.

## Plan Artifacts Reviewed (post-remediation)

| Path                                              | Change since v1                                                                            |
|---------------------------------------------------|--------------------------------------------------------------------------------------------|
| `specs/020-per-server-tool-selection/spec.md`     | +FR-029 (enforcement), +FR-030 (transactional replace_set), +FR-031 (observer isolation), +SC-011..014, refined "authoritative" assumption |
| `specs/020-per-server-tool-selection/plan.md`     | Constitution Check §III updated, Project Structure +ToolExposureGate.php +ToolExposureGateTest.php |
| `specs/020-per-server-tool-selection/data-model.md` | +§Runtime Enforcement Consumer, transactional replace_set shape, concrete `mcp_server_deleted` cascade wiring |
| `specs/020-per-server-tool-selection/contracts/rest-api.md` | Explicit `args` schema for `tools`, exact try/catch observer loop |
| `specs/020-per-server-tool-selection/contracts/js-hooks.md` | Observer isolation guarantee updated                                                 |
| `specs/020-per-server-tool-selection/contracts/enforcement.md` | **NEW** — 7-scenario `mcp_adapter_pre_tool_call` callback contract              |
| `specs/020-per-server-tool-selection/memory-synthesis.md` | All 6 conflict warnings marked RESOLVED                                              |

**Additional context consulted for accessor verification** (v1 could not check this):

| Path                                                                    | Purpose                                              |
|-------------------------------------------------------------------------|------------------------------------------------------|
| `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182` | Confirmed filter fire site + argument order          |
| `vendor/wordpress/mcp-adapter/includes/Core/McpServer.php:260`          | Confirmed real class name + accessor signature       |
| `includes/MCP/AbilityExposureGate.php:99-119`                           | F017's reference implementation — same vendor accessor pattern |
| `includes/AccessControl/AcrossAI_MCP_Access_Control.php:249-253`        | F015's reference implementation — same accessor pattern |

## Findings on Closed v1 Items (verification)

| v1 Finding    | v1 Severity | v2 Status  | Verification                                                                                   |
|---------------|-------------|------------|------------------------------------------------------------------------------------------------|
| SEC-020-001   | HIGH        | **Re-opened as SEC-020-007** | Contract exists but uses wrong class/accessor — see finding below.               |
| SEC-020-002   | MEDIUM      | Closed     | FR-030 added; `data-model.md` shows TX wrap; `plan.md §III` echoes; SC-011 verifies. Minor gap: isolation level not specified (see SEC-020-008). |
| SEC-020-003   | LOW         | **Closed** | FR-026 rewritten to name `mcp_server_deleted`; `data-model.md §Deletion hook` documents both admin caller paths (`Settings.php:129,223`) and the vendor fire site. |
| SEC-020-004   | LOW         | Closed     | FR-031 added; `contracts/rest-api.md §Side effects on 200` shows exact loop; `contracts/js-hooks.md` guarantees isolation. |
| SEC-020-005   | INFO        | Partial    | `tools` body param has explicit args schema; `server_id` path param does NOT (see SEC-020-009). |
| SEC-020-006   | INFO        | Same       | No change — preserved as documented trade-off matching F017 precedent. Still fine.             |

## Vulnerability Findings (v2)

---

### SEC-020-007 — Enforcement callback uses wrong vendor class + accessor; effectively re-opens SEC-020-001

- **finding_id**: SEC-020-007
- **location**: `specs/020-per-server-tool-selection/contracts/enforcement.md:40` (Step 2: `$server_id = $server instanceof \WP\MCP\Server ? (int) $server->get_id() : 0;`); `data-model.md §Runtime Enforcement Consumer §Callback semantics §2`
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-285: Improper Authorization
- **cvss_score**: 7.5 (HIGH — vector: `AV:N/AC:H/PR:H/UI:N/S:C/C:L/I:H/A:N`)
- **spec_kit_task**: TASK-SEC-020-007

**Description**

The remediation's `contracts/enforcement.md` at line 40 encodes:

```php
$server_id = $server instanceof \WP\MCP\Server ? (int) $server->get_id() : 0;
if ( $server_id <= 0 || ! MCPServerQuery::instance()->get_item( $server_id ) ) {
    do_action( 'acrossai_mcp_tool_gate_missing_server', $tool_name, $server );
    return $result;  // Fail-open
}
```

Three ground-truth mismatches against the actual vendor:

1. **Wrong class name**: Vendor class is `\WP\MCP\Core\McpServer` (verified at `vendor/wordpress/mcp-adapter/includes/Core/McpServer.php:26`), NOT `\WP\MCP\Server`. `$server instanceof \WP\MCP\Server` returns `false` for every real request.
2. **Wrong method name**: Vendor accessor is `get_server_id(): string` (verified at `McpServer.php:260`), NOT `get_id()`. Even if the `instanceof` check were fixed, `$server->get_id()` would fatal on undefined method (or PHP 8+ dynamic-property warning if magic access is enabled).
3. **Wrong type**: `get_server_id()` returns a **STRING** (the server slug, e.g. `"mcp-adapter-default-server"`), NOT an integer. `(int) $slug` casts the string to `0` in almost every case. The subsequent `MCPServerQuery::instance()->get_item( 0 )` returns `null`.

Net effect at runtime:

- Every real invocation from `ToolsHandler.php:182` produces `$server_id = 0`.
- The `$server_id <= 0` guard fires; the fail-open branch triggers.
- The callback returns `$result` unchanged.
- **F020's enforcement gate is a no-op on every real request.**
- The tool set stored in `wp_acrossai_mcp_server_tools` has no effect on what AI clients can call.

This is the same effective outcome as pre-remediation SEC-020-001. The Tools tab remains UI theater. The audit trail says "closed" but the runtime behavior says "still open".

**How F017 does it correctly** (F020 should mirror one-for-one, `AbilityExposureGate.php:98-119`):

```php
// Fail-open when the server accessor is missing.
if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
    return $args;
}
$server_slug = (string) $server->get_server_id();   // slug, not int
if ( '' === $server_slug ) {
    return $args;
}
// Resolve slug → integer server_id via the F011 MCPServer table.
$rows = MCPServerQuery::instance()->query( array(
    'server_slug' => $server_slug,
    'number'      => 1,
) );
if ( empty( $rows ) ) {
    return $args;  // Server row missing — fail-open.
}
$server_id = (int) $rows[0]->id;
```

Same defensive shape used by F015 at `AcrossAI_MCP_Access_Control.php:249-253`. F017 and F015 both correctly consume `get_server_id()` as a slug + resolve to integer via `MCPServerQuery`.

**Recommendation**

Update `contracts/enforcement.md` step 2 to mirror F017's reference implementation exactly:

```php
if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
    return $result;
}
$server_slug = (string) $server->get_server_id();
if ( '' === $server_slug ) {
    return $result;
}
$rows = MCPServerQuery::instance()->query( array(
    'server_slug' => $server_slug,
    'number'      => 1,
) );
if ( empty( $rows ) ) {
    do_action( 'acrossai_mcp_tool_gate_missing_server', $tool_name, $server_slug );
    return $result;
}
$server_id = (int) $rows[0]->id;
```

Update `data-model.md §Runtime Enforcement Consumer §Callback semantics §2` in lockstep. Delete the `\WP\MCP\Server` class reference throughout — the correct class is `\WP\MCP\Core\McpServer`, but F020's callback shouldn't type-check against it at all (F017 uses `method_exists`, which is duck-typed and forward-compatible with vendor refactors).

Also update `ToolExposureGate::filter_pre_tool_call` signature docblock to declare `\WP\MCP\Core\McpServer|mixed $server` (matches F017's `AbilityExposureGate.php:86` docblock exactly).

Once these three edits land, SEC-020-007 is closed and SEC-020-001 stays properly closed. Verify by running the SC-012 quickstart step against a real MCP tool call from a client — if `error_log` shows the gate firing at priority 30 and returning `WP_Error( 'acrossai_mcp_tool_not_added', ... )`, the fix worked.

**Blocking**: YES — do NOT proceed to `/speckit-tasks` without addressing this. F020's core value proposition (per-server tool curation) is unrealized until this is fixed.

---

### SEC-020-008 — DB transaction isolation level not specified for `replace_set()`

- **finding_id**: SEC-020-008
- **location**: `specs/020-per-server-tool-selection/data-model.md §Query API §replace_set`; `spec.md` FR-030
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-732: Incorrect Permission Assignment for Critical Resource
- **cvss_score**: 3.7 (LOW — vector: `AV:N/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:L`)
- **spec_kit_task**: TASK-SEC-020-008

**Description**

FR-030 mandates `START TRANSACTION` / `COMMIT` / `ROLLBACK` wrap on `replace_set()`. The isolation level is not specified. Under InnoDB's default `REPEATABLE READ`:

- The initial `get_added_slugs()` read sees a consistent snapshot.
- Subsequent `add_item()` inserts hold row-level locks + the UNIQUE(server_id, ability_slug) gap lock.
- If Admin B's transaction started after A's read but before A's commit, B's read sees `[]` (repeatable-read isolation), and B's insert of any slug A also inserts blocks on the gap lock. Depending on InnoDB's deadlock detector, one transaction is rolled back and its callback re-throws.

FR-030's claim "deterministic last-committer-wins" is close-to-true but depends on InnoDB deadlock resolution being deterministic (it's mostly deterministic per InnoDB docs, but implementation-dependent under high concurrency). Under high contention, an aborted transaction returns a 5xx error to that admin — not "last-committer-wins", but "last-committer-wins OR retry".

**Recommendation**

Option A (simpler) — add explicit `SELECT ... FOR UPDATE` on the server_id rows at the start of the transaction, which serializes the two transactions cleanly:

```sql
SELECT id FROM {prefix}acrossai_mcp_server_tools WHERE server_id = %d FOR UPDATE;
-- ... then proceed with the diff + writes.
```

Option B — accept the current InnoDB-default behavior, but update FR-030 language: "under concurrent Save operations, the DB commits the second-arriving transaction wholesale OR aborts one with an error status; no partial-commit or set-union state ever appears." This is the honest description of what the current design guarantees.

Update the FR-030 wording and update `data-model.md §replace_set` with the explicit isolation choice.

**Not blocking** — but resolve before implementation because it materially affects error-handling and the concurrency test in `QueryReplaceSetTest` (SC-011).

---

### SEC-020-009 — `server_id` path param lacks explicit REST `args` schema

- **finding_id**: SEC-020-009
- **location**: `specs/020-per-server-tool-selection/contracts/rest-api.md §Route 1 §Request` + `§Route 2 §Request` (path param specified informally with `absint()`)
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-20 (via CWE-693)
- **cvss_score**: 3.1 (LOW — vector: `AV:N/AC:L/PR:H/UI:N/S:U/C:N/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-020-009

**Description**

SEC-020-005 (v1) was closed for the `tools` body param — an explicit `args` schema block was added. The `server_id` path parameter is still specified informally: prose says `absint()`, but there's no `register_rest_route`-level schema. Implementation risk: if the controller callback runs before the check, the endpoint could be called with `server_id=0` and produce a 404 rather than a 400.

This is a very minor consistency gap. Both params should be declared in the same `args` block:

```php
'args' => array(
    'server_id' => array(
        'type'              => 'integer',
        'required'          => true,
        'sanitize_callback' => 'absint',
        'validate_callback' => static function ( $value ) {
            return $value > 0 || new \WP_Error(
                'rest_invalid_id',
                esc_html__( 'server_id must be a positive integer.', 'acrossai-mcp-manager' ),
                array( 'status' => 400 )
            );
        },
    ),
    'tools' => array( /* existing */ ),
),
```

**Recommendation**

Add the `server_id` args entry to `contracts/rest-api.md` §Route 2 §Request block. Mention in Route 1 that GET reuses the same path-param schema.

**Not blocking** — cosmetic contract completeness.

---

### SEC-020-010 — Controller error response on TX rollback not specified

- **finding_id**: SEC-020-010
- **location**: `specs/020-per-server-tool-selection/data-model.md §Query::replace_set` step 3 (rollback + rethrow); `contracts/rest-api.md` (no 500 response documented)
- **owasp_category**: A09:2025-Security Logging and Monitoring Failures
- **cwe**: CWE-209: Generation of Error Message Containing Sensitive Information
- **cvss_score**: 2.4 (INFORMATIONAL — vector: `AV:N/AC:H/PR:H/UI:N/S:U/C:L/I:N/A:N`)
- **spec_kit_task**: TASK-SEC-020-010

**Description**

`data-model.md §replace_set` step 3 says "the controller catches, returns HTTP 500 with a generic error". `contracts/rest-api.md` §Route 2 documents 200/400/403/404 but not 500. The composition of the 500 body is implicit.

Risk: an implementation that returns `$e->getMessage()` in the response body leaks DB error text (potentially containing table names, column names, or schema hints) to the caller. Standard WordPress REST practice: return a generic `WP_Error` code + human-readable message, log the specific exception server-side.

**Recommendation**

Add a §Response — 500 section to `contracts/rest-api.md` §Route 2:

```json
{
  "code": "acrossai_mcp_tools_save_failed",
  "message": "Could not save the tools list. Please try again.",
  "data": { "status": 500 }
}
```

Underlying `$e->getMessage()` MUST be `error_log`'d server-side but MUST NOT appear in the response body. Same convention as WP REST's other error paths.

**Not blocking** — INFORMATIONAL.

---

### SEC-020-011 — Cascade cleanup per-row `delete_item()` loop (optimization opportunity)

- **finding_id**: SEC-020-011
- **location**: `specs/020-per-server-tool-selection/data-model.md §Query API §delete_items_for_server`
- **owasp_category**: N/A (defensive / performance)
- **cwe**: N/A (not a vulnerability)
- **cvss_score**: 0.0 (INFORMATIONAL — no security impact)
- **spec_kit_task**: TASK-SEC-020-011

**Description**

`delete_items_for_server()` is documented as "iterates rows for server_id via `query()` and calls `delete_item()` on each. Returns the count of deleted rows." Under bulk-delete of many servers (via `Settings.php:223` bulk action), this produces `N × M` DELETE statements (N servers × M tools per server).

A single `$wpdb->delete( $table, [ 'server_id' => $server_id ] )` statement covers all rows for a given server_id in one round-trip — same result, fewer round-trips. Not a security issue; a modest DB round-trip savings.

**Recommendation**

Reword `data-model.md §delete_items_for_server` to prefer the single-statement approach:

```php
public function delete_items_for_server( int $server_id ): int {
    global $wpdb;
    $count = $wpdb->delete(
        $this->get_table_name(),
        array( 'server_id' => $server_id ),
        array( '%d' )
    );
    return (int) $count;
}
```

Or explicitly commit to per-row for cache-invalidation consistency (BerlinDB's `delete_item` clears per-item caches; a raw `$wpdb->delete` doesn't). If per-row is chosen intentionally for cache correctness, document that rationale.

**Not blocking** — INFORMATIONAL.

---

## Confirmed Secure Patterns (v2 — added since v1)

Beyond the 12 patterns confirmed in v1, the remediation added:

- **Explicit REST `args` schema on `tools`** — WP REST middleware validates + sanitizes at boundary before controller code runs. Prevents malformed bodies from ever reaching Query layer.
- **Transactional `replace_set()`** — atomic diff apply; concurrent overlapping saves cannot produce set-union superset (modulo SEC-020-008 isolation-level refinement).
- **Observer isolation** — each `do_action` fire individually wrapped in try/catch; broken mu-plugin observer cannot 500 the endpoint.
- **BerlinDB-native cascade hook** — `mcp_server_deleted` action from `MCPServer\Query::delete_item()` covers both admin caller paths without new fire sites. Simpler than adding a wrapper.
- **Fail-open on unresolvable server_id at enforcement gate** — matches F015/F017 D19 pattern; documented action fire `acrossai_mcp_tool_gate_missing_server` for observability.
- **Deny-precedence in enforcement gate** — F020 NEVER re-allows an ability F015 or F017 denied; deny always wins.
- **Priority-slot map documented** — future gate features have a canonical location to slot in without surprise reordering.

**Once SEC-020-007 is fixed**, these patterns fully realize the intended security model.

---

## Action Plan & Next Steps

### Blocking before `/speckit-tasks`

1. **SEC-020-007 (HIGH — remediation regression)**: Update `contracts/enforcement.md` step 2 + `data-model.md §Runtime Enforcement Consumer §Callback semantics §2` to mirror F017's `AbilityExposureGate::gate_tool_call_by_exposure()` at `includes/MCP/AbilityExposureGate.php:98-119` — `method_exists( $server, 'get_server_id' )`, `(string) $server->get_server_id()`, slug→id resolution via `MCPServerQuery::instance()->query([ 'server_slug' => $slug, 'number' => 1 ])`. Do NOT type-check against a specific class name — use duck-typed feature detection. **Blocking**.

### Recommended before `/speckit-implement`

2. **SEC-020-008 (LOW)**: Pick isolation strategy (SELECT ... FOR UPDATE at start of TX vs accept-default-with-retry-on-error) and update FR-030 + data-model to reflect the choice.
3. **SEC-020-009 (LOW)**: Add explicit `server_id` args schema in `contracts/rest-api.md`.

### Recommended during implementation

4. **SEC-020-010 (INFO)**: Document the 500 response body in `contracts/rest-api.md`.
5. **SEC-020-011 (INFO)**: Decide per-row-vs-bulk delete in `delete_items_for_server`; document rationale.

### Durable Memory Preservation

**Deferred with a note**: SEC-020-007 highlights a systemic risk pattern worth capturing post-implement: **"Cross-vendor API accessors must be verified by feature-detection (`method_exists`) + reading the actual vendor source, not by assuming a class name from casual documentation. Instanceof checks against un-verified class names silently fail-open."** This is close to but distinct from B22 (@wordpress/* runtime store lookups). A new bug pattern (`Bnn — Vendor accessor assumption without feature-detection`) may be worth adding to BUGS.md if the fix confirms the failure mode empirically. Capture via `/speckit-memory-md-capture` once F020 lands with the corrected implementation.

### Remediation Planning

Recommend running `/speckit-security-review-followup` after SEC-020-007 is resolved. That command materializes SEC-020-007 → SEC-020-011 into `TASK-SEC-020-NNN` in the eventual tasks.md.

---

## Memory Hub INDEX.md Row

Paste into `docs/memory/INDEX.md` §Security Reviews (this row REPLACES the v1 row for the same plan phase):

```text
| docs/security-reviews/2026-07-09-020-per-server-tool-selection-plan-v2.md | plan | 2026-07-09 | HIGH | C:0 H:1 M:0 L:2 | A01,A05,A09 |
```

If preserving the v1 row for audit history, add this row alongside it. Recommended: keep both — v1 shows the pre-remediation state, v2 shows the post-remediation state with the regression flagged.
