---
document_type: security-review
review_type: plan
assessment_date: 2026-07-13
codebase_analyzed: acrossai-mcp-manager (Feature 025 planning artifacts)
total_files_analyzed: 8
total_findings: 3
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 3
owasp_categories: [A01, A04, A08]
cwe_ids: [CWE-441, CWE-362, CWE-1188]
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

# Security Review — Feature 025 Plan

## Executive Summary

**Feature**: 025-server-tools-registration-hooks
**Branch**: `025-server-tools-registration-hooks`
**Plan artifact under review**: `specs/025-server-tools-registration-hooks/plan.md` (2026-07-13)
**Overall risk**: **LOW** — 0 Critical, 0 High, 0 Medium, 0 Low, 3 Informational.

Feature 025 extends Feature 020's per-server tool selection with a hybrid storage layer (three boolean columns on the server row for the fixed protocol set + F020's presence-based rows for the open-ended curated set), wires the composed tool list into `\WP\MCP\Core\McpAdapter::create_server()` for both server-registration paths, adds a companion-plugin filter `acrossai_mcp_manager_server_tools` for the database-server path, and hooks the vendor filter `mcp_adapter_default_server_config` for the default-server path.

The plan preserves every hardened surface from Features 015 / 017 / 020: no new REST routes, `permission_callback` on both existing routes retained, BerlinDB prepared writes for the new column update, nonce handling inherited from F020, and vendor MCP-adapter code untouched. The schema migration is a MINOR ADD-COLUMN with `DEFAULT 1` — no data motion, no destructive DDL. Constitution §III Security First gates are all addressable at implementation time.

Three INFORMATIONAL findings surface UX/documentation concerns and one accepted-race disclosure — none are exploit-adjacent or require code changes to the plan before implementation.

## Plan Artifacts Reviewed

- `specs/025-server-tools-registration-hooks/plan.md` (technical context, constitution check, project structure, complexity tracking)
- `specs/025-server-tools-registration-hooks/spec.md` (17 FRs, 4 stories, 6 SCs, edge cases, security checklist)
- `specs/025-server-tools-registration-hooks/research.md` (6 decisions, alternatives considered)
- `specs/025-server-tools-registration-hooks/data-model.md` (schema deltas, ToolPolicy service, race analysis)
- `specs/025-server-tools-registration-hooks/contracts/filter-acrossai_mcp_manager_server_tools.md`
- `specs/025-server-tools-registration-hooks/contracts/rest-tools-endpoint-semantics.md`
- `specs/025-server-tools-registration-hooks/quickstart.md`
- `specs/025-server-tools-registration-hooks/memory-synthesis.md`

**Memory hub context**: `.specify/memory/constitution.md` v1.1.0, `docs/memory/INDEX.md` (D18 canonical gate, D19 fail-open pattern, S1–S9, B18/B21/B24), F020 plan-review-v2 (2026-07-09) as design precedent for the tool-selection surface.

**Not consulted** (not applicable to F025): OAuth flow docs (F016 retired that surface); ability-selection resolver decisions (F017's DEC-ABILITY-OVERRIDE-RESOLUTION is orthogonal per plan §Constitution Check).

## Vulnerability Findings

### SEC-025-INFO-1 — Companion filter can circumvent operator's protocol-tool removal

| Field | Value |
|---|---|
| **Finding ID** | SEC-025-INFO-1 |
| **Location** | `specs/025-server-tools-registration-hooks/contracts/filter-acrossai_mcp_manager_server_tools.md` §"Return contract" + `plan.md` §Technical Context |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (design observation; not exploitable by external attacker) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design (documentation gap only) |
| **CWE** | CWE-441 — Unintended Proxy or Intermediary ('Confused Deputy') |
| **Spec-Kit task** | TASK-SEC-025-001 |

**Observation**: The new `acrossai_mcp_manager_server_tools` filter (FR-008) explicitly allows companion plugins to add ANY slug to what the server registers, including protocol slugs that the operator has removed via the Tools tab (column value flipped to `0`). Symmetrically, `mcp_adapter_default_server_config` on the default-server path allows the same override.

Concretely: an operator who removes `mcp-adapter/discover-abilities` via the confirmation-dialog UX (US2 flow) intends "no discovery on this server". A companion plugin whose callback returns `array_merge( $tools, [ 'mcp-adapter/discover-abilities' ] )` silently re-enables it. `ToolExposureGate::EXCLUDED_SLUGS` (F020's vestigial bypass, per SEC-025-INFO-3 below) allows the resulting `tools/call` — the operator's removal is nullified without any operator-visible signal.

**Why this is not a security vulnerability**: Companion plugins are privileged code by the WordPress trust model (they run `include`-level PHP inside the same request). Preventing them from modifying tool exposure would require sandboxing, which is out of scope. The operator installs companion plugins consciously.

**Why it deserves surface-level treatment**: The confirmation-dialog copy in FR-003 does not mention that companion plugins can override the removal. An operator following the Tools tab UI without knowing about the filter model would reasonably assume "Remove anyway" is authoritative.

**Recommendation for the plan** (non-blocking):
1. Amend the confirmation-dialog copy in FR-003 with a one-sentence advisory: *"This decision can be overridden by companion plugins that hook the `acrossai_mcp_manager_server_tools` filter."* — OR — leave the copy alone but add a sentence to `docs/extending-server-tools.md` (Task 9) explicitly noting the audit-trail expectation for filter authors.
2. No code change to plan.

**Blocking?** No.

---

### SEC-025-INFO-2 — Accepted race between column update and curated `replace_set` on POST

| Field | Value |
|---|---|
| **Finding ID** | SEC-025-INFO-2 |
| **Location** | `specs/025-server-tools-registration-hooks/data-model.md` §"Two-write POST path — accepted race" + `contracts/rest-tools-endpoint-semantics.md` §"Internal handling on POST" |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 2.7 (Low but rounded down — requires two concurrent `manage_options`-authenticated operators) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design |
| **CWE** | CWE-362 — Concurrent Execution using Shared Resource with Improper Synchronization ('Race Condition') |
| **Spec-Kit task** | TASK-SEC-025-002 |

**Observation**: `ToolsController::post_tools()` writes the two storage layers in sequence:

1. `MCPServerQuery::update_item( $server_id, $columns )` — one UPDATE, three columns.
2. `MCPServerToolQuery::replace_set( $server_id, $curated )` — F020's transactional path (its own `START TRANSACTION`).

The two writes are NOT wrapped in a single outer transaction. Two concurrent saves on the same server_id CAN leave columns from writer A and curated rows from writer B. The `data-model.md` explicitly acknowledges this as an accepted race.

**Threat model**: exploitable only by two authenticated `manage_options` users saving within the millisecond window between the two writes. There is no external-attacker vector; there is no privilege escalation; the resulting state is not exploitable — worst case is that the operator sees an unexpected combination on the next page load and corrects it.

**Why this is acceptable now**:
- The Tools tab is single-operator in practice (site admins rarely coordinate simultaneous saves).
- The window is milliseconds.
- Correction is one Reset or Save away.
- Documenting the race in code + spec keeps it visible for future work.

**Recommendation for the plan** (non-blocking):
1. Ensure the code comment at `ToolsController::post_tools()` (Task 6) explicitly names this as accepted race under a `// SEC-025-INFO-2` marker so the trace persists in blame.
2. Ensure the `docs/extending-server-tools.md` documentation notes the semantic for filter authors who might observe transient inconsistency during the window.
3. No code change to plan.

**Blocking?** No.

---

### SEC-025-INFO-3 — `ToolExposureGate::EXCLUDED_SLUGS` becomes vestigial; grep gate needed at review time

| Field | Value |
|---|---|
| **Finding ID** | SEC-025-INFO-3 |
| **Location** | `plan.md` §Technical Context ("call-time invariant"); `memory-synthesis.md` §Relevant Decisions (DEC-F020-TOOL-ENFORCEMENT-PRIORITY); `includes/MCP/ToolExposureGate.php` (existing F020 file) |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (no active vulnerability; defense-in-depth note) |
| **OWASP Top 10 2025** | A08:2025 — Software and Data Integrity Failures (dead-code concern) |
| **CWE** | CWE-1188 — Initialization of a Resource with an Insecure Default (misapplied here — the concern is *dead default*, not insecure) |
| **Spec-Kit task** | TASK-SEC-025-003 |

**Observation**: F020's `ToolExposureGate::EXCLUDED_SLUGS` bypass — which allows protocol-tool `tools/call` invocations to skip the curation gate — becomes vestigial under F025's DB-authoritative model. If the operator flips a `tool_*` column to `0`, the server no longer registers the protocol slug at all; the adapter refuses calls at the tool-lookup layer, not the gate layer. The `EXCLUDED_SLUGS` bypass is now belt-and-braces safety for stale AI clients that cached a slug from an earlier session — harmless but redundant.

**Why the plan is right to keep it**: removing the bypass in F025 would extend the change surface and require re-reviewing F020's SEC-020-007 (B24 vendor-accessor pattern). Leaving the bypass in place is the conservative choice.

**Concern**: a future developer reading `ToolExposureGate::EXCLUDED_SLUGS` may misinterpret it as "protocol tools are always callable" and use it as justification for a new bypass elsewhere — a green-light-by-precedent risk.

**Recommendation for the plan** (non-blocking):
1. Add a docblock comment at `ToolExposureGate::EXCLUDED_SLUGS` during Task 3 (`Controller` cleanup) or Task 6 (`ToolsController` cleanup) explicitly noting: *"Vestigial post-F025 (2026-07-13). Preserved as safety net for cached AI clients — do NOT use as precedent for new bypass rules; the adapter will refuse unregistered tools regardless."*
2. Consider filing a follow-up ticket to remove the bypass in a future maintenance PR once F025 has soaked for two weeks with no cached-client fallout.
3. No code change to F025 plan.

**Blocking?** No.

## Confirmed Secure Patterns

The following aspects of the plan explicitly satisfy Constitution §III and the plugin's durable security constraints (S1–S9 in memory hub):

1. **S2 — REST `permission_callback` preserved.** Both `GET` and `POST` routes on `/servers/{id}/tools` retain the `manage_options` capability check inherited from F020. No new routes; no `__return_true` introduced. `plan.md` §Constitution Check confirms.
2. **S4 — Prepared statements.** The new column update goes through `MCPServerQuery::instance()->update_item()` which prepares via BerlinDB Kern. No raw SQL added. `data-model.md` §"Two-write POST path" confirms.
3. **S1 — Nonces inherited from F020.** The Tools tab REST calls use the WP core REST nonce middleware (F017 baseline `createNonceMiddleware` per B25). F025's UI changes touch dialog rendering and payload shape; the nonce flow is unchanged.
4. **Input sanitization at boundary.** The three tinyint flags are sanitized via `absint()` at the REST boundary before the `update_item()` call. Slug entries in the `tools` array are `strval`'d + validated against `wp_get_abilities()` per F020's baseline.
5. **Output escaping.** All new dialog copy in `src/js/tools.js` uses `__()` + implicit React-side escaping. No new PHP HTML output surfaces.
6. **B24 — no new vendor-accessor via `instanceof`.** The `filter_default_server_config()` callback receives `array $config` (not a vendor object). `register_database_servers()` receives `\WP\MCP\Core\McpAdapter $adapter` and only calls `$adapter->create_server(...)` — no new duck-typed feature detection needed.
7. **B18 — TINYINT→(int) cast disclosed and mandated.** `Row::__construct()` MUST int-cast the three new columns; `ToolPolicy::compose_for_row()` MUST use `! empty()` rather than `=== 1`. `data-model.md` §"Row shape delta" and `memory-synthesis.md` §Related Historical Lessons both enforce.
8. **Vendor code untouched.** No file under `vendor/wordpress/mcp-adapter/` is edited. Verified in `plan.md` §"Constraints" and reiterated in `spec.md` §Assumptions.
9. **Fail-open observability.** The reuse of `acrossai_mcp_tools_changed` (per FR-016 + spec §Clarifications Q1) follows D19's fail-open pattern — column flips fire the same event stream as F020 curated changes; observers with per-bullet try/catch (F020 inherited) cannot bubble errors to the REST response.
10. **Filter throw safety documented.** `contracts/filter-acrossai_mcp_manager_server_tools.md` §"Return contract" explicitly states throws propagate (standard WordPress behavior); no try/catch is added around `apply_filters()`. Companion authors own throw safety, and the docs will call this out per Task 9.

## Action Plan & Next Steps

### 1. Recommended non-blocking edits (before merge, not before implementation)

- **SEC-025-INFO-1** — Add filter-authors advisory sentence to `docs/extending-server-tools.md` §Filter contract, noting that a callback that re-adds a protocol slug will silently override the operator's UI-facing removal decision.
- **SEC-025-INFO-2** — Include a `// SEC-025-INFO-2 accepted race` marker in `ToolsController::post_tools()` between the two write calls.
- **SEC-025-INFO-3** — Update the `ToolExposureGate::EXCLUDED_SLUGS` docblock with the "vestigial post-F025" note.

### 2. Durable Memory Preservation

The three findings are per-feature UX/documentation observations, not new architectural patterns or repeatable lessons. No `/speckit.memory-md.capture` invocation is warranted from this security review alone. (The separate `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` decision surfaced by the memory synthesis + plan §Complexity Tracking should be captured after implementation lands cleanly, per the F020 WORKLOG lesson to "defer UX-facing ADR capture until AFTER security-review runs cleanly, not pre-plan speculatively" — F025's plan already respects this.)

### 3. Remediation Planning

No CRITICAL or HIGH findings surfaced. `/speckit.security-review.followup` is NOT required. Address the three INFO findings as inline TODOs during implementation Tasks 3, 6, and 9.

### 4. Proceed to Architecture Guard

The plan is safe to hand off to `/speckit.architecture-guard.violation-detection` (Step 5 of the parent `governed-plan` workflow).

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-13-025-server-tools-registration-hooks-plan.md | plan | 2026-07-13 | LOW | C:0 H:0 M:0 L:0 I:3 | A01,A04,A08 |
```
