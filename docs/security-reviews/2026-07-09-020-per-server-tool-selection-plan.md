---
document_type: security-review
review_type: plan
assessment_date: 2026-07-09
codebase_analyzed: acrossai-mcp-manager (Feature 020 — per-server-tool-selection)
total_files_analyzed: 6
total_findings: 6
overall_risk: HIGH
critical_count: 0
high_count: 1
medium_count: 1
low_count: 2
informational_count: 2
owasp_categories: [A01, A04, A05, A08]
cwe_ids: [CWE-285, CWE-362, CWE-693, CWE-1188]
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

# Security Review — Feature 020: Per-Server Tool Selection (Plan)

**Feature branch**: `020-per-server-tool-selection`
**Reviewer**: `/speckit-security-review-plan` (automated)
**Plan artifact hash**: `plan.md` @ 2026-07-09 01:44 UTC

## Executive Summary

Feature 020's plan is largely security-sound at the CRUD boundary — REST routes enforce `manage_options`, POST validates every slug against `wp_get_abilities()` before persistence, BerlinDB provides prepared statements, and the uninstall path correctly sits below the F012 opt-in gate.

However, the plan has **one HIGH-severity gap** that materially reproduces the same class of finding that F017's plan-review surfaced (SEC-017-001 → closed by F017 Q4/FR-030): **the plan does not specify how curated tool selections are enforced at the AI-client boundary.** F017 closed its gap by hooking `mcp_adapter_pre_tool_call` at priority 20. F020's spec, plan, data-model, contracts, and research files contain **zero references** to any enforcement hook (`mcp_adapter_pre_tool_call`, `mcp/tools/list` filter, or any other adapter integration surface). Without an explicit runtime path, the Tools tab is UI-only — an operator "removes" a tool via the picker, the row is deleted from `wp_acrossai_mcp_server_tools`, but the AI client continues to see and call it because the mcp-adapter package has no awareness of the table.

The remaining findings are process/integrity issues (concurrent-editor race, unspecified deletion-hook name, unbounded observer exceptions) that will not surface as exploits but degrade the reliability of the feature under adversarial admin conditions. Fix HIGH before implementation; MEDIUM+LOW can be addressed during the tasks phase.

## Plan Artifacts Reviewed

| Path                                              | Purpose                            | Notes                                                      |
|---------------------------------------------------|------------------------------------|------------------------------------------------------------|
| `specs/020-per-server-tool-selection/plan.md`     | Implementation plan (this doc)     | Constitution check + Complexity Tracking + phase artifacts |
| `specs/020-per-server-tool-selection/spec.md`     | Feature spec + FR-001..FR-028      | Clarifications from `/speckit-clarify` embedded            |
| `specs/020-per-server-tool-selection/research.md` | Design decisions + alternatives    | All `NEEDS CLARIFICATION` resolved                         |
| `specs/020-per-server-tool-selection/data-model.md` | BerlinDB schema + query API      | `MCPServerTool` module                                     |
| `specs/020-per-server-tool-selection/contracts/rest-api.md` | GET + POST route contract | Explicit 400/403/404 responses                             |
| `specs/020-per-server-tool-selection/contracts/js-hooks.md` | 3 JS filters + 1 PHP action | `acrossai_mcp_tools_changed`                               |
| `specs/020-per-server-tool-selection/memory-synthesis.md` | Memory-informed context      | Resolved 1 HARD conflict + 2 SOFT conflicts documented     |
| `docs/memory/INDEX.md`                            | Routing map                        | 5 decisions + 5 constraints + 3 security constraints consulted |
| `docs/security-reviews/2026-07-07-017-per-server-ability-selection-plan.md` | F017 prior art | SEC-017-001 enforcement-gap precedent — see SEC-020-001 |

## Vulnerability Findings

---

### SEC-020-001 — Runtime enforcement path unspecified: Tools tab may be UI-only theater

- **finding_id**: SEC-020-001
- **location**: `specs/020-per-server-tool-selection/plan.md:8` (Summary describes storage + UI only)
- **owasp_category**: A01:2025-Broken Access Control
- **cwe**: CWE-285: Improper Authorization
- **cvss_score**: 7.5 (HIGH — vector: `AV:N/AC:H/PR:H/UI:N/S:C/C:L/I:H/A:N`)
- **spec_kit_task**: TASK-SEC-020-001

**Description**

The plan describes end-to-end storage (`MCPServerTool` BerlinDB module), an admin UI (React shuttle picker), a REST API for CRUD (`ToolsController`), and an observability action (`acrossai_mcp_tools_changed`). It says the mcp-adapter package is responsible for exposing added abilities as MCP tools to clients (`spec.md` §Assumptions §"The wordpress/mcp-adapter package is the delivery mechanism"). But there is **no design specification for how mcp-adapter learns which abilities to expose per server**.

Concrete gaps grep-verified across all six artifacts:

- Zero references to `mcp_adapter_pre_tool_call` (F017's canonical call-time enforcement filter — see D18 in INDEX).
- Zero references to `mcp/tools/list` list-time filtering.
- Zero references to any mcp-adapter integration hook.
- The `data-model.md §Query API` documents `get_added_slugs()` and `replace_set()` but no runtime enforcement consumer.
- `contracts/js-hooks.md` mentions only observation hooks (`acrossai_mcp_tools_changed`), no enforcement hooks.

F017's plan-review surfaced the identical gap (SEC-017-001 HIGH; see `docs/security-reviews/2026-07-07-017-per-server-ability-selection-plan.md` and F017 `plan.md:43`). F017 closed it via FR-030 — a callback on `mcp_adapter_pre_tool_call` at priority 20 (F015 = 10, so F017's ability gate stacks after access control). F020 must make the analogous decision explicit.

**Threat model**

Attacker: authenticated MCP client (Claude, cursor, etc.) attempting to call an ability the operator has NOT curated as a tool for this server.

- Storage says: no row in `wp_acrossai_mcp_server_tools` for `(server_id, ability_slug)`.
- UI says: ability appears in the "All abilities" pool, not "Added as tools".
- Runtime path (unspecified): if mcp-adapter has no filter that consults F020's table, the ability is still callable via the standard MCP protocol (either exposed by F017's `ExposureResolver` if `meta.mcp.public` is truthy, or by mcp-adapter's default discovery).

Result: operators believe removing an ability from the Tools tab restricts AI access, but the ability remains reachable. Curated permissioning is a lie.

**Recommendation**

**Before implementation**: extend the plan with an explicit runtime enforcement design. Two workable shapes:

1. **Call-time enforcement (F017 shape)** — hook `mcp_adapter_pre_tool_call` at a documented priority (e.g., 30 to stack after F015 access control @ 10 and F017 ability gate @ 20). Callback: if `(server_id, ability_slug)` is NOT present in `wp_acrossai_mcp_server_tools`, return `WP_Error( 'acrossai_mcp_tool_not_added', ..., [ 'status' => 403 ] )`. Deny-precedence: never override an earlier `WP_Error` return.

2. **List-time hiding (deferred by F017)** — filter the MCP protocol's tools/list endpoint so unadded abilities never appear in the client's discovery pass. Cleaner UX but requires identifying the mcp-adapter filter that shapes tools/list output (F017 documented this as a follow-up; F020 could adopt it now).

The cleanest design is **both** — call-time as defense-in-depth, list-time for UX. Pick one for MVP + document the other as a follow-up if scope constrains.

Alternative (only if the interpretation of F020 shifts): if the intent is that F020 is *purely UI on top of F017's ExposureResolver*, spec must state this explicitly + remove the "independent" claim (spec §Assumptions §"Relationship to Abilities tab" — "independent — presence in Tools is authoritative"). But that reinterpretation contradicts the current spec.

**Blocking**: YES — do not proceed to `/speckit-tasks` without adding a new FR (e.g., FR-029) that names the enforcement hook, priority, deny-precedence contract, and fail-open behavior for missing servers. Mirror F017 FR-030 verbatim in shape.

---

### SEC-020-002 — Concurrent `replace_set()` race produces set-union superset state

- **finding_id**: SEC-020-002
- **location**: `specs/020-per-server-tool-selection/data-model.md:56-64` (`Query::replace_set()` steps 2–5 are non-atomic)
- **owasp_category**: A04:2025-Insecure Design
- **cwe**: CWE-362: Concurrent Execution using Shared Resource with Improper Synchronization
- **cvss_score**: 5.3 (MEDIUM — vector: `AV:N/AC:H/PR:H/UI:R/S:U/C:N/I:H/A:N`)
- **spec_kit_task**: TASK-SEC-020-002

**Description**

`Query::replace_set()` is documented as: (1) normalize input, (2) fetch current via `get_added_slugs()`, (3) compute diff, (4) insert additions, (5) delete removals. Steps 2–5 are non-atomic — no DB transaction, no advisory lock, no CAS.

Concurrency scenario:

- t=0: Admin A saves `[X, Y]`; Admin B saves `[Y, Z]`; server currently has `[]`.
- t=1: A reads `[]`, computes `added=[X,Y]`.
- t=2: B reads `[]`, computes `added=[Y,Z]`.
- t=3: A inserts X + Y. UNIQUE constraint holds.
- t=4: B inserts Y (fails UNIQUE — swallowed as `false` by BerlinDB's `add_item`) + Z (succeeds).
- t=5: Final state: `[X, Y, Z]`.

Neither A nor B intended `[X, Y, Z]`. Admin A believes the tool set is `[X, Y]`; Admin B believes it's `[Y, Z]`. UI reflects last-fetched state, which is stale for both.

**Impact**

- Not a data-corruption issue (UNIQUE prevents duplicate rows).
- Not a privilege-escalation issue (both admins already have `manage_options`).
- Is a semantic-surprise issue: an ability neither admin intended can survive in the tool set. If the surprise is a tool the operator wanted removed (e.g., `delete-post`), the resulting state may inadvertently grant AI clients access to a destructive ability that the last-writer intended to strip.

The `all-or-nothing` guarantee in the POST contract (`contracts/rest-api.md` §Validation) does NOT cover this — all-or-nothing applies within one request, not across concurrent requests.

**Recommendation**

Wrap `Query::replace_set()` in an explicit DB transaction:

```php
public function replace_set( int $server_id, array $desired_slugs ): array {
    global $wpdb;
    $wpdb->query( 'START TRANSACTION' );
    try {
        // ... existing normalize + diff + apply logic ...
        $wpdb->query( 'COMMIT' );
        return [ 'added' => $added, 'removed' => $removed ];
    } catch ( \Throwable $e ) {
        $wpdb->query( 'ROLLBACK' );
        throw $e;
    }
}
```

Add SC-011 to spec: "Concurrent Save operations produce a deterministic last-writer-wins state — the value of the store after two overlapping POSTs equals exactly the second POST's request body."

Alternative (weaker): document the race in `spec.md` §Edge Cases as an accepted quirk and update FR-015 language from "last-writer-wins" to "last-committer-wins with possible set-union interleave". Only acceptable if the operator population is small and the semantic surprise is triaged.

**Not blocking** — but do NOT ship without picking one path. The current spec's `FR-015 last-writer-wins` claim is technically false as-implemented.

---

### SEC-020-003 — Server-deletion cascade hook name unspecified

- **finding_id**: SEC-020-003
- **location**: `specs/020-per-server-tool-selection/data-model.md:87-89` (Deletion hook TBD-during-implementation)
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-1188: Initialization of a Resource with an Insecure Default
- **cvss_score**: 3.7 (LOW — vector: `AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:L`)
- **spec_kit_task**: TASK-SEC-020-003

**Description**

FR-026 mandates that F020 own the cascade cleanup on MCP server deletion. `data-model.md §MCPServer §Deletion hook` defers the concrete action name to implementation: "Concrete action name to be identified during implementation and documented in tasks.md — likely `acrossai_mcp_server_deleted` or `deleted_post` for whatever CPT/mechanism represents servers."

If the wrong action is chosen — or if the correct action doesn't fire on all deletion paths (e.g., admin UI vs WP-CLI vs REST vs bulk-delete) — cascade fails silently. Orphaned rows accumulate. Under sufficient churn, the presence-based invariant "row exists ⟺ operator added this tool" degrades.

**Impact**

- Not a privilege issue (orphaned rows are inert; no server_id → no MCP client sees them).
- Is a data-hygiene + attack-surface-audit issue: `SELECT * FROM wp_acrossai_mcp_server_tools` shows tool selections for servers that don't exist. Auditors chasing "which servers expose which tools" get confused.

**Recommendation**

Before Phase 2 tasks, grep the codebase for all server-deletion code paths:

```
grep -rEn "delete.*server\|DELETE FROM.*mcp_servers\|MCPServerQuery.*delete" includes/ admin/ public/
```

Enumerate every path (admin form handler, WP-CLI command, REST endpoint). Wire the cascade cleanup callback to whichever action ALL paths fire (or add a single-source-of-truth action that ALL paths fire — e.g., `acrossai_mcp_server_deleted( int $server_id )` in `MCPServerQuery::delete_item()`).

Document the chosen action name in `data-model.md §Deletion hook` and in `tasks.md` as a first-class task, not a deferred implementation detail.

**Not blocking** — but the current "identify during implementation" wording accepts a silent-failure mode.

---

### SEC-020-004 — Uncaught observer exceptions can 500 the POST endpoint

- **finding_id**: SEC-020-004
- **location**: `specs/020-per-server-tool-selection/contracts/js-hooks.md:145` (`acrossai_mcp_tools_changed` contract: "Callback exceptions are NOT caught by the controller")
- **owasp_category**: A04:2025-Insecure Design
- **cwe**: CWE-693: Protection Mechanism Failure
- **cvss_score**: 3.1 (LOW — vector: `AV:N/AC:H/PR:H/UI:R/S:U/C:N/I:L/A:L`)
- **spec_kit_task**: TASK-SEC-020-004

**Description**

The action contract explicitly documents: *"Callback exceptions are NOT caught by the controller — a broken observer can 500 the request. Consumers should try/catch defensively."*

Under adversarial-admin conditions (compromised admin creds installing a malicious mu-plugin), this means one observer callback throwing can:

1. 500 the POST — save fails, but only AFTER `Query::replace_set()` committed. Client sees error, DB has new state, client `added` state re-fetches to the new (unexpected) truth. Confusion.
2. Prevent later observers from firing — legitimate audit consumers registered after the malicious one never observe the change.

Under normal-admin conditions, the risk is a well-meaning mu-plugin whose exception behavior surprises operators.

**Recommendation**

Wrap the `do_action()` calls in try/catch inside the controller:

```php
foreach ( $applied['added'] as $slug ) {
    try {
        do_action( 'acrossai_mcp_tools_changed', [
            'server_id'    => $server_id,
            'ability_slug' => $slug,
            'operation'    => 'added',
        ] );
    } catch ( \Throwable $e ) {
        error_log( sprintf(
            '[acrossai_mcp_tools_changed] observer error for %s on %d: %s',
            $slug, $server_id, $e->getMessage()
        ) );
    }
}
```

Update `contracts/js-hooks.md` to guarantee the try/catch — the controller isolates observers from each other and from the REST response cycle. Matches the safeApplyFilters JS-side pattern.

**Not blocking** — but recommended before implementation because retrofitting later requires updating a public contract.

---

### SEC-020-005 — POST body `tools` field lacks explicit `args` schema in the contract

- **finding_id**: SEC-020-005
- **location**: `specs/020-per-server-tool-selection/contracts/rest-api.md:76-84` (Request body specified informally)
- **owasp_category**: A05:2025-Security Misconfiguration
- **cwe**: CWE-20 (via CWE-693)
- **cvss_score**: 2.0 (INFORMATIONAL — vector: `AV:N/AC:L/PR:H/UI:N/S:U/C:N/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-020-005

**Description**

The POST contract shows an example JSON body and prose describes validation ("Missing/null/non-array field returns 400"), but the WordPress REST `args` schema is not specified. Implementation risk: without an explicit `args` schema (`type => 'array'`, `items => [ 'type' => 'string' ]`, `required => true`, `sanitize_callback`, `validate_callback`), the controller relies on ad-hoc code-path validation, which is error-prone.

**Recommendation**

Add an `args` block to the contract:

```php
'args' => [
    'tools' => [
        'type'              => 'array',
        'items'             => [ 'type' => 'string' ],
        'required'          => true,
        'sanitize_callback' => static fn ( $v ) => array_map( 'sanitize_text_field', (array) $v ),
        'validate_callback' => static fn ( $v ) => is_array( $v ) || new WP_Error( 'rest_invalid_type', ..., [ 'status' => 400 ] ),
    ],
],
```

Update `contracts/rest-api.md` §Route 2 §Request with this explicit schema. Guarantees WP REST middleware rejects malformed bodies before controller code executes.

**Not blocking** — INFORMATIONAL because the current prose ("returns 400") describes the correct behavior, just not the mechanism.

---

### SEC-020-006 — Observer payload omits actor identity (audit trail gap)

- **finding_id**: SEC-020-006
- **location**: `specs/020-per-server-tool-selection/contracts/js-hooks.md:130-141` (`acrossai_mcp_tools_changed` payload contains slug + server_id + operation only)
- **owasp_category**: A08:2025-Software and Data Integrity Failures
- **cwe**: CWE-778: Insufficient Logging
- **cvss_score**: 2.7 (INFORMATIONAL — vector: `AV:N/AC:H/PR:H/UI:N/S:U/C:N/I:L/A:N`)
- **spec_kit_task**: TASK-SEC-020-006

**Description**

The `acrossai_mcp_tools_changed` action payload is deliberately minimal (`server_id`, `ability_slug`, `operation`). Rationale in `contracts/js-hooks.md`: "Payload contains no user IDs, IP addresses, or session identifiers — matches Security Checklist §'No secrets logged'." This is a defensible privacy stance but has a cost: audit-log consumers cannot answer "who made this change?" without correlating with WordPress core audit logs (which may not be enabled).

F017's parallel action `acrossai_mcp_ability_exposure_changed` follows the same convention. Consistency is a valid reason to keep the omission.

**Recommendation**

Two options — pick either explicitly:

1. **Preserve minimal payload** — add a note to `contracts/js-hooks.md`: "Audit consumers wanting actor identity SHOULD subscribe to WordPress core `user_register` / `wp_login` / activity plugins for actor correlation. F020 declines to include actor identity in the action payload to preserve consumer privacy."

2. **Add opt-in actor field** — include `user_id => get_current_user_id()` at fire time. Consumers who don't need it can ignore it; consumers who do need it get it without extra plumbing.

**Not blocking** — INFORMATIONAL. Depending on future audit requirements this may become HIGH.

---

## Confirmed Secure Patterns

These design choices are explicitly correct and should be preserved through implementation:

- **REST permission callbacks** — both routes explicitly check `current_user_can( 'manage_options' )`. No `__return_true`. Compliant with S2 + Principle III.
- **Nonce enforcement** — client uses `@wordpress/api-fetch` nonce middleware seeded from `wp_create_nonce( 'wp_rest' )`; server verifies via WordPress core REST middleware. Compliant with S1.
- **Prepared statements** — all DB access via BerlinDB's inherited layer. No raw `$wpdb->query()` with interpolated values. Compliant with S4.
- **POST payload validation** — every slug filtered against `wp_get_abilities()` before persistence. Rejects the whole batch on any invalid slug. Prevents B7 mass-assignment.
- **Excluded slugs defense-in-depth** — the three `mcp-adapter/*` protocol tools are hard-coded in both client and server code. Defense-in-depth against a user manually crafting a payload that bypasses UI filtering.
- **Server-id boundary** — every REST call validates `server_id` resolves to a real row; 404 otherwise. Prevents cross-server data disclosure (parallel to F017's server-id check).
- **Uninstall gate** — DROP TABLE + `delete_option` sit BELOW the F012 opt-in gate. No second gate added. Compliant with `DEC-UNINSTALL-OPT-IN-GATE`.
- **`untrailingslashit( rest_url() )`** — client-side base URL correctly stripped. Prevents B17.
- **Presence-based storage** — no boolean-with-third-state hazard. UNIQUE(server_id, ability_slug) enforces correctness at the DB level.
- **Phantom-version guard** — Table subclass overrides `maybe_upgrade()` to self-heal a stamped-option-with-missing-table condition. Silent per F011 Clarification Q1.
- **safeApplyFilters JS boundary** — broken third-party JS filter callbacks logged to console but do not white-screen the mount. Matches F017's defensive pattern.
- **PII omission from observer payload** — SEC-020-006 flags this as gap-with-trade-off, but the current design is defensible (matches F017 precedent).

---

## Action Plan & Next Steps

### Blocking gates before `/speckit-tasks`

1. **SEC-020-001 (HIGH)**: Add FR-029 (or higher) naming the enforcement hook, priority, deny-precedence contract, and fail-open behavior. Recommend mirroring F017 FR-030's shape verbatim, adjusted for presence-based storage. **Blocking**.

### Recommended before `/speckit-implement`

2. **SEC-020-002 (MEDIUM)**: Update `data-model.md §Query API` to specify DB transaction wrap on `replace_set()`. Update SC-011 or add SC-011 in spec.
3. **SEC-020-003 (LOW)**: Enumerate server-deletion code paths + name the cascade hook explicitly in `data-model.md`.
4. **SEC-020-004 (LOW)**: Update `contracts/js-hooks.md` — controller MUST wrap `do_action` in try/catch.

### Recommended during implementation

5. **SEC-020-005 (INFORMATIONAL)**: Add explicit `args` schema to `contracts/rest-api.md §Route 2`.
6. **SEC-020-006 (INFORMATIONAL)**: Decide + document actor-identity policy in `contracts/js-hooks.md`.

### Durable Memory Preservation

**Deferred**: no new systemic security patterns were identified that don't already exist in memory. The enforcement-hook-priority pattern was captured for F017 via D18 (`mcp_adapter_pre_tool_call`). F020 should REUSE that pattern, not fork it. If F020 introduces its own enforcement hook at a new priority slot (e.g., 30), add a companion row under D18 or a new decision like `DEC-F020-TOOL-ENFORCEMENT-PRIORITY` — but only after F020 makes the call.

### Remediation Planning

**Recommendation**: run `/speckit-security-review-followup` after SEC-020-001 is addressed in the spec/plan/data-model. That command materializes each finding above into a `TASK-SEC-020-NNN` task in `tasks.md` so `/speckit-tasks` can sequence remediation alongside feature work.

---

## Memory Hub INDEX.md Row

Paste into `docs/memory/INDEX.md` §Security Reviews:

```text
| docs/security-reviews/2026-07-09-020-per-server-tool-selection-plan.md | plan | 2026-07-09 | HIGH | C:0 H:1 M:1 L:2 | A01,A04,A05,A08 |
```
