---
document_type: security-review
review_type: plan
assessment_date: 2026-07-13
codebase_analyzed: acrossai-mcp-manager (Feature 025 planning artifacts + vendor source cross-check)
total_files_analyzed: 12
total_findings: 3
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 1
informational_count: 2
owasp_categories: [A04, A08]
cwe_ids: [CWE-670, CWE-754, CWE-1188]
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

# Security Review — Feature 025 Plan (v2 — close-in-substance)

## Executive Summary

**Feature**: 025-server-tools-registration-hooks
**Review type**: v2 close-in-substance pass, per F020 WORKLOG lesson (2026-07-09): "run security-review v2 for close-in-substance verification — SEC-020-007 vendor-accessor bug (`B24`) survived v1's remediation because v1 didn't verify contract text against vendor source; v2 caught it."
**Plan artifacts under review**: `plan.md`, `data-model.md`, `contracts/*`, `research.md`, `quickstart.md` (all 2026-07-13).
**v1 review**: [`2026-07-13-025-server-tools-registration-hooks-plan.md`](./2026-07-13-025-server-tools-registration-hooks-plan.md) — 3 INFO findings, LOW overall.
**v2 overall risk**: **LOW** — 0 Critical, 0 High, 0 Medium, 1 Low, 2 Informational.

v2 performed a targeted cross-check against the vendored MCP adapter source (`vendor/wordpress/mcp-adapter/`) — the exact class of gap v1 missed on F020. Three additional observations surfaced. All are non-blocking; one (SEC-025-v2-1) is a genuine spec-vs-plan inconsistency worth resolving in-plan before implementation.

### Vendor source cross-check (results)

| Contract text vs. vendor source | Verification | Verdict |
|---|---|---|
| `mcp_adapter_default_server_config` filter signature: single `array $config` arg | `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:88` — `apply_filters( 'mcp_adapter_default_server_config', $wordpress_defaults )`. Post-filter merge via `wp_parse_args( $config, $wordpress_defaults )` at line 94. | ✓ Plan callback correct. |
| Protocol tool abilities (`mcp-adapter/discover-abilities`, etc.) registered by REST-init time | `vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php:120` — `add_action( 'wp_abilities_api_init', array( $this, 'register_default_abilities' ) );` — fires before `rest_api_init`. | ✓ `wp_get_ability()` validation on the POST path will resolve them. |
| `$adapter->create_server()` 10th argument shape: `array $tools = array()` of ability slugs | Vendor `McpAdapter.php` signature confirmed at F020 baseline. Slugs resolved via `wp_get_ability()` in `McpComponentRegistry::register_ability_tool()`. | ✓ No new vendor-accessor via `instanceof` (B24 preserved). |
| Vendor's fallback when filter returns non-array `$config` | Explicit reset to `$wordpress_defaults` at line 91–93. | ✓ Plan callback's defensive returns compose safely. |
| Vendor's default `tools` array | Lines 53–57 — three protocol slugs. Preserved via `wp_parse_args` when filter callback returns without `tools` key. | ✓ See SEC-025-v2-1 — behavioral asymmetry with database-server path. |

## Vulnerability Findings

### SEC-025-v2-1 — Spec-vs-plan inconsistency on empty-set behavior for default server

| Field | Value |
|---|---|
| **Finding ID** | SEC-025-v2-1 |
| **Location** | `plan.md` §Post-Design Constitution Re-Check (points to the callback's `if ( empty( $tools ) ) return $config;`); `spec.md` §Edge Cases §"empty tool list"; `contracts/filter-acrossai_mcp_manager_server_tools.md` §"Defensive short-circuits" |
| **Severity** | LOW |
| **CVSS v3.1** | 2.7 (documentation/consistency gap; not exploitable) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design |
| **CWE** | CWE-670 — Always-Incorrect Control Flow Implementation (subclass — the two paths' control flow are inconsistent for the same operator intent) |
| **Spec-Kit task** | TASK-SEC-025-v2-1 |

**Observation**: The spec's Edge Cases section states:

> *"What if the operator removes all three built-in defaults AND has no curated picks? The server registers with an empty tool list, and `tools/list` returns an empty array."*

This describes the **database-server** path (`Controller::register_database_servers()`), where the composed `$tools` array is passed to `$adapter->create_server()` as-is, resulting in an empty tool list.

The **default-server** path (`Controller::filter_default_server_config()`) has a documented short-circuit:

> *"the composed picks array is empty (the operator explicitly removed every tool AND has no curated picks AND declined Reset — vendor defaults are the safer fallback)"*

When `ToolPolicy::compose_for_row()` returns `[]` on the default server, the callback returns `$config` untouched. The vendor's `wp_parse_args( $config, $wordpress_defaults )` then merges the vendor's default `['tools' => [3 protocol slugs]]` back in — so the default server ADVERTISES all three protocol tools even though the operator explicitly disabled them.

The two paths honor the operator's "remove everything" intent asymmetrically:

- **Database server**: honored → empty `tools/list`.
- **Default server**: overridden → vendor's three protocol slugs advertised.

**Why this is a Low severity finding, not Critical/High**:
- Not exploitable — no external attacker vector.
- The plan explicitly DOCUMENTS the asymmetry as a "safer fallback" design decision.
- The operator can achieve empty-list state on the default server via a companion plugin hooking `mcp_adapter_default_server_config` at higher priority than the plugin's callback (default 10 → hook at 20).
- The security impact is zero — an operator who wants "no tools" ends up with "three self-describing tools" that themselves cannot execute WordPress abilities.

**Why v2 flags it**:
- The spec §Edge Cases claim is factually incorrect for the default server. Confused operators may report a bug when they see `tools/list` returning three slugs after they "removed everything".
- The `filter_default_server_config()` callback's short-circuit rationale ("vendor defaults are the safer fallback") is a security-adjacent design choice worth explicit documentation. What if a security-hardened operator NEEDS the empty state for compliance?

**Recommendation (non-blocking, pre-implementation edit)**:
Choose one and reflect it in both spec §Edge Cases and `contracts/filter-acrossai_mcp_manager_server_tools.md`:

- **Option A (recommended, matches plan intent)**: keep the default-server fallback but amend spec §Edge Cases to state: *"For DATABASE servers, the server registers with an empty tool list. For the DEFAULT server, vendor defaults (three protocol tools) win as a safer fallback — operators requiring truly empty state for the default server MUST hook `mcp_adapter_default_server_config` at priority >10 and explicitly set `$config['tools'] = []`."*
- **Option B**: honor the empty state on the default server too. Change the callback: instead of `if ( empty( $tools ) ) return $config;`, set `$config['tools'] = []` and return. Then update the callback's docblock and `contracts/filter-acrossai_mcp_manager_server_tools.md`.

Blocking? **No.** Requires either spec edit or callback logic edit — no code that F025 introduces changes; the plan is internally consistent, just spec-and-plan are inconsistent with each other.

---

### SEC-025-v2-2 — `wp_get_abilities()` validation timing verified; add explicit test case

| Field | Value |
|---|---|
| **Finding ID** | SEC-025-v2-2 |
| **Location** | `contracts/rest-tools-endpoint-semantics.md` §"POST accepts protocol slugs"; existing `ToolsController::post_tools()` validation branch (~lines 275–300 in the F020 baseline) |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (verified secure at vendor-source layer; hardening recommendation only) |
| **OWASP Top 10 2025** | A04:2025 — Insecure Design (fail-forward hardening) |
| **CWE** | CWE-754 — Improper Check for Unusual or Exceptional Conditions (candidate — timing-sensitive validation call) |
| **Spec-Kit task** | TASK-SEC-025-v2-2 |

**Observation**: The v2 cross-check confirms that the three protocol tool abilities register on `wp_abilities_api_init` (vendor `McpAdapter.php:120`), which fires before `rest_api_init`. F020's `ToolsController::post_tools()` validation loop calls `wp_get_ability( $slug )` for each submitted slug and rejects with 400 if the return is null.

After F025 deletes `EXCLUDED_SLUGS` (Task 6), the three protocol slugs pass through the validation loop directly to `wp_get_ability()`. **Verified**: `wp_get_ability( 'mcp-adapter/discover-abilities' )` returns the registered ability, validation passes, POST returns 200.

**Why this is INFORMATIONAL, not a real finding**:
- The vendor source confirms the hook order (`wp_abilities_api_init` before `rest_api_init`).
- The F020 baseline test suite exercises `post_tools()` at REST-init time — any regression would surface immediately.

**Recommendation (non-blocking hardening)**:
Add an explicit PHPUnit case in `tests/phpunit/REST/ToolsControllerTest.php` under Task 8:

```
public function test_post_accepts_all_three_protocol_slugs(): void {
    $response = $this->rest_post(
        '/servers/1/tools',
        [ 'tools' => [
            'mcp-adapter/discover-abilities',
            'mcp-adapter/get-ability-info',
            'mcp-adapter/execute-ability',
        ] ]
    );
    $this->assertSame( 200, $response->get_status() );
}
```

This turns "verified once during v2" into "verified on every PR" — a regression-net for future MCP adapter package updates that might change the ability-registration hook.

Blocking? **No.**

---

### SEC-025-v2-3 — `server_slug` is KEY not UNIQUE (F011 baseline); `filter_default_server_config()` picks first row deterministically

| Field | Value |
|---|---|
| **Finding ID** | SEC-025-v2-3 |
| **Location** | `includes/Database/MCPServer/Schema.php:127-137` (existing F011 index definition); `contracts/filter-acrossai_mcp_manager_server_tools.md` §"Interaction with the vendor filter" (defensive short-circuits) |
| **Severity** | INFORMATIONAL |
| **CVSS v3.1** | 0.0 (integrity note within `manage_options` trust boundary) |
| **OWASP Top 10 2025** | A08:2025 — Software and Data Integrity Failures |
| **CWE** | CWE-1188 — Initialization of a Resource with an Insecure Default (applied conceptually — the index type at F011 is more permissive than presumed) |
| **Spec-Kit task** | TASK-SEC-025-v2-3 |

**Observation**: The `MCPServer\Schema` index definition (F011 baseline) declares:

```php
array(
    'name'    => 'server_slug',
    'type'    => 'key',        // NOT 'unique'
    'columns' => array( 'server_slug' ),
),
```

`filter_default_server_config()` looks up the default server row via `MCPServerQuery::instance()->query( [ 'server_slug' => DefaultServerSeeder::SLUG, 'number' => 1 ] )`. If two rows exist with the same slug (theoretically possible via direct DB write or a bug in an activation hook), BerlinDB returns the first by primary-key order (typically lowest `id` first) — deterministic but not guaranteed to be the "seeded" row.

**Why this is INFORMATIONAL, not a real finding**:
- Requires an actor with `manage_options` (already privileged) OR a bug in a companion plugin's activation hook.
- The `DefaultServerSeeder::seed()` code path checks for existence via `SELECT COUNT(*) FROM %i WHERE server_slug = %s` before inserting, so the plugin's own code will never duplicate.
- Even if duplication occurs, `filter_default_server_config()` still finds A row and composes its tools — no crash, no error, no security bypass. The composed set may differ from operator intent by picking the "wrong" duplicate row.

**Recommendation (non-blocking; future maintenance)**:
- Consider promoting the `server_slug` index from `'type' => 'key'` to `'type' => 'unique'` in a future migration. Out of scope for F025 — would require a data-integrity ALTER + rejecting existing duplicates.
- For F025, add a code comment at `filter_default_server_config()`'s `MCPServerQuery::query()` call noting the KEY-not-UNIQUE semantic and that `number => 1` selects the first insertion-order match.

Blocking? **No.**

## Confirmed Secure Patterns (v1 findings validated)

v1's three INFO findings all stand after v2 review:

- **SEC-025-INFO-1** (filter override of operator removal): confirmed — no change; documentation-only remediation in Task 9.
- **SEC-025-INFO-2** (two-write POST race): confirmed — code comment marker sufficient.
- **SEC-025-INFO-3** (`EXCLUDED_SLUGS` vestigial): confirmed — docblock update sufficient.

Additional secure patterns validated in v2:

11. **Vendor hook contract verified against source.** The `mcp_adapter_default_server_config` signature, invocation site, and post-merge behavior all match the plan's callback design. No F020-class B24 gap present.
12. **`wp_parse_args` merge is order-safe.** Even if the callback throws (which it doesn't), the vendor's post-filter `if ( ! is_array( $config ) ) { $config = $wordpress_defaults; }` guards against corruption.
13. **`create_server()` slug resolution boundary is stable.** `McpComponentRegistry::register_ability_tool()` (line 227) resolves each slug via `wp_get_ability()`; unknown slugs are silently dropped — not an exception vector.
14. **Protocol ability registration timing is deterministic.** `wp_abilities_api_init` → `rest_api_init` order confirmed via vendor source; POST validation cannot 400 on protocol slugs by timing accident.
15. **BerlinDB Kern `update_item()` used for column write.** Verified as the prepared-write path per F011 conventions; no ORM leakage into REST controller.

## Action Plan & Next Steps

### 1. Recommended pre-implementation edits (non-blocking)

- **SEC-025-v2-1** (LOW): pick Option A or Option B and reflect in spec §Edge Cases + contract doc before Task 7 lands. Recommend Option A (matches plan intent; least intrusive).
- **SEC-025-v2-2** (INFO): add the protocol-slug POST test case to Task 8's `ToolsControllerTest.php` scope.
- **SEC-025-v2-3** (INFO): inline code comment at Task 4's callback (the `MCPServerQuery::query()` call).

### 2. Durable Memory Preservation

v2 identified one durable pattern worth capturing post-implementation:

- **DEC-F025-V2-VENDOR-SOURCE-CROSS-CHECK-CADENCE**: F020's WORKLOG lesson recommends v2 close-in-substance verification for any feature that consumes a vendor hook or vendor return contract. F025 v2 confirms the value — three additional observations surfaced only via vendor source cross-check. Codify: "For any feature adding a vendor-filter callback or vendor return-contract consumer, EITHER (a) the plan.md must explicitly cite vendor-source line numbers for every contract claim it makes, OR (b) a v2 security review is mandatory before implementation."

This is a systemic reviewer-cadence pattern, not a per-feature finding. Propose capture via `/speckit.memory-md.capture` after F025 lands (per the "defer capture until soaked" WORKLOG guidance).

### 3. Remediation Planning

No CRITICAL or HIGH findings. `/speckit.security-review.followup` NOT required. SEC-025-v2-1 warrants a plan/spec edit before Task 7 (JS UI) starts; SEC-025-v2-2 and SEC-025-v2-3 are inline TODOs on Tasks 4 / 8.

### 4. Proceed to Task Generation

The plan v2 is safe to hand off to `/speckit-tasks` after (or in parallel with) the SEC-025-v2-1 spec/plan edit.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-13-025-server-tools-registration-hooks-plan-v2.md | plan | 2026-07-13 | LOW | C:0 H:0 M:0 L:1 I:2 | A04,A08 |
```
