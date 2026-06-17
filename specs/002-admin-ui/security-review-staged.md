---
document_type: security-review
review_type: staged
assessment_date: 2026-06-17
codebase_analyzed: acrossai-mcp-manager-new (working-tree diff scope — nothing git-add'd yet)
total_files_analyzed: 19
total_findings: 4
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 1
low_count: 0
informational_count: 3
owasp_categories: [A02, A04, A05]
cwe_ids: [CWE-89, CWE-312, CWE-664]
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

Nothing was `git add`'d at the time of this review, so the **working-tree code changes** were treated as the de-facto pre-commit set per the user's intent. The scope covers **19 files** (4 modified + 15 new) introduced during the Phase 2 Admin UI session: the BerlinDB-style Query layer (8 files), 6 admin/Partials/ classes, 1 utility, the Admin\Main asset enqueue, the Loader wiring in `includes/Main.php`, and the JS dismissal IIFE.

**Overall risk: MODERATE.** Zero new Critical / High findings. One Medium finding (SEC-001 — Claude Connector Secret stored plaintext at rest) is **carried forward from the plan-stage review** (`security-review-plan.md`) and the implementation matches the previously-approved behavior; it remains as pre-existing tech debt with a Phase 6 follow-up already noted in `data-model.md`. Three Informational findings touch code-hygiene patterns that don't represent active vulnerabilities.

**Strongest aspects of this implementation**:
- Every state-changing handler enforces both a per-action nonce AND `current_user_can('manage_options')` before any DB write or response
- The data layer is fully isolated — `grep -rn '\$wpdb' admin/` returns empty
- All DB writes use `$wpdb->insert/update/delete` with explicit format arrays; reads use `$wpdb->prepare()` for any user-controllable input
- All HTML output escaped at the boundary (`esc_html/esc_attr/esc_url/esc_textarea/esc_js`)
- `admin_url()` is universally `esc_url()`-wrapped (SEC-002 / B6 verified clean)
- No `eval`, no `unserialize`, no `exec`-family calls, no `system`, no `shell_exec`
- No hardcoded credentials, secrets, or tokens in the codebase
- REST routes (ApplicationPasswords) declare explicit `permission_callback` returning `manage_options`
- File `include` guarded by `file_exists()` (FR-019); asset URL `esc_url()`-wrapped

## Staged Diff Reviewed

Working-tree set treated as the staged scope:

**Modified (4)**:
- `admin/Main.php` — asset enqueue + screen guard
- `admin/Partials/Menu.php` — menu structure registration
- `includes/Main.php` — Loader wiring for all admin hooks
- `src/js/backend.js` — dismissal IIFE

**New (15)**:
- `admin/Partials/Settings.php` — handler dispatcher + 4 tab renderers + create form
- `admin/Partials/Notices.php` — FR-015 + FR-016 notice handlers
- `admin/Partials/MCPServerListTable.php` — WP_List_Table for servers
- `admin/Partials/CliAuthLogListTable.php` — WP_List_Table for logs
- `admin/Partials/ApplicationPasswords.php` — 2 REST endpoints + Tokens tab render
- `admin/Partials/SettingsRenderer.php` — tab-nav helper
- `includes/Utilities/AdminPageSlugs.php` — shared constants
- `includes/Database/MCPServer/{Schema,Table,Row,Query}.php` — 4 BerlinDB-style files
- `includes/Database/CliAuthLog/{Schema,Table,Row,Query}.php` — 4 BerlinDB-style files

## Vulnerability Findings

### SEC-001 — [MODERATE — Carried Forward] Claude Connector Client Secret stored plaintext at rest

- **Location**: `admin/Partials/Settings.php:537-543` (handle_claude_connector_update); `includes/Database/MCPServer/Schema.php:35` (column `claude_connector_client_secret VARCHAR(255)`)
- **OWASP Category**: A02:2025 — Cryptographic Failures
- **CWE**: CWE-312 — Cleartext Storage of Sensitive Information
- **CVSS v3.1**: 5.3 (AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:N/A:N)
- **Spec-Kit Task**: TASK-SEC-001 (already tracked in `data-model.md` follow-up note + `specs/002-admin-ui/security-review-plan.md`)

**Description**: The implementation persists the Claude OAuth Client Secret as a plain `VARCHAR(255)` column. The form masks the value on re-render and the save handler preserves the existing stored secret when the user submits only the mask placeholder (good UX). However, anyone with DB read access (legitimate or via a compromise) reads the cleartext. The plan-stage review flagged this as carried-forward behavior per the Q1 1:1-port decision; the implementation matches that decision.

**Mitigating factors observed in the implementation**:
1. `manage_options` capability required to write OR read the secret via the admin form
2. `$wpdb` prepared statements prevent SQL-injection-based leakage
3. Mask-on-render hides the value from over-the-shoulder observation
4. Defense-in-depth: `is_secret_placeholder()` prevents re-saving the mask as the real secret

**Remediation**: No change required in this slice. Phase 6 (Claude Connectors OAuth) should wrap the secret with `WP_SECURE_AUTH_KEY`-derived AES-256-GCM (per-row IV) on write and decrypt at the outbound-OAuth-request boundary only. Consider amending Constitution §III bullet 7 to cover outbound client secrets explicitly.

---

### SEC-S1 — [INFORMATIONAL] Direct SQL interpolation in idempotency check

- **Location**: `includes/Database/MCPServer/Table.php:92-94`
- **OWASP Category**: A05:2025 — Security Misconfiguration (modernization)
- **CWE**: CWE-89 (mitigated, but pattern is non-canonical)
- **CVSS v3.1**: 0.0 (informational — no exploit path)
- **Spec-Kit Task**: TASK-SEC-S1

**Description**: The `insert_default_server()` idempotency check uses a bare `"SELECT COUNT(*) FROM {$table_name}"` with `phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared`. The interpolated `$table_name` is `$wpdb->prefix + 'acrossai_mcp_servers'` — both are server-controlled values, not user input, so no injection path exists. However, the modern WordPress pattern uses the `%i` identifier placeholder.

**Remediation** (optional hardening):
```php
$count = (int) $wpdb->get_var(
    $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name )
);
```
Remove the `phpcs:ignore` comment after the change.

**Status (2026-06-17)**: Applied in this session — see commit log.

---

### SEC-S2 — [INFORMATIONAL] "// esc_url'd above" inline-comment pattern is fragile

- **Location**: `admin/Partials/Settings.php:415` (render_general_tab form action), `:567` (render_create_form), `:660` (render_claude_connector_tab form action), and 2 similar sites
- **OWASP Category**: A04:2025 — Insecure Design (defense in depth)
- **CWE**: CWE-664 — Improper Control of a Resource Through its Lifetime
- **CVSS v3.1**: 0.0 (informational — no current exploit)
- **Spec-Kit Task**: TASK-SEC-S2

**Description**: Form `action="…"` outputs use `<?php echo $post_url; // esc_url'd above ?>` where `$post_url` was earlier assigned via `esc_url(add_query_arg(...))`. Currently safe, but a future refactor (renaming `$post_url`, moving the assignment, or copy-pasting the line elsewhere) could silently break the escape. The comment is documentation, not enforcement.

**Remediation** (optional hardening): switch to inline `esc_url()` at the output point. The result is idempotent (`esc_url()` is safe to call twice):
```php
<form method="post" action="<?php echo esc_url( $post_url ); ?>">
```

**Status (2026-06-17)**: Applied in this session — see commit log.

---

### SEC-S3 — [INFORMATIONAL] Mask-detection regex could mis-classify a legitimate bullet-only secret

- **Location**: `admin/Partials/Settings.php:566-569` (`is_secret_placeholder()`)
- **OWASP Category**: A04:2025 — Insecure Design
- **CWE**: CWE-664
- **CVSS v3.1**: 0.0 (informational — exceptionally unlikely)
- **Spec-Kit Task**: TASK-SEC-S3

**Description**: `is_secret_placeholder()` returns `true` when the input contains **only** `•` (U+2022) and `*` characters. If a user — perversely — chose a real Claude Client Secret consisting entirely of bullets/asterisks, that secret would be discarded on save and the previously-stored secret kept. Likelihood: negligible (OAuth client secrets are issued by Claude, not user-chosen). Worth a note for completeness.

**Status**: Not applied — current heuristic is good enough; sentinel-checkbox alternative adds JS complexity for an exceptionally unlikely edge case.

---

## Confirmed Secure Patterns (10)

The implementation **demonstrates** these patterns, all verified by grep + inspection of the diff:

1. **Universal nonce verification** — every state-changing handler in `Settings.php` + `Notices.php` calls `check_admin_referer()` or `check_ajax_referer()` before any DB write or response. Per-action nonce action names include the row ID where relevant (e.g., `acrossai_mcp_toggle_<id>`).
2. **Universal capability gate** — `current_user_can('manage_options')` checked in every handler AND in every render callback. Non-admins receive `wp_die()` or `wp_send_json_error(403)`.
3. **Input sanitization at the boundary** — every `$_GET/$_POST/$_REQUEST` access is paired with `wp_unslash()` and the most-specific sanitiser (`sanitize_text_field`, `sanitize_textarea_field`, `sanitize_key`, `absint`, `esc_url_raw`).
4. **Mass-assignment safe** — `MCPServer\Query::update_item()` and `add_item()` iterate the schema's declared columns and silently drop unknown keys, so extra POST fields (forged or attacker-controlled) cannot write arbitrary columns. Captured as new memory entry **B7** (2026-06-17).
5. **SQL injection mitigated** — every `$wpdb->insert/update/delete` uses explicit format arrays; every user-controllable `$wpdb->get_results/get_var/get_row` uses `$wpdb->prepare()`; SQL keywords (`ORDER BY`, `LIMIT`) constrained to validated column names + hardcoded directions.
6. **Output escaping at point of render** — `esc_html/esc_attr/esc_url/esc_textarea/esc_js` applied universally. `esc_url(admin_url(...))` verified clean throughout (B6/S5).
7. **Hashed Application Password storage preserved** — `ApplicationPasswords::generate_app_password()` delegates entirely to `WP_Application_Passwords::create_new_application_password()` (WP core stores hashes; plaintext returned once and not persisted).
8. **REST routes declare permission_callback** — both `/generate-app-password` and `/list-app-passwords` use `function () { return current_user_can('manage_options'); }`. No `__return_true` on mutating routes (S2 honored).
9. **Optional integration guards** — `class_exists('\WP\MCP\Plugin')` (adapter notice), `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` (submenu + tab + Loader wiring). Plugin degrades gracefully.
10. **Per-user notice dismissal with defense-in-depth** — `Notices::handle_adapter_notice_dismissal` requires BOTH nonce AND `manage_options` even though only admins see the notice in the first place. Idempotent under repeat fire.

---

## Action Plan

| # | Action | Severity | Status |
|---|---|---|---|
| 1 | SEC-001 — Claude Connector Secret at-rest encryption | MEDIUM | Tracked as Phase 6 follow-up in `data-model.md`. No action required in this slice. |
| 2 | SEC-S1 — Modernize `Table.php:92-94` to `%i` placeholder | INFO | **APPLIED 2026-06-17.** |
| 3 | SEC-S2 — Switch form-action outputs to inline `esc_url($post_url)` | INFO | **APPLIED 2026-06-17.** |
| 4 | SEC-S3 — Replace bullet-detection heuristic with sentinel | INFO | Not applied — heuristic is good enough. |

**No commit-blocking findings.** All Critical / High threats are absent; the lone Medium is pre-approved tech debt.

---

## Memory Hub INDEX.md Row

```text
| specs/002-admin-ui/security-review-staged.md | staged | 2026-06-17 | MODERATE | C:0 H:0 M:1 L:0 | A02,A04,A05 |
```
