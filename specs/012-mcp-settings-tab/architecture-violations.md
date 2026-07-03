# Architecture Guard — Violation Detection Report

**Reviewed plan**: `specs/012-mcp-settings-tab/plan.md`
**Reviewed spec**: `specs/012-mcp-settings-tab/spec.md`
**Constitution**: `.specify/memory/constitution.md`
**Memory synthesis**: `specs/012-mcp-settings-tab/memory-synthesis.md`
**Security review**: `specs/012-mcp-settings-tab/security-constraints.md`
**Date**: 2026-07-03
**Reviewer**: governed-plan orchestrator (architecture-guard inline pass)

---

## Scope

Framework-agnostic detection of architectural drift between the proposed plan and the project's constitutional principles + durable memory (A1–A15, S1–S9, B1–B15, D1–D15 + DEC-BERLINDB-* + DEC-VENDOR-SETTINGS-TAB-INTEGRATION-pending, DEV1–DEV4).

Feature 012 has three coordinated changes: MCP tab addition, `uninstall.php` rewrite, CLI Auth Log admin surface removal. Each change interacts with different constraints; this report checks each family.

---

## Constitutional Principles Check

| Principle | Plan Compliance | Evidence |
|---|---|---|
| **I. Modular Architecture** | ✅ | `SettingsMenu` is a self-contained singleton in `admin/Partials/`; no cross-module reach; no candidates for `includes/Utilities/` promotion this feature |
| **II. WordPress Standards** | ✅ | PHPCS + PHPStan L8 gates preserved per task (§VII); PHP 8.1+ unchanged; no deprecated fns |
| **III. Security First** | ✅ | Nonce/capability inherited from vendor `PageRenderer`; `$wpdb->prepare()` on `uninstall.php` LIKE-sweep; sanitize callbacks on all 3 options; SEC-001 atomic-CAS preserved via DB-layer non-deletion |
| **IV. User-Centric Design (DataForm)** | ⚠ **ACCEPTED DEV CARVE-OUT** | MCP tab renders via vendor `PageRenderer` (WP Settings API), not DataForm. Justified because the shared page is vendor-owned; DataForm mandate applies to per-plugin admin surfaces. Formally captured as `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` in TASK-7 per spec FR-029. Consumer plugins must migrate WITH the vendor if `PageRenderer` ever changes shape. |
| **V. Extensibility Without Core Modification** | ✅ | `acrossai_settings_tabs` filter is the vendor-defined extension point; no core mod; `class_exists` guard omission on the vendor class is JUSTIFIED (hard require via composer.json + Feature 010 FR-030 P1 pre-activation guard) — not a §V violation. Documented in the plan §Constitution Check + DEC entry note |
| **VI. Reusability & DRY** | ✅ | `AdminPageSlugs::SETTINGS_TAB` const bridges `SettingsMenu::TAB_SLUG` (both retained per FR-016); no candidate for cross-feature promotion |
| **VII. Definition of Done** | ✅ | Spec §Success Criteria enumerates 7 DoD gates + 8 pre-DoD checks; plan §Task Groups binds each task to its gate |

---

## Architecture Constraints (A1–A15) Check

| Constraint | Status | Notes |
|---|---|---|
| **A1** — Hooks via Loader only | ✅ **LOAD-BEARING** | FR-013 mandates zero `add_action`/`add_filter` in SettingsMenu class body; FR-014 wires filter + action in `Main::define_admin_hooks()` via Loader |
| **A2** — Singleton + private ctor | ✅ **LOAD-BEARING** | SettingsMenu declares `protected static $instance = null; public static function instance(): self; private function __construct() {}` per spec TASK-1 code snippet |
| **A3** — Admin partials in `admin/Partials/` | ✅ | SettingsMenu.php lives at `admin/Partials/SettingsMenu.php` with namespace `AcrossAI_MCP_Manager\Admin\Partials` |
| **A4** — DataForm/DataViews | ⚠ **ACCEPTED DEV CARVE-OUT** | See Principle IV above. Captured via DEC-VENDOR-SETTINGS-TAB-INTEGRATION |
| **A5** — MCP server listing via `wpb-mcp-servers-list` | ✅ N/A | Not touched by this feature |
| **A6** — `use` imports / leading-`\` FQN | ✅ **LOAD-BEARING** | SettingsMenu references `\AcrossAI_Main_Menu\SettingsPage` (leading `\`) + `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth` (leading `\`); Main.php uses full FQN `\AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu::instance()`. Prevents B1 double-namespace silent failure. |
| **A7** — Plugin constants in `Main::define_constants()` | ✅ | No new plugin-wide constants; `SETTINGS_TAB` is a per-class const on `AdminPageSlugs`, not a plugin-wide `define()` |
| **A8** — AccessControl via vendor pkg | ✅ **LOAD-BEARING** | FR-025 preserves `Menu.php` position 4 (`class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` conditional) unchanged. Only position 3 (CLI Auth Log) is deleted. |
| **A9** — Canonical whitelist additive-only | ⚠ **SOFT — FIRST SUBTRACTIVE EDIT** | FR-017 EXTENDS whitelist with new screen ID (additive, standard A9). FR-018 + FR-026 SHRINK whitelist by 2 entries for a page slug that no longer exists — this is the FIRST subtractive edit against A9's "additive-only" wording. Formally captured as `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` in TASK-7. Rationale: A9's SPIRIT is "the whitelist accurately reflects the live surface" — deleting entries for a deleted surface preserves that spirit. Future subtractive edits allowed ONLY when the corresponding page is removed in the same feature. NOT a hard conflict. |
| **A10** — `WP_List_Table` singleton exemption | ⚠ **SOFT — SCOPE NARROWING** | Feature 012 deletes `admin/Partials/CliAuthLogListTable.php` (a WP_List_Table under DEV1's original scope). DEV1 exemption narrows: only `MCPServerListTable.php` remains. Feature 011's T032 DEV1 non-widening gate (post-B15 fix at `grep -cE 'extends\s+\\?WP_List_Table'`) still applies to the remaining file. NOT a violation of A10 itself — the remaining WP_List_Table user is still exempt. |
| **A11** — Pure service class singleton exemption | ✅ N/A | SettingsMenu holds hook-target methods (register_tab, register_settings, sanitize_uninstall_flag, 6 render methods) → stateful → singleton required, A11 exemption does NOT apply |
| **A12** — Pure-PHP modules with WP-free bootstrap | ✅ N/A | This feature depends on WP admin API |
| **A13** — RFC-prescribed forms exempted from A4 | ✅ N/A | No RFC-prescribed forms |
| **A14** — WP-CLI singleton exemption | ✅ N/A | Not a WP-CLI class |
| **A15** — Database-namespace static helpers | ✅ N/A | Not a Database-namespace class |

---

## Active Decisions (D1–D15 + Feature-011 DEC-*) Check

| Decision | Status | Impact on Plan |
|---|---|---|
| **D6** — Activator uses `use` imports for DB class references | ✅ | Main.php Loader-wiring lines use full FQN (`\AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu`) instead of `use ... as SettingsMenu;` — both satisfy D6's spirit (no bare relative names) |
| **D4** — `class_exists()` guards are silent no-op | ⚠ **SCOPE NARROWING (inverse of Feature 011 narrowing)** | FR-013 DELIBERATELY OMITS `class_exists('\AcrossAI_Main_Menu\SettingsPage')` around the `tab_page_slug()` call. Contrast with Feature 011 FR-016 which DELIBERATELY OMITS `class_exists(XxxTable::class)` around the four Table calls in Activator. Both omissions have the SAME rationale (adding a guard would mask a real regression since the class is guaranteed present). D4's rule (guards are silent no-op) NARROWS further under Feature 012 — now also inapplicable to vendor package classes with hard-require premise. Captured as a note in DEC-VENDOR-SETTINGS-TAB-INTEGRATION. |
| **D15** — Shared package bootstrap on `plugins_loaded` P0 + P1 pre-activation vendor guard | ✅ **LOAD-BEARING** | This feature is a CONSUMER of the same shared package that D15/DEV4 bootstraps. The vendor package's guaranteed-present premise (composer hard require + P1 pre-activation guard) is what makes FR-013's unconditional call safe. |
| **DEC-BERLINDB-TABLE-REQUEST-BOOT (F011)** | ✅ **LOAD-BEARING** | Main::load_hooks() already calls `bootstrap_database_tables()` for BerlinDB registration. New SettingsMenu wiring lands AFTER that call — same load_hooks() lifecycle. Explicitly ordered in plan §Task Groups T2 gate line. |
| **DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION (F011)** | ✅ N/A | SettingsMenu doesn't extend a BerlinDB Kern class — the collision pattern doesn't apply |

---

## Bug Patterns (B1–B15) Guard Check

| Pattern | Status | Guard |
|---|---|---|
| **B1** — Namespace double-Includes silent failure | ✅ **LOAD-BEARING** | SettingsMenu.php uses leading-`\` FQN for `\AcrossAI_Main_Menu\SettingsPage` and `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth`. Main.php uses full FQN for the SettingsMenu reference. Prevents `AcrossAI_MCP_Manager\Admin\Partials\AcrossAI_Main_Menu\SettingsPage` drift. |
| **B7** — Mass-assignment via forged POST keys | ✅ N/A | This feature routes user input through WP Settings API (`options.php` handoff), not directly to `$wpdb->update`/`insert`. Sanitize callbacks (`rest_sanitize_boolean`, `sanitize_uninstall_flag`) coerce values. No `add_item($_POST)` shortcuts. |
| **B14** — `register_activation_hook` P10 fatals before P1 guards | ✅ | Not directly touched; Feature 010 established the P1 pre-guard which this feature relies on for the vendor-package guarantee |
| **B15** — Regex verification gates need FQN + short-name coverage | ✅ **LOAD-BEARING** | Spec's Second pre-flight grep (CLI Auth Log removal) uses ERE pattern `acrossai_mcp_manager_cli_auth_log|CliAuthLogListTable|CLI_AUTH_LOG|render_cli_auth_log_page` — covers page slug (string literal), class name (short-form), constant name (short-form), method name (short-form). Companion DB-layer grep uses ERE pattern with `\\?` optional-backslash + `use .*CliAuthLog\\` short-name variant. B15 pattern applied correctly. |
| **B10** — Atomic-CAS check-then-act | ✅ **PRESERVED** | Spec FR-028 mandates verbatim preservation of `CliAuthLog\Query::redeem_atomic()` (Feature 011 FR-006 predicate: `WHERE id = %d AND completed_at IS NULL`). Zero edits touch this method. Companion grep proves file preservation. |

Other B-patterns (B2, B3, B4, B5, B6, B8, B9, B11, B12, B13) do not surface in this feature's scope.

---

## Accepted Deviations (DEV1–DEV4) Check

| Deviation | Status | Notes |
|---|---|---|
| **DEV1** — MCP Manager parent menu `WP_List_Table` exception | ⚠ **SOFT SCOPE NARROWING** | Feature 012 deletes `CliAuthLogListTable.php` (one of two files under DEV1's original scope). Remaining WP_List_Table user: `MCPServerListTable.php` (under DEV1 exemption preserved). NOT a widening. NOT a violation of A10. Captured in DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG. |
| **DEV2** — `includes/Compat.php` boot-time placement | ✅ N/A | Not touched |
| **DEV3** — Bidirectional `FrontendAuth` ↔ `CliController` import (Feature 007) | ✅ N/A | Not touched |
| **DEV4** — FR-030 P1 pre-activation vendor guard (Feature 010, D15 family) | ✅ **PRESERVED + LEVERAGED** | This feature LEVERAGES DEV4 as the safety premise for FR-013's unconditional `SettingsPage::tab_page_slug()` call. Without DEV4, the plan would need a `class_exists` guard. |

---

## Security Constraints (S1–S9) Check

| Constraint | Status | Notes |
|---|---|---|
| **S1** — Nonce on forms/AJAX | ✅ | Inherited from vendor `PageRenderer::render()` call to `settings_fields('acrossai-settings')` — emits `_wpnonce` + `_wp_http_referer`; `options.php` handoff validates |
| **S2** — REST `permission_callback` explicit | ✅ N/A | No REST route added; existing routes unchanged |
| **S3** — OAuth tokens SHA-256 hashed | ✅ **PRESERVED** | DB-layer preservation (FR-028) keeps Feature 011's `char(64)` columns intact. Zero schema changes |
| **S4** — `$wpdb->prepare()` mandatory | ✅ **LOAD-BEARING** | `uninstall.php` LIKE-sweep uses `$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", 'acrossai_mcp_%' )` per FR-020/FR-022. `DROP TABLE` loop has scoped `phpcs:ignore` because `$table` is derived from `$wpdb->prefix` + hardcoded strings, no user input |
| **S5** — `admin_url()` → `esc_url()` | ✅ | FR-012 requires most-specific escape at render point; render methods use `esc_url()` on any URL output (e.g., FrontendAuth URL, three OAuth URLs) |
| **S6** — Singleton `__construct()` private | ✅ **LOAD-BEARING** | SettingsMenu `private function __construct() {}` per plan §Concrete decisions + spec TASK-1 code snippet |
| **S7** — OAuth token endpoint `__return_true` exception | ✅ N/A | Not touched |
| **S8** — Body-authenticated CLI device-code-grant exception | ✅ N/A | Not touched |
| **S9** — Consent-surface displayed-state from server-authoritative store | ✅ N/A | The MCP tab is admin-only, not a consent surface. §III consent-surface exception (Feature 007 amendment) does not apply. Standard `manage_options` gate is correct. |

---

## Security-Architecture Conflicts

None detected. The security review's three RECOMMEND items (PHPUnit assertion on uninstall preserve-by-default; reviewer callout for DROP TABLE `phpcs:ignore` scope; companion DB-layer grep as merge-gate) are architectural code-review gates, not conflicts. All three land at T4/T5/T6 DoD lines cleanly.

---

## Drift & Consistency Risks

- **NONE HARD** — plan is internally consistent with spec + memory-synthesis + constitution.
- **SOFT — A9 first subtractive edit** — the whitelist has always been documented as "additive-only". Feature 012 introduces the first justified subtractive edit. `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` (planned for TASK-7) codifies the rule going forward: subtractive edits allowed ONLY when the corresponding submenu page is removed in the same feature. This normalization prevents future subtractive drift.
- **SOFT — DEV1 scope narrowing** — captured via same DEC entry.
- **SOFT — D4 scope narrowing (vendor-package variant)** — Feature 011 already narrowed D4 for Table subclasses; Feature 012 further narrows for vendor package classes with hard-require premise. `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` captures the pattern.

---

## Recommendations for `/speckit-tasks`

1. Preserve the T1..T7 task-group structure from plan.md §Task Groups. Task ordering matches the spec's TASK-1..TASK-7 breakdown.
2. **T2 DoD MUST include**: Loader-wiring order verification — the 3 new SettingsMenu Loader lines land AFTER `bootstrap_database_tables()` in `load_hooks()` per DEC-BERLINDB-TABLE-REQUEST-BOOT compatibility.
3. **T5 DoD MUST include**: two smoke tests per spec — (a) preserve-by-default (checkbox unchecked → uninstall → tables + options preserved); (b) destructive opt-in (checkbox checked → uninstall → tables + options gone). Security review's RECOMMEND #1 optionally adds a PHPUnit assertion for the preserve path.
4. **T6 DoD MUST include**: removal grep zero AND companion DB-layer grep unchanged (both from spec + security review RECOMMEND #3).
5. **T7 MUST update INDEX.md AND DECISIONS.md AND WORKLOG.md in the same commit** with THREE new decision entries (DEC-VENDOR-SETTINGS-TAB-INTEGRATION, DEC-UNINSTALL-OPT-IN-GATE, DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG) plus a Feature 012 WORKLOG milestone. INDEX rows MUST match DECISIONS statuses.
6. Every task's DoD MUST include the constitution §VII per-task gate (PHPCS + PHPStan L8 green before the next task starts).

---

## Status

**PASS** — no HARD architectural conflicts. Plan is ready for `/speckit-tasks`.
