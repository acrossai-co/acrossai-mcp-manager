# Security Review — Plan-Level Constraints (Feature 015)

**Reviewed plan**: `specs/015-access-control-v2-adoption/plan.md`
**Reviewed spec**: `specs/015-access-control-v2-adoption/spec.md` (incl. 3 Clarifications)
**Constitution**: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE)
**Date**: 2026-07-04
**Reviewer**: governed-plan orchestrator (inline `speckit-security-review-plan` fallback)

---

## Scope

Feature 015 is a **live-bug fix + adoption + feature-completion** feature. Security-relevant surfaces:

1. **v1→v2 API migration** — every call site that previously used `AccessControlManager::instance()` (a fatal in v2) is either fixed or deleted. Post-F015 grep gate proves this.
2. **New DB table** owned by vendor package — `{prefix}mcp_access_control` with schema controlled by `RuleTable::get_columns()`. All writes go through `RuleQuery` which uses `$wpdb->prepare()` internally (S4 preserved).
3. **Two enforcement sites** — CliController `/servers` (fix + observability hook) + MCP tool boundary (new via `mcp_adapter_pre_tool_call` filter). Both fail-open when the vendor package is unavailable.
4. **Per-server rule UI** — `AccessControlBlock` extends F013's `AbstractClientRenderer` inheriting cap-check-via-context (SEC-013-005) + `@experimental` docblock convention.
5. **Full WP capability set exposure via vendor React** (Clarifications Q4, supersedes Q1's SAFE_CAPABILITIES design) — the `AccessControlBlock` mounts vendor's React `<AccessControl>` component; the capability picker enumerates every registered role capability (dedup + sort from `wp_roles()->role_objects`) including `manage_options` / `edit_users`. Non-escalatory because admin bypass hierarchy (v2 step 2) makes those capabilities no-ops as rule targets. Third-party extensions append via `acrossai_mcp_ac_available_capabilities` filter — no deny-list.
6. **Two observability action hooks** (Clarifications Q2 + Q3) — `acrossai_mcp_access_control_denied` (fires BEFORE deny return) + `acrossai_mcp_access_control_missing_server` (fires on race with concurrent DELETE). Fire-and-forget.
7. **Preserved F012 invariants** — uninstall opt-in gate MUST also purge the new AC namespace + drop the new table + delete the new version option (FR-012, FR-013).

## Trust Boundaries

| Boundary | Direction | Threat Surface | Defense |
|---|---|---|---|
| Admin browser → AccessControlTab per-server form POST | inbound at HTTP POST | Nonce forge, cap escalation, mass-assignment via forged POST keys | FR-023: `wp_verify_nonce()` against `'acrossai_mcp_manager_server_' . $id` (F013 convention preserved) + `current_user_can( $context['cap'] )` (default `manage_options`) + `sanitize_key()` on role/capability names + `absint()` on user IDs — BEFORE any `RuleQuery::set_rule()` call. |
| REST client → `/wp-json/acrossai-mcp-manager/v1/servers` | inbound at HTTP GET | Enumeration attack — unauthorized client tries to discover MCP server list | FR-006 preserves today's semantics: on deny, HTTP 200 + empty `servers` array (not 403 — matches enumeration-defense pattern). Plus FR-026 fires `do_action('acrossai_mcp_access_control_denied', ...)` so operators can observe the pattern. |
| MCP client → `/mcp-adapter/v1/dispatch` → tool call | inbound at HTTP POST via mcp-adapter | Unauthorized tool invocation — MCP client authenticates but should not access this server's tools | FR-007: `mcp_adapter_pre_tool_call` filter callback resolves `$server->get_server_id()` → `$server_slug` → `user_has_access(get_current_user_id(), 'acrossai-mcp-manager', $server_slug)`. On deny, WP_Error with `array('status'=>403)` + FR-026 observability hook fires. Admin (`manage_options`) always allowed per v2 access-hierarchy step 2. |
| Third-party filter callback → `acrossai_mcp_ac_available_capabilities` (post Q4) | at filter apply time | Malicious extension appends fake capabilities to widen the picker dropdown | Non-issue: rules only grant access to users who ALREADY hold the capability. Adding a fake capability name to the picker cannot grant access to anyone — no user holds a fake capability. `manage_options` / `edit_users` are safe to expose because admins bypass all rules (v2 hierarchy step 2). |
| Third-party filter callback → `acrossai_mcp_access_control_providers` (vendor's) | at filter apply time | Third party appends an invalid provider FQN or a class that doesn't extend `AbstractProvider` | The v2 vendor package's `AccessControlManager::load_providers()` silently skips invalid FQNs (built-in defense, no F015 code needed). Additionally, admin users bypass all providers per v2 access-hierarchy step 2. |
| `MCPServerQuery::get_item()` null response | at filter callback | Race with concurrent DELETE — stale server_id in the mcp-adapter routing layer | Clarifications Q2 + FR-007: fail-open (return `$args` unchanged) + fire `do_action('acrossai_mcp_access_control_missing_server', ...)` for observability. Mcp-adapter's routing layer already rejected unregistered server IDs upstream, so this is race-only. |
| Vendor package absence | at wrapper `is_available()` check | Composer install skipped, mid-upgrade race, or manual `vendor/` removal | All code paths fail-open: tab renders info notice, `/servers` returns full list, `mcp_adapter_pre_tool_call` returns `$args` unchanged. Amber admin_notice fires to `manage_options` users. Matches sibling DEC-PERM-CB. |
| Uninstall opt-in gate | at plugin deletion | Accidental data loss on delete | FR-012 + FR-013: F012's `acrossai_mcp_uninstall_delete_data === 1` opt-in check preserved — DROP TABLE + purge_namespace + delete_option ONLY fire when opt-in confirms. Preserve-by-default is F012 invariant. |

## Authorization Assumptions

F015 introduces:

- **No new REST route owned by F015** — the CliController `/servers` route is modified (line 333 v1→v2 fix + observability hook) but its `permission_callback` is unchanged. The vendor package's `/wpb-ac/v1/mcp/rules/...` REST routes are registered via `$manager->register_rest_api()` — they inherit the vendor's default `manage_options` permission check (no F015 override; if a future feature needs to loosen this, it must file a distinct security review).
- **New cap check pattern on Renderer** — cap check via `$context['cap']` (default `manage_options`) is the extension point BuddyBoss/WooCommerce need to embed the block in non-admin read-only contexts. Admin tab passes `manage_options` unconditionally. Third-party embedders can override, but the `AccessControlBlock` save handler still requires `manage_options` regardless of context (FR-023) — no cap override on mutating actions.
- **NO new capabilities introduced** — the plugin does not register any custom capabilities via `add_cap`.

F015 preserves:

- **F012 uninstall opt-in gate** — DEC-UNINSTALL-OPT-IN-GATE unchanged; F015 adds new destructive operations behind the same gate.
- **F013 SEC-013-005** cap-check-via-context — inherited by `AccessControlBlock` via `AbstractClientRenderer`.
- **F013 SEC-013-002** App Password lockdown — not affected by F015 (no App Password generation added).
- **F011 SEC-001 atomic-CAS** — not affected by F015 (no CliAuthLog writes added).

## Data Isolation & Validation Risks

- **Per-server data isolation** — every rule row's `key` is `$server_slug` from the F011 `MCPServer` row. The unique key `(namespace, key, access_control_value)` on the table prevents duplicate rows for the same (server, provider, value) tuple. Cross-server rule contamination is impossible.
- **User-level data isolation** — `get_current_user_id()` is the sole authority for the user identity at both enforcement sites; no user_id spoofing at the mcp-adapter boundary (standard WP cookie/OAuth). Admin users always allowed per v2 access-hierarchy step 2, so no user can lock themselves out via a self-applied rule.
- **Available-capabilities isolation** (post Q4) — `get_available_capabilities()` runs on the wrapper side (single point of enumeration), so the picker + REST layer + any third-party consumer see the same filter-normalized list. No deny-list guard is needed because admin bypass hierarchy makes high-privilege capabilities non-escalatory as rule targets.
- **Rule write validation** — all provider values sanitized before reaching `RuleQuery::set_rule()`: role names via `sanitize_key()` (allowed chars `a-z0-9_-`), user IDs via `absint()` (positive int cast), capability names via `sanitize_key()`. The vendor's `RuleQuery` uses `$wpdb->prepare()` for the INSERT — no SQL injection surface.
- **JSON injection / mass-assignment** — F015 does not accept JSON POST bodies. All form input is standard WP `<input type="checkbox">` / `<input type="text">` fields sanitized at the boundary. No mass-assignment risk (B7 pattern) because we explicitly enumerate `wp_role[]`, `wp_user[]`, `wp_capability[]` field names in the save handler.

## Async / Concurrency Security Context

- **Concurrent admin edits on the same server's rules** — last-write-wins semantics via the vendor's `RuleQuery::set_rule()` → `INSERT ... ON DUPLICATE KEY UPDATE` pattern (BerlinDB-backed). If two admins edit simultaneously, the later save overwrites the earlier one. Acceptable for this feature — rule edits are administrative, not high-frequency.
- **Race between MCP tool call and rule DELETE** — Clarifications Q2 covers this: `get_item()` null → fail-open + observability hook. Tool call executes (which is safe because mcp-adapter's routing already validated the server_id upstream).
- **Race between plugin activation and vendor package uninstall** — vendor Composer package MUST be present when `Activator::activate()` runs. If someone `composer remove wpboilerplate/wpb-access-control` and then re-activates without re-installing, `RuleTable::maybe_upgrade()` would fatal. Mitigation: `class_exists('\WPBoilerplate\AccessControl\Database\Rule\RuleTable')` guard around the maybe_upgrade call in the Activator (added defense-in-depth per this security review — will be captured in tasks.md T2 DoD refinement).
- **Vendor package's own cache_group timing** — v2 initializes `wpb_ac_mcp` cache group on `init` priority 5 (via `boot_manager` Loader-wire). REST calls firing on `rest_api_init` (priority 10) always find the cache group ready. No timing race.

## Missing Gates / Recommendations

- **RECOMMEND — Add `class_exists` guard around `RuleTable::maybe_upgrade()` in Activator** — defense against vendor-uninstall-then-reactivate. Zero cost, defends against a single operator misstep. **Fold into TASK-2 DoD.**
- **RECOMMEND — Explicit PHPUnit test for `get_available_capabilities()` post Q4** — the security review's original SEC-015-004 recommendation is rewritten because Q4 withdrew the deny-list guard. New test case: `test_get_available_capabilities_returns_full_set_and_supports_filter`. Add as test #9 in the TASK-8 test file — assert the return covers the full role-derived capability set (including `manage_options` / `edit_users`) and that the `acrossai_mcp_ac_available_capabilities` filter honors appended entries without silent stripping.
- **RECOMMEND — Document observability hook signatures in a per-hook docblock** — third-party observability listeners need machine-readable contract (args + fire-timing) for stability. TASK-1 DoD should include: docblock on `gate_mcp_tool_call()` documenting BOTH observability hooks + their signatures. Verified via reviewer at TASK-1 code review.
- **RECOMMEND — Do NOT weaken F015's fail-open pattern into fail-closed later** — a future feature that flips fail-open → fail-closed would silently deny every MCP tool call on a site where the vendor package briefly became unreachable (mid-upgrade, filesystem race). Codify fail-open in DEC-ACCESS-CONTROL-V2-ADOPTION at TASK-9 as an invariant, not a preference.

## Status

**PASS** — no HARD security-architecture conflicts identified. Four RECOMMEND items surface as advisory gates for `/speckit-tasks` to fold into TASK-1 / TASK-2 / TASK-8 / TASK-9 DoD lines.

Overall risk: **LOW**. F015's security posture matches or slightly strengthens the pre-feature baseline:

- **Fixed**: 3 v1-API fatal call sites (was blocking every AccessControl interaction).
- **New**: `mcp_adapter_pre_tool_call` filter callback with fail-open + observability (defense-in-depth on the MCP boundary that was previously ungated).
- **New (post Q4)**: `get_available_capabilities()` enumeration surface — full WP capability set exposed via vendor React picker + `acrossai_mcp_ac_available_capabilities` filter for third-party extensions. Non-escalatory because admin bypass hierarchy neutralizes high-privilege capabilities as rule targets.
- **New**: Two observability action hooks (denial + missing-server) for operator visibility.
- **Preserved**: F012 uninstall opt-in gate; F013 SEC-013-005 cap-check-via-context; F011 SEC-001 atomic-CAS; S1 nonce verification; S2 REST permission_callback pattern; S6 private ctor on singletons.

## Findings

| ID | Severity | OWASP | CWE | CVSS | Description | Related task |
|:---|:---|:---|:---|:---|:---|:---|
| SEC-015-001 | INFO | A05 | CWE-1104 | 0.0 | Vendor package v2 API is stable within `^2.0.0`. If v3 releases, FR-017 grep + PHPUnit TASK-8 case 3 catches signature changes. Not a vulnerability. | TASK-8 |
| SEC-015-002 | LOW | A05 | CWE-20 | 3.7 | Vendor package could be uninstalled between plugin activations. Mitigation: `class_exists` guard around `RuleTable::maybe_upgrade()` in Activator (defense-in-depth). | TASK-2 |
| SEC-015-003 | LOW | A03 | CWE-352 | 3.1 | Concurrent rule edits — last-write-wins. Acceptable for admin-frequency operations; not a vulnerability. | N/A |
| SEC-015-004 | LOW | A01 | CWE-863 | 3.7 | Malicious filter callback appends `manage_options` to safe capabilities. Mitigation: `array_diff` deny-list guard on wrapper side (Clarifications Q1). PHPUnit test asserts strip. | TASK-8 |
| SEC-015-005 | INFO | A09 | CWE-778 | 0.0 | Denial observability requires operator to hook `acrossai_mcp_access_control_denied` — silent by default. Not a vulnerability; documented in FR-026. | TASK-9 |
| SEC-015-006 | INFO | A05 | CWE-778 | 0.0 | Third-party plugins can register providers via `acrossai_mcp_access_control_providers` filter. v2 package silently skips invalid FQNs. Not a vulnerability; documented in vendor code. | N/A |

Overall risk: **LOW** (3 LOW + 3 INFO; zero HIGH/CRITICAL).
