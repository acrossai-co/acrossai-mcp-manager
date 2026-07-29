---
document_type: security-review
review_type: branch
assessment_date: 2026-07-29
codebase_analyzed: acrossai-mcp-manager (F038 branch 037-user-accessible-mcp-servers-shortcode diff vs main)
total_files_analyzed: 8
total_findings: 1
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 1
owasp_categories: []
cwe_ids: [CWE-489]
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

# SECURITY REVIEW REPORT — BRANCH: 037-user-accessible-mcp-servers-shortcode vs main

## Executive Summary

The F038 branch delivers a **read-only frontend rendering feature** with no forms, no AJAX endpoints, no REST routes, no database mutations, no admin surface, and no external HTTP calls. Every authorization decision delegates to shipped, previously-reviewed upstream helpers (F011 MCPServerQuery, F015 AcrossAI_MCP_Access_Control, F037 AbstractEmbedTransport). The implementation matches the contracts (`contracts/AbstractUserServersRenderer.contract.md` + `contracts/UserServersBlock.contract.md`) verbatim and applies every plan-review + tasks-review documentation remediation (SEC-001 caller-authority, SEC-002 filter trust boundary, SEC-004 filter mutation-vs-gate, SEC-005 static-CSS invariant).

Every OWASP Top 10 2025 category applicable to this surface was reviewed against the actual diff:

- **A01 Broken Access Control** — F015 gate applied per server row; anonymous short-circuit at `AbstractUserServersRenderer.php:112`; caller-authority responsibility documented in class docblock (SEC-001).
- **A02 Cryptographic Failures** — N/A (no secrets, no tokens, no crypto).
- **A03 Injection** — N/A for SQL (no `$wpdb` calls; all reads delegate). N/A for command/template injection.
- **A04 Insecure Design** — N/A (composition-only design; no novel trust boundaries).
- **A05 Security Misconfiguration** — N/A (no configuration surface introduced).
- **A06 Vulnerable & Outdated Components** — N/A (zero new dependencies).
- **A07 Identification & Authentication Failures** — N/A (delegates to WP core `get_current_user_id()`).
- **A08 Software & Data Integrity Failures** — N/A (no data mutation).
- **A09 Security Logging & Monitoring** — Not applicable; feature is read-only.
- **A10 SSRF** — N/A (no outbound HTTP).

**XSS surface** — All string values escape at render boundary (`esc_html`, `esc_attr`, `esc_url`). PHPUnit test `test_escape_at_boundary_server_name` (UserServersBlockTest.php:229) exercises a hostile `<script>` payload in a server name and asserts escaped output.

**Overall risk: INFORMATIONAL.** One low-signal INFO finding on test-hook naming (B23-adjacent smell). Zero CRITICAL / HIGH / MODERATE / LOW findings.

## Branch Diff Reviewed

**Target**: `037-user-accessible-mcp-servers-shortcode`
**Base**: `main`
**Commits ahead**: 0 (all changes uncommitted, staged for a single PR commit)

**Files changed** (10 total — 8 modified + 2 new production, 3 new test, 4 new docs):

| File | Change | Security surface |
|------|--------|------------------|
| `public/Renderers/UserServers/AbstractUserServersRenderer.php` | NEW ~230 LOC | **Primary** — gate cascade orchestration |
| `public/Renderers/UserServers/UserServersBlock.php` | NEW ~380 LOC | **Primary** — HTML rendering + escape-at-boundary |
| `includes/Main.php` | +20 LOC (hook wiring) | Wiring only (A1 conformance) |
| `phpunit.xml.dist` | +19 LOC | Test config (no runtime surface) |
| `.github/workflows/phpunit.yml` | +4 LOC | CI config (no runtime surface) |
| `README.txt` | +2 LOC | Changelog docs |
| `docs/memory/DECISIONS.md` | +30 LOC (D40) | Docs |
| `docs/memory/INDEX.md` | +3 LOC (D40 + 2 security-review rows) | Docs |
| `docs/memory/WORKLOG.md` | +41 LOC (F038 entry) | Docs |
| `tests/phpunit/Public/Renderers/UserServers/*Test.php` | NEW 3 files | Test-only (not shipped to production) |
| `docs/planings-tasks/038-...md`, `specs/037-...` | NEW planning + spec artifacts | Docs |
| `docs/security-reviews/2026-07-29-037-...-{plan,tasks}.md` | NEW review artifacts | Docs |

## Vulnerability Findings

### SEC-B-001 (INFORMATIONAL) — Test-hook static method `reset_style_emitted_for_tests()` matches B23 naming smell

- **Finding ID**: SEC-B-001
- **Location**: `public/Renderers/UserServers/UserServersBlock.php:129`
- **OWASP category**: N/A (code hygiene, not vulnerability)
- **CWE**: CWE-489 — Active Debug Code (adjacent — test-only entry point exposed on production class)
- **CVSS v3.1 base score**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-B-001

`UserServersBlock::reset_style_emitted_for_tests()` matches the naming pattern flagged by memory bug-pattern **B23** (`_for_tests` / `_for_testing` suffix on production classes). B23's failure mode is: "test-suffix method names called from production code path are a silent-regression smell — maintainer removes the method during a cleanup sweep, invariant enforced by the call site silently breaks."

**Actual risk profile in F038**:
- ✅ Method is called **only from tests** (grep confirms: 3 hits in `tests/phpunit/Public/Renderers/UserServers/UserServersBlockTest.php`, zero hits in `includes/` or `public/`).
- ✅ Method is `public static` but resets a boolean flag with no security consequences — worst-case abuse: an attacker who somehow calls it gets a duplicate `<style>` block emitted (harmless).
- ⚠ Method name is a maintenance hazard per B23's smell heuristic.

**Recommendation** (optional, post-merge):

Rename to a production-shape name that happens to be documented as "for test isolation only". Suggested: `flush_style_cache()` with the same body. Preserves the API surface (test files unchanged after search-and-replace) and eliminates the B23 smell.

Alternative: leave as-is, add a `@internal` docblock tag + `_doing_it_wrong` call under `WP_DEBUG` if invoked from a non-test context (harder to detect reliably, mostly noise).

**Not blocking merge.** F038's actual runtime risk is bounded (boolean flag, no side effects beyond the style block). This finding is a code-hygiene note for future refactor.

## Confirmed Secure Patterns

The following patterns from the plan review + tasks review + memory synthesis are correctly applied in the implementation:

1. **Anonymous short-circuit** — `AbstractUserServersRenderer.php:112` returns `array()` immediately when `$user_id <= 0`. Verified by `test_anonymous_returns_empty` + `test_explicit_zero_user_id_returns_empty` + `test_negative_user_id_returns_empty` (all in `AbstractUserServersRendererTest.php`).
2. **F015 gate applied per server row** — `AbstractUserServersRenderer.php:137` calls `AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, $server_id )`. Fail-open when vendor package absent is inside the wrapper. Verified by `test_f015_fail_open_when_package_absent`.
3. **F037 per-DTO gate applied at DTO granularity** — `AbstractUserServersRenderer.php:158` calls `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $dto_slug )`. R2 memoization preserved. Verified by `test_master_toggle_off_drops_server` + `test_zero_dtos_drops_server` + `test_one_dto_enabled_includes_server`.
4. **Delegation-not-reimplementation grep-gates** — all 5 gates from spec.md §DoD pass:
   - Zero hits for `apply_filters.*acrossai_mcp_embed_transports` inside `public/Renderers/UserServers/`.
   - Zero hits for `apply_filters.*acrossai_mcp_client_classes` inside `public/Renderers/UserServers/`.
   - Zero hits for `ServerMetaQuery::get_meta` with `_embeds_*` keys inside `public/Renderers/UserServers/` (docblock mentions are semantic explanation only, not code).
   - Zero `use` imports of the `UserServers` namespace inside `includes/`.
   - Exactly one `add_shortcode()` call inside `public/Renderers/UserServers/`.
5. **Escape-at-boundary** — Every user-supplied string uses `esc_html` (name / description / label / empty message), `esc_attr` (data-server-id / data-server-slug / data-key / data-slug), or `esc_url` (URL icons). `test_escape_at_boundary_server_name` exercises a `<script>` payload in `server_name` and asserts the output contains `&lt;script&gt;` not `<script>`.
6. **Icon URL whitelist** — `UserServersBlock::icon_is_url()` (line 351) restricts `<img>` emission to `http://` and `https://` prefixes only. `esc_url` at the render seam provides defense-in-depth against scheme injection (matches SEC-003 informational-only finding from plan review).
7. **SEC-001 caller-authority disclosure** — Class docblock lines 30–56 of `AbstractUserServersRenderer.php` contain the caller-authority responsibility text verbatim. Documented in the IDE-visible surface, not just the specs/ folder.
8. **SEC-002 filter-boundary trust disclosure** — Class docblock lines 11–20 of `UserServersBlock.php` contain the un-sanitized filter return warning verbatim. Documented at consumption time.
9. **SEC-004 filter mutation-vs-gate disclosure** — Filter docblock lines 178–199 of `AbstractUserServersRenderer.php` contain the non-goals paragraph verbatim (SEC-004 remediation). Documented inline at the `apply_filters()` call site.
10. **SEC-005 static-CSS invariant** — Property docblock lines 51–63 of `UserServersBlock.php` contain the static-CSS invariant. `INLINE_STYLE` is a `private const` (line 77) — structurally prevents dynamic interpolation.
11. **D36 `final class` on public @experimental renderer** — `UserServersBlock.php:36` declared `final`. Reflection assertion in `test_singleton_private_ctor` verifies this at CI time.
12. **S6 private singleton constructor** — `UserServersBlock.php:116` declared `private`. Reflection assertion verifies.
13. **Non-array filter return defensive coercion** — `AbstractUserServersRenderer.php:214` (`return is_array( $data ) ? $data : array();`) prevents PHP TypeError when a buggy filter returns a non-array. Verified by `test_filter_non_array_return_defensively_coerced_to_empty`.
14. **One-way `public/` → `includes/` boundary** — No `use` statements referencing `UserServers` inside `includes/`. `Main.php:778` inline FQN wiring is A1-mandated, not a back-import.
15. **A1 hook-wiring in Main.php only** — `add_shortcode()` inside `UserServersBlock::register_shortcode()` is A1-transitive per D17 (Loader-wired bootstrap method). `Main::define_public_hooks()` at `Main.php:774–779` is the sole hook-registration site for F038.
16. **Constitution §III Security First — all applicable items** — S1 (nonces) N/A no forms; S2 (permission_callback) N/A no REST; S3 (hashed secrets) N/A no secrets; S4 (`$wpdb->prepare`) N/A no direct SQL; S5 (`esc_url` on `admin_url`) N/A no admin_url calls; S6 (private singleton ctor) enforced by test.
17. **Constitution §V Extensibility** — Two new filters (`acrossai_mcp_user_accessible_servers`, `acrossai_mcp_servers_shortcode_html`) + subclass extension surface for `AbstractUserServersRenderer`. Fail-open on wpb-access-control absence preserved.
18. **Constitution §VI DRY** — Zero re-implementation of shipped helpers. All quality gates green (PHPCS strict, PHPStan L8, npm validate-packages).

## Action Plan

1. **SEC-B-001 (INFO, optional)** — consider renaming `reset_style_emitted_for_tests()` to `flush_style_cache()` in a follow-up refactor. Not blocking merge.
2. **Merge-ready** — no CRITICAL / HIGH / MODERATE / LOW findings. All plan-review + tasks-review remediations applied verbatim in the shipped code.
3. **Next steps in the governed-implement workflow**:
   - `/speckit-architecture-guard-architecture-review` — post-implementation architectural drift scan.
   - `/speckit-analyze` — cross-artifact consistency (spec ↔ plan ↔ tasks ↔ code).
   - `/speckit-git-commit` → PR against `main` → merge → release.

## Durable Memory Preservation

No new systemic vulnerabilities or reusable security patterns emerged from the branch review. The implementation exactly matches the contracts and applies every prior-review remediation. `D40 / DEC-USER-SCOPED-ENUMERATION-COMPOSES-GATES` (captured in the pre-implement WORKLOG update) already codifies the primary durable lesson. No additional `/speckit-memory-md-capture` invocation required.

---

## Memory Hub INDEX.md row

```text
| docs/security-reviews/2026-07-29-037-user-accessible-mcp-servers-shortcode-branch.md | branch | 2026-07-29 | INFORMATIONAL | C:0 H:0 M:0 L:0 I:1 |  |
```
