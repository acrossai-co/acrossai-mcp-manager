---
document_type: security-review
review_type: staged
assessment_date: 2026-07-27
codebase_analyzed: acrossai-mcp-manager (F035 staged/uncommitted diff)
total_files_analyzed: 8
total_findings: 1
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 1
owasp_categories: [A03]
cwe_ids: [CWE-79]
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

# SECURITY REVIEW REPORT — STAGED: F035 Public Connection-Method Discovery API

## Executive Summary

**Verdict**: **Approved for commit / PR**. Zero CRITICAL, HIGH, MODERATE, or LOW findings on the shipped code. One INFORMATIONAL note (SEC-035-005) for follow-up documentation.

All four plan-review findings (SEC-035-001 LOW + 002/003/004 INFO) are correctly closed by shipped code + docs — verified below in §Confirmed Secure Patterns. The plan-review's LOW-severity concern (SEC-035-001 DTO-type validation gap) is fully remediated in the `validate_npm_dto()` helper.

## Staged Diff Reviewed

**Modified** (7 files):
- `public/Renderers/NpmClientBlock.php` (+40 lines: new `get_default_npm_method()` helper + light `render_command_ui`/`render_body` refactor)
- `phpunit.xml.dist` (+13 lines: `discovery` suite registration)
- `.github/workflows/phpunit.yml` (+4 lines: `discovery` CI job)
- `README.txt` (+3 lines: `= Unreleased =` changelog)
- `docs/memory/INDEX.md` (+1 line: 2026-07-26 plan-review row)
- `.specify/feature.json` (pointer update)
- `.mcp.json` (unrelated MCP client config drift — not F035)

**Untracked** (14 files):
- `public/Discovery/ConnectionMethodRegistry.php` (**new — 320 lines: the class**)
- `tests/phpunit/Public/Discovery/ConnectionMethodRegistryTest.php` (~350 lines: US1/US2/US3 test coverage)
- `tests/phpunit/Public/Discovery/NpmDefaultHelperTest.php` (~55 lines: SC-007 helper regression)
- `docs/security-reviews/2026-07-26-035-connection-method-discovery-api-plan.md` (plan-review artifact)
- 9 files under `specs/035-connection-method-discovery-api/` (Spec Kit documentation only — no runtime code)

## Vulnerability Findings

### [INFORMATIONAL] SEC-035-005 — Consumer contract docblock is authoritative; quickstart is illustrative

**Location**: `public/Discovery/ConnectionMethodRegistry.php:15-24` (class-level docblock) + `specs/035-connection-method-discovery-api/quickstart.md`.

**OWASP Category**: A03:2025-Injection (documentation-scoped, discoverability concern).

**CWE**: CWE-79 (Improper Neutralization of Input During Web Page Generation).

**CVSS Score**: 0.0 (Informational — pure documentation discoverability observation).

**Description**: The SEC-035-002 "Consumer Security Responsibility" callout lives in TWO places:
- The class-level docblock at `ConnectionMethodRegistry.php:15-24` (developers who read source)
- The quickstart §1 (developers who read documentation)

Both say the same thing. A third-party plugin developer who reads NEITHER (skims the method signatures via IDE autocomplete without expanding the class docblock) could miss it. This is inherent to any developer-facing contract — but worth noting for future observability additions.

**Remediation** (optional, non-blocking): Consider adding a *runtime* signal in a future minor release — e.g., a WP admin notice on `admin_init` for administrators of sites where DTO field lengths exceed a suspicious threshold (early XSS payloads tend to be long). Not required for shipping; a defense-in-depth suggestion.

**Spec-Kit Task**: N/A (future minor release consideration).

---

## Confirmed Secure Patterns

Every plan-review finding is verified as CLOSED in shipped code:

### 1. SEC-035-001 (plan-phase LOW) → SHIPPED as `validate_npm_dto()` helper

**Location**: `public/Discovery/ConnectionMethodRegistry.php:293-301`.

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

Test coverage confirmed: `ConnectionMethodRegistryTest::test_npm_malformed_dto_type_mismatch_dropped` with `#[DataProvider]` covering `slug => array()`, `meta => 'string'`, `name => new stdClass()`, `icon => 42`.

### 2. SEC-035-002 (plan-phase INFO) → SHIPPED in class docblock + quickstart §1

`ConnectionMethodRegistry.php:15-24`:
```
## Consumer Security Responsibility
DTO string fields (`name`, `description`, `icon`, `meta.*`) are contributed
by admin-installed companion plugins and are NOT pre-escaped by this class.
If you render these values into an admin page, REST response, or frontend
HTML, escape at the render boundary using the most-specific WordPress
escaping function (`esc_html()`, `esc_attr()`, `esc_url()` per context).
Mirrors F034 SEC-034-001 preservation invariant. See SEC-035-002.
```

Quickstart §1 mirrors the same text as a `> **Security note (SEC-035-002)**:` block.

### 3. SEC-035-003 (plan-phase INFO) → SHIPPED in data-model.md

`meta.enabled_option` row description explicitly notes: "Treated as a boolean gate flag. Consumers MUST verify the return of `get_option( $dto['meta']['enabled_option'] )` is truthy before considering the NPM method enabled. Consumers MUST NOT use this field as a general-purpose option-name channel or leak the returned value into their UI."

### 4. SEC-035-004 (plan-phase INFO) → SHIPPED in quickstart §Memoization

Callout added: "If your plugin registers a security-critical filter callback AFTER any code has already called `get_all()` in the same request, that callback WILL NOT take effect until you call `ConnectionMethodRegistry::instance()->flush_cache()`. Register filter callbacks early (at `plugins_loaded` or `init`)…"

### 5. Delegation-not-re-implementation (B32 + D35) — VERIFIED

Grep gate output (SC-005):
```
$ grep -rEn 'apply_filters.*acrossai_mcp_client_classes|apply_filters.*acrossai_mcp_manager_connector_profiles' public/Discovery/
(no output — 0 hits)
```

F035 delegates cleanly to F034 + F021. Third-party filter callbacks fire exactly once.

### 6. One-way layering (SC-006) — VERIFIED

```
$ grep -rEn '\bConnectionMethodRegistry\b' --include='*.php' includes/
(no output — 0 hits)
```

`public/` is never imported into `includes/`. Prevents cross-layer coupling that would let a future refactor accidentally elevate the discovery API to a plugin-internal dependency.

### 7. A1 preservation — VERIFIED

```
$ grep -rEn 'add_filter|add_action' --include='*.php' public/Discovery/
(no output — 0 hits)
```

F035 DEFINES two new filters (`apply_filters` inside methods) but REGISTERS none — `Main.php` has zero changes required.

### 8. `final class` locks singleton contract (defensive)

`ConnectionMethodRegistry.php:44` declares `final class ConnectionMethodRegistry`. Prevents subclass-based state fragmentation and D35 delegation-invariant defeat via runtime override. Documented extension path is filter-only (four canonical seams).

### 9. NpmClientBlock light-touch — no security-escaping drift

Diff review of `render_command_ui()` confirms every `esc_html()` / `esc_html__()` / `esc_attr()` / `esc_textarea()` call is preserved verbatim. The only change is sourcing `$template` from the static helper — the escape stack is untouched.

## Overall Threat Model Coverage

| Threat class | Status | Notes |
|--------------|--------|-------|
| Injection (SQL, command, XSS) | ✅ N/A | F035 issues no DB queries, executes no commands, emits no HTML |
| Broken authorization | ✅ N/A | No admin pages, no REST routes, no capability checks required |
| Missing nonces | ✅ N/A | No forms, no AJAX endpoints |
| Secret leakage | ✅ N/A | No token/password handling; `enabled_option` documented as gate flag only |
| Cryptographic failures | ✅ N/A | No cryptographic operations |
| Trust-boundary confusion | ✅ Bounded | Single boundary (filter contribution) mirrors two pre-existing F021 + F034 seams; SEC-013-008 pattern inherited via FR-009b + FR-012a |
| Cross-tenant / cross-server bypass | ✅ N/A | No per-tenant data; DTOs are static-shape configuration |
| Denial of service | ✅ N/A | O(≤13) DTO enumeration; memoized per-request; no I/O |

## Action Plan & Next Steps

1. **Approved for `/speckit-git-commit`** — commit F035 as-is; open PR against `main`.
2. **Post-CI verification**: T029 (WP PHPUnit `discovery` suite) runs green on CI.
3. **Post-merge**: `/speckit-architecture-guard-architecture-verify` completion gate on the merged code (maps tasks.md T-IDs to landed code evidence).
4. **Durable Memory Preservation check**: No new systemic vulnerabilities or reusable security patterns identified in this review. All four plan-review findings applied existing patterns (SEC-013-008, B32, F034 SEC-034-001) to a new consumer surface. Capture pass not triggered.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-27-035-connection-method-discovery-api-staged.md | staged | 2026-07-27 | INFORMATIONAL | C:0 H:0 M:0 L:0 I:1 | A03 |
```
