---
document_type: security-review
review_type: plan
assessment_date: 2026-07-14
codebase_analyzed: acrossai-mcp-manager (Feature 026 planning artifacts + F025 shipping source cross-check)
total_files_analyzed: 10
total_findings: 2
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 2
owasp_categories: [A04]
cwe_ids: [CWE-670, CWE-754]
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

# Security Review — Feature 026 Plan (v2 — close-in-substance)

## Executive Summary

**Feature**: 026-abilities-into-tool-registration
**Review type**: v2 close-in-substance pass. Follows the `DEC-F025-V2-VENDOR-SOURCE-CROSS-CHECK-CADENCE` durable-memory rule established after F020 / F025 — static plan-review claims about vendor-source hook timing and cross-module contract must be verified against actual shipping code.
**Plan artifacts under review**: `plan.md`, `data-model.md`, `contracts/filter-*.md`, `research.md`, `quickstart.md`, `memory-synthesis.md`.
**v1 review**: [`2026-07-14-026-abilities-into-tool-registration-plan.md`](./2026-07-14-026-abilities-into-tool-registration-plan.md) — 3 INFO, LOW overall.
**v2 overall risk**: **LOW** — 0 Critical, 0 High, 0 Moderate, 0 Low, 2 Informational.

v2 performed a targeted cross-check against three high-risk claim classes:

1. **Contract fidelity to F017 `ExposureResolver::resolve()`** — verified against `includes/Database/MCPServerAbility/ExposureResolver.php` shipping source. All claims accurate.
2. **Hook timing for `wp_get_abilities()` at F026 registration time** — traced the full `rest_api_init → mcp_adapter_init → register_database_servers` chain against `Main.php:513-514` + `Controller.php:86-102` shipping source. Third-party abilities register on `wp_abilities_api_init` (during `init`, before `rest_api_init`), so `wp_get_abilities()` at F026's call time correctly returns third-party abilities.
3. **Line references in plan's `TASK-2` targeting** — verified against `Controller.php:142` and `Controller.php:247` shipping source. Both line numbers correct.

**Vendor source cross-check (results)**:

| Contract text vs. vendor / F017 source | Verification | Verdict |
|---|---|---|
| `ExposureResolver::resolve( int, string, array ): bool` signature | `ExposureResolver.php:53` — signature matches; per-request static cache at line 40; row-in-table precedence at lines 67-69; `meta.mcp.public` fallback at line 71 | ✓ Plan claims correct. |
| Line 142 in `register_database_servers()` calls `compose_for_row($server)` | `Controller.php:142` — `$tools = ToolPolicy::compose_for_row( $server );` — matches plan | ✓ TASK-2 line ref correct. |
| Line 247 in `filter_default_server_config()` calls `compose_for_row($rows[0])` | `Controller.php:247` — `$tools = ToolPolicy::compose_for_row( $rows[0] );` — matches plan; immediately followed by `if ( empty( $tools ) ) return $config;` short-circuit at line 248-250 | ✓ TASK-2 line ref correct + short-circuit context accurate. |
| Post-filter defensive re-normalize at `Controller.php:163` handles hostile filter returns | `Controller.php:163` — `$tools = array_values( array_unique( array_map( 'strval', (array) $tools ) ) );` — verbatim match | ✓ Companion-plugin safety net intact. |
| Hook chain: `rest_api_init` → `initialize_adapter` → `add_action('mcp_adapter_init', priority 11)` → `register_database_servers` | `Main.php:513` (`rest_api_init` → `initialize_adapter`); `Controller.php:102` (`add_action('mcp_adapter_init', 'register_database_servers', 11)`) — matches | ✓ Timing chain verified. |

Two INFORMATIONAL findings surface — both are behavioral-semantic clarifications, not runtime bugs. Neither blocks implementation.

## Vulnerability Findings

### SEC-026-v2-1 — Empty-set fallback in `filter_default_server_config()` shifts semantics under F026

| Field | Value |
|---|---|
| **Finding ID** | SEC-026-v2-1 |
| **Location** | `includes/MCP/Controller.php:248-250` (F025 shipping code); `specs/026-abilities-into-tool-registration/plan.md` §TASK-2 (F026 swap); `specs/026-abilities-into-tool-registration/spec.md` §Edge Cases |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 2.7 (documented behavioral shift; not exploitable) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design |
| **CWE** | CWE-670 — Always-Incorrect Control Flow Implementation (F025's short-circuit fires less often under F026 without the plan acknowledging the shift) |
| **Spec-Kit task** | TASK-SEC-026-v2-1 |

**Observation**: F025 shipped a defensive short-circuit at `Controller::filter_default_server_config()`:

```php
$tools = ToolPolicy::compose_for_row( $rows[0] );
if ( empty( $tools ) ) {
    return $config;  // Vendor defaults win — "safer fallback" per SEC-025-v2-1
}
$config['tools'] = $tools;
return $config;
```

Under F026, the swap becomes:

```php
$tools = ToolPolicy::compose_effective_tools_for_row( $rows[0] );  // NEW composer
if ( empty( $tools ) ) {
    return $config;  // Fires ONLY when protocol + curated + F017-effective are ALL empty
}
```

The fallback branch now fires under stricter conditions:

- **Pre-F026**: `empty( $tools )` = all three `tool_*` columns are 0 AND `wp_acrossai_mcp_server_tools` has no rows for this server. Documented by F025 as "operator explicitly removed every tool AND declined Reset — vendor defaults safer".
- **Post-F026**: `empty( $tools )` = all three `tool_*` columns are 0 AND `wp_acrossai_mcp_server_tools` has no rows AND `wp_get_abilities()` returns zero abilities where `ExposureResolver::resolve()` = true (i.e., no ability has `mcp.public = true` without a `is_exposed = 0` override).

On a typical install with any third-party plugin that registers an ability with `mcp.public = true`, the F017-effective set is non-empty even when the operator has explicitly removed every F025 tool. The fallback path becomes unreachable in that state — F026 will advertise the F017-effective abilities instead of falling back to vendor defaults.

**Why this is not a real vulnerability**:
- The operator's intent when removing every F025 tool + F017 override is unclear. F026's behavior (advertising F017-effective abilities) is arguably MORE respectful of operator intent than falling back to vendor defaults.
- F017 already treated `mcp.public = true` abilities as callable — F026 just makes them visible in `tools/list`.
- No security boundary crossed; permission_callback + call-time gates preserved.

**Why v2 flags it**:
- The plan's TASK-2 description says "immediately before the `if ( empty( $tools ) )` short-circuit check" — implying the short-circuit continues to serve its F025 purpose. But under F026, the short-circuit fires under a strict subset of the pre-F026 conditions.
- Spec §Edge Cases (SEC-025-v2-1 amendment) still describes the DEFAULT-server empty-set behavior as "vendor defaults win as a safer fallback" — technically true only when the empty state includes zero F017-effective abilities. Worth explicitly noting.

**Recommendation** (non-blocking; spec-hygiene edit before Task 7 landing):

Amend `spec.md §Edge Cases §"empty tool list"` to note: *"Under F026, the default-server fallback fires only when protocol + curated + F017-effective are all empty. On installs with any third-party ability registered with `mcp.public = true`, the fallback path is typically unreachable — F026 advertises the F017-effective abilities instead."*

**Blocking?** No.

---

### SEC-026-v2-2 — Implicit "third-party abilities must register on `wp_abilities_api_init`" constraint not documented

| Field | Value |
|---|---|
| **Finding ID** | SEC-026-v2-2 |
| **Location** | `specs/026-abilities-into-tool-registration/data-model.md` §"Order-of-operations at server registration time"; `specs/026-abilities-into-tool-registration/quickstart.md` §"Register a scratch public ability" (uses `wp_abilities_api_init` hook correctly, implicit assumption) |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (documentation gap; not exploitable) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design |
| **CWE** | CWE-754 — Improper Check for Unusual or Exceptional Conditions |
| **Spec-Kit task** | TASK-SEC-026-v2-2 |

**Observation**: F026 iterates `wp_get_abilities()` at `mcp_adapter_init` firing time — which fires inside `Controller::initialize_adapter()` on `rest_api_init` (verified via `Main.php:513-514` + `Controller.php:86-102`). Timing sequence:

1. `plugins_loaded` fires.
2. Third-party plugins hook `wp_abilities_api_init` via `add_action(...)`.
3. `init` fires.
4. `wp_abilities_api_init` fires — third-party listeners run, registering abilities via `wp_register_ability()`.
5. `rest_api_init` fires.
6. `Controller::initialize_adapter()` runs → boots `\WP\MCP\Plugin::instance()` → vendor adapter's constructor calls `add_action('wp_abilities_api_init', ...)` — TOO LATE (this is the B29 lesson from F025).
7. `mcp_adapter_init` fires → F026's new composer runs → `wp_get_abilities()` returns third-party abilities (from step 4) but NOT vendor MCP protocol abilities (from step 6).

**Verified**: third-party abilities registered on `wp_abilities_api_init` DO appear in F026's iteration. This is the common case and it works correctly. F025's B29 mitigation (`ToolPolicy::PROTOCOL_TOOLS` columns as the canonical source for the three vendor protocol slugs) means F026 does NOT need to see them via `wp_get_abilities()` — they come from column state.

**The gap**: if a third-party plugin late-registers its abilities on a hook that fires AFTER `rest_api_init` (e.g., inside a REST route handler, or during another plugin's REST callback), those abilities are invisible to F026's iteration on THAT particular REST request. They'd become visible on the NEXT REST request (since the ability registration would fire during that request's `init` phase).

**Why this is not a runtime bug for typical use**:
- The standard pattern for third-party ability registration is `add_action( 'wp_abilities_api_init', 'my_register_abilities' )` — matches F017 documentation.
- Any plugin following that pattern is timing-safe under F026.

**Why v2 flags it**:
- The plan does not explicitly document the "third-party abilities must register by `wp_abilities_api_init`" constraint. Extension authors reading `docs/extending-server-tools.md` don't get an explicit statement of the invariant.
- If a third-party plugin author accidentally late-registers, they'd see a mysterious "why isn't my ability in tools/list?" bug that's very hard to trace. Documenting the constraint prevents that debugging round.

**Recommendation** (non-blocking; docs-only edit as part of TASK-4):

Add one sentence to `docs/extending-server-tools.md` §"Interaction with the Abilities tab" (or in a new §"Ability registration timing" section):

> *"For F026 to see your ability at server-registration time, it MUST be registered via `add_action( 'wp_abilities_api_init', ... )` (which fires during WordPress `init`). Abilities late-registered on `rest_api_init` or later hooks will be invisible to `tools/list` on the same request-cycle."*

**Blocking?** No.

## Confirmed Secure Patterns (v1 findings validated)

v1's three INFO findings all stand after v2 review:

- **SEC-026-INFO-1** (confused-deputy surface widens): confirmed — companion filter can strip F017-effective abilities the operator enabled, or add F017-hidden ones. F017 call-time gate still blocks execution regardless. Non-blocking; documentation-only remediation.
- **SEC-026-INFO-2** (`_reset_cache_for_tests()` B23 pattern): confirmed — test-only dependency, no production code path. Non-blocking; noted for future F017 maintenance.
- **SEC-026-INFO-3** (`mcp.public = true` implicit opt-in): confirmed — this is the exact F026 design characteristic v2's SEC-026-v2-1 addresses (empty-set fallback semantic shift). Cross-referenced.

Additional secure patterns validated in v2:

16. **`ExposureResolver::resolve()` signature match verified** at `ExposureResolver.php:53`. Return type `bool`, cache at line 40 (per-request static, keyed by composite key), precedence at lines 67-72 (row > fallback). Plan claims accurate.
17. **F017 fallback with empty meta safely handled**. `! empty( $meta['mcp']['public'] )` on an empty array `[]` evaluates false (PHP semantics: `empty()` on undefined nested key returns true). F026's `is_array( $meta ) ? $meta : array()` guard prevents a `TypeError` on non-array `$meta` returns.
18. **Post-filter defensive re-normalize at `Controller.php:163` remains intact**. Companion plugin returning non-array / non-string / duplicates cannot corrupt `create_server()` call.
19. **Line references in plan TASK-2 verified against shipping source**. No off-by-one drift; `Controller.php:142` and `Controller.php:247` are correct as of 2026-07-14 F025-shipped code.
20. **Hook chain timing verified** — `rest_api_init` → `initialize_adapter` at `Main.php:513` → `add_action mcp_adapter_init priority 11` at `Controller.php:102` → `register_database_servers` running strictly after vendor `DefaultServerFactory` at priority 10. Chain intact.
21. **B29 not applicable to F026**. Vendor protocol abilities come from `ToolPolicy::PROTOCOL_TOOLS` columns (F025), not from `wp_get_abilities()`. Third-party abilities registered via `wp_abilities_api_init` (during `init`) ARE visible at F026's iteration time (during `mcp_adapter_init`, in `rest_api_init`). No B29-class runtime bug.
22. **Concurrent-modification safety**. `foreach ( \wp_get_abilities() as $ability )` iterates an array snapshot; new ability registrations during iteration don't affect it.
23. **N+1 query performance bounded by resolver's per-request cache**. Cold cache: N BerlinDB queries per server; warm cache: O(1) per (server, ability) pair. Typical install: `<200` abilities × `<5` servers = `<1000` queries per REST request. Not a scale concern; F017 already tuned this for the call-time gate.

## Action Plan & Next Steps

### 1. Recommended pre-implementation edits (non-blocking)

- **SEC-026-v2-1** (LOW-severity spec hygiene): amend `spec.md §Edge Cases` to note the F026-shifted fallback semantics for the default server. One-sentence addition.
- **SEC-026-v2-2** (INFO docs hygiene): add "ability-registration timing" sentence to `docs/extending-server-tools.md` §"Interaction with the Abilities tab" as part of TASK-4.

### 2. Durable Memory Preservation

v2 identified two behavioral-shift observations, both spec-hygiene remediable inline. Neither is a systemic pattern warranting a new memory entry.

**Deferred**: consider whether F026's clean re-use of `DEC-ABILITY-OVERRIDE-RESOLUTION` (F017's canonical resolver) is worth annotating on the existing decision as "second consumer" (first was F017's own `AbilityExposureGate`). Marginal value — defer to post-implementation.

### 3. Remediation Planning

No CRITICAL or HIGH findings. `/speckit-security-review-followup` NOT required. SEC-026-v2-1 warrants a spec edit before Task 4 (docs); SEC-026-v2-2 is an inline TODO on Task 4.

### 4. Proceed to Task Generation

The plan v2 is safe to hand off to `/speckit-tasks` after the SEC-026-v2-1 spec edit lands (or the edit can be folded into an implementation-phase docs pass).

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-14-026-abilities-into-tool-registration-plan-v2.md | plan | 2026-07-14 | LOW | C:0 H:0 M:0 L:0 I:2 | A04 |
```
