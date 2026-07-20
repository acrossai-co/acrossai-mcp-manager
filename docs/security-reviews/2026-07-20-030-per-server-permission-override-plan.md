---
document_type: security-review
review_type: plan
assessment_date: 2026-07-20
codebase_analyzed: acrossai-mcp-manager (Feature 030 plan artifacts only)
total_files_analyzed: 3
total_findings: 5
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 1
low_count: 2
informational_count: 2
owasp_categories: [A03, A08, A09, A02]
cwe_ids: [CWE-79, CWE-778, CWE-693, CWE-311]
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

# Security Review — Feature 030 Plan

## Executive Summary

Feature 030's plan is **secure by design at the level of authorization**, but ships **one MEDIUM-severity encoding gap** that must be pinned before implementation, plus **two LOW-severity operability gaps** worth addressing in the same increment. The runtime override closure and the DB save path are both correctly gated by capability + nonce + defensive scope narrowing (CurrentServerHolder + ExposureResolver + operator opt-in), so no critical or high-severity findings surfaced. The deliberate bypass of the ability's own `permission_callback` is an acknowledged design intent (soft conflict with D24 corollary, documented in the plan's Complexity Tracking table and in `memory-synthesis.md`) — reviewed and accepted with the six defensive layers documented in the plan's §Constitution Check §III.

**Overall risk: MODERATE.** Ship-blocking only for SEC-030-001 (inline `<script>` XSS-adjacent encoding); the other four findings are quality-gate recommendations that are cheap to close in the same PR.

## Plan Artifacts Reviewed

- `specs/030-per-server-permission-override/plan.md` — the implementation plan generated inline by `/speckit-architecture-guard-governed-plan` (current turn).
- `specs/030-per-server-permission-override/spec.md` — feature specification with 4 clarification Q/As integrated (Session 2026-07-20).
- `specs/030-per-server-permission-override/memory-synthesis.md` — memory synthesis identifying D24 soft conflict + load-bearing constraints (D28, D25, A17, B18, B34).
- Cross-referenced against `.specify/memory/constitution.md` v1.1.0 (§III Security First), `docs/planings-tasks/030-per-server-permission-override.md` (engineering brief with concrete code snippets), and `docs/memory/INDEX.md` (34 bug patterns, 9 security constraints).

## Vulnerability Findings

### SEC-030-001 — Inline `<script>` for `confirm()` prompt must JSON-encode the server name

- **Severity**: MEDIUM
- **Location**: `admin/Partials/ServerTabs/AccessControlTab.php::render_body()` — the inline `<script>` block introduced by FR-017 (spec) / Task-2 (engineering brief)
- **OWASP Category**: A03:2025 Injection (Cross-Site Scripting)
- **CWE**: CWE-79: Improper Neutralization of Input During Web Page Generation
- **CVSS v3.1**: 4.6 (Medium) — `AV:N/AC:H/PR:H/UI:R/S:U/C:L/I:L/A:N`
- **Spec-Kit task**: TASK-SEC-030-001

**Description**: FR-017 requires the form to fire a native browser `confirm()` prompt "naming the specific server (server name from the row)" when submitted with the override checkbox checked. The plan does not specify the encoding path for the dynamic server-name interpolation into the inline `<script>` block. Naïve implementations use `echo` / `esc_html()` — but `esc_html()` produces HTML-safe output, NOT JS-string-safe output. A server name containing `'`, `\`, newline, or the closing tag `</script>` will either break the JS or introduce stored XSS.

**Threat model**: An authenticated admin with `manage_options` creates a server whose `server_name` field contains `'; alert(document.cookie); //`. A second admin visits that server's Access Control tab and submits the form. The malicious payload executes in the second admin's browser session. Requires admin-on-admin exploitation, which limits impact — but F030 introduces the injection vector via inline script.

**Remediation**:
- MUST use `wp_json_encode( $server_name )` when interpolating into the inline `<script>` — this produces a JS-string-safe literal that handles quotes, escapes, and `</` sequences correctly.
- Alternative: emit the server name as a `data-*` attribute (`esc_attr()` encoded) on the form element and have the inline script read it via `event.target.form.dataset.serverName`. Cleaner separation but same defense.
- Add a task-level PHPUnit test that renders the form with a server whose name contains each of: `'`, `"`, `\`, `\n`, `</script>`. Assert the rendered HTML contains no unescaped occurrence of any of these in a JS context.

**Add to plan** as an explicit FR (suggested wording): *"FR-017a: When interpolating any dynamic value (server name, server id) into the inline `<script>` block for the `confirm()` prompt, the value MUST be encoded via `wp_json_encode()`. `esc_html()` / `esc_attr()` are insufficient for JavaScript string contexts."*

---

### SEC-030-002 — No audit trail for override toggle changes

- **Severity**: LOW
- **Location**: `admin/Partials/Settings.php::handle_save_permission_override()` (per plan Phase 1)
- **OWASP Category**: A09:2025 Security Logging and Monitoring Failures
- **CWE**: CWE-778: Insufficient Logging
- **CVSS v3.1**: 2.4 (Low) — `AV:L/AC:H/PR:H/UI:N/S:U/C:N/I:N/A:N` (auditability, not confidentiality/integrity)
- **Spec-Kit task**: TASK-SEC-030-002

**Description**: The permission override is a security-relevant toggle whose "ON" state grants any authenticated MCP client access to every ability exposed to the target server. The plan does not emit any audit log entry when the toggle is flipped. The stated precedent (per synthesis) is that the F025 `tool_*` per-server flags also don't audit-log — but those flags gate feature availability, not authorization. F030's toggle carries meaningfully higher security weight and should be traceable ("who enabled this, when, on which server?").

**Threat model**: Post-incident forensics on a compromised MCP client. Investigator asks "when did MCP requests start bypassing per-ability access rules for server X, and which admin made that change?" — no answer available without an audit trail. Not exploitable, but hampers response.

**Remediation** (options in preference order):
1. **Fire a scoped `do_action()`** at end of `handle_save_permission_override()`: `do_action( 'acrossai_mcp_permission_override_toggled', (int) $server_id, (int) $value, get_current_user_id(), time() );`. Matches D19 fail-open observability pattern — fire-and-forget, no hard dep on a specific audit store, operators can wire any logger (Query Monitor, custom `add_action` handler, etc.).
2. **Write to `oauth_audit`** table with `event_type = 'permission_override_toggled'`. Adds a hard dep on the audit table's presence and shape; heavier but persistent.
3. Accept the auditability gap and document in DECISIONS.md as an accepted trade-off. Consistent with `tool_*` precedent but arguably weaker than the security weight warrants.

**Recommendation**: implement option 1 (`do_action`). Zero new tables, zero new deps, matches an existing plugin-wide pattern.

---

### SEC-030-003 — Filter-priority precedence is a fragile security control

- **Severity**: LOW
- **Location**: `includes/Abilities/PermissionOverrideProcessor::boot()` — the `add_filter('wp_register_ability_args', ..., 999999, 2)` registration
- **OWASP Category**: A08:2025 Software and Data Integrity Failures
- **CWE**: CWE-693: Protection Mechanism Failure
- **CVSS v3.1**: 3.7 (Low) — `AV:L/AC:H/PR:H/UI:N/S:U/C:L/I:L/A:N`
- **Spec-Kit task**: TASK-SEC-030-003

**Description**: F030 registers on `wp_register_ability_args` at priority `999999` to guarantee it wins over sibling `acrossai-abilities-manager` (P100000) and this plugin's own `CallbackReplacer` (P10). Any future filter registered at OR ABOVE `999999` silently supersedes F030's override closure — either restoring the ability's original `permission_callback` (undoing the operator's opt-in) OR replacing it with a different arbitrary callback. Filter priority is not a durable authorization boundary; it is a load-order coincidence.

**Threat model**: A rogue plugin installs a `wp_register_ability_args` hook at P1000000 that wraps every ability's callback to always return `true`. F030's operator-opt-in gating (banner + confirm + toggle state) is bypassed silently — the rogue plugin's higher priority wins even when the operator's toggle is OFF. Requires admin to install the rogue plugin (already game-over territory), but a defense-in-depth signal is cheap.

**Remediation**:
1. **Boot-time detection warning**: on `plugins_loaded` P999999 (immediately after F030 registers), enumerate `$GLOBALS['wp_filter']['wp_register_ability_args']` and log a `_doing_it_wrong()` OR admin-notice if any OTHER filter is registered at priority ≥ 999999. Alerts the admin to investigate.
2. **Document the priority slot map** as a durable memory entry (Proposed B35 in the plan's memory capture section). This alone doesn't prevent the footrace but documents the invariant future features must respect — extends `DEC-F020-TOOL-ENFORCEMENT-PRIORITY` pattern to the ability-args filter.
3. Consider a follow-up feature to consolidate ability-callback-swap patterns behind a `AbilityCallbackFilterRegistry` (analogous to the proposed `McpAdapterGateRegistry` in DEC-F020-TOOL-ENFORCEMENT-PRIORITY). Out of scope for F030.

**Recommendation**: ship option 2 (documentation) in the same PR (via `/speckit-memory-md-capture`). Consider option 1 for a follow-up feature. Note as accepted limitation in the DECISIONS entry.

---

### SEC-030-004 — Trust boundary for `CurrentServerHolder` is correct but under-documented

- **Severity**: INFORMATIONAL
- **Location**: plan.md §Technical Context + §Constitution Check §III
- **OWASP Category**: N/A (documentation gap)
- **CWE**: N/A
- **CVSS v3.1**: 0.0 (Informational)
- **Spec-Kit task**: TASK-SEC-030-004

**Description**: The runtime override closure trusts `CurrentServerHolder::instance()->get_server_id()` as the sole authority for "which server is being served right now". `CurrentServerHolder::capture_from_request()` is populated at `rest_pre_dispatch` P5 by matching the incoming REST route against registered MCP server routes — server-side authoritative, not URL-parameter-derived. This is correct, and the plan correctly notes A17 as the load-bearing constraint. However, the plan does NOT explicitly enumerate the trust boundary in threat-model terms.

**Remediation** (documentation-only):
- Add one paragraph to plan.md §Constitution Check §III: *"Trust boundary: the override closure trusts `CurrentServerHolder::get_server_id()` as authoritative. That holder is populated only inside `rest_pre_dispatch` P5 by matching the incoming request URI against `McpAdapter::instance()->get_servers()`. It is NOT populated from URL parameters, POST body, headers, or client-supplied identifiers. Any bug in `CurrentServerHolder::capture_from_request()` that lets a client control which server_id is returned would defeat F030's per-server scoping; A17 wiring must not regress."*
- Add this to the follow-up ability-manager audit tasks as a regression-test candidate.

---

### SEC-030-005 — `download_url` for the abilities-manager add-on must be HTTPS

- **Severity**: INFORMATIONAL
- **Location**: `admin/Partials/AddonsFilter.php` (per plan Phase 1) — the addon entry for `acrossai-abilities-manager`
- **OWASP Category**: A02:2025 Cryptographic Failures (data-in-transit)
- **CWE**: CWE-311: Missing Encryption of Sensitive Data
- **CVSS v3.1**: 0.0 (Informational — GitHub does not serve HTTP for release ZIPs)
- **Spec-Kit task**: TASK-SEC-030-005

**Description**: The plan's Phase 1 pinning of the abilities-manager addon entry references a GitHub release ZIP URL. GitHub release URLs are HTTPS-only (GitHub redirects HTTP → HTTPS at the edge), but F030 should still explicitly pin `https://` in the entry to guard against future URL changes and to satisfy plugin-directory review guidelines.

**Remediation**:
- Ensure the pinned `download_url` in the addon entry starts with `https://` explicitly (do not rely on GitHub's HTTP → HTTPS redirect during install; `Plugin_Upgrader` may not follow it in all environments).
- Add a task-time invariant to the addon entry constant: `assert( strpos( ADDON_DOWNLOAD_URL, 'https://' ) === 0 )` at boot, or a PHPCS custom rule.

**Recommendation**: pin explicitly; no additional runtime guard needed if the constant is `const` / class-const.

## Confirmed Secure Patterns

The following aspects of the plan were reviewed and confirmed to meet or exceed constitution §III security baseline:

- **Nonce enforcement** — per-server nonce `acrossai_mcp_manager_permission_override_{server_id}` verified via `check_admin_referer()` in the save handler. Matches S1 and constitution §III.
- **Capability enforcement** — `manage_options` verified on the save handler; `install_plugins` + `activate_plugins` gated by main-menu's `AddonsAjaxHandlers` on the promo card. Matches constitution §III.
- **DB query parameterisation** — BerlinDB `Query::update_item()` is parameterised; the `upgrade_to_1_1_2()` ALTER interpolates only a hard-coded identifier (`$wpdb->prefix . 'acrossai_mcp_servers'`), matching D28 reference impl. No user input crosses into raw SQL. Satisfies S4.
- **Output escaping in admin markup** — plan mandates `esc_html__()` for labels, `checked()` for checkbox state, `esc_url()` for the form action, `esc_attr()` for input attributes. Matches S5 + constitution §III.
- **Singleton `__construct()` privacy** — plan mandates `PermissionOverrideProcessor::__construct()` be `private`, preventing duplicate filter registration (matches S6, B5).
- **`$wpdb` tinyint casting** — plan mandates `(int)` cast in Row constructor and `0 === (int) $rows[0]->override_abilities_permission` comparison in the closure, matching B18 mitigation.
- **`rest_url()` trailing-slash handling** — N/A (F030 does not construct sub-path URLs from `rest_url()`).
- **BerlinDB schema drift** — plan follows D28 3-part contract exactly, mitigating B34 silent write-loss.
- **Fail-safe closure fall-through** — closure returns the original `permission_callback`'s result when the server context is null, when the override is off, or when the ability is not exposed. `false` is the fallback when the original was non-callable, matching WP Abilities API deny-by-default semantics.
- **Deliberate `permission_callback` bypass** — accepted as design intent per plan Complexity Tracking table, bounded by 6 defensive layers documented in plan §Constitution Check §III and mirrored in memory-synthesis §Conflict Warnings. Not a §III violation; slated for capture as `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS`.
- **Consent-surface exception** — N/A. This is an admin-only `manage_options` toggle, not a browser-mediated user consent surface.

## Action Plan & Next Steps

1. **Durable Memory Preservation (Mandatory Check)** — Two systemic patterns surfaced worth capturing when `/speckit-memory-md-capture` is next invoked:
   - Confirm `SEC-030-001` remediation as `B36 — Inline <script> string-interpolation requires wp_json_encode(), not esc_html()/esc_attr()` (generalizable bug pattern; not F030-specific).
   - Confirm `SEC-030-003` remediation as an extension of the proposed `B35` priority-slot-map pattern (already tabled in the plan's memory capture proposals).
   - `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS` (already proposed in plan) — no new content added by this review; the 6-layer defensive gating is intact.

2. **Remediation Planning** — No CRITICAL or HIGH findings — `/speckit-security-review-followup` is not required. However, the one MEDIUM finding (SEC-030-001) and two LOW findings (SEC-030-002, SEC-030-003) should each be projected to explicit T### tasks by `/speckit-tasks`:
   - **TASK-SEC-030-001** — Add FR-017a to spec + implement `wp_json_encode()` in the inline `<script>` + add regression-test with 5 hostile server names.
   - **TASK-SEC-030-002** — Add `do_action('acrossai_mcp_permission_override_toggled', ...)` fire in save handler.
   - **TASK-SEC-030-003** — Document priority slot map in the DECISIONS.md capture (via `/speckit-memory-md-capture`) + add boot-time diagnostic (optional follow-up feature).
   - **TASK-SEC-030-004** — Add trust-boundary paragraph to plan.md §Constitution Check §III.
   - **TASK-SEC-030-005** — Pin `https://` explicitly in addon `download_url` constant.

3. **Re-review after implement** — recommend running `/speckit-security-review-staged` before merge to confirm SEC-030-001's `wp_json_encode()` remediation is present in the diff (this is the primary ship-blocker gate).

---

## Memory Hub INDEX.md Row

```text
| docs/security-reviews/2026-07-20-030-per-server-permission-override-plan.md | plan | 2026-07-20 | MODERATE | C:0 H:0 M:1 L:2 I:2 | A02,A03,A08,A09 |
```
