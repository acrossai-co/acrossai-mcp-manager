# Memory Synthesis

## Current Scope

Feature 012 adds a new `SettingsMenu` class at `admin/Partials/SettingsMenu.php` that registers an "MCP" tab on the shared `?page=acrossai-settings` page owned by the `acrossai-co/main-menu` vendor package. Three toggles (`acrossai_mcp_npm_login_enabled`, `acrossai_mcp_claude_connectors_enabled`, `acrossai_mcp_uninstall_delete_data`) persist under the shared `'acrossai-settings'` option group. Rewrites `uninstall.php` behind an opt-in gate (preserve-by-default). Removes the standalone CLI Auth Log admin submenu (`admin/Partials/CliAuthLogListTable.php` deleted; `render_cli_auth_log_page` deleted from `Settings.php`; `AdminPageSlugs::CLI_AUTH_LOG` + 2 whitelist entries deleted) while preserving the entire `includes/Database/CliAuthLog/**` runtime layer that OAuth still consumes.

## Relevant Decisions

- **D6 — Activator MUST use `use` imports for DB class references** (Reason Included: `Main.php` gains a new `SettingsMenu::instance()` reference — namespace resolution follows the same rule; leading-`\` FQN or `use` import required; Status: Active; Source: DECISIONS.md D6)
- **D15 — Shared package bootstrap on `plugins_loaded` P0 + P1 pre-activation vendor guard** (Reason Included: This feature is a CONSUMER of the same `acrossai-co/main-menu` package that D15/DEV4 bootstraps; the vendor package's guaranteed-present premise underlies FR-013's unconditional `SettingsPage::tab_page_slug()` call; Status: Active; Source: DECISIONS.md D15)
- **DEC-BERLINDB-TABLE-REQUEST-BOOT (Active, F011)** — (Reason Included: `Main::load_hooks()` where SettingsMenu will be wired ALREADY calls `bootstrap_database_tables()` for BerlinDB registration; new SettingsMenu wiring must land AFTER that call to preserve order — same load_hooks() lifecycle; Status: Active; Source: DECISIONS.md)
- **D4 — `class_exists()` guards in Activator are silent no-op** (Reason Included: FR-013's DELIBERATE OMISSION of a `class_exists('\AcrossAI_Main_Menu\SettingsPage')` guard around the `tab_page_slug()` call inverts D4's rationale — here the guard would be dead code because the vendor is a hard require; documented departure with re-evaluation trigger; Status: Active-scope-narrowed; Source: DECISIONS.md D4)
- **DEC-ADMIN-SURFACE-CONSTITUTION-§IV-DataForm** — (Reason Included: The MCP tab renders via the vendor's `PageRenderer` (WP Settings API), not DataForm. The spec's Admin UI Requirements section documents this as an accepted DEV carve-out because the shared page is owned by the vendor package. Formal capture happens IN this feature via `FR-029 DEC-VENDOR-SETTINGS-TAB-INTEGRATION`; Status: pending Feature-012 memory hygiene; Source: this feature's spec.md)

## Active Architecture Constraints

- **A1 — All hook registration lives in `includes/Main.php` via the Loader** (Reason Included: FR-013/FR-014 mandate that `SettingsMenu` contain zero `add_action`/`add_filter` calls in its class body — Main.php's `define_admin_hooks()` wires the filter + action; Source: ARCHITECTURE.md A1)
- **A2 — Every feature class uses singleton `instance()` pattern** (Reason Included: `SettingsMenu` is a stateful class registering hooks; A11/A15 pure-service exemption does NOT apply because it holds hook-target methods; must be singleton with private ctor; Source: ARCHITECTURE.md A2)
- **A6 — Classes in `AcrossAI_MCP_Manager\...` MUST use `use` imports or leading-`\` FQN for sub-namespace references** (Reason Included: `SettingsMenu` references `\AcrossAI_Main_Menu\SettingsPage` (vendor namespace) + `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth` (own sub-namespace) — both must use leading-`\` FQN to avoid B1 double-namespace silent failure; Source: ARCHITECTURE.md A6)
- **A8 — Access Control via `wpb-access-control` vendor package** (Reason Included: `Menu.php` position-3 (CLI Auth Log) is being DELETED but position-4 (Access Control, conditional on `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')`) MUST be preserved; FR-025 explicitly calls this out; Source: ARCHITECTURE.md A8)
- **A9 — `AdminPageSlugs::plugin_screen_ids()` canonical whitelist — additive-only** (Reason Included: FR-017 EXTENDS the whitelist with `'acrossai_page_acrossai-settings'`; FR-026/FR-018 explicitly SHRINK by removing 2 CLI_AUTH_LOG entries — this feature is the FIRST authorized subtractive edit; requires careful justification in DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG; Source: ARCHITECTURE.md A9)

## Accepted Deviations

- **DEV1 — MCP Manager parent menu `WP_List_Table` exception** (Reason Included: Feature 012 DELETES `admin/Partials/CliAuthLogListTable.php` — a WP_List_Table under DEV1's original scope. DEV1's exemption NARROWS after this feature: only `MCPServerListTable.php` remains under the exception. Feature 011's T032 DEV1 non-widening gate (post-B15 fix) still applies to the remaining file; Status: Accepted-Deviation, scope narrowing captured in DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG)
- **DEV4 — FR-030 P1 pre-activation vendor guard** (Reason Included: DEV4's guard is exactly what makes FR-013's unconditional `SettingsPage::tab_page_slug()` call safe — the vendor package is guaranteed present at `admin_init` because activation would `wp_die` without it; Status: Accepted-Deviation)

## Relevant Security Constraints

- **S4 — All DB queries MUST use `$wpdb->prepare()`** (Reason Included: FR-020 rewrites `uninstall.php` with a LIKE-sweep on `wp_options` using `$wpdb->prepare()`; the `DROP TABLE IF EXISTS` loop is the sole exception, ignore-scoped to that loop only because `$table` is derived from `$wpdb->prefix` + hardcoded strings; Source: CONSTITUTION.md §III)
- **S6 — Singleton `__construct()` MUST be private** (Reason Included: `SettingsMenu` singleton needs private ctor; Source: PROJECT_CONTEXT.md S6)
- **§III Consent-surface exception (Feature-007 amendment)** — (Reason Included: The MCP tab is an admin-only settings surface for `manage_options` users; the consent-surface exception applies only to browser-mediated user-on-own-behalf credential-issuing surfaces (CLI device-grant, OAuth authorization). NOT applicable here; standard `manage_options` gate is correct — the vendor's own PageRenderer handles the capability check upstream. Source: CONSTITUTION.md §III amendment)

## Related Historical Lessons

- **B1 — Namespace double-Includes silent failure** (Reason Included: SettingsMenu.php + Main.php modifications both reference cross-namespace classes; every reference MUST use leading-`\` FQN or `use` import; a bare `SettingsPage::tab_page_slug()` inside the `AcrossAI_MCP_Manager\Admin\Partials` namespace would silently fail because PHP would look for `AcrossAI_MCP_Manager\Admin\Partials\SettingsPage`)
- **B15 — Regex verification gates need FQN + short-name coverage** (Reason Included: The pre-flight greps in this spec use ERE patterns for the option-key sweep + CLI Auth Log symbol sweep + CliAuthLog DB-layer companion. Any narrowing to bare-name-form-only would silently pass while missing FQN/aliased forms — apply B15's `\\?` optional-backslash idiom on `extends`/`use`/`new` patterns where applicable)

## Conflict Warnings

- **SOFT — A9 canonical whitelist subtractive edit is a first**: Feature 012's FR-026 removes 2 entries from `AdminPageSlugs::plugin_screen_ids()`. A9's original wording is "additive-only extensions". This is the first justified subtractive edit — the removal targets a page slug that no longer exists. DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG (created in TASK-7) codifies the rule for future subtractive edits: allowed ONLY when the corresponding submenu page is removed in the same feature. NOT a hard conflict — A9's spirit is preserved (the whitelist accurately reflects the live surface).
- **SOFT — DEV1 scope narrowing**: This feature deletes one of two WP_List_Table files under DEV1. Not a widening (§IV DataForm mandate is not exercised) but a narrowing — DEV1's surface shrinks. Captured via DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG memory entry per FR-029.
- **RESOLVED — §IV DataForm mandate for the shared page**: The MCP tab renders via the vendor `PageRenderer` (WP Settings API). Not a violation because the shared page is vendor-owned; the DataForm mandate applies to per-plugin admin surfaces, not to vendor-consumed shared surfaces. Captured via DEC-VENDOR-SETTINGS-TAB-INTEGRATION memory entry per FR-029.

No HARD conflicts. Constitution + Active Decisions + Active Constraints all support the spec as written.

## Retrieval Notes

- Config: `.specify/extensions/memory-md/config.yml` — optimizer disabled, markdown-only.
- Files read this run: `INDEX.md` (already in context from prior sessions — 15 D + 15 A + 4 DEV + 9 S + 15 B rows). Constitution + spec.md already in context. No source-section reads from durable files needed — INDEX row summaries + prior-session recall covered every entry.
- Index entries considered: ~20 across D/A/DEV/S/B categories.
- Selected within budget: 5 decisions + 5 architecture constraints + 2 deviations + 3 security constraints + 2 bug patterns (well under budget).
- Phase: Plan phase — retrieval prioritized boundary definitions (A1, A2, A6, A9), consumer-of-vendor-package integration risks (D15/DEV4), and admin-surface subtractive-edit rationale (A9 narrowing, DEV1 narrowing).
- Budget: within `max_synthesis_words: 900`.
