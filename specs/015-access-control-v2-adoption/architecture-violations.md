# Architecture Violation Detection (Feature 015)

**Reviewed plan**: `specs/015-access-control-v2-adoption/plan.md`
**Constitution**: `.specify/memory/constitution.md` §I-§VII
**Memory synthesis**: `specs/015-access-control-v2-adoption/memory-synthesis.md`
**Date**: 2026-07-04
**Reviewer**: governed-plan orchestrator (inline architecture-guard violation detection)

---

## Scope

F015 is a **live-bug fix + focused feature-completion** architectural change:

- 1 new plugin module (`includes/AccessControl/` — new module boundary)
- 1 new Renderer block (`public/Renderers/AccessControlBlock.php` — inherits F013 DEC-CLIENT-RENDERER-PUBLIC-API)
- 1 modified tab class (`AccessControlTab.php` → thin delegate)
- 3 modified core files (`Main.php`, `CliController.php`, `Activator.php`)
- 1 modified admin dispatcher (`Settings.php::handle_actions()` extended for `save_access_control`)
- 1 modified uninstall (`uninstall.php` — opt-in gate purge/drop)
- 1 new PHPUnit test file (`AcrossAI_MCP_Access_Control_Test.php` — 7 tests, 8 with SEC-015-004 recommendation)

Scope of this review: verify each new class + hook wiring against §I-§VII of the constitution + all 15 A-* architecture constraints + F011/F012/F013 DECs.

## Violations

| ID | Category | Severity | Location(s) | Summary | Evidence/Rationale |
|:---|:---|:---|:---|:---|:---|
| — | — | — | — | **Zero HARD violations detected.** | See boundary verification below. |

## Boundary Verification

| Boundary | Expected (per Constitution + Memory) | Delivered by F015 plan | Verdict |
|:---|:---|:---|:---|
| **§I Modular Architecture** | Each feature module self-contained, independently testable, shared logic in `includes/Utilities/` | 1 wrapper class + 1 Block — self-contained. Wrapper class is single-source-of-truth for AC-related constants (single-owner exemption). Test coverage at TASK-8 exercises class in isolation via mock. No cross-module coupling to `includes/OAuth/`, `includes/MCP/`, etc. | ✅ |
| **§II WPCS + PHPStan L8** | Zero errors plugin-wide | Plan explicit gates at TASK-1..TASK-9 DoD | ✅ (gate) |
| **§III Security** | Nonce + cap + prepare() + escape at rendering | FR-023 (nonce+cap; vendor REST owns saves post Q4), FR-024 (cap via context.cap). Vendor package handles `prepare()` internally. FR-026 observability hooks fire BEFORE deny return. FR-025's SAFE_CAPABILITIES deny-list guard was withdrawn per Q4 — admin-bypass hierarchy makes exposing high-privilege capabilities non-escalatory. See `security-constraints.md` SEC-015-001..006 (all LOW/INFO). | ✅ |
| **§IV DataForm / DataViews mandate** | New admin forms use DataForm | AccessControlBlock defers to vendor's React `<AccessControl>` component (post Q4 pivot) — neither `@wordpress/dataviews` DataForm nor raw hand-rolled PHP form. Same DEC-CLIENT-RENDERER-PUBLIC-API precedent as F013. To be reaffirmed at TASK-9 in DEC-ACCESS-CONTROL-V2-ADOPTION. | ⚠ SOFT (carve-out, precedent exists) |
| **§V Extensibility Without Core Modification** | Third-party extensibility via WP hooks | 3 new hooks (`acrossai_mcp_ac_available_capabilities` filter per Q4 + 2 observability actions) + vendor's providers filter. Third parties never patch plugin code. | ✅ |
| **§VI Reusability & DRY** | No code duplication | Wrapper class is a copy-adapt of sibling plugin's `AcrossAI_Abilities_Access_Control` — diff-and-namespace-swap proves DRY at plugin-family level. FR-016 grep gate (`AccessControlManager::instance` = 0 hits) + FR-017 grep gate (`new AccessControlManager` = 1 hit only in wrapper) enforce single-source-of-truth. | ✅ (gate) |
| **§VII Definition of Done** | Per-task gates | Every TASK-N block has explicit DoD in the planning doc | ✅ |
| **A1 — Hooks in `Main.php` only** | All `add_action`/`add_filter` in `define_admin_hooks()`/`define_public_hooks()` | Plan T6: `Main.php::define_public_hooks()` wires all 5 F015 hooks via Loader — `boot_manager` on init, `register_rest_api` on rest_api_init, `maybe_show_library_notice` on admin_notices, `gate_mcp_tool_call` on `mcp_adapter_pre_tool_call`, `register_default_providers` on the providers filter. Zero `add_action`/`add_filter` in class bodies. | ✅ |
| **A2 — Singleton `instance()` pattern** | Every feature class is a singleton | Wrapper + Block are both singletons matching F012 SettingsMenu member ordering (`protected static $instance = null;` → `public static function instance(): self` → `private function __construct() {}`). | ✅ |
| **A3 — All admin UI in `admin/Partials/`** | Admin classes namespaced under `AcrossAI_MCP_Manager\Admin\Partials` | AccessControlTab remains in `admin/Partials/ServerTabs/` (F013). AccessControlBlock lives in `public/Renderers/` — same F013 pattern where admin tab is a thin delegate to a public/-namespaced Renderer (cross-context reuse per DEC-CLIENT-RENDERER-PUBLIC-API). Save handler stays in `admin/Partials/Settings.php`. | ✅ |
| **A4 — DataForm / DataViews mandate** | New forms use DataForm | AccessControlBlock uses WP-core form HTML — see §IV carve-out row. | ⚠ SOFT (carve-out via DEC-CLIENT-RENDERER-PUBLIC-API) |
| **A5 — MCP server listing via `wpb-mcp-servers-list`** | Not affected by F015 | F015 does not touch server listing paths | ✅ (unchanged) |
| **A6 — Leading-`\` FQN in Includes namespace** | Cross-namespace references use `use` or leading-`\` FQN | Plan T1 wrapper: `use WPBoilerplate\AccessControl\AccessControlManager;` at top. Plan T2 Activator: `use WPBoilerplate\AccessControl\Database\Rule\RuleTable;`. Plan T6 Main.php: fully-qualified `\AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance()`. All bare-relative-namespace risks (B1) avoided. | ✅ |
| **A7 — Constants in Main.php only** | Plugin-wide constants defined in `Main::define_constants()` | F015 constants (`PROVIDERS_FILTER`, `TABLE_SLUG`) live on the wrapper class — these are wrapper-scoped, NOT plugin-wide. (SAFE_CAPABILITIES constant was withdrawn per Q4.) A7 governs `ACROSSAI_MCP_MANAGER_*` global-namespace `define()` calls; class constants are exempt. | ✅ (correct scope) |
| **A8 — AccessControl via vendor package** | `\WPBoilerplate\AccessControl\AccessControlManager` vendor package usage | F015 IS the v2 adoption — the base rule holds. The version pin needs bumping from `^1.0` to `^2.0.0` (SOFT conflict; resolved at TASK-9 by amending A8 + D8 rows in-place, not deprecation). | ⚠ SOFT (version-pin amendment) |
| **A9 — Shared constants in `Utilities/`** | Constants read by ≥2 modules live in `includes/Utilities/` | F015 wrapper constants (`PROVIDERS_FILTER`, `TABLE_SLUG`) are consumed by ≥2 files (Activator + Main + AccessControlBlock + uninstall). (SAFE_CAPABILITIES was withdrawn per Q4.) Per plan §I Modular row, the wrapper is the SOLE consumer of these constants (Activator/Main/uninstall reference `AcrossAI_MCP_Access_Control::CONSTANT`, not the raw value). Single-owner → constants live on the owner class per A9's single-owner exemption. Documented in TASK-1 DoD. | ✅ (single-owner exemption) |
| **A10 — WP_List_Table singleton exemption** | Not affected by F015 | F015 introduces no `WP_List_Table` classes | ✅ (unchanged) |
| **A11 — Pure service class exemption** | Not applicable | Wrapper has state (`$manager` property); AccessControlBlock has singleton state | ✅ (correct singleton path) |
| **A12 — Pure-PHP module test-harness** | Not applicable | F015 tests use WP-PHPUnit bootstrap (mocks `class_exists()` for is_available true/false paths) — the wrapper is intentionally WP-coupled (it consumes `wp_admin_notice`, `class_exists()`, WP-side `get_current_user_id()`) | ✅ (not a WP-independent module) |
| **A13 — RFC-prescribed carve-out** | Not applicable | F015 doesn't render an RFC-prescribed form | ✅ |
| **A14 — WP-CLI stateless exemption** | Not applicable | F015 introduces no WP-CLI commands | ✅ |
| **A15 — Recorder static helper exemption** | Not applicable | F015 introduces no audit-recorder statics | ✅ |
| **DEV1 — WP_List_Table exception** | Not applicable | F015 introduces no ListTable | ✅ |
| **DEV4 — Vendor bootstrap in plugin entry file** | Not affected by F015 | F015 doesn't touch `acrossai-mcp-manager.php` bootstrap | ✅ (unchanged) |
| **DEC-BERLINDB-TABLE-REQUEST-BOOT (F011)** | BerlinDB Tables booted before Query use | Plan T6 wires `boot_manager` on `init` priority 5 — vendor's `RuleQuery` instantiated inside AccessControlManager on `init`. Well before any REST/AJAX request timing (F011 request-time boot). | ✅ |
| **DEC-VENDOR-SETTINGS-TAB-INTEGRATION (F012)** | Same-family shared vendor-package integration | F015 follows the sibling `acrossai-abilities-manager` wrapper pattern (proven by DEC-VENDOR-SETTINGS-TAB-INTEGRATION at F012). Copy-adapt approach codified. | ✅ (precedent-conforming) |
| **DEC-UNINSTALL-OPT-IN-GATE (F012)** | Preserve-by-default; destructive SQL behind opt-in | Plan T7 gates F015's `purge_namespace` + `DROP TABLE` + `delete_option` behind the SAME opt-in check. New destructive operations align with the F012 invariant. | ✅ (LOAD-BEARING) |
| **DEC-CLIENT-RENDERER-PUBLIC-API (F013)** | Renderer layer extends AbstractClientRenderer; cap via context.cap; @experimental | Plan T4: AccessControlBlock extends AbstractClientRenderer. FR-024: cap check via `$context['cap']`. FR-008: `@since 0.0.7 @experimental May change without notice before 1.0.0`. Every principle from DEC-CLIENT-RENDERER-PUBLIC-API preserved. | ✅ (LOAD-BEARING) |
| **B9 — PHPUnit `#[DataProvider]` attribute** | Tests use PHP attribute, not `@dataProvider` annotation | Plan T8 test docblock cites B9; all 7 (or 8 with SEC-015-004) test methods use `#[DataProvider]` where applicable | ✅ |
| **B15 — Grep gates handle FQN + short-name forms** | Regex accounts for both spellings | Plan T3 grep gate `AccessControlManager::instance` matches both `\WPBoilerplate\AccessControl\AccessControlManager::instance()` (leading-`\` FQN) AND `AccessControlManager::instance()` (short-name via `use`). Grep string is short enough that regex escaping is not needed; test-run at TASK-3 DoD verifies zero hits. | ✅ |
| **B16 — Mixed placeholders forbidden** | printf uses one placeholder style consistently | Plan CONSTRAINTS forbids mixed; wrapper's `maybe_show_library_notice()` + Block's form field labels + observability hook `WP_Error` message all use ONE style. | ✅ (gate at TASK-1 + TASK-4 review) |

## Cross-Cutting Analysis

### Intent Divergence

- Spec says F015 = "adopt v2 + fix 3 fatals + build UI + wire enforcement." Plan matches. Zero intent divergence.

### Hallucinated Abstractions

- `AcrossAI_MCP_Access_Control` wrapper: has concrete implementation target (TASK-1).
- `AccessControlBlock`: has concrete implementation target (TASK-4).
- Test class: has concrete implementation target (TASK-8).
- `mcp_adapter_pre_tool_call` filter callback: verified to exist in `vendor/wordpress/mcp-adapter/includes/Handlers/ToolsHandler.php:182` (Explore-agent research, cited in memory-synthesis).
- No abstraction is "mentioned but missing implementation task."

### Boundary Erosion

- Wrapper class is the ONLY owner of the v2 `AccessControlManager` instance in the plugin. FR-017 grep gate (`new AccessControlManager` = 1 hit only) enforces this. No other class instantiates the vendor manager directly.
- `AccessControlTab.php` is a thin delegate (matches F013 pattern). FR-022 grep gate (form/nonce/rule-HTML in tab body = 0 hits) enforces this.
- Save handler for `save_access_control` lives in `Settings.php::handle_actions()` (matches F013 `save_claude_connector` pattern). No parallel save path.

### Tight Coupling

- Wrapper depends on `WPBoilerplate\AccessControl\*` (vendor package — expected). Loose fit via `class_exists()` guard on unavailable path.
- Wrapper depends on `MCPServerQuery::instance()->get_item()` (F011 — for the `mcp_adapter_pre_tool_call` callback's server-slug resolution). Loose fit via defensive fail-open on `get_item()` null (Clarifications Q2 + FR-007).
- AccessControlBlock depends on wrapper. AccessControlTab depends on AccessControlBlock. Uninstall depends on wrapper's `TABLE_SLUG` constant. All one-way dependencies. No cycles.

### Contract Mismatch

- Wrapper's public API (`is_available` / `boot_manager` / `get_manager` / `register_rest_api` / `maybe_show_library_notice` / `gate_mcp_tool_call` + static `register_default_providers`) — no `@experimental` needed because this is an internal API not exposed to third parties.
- AccessControlBlock's public API (via F013's `AbstractClientRenderer::render()`) — inherits `@experimental` from DEC-CLIENT-RENDERER-PUBLIC-API.
- 3 new hooks (`acrossai_mcp_ac_available_capabilities` filter per Q4 + 2 observability actions) — `@experimental` docblock convention preserved (matches F013 shortcode/filter API).
- No stability contract mismatch since consumers are informed of the experimental status.

### Constitution Breach

- **Zero HARD constitution breaches.** §IV DataForm mandate has a SOFT deviation (carve-out) documented via DEC-CLIENT-RENDERER-PUBLIC-API precedent + planned reaffirmation at TASK-9.

## Refactor Tasks Generated

**None.** Zero HARD violations means the refactor generator has no drift to convert into tasks. The three SOFT items are already handled in-plan:

- SOFT #1 → §IV DataForm carve-out reaffirmation at TASK-9 in DEC-ACCESS-CONTROL-V2-ADOPTION (planning doc already specifies this).
- SOFT #2 → A8 + D8 version-pin references amended in-place at TASK-9 (planning doc already specifies this — INDEX.md row edit).
- SOFT #3 → SEC-015-002 `class_exists` guard around `RuleTable::maybe_upgrade()` in Activator — fold into TASK-2 DoD (planning doc mentions activation-time table setup; the guard is a defense-in-depth refinement raised in security review).

## Task Synchronization

- **Status**: Synced. All 9 tasks in the planning doc map cleanly to Constitution + memory constraints + spec FRs.
- **Missing implementations**: None — every task-referenced file has a concrete plan section.
- **Pending tasks**: All 9 tasks are pre-defined; `tasks.md` generation at `/speckit-tasks` will convert task groups → atomic implementation tasks with per-task DoD refinement per SEC-015-002 + SEC-015-004 recommendations.

## Metrics

- **Constitution compliance**: 100% (7/7 principles; §IV carve-out precedent-authorized via DEC-CLIENT-RENDERER-PUBLIC-API).
- **Boundary integrity**: **Strong** — wrapper / AccessControlBlock / AccessControlTab boundaries cleanly separated with FR-016..FR-022 grep gates.
- **Architectural risk**: **LOW** — smallest architectural change since F011; single new module bounded by explicit grep gates and PHPUnit invariants.
- **Security-architecture conflicts detected**: **Zero**.
- **A-* architecture constraints checked**: 15 (A1-A15), plus 4 DEV entries, plus 4 relevant DECs.

## Recommendations

1. **Continue** to `/speckit-tasks` — the plan is architecture-clean.
2. **At TASK-2 code review**: verify SEC-015-002 recommendation applied (`class_exists('\WPBoilerplate\AccessControl\Database\Rule\RuleTable')` guard around `maybe_upgrade` call).
3. **At TASK-8 code review**: verify SEC-015-004 recommendation applied per Q4 rewrite (9th test method `test_get_available_capabilities_returns_full_set_and_supports_filter`).
4. **At TASK-9**: verify DEC-ACCESS-CONTROL-V2-ADOPTION includes the §IV DataForm carve-out reaffirmation AND the fail-open invariant (per SEC review's fourth recommendation).
5. **At TASK-9**: verify A8 + D8 rows in `docs/memory/INDEX.md` are amended in-place from `^1.0` to `^2.0.0` (not deprecated, not superseded — the base rule is unchanged, only the version pin advances).
