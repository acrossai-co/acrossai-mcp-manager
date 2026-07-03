---
document_type: security-review
review_type: plan
assessment_date: 2026-07-04
codebase_analyzed: acrossai-mcp-manager (Feature 015 planning artifacts)
total_files_analyzed: 6
total_findings: 6
overall_risk: LOW
critical_count: 0
high_count: 0
medium_count: 0
low_count: 3
informational_count: 3
owasp_categories: [A01, A03, A05, A09]
cwe_ids: [CWE-20, CWE-352, CWE-863, CWE-778, CWE-1104]
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

# Security Review — Feature 015 Plan (Access Control v2 Adoption)

## Executive Summary

Feature 015 adopts `wpboilerplate/wpb-access-control ^2.0.0` — fixing a **live crash bug** where the plugin ships v2 in `composer.json:18` but every consumer targets v1's `::instance()` singleton API (which does not exist in v2). Three call sites (`AccessControlTab.php:65`, `CliController.php:333`, `Main.php:432` commented-out block) will fatal as soon as they fire. F015 introduces an `AcrossAI_MCP_Access_Control` wrapper class (copy-adapted verbatim from the sibling `acrossai-abilities-manager` plugin's proven pattern), adds activation-time `RuleTable('mcp_manager')->maybe_upgrade()` in `Activator.php`, wires MCP-boundary enforcement via the `mcp_adapter_pre_tool_call` filter shipped by `vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182`, ships a per-server rule UI as `public/Renderers/AccessControlBlock.php` extending F013's `AbstractClientRenderer`, and adds uninstall opt-in gate cleanup for the new namespace + table + version option.

**Overall risk: LOW.** No CRITICAL, HIGH, or MEDIUM findings. Six findings total (3 LOW + 3 INFO), all pre-authorized as low-cost defense-in-depth refinements folding into TASK-2 / TASK-8 / TASK-9 DoDs. The plan's security posture matches or slightly strengthens the pre-feature baseline: fixes 3 crash-inducing call sites; adds fail-open observability hooks on 2 enforcement sites; preserves F012 uninstall opt-in gate; inherits F013 SEC-013-005 cap-check-via-context pattern; preserves S1/S2/S4/S6 constitution constraints.

## Plan Artifacts Reviewed

| File | Purpose | Read scope |
|---|---|---|
| `specs/015-access-control-v2-adoption/spec.md` | Feature specification (279 lines, 3 Clarifications, 26 FRs, 7 SCs) | Full read |
| `specs/015-access-control-v2-adoption/plan.md` | Implementation plan (constitution check + 9-task preview) | Full read |
| `specs/015-access-control-v2-adoption/memory-synthesis.md` | Memory synthesis (897 words) | Full read |
| `specs/015-access-control-v2-adoption/security-constraints.md` | Plan-level trust boundary + risk analysis (from governed-plan step 4) | Full read |
| `specs/015-access-control-v2-adoption/architecture-violations.md` | Architecture violation detection (from governed-plan step 5) | Full read |
| `docs/planings-tasks/015-access-control-v2-adoption.md` | Source-of-truth planning doc (634 lines, 9 TASK-N breakdowns + CONSTRAINTS) | Full read |
| `docs/memory/INDEX.md` | Memory hub routing map | Selective (D8 + A8 rows + F013 DEC-CLIENT-RENDERER-PUBLIC-API + F012 DEC-UNINSTALL-OPT-IN-GATE) |
| `.specify/memory/constitution.md` | Constitution §III Security-First (NON-NEGOTIABLE) | Selective (§III + §V + §VII gates) |
| `docs/security-reviews/2026-07-03-013-per-server-tabs-refactor-plan.md` | F013 precedent for the review file shape + SEC-013-* invariants inherited | Reference only |

## Vulnerability Findings

### [LOW] SEC-015-001 — Vendor package unavailable at Activator time

**Location:** `includes/Activator.php` (F015 TASK-2 modification target)
**OWASP Category:** A05:2025-Security Misconfiguration
**CWE:** CWE-20: Improper Input Validation
**CVSS Score:** 3.7 (LOW)
**Description:** F015's TASK-2 adds `(new RuleTable('mcp_manager'))->maybe_upgrade()` to `Activator::activate()`. If the vendor `wpb-access-control` package is uninstalled between plugin activations (unusual composer setup, mid-upgrade race, or manual `vendor/` removal) but someone then re-activates the plugin without `composer install`, the `RuleTable` autoload will fail and `Activator::activate()` will fatal — potentially leaving the plugin in an inconsistent activation state.

**Remediation:** Wrap the `RuleTable::maybe_upgrade()` call with `class_exists('\WPBoilerplate\AccessControl\Database\Rule\RuleTable')` guard. Zero cost, defends against a single operator misstep. Matches the plugin's existing pattern of guarding vendor calls with `class_exists()` per D8. The F015 wrapper's own `is_available()` is not sufficient here because Activator runs before `boot_manager()`.

**Spec-Kit Task:** TASK-SEC-015-001 — fold into F015 TASK-2 DoD.

---

### [LOW] SEC-015-002 — Malicious filter callback appends privileged capability

**Location:** `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (F015 TASK-1 new file, `SAFE_CAPABILITIES` + `acrossai_mcp_ac_safe_capabilities` filter)
**OWASP Category:** A01:2025-Broken Access Control
**CWE:** CWE-863: Incorrect Authorization
**CVSS Score:** 3.7 (LOW)
**Description:** Clarifications Q1 introduced the `acrossai_mcp_ac_safe_capabilities` filter to let operators extend the built-in `SAFE_CAPABILITIES` allow-list (e.g., adding `manage_woocommerce` on a WooCommerce site). A malicious or poorly-written filter callback could append `manage_options` or `edit_users` to the returned array. If those capabilities appeared in the AccessControlBlock's picker, an admin could accidentally save a rule that grants persistent admin bypass via the capability provider.

**Remediation:** FR-025 already codifies the fix — `array_diff( $filtered, [ 'manage_options', 'edit_users' ] )` deny-list guard on the wrapper side, run BEFORE the Block reads the filter return. Per this security review's recommendation: add a dedicated PHPUnit test (`test_safe_capabilities_filter_strips_manage_options`) as an 8th test method in TASK-8. Assertion: even when a malicious `add_filter('acrossai_mcp_ac_safe_capabilities', fn($caps) => [...$caps, 'manage_options'])` is registered, the value returned by the wrapper's public getter does NOT contain `manage_options`.

**Spec-Kit Task:** TASK-SEC-015-002 — fold into F015 TASK-8 DoD as the 8th test method.

---

### [LOW] SEC-015-003 — Cross-context nonce replay via `save_access_control` action

**Location:** `admin/Partials/Settings.php::handle_actions()` (F015 TASK-3 + TASK-4 extension)
**OWASP Category:** A03:2025-Injection (CSRF-adjacent)
**CWE:** CWE-352: Cross-Site Request Forgery
**CVSS Score:** 3.1 (LOW)
**Description:** F015's `AccessControlBlock` save handler MUST verify a nonce bound to `'acrossai_mcp_manager_server_' . (int) $server['id']` (FR-023) — matches F013's convention. However, F013's nonce action is shared across `save_general`, `save_claude_connector`, and now `save_access_control`. In theory, an admin who saves the Overview form could have their nonce replayed against a `save_access_control` POST to a different server_id by an XSS-planted script (nonce action name is server-id-scoped but not action-scoped). Practically, exploiting this requires XSS on an admin-authenticated session, which is a higher-severity precondition than the CSRF itself.

**Remediation:** F015 preserves the F013 convention deliberately (matches the DEC-SERVER-TAB-CLASS-HIERARCHY pattern — one nonce action per server, all save actions share it). Not a new vulnerability; documented as accepted risk. Follow-up (deferred beyond F015): if the plugin ever adds a cross-action nonce, F013's `AbstractServerTab::nonce_field()` should gain an `$action_slug` param for per-action binding. Not blocking F015.

**Spec-Kit Task:** N/A — accepted risk, matches F013 baseline. Documented here for future feature reviewers.

---

### [INFO] SEC-015-004 — Vendor v2 API is stable within `^2.0.0` semver range

**Location:** `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (F015 TASK-1)
**OWASP Category:** A05:2025-Security Misconfiguration
**CWE:** CWE-1104: Use of Unmaintained Third Party Components
**CVSS Score:** 0.0 (INFO)
**Description:** F015 uses `new AccessControlManager( $providers_filter, $table_slug )` per the v2 constructor signature. If a future v3 releases with a breaking constructor change, F015's wrapper will fatal. Not a vulnerability today — informational for future maintainers.

**Remediation:** FR-017 grep gate (`new AccessControlManager` returns exactly 1 hit in the wrapper file only) + FR-008 PHPUnit `test_boot_manager_creates_v2_instance_with_correct_slug_and_filter` (uses reflection to assert the constructor arguments) both catch signature drift at CI. Regression is CI-caught before production ships.

**Spec-Kit Task:** N/A — informational; regression-tested at TASK-8 case 3.

---

### [INFO] SEC-015-005 — Denial observability requires operator to hook the action

**Location:** `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (F015 TASK-1, `gate_mcp_tool_call` method) + `includes/REST/CliController.php:333` (F015 TASK-3)
**OWASP Category:** A09:2025-Security Logging and Monitoring Failures
**CWE:** CWE-778: Insufficient Logging
**CVSS Score:** 0.0 (INFO)
**Description:** Clarifications Q3 introduced the `acrossai_mcp_access_control_denied` action hook, fired BEFORE returning WP_Error / empty list on deny. By default, no listener is registered — the hook is fire-and-forget until an operator adds a logger (e.g., via Query Monitor, custom mu-plugin, or a remote SIEM adapter). Sites without any listener will have zero visibility into denials. Not a vulnerability by itself — the WP_Error surface at the MCP boundary already informs the client — but operators upgrading from F014-baseline may not realize they need to add a listener for audit compliance.

**Remediation:** Document the hook's existence + payload signature in the `README.txt` Unreleased changelog bullet at TASK-9. Include a code snippet showing a minimal `error_log`-based listener. Third-party observability adapters (e.g., a future "AcrossAI MCP Audit Logger" plugin) can hook this action without any F015 code change.

**Spec-Kit Task:** TASK-SEC-015-005 — fold into F015 TASK-9 README.txt bullet DoD.

---

### [INFO] SEC-015-006 — v2 package silently skips invalid provider FQNs

**Location:** `includes/AccessControl/AcrossAI_MCP_Access_Control.php::register_default_providers()` + third-party callbacks on `acrossai_mcp_access_control_providers` filter
**OWASP Category:** A05:2025-Security Misconfiguration
**CWE:** CWE-778: Insufficient Logging
**CVSS Score:** 0.0 (INFO)
**Description:** The v2 vendor package's `AccessControlManager::load_providers()` silently skips FQNs that don't `class_exists()` OR don't extend `AbstractProvider`. A third-party plugin that appends an invalid FQN via `add_filter('acrossai_mcp_access_control_providers', ...)` will find its provider missing but no admin notice will fire. Not a vulnerability — it's the correct fail-open behavior for third-party robustness (matches F013 FR-016b silent-skip pattern). Informational for third-party plugin authors reading this review.

**Remediation:** None needed — vendor's built-in silent-skip is the correct behavior. Third-party plugin authors debugging "my custom provider doesn't show up" should verify their class extends `\WPBoilerplate\AccessControl\AbstractProvider` and is autoloadable. No F015 code change.

**Spec-Kit Task:** N/A — informational; vendor-managed behavior.

## Confirmed Secure Patterns

The plan explicitly preserves or introduces these secure patterns:

1. **v1→v2 API migration completeness** — FR-016 grep gate `AccessControlManager::instance` returns 0 hits after TASK-3. Proves the crash bug is fully eliminated with no residual v1-API calls anywhere in the plugin.

2. **Single-source-of-truth for the v2 vendor manager** — FR-017 grep gate `new AccessControlManager` returns exactly 1 hit (only inside `AcrossAI_MCP_Access_Control::boot_manager()`). No other class instantiates the vendor manager directly — enforces §I Modular boundary + prevents accidental multiple providers-filter registration.

3. **Nonce + cap verification on the AccessControlBlock save handler** — FR-023 wires the F013 `'acrossai_mcp_manager_server_' . $id` nonce action + `current_user_can('manage_options')` before writing rules. Matches S1 + DEC-CLIENT-RENDERER-PUBLIC-API convention.

4. **Cap check via `$context['cap']` — never hardcoded** — FR-024 inherits F013 SEC-013-005 pattern. Third-party embedders can override for legitimate read-only viewing contexts (BuddyBoss); admin surfaces always pass `manage_options`.

5. **Deny-list guard on the `SAFE_CAPABILITIES` filter return** — Clarifications Q1 + FR-025's `array_diff($filtered, ['manage_options', 'edit_users'])` prevents privileged capability leakage regardless of filter callback quality (defense-in-depth).

6. **Fail-open on vendor package absence** — spec + plan document fail-open at all 4 code paths: tab renders info notice, `/servers` returns full list, `mcp_adapter_pre_tool_call` returns `$args` unchanged, admin_notices amber warning fires. Matches sibling DEC-PERM-CB pattern. Never fail-closed silently.

7. **F012 uninstall opt-in gate preserved** — FR-012 gates the F015 `purge_namespace` + `DROP TABLE` + `delete_option` behind the existing `acrossai_mcp_uninstall_delete_data === 1` check. Preserve-by-default is invariant. Every new destructive operation lives behind the same gate.

8. **MCP-boundary enforcement without vendor fork** — FR-007 uses the vendor-shipped `mcp_adapter_pre_tool_call` filter, not a fork. §V Extensibility Without Core Modification preserved.

9. **Observability hooks fire BEFORE the deny return** — Clarifications Q3 codified this ordering in FR-006 + FR-007 + FR-026. Ensures denial listeners see the event before the client gets the error response — critical for audit trail integrity.

10. **Per-server data isolation via `(namespace, key, ac_value)` unique key** — the v2 vendor schema's `UNIQUE KEY ns_key_value` prevents cross-server contamination. F015 doesn't override or extend this — inherits the vendor's isolation contract as-is.

11. **`prepare()`-based SQL writes via BerlinDB `RuleQuery`** — the vendor's Query class handles all writes; F015 code never touches `$wpdb` directly for rule I/O. Preserves S4.

12. **Private constructor on singletons** — FR-001 (wrapper) + FR-008 (AccessControlBlock) both use `private function __construct() {}` per S6 + F012 SettingsMenu member ordering.

---

## Action Plan & Next Steps

### 1. Durable Memory Preservation (Mandatory Check)

The security review surfaced **no new reusable security patterns beyond what F015's planning already captured**. The two candidates the governed-plan flow proposed (`DEC-ACCESS-CONTROL-V2-ADOPTION`, `D18`, `D19`) already fold the observability + fail-open patterns into DECISIONS.md at TASK-9. Additionally:

- **SEC-015-002's deny-list guard pattern** could be codified as a family-wide convention ("cap allow-lists are gated by both a positive const AND a deny-list guard on the extension filter") — this generalizes beyond F015 to any future feature adding operator-extensible capability sets. Recommend capturing as a new memory row at F015 TASK-9 (**D20 candidate**), OR deferring to a later feature that also uses the pattern (avoids premature codification).

Per your earlier "defer to TASK-9" preference for memory captures, the deny-list-guard candidate is added to the TASK-9 memory capture list but requires no immediate action.

### 2. Remediation Planning

- **No CRITICAL or HIGH findings** — `/speckit.security-review.followup` is NOT required. All 3 LOW findings and 3 INFO findings are addressable via low-cost DoD refinements folded into existing tasks:
  - **SEC-015-001** → TASK-2 DoD (`class_exists` guard around `RuleTable::maybe_upgrade`)
  - **SEC-015-002** → TASK-8 DoD (8th test method for deny-list guard)
  - **SEC-015-003** → accepted risk (matches F013 baseline; documented here for future review awareness)
  - **SEC-015-004** → covered by FR-017 grep gate + TASK-8 case 3 (informational only)
  - **SEC-015-005** → TASK-9 README.txt bullet (document the observability hook + provide a minimal listener snippet)
  - **SEC-015-006** → no action needed (vendor behavior; informational for third-party plugin authors)

### 3. Recommended Next Command

```
/speckit-tasks
```

Task generation should fold the SEC-015-* recommendations into their respective TASK DoD lines (SEC-015-001 → TASK-2, SEC-015-002 → TASK-8, SEC-015-005 → TASK-9). The plan is architecture-clean, security-clean, and memory-synced — no blockers to task generation.

---

## Memory Hub INDEX.md Row

Paste the following row into `docs/memory/INDEX.md` under the `## Security Reviews` section:

```text
| docs/security-reviews/2026-07-04-015-access-control-v2-adoption-plan.md | plan | 2026-07-04 | LOW | C:0 H:0 M:0 L:3 | A01,A03,A05,A09 |
```

This addition can land alongside the F015 TASK-9 memory captures (per the "defer to TASK-9" preference), or immediately if you want the security review discoverable to future planning cycles before implementation completes.
