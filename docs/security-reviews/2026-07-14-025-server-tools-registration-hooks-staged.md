---
document_type: security-review
review_type: staged
assessment_date: 2026-07-14
codebase_analyzed: acrossai-mcp-manager (F025 working-tree diff)
total_files_analyzed: 14
total_findings: 2
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 2
owasp_categories: [A04]
cwe_ids: [CWE-1188]
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

## Executive Summary

**Feature**: 025-server-tools-registration-hooks
**Scope**: Working-tree diff (nothing yet staged — treating all uncommitted work as the review target, i.e. what will be staged in the next `git add`).
**Files analyzed**: 14 (10 modified source, 1 new source `ToolPolicy.php`, 3 new PHPUnit test files; excluding docs + spec artifacts).
**Diff size**: +482 / −245 across the source set (before untracked additions).
**Overall risk**: **LOW** — 0 Critical / 0 High / 0 Moderate / 0 Low / 2 Informational.

All six pre-implementation security-review findings (v1 3 INFO + v2 1 LOW + 2 INFO + tasks 3 INFO) are addressed inline in the shipped diff — verified by grep + read-through of the affected files. Two runtime-discovered issues (POST validation timing + GET abilities-catalog fallback) are documented as FR-018 and shipped with clear rationale in `ToolPolicy::PROTOCOL_TOOL_METADATA` + inline code comments.

The staged diff introduces no new attack surface: no new REST routes, no new secrets, no new outbound network calls, no new dependencies, no vendor code changes. All DB writes flow through BerlinDB Kern's prepared-statement path (`MCPServerQuery::update_item()`, `MCPServerToolQuery::replace_set()`). The T012 PRESERVATION invariant (`permission_callback`, nonce middleware, `manage_options` capability check MUST NOT be modified) is honored — confirmed by diff read.

Two INFORMATIONAL findings are reviewer-clarity observations, not runtime concerns:

1. **SEC-STAGED-025-1**: FR-018's POST validation bypass and F020's `ToolExposureGate::EXCLUDED_SLUGS` gate bypass are two separate documented protocol-slug exemption surfaces at different layers. Neither crosses a security boundary, but reviewer awareness matters when future features add gates on the same tool-call path.
2. **SEC-STAGED-025-2**: The per-column `acrossai_mcp_tools_changed` emission loop in `ToolsController::post_tools()` delegates fault isolation to the `fire_change_action()` helper's inner try/catch. A reviewer scanning `post_tools()` in isolation might miss where the observer-fault isolation lives; an inline comment naming the helper would improve traceability.

Neither blocks merge.

## Staged Diff Reviewed

### Modified (10 files, from `git diff --stat`)

| File | Delta | Nature |
|---|---|---|
| `includes/Database/MCPServer/Schema.php` | +23 / −0 | 3 new `tinyint(1) NOT NULL DEFAULT 1` columns (`tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`) |
| `includes/Database/MCPServer/Table.php` | +1 / −1 | `$version` `1.0.0` → `1.1.0` |
| `includes/Database/MCPServer/Row.php` | +33 / −22 | 3 new public int props + B18 `(int)` casts in ctor + 3 new `to_array()` keys |
| `includes/MCP/Controller.php` | +85 / −4 | `register_database_servers()` uses `ToolPolicy` + fires new filter; adds `filter_default_server_config()` method |
| `includes/MCP/ToolExposureGate.php` | +14 / −5 | Docblock only — marks `EXCLUDED_SLUGS` vestigial post-F025 (SEC-025-INFO-3) |
| `includes/Main.php` | +1 / −0 | One `$this->loader->add_filter('mcp_adapter_default_server_config', ...)` line |
| `includes/REST/ToolsController.php` | +107 / −63 | Deletes `EXCLUDED_SLUGS` + rejection guard; adds `ToolPolicy::split_payload` write path (columns + curated); GET composes via `ToolPolicy::compose_for_row`; adds FR-018 catalog fallback + POST protocol-slug validation bypass |
| `src/js/tools.js` | +215 / −143 | Deletes JS `EXCLUDED_SLUGS`; adds `PROTOCOL_TOOL_SLUGS`; rewrites `AbilityRow` (drops locked-builtin branch); imports `ConfirmDialog`; adds Remove-protocol + Reset dialogs; empty-state banner (FR-017); count-text simplification |
| `build/js/tools.{js,asset.php}` | (build artifacts) | Auto-generated by `wp-scripts build` — reviewed as build output, not source of truth |
| `.specify/feature.json` | +1 / −1 | Feature pointer update |

### New / untracked (4 source, ignoring docs + spec artifacts)

| File | Nature |
|---|---|
| `includes/Database/MCPServer/ToolPolicy.php` | New stateless service — `PROTOCOL_TOOLS`, `COLUMN_MAP`, `PROTOCOL_TOOL_METADATA`, `compose_for_row()`, `split_payload()` |
| `tests/phpunit/Database/MCPServer/ToolPolicyTest.php` | 9 test cases (includes SEC-TASKS-025-1 confused-deputy) |
| `tests/phpunit/Database/MCPServer/SchemaMigrationTest.php` | 3 test cases |
| `tests/phpunit/MCP/ControllerToolsInjectionTest.php` | 9 test cases (includes SEC-TASKS-025-1) |
| `tests/phpunit/REST/ToolsControllerTest.php` | 7 test cases (includes SEC-025-v2-2 + SEC-TASKS-025-2 legal-empty state) |

## Vulnerability Findings

### [INFORMATIONAL] SEC-STAGED-025-1 — Dual protocol-slug exemption surface

**Location**: `includes/REST/ToolsController.php:281-284` (POST validation bypass, added this diff) + `includes/MCP/ToolExposureGate.php:55-59` (existing F020 `EXCLUDED_SLUGS` call-time gate bypass, now vestigial per T019)
**OWASP Category**: A04:2025 — Insecure Design (documentation clarity)
**CWE**: CWE-1188 — Initialization of a Resource with an Insecure Default (applied conceptually — the concern is *dual defaults* rather than *insecure defaults*)
**CVSS v3.1**: 0.0 (no runtime vulnerability)
**Spec-Kit task**: TASK-SEC-STAGED-025-1

**Description**: The staged diff introduces FR-018's protocol-slug bypass on the REST POST validation path. Meanwhile, F020's `ToolExposureGate::EXCLUDED_SLUGS` bypass on the `mcp_adapter_pre_tool_call` gate (call-time) continues to exist. Both exempt the same three slugs, but they operate at different layers:

- **FR-018 POST bypass**: skips `wp_get_abilities()` catalog validation because the vendor's abilities registration runs too late (documented in the source comment and spec FR-018 §Rationale).
- **F020 gate bypass**: skips the per-server tool-curation gate because F020's shipping-time contract treated protocol tools as unconditional. Now vestigial under F025's DB-authoritative model (documented in T019's docblock update).

Neither bypass crosses a security boundary — `permission_callback` (`manage_options`) still gates REST access; the adapter still refuses unregistered tools on `tools/call`. But a reviewer or future feature author scanning "how does the plugin handle the three protocol slugs" now has to trace two different exemption paths in two different files.

**Why this is not a real vulnerability**: both bypasses are documented, justified, and gated by the plugin's own canonical constant (`ToolPolicy::PROTOCOL_TOOLS`). Neither can be triggered by attacker-controlled input to expand the exemption set.

**Remediation** (non-blocking):
- Consider extending `T019`'s `EXCLUDED_SLUGS` docblock to cross-link FR-018 and the POST-side bypass, so a reviewer touching either finds the other via one grep.
- Post-2-week-soak follow-up ticket (already tracked in tasks.md Notes): remove `ToolExposureGate::EXCLUDED_SLUGS` and rely solely on the DB-authoritative model. That collapses the dual-exemption surface into one.

**Blocking?** No.

---

### [INFORMATIONAL] SEC-STAGED-025-2 — `fire_change_action` fault isolation not surfaced in `post_tools()` reviewer view

**Location**: `includes/REST/ToolsController.php:329-353` (new F025 per-column emission loop) + `includes/REST/ToolsController.php:378-401` (existing F020 `fire_change_action()` helper with try/catch)
**OWASP Category**: A04:2025 — Insecure Design (documentation clarity)
**CWE**: (defensive-code documentation, no direct CWE)
**CVSS v3.1**: 0.0 (behavior is correct; reviewer clarity only)
**Spec-Kit task**: TASK-SEC-STAGED-025-2

**Description**: F020 shipped `fire_change_action()` as a helper that wraps `do_action( 'acrossai_mcp_tools_changed', ... )` in `try { ... } catch ( \Throwable $e ) { ... }` — so a broken observer never 500s the REST response (SEC-020-004 / FR-031). F025 reuses this helper for per-column-flip emission via a new loop in `post_tools()`.

A reviewer reading only `post_tools()` sees a bare-looking loop calling `$this->fire_change_action(...)` — the try/catch isolation is in the helper, not visible in `post_tools()`. Someone auditing "does F025's new event emission properly isolate observer faults?" has to know to jump to `fire_change_action()` to confirm.

**Why this is not a real vulnerability**: the fault isolation IS present — F020 shipped it and F025 reuses it. The code IS correct.

**Remediation** (non-blocking): add a one-line comment above the F025 emission loop naming the helper's fault-isolation contract, e.g.:

```php
// F025 FR-016 — fire acrossai_mcp_tools_changed per flipped protocol column.
// Fault isolation lives in fire_change_action() (try/catch per SEC-020-004);
// broken observers cannot 500 this response.
foreach ( ToolPolicy::COLUMN_MAP as $column => $slug ) {
    ...
}
```

**Blocking?** No.

## Confirmed Secure Patterns

The staged diff explicitly upholds the following security posture. Each is verified by direct read of the diff — not inferred from the plan.

1. **T012 PRESERVATION invariant honored** — `permission_callback = array( $this, 'permission_check' )` bindings on both GET and POST routes are UNTOUCHED. Nonce middleware setup in `src/js/tools.js` is UNTOUCHED. `manage_options` capability check inside `permission_check()` is UNTOUCHED. Verified by targeted diff read of `register_routes()` and `permission_check()`.
2. **Prepared statements only** — three DB write paths introduced by this diff (`MCPServerQuery::update_item($server_id, $split['columns'])`, `MCPServerToolQuery::replace_set()`, plus the schema `ALTER TABLE ADD COLUMN` triggered by BerlinDB `maybe_upgrade()`) all flow through BerlinDB Kern's prepared-statement infrastructure. Zero raw `$wpdb->query()` calls added.
3. **Column names + values are constant-derived, not attacker-derived** — `ToolPolicy::split_payload()`'s `columns` output has keys from `COLUMN_MAP` (a class constant) and values from a boolean `in_array($slug, $normalized, true)` check. Attacker cannot inject an unknown column name into `update_item()`.
4. **Slug normalization at the REST boundary** — `$tools_param` passes through `strval` + `array_filter( ..., 'strlen' )` + `array_unique` inside `ToolPolicy::split_payload()` before hitting the write path. Non-string / empty / duplicate entries collapse to a clean canonical form.
5. **Protocol-slug bypass is safe** — the FR-018 POST validation bypass exempts slugs matched against `ToolPolicy::PROTOCOL_TOOLS`, a hardcoded plugin-owned class constant. No attacker input flows into the bypass predicate.
6. **Filter return re-normalized** — `Controller::register_database_servers()` re-normalizes the third-party filter return via `array_values( array_unique( array_map( 'strval', (array) $tools ) ) )` before passing to `$adapter->create_server()`. Companion plugin returning non-array / non-string / duplicate cannot corrupt the adapter call.
7. **No new secrets** — grep audit: no new API keys, tokens, passwords, or credentials hardcoded in the diff. The three protocol slugs in `ToolPolicy::PROTOCOL_TOOLS` are public identifiers, not secrets.
8. **Static i18n copy in JS dialogs** — all `ConfirmDialog` copy in `src/js/tools.js` uses hardcoded English strings wrapped in `__()`. No user-controlled text renders in the dialogs; no XSS via dialog body.
9. **Error responses use static i18n strings + minimal reflected input** — the two 400 branches in `post_tools()` (`rest_invalid_type` and `acrossai_mcp_invalid_tool_slug`) return static i18n messages plus an `invalid_slugs` array that is a `strval`'d subset of user input. JSON response body; no HTML rendering path.
10. **Server-side `error_log()` line has no user-controlled content** — the `sprintf` in the `post_tools()` catch branch composes `server_id` (int), `desired_count` (int), and `$e->getMessage()`. Even if `$e->getMessage()` contained reflected content, it's server-log-only per SEC-020-010 (log-specific / respond-generic).
11. **Zero new dependencies** — `composer.json`, `package.json`, `composer.lock`, `package-lock.json` all unmodified in the diff. No supply-chain risk introduced.
12. **Zero vendor edits** — `vendor/wordpress/mcp-adapter/` is untouched. Verified by `git diff --stat vendor/` returning empty for the F025 branch.
13. **No new outbound HTTP** — no `wp_remote_get()` / `wp_remote_post()` / `curl` / `fetch()` calls introduced by the diff. The plugin's REST surface exposes an inbound endpoint; the adapter registration is internal.
14. **Nonce middleware preserved on tools.js** — the `apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) )` call at `src/js/tools.js:806-808` is untouched. Every POST from the Tools tab carries the plugin-scoped nonce.
15. **Vendor filter contract used exactly** — `Controller::filter_default_server_config( $config )` matches the vendor's `apply_filters( 'mcp_adapter_default_server_config', $wordpress_defaults )` signature (single positional arg). Verified against `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:88`.

## Action Plan

### 1. Non-blocking clarity edits (in this PR or follow-up)

- **SEC-STAGED-025-1** — one-line docblock cross-links in `ToolExposureGate::EXCLUDED_SLUGS` and the FR-018 comment in `ToolsController::post_tools()`.
- **SEC-STAGED-025-2** — one-line comment above the per-column emission loop naming `fire_change_action()`'s fault-isolation contract.

### 2. Durable Memory Preservation (mandatory check)

The two staged-diff findings are per-review clarity observations, not systemic patterns. No new architectural patterns or repeatable lessons surfaced from this staged review specifically. The four systemic patterns identified during the earlier plan/tasks/architecture reviews (`DEC-F025-HYBRID-TOOL-STORAGE`, `DEC-F025-V2-VENDOR-SOURCE-CROSS-CHECK-CADENCE`, `DEC-TASKS-025-PRESERVATION-INVARIANT-PATTERN`, `DEC-TASKS-025-COVERAGE-MATRIX-PATTERN`) remain deferred to post-merge capture per F020's "defer until soaked" WORKLOG guidance.

**No `/speckit-memory-md-capture` invocation at this turn.**

### 3. Remediation Planning

No CRITICAL or HIGH findings. `/speckit-security-review-followup` NOT required. Both INFO findings are documentation-clarity nits.

### 4. Ready for merge

The staged diff is **cleared for `git add` + `git commit`** from a security perspective. Recommended sequence:

1. `git add` — stage all F025 files.
2. `git commit` — one logical commit covering the F025 diff.
3. `gh pr create` — open the PR against `main`.

The two SEC-STAGED-025-* clarity edits can be included in this same PR or filed as a lightweight follow-up.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-14-025-server-tools-registration-hooks-staged.md | staged | 2026-07-14 | LOW | C:0 H:0 M:0 L:0 I:2 | A04 |
```
