# Memory Synthesis

## Current Scope

F015 adopts `wpboilerplate/wpb-access-control ^2.0.0`: fixes 3 fatal v1-API call sites (`AccessControlTab.php:65`, `CliController.php:333`, `Main.php:432` commented block); introduces `AcrossAI_MCP_Access_Control` wrapper class under `includes/AccessControl/`; adds `RuleTable( 'mcp' )->maybe_upgrade()` in `Activator.php`; ships per-server rule UI as `public/Renderers/AccessControlBlock.php` extending F013's `AbstractClientRenderer` (thin-delegate tab preserved per D8) that mounts the vendor's React `<AccessControl>` component (post Clarifications Q4 pivot); wires `mcp_adapter_pre_tool_call` filter in `Main.php::define_public_hooks()` for MCP-boundary enforcement; gates purge/DROP behind F012 opt-in in `uninstall.php`; captures 4 clarifications (Q1 SAFE_CAPABILITIES deny-list guard [later superseded by Q4], Q2 missing-server fail-open, Q3 denial observability hook, Q4 full WP capability set exposure via vendor React). Affected modules: `admin/Partials/ServerTabs/AccessControlTab.php`, `includes/AccessControl/*` (new), `includes/REST/CliController.php`, `includes/Main.php`, `includes/Activator.php`, `public/Renderers/AccessControlBlock.php` (new), `uninstall.php`, plus `admin/Main.php`, `src/js/access-control.js`, and `webpack.config.js` for the vendor React mount pipeline.

## Relevant Decisions

- **D8** — AccessControl stub targets wpb-access-control vendor package FQN (Reason Included: F015 IS the v1→v2 API upgrade of the pattern D8 established; the class_exists guard convention preserved verbatim, but the version pin `^1.0` is superseded — see Conflict Warnings. Status: Active — will be superseded by DEC-ACCESS-CONTROL-V2-ADOPTION at TASK-9. Source: DECISIONS.md).
- **D17** — A1 hook-registration by transitivity (Reason Included: F015 wires 5 hooks via Loader in `Main.php::define_public_hooks()` — the `mcp_adapter_pre_tool_call` filter, providers filter, boot_manager/register_rest_api/maybe_show_library_notice actions. All Loader-wired at the outer boundary; A1 satisfied directly (no transitivity needed). Status: Active (F013). Source: DECISIONS.md).
- **DEC-VENDOR-SETTINGS-TAB-INTEGRATION** — canonical shape for consuming shared vendor packages (Reason Included: F015 follows the sibling `acrossai-abilities-manager` plugin's `AcrossAI_Abilities_Access_Control` wrapper class pattern verbatim — same-family vendor-integration precedent applies. Status: Active F012. Source: DECISIONS.md).
- **DEC-UNINSTALL-OPT-IN-GATE** — `uninstall.php` MUST short-circuit unless `acrossai_mcp_uninstall_delete_data === 1` (Reason Included: FR-012 + FR-013 gate F015's `purge_namespace('acrossai-mcp-manager')` + `DROP TABLE mcp_access_control` + `delete_option('wpb_ac_mcp_db_version')` behind this exact check. Preserve-by-default is invariant. Status: Active F012. Source: DECISIONS.md).
- **DEC-CLIENT-RENDERER-PUBLIC-API** — public Renderer layer with 4 sanctioned extension points + §IV DataForm carve-out (Reason Included: F015's `AccessControlBlock` extends `AbstractClientRenderer`, matching NpmClientBlock/MCPClientsBlock/ClaudeConnectorBlock shape; inherits `@experimental` docblock convention + cap-check-via-context.cap pattern + the §IV carve-out. Status: Active F013. Source: DECISIONS.md).

## Active Architecture Constraints

- **A1** — All hook registration lives in `Main.php::define_admin_hooks()` / `define_public_hooks()` (Reason Included: F015 adds 5 new Loader-wired hooks. FR-014 gates this. Source: ARCHITECTURE.md).
- **A2** — Singleton `instance()` pattern (Reason Included: `AcrossAI_MCP_Access_Control` + `AccessControlBlock` are both singletons matching F012 SettingsMenu member ordering per FR-001. Source: ARCHITECTURE.md).
- **A6** — Leading-`\` FQN or `use` import when referencing sub-namespace classes (Reason Included: F015 imports `use WPBoilerplate\AccessControl\AccessControlManager;` in the wrapper, `use WPBoilerplate\AccessControl\Database\Rule\RuleTable;` in Activator. Bare relative names silently fail. Source: ARCHITECTURE.md).
- **A8** — AccessControl wiring MUST use `\WPBoilerplate\AccessControl\AccessControlManager` vendor package (Reason Included: F015 IS the v2 adoption. The base rule holds; the version pin needs bump — see Conflict Warnings. Source: ARCHITECTURE.md).
- **A9** — Shared admin constants in `includes/Utilities/` (Reason Included: `PROVIDERS_FILTER` + `TABLE_SLUG` constants live on `AcrossAI_MCP_Access_Control` because it's the sole owner + consumer. SAFE_CAPABILITIES constant was withdrawn per Q4. A9 exempts single-owner constants; documented in TASK-1 DoD. Source: ARCHITECTURE.md).

## Accepted Deviations

- **§IV DataForm carve-out** (via DEC-CLIENT-RENDERER-PUBLIC-API) — F015's `AccessControlBlock` emits WP-core form HTML (`<input type="checkbox">` + `submit_button()`), NOT `@wordpress/dataviews` DataForm. Same precedent that F013 Renderer layer established; per-server admin surfaces on `?page=acrossai_mcp_manager` are exempt from §IV. Status: Accepted-Deviation.

## Relevant Security Constraints

- **S1** — All forms + AJAX endpoints verify nonce (Reason Included: FR-023 — AccessControlBlock save handler verifies `wp_verify_nonce()` against the F013 `acrossai_mcp_manager_server_<id>` action name before writing rules. Source: security-constraints.md → CONSTITUTION.md §III).
- **S2** — REST routes have explicit `permission_callback` (Reason Included: CliController `/servers` route retains its existing explicit callback (unchanged by F015). Vendor package's `/wpb-ac/v1/mcp/rules/...` routes inherit vendor's `manage_options` default — no override in F015. Source: CONSTITUTION.md §III).
- **S6** — Private `__construct()` on singletons (Reason Included: `AcrossAI_MCP_Access_Control` + `AccessControlBlock` both have private ctor per FR-001 + FR-008. Source: PROJECT_CONTEXT.md).

## Related Historical Lessons

- **B9** — PHPUnit `#[DataProvider]` attribute, not `@dataProvider` (Reason Included: F015 test file at TASK-8 uses attribute form for all 7 test methods. Source: BUGS.md).
- **B15** — Grep gates must handle leading-`\` FQN + short-name aliased forms (Reason Included: FR-016 grep `AccessControlManager::instance` must catch `\WPBoilerplate\AccessControl\AccessControlManager::instance()` (leading-`\` FQN) AND `AccessControlManager::instance()` (short-name via `use`). Two-pass grep or ERE alternation required. Source: BUGS.md).
- **B16** — Mixed `%s` + `%1$s` placeholders silently mislabel (Reason Included: F015 CONSTRAINTS document forbids mixed printf styles; wrapper's `maybe_show_library_notice()` + Block's form-field labels use ONE style consistently. Source: BUGS.md).
- **F011 phantom-version guard** (Worklog 2026-07-02) — BerlinDB Table's `maybe_upgrade()` short-circuits if version option stamped but physical table missing (Reason Included: F015 consumes vendor's `RuleTable( 'mcp' )->maybe_upgrade()`. Vendor's package handles this internally at v2 per code read; if a future v3 regresses, this lesson applies. Source: WORKLOG.md).

## Conflict Warnings

- **SOFT — D8 + A8 version pin drift**: Both memory entries reference `wpb-access-control ^1.0`. F015 upgrades to `^2.0.0`. The base convention (use the vendor package + preserve `class_exists` guard) still holds; only the version pin changes. Resolution: at TASK-9, capture `DEC-ACCESS-CONTROL-V2-ADOPTION` which explicitly supersedes D8's version pin and updates A8's version reference. Neither becomes deprecated (the convention is unchanged); both get amended in-place with an "as of F015: v2.0.0" note.
- **No HARD conflicts** identified. F015 does not violate any Constitution MUST, security constraint, or still-valid decision.

## Retrieval Notes

- Considered 22 index entries; selected 18 within budget (5 decisions, 5 architecture, 1 deviation, 3 security, 3 bugs, 1 worklog).
- Not selected as F015-load-bearing: DEC-SERVER-TAB-CLASS-HIERARCHY (consumed not extended), DEC-BERLINDB-TABLE-REQUEST-BOOT (handled internally by vendor), D14–D16, A4 (superseded by §IV carve-out for F015), A7/A10–A15, DEV1/DEV3, S3–S5/S7–S9.
- Sibling-plugin `DEC-PERM-CB` (fail-open on package absent) referenced but not indexed here — F015 mirrors it at FR-006/FR-007/FR-009. Codify as plugin-family convention in TASK-9 memory captures if approved.
- Source read: INDEX.md + selected DECs only; `full_memory_read_allowed: false` (optimizer off).
