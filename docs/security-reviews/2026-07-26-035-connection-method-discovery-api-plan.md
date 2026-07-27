---
document_type: security-review
review_type: plan
assessment_date: 2026-07-26
codebase_analyzed: acrossai-mcp-manager (F035 — Public Connection-Method Discovery API)
total_files_analyzed: 7
total_findings: 4
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 1
informational_count: 3
owasp_categories: [A03, A08]
cwe_ids: [CWE-20, CWE-79, CWE-1188]
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

# SECURITY REVIEW REPORT — PLAN: F035 Public Connection-Method Discovery API

## Executive Summary

**Verdict**: **Approved for implementation** with 1 LOW finding to address at plan-refinement time and 3 INFORMATIONAL notes to fold into the quickstart / consumer documentation.

**Feature shape (why the risk is low)**: F035 introduces a programmatic discovery API — one new class, no admin UI, no REST routes, no DB queries, no HTTP surface, no token handling, no user input, no HTML output. The only trust boundary crossed is developer filter contributions (`acrossai_mcp_npm_methods`, `acrossai_mcp_connection_methods`) — same anchor as the existing `acrossai_mcp_client_classes` (F034) and `acrossai_mcp_manager_connector_profiles` (F021) seams, both of which have been production for months. F035 explicitly inherits the SEC-013-008 malformed-drop pattern (FR-009b + FR-012a) rather than inventing a new validation semantic.

**Constitution §III surface**: Every applicable rule (S1–S9) marked N/A with justification in `security-constraints.md`; verified during this review.

**Memory hub context loaded**: `memory-synthesis.md` (this feature's own synthesis), `security-constraints.md` (plan-phase reviewer artifact), plus targeted `INDEX.md` scan for `security-critical` / `xss` / `input-validation` / `filter-contract` tags — no unresolved conflicts.

## Plan Artifacts Reviewed

- `specs/035-connection-method-discovery-api/plan.md`
- `specs/035-connection-method-discovery-api/spec.md` (with 3 clarifications resolved)
- `specs/035-connection-method-discovery-api/research.md`
- `specs/035-connection-method-discovery-api/data-model.md`
- `specs/035-connection-method-discovery-api/contracts/ConnectionMethodRegistry.contract.md`
- `specs/035-connection-method-discovery-api/quickstart.md`
- `specs/035-connection-method-discovery-api/security-constraints.md`
- `specs/035-connection-method-discovery-api/memory-synthesis.md`

Constitution + memory hub:
- `.specify/memory/constitution.md` (v1.1.0)
- `docs/memory/INDEX.md` (targeted scan)

---

## Vulnerability Findings

### [LOW] SEC-035-001 — `acrossai_mcp_npm_methods` DTO-type validation gap (FR-009b)

**Location**: `plan.md` §Constitution Check; `spec.md` FR-009b; `contracts/ConnectionMethodRegistry.contract.md` §`get_npm_methods()`.

**OWASP Category**: A03:2025-Injection (adjacent — type-confusion → downstream misuse).

**CWE**: CWE-20 (Improper Input Validation) + CWE-1188 (Insecure Default Initialization of Resource).

**CVSS Score**: 3.7 (Low — AV:N / AC:H / PR:H / UI:R / S:U / C:L / I:L / A:N — requires admin-installed malicious companion plugin + a downstream consumer that trusts value types without checking).

**Description**: FR-009b mandates validation that each `acrossai_mcp_npm_methods` filter contribution has the six required top-level keys (`category`, `slug`, `name`, `description`, `icon`, `meta`). However, the FR does NOT require validation that the VALUES have the expected TYPES. A companion plugin could contribute:

```php
array(
    'category'    => 'npm',
    'slug'        => array( 'not', 'a', 'string' ),    // TYPE VIOLATION
    'name'        => new \stdClass(),                   // TYPE VIOLATION
    'description' => '',
    'icon'        => '',
    'meta'        => 'not-an-array',                    // TYPE VIOLATION
)
```

This passes FR-009b (all six keys present) but breaks every downstream consumer that does `strval( $dto['slug'] )` (gets `"Array"` — leaks structural info), `$dto['name']` string concatenation (fatal on `stdClass`), or `$dto['meta']['command_template']` array access (fatal on string). Worst realistic case: a consumer stores `strval( $dto['slug'] )` in its own DB and rendes it — the DTO's malformed type surfaces as a rendered `"Array"` string across the consumer's admin UI, and the malicious plugin has now planted a persistent structural fingerprint in the consumer's data.

The `wp_json_encode()` round-trip invariant (SC-001) IS a partial guard — `new \stdClass()` DOES round-trip (encodes as `{}`), but the shape drift persists. A closure or resource would fail encoding cleanly, but many other type mismatches ride through JSON.

**Remediation**: Extend FR-009b to also validate value types before the entry is accepted. Recommended shape:

```php
private function validate_npm_dto( array $dto ): bool {
    foreach ( array( 'category', 'slug', 'name', 'description', 'icon' ) as $string_key ) {
        if ( ! isset( $dto[ $string_key ] ) || ! is_string( $dto[ $string_key ] ) ) {
            return false;
        }
    }
    return isset( $dto['meta'] ) && is_array( $dto['meta'] );
}
```

Attach the same tightening to the `acrossai_mcp_connection_methods` fallback path (FR-012a) — each DTO within the assembled result MAY optionally be re-validated OR the constraint can be documented as "F035 validates SHAPE at the boundary; deep-value guarantees come only from `get_npm_methods()` inbound validation, so the cross-category filter is trusted to preserve types."

**Spec-Kit Task**: `TASK-SEC-035-001` — extend FR-009b to `FR-009b'`: validate that all five string-typed keys hold `is_string()` values AND `meta` holds `is_array()`. Add PHPUnit case to `ConnectionMethodRegistryTest.php` covering type-drift contribution (string field is an array, array field is a scalar); assert entry dropped + `_doing_it_wrong` fires.

---

### [INFORMATIONAL] SEC-035-002 — Consumer contract: string fields require render-time escaping

**Location**: `quickstart.md` §1 (enumeration walkthrough); `data-model.md` §Top-Level DTO Shape.

**OWASP Category**: A03:2025-Injection (documentation-scoped).

**CWE**: CWE-79 (Improper Neutralization of Input During Web Page Generation).

**Description**: F035's DTO string fields (`name`, `description`, `icon`, and inside `meta`: `command_template`, `config_file`, `top_level_key`) are populated by (a) admin-installed plugins that contribute filter callbacks, or (b) admin-installed plugin authors who wrote the underlying `AbstractMCPClient` / `AbstractConnectorProfile` subclass. Trust level: "installed by admin" — same anchor as F034 metadata (SEC-034-001 preservation invariant).

F035's contract is to emit data, not HTML. The escape happens at the render boundary, which the CONSUMER owns. This is correct architecturally, but `quickstart.md` currently shows a `printf( "[%s] %s (%s)\n", ... )` example that does NOT escape values — a consumer developer who copy-pastes this into an admin-page-render path would ship XSS.

**Remediation** (documentation only — not a code change):

1. Add a "Consumer Security Responsibility" callout to `quickstart.md` immediately after the §1 enumeration example:

   > **Security note**: DTO string fields (`name`, `description`, `icon`, `meta.*`) are contributed by admin-installed companion plugins and are NOT pre-escaped by F035. If you render these values into an admin page, REST response, or frontend HTML, escape at the render boundary using the most-specific WordPress escaping function (`esc_html()`, `esc_attr()`, `esc_url()` per context). This mirrors F034's SEC-034-001 preservation invariant.

2. Ship the same callout in the class-level docblock of `ConnectionMethodRegistry.php` for developers who don't read the quickstart.

**Spec-Kit Task**: `TASK-SEC-035-002` — add callout to `quickstart.md` + `ConnectionMethodRegistry.php` docblock during Phase 2 (`/speckit-tasks`).

---

### [INFORMATIONAL] SEC-035-003 — Consumer contract: `meta.enabled_option` is a gate flag, not a general option-read seam

**Location**: `data-model.md` §Category `npm` `meta` sub-shape; `contracts/ConnectionMethodRegistry.contract.md`.

**OWASP Category**: A08:2025-Software and Data Integrity Failures (adjacent).

**CWE**: CWE-20 (Improper Input Validation — of downstream consumer behaviour, not F035 itself).

**Description**: The NPM DTO's `meta.enabled_option` field carries a WP option NAME (built-in: `acrossai_mcp_npm_login_enabled`) intended for boolean gate checks. A malicious companion plugin could contribute an NPM DTO with `meta.enabled_option => 'wp_user_roles'` (or any other WP option name), tricking a naive consumer into `get_option( $dto['meta']['enabled_option'] )` and either (a) leaking the returned value in its UI or (b) using it as authorization state.

F035 is NOT responsible for this — the semantics of the field are documented as "boolean gate flag" and the consumer chooses what to do with it. But the semantic constraint SHOULD be explicit in the data model to prevent well-intentioned consumers from misusing the field.

**Remediation** (documentation only):

1. In `data-model.md`, expand the `enabled_option` description:

   > **Consumer contract**: Treated as a boolean gate flag. Consumers MUST verify the return of `get_option( $dto['meta']['enabled_option'] )` is truthy before considering the NPM method enabled. Consumers MUST NOT use this field as a general-purpose option-name channel or leak the returned value into their UI. Option-name validation MAY be tightened by the consumer if they wish (e.g. allow-list of `acrossai_mcp_*` prefix).

2. Consider adding an F035-side allow-list constraint in a future minor release: `_doing_it_wrong` if `enabled_option` doesn't start with `acrossai_` or the consumer's own namespace prefix. Not blocking for this release.

**Spec-Kit Task**: `TASK-SEC-035-003` — expand `data-model.md` `enabled_option` description with consumer-contract note during Phase 2.

---

### [INFORMATIONAL] SEC-035-004 — Memoization + late-registered filter callback timing

**Location**: `plan.md` §R2 (memoization decision); `contracts/ConnectionMethodRegistry.contract.md` §`get_all()`; `quickstart.md` §Memoization.

**OWASP Category**: A08:2025-Software and Data Integrity Failures (adjacent — decision-timing issue).

**CWE**: CWE-1188 (Insecure Default Initialization of Resource — adjacent).

**Description**: `get_all()` memoizes on first call per-request. If a security-relevant filter callback (e.g., a companion plugin's `acrossai_mcp_connection_methods` callback that removes a compromised connector) registers AFTER the first `get_all()` call, the memoized result WILL NOT reflect the filter's contribution until `flush_cache()` is called manually.

Realistic scenarios:
- Extremely rare in normal WP usage — callbacks register at `plugins_loaded` / `init` (long before consumers typically call `get_all()` at `admin_menu` / `rest_api_init` / template render time).
- More plausible in mid-request contexts: a user action triggers a companion plugin to dynamically register a filter, then re-query the registry expecting the filter to apply.

**Remediation** (documentation only — no code change):

The quickstart.md already documents this in §Memoization. Tighten the phrasing to make the security-adjacent implication explicit:

> **Security implication of memoization**: If your plugin registers a security-critical filter callback AFTER any code has already called `get_all()` in the same request, that callback WILL NOT take effect until you call `ConnectionMethodRegistry::instance()->flush_cache()`. Register filter callbacks early (at `plugins_loaded` or `init`) to guarantee they apply on every discovery-API consumer's first query.

**Spec-Kit Task**: `TASK-SEC-035-004` — tighten quickstart.md §Memoization phrasing with the security-implication callout during Phase 2.

---

## Confirmed Secure Patterns

1. **Delegation-not-re-implementation** (FR-010 + FR-011 + FR-017): F035 does NOT re-fire `acrossai_mcp_client_classes` or `acrossai_mcp_manager_connector_profiles`. Enforced by SC-005 grep gate. Direct application of B32 (canonical resolver defence) — filter defaults MUST express canonical output, never partial derivation.
2. **Malformed-drop symmetry** (FR-009b + FR-012a): Both new filters inherit the SEC-013-008 pattern (silent-drop + `_doing_it_wrong` under `WP_DEBUG`) that F034 established for `acrossai_mcp_client_classes`. Trust boundary is consistent across all three category seams.
3. **JSON-serializability invariant** (SC-001): Every DTO round-trips through `wp_json_encode()` losslessly. Precludes closures, resources, and most object-based sneak-through. (Partial guard against SEC-035-001 above — but doesn't stop `stdClass`.)
4. **One-way layering** (SC-006 grep gate): `public/` MUST NOT be imported into `includes/`. Enforced by grep gate. Prevents cross-layer coupling that would let a future refactor accidentally elevate the discovery-API surface to a plugin-internal dependency.
5. **A2 singleton with private ctor** (FR-002): Blocks `new ConnectionMethodRegistry()` bypass. Preserves the singleton state contract that R2 memoization depends on.
6. **A18-honoring bootstrap choice** (spec Assumptions + plan Technical Context): Chosen `tests/bootstrap-wp.php` over pure-PHP bootstrap because transitive dependencies (`ConnectorProfileRegistry` + `NpmClientBlock`) would push past the ~10-symbol stub ceiling. Prevents A18-drift.

---

## Action Plan & Next Steps

1. **Fold SEC-035-001 (LOW) into spec + contract**: Extend FR-009b to require type validation, not just key presence. Add matching PHPUnit case. Small change — recommended before `/speckit-tasks`.
2. **Fold SEC-035-002 / 003 / 004 (INFORMATIONAL) into Phase 2 tasks**: Documentation edits only; queue them as `TASK-SEC-035-002..004` during `/speckit-tasks`.
3. **No `/speckit.security-review.followup` needed**: overall risk LOW, no CRITICAL or HIGH findings. All four findings are self-contained + can be addressed in Phase 2.
4. **Durable Memory Preservation check**: No new architectural patterns or repeatable lessons identified in this review. All four findings apply existing patterns (SEC-013-008, B32, F034 SEC-034-001) to a new consumer surface. Skipping `/speckit-memory-md-capture-from-diff` at this phase — capture will run at staged-review time if the implementation surfaces new lessons.
5. **Recommended re-review**: Re-run `/speckit-security-review-staged` after implementation lands to catch any drift between plan and code. F035's threat model expects zero staged-review findings; any MODERATE or HIGH from staged review would indicate an unplanned surface was added.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-26-035-connection-method-discovery-api-plan.md | plan | 2026-07-26 | LOW | C:0 H:0 M:0 L:1 I:3 | A03,A08 |
```
