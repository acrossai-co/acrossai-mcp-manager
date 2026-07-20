---
document_type: security-review
review_type: staged
assessment_date: 2026-07-20
codebase_analyzed: acrossai-mcp-manager (Feature 030 unstaged diff — 8 modified files + 3 new PHP files + 5 new PHPUnit tests + marketing assets)
total_files_analyzed: 16
total_findings: 5
overall_risk: INFORMATIONAL
critical_count: 0
high_count: 0
medium_count: 0
low_count: 0
informational_count: 5
owasp_categories: [A03, A08, A09]
cwe_ids: [CWE-79, CWE-778, CWE-693]
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

**Verdict: green-light for merge from a security perspective.** All 5 plan-phase findings from `2026-07-20-030-per-server-permission-override-plan.md` are either verified-remediated in this diff or accepted as documented limitations. No NEW vulnerabilities were introduced by the implementation phase. Overall risk downgraded from MODERATE (plan) to INFORMATIONAL (post-implement).

The by-design `permission_callback` bypass remains an accepted product decision — safety depends on operator judgment about when to flip the toggle. That's an irreducible characteristic of any admin-facing security-override feature; not a code defect.

**Note on scope**: nothing is currently `git add`-staged. This review treats the entire F030 working-tree diff (`git diff` output) + untracked new files as "the changes that will be staged." Re-run this review after `git add` if you stage a partial subset before commit.

## Staged Diff Reviewed

**Modified (8 files, 756 insertions / 48 deletions):**
- `admin/Partials/ServerTabs/AccessControlTab.php` — form render + inline `<script>` for confirm() + inline `<style>` layout
- `admin/Partials/Settings.php` — router branch + `handle_save_permission_override()`
- `includes/Main.php` — 4-line PermissionOverrideProcessor wiring
- `includes/Database/MCPServer/Schema.php` — column entry
- `includes/Database/MCPServer/Table.php` — `$version` bump + `upgrade_to_1_1_2()`
- `includes/Database/MCPServer/Row.php` — property + int cast
- `phpunit.xml.dist` — 3 new test suites (abilities/database/mcp)
- `.github/workflows/phpunit.yml` — 3 new CI steps
- `README.txt` — changelog bullet
- `.specify/feature.json` — feature dir pointer

**New / untracked (11 files):**
- `includes/Abilities/PermissionOverrideProcessor.php` — the runtime closure singleton (170 lines)
- `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php` — promo card + `<details>` (200 lines)
- 5 PHPUnit test files (~500 lines total)
- 4 spec/memory/planning/security-review docs
- 14 marketing assets in `.wordpress-org/`

## Vulnerability Findings

### [INFORMATIONAL] SEC-030-001 — JS-context XSS remediation VERIFIED PRESENT

**Location:** `admin/Partials/ServerTabs/AccessControlTab.php:509–513`
**OWASP Category:** A03:2025 Injection (XSS)
**CWE:** CWE-79: Improper Neutralization of Input During Web Page Generation
**CVSS v3.1:** 0.0 (verified remediated; downgraded from MEDIUM in plan review)
**Spec-Kit task:** TASK-SEC-030-001 (closed)

**Description:** The plan-phase review flagged this as MEDIUM ship-blocker. Diff confirms the inline `<script>` block interpolates both dynamic values via `wp_json_encode()`:

```php
printf(
    '<script>document.getElementById(%1$s).addEventListener("submit", ... window.confirm(%2$s) ...</script>',
    wp_json_encode( $form_id ),
    wp_json_encode( $confirm_msg )
);
```

Neither value flows through `esc_html()` or `esc_attr()` for the JS-string context, which was the risk vector. Hostile server names containing `'`, `"`, `\`, newline, or `</script>` are correctly encoded as JSON string literals. **Ship-blocker closed.**

**Remediation:** none — verified applied.

---

### [INFORMATIONAL] SEC-030-002 — Audit trail observability hook VERIFIED PRESENT

**Location:** `admin/Partials/Settings.php:441–455`
**OWASP Category:** A09:2025 Security Logging Failures
**CWE:** CWE-778: Insufficient Logging
**CVSS v3.1:** 0.0 (verified remediated; downgraded from LOW in plan review)
**Spec-Kit task:** TASK-SEC-030-002 (closed)

**Description:** The save handler fires the D19-style observability action before the redirect:

```php
do_action(
    'acrossai_mcp_permission_override_toggled',
    $server_id,
    $value,
    get_current_user_id(),
    time()
);
```

Fire-and-forget — operators wire any logger (Query Monitor, custom audit table, syslog, webhook) without F030 depending on a specific store. Payload includes the four fields needed for post-incident forensics: which server, new value, who flipped it, when.

**Residual note:** if no operator attaches a listener, there is still no forensic trail. That's a documented product trade-off per the plan review's recommendation-not-mandate stance. Consider `docs/memory/WORKLOG.md` guidance on wiring this hook for production installs.

**Remediation:** none required for the code path.

---

### [INFORMATIONAL] SEC-030-003 — Filter-priority footrace accepted-limitation VERIFIED DOCUMENTED

**Location:** `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php:184–190`
**OWASP Category:** A08:2025 Software and Data Integrity Failures
**CWE:** CWE-693: Protection Mechanism Failure
**CVSS v3.1:** 3.7 (Low — unchanged; the finding is accepted as documented limitation, not remediated by code)
**Spec-Kit task:** TASK-SEC-030-003 (partially closed — documentation shipped, runtime detection deferred)

**Description:** The `<details>` block in the promo card explicitly documents both the filter name and F030's priority number via `PermissionOverrideProcessor::PRIORITY` constant:

> "This plugin registers on the WordPress core filter `wp_register_ability_args` at priority 999999. Hook the same filter at a higher priority to override the override…"

Combined with the `test_p999999_beats_p100000_denying_filter` regression in `PermissionOverrideIsolationTest`, the priority slot map is now documented + tested. **Boot-time detection of conflicting registrations at ≥ P999999 was NOT implemented** — remains a follow-up feature. If a future plugin registers at P999999 or above, F030's bypass semantics silently break (either negated or subsumed). Accepted per the plan review.

**Remediation:** none in this diff; note as durable-memory pattern `B35` when running `/speckit-memory-md-capture`.

---

### [INFORMATIONAL] SEC-030-004 — Trust boundary documentation VERIFIED PRESENT

**Location:** `specs/030-per-server-permission-override/spec.md` §Security Checklist (trust-boundary paragraph)
**OWASP Category:** N/A (documentation)
**CWE:** N/A
**CVSS v3.1:** 0.0
**Spec-Kit task:** TASK-SEC-030-004 (closed)

**Description:** Spec now explicitly enumerates the trust boundary for `CurrentServerHolder::get_server_id()`:

> "Populated only inside `rest_pre_dispatch` P5 by `capture_from_request()`, which matches the incoming REST route against `McpAdapter::instance()->get_servers()` — server-side authoritative. NEVER populated from URL parameters, POST body, headers, or client-supplied identifiers. Any bug in `CurrentServerHolder::capture_from_request()` that let a client control which server_id is returned would defeat F030's per-server scoping; A17 wiring (including the `shutdown` P999 safety-net) MUST NOT regress."

Correctly identifies the A17 wiring as a load-bearing dependency and the `shutdown` safety-net as a required invariant. **Reviewers of future changes to `CurrentServerHolder` should read this paragraph as part of their diff review.**

**Remediation:** none.

---

### [INFORMATIONAL] SEC-030-005 — HTTPS `download_url` VERIFIED SAFE VIA BASELINE

**Location:** `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php` (state detection consumes main-menu's baseline addons list, not a plugin-authored entry)
**OWASP Category:** A02:2025 Cryptographic Failures (data-in-transit)
**CWE:** CWE-311: Missing Encryption of Sensitive Data
**CVSS v3.1:** 0.0
**Spec-Kit task:** TASK-SEC-030-005 (closed via baseline)

**Description:** Plan phase T024 called for pinning `download_url` explicitly with `https://` on a F030-owned addon entry. Implementation instead relies on `acrossai-co/main-menu`'s baseline `AddonsPageRenderer::ADDONS` const, which lists `acrossai-abilities-manager` with `source: 'wordpress.org'`. WordPress core's `plugins_api()` enforces HTTPS for wordpress.org sources. **No F030-owned URL to validate — SEC-030-005 is safe by vendor delegation.**

**Follow-up guard** (architecture review R5): add one PHPUnit assertion that `apply_filters('acrossai_addons', [])` contains the sibling slug — protects against future `main-menu` version dropping the entry. Not shipped in this diff; noted as low-priority follow-up.

**Remediation:** none required; add the R5 regression test as follow-up.

## Confirmed Secure Patterns

The following patterns were reviewed and confirmed secure in the diff:

| # | Pattern | Evidence |
|---|---------|----------|
| 1 | **Nonce bound to server_id** | `Settings.php:401` — `check_admin_referer( 'acrossai_mcp_manager_permission_override_' . $server_id, 'acrossai_mcp_manager_permission_override_nonce' )`. Attacker cannot replay a nonce generated for server A against server B. |
| 2 | **Capability gate defensively re-enforced** | `Settings.php:419` — `if ( ! current_user_can( 'manage_options' ) ) { wp_die( … 403 ) }` inside `handle_save_permission_override()` on top of the top-level `handle_actions()` gate. |
| 3 | **Input sanitization at boundary** | `Settings.php:394` — `absint( $_GET['server'] )`; `Settings.php:436` — `! empty( $_POST['override_abilities_permission'] ) ? 1 : 0` (boolean coercion, no arbitrary value written to DB). |
| 4 | **Output escaping at render** | `AccessControlTab.php` and `AbilitiesManagerPromoCard.php` — every echoed dynamic value wrapped in `esc_html`, `esc_html__`, `esc_attr`, `esc_url`, `esc_url_raw`, or `checked()`. Static translated strings use `esc_html_e`. |
| 5 | **JS-string context correctly encoded** | `AccessControlTab.php:509–513` — `wp_json_encode()` for both `$form_id` and `$confirm_msg` interpolations into the inline `<script>`. SEC-030-001 remediation in place. |
| 6 | **Reverse-tabnabbing defense on new-tab links** | `AbilitiesManagerPromoCard.php:159` — promo card CTA link uses `target="_blank" rel="noopener noreferrer"`. Prevents `window.opener` control by the destination Add-ons page (even though it's same-origin, defense-in-depth). |
| 7 | **Private singleton `__construct()`** | `PermissionOverrideProcessor.php:92` — `private function __construct() {}`. Prevents duplicate instantiation → duplicate filter registration → double-firing. |
| 8 | **DB queries parameterised via BerlinDB Query** | `PermissionOverrideProcessor.php:167` — `MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )`. `Settings.php:438` — `$query->update_item( $server_id, array( 'override_abilities_permission' => $value ) )`. BerlinDB base class parameterises. |
| 9 | **ALTER TABLE identifier interpolation only** | `Table.php:upgrade_to_1_1_2` — DDL uses `$wpdb->prefix . 'acrossai_mcp_servers'` (hard-coded slug) with backtick quoting; column name is a hard-coded literal in the ALTER string. `$wpdb->prepare()` does not support DDL identifiers; this matches the D28 reference impl and the existing `upgrade_to_1_1_1` pattern. |
| 10 | **B18 tinyint-as-string defense** | `Row.php:52` — `override_abilities_permission = (int) $this->override_abilities_permission` in the constructor. `PermissionOverrideProcessor.php:118` — closure comparison uses `0 === (int) $row->override_abilities_permission`. No `1 === $val` strict-compare against string. |
| 11 | **B24 duck-typed vendor accessor** | `AbilitiesManagerPromoCard.php:246` — `class_exists( '\\AcrossAI_Main_Menu\\AddonsInstaller' )` guard before instantiation, with `get_plugins()` fallback. Matches the F015/F017/F020 duck-typing pattern (not `instanceof`). |
| 12 | **A17 request-scoped context + shutdown safety-net** | `Main.php:551–553` — companion `rest_post_dispatch` P999 + `shutdown` P999 hooks wired for `PermissionOverrideProcessor::clear_request_cache`, symmetric with `CurrentServerHolder::clear`. Long-lived-PHP-process leak defense. |
| 13 | **Wp_die with esc_html__ message + 403 response code** | `Settings.php:421–425` — `wp_die( esc_html__( 'You do not have permission…', 'acrossai-mcp-manager' ), '', array( 'response' => 403 ) )`. Correct HTTP status + escaped translated string. |
| 14 | **Save-notice GET flag safety** | `AccessControlTab.php:135` — `if ( empty( $_GET['acrossai_mcp_manager_permission_saved'] ) ) return;` only gates a static translated string echo. No user-controlled data flows from the GET param to the output. Worst case: attacker crafts a link causing an admin to see a spurious "saved" notice — pure UX confusion, no security impact. |
| 15 | **wp_safe_redirect + esc_url_raw** | `Settings.php:461–474` — `wp_safe_redirect( esc_url_raw( add_query_arg( … , admin_url( 'admin.php' ) ) ) )`. Redirect restricted to same host + URL properly escaped for header context. |
| 16 | **Empty `$meta` to ExposureResolver — DOCUMENTED carve-out** | `PermissionOverrideProcessor.php:123–142` — 20-line inline comment explains the DEC-F030-EXPLICIT-EXPOSURE-ONLY scoped exception to DEC-ABILITY-OVERRIDE-RESOLUTION. Deliberately more conservative than F017's canonical resolution; scoped to F030 only. |

## Action Plan & Next Steps

**Merge decision**: 🟢 **APPROVED from security perspective.**

Prioritized items:

1. **Ship the diff.** All plan-phase findings resolved or documented. No new vulnerabilities introduced.
2. **Run `/speckit-memory-md-capture`** (T033) — formalize the durable memory entries proposed by earlier phases:
   - `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS` (D24 scoped carve-out)
   - `DEC-F030-EXPLICIT-EXPOSURE-ONLY` (DEC-ABILITY-OVERRIDE-RESOLUTION scoped carve-out)
   - `DEV5` (per-server-edit tab hand-rolled form exception to §IV)
   - `B35` (filter-priority footrace pattern)
   - `B36` (wp_json_encode for JS-context — generalizable beyond F030)
3. **Add R5 regression test** (architecture review follow-up) — one PHPUnit assertion that `apply_filters('acrossai_addons', [])` contains `acrossai-abilities-manager`.
4. **Wire the `acrossai_mcp_permission_override_toggled` action** in production installs — recommend adding operator guidance to README or a `docs/OPERATIONS.md` snippet.
5. **Consider a boot-time filter-priority conflict detector** as a follow-up feature (SEC-030-003 hardening). Non-blocking.
6. **Batch-flip completed tasks.md checkboxes** — 25 of 35 tasks completed; the pending set is T030 (`git add .wordpress-org/`), T031 (already run — this review closes it), T032 (this review), T033–T035 (memory capture + INDEX + docs).

**No systemic vulnerability patterns surfaced.** The five INFORMATIONAL findings are all closures of plan-phase items, not new discoveries — no NEW security lessons to capture beyond the four already-proposed durable entries listed above.

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-20-030-per-server-permission-override-staged.md | staged | 2026-07-20 | INFORMATIONAL | C:0 H:0 M:0 L:0 I:5 | A02,A03,A08,A09 |
```
