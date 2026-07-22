---
document_type: security-review
review_type: plan
assessment_date: 2026-07-21
codebase_analyzed: acrossai-mcp-manager (F032 OAuth Per-Server Scoping — plan phase)
total_files_analyzed: 9
total_findings: 6
overall_risk: HIGH
critical_count: 0
high_count: 1
medium_count: 2
low_count: 2
informational_count: 1
owasp_categories: [A01, A03, A05, A09]
cwe_ids: [CWE-346, CWE-208, CWE-841, CWE-359, CWE-918]
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

# SECURITY REVIEW REPORT — PLAN PHASE

## Executive Summary

F032 is a security fix. Its purpose IS to close a critical cross-server privilege-escalation vulnerability on the AI Connectors admin surface. The plan structurally addresses the primary vulnerability well — composite `UNIQUE(client_id, server_id)`, REST-endpoint validation via `find_by_client_id_and_server_id`, 403 on mismatch, NOT-NULL post-migration invariant, auto-purge of legacy DCR rows, and D19 fail-open observability signals — all consistent with the durable memory patterns (D28, D19, S1-S4) referenced in `memory-synthesis.md`.

However, the plan introduces **one HIGH-severity secondary risk** (DCR resource-URL origin verification is not specified beyond "reuse CurrentServerHolder::capture_from_request() normalization" — SSRF / URL-confusion surface if the helper does not verify the URL's origin against this site's known base URL), **two MEDIUM-severity issues** (a new `find_by_client_id_any_server` cross-server oracle helper whose containment relies on process controls only, and a backfill UPDATE that can assign legacy rows to non-existent server_ids), plus a handful of lower-severity latency / race / rollback concerns.

Recommended plan-phase changes: (a) specify origin verification for DCR `resource` URL in `resolve_server_id_from_resource_url` — MUST reject any URL whose origin does not match `home_url()` or `network_home_url()`; (b) narrow the observability action args from `int $server_id_actual` to `bool $exists_on_another_server` OR remove the field entirely to close the cross-server oracle in the do_action listener path; (c) add an `AND EXISTS (SELECT 1 FROM oauth_servers WHERE id = <parsed>)` guard to the backfill UPDATE (or a post-backfill "purge orphaned server_id" step) to prevent legacy rows from being assigned to phantom server_ids.

Plan approval recommendation: **proceed to `/speckit-tasks` after remediating SEC-032-001 and SEC-032-003 inline** (both are small plan-doc edits — no design pivot required). SEC-032-002 (oracle) can be either remediated at plan phase or captured as a mandatory branch-review verification task.

## Plan Artifacts Reviewed

| Artifact | Path | Notes |
|---|---|---|
| Spec | `specs/032-oauth-per-server-scoping/spec.md` | 26 FRs, 4 clarifications integrated 2026-07-21 |
| Plan | `specs/032-oauth-per-server-scoping/plan.md` | Constitution Check all PASS; Security Boundary Matrix present |
| Research | `specs/032-oauth-per-server-scoping/research.md` | 8 research decisions with memory-hub cross-refs |
| Data model | `specs/032-oauth-per-server-scoping/data-model.md` | 3 entities, 6-step upgrade callback ordering |
| Contracts | `specs/032-oauth-per-server-scoping/contracts/*.md` | 4 REST route contracts |
| Quickstart | `specs/032-oauth-per-server-scoping/quickstart.md` | Operator upgrade guide + 10-step dev verification |
| Memory synthesis | `specs/032-oauth-per-server-scoping/memory-synthesis.md` | Retrieval per max_synthesis_words=900 budget |
| Planning brief | `docs/planings-tasks/032-oauth-per-server-scoping.md` | Reconciled 2026-07-21 with 4 clarifications |
| Constitution | `.specify/memory/constitution.md` | v1.1.0 (2026-07-12); no dedicated security_constitution.md exists |

Memory hub context loaded: `docs/memory/INDEX.md` — D28, D19, D27 (OAuth soft-auth), B34 (schema drift), S1-S4, S8 (RFC 6749 body-auth exception). No conflicts with existing durable memory. Two new memory entries expected post-implement (DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS + B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY per `memory-synthesis.md`).

## Vulnerability Findings

### [HIGH] SEC-032-002 — DCR resource-URL origin verification unspecified (URL confusion / SSRF-adjacent surface)

**Location**: `plan.md` §Project Structure → `includes/OAuth/ClientRegistrationController.php` (planned) — `resolve_server_id_from_resource_url( string $resource ): int` helper
Also: `contracts/dcr-register.md` §Resource URL Resolution; `data-model.md` Entity 1 state transitions; `research.md` R8 references.
**OWASP Category**: A01:2025-Broken Access Control (adjacent to A10-SSRF)
**CWE**: CWE-346: Origin Validation Error
**CVSS Score**: 7.1 (HIGH) — attacker-controlled DCR resource URL with no explicit origin check on the plugin side; impact bounded by MCPServerQuery's own URL-shape assumptions but insufficient as documented
**Description**: The plan says `resolve_server_id_from_resource_url` "reuses the existing route-matching helper from `CurrentServerHolder::capture_from_request()` for consistent normalization (trailing slash, port differences, IPv6 hosts)." Consistent normalization is a good UX property, but the plan does NOT specify that the DCR handler MUST reject any URL whose origin (scheme + host + port) does not match `home_url()` or `network_home_url()`. If `CurrentServerHolder`'s route-matcher accepts any URL that STRUCTURALLY matches the wp-json path segment (e.g., `https://evil.attacker.com/wp-json/mcp/server-1-slug` because the path portion parses cleanly), an attacker sending a DCR request via CSRF or a malicious MCP host could resolve to server_id 1 on THIS site while pointing at an evil origin. This becomes especially dangerous if the DCR flow later fetches the resource metadata from the URL (SSRF vector).

The DCR endpoint accepts body auth per S8 (RFC 6749 §2.3.1 exception), meaning no nonce or cap check gates request formation. This makes the endpoint reachable by anyone, so URL origin verification is the primary defence.

**Remediation**:
1. Explicit FR addition to spec.md: "FR-027: `resolve_server_id_from_resource_url` MUST reject any `resource` URL whose origin (scheme, host, port) does not match `home_url()`. On origin mismatch, return `WP_Error( 'invalid_target', 400 )` with distinct log message `resource_url_origin_mismatch` for observability differentiation from generic path-mismatch."
2. Update `contracts/dcr-register.md` §Resource URL Resolution to spell out the two-step check: (a) origin match against `home_url()`, (b) path resolution against `MCPServerQuery`.
3. Add PHPUnit test case DCR-007 to `contracts/dcr-register.md` Test Cases table: "Malicious `resource` URL with correct wp-json path but attacker-controlled origin → 400 `invalid_target`; verify no client row created."
4. Update `planning-doc TASK-6` to include the origin-check code in the `resolve_server_id_from_resource_url` snippet.

**Spec-Kit Task**: TASK-SEC-032-001

---

### [MEDIUM] SEC-032-001 — Cross-server oracle via `find_by_client_id_any_server` helper + `$server_id_actual` observability arg (process-only containment)

**Location**: `plan.md` §Project Structure → `includes/Database/OAuthClients/Query.php` `find_by_client_id_any_server()` (planned NEW)
Also: `planning-doc TASK-5` REST handler code snippet; `data-model.md` Entity 1 Query.php deltas; `contracts/revoke-client-tokens.md` §Side effect (FR-023).
**OWASP Category**: A01:2025-Broken Access Control
**CWE**: CWE-359: Exposure of Private Personal Information to an Unauthorized Actor (adjacent to CWE-841 Improper Enforcement of Behavioral Workflow)
**CVSS Score**: 5.4 (MEDIUM) — impact scoped to same-process WordPress plugin listeners; hostile-plugin scenario has non-trivial attacker prerequisites
**Description**: F032 introduces a new internal-only helper `ClientsQuery::find_by_client_id_any_server( string $client_id ): ?Row` whose ONLY caller is `ConnectorAdminController` in the observability path — it's used to populate `$server_id_actual` in the `do_action( 'acrossai_mcp_oauth_cross_server_attempted', ..., $server_id_actual, ... )` fire before returning 403.

Two coupled concerns:
1. **Weak helper containment**: the plan enforces "MUST NOT be exposed publicly or used to grant cross-server access" via docblock + grep gate only. A future developer refactoring ConnectorAdminController or adding a new REST endpoint could easily call this helper and use the result to check "does this client_id belong to any server?" — the exact cross-server oracle F032 exists to close, just relocated from REST body to Query surface.
2. **Observability arg leaks cross-server binding**: the `$server_id_actual` int argument of the do_action reveals to ANY listener (including hostile plugins hooking the same action) which server the client_id actually belongs to. WordPress plugins can hook any action without permission checks. A hostile plugin installed on the same site (either malicious upload or supply-chain compromise) instantly gets a live cross-server oracle by attaching a listener that logs `$client_id → $server_id_actual` pairs.

Combined, these mean the 403's "no cross-server existence leak" invariant is defended for the HTTP response body but silently violated on the internal signal path.

**Remediation** (pick one — Option A recommended for maximum simplicity):

**Option A (recommended)**: Remove `find_by_client_id_any_server` entirely. Change the do_action arg from `int $server_id_actual` to omission — fire `do_action( 'acrossai_mcp_oauth_cross_server_attempted', $client_id, $server_id_requested, $user_id, $timestamp )` with 4 args instead of 5. Operators who want to know which server the client belongs to can query the DB themselves inside their listener (they have full DB access from within a listener; the plugin doesn't need to help). Reduces the helper surface + closes the oracle in the action arg simultaneously.

**Option B**: Keep the helper but change the do_action arg from `int $server_id_actual` to `bool $exists_on_another_server`. Loses some forensic precision but eliminates the oracle. Add a large security-note docblock on `find_by_client_id_any_server` explaining the containment invariant.

Update `spec.md` FR-023 args list. Update `planning-doc TASK-5` snippet. Update `contracts/revoke-client-tokens.md` and `contracts/delete-client.md` §Side effect sections. Add a governance grep gate: `grep -rn 'find_by_client_id_any_server' includes/` MUST return only the definition + the single ConnectorAdminController call site (if Option B kept).

**Spec-Kit Task**: TASK-SEC-032-002

---

### [MEDIUM] SEC-032-003 — Backfill can assign legacy rows to non-existent `server_id`s (parse trusts prefix without existence check)

**Location**: `planning-doc TASK-1` upgrade callback Step 2; `data-model.md` §Upgrade callback ordering (OAuthClients Step 2); `research.md` R1.
**OWASP Category**: A05:2025-Security Misconfiguration
**CWE**: CWE-841: Improper Enforcement of Behavioral Workflow
**CVSS Score**: 4.3 (MEDIUM) — attacker cannot directly reach this code path; but backfill-produced orphan rows create a runtime footgun exploitable via future features
**Description**: The OAuthClients backfill UPDATE is:

```sql
UPDATE oauth_clients
SET server_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(client_id, '-', 2), '-', -1) AS UNSIGNED)
WHERE server_id IS NULL AND client_id LIKE 'server-%'
```

This trusts the `client_id` prefix without verifying the parsed `server_id` actually exists in `wp_acrossai_mcp_servers`. Failure modes:

- A legacy admin client whose server was previously deleted (server row gone but client row lingered) gets assigned to a non-existent `server_id`. Post-PURGE (Step 3 only removes `server_id IS NULL` rows), this orphan survives with a phantom `server_id`.
- A malformed prefix like `server-99999-...` where 99999 was never a real server_id gets an integer assignment that doesn't match anything.
- A test/staging install with previously-cloned server IDs vs a different set of live server IDs produces phantom assignments post-clone.

Post-migration these phantom-server rows survive the PURGE step (which only targets `server_id IS NULL`) and become invisible in the UI (per every server's tab filter) but present in the DB. If a future feature exposes an "orphaned OAuth clients" report OR if an operator manually creates a server with an ID matching the phantom, the phantom rows suddenly become visible/associated with the wrong server.

**Remediation**: Change the backfill UPDATE to verify server existence:

```sql
UPDATE oauth_clients
SET server_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(client_id, '-', 2), '-', -1) AS UNSIGNED)
WHERE server_id IS NULL
  AND client_id LIKE 'server-%'
  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(client_id, '-', 2), '-', -1) AS UNSIGNED)
      IN (SELECT id FROM {$wpdb->prefix}acrossai_mcp_servers)
```

Rows whose parsed server_id doesn't match a real server row are left as `server_id IS NULL`, then correctly PURGED in Step 3 (same fate as pre-F032 DCR rows — the operator's servers were deleted, so the OAuth clients bound to them are orphaned and correctly removed).

Update `planning-doc TASK-1` Step 2 SQL. Update `data-model.md` upgrade ordering. Update `quickstart.md` §Post-Upgrade Verification with a new check: `wp db query "SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_clients WHERE server_id NOT IN (SELECT id FROM wp_acrossai_mcp_servers)"` — expect 0.

**Spec-Kit Task**: TASK-SEC-032-003

---

### [LOW] SEC-032-004 — Timing side-channel on cross-server 403 (extra DB lookup reveals cross-server existence via latency)

**Location**: `planning-doc TASK-5` REST handler code snippet — `handle_revoke_client_tokens` observability path.
**OWASP Category**: A09:2025-Security Logging and Monitoring Failures (adjacent to A01)
**CWE**: CWE-208: Observable Timing Discrepancy
**CVSS Score**: 3.1 (LOW) — network attackers face high jitter; localhost/co-tenant attackers can reliably time-attack
**Description**: The observability path fires `find_by_client_id_any_server` (extra DB lookup) before the 403 return. Two response latencies emerge:
- Client_id genuinely doesn't exist anywhere → single `find_by_client_id_and_server_id` returns NULL → extra `find_by_client_id_any_server` returns NULL → fast path.
- Client_id exists on a different server → single `find_by_client_id_and_server_id` returns NULL → extra `find_by_client_id_any_server` returns the Row (extra work) → slower path.

An attacker can probe cross-server existence via response-time distribution analysis. Mitigation partially aligned with SEC-032-001 Option A (removing the helper eliminates the differential too).

**Remediation**:
- If SEC-032-001 Option A adopted: this finding auto-closes.
- If SEC-032-001 Option B adopted (helper retained): make the extra lookup unconditional — perform `find_by_client_id_any_server` first, always, then decide 200/403 based on server match. Equalizes the latency profile.

**Spec-Kit Task**: TASK-SEC-032-004 (may be closed as duplicate if SEC-032-001 Option A adopted)

---

### [LOW] SEC-032-005 — DCR registration race between deploy and upgrade fires can silently destroy legitimate registrations

**Location**: `spec.md` §Edge Cases "Race condition — DCR request during upgrade"; `research.md` R2.
**OWASP Category**: A05:2025-Security Misconfiguration
**CWE**: CWE-841: Improper Enforcement of Behavioral Workflow
**CVSS Score**: 3.1 (LOW) — narrow window (single admin request between deploy and upgrade fire) and only affects DCR requests, not admin-generated ones
**Description**: Between plugin file replacement and the next admin request triggering `Main::reconcile_database_schemas()` on `admin_init@3`, a DCR request could arrive. It sees the pre-migration schema (no server_id column), successfully creates a client row with unset server_id (the plugin code from the new version tries to persist server_id but the pre-migration column doesn't exist → wpdb warning → row created with default field state). When the migration fires on the next admin request, the row gets purged (`WHERE server_id IS NULL`) — silently destroying a legitimate live registration whose OAuth flow may already be in progress.

**Remediation**:
1. **Detection**: `ClientRegistrationController::handle_register` MUST verify the `server_id` column exists on `oauth_clients` before INSERT. If missing, return `WP_Error( 'service_unavailable', 'Server initialization in progress; please retry in a few seconds.', array( 'status' => 503 ) )`. This causes retry-friendly failure for AI-host clients that respect Retry-After semantics.
2. **Alternative**: On `Main::plugins_loaded` at very high priority, run a lightweight schema-check that either fires migration IMMEDIATELY (blocking further request handling) OR sets a `wpdb_acrossai_mcp_migration_pending` transient that the DCR endpoint checks.

Add a new FR to spec.md: "FR-028: DCR endpoint MUST verify `server_id` column presence via `INFORMATION_SCHEMA.COLUMNS` (cached per-request) before INSERT. On absent column, return 503 `service_unavailable` — do NOT INSERT."

**Spec-Kit Task**: TASK-SEC-032-005

---

### [INFORMATIONAL] SEC-032-006 — Rollback path leaves composite UNIQUE constraint (availability, not security)

**Location**: `quickstart.md` §Rollback.
**OWASP Category**: A05:2025-Security Misconfiguration (marginal)
**CWE**: N/A
**CVSS Score**: 0 (INFORMATIONAL) — not a security concern; availability/operability concern surfaced for completeness
**Description**: Quickstart §Rollback correctly notes that composer downgrade leaves the composite `UNIQUE(client_id, server_id)` intact. Pre-F032 code expects the standalone `UNIQUE(client_id)` — if a DCR client registered on two servers post-F032 gets rolled back, pre-F032 INSERT of a new client with either row's `client_id` would fail with `Duplicate entry` from the composite constraint (which pre-F032 code doesn't know about). Symptom: DCR endpoint returns 500 on any registration attempt after rollback.

**Remediation** (documentation-only):
- Extend quickstart.md §Rollback with a full-recovery SQL: `ALTER TABLE wp_acrossai_mcp_oauth_clients ADD UNIQUE KEY client_id (client_id), DROP INDEX client_id_server_id;` — with a warning that this SQL will FAIL if any two rows share the same client_id (which is exactly the F032 "same DCR on multiple servers" scenario). Rollback recovery must first pick one server's row and DELETE the others.
- OR mark F032 as forward-only, adding a clear "F032 is not safely rollbackable to a previous minor version once multi-server DCR clients exist on the install" note.

**Spec-Kit Task**: TASK-SEC-032-006 (documentation only)

---

## Confirmed Secure Patterns

The following pre-existing patterns are correctly preserved or applied in the F032 plan:

- **S1 nonce coverage**: F024's existing `acrossai_mcp_manager_connector` nonce covers the three mutating admin endpoints; F032 adds no new form/AJAX surface that would need a new nonce. ✅ Preserved.
- **S2 permission_callback on REST**: every mutating route retains `manage_options`; the DCR route retains its S8 body-auth exception with unchanged shape (F032 adds `resource`-required validation on top). ✅ Preserved.
- **S3 hashed OAuth tokens**: F032 changes no secret-storage semantics; access_token + refresh_token remain SHA-256 hashed per F021. ✅ Preserved.
- **S4 `$wpdb->prepare()`**: BerlinDB Query uses prepared statements; F032's new upgrade callbacks use `INFORMATION_SCHEMA` gates via `$wpdb->prepare()` and hardcoded schema-literal DDL (no attacker-controllable input in the SQL). ✅ Preserved.
- **S5 `esc_url()` in HTML**: F032's `data-acrossai-server-id` attribute uses `esc_attr( (int) $server_id )`, correctly using the most specific escaper for the numeric context. ✅ Correctly applied.
- **S6 singleton with private ctor**: all F032-touched classes are existing singletons. ✅ Preserved.
- **D19 fail-open observability**: two new `do_action` signals follow the established fire-and-forget shape (no hard listener dependency, `(int)` casts on IDs, `(string)` on client_id). ✅ Correctly applied (subject to SEC-032-001 arg-set change).
- **D27 confidential-client soft-auth via PKCE**: F032's TokenController changes preserve F029's PKCE-only fallback path for `authorization_code` + `refresh_token` grants (research.md R1 references this). ✅ Preserved.
- **D28 BerlinDB schema-drift reconciliation**: 3-part contract correctly applied per table (bump `$version` + register `$upgrades` + idempotent callback with `INFORMATION_SCHEMA` gates). ✅ Correctly applied (subject to SEC-032-003 backfill guard).
- **D29 six-layer defensive gating**: F030's permission-callback bypass carve-out is not disturbed by F032; F032's REST validation runs at endpoint boundary, D29's `permission_callback` bypass runs at `wp_register_ability_args` P999999 — disjoint layers. ✅ Preserved.
- **B34 silent write-loss prevention**: F032 IS the fix for exactly this class of bug applied to the OAuth tables. ✅ Correctly applied.
- **B36 inline `<script>` interpolation via `wp_json_encode()`**: F032 introduces no inline `<script>` interpolations; the F024 JS bundle reads data-attributes, not embedded PHP. ✅ N/A but pattern preserved for the codebase.
- **NOT NULL invariant at SQL layer** (Q4 decision): schema-enforced strong invariant, matches how `client_id` is already declared. ✅ Best practice.
- **403 (not 404) on cross-server mismatch** (R8): correctly prevents cross-server existence leak in response body. ✅ Correctly specified.
- **Registration-order awareness** (R2): tokens/auth-codes callbacks BEFORE clients so JOIN backfill can resolve source rows before purge. ✅ Explicit in research.md + data-model.md + planning-doc.

## Trust Boundary Analysis

| Boundary | F032 Change | Status |
|---|---|---|
| REST body → REST handler | Adds `server_id` required param; validates via composite lookup | ✅ Trust boundary honored |
| REST handler → BerlinDB Query | New composite-key lookup replaces prefix-parse workaround | ✅ Boundary strengthened |
| RFC 8707 `resource` URL → Server ID | Reuses `CurrentServerHolder` normalization | ⚠️ **SEC-032-002 gap** — origin verification not specified |
| Upgrade callback → OAuth data | Idempotent DDL + backfill + purge + MODIFY NOT NULL | ⚠️ **SEC-032-003 gap** — backfill trusts prefix parse |
| do_action fire → any listener | New signals per D19 | ⚠️ **SEC-032-001 gap** — `$server_id_actual` leaks cross-server binding to any listener |
| DCR endpoint → OAuth clients | Adds server_id capture + reject on invalid_target | ⚠️ **SEC-032-005 gap** — race between deploy and migration |
| Rollback → OAuth tables | Composite UNIQUE not backed out | ℹ️ **SEC-032-006 note** — documentation gap |

## Action Plan & Next Steps

### Remediation Priority

| Priority | Findings | Action |
|---|---|---|
| **Must-fix at plan phase** | SEC-032-002 (HIGH), SEC-032-003 (MEDIUM) | Edit spec.md + planning-doc + data-model.md + contracts inline before `/speckit-tasks`. Both are small doc edits (~1–2 paragraphs each). |
| **Recommended at plan phase** | SEC-032-001 (MEDIUM), SEC-032-005 (LOW) | Recommend adopting SEC-032-001 Option A (remove helper + reduce action arity from 5 → 4) — simplest resolution and eliminates SEC-032-004 as a side benefit. SEC-032-005 requires a new FR-028 (503 response on missing column). |
| **Documentation-only** | SEC-032-006 (INFORMATIONAL) | Extend `quickstart.md` §Rollback. Can defer to a follow-up doc PR. |

### Extension Hooks

**Recommended follow-up**:
- If the user accepts Option A for SEC-032-001, edit spec.md + planning-doc immediately (this turn or next), then re-run `/speckit-security-review-plan` for verification (`-v2` shape mirrors F025 plan-review v1 → v2).
- Otherwise, capture the four MUST-VERIFY items (SEC-032-001, 002, 003, 005) as first-class remediation tasks under `/speckit-security-review-followup` (recommended by the skill outline for HIGH findings).

### Durable Memory Preservation (Mandatory Check)

No new architectural patterns or repeatable security lessons emerged from this review beyond what F032's own DEC-F032-OAUTH-SERVER-ID-FIRST-CLASS + B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY entries (captured post-implement via `/speckit-memory-md-capture-from-diff`) will already cover. **Skipping proactive `/speckit-memory-md-capture` this turn** — the SEC-032-002 URL-origin-verification lesson may become a generalizable BUG-pattern candidate once seen in a second feature (per D13 escalation threshold), but with a single occurrence it's premature to codify.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-21-032-oauth-per-server-scoping-plan.md | plan | 2026-07-21 | HIGH | C:0 H:1 M:2 L:2 | A01,A03,A05,A09 |
```
