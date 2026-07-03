# Implementation Plan: Adopt `wpboilerplate/wpb-access-control` v2 — per-server access rules + MCP-boundary enforcement (Feature 015)

**Feature Directory**: `specs/015-access-control-v2-adoption/`
**Feature Branch**: `015-access-control-v2-adoption`
**Spec**: `spec.md`
**Memory synthesis**: `memory-synthesis.md`
**Created**: 2026-07-04
**Status**: Draft (pre-implementation)

## Summary

F015 adopts `wpboilerplate/wpb-access-control ^2.0.0` (a live-bug fix — the plugin ships v2 in `composer.json:18` but every call site targets v1's `::instance()` singleton API, which does not exist in v2). It introduces an `AcrossAI_MCP_Access_Control` wrapper class (copy-adapted verbatim from the sibling `acrossai-abilities-manager` plugin's proven pattern), adds activation-time `RuleTable( 'mcp' )->maybe_upgrade()` in `Activator.php`, wires MCP-boundary enforcement via the `mcp_adapter_pre_tool_call` filter shipped by `vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182`, ships a per-server rule UI as `public/Renderers/AccessControlBlock.php` extending F013's `AbstractClientRenderer`, and adds uninstall opt-in gate cleanup for the new namespace + table + version option. Two observability action hooks (`acrossai_mcp_access_control_denied` + `acrossai_mcp_access_control_missing_server`) are fire-and-forget signals for operator logging. No new npm deps, no vendor forks.

## Technical Context

**PHP Version**: 8.1+ (matches Feature 010 baseline)
**WordPress Version**: 6.9+
**Multisite**: Single-site only
**PHP dependencies**: `wpboilerplate/wpb-access-control ^2.0.0` (already pinned in composer.json:18); `wordpress/mcp-adapter ^0.5.0` (provides the `mcp_adapter_pre_tool_call` filter); `berlindb/core ^3.0.0` (F010 baseline, consumed transitively via the vendor's `RuleTable`); `acrossai-co/main-menu` (D15/DEV4 hard-require, unchanged). **No new Composer dependencies.**
**Reused support layers** (do NOT re-implement — synthesis "REUSE" list):
- `\WPBoilerplate\AccessControl\AccessControlManager` + `\...\Database\Rule\{RuleQuery,RuleTable}` + built-in `Wp{Role,User,Capability}Provider` classes (v2 vendor package)
- `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->get_item()` (F011)
- `\AcrossAI_MCP_Manager\Public\Renderers\AbstractClientRenderer` (F013)
- `\AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab` (F013 — for the AccessControlTab delegate shape)
- `\AcrossAI_MCP_Manager\Admin\Partials\Settings::handle_actions()` — extend to handle `save_access_control` action (matches F013 `save_claude_connector` pattern)
- Sibling plugin's `AcrossAI_Abilities_Access_Control` wrapper class at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Modules/Abilities/AcrossAI_Abilities_Access_Control.php` — copy-adapt verbatim

**Structure change scope**: NEW `includes/AccessControl/AcrossAI_MCP_Access_Control.php`, NEW `public/Renderers/AccessControlBlock.php`, MODIFY `admin/Partials/ServerTabs/AccessControlTab.php` (thin-delegate rewrite), MODIFY `includes/REST/CliController.php` (line 333 v1→v2 fix + FR-026 observability hook), MODIFY `includes/Main.php` (Loader-wire 5 hooks, delete dead Phase 7 TODO block), MODIFY `includes/Activator.php` (add RuleTable maybe_upgrade), MODIFY `uninstall.php` (add opt-in-gated purge + drop), MODIFY `admin/Partials/Settings.php` (extend `handle_actions()` for `save_access_control`), NEW `tests/phpunit/Includes/AccessControl/AcrossAI_MCP_Access_Control_Test.php`.

### Concrete decisions locked at spec time (from Clarifications session)

| # | Decision | Source |
|:---|:---|:---|
| Q1 | `SAFE_CAPABILITIES` allow-list is a locked `public const` array + `acrossai_mcp_ac_safe_capabilities` filter for extension + `array_diff` deny-list guard strips `manage_options` / `edit_users` even if filter returns them | Clarifications 2026-07-04 |
| Q2 | On `MCPServerQuery::get_item()` null (race with concurrent DELETE), the `mcp_adapter_pre_tool_call` callback fires `do_action('acrossai_mcp_access_control_missing_server', ...)` for observability + returns `$args` unchanged (fail-open) | Clarifications 2026-07-04 |
| Q3 | Both enforcement sites (CliController `/servers` + MCP filter callback) fire `do_action('acrossai_mcp_access_control_denied', $user_id, $server_slug, $tool_name_or_null, $context_slug)` BEFORE returning WP_Error / empty list — plugin-scoped observability hook layered on the vendor's low-level `wpb_access_control_denied` | Clarifications 2026-07-04 |

## Constitution Check

| Principle | Status | Notes |
|:---|:---|:---|
| **§I Modular Architecture** | ✅ | 1 wrapper class + 1 Renderer block — each self-contained, independently testable. `includes/AccessControl/` is a new module cleanly separated from `includes/OAuth/`, `includes/REST/`, `includes/MCP/`. No shared logic added outside `Utilities/` (constants live on the wrapper as sole owner per A9 single-owner exemption). |
| **§II WPCS + PHPStan L8** | ✅ (gate) | Whole-plugin PHPStan L8 exit 0 required; PHPCS baseline unchanged on modified files. `#[DataProvider]` per B9 in the 7-test file. |
| **§III Security** | ✅ | FR-023 nonce+cap on save (vendor REST); FR-024 cap check via `$context['cap']` never hardcoded; two enforcement sites gate MCP surface with observability hooks; fail-open documented as intentional per sibling DEC-PERM-CB. Uninstall opt-in gate (F012 DEC-UNINSTALL-OPT-IN-GATE) preserved for the new table + option. Per Clarifications Q4 the FR-025 SAFE_CAPABILITIES deny-list guard is withdrawn — admin-bypass hierarchy makes exposing `manage_options`/`edit_users` non-escalatory. |
| **§IV DataForm mandate** | ⚠ carve-out | AccessControlBlock defers to vendor's React `<AccessControl>` component (post Q4 pivot). Not `@wordpress/dataviews` DataForm, and not raw hand-rolled PHP form either — same DEC-CLIENT-RENDERER-PUBLIC-API precedent covers third-party-shipped React embedding. To be reaffirmed in DEC-ACCESS-CONTROL-V2-ADOPTION at TASK-9. |
| **§V Extensibility Without Core Modification** | ✅ | 3 new hooks are the extensibility API: `acrossai_mcp_ac_available_capabilities` filter (site-specific capability additions per Q4), `acrossai_mcp_access_control_denied` action (denial observability), `acrossai_mcp_access_control_missing_server` action (missing-server observability). Third parties + operators never patch plugin code. Third-party providers appended via the vendor's `acrossai_mcp_access_control_providers` filter (F015 registers 3 defaults via `register_default_providers`). |
| **§VI Reusability & DRY** | ✅ (gate) | Wrapper class is a diff-and-namespace-swap of the sibling plugin's `AcrossAI_Abilities_Access_Control` (proves DRY at the plugin-family level). Grep gates: `AccessControlManager::instance` returns 0 hits (proves v1→v2 migration complete); `new AccessControlManager` returns exactly 1 hit (sole owner in the wrapper); `mcp_adapter_pre_tool_call` returns exactly 1 hit in Main.php. |
| **§VII Definition of Done** | ✅ (per-task) | Every TASK-N block has explicit DoD gates. Whole-plugin gate at TASK-9. |

## Project Structure

### Documentation (this feature)

```
specs/015-access-control-v2-adoption/
├── spec.md                         (279 lines, includes 3 Clarifications)
├── plan.md                         (this file)
├── memory-synthesis.md             (897 words)
├── security-constraints.md         (to be created by /speckit-security-review-plan)
├── architecture-violations.md      (to be created by /speckit-architecture-guard-violation-detection)
├── tasks.md                        (to be created by /speckit-tasks)
├── checklists/requirements.md      (created by /speckit-specify)
└── docs/planings-tasks/015-access-control-v2-adoption.md  (source of truth for /speckit-specify)
```

### Source Code (repository root)

```
includes/AccessControl/               NEW — 1 file
  AcrossAI_MCP_Access_Control.php     wrapper class (copy-adapt sibling verbatim); PROVIDERS_FILTER + TABLE_SLUG constants; is_available/boot_manager/get_manager/register_rest_api/maybe_show_library_notice/gate_mcp_tool_call/get_available_capabilities methods; static register_default_providers method

public/Renderers/                     NEW — 1 file
  AccessControlBlock.php              extends AbstractClientRenderer (F013); singleton with private ctor; @experimental docblock; render_body emits a mount `<div id="acrossai-mcp-ac-root">` for the vendor React <AccessControl> component (post Q4 pivot); no plugin-owned form or save handler

admin/Partials/ServerTabs/            MODIFY — 1 file
  AccessControlTab.php                REWRITE render_body() as a thin delegate to AccessControlBlock — matches F013 NpmTab/ClientsTab/ClaudeConnectorTab shape; delete v1-API AccessControlManager::instance() call at line 65

admin/Partials/                       MODIFY — 1 file
  Settings.php                        extend handle_actions() to route `save_access_control` action through nonce + cap verification + wrapper→get_manager()→set_rule/clear_rule calls (matches F013 save_claude_connector pattern)

includes/                             MODIFY — 3 files
  Main.php                            Loader-wire 5 hooks (init boot_manager priority 5, rest_api_init register_rest_api, admin_notices maybe_show_library_notice, mcp_adapter_pre_tool_call gate_mcp_tool_call, PROVIDERS_FILTER register_default_providers); delete Main.php:432 commented v1-API line + line 374-379 empty class_exists TODO block
  REST/CliController.php              line 333 v1→v2 fix — route through AcrossAI_MCP_Access_Control::instance()->get_manager()->user_has_access() + fail-open on is_available()=false + do_action(acrossai_mcp_access_control_denied) BEFORE returning empty server list on deny
  Activator.php                       add `use WPBoilerplate\AccessControl\Database\Rule\RuleTable;` + `use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;` + `(new RuleTable(AcrossAI_MCP_Access_Control::TABLE_SLUG))->maybe_upgrade();` after the 4 existing F011 table calls

uninstall.php                         MODIFY — 1 file
                                      AFTER F012 opt-in gate confirms acrossai_mcp_uninstall_delete_data===1, add class_exists-guarded (new RuleQuery( 'mcp' ))->purge_namespace('acrossai-mcp-manager') + $wpdb->query('DROP TABLE IF EXISTS {prefix}mcp_access_control') + delete_option('wpb_ac_mcp_db_version')

tests/phpunit/Includes/AccessControl/  NEW — 1 file
  AcrossAI_MCP_Access_Control_Test.php  7 tests (is_available true/false, boot_manager construction, gate_mcp_tool_call fail-open × 2 (no-rule + package-missing) + deny WP_Error, register_default_providers returns 3); B9 #[DataProvider] attribute

docs/
  memory/DECISIONS.md                 APPEND — DEC-ACCESS-CONTROL-V2-ADOPTION + D18 (mcp_adapter_pre_tool_call filter lesson from F015)
  memory/INDEX.md                     APPEND — 2 rows (1 DEC + 1 D-row) + amend A8/D8 version-pin references to `^2.0.0`
  planings-tasks/README.md            APPEND — F015 row
  planings-tasks/015-access-control-v2-adoption.md  ALREADY PRESENT (source of truth for /speckit.specify)

README.txt                            MODIFY — Unreleased changelog bullet
```

## Task Groups (Phase 2 preview)

Mirrors the 8-task breakdown in `docs/planings-tasks/015-access-control-v2-adoption.md`:

| Task | Scope | New/Modified files | Gates |
|:---|:---|:---|:---|
| T1 | Scaffold `AcrossAI_MCP_Access_Control` wrapper class | 1 NEW | php-l + PHPStan L8 + PHPCS + sibling-diff proves copy-adapt |
| T2 | Add `RuleTable( 'mcp' )->maybe_upgrade()` in Activator | 1 MODIFY | grep gate #3 (RuleTable maybe_upgrade in Activator.php = 1 hit); fresh-activate creates table |
| T3 | Fix 3 v1-API fatal call sites | 3 MODIFY | grep gate #1 (`AccessControlManager::instance` = 0 hits); PHPStan L8; PHPCS |
| T4 | Ship `AccessControlBlock` (per-server rule UI) | 1 NEW + 1 MODIFY (Settings.php handle_actions extension) | form saves round-trip; rule appears in DB; PHPStan L8 |
| T5 | Register 3 built-in providers via providers filter | 1 MODIFY (T1 stub) | `user_has_access()` resolves correctly against a saved rule |
| T6 | Wire everything in `Main.php::define_public_hooks()` | 1 MODIFY | grep gate #4 (`mcp_adapter_pre_tool_call` in Main.php = 1 hit); all 5 hooks Loader-wired per A1 |
| T7 | Uninstall opt-in gate purges namespace + drops table | 1 MODIFY | grep gate #5 (`purge_namespace` in uninstall.php ≥1 hit); opt-in fires drop; non-opt-in preserves |
| T8 | PHPUnit coverage — 7 tests | 1 NEW | B9 `#[DataProvider]` attribute; all 7 green |
| T9 | Memory hygiene + changelog + docs — DEC-ACCESS-CONTROL-V2-ADOPTION + D18 captures | 4 MODIFY (DECISIONS + INDEX + planings-tasks/README + README.txt) | DEC captured; INDEX rows present; A8/D8 version-pin references amended to `^2.0.0` |

**Total task count**: 9. **Estimated file impact**: 3 new PHP (wrapper + block + test) + 6 modified PHP (Settings.php, Main.php, CliController.php, Activator.php, AccessControlTab.php, uninstall.php) + 4 modified markdown (DECISIONS, INDEX, planings-tasks/README, README.txt). Substantially smaller than F013 (27 net-new + 15 modified).

## Constitution Re-check (post-Phase-1 design)

Re-evaluated after drafting the class shapes above:

- **§I Modular**: still ✅. `includes/AccessControl/` is a new module cleanly separated. `AccessControlBlock` inherits F013's boundary discipline via `AbstractClientRenderer`.
- **§II WPCS + PHPStan L8**: still ✅ (gate). No changes to baseline configuration; no new legacy patterns introduced.
- **§III Security**: still ✅. Added observation: FR-026's `do_action('acrossai_mcp_access_control_denied', ...)` fires BEFORE the WP_Error/empty-list return — reviewer to verify at TASK-3 (CliController) + TASK-6 (Main.php) code review that the hook fires unconditionally on deny, not gated by another guard.
- **§IV DataForm carve-out**: still applies via DEC-CLIENT-RENDERER-PUBLIC-API precedent. Reaffirmation at TASK-9 in DEC-ACCESS-CONTROL-V2-ADOPTION.
- **§V Extensibility**: still ✅. 3 new hooks (`acrossai_mcp_ac_available_capabilities` filter per Q4 + 2 observability actions) + 1 filter registration for providers.
- **§VI DRY**: still ✅ (gate). Wrapper class copy-adapted from sibling — the definitive proof of DRY at the plugin-family level.
- **§VII DoD**: still ✅. Every TASK has explicit DoD.

**Zero HARD violations detected.** Two SOFT items require capture at TASK-9:

1. §IV DataForm carve-out reaffirmation in DEC-ACCESS-CONTROL-V2-ADOPTION (via DEC-CLIENT-RENDERER-PUBLIC-API precedent).
2. D8 + A8 version-pin references need amending from `^1.0` to `^2.0.0` (SOFT conflict flagged in memory-synthesis.md — resolvable at TASK-9 via in-place amendment, not deprecation).

## Complexity Tracking

**Net-new architectural surface** (vs. F013's larger scope):

- 1 new plugin module (`includes/AccessControl/` — first new module since F013's `public/Renderers/`)
- 3 new public API extension points (1 filter + 2 action hooks)
- 1 new custom DB table owned by vendor package (schema controlled by vendor's `RuleTable::get_columns()`)
- 1 new WordPress option (`wpb_ac_mcp_db_version` — set + read by vendor, cleaned up on opt-in uninstall)
- 1 new REST route (`/wpb-ac/v1/mcp/rules/...` — registered by vendor's `register_rest_api()`, not authored by us)

**Justification**: F015 is the smallest meaningful architectural change since F011 — it's a live-bug fix + feature completion. The wrapper class + Block are single-file additions each. The mcp-adapter filter wiring is a single `add_filter()` call. The new observability hooks (Q3) add operator visibility without adding storage. Vendor package handles all the heavy lifting (schema, migrations, provider registry, REST routes) — F015 is thin glue. The plugin-family alignment with the sibling `acrossai-abilities-manager` is deliberate: same wrapper shape means future maintainers can grep across plugins for identical patterns.

**Task count justification**: 9 tasks — matches F013's 9. The extra "spec-time trailing" tasks (memory hygiene, changelog) are constant across features; the implementation core is smaller (T1-T6 = 6 tasks vs F013's T1-T7 = 7 tasks + more sub-tasks per task in F013).

**Risk register**:

- **R1** (LOW): Vendor package's v2 constructor signature changes in a future 2.x release. **Mitigation**: FR-017 grep gate + PHPUnit reflection test at TASK-8 case 3 asserts the constructor arguments. Regression is CI-caught.
- **R2** (LOW): `mcp_adapter_pre_tool_call` filter signature changes in a future mcp-adapter release. **Mitigation**: TASK-8 cases 4/5/6 exercise the filter with the current 4-arg signature. Regression is CI-caught.
- **R3** (LOW): A third-party plugin registers a malicious provider that grants unwanted access. **Mitigation**: SEC-013-008-style silent-skip on invalid provider FQNs already present in the v2 vendor package's `AccessControlManager::load_providers()` — no F015 code needed. Additionally, `manage_options` users bypass all providers per v2 access-hierarchy step 2, so a malicious provider cannot lock admins out of their own site.
- **R4** (DISSOLVED by Clarifications Q4): The original R4 posited that an operator misconfigures the `acrossai_mcp_ac_safe_capabilities` filter to grant `edit_users`, and cited FR-025's `array_diff` deny-list guard as the mitigation. Both the risk and the mitigation are withdrawn — per Q4, the admin-bypass hierarchy makes exposing `manage_options`/`edit_users` in a rule non-escalatory (only admins hold them, and admins are always allowed). The filter is now `acrossai_mcp_ac_available_capabilities` and has no deny-list — a malicious extension can only widen the picker's dropdown, not grant real access to any non-admin.

## Post-Phase Governance Checkpoints

- **After TASK-1**: manual review that the wrapper class is a faithful copy-adapt of the sibling (diff should show only namespace/constant-value/text differences — no design drift).
- **After TASK-6**: manual smoke test on live WP — save a `wp_role=[editor]` rule for server X, POST an MCP tool call as a subscriber, verify WP_Error + observability action fires.
- **After TASK-7**: manual smoke test — plugin uninstall with opt-in drops the table; opt-in without preserves.
- **After TASK-9**: verify `docs/memory/INDEX.md` A8 + D8 rows amended to `^2.0.0` (not deprecated).
