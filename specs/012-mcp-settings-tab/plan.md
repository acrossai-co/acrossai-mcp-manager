# Implementation Plan: MCP Settings Tab + CLI Auth Log Admin Page Removal (Feature 012)

**Branch**: `012-mcp-settings-tab` | **Date**: 2026-07-03 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/012-mcp-settings-tab/spec.md`
**Memory synthesis**: [memory-synthesis.md](./memory-synthesis.md) — 5 decisions, 5 architecture constraints, 2 deviations, 3 security constraints, 2 bug patterns selected within budget.
**Governance mode**: `speckit-architecture-guard-governed-plan` inline execution (`/speckit.plan` not registered; plan generated inline per the skill's documented fallback).

---

## Summary

Feature 012 delivers two coordinated changes in a single feature branch:

**Addition** — a new `SettingsMenu` class at `admin/Partials/SettingsMenu.php` registers an "MCP" tab (priority 20) on the shared `?page=acrossai-settings` page owned by the `acrossai-co/main-menu` vendor package. Three toggles persist under the shared `'acrossai-settings'` option group via the WordPress Settings API. Sibling `acrossai-abilities-manager/admin/Partials/SettingsMenu.php:1-221` is the canonical shape reference — class structure, member ordering, and Settings API call idiom match verbatim. Divergences (per-section render callbacks for the two operator-warning banners; the third "Uninstall Settings" section) are justified against the target UI and captured in the plan.

**Removal** — the standalone "CLI Auth Log" admin submenu at `?page=acrossai_mcp_manager_cli_auth_log` is retired. Four files receive coordinated edits: `admin/Partials/CliAuthLogListTable.php` (deleted entirely), `admin/Partials/Menu.php` (`add_submenu_page` block deleted), `includes/Utilities/AdminPageSlugs.php` (constant + 2 whitelist entries deleted), `admin/Partials/Settings.php` (render method + `use` import deleted). Every file under `includes/Database/CliAuthLog/**` is preserved verbatim — the OAuth token exchange (`redeem_atomic` SEC-001 atomic-CAS from Feature 011 FR-006) still consumes the DB layer.

**Behavior change (uninstall)** — the pre-Feature-012 `uninstall.php` unconditionally drops the two OAuth tables + their `db_version` options + the OAuth cleanup cron. Feature 012 migrates that behavior behind the new `acrossai_mcp_uninstall_delete_data` opt-in flag (default 0). The new default is preserve-everything; sites that expected the old wipe must tick the checkbox before uninstalling. Disclosed in the Unreleased changelog (FR-023) + captured as `DEC-UNINSTALL-OPT-IN-GATE` in memory hygiene (TASK-7).

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.1+ (unchanged from Feature 010); JavaScript untouched |
| Primary dependencies | `acrossai-co/main-menu 0.0.10+` (already installed via Feature 010; no composer changes) |
| Storage | 3 new options in `wp_options` under `'acrossai-settings'` option group: `acrossai_mcp_npm_login_enabled` (boolean, default false), `acrossai_mcp_claude_connectors_enabled` (boolean, default false), `acrossai_mcp_uninstall_delete_data` (int 0/1, default 0). No new tables. |
| Testing | New: `tests/phpunit/Admin/SettingsMenuTest.php` (3 test methods per FR-032 checklist / spec TASK-4). Every existing test suite in `tests/phpunit/{Database,OAuth,RestCli,MCPClients,Frontend}` continues to pass. No JS to lint. |
| Target platform | WordPress 6.9+; single-site only |
| Project type | WordPress plugin — admin-surface addition + subtractive edit + uninstall rewrite |
| Performance goals | No measurable regression. Settings page render is server-side WP admin; SettingsMenu adds one filter callback + one `admin_init` handler with 3 `register_setting()` + 3 `add_settings_section()` + 3 `add_settings_field()` calls (constant-time). |
| Constraints | Constitution §II (PHPStan L8, PHPCS zero-warning), §III (S4 `$wpdb->prepare` in uninstall.php LIKE-sweep, S6 private singleton ctor), §IV (DataForm carve-out for vendor-owned page — captured as DEC-VENDOR-SETTINGS-TAB-INTEGRATION), §VII per-task DoD gating; memory-synthesis A1, A2, A6, A8, A9; DEV1 (scope-narrowing captured), DEV4 (hard-require premise); B1 (leading-`\` FQN), B15 (grep gate hygiene); zero edits under `vendor/` or `includes/Database/CliAuthLog/**` |
| Scale / scope | 6 files touched: 1 new (`SettingsMenu.php`) + 5 edited (`Menu.php`, `AdminPageSlugs.php`, `Settings.php`, `Main.php`, `uninstall.php`) + 1 new test (`SettingsMenuTest.php`) + 5 memory/doc files. Estimated 400-500 LOC net (SettingsMenu.php ~250 LOC + test ~60 LOC + rewrite of uninstall.php ~60 LOC + delta edits ~30 LOC). |

### Concrete decisions from planning doc (locked in the spec)

- **Tab slug**: `'mcp'` (matches `AdminPageSlugs::SETTINGS_TAB` const, FR-016).
- **Tab label**: `__( 'MCP', 'acrossai-mcp-manager' )` (FR-001).
- **Tab priority**: `20` — sorts AFTER sibling Abilities tab (priority 10), per spec preserved contract map + FR-003.
- **Option group**: `'acrossai-settings'` (shared with sibling; matches vendor's `PageRenderer::render()` call to `settings_fields()`, FR-004).
- **Per-tab page slug**: `SettingsPage::tab_page_slug( 'mcp' )` → `'acrossai-settings-mcp'` (FR-006).
- **Section IDs**: `acrossai_mcp_npm_section`, `acrossai_mcp_claude_connectors_section`, `acrossai_mcp_uninstall_section` (FR-007).
- **Uninstall option default**: `0` (preserve-by-default per FR-020 + WP.org guideline 5).
- **Text domain**: `'acrossai-mcp-manager'` (Constitution §II + FR-032).
- **No `class_exists( '\AcrossAI_Main_Menu\SettingsPage' )` guard in `register_settings()`** — vendor is a hard require (D15/DEV4/B14 defense chain from Feature 010).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Requirement | This feature |
|---|---|---|
| I. Modular Architecture | Each feature self-contained; shared logic in `includes/Utilities/` | ✅ `SettingsMenu` is a stateful singleton in `admin/Partials/`; no cross-module reach; `AdminPageSlugs` const bridges to the sibling class |
| II. WordPress Standards | PHPCS zero-warning, PHPStan L8, escape at render, sanitize at boundary | ✅ FR-012 mandates escape idioms; FR-004..005 use `rest_sanitize_boolean` + custom `sanitize_uninstall_flag`; §VII per-task gate applied at every DoD |
| III. Security First (NON-NEGOTIABLE) | Nonce, capability, `$wpdb->prepare`, SHA-256 tokens, no `__return_true` on mutating routes | ✅ Vendor's `PageRenderer` handles nonce via `settings_fields()`; `manage_options` gate is standard WP admin; `uninstall.php` LIKE-sweep uses `$wpdb->prepare()`; no new REST/token surface |
| IV. User-Centric Design | DataForm/DataViews for new admin UI; DEV1 exception for MCP Manager parent menu | ✅ **Accepted DEV carve-out**: MCP tab renders via vendor `PageRenderer` (WP Settings API), not DataForm. Captured as `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` per FR-029. The vendor owns page rendering; DataForm mandate applies to per-plugin admin surfaces, not vendor-consumed shared surfaces. DEV1 narrows (CliAuthLogListTable deleted); MCPServerListTable remains under exception. |
| V. Extensibility Without Core Modification | Hooks/extension points only; degrade if optional integration absent | ✅ `acrossai_settings_tabs` filter is the vendor-defined extension point; no core mod. The `class_exists` guard omission is JUSTIFIED because the vendor is a hard require (see spec CONSTRAINTS block + memory-synthesis DEV4 note) — not a §V violation |
| VI. Reusability & DRY | Shared logic in `includes/Utilities/`; existing utilities reused before new ones | ✅ `AdminPageSlugs::SETTINGS_TAB` const bridges the sibling `SettingsMenu::TAB_SLUG` const; no candidate for cross-feature promotion |
| VII. Definition of Done | PHPCS, PHPStan L8, ESLint, security review, tests, DataForm/DataViews, DRY, `acrossai_mcp_` prefix, AGENTS.md, `npm run validate-packages` | ✅ Per-task DoD gates apply to all 7 tasks; no JS in this feature so ESLint is trivially green |

**Result: PASS** — §IV DataForm carve-out is the only notable point, and it's a vendor-consumed surface (justified). No constitution violations. Complexity Tracking section left empty.

## Project Structure

### Documentation (this feature)

```text
specs/012-mcp-settings-tab/
├── spec.md                    # Feature spec (2026-07-03)
├── plan.md                    # This file
├── memory-synthesis.md        # Memory-first synthesis (2026-07-03)
├── security-constraints.md    # Phase-gate output (governed-plan Step 4)
├── architecture-violations.md # Phase-gate output (governed-plan Step 5)
├── checklists/
│   └── requirements.md        # Spec quality checklist — all items pass
└── tasks.md                   # Phase 2 output (/speckit-tasks, not created by this command)
```

### Source Code (repository root)

```text
admin/Partials/
├── SettingsMenu.php                 # NEW — singleton; register_tab + register_settings + 6 render methods + sanitize_uninstall_flag
├── Settings.php                     # delta: delete stub register_settings() method + delete render_cli_auth_log_page() method + delete `use ...CliAuthLogListTable;` import
├── Menu.php                         # delta: delete `add_submenu_page(...CLI_AUTH_LOG...)` block + update position docblock
├── CliAuthLogListTable.php          # DELETED (whole file, 175 lines)
├── MCPServerListTable.php           # UNCHANGED — remains under DEV1 exception per Feature 011 T032
├── ApplicationPasswords.php         # UNCHANGED
├── Notices.php                      # UNCHANGED
└── SettingsRenderer.php             # UNCHANGED
includes/
├── Main.php                         # delta: delete Loader line wiring Settings::register_settings; add 3 Loader lines wiring SettingsMenu (filter + admin_init action); update docblock
├── Utilities/
│   └── AdminPageSlugs.php           # delta: add SETTINGS_TAB const; delete CLI_AUTH_LOG const + docblock; extend plugin_screen_ids() with 'acrossai_page_acrossai-settings'; delete 2 CLI_AUTH_LOG whitelist entries
├── Database/CliAuthLog/             # UNCHANGED — Table.php, Schema.php, Query.php, Row.php, Recorder.php all preserved (OAuth flow dependency)
├── OAuth/                           # UNCHANGED — all files continue to consume CliAuthLog DB layer
├── REST/CliController.php           # UNCHANGED — continues to consume CliAuthLogRecorder
└── (other includes/**)              # UNCHANGED
public/Partials/FrontendAuth.php     # UNCHANGED — get_base_url() consumed by SettingsMenu::render_npm_section_description()
uninstall.php                        # full rewrite behind FR-019 opt-in gate
tests/phpunit/
├── Admin/
│   └── SettingsMenuTest.php         # NEW — 3 test methods (register_tab shape, non-array normalization, register_settings option-group binding)
└── (existing suites)                # UNCHANGED — all pass
docs/
├── memory/
│   ├── DECISIONS.md                 # append DEC-VENDOR-SETTINGS-TAB-INTEGRATION + DEC-UNINSTALL-OPT-IN-GATE + DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG
│   ├── WORKLOG.md                   # Feature 012 milestone entry
│   └── INDEX.md                     # 3 new decision rows + WORKLOG row
├── planings-tasks/
│   ├── 012-mcp-settings-tab.md      # ALREADY LANDED (created in prior turn; drives this Spec Kit chain)
│   └── README.md                    # append row for Feature 012
README.txt                           # 3 Unreleased changelog bullets
```

**Structure Decision**: single project — this plugin's existing admin/, includes/, public/, tests/, docs/ layout. No new directories at the top level. `tests/phpunit/Admin/` is a new subdirectory under `tests/phpunit/` mirroring the existing `Database/`, `OAuth/`, `RestCli/`, etc. subdirs.

## Task Groups (Phase 2 preview)

Task decomposition is `/speckit-tasks` territory, but the plan-time expected task boundaries mirror the planning-doc TASK-1..TASK-7 sketch:

| Group | Files touched | Gate |
|---|---|---|
| **T1 — Create `SettingsMenu` class** | `admin/Partials/SettingsMenu.php` (NEW) | PHPStan L8 + PHPCS green; class parses (`php -l`); PHPUnit test file exists ready-to-run |
| **T2 — Wire in `Main.php` + delete `Settings::register_settings()` stub** | `includes/Main.php` (delta) + `admin/Partials/Settings.php` (delta) | PHPStan L8 + PHPCS green; `grep -n 'register_settings' admin/Partials/Settings.php` returns 0 lines; Loader wiring order verified (after `bootstrap_database_tables()` per Feature 011 order) |
| **T3 — Extend `AdminPageSlugs`** | `includes/Utilities/AdminPageSlugs.php` (delta) | PHPStan L8 + PHPCS green; `grep 'SETTINGS_TAB' includes/Utilities/AdminPageSlugs.php` returns 1; existing entries preserved |
| **T4 — PHPUnit test** | `tests/phpunit/Admin/SettingsMenuTest.php` (NEW) | `vendor/bin/phpunit tests/phpunit/Admin/SettingsMenuTest.php` returns green; 3 assertions cover the invariants |
| **T5 — Gate `uninstall.php` on the opt-in flag** | `uninstall.php` (rewrite) | `php -l uninstall.php` clean; PHPStan L8 + PHPCS green; both smoke tests pass (preserve-by-default AND destructive opt-in) |
| **T6 — Remove the CLI Auth Log admin page** | `admin/Partials/CliAuthLogListTable.php` (DELETE) + `admin/Partials/Menu.php` (delta) + `admin/Partials/Settings.php` (delta) + `includes/Utilities/AdminPageSlugs.php` (delta) | Removal grep returns zero; companion DB-layer grep hit count unchanged; PHPStan L8 + PHPCS green on modified files; existing PHPUnit tests still pass |
| **T7 — Memory hygiene + changelog** | `README.txt`, `docs/memory/{DECISIONS,WORKLOG,INDEX}.md`, `docs/planings-tasks/README.md` | INDEX rows match DECISIONS entries; 3 new decisions captured; changelog reflects all three feature outcomes (new tab, uninstall default change, CLI Auth Log removal) |

Each task ends on the constitution §VII per-task DoD gate. `/speckit-tasks` will refine this into numbered T001..T0NN entries with acceptance criteria per task.

## Constitution Re-check (post-Phase-1 design)

Design decisions above do not introduce new constitution violations:

- **A1** — no new hooks; `SettingsMenu` has zero `add_action`/`add_filter` in its class body; hooks wired in `Main.php`.
- **A2** — `SettingsMenu` uses singleton `instance()` + private ctor + `$instance` var.
- **A6 / B1** — leading-`\` FQN on `\AcrossAI_Main_Menu\SettingsPage`, `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth`, and (in Main.php) `\AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu`.
- **A8** — Access Control submenu (position 4 in Menu.php, guarded by `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')`) survives the position-3 CLI Auth Log deletion.
- **A9** — subtractive edit to `plugin_screen_ids()` is justified (removes entries for a page slug that no longer exists) and codified in the new `DEC-ADMIN-SURFACE-PRUNE-CLI-AUTH-LOG` decision.
- **§III** — `manage_options` gate preserved; `$wpdb->prepare()` in uninstall.php LIKE-sweep; no new REST/nonce/capability surface.
- **§IV** — accepted DEV carve-out documented and captured as `DEC-VENDOR-SETTINGS-TAB-INTEGRATION` for future consumer plugins.
- **§VII** — per-task DoD gates apply to every T1..T7.

**Result: PASS on second gate.**

## Complexity Tracking

No constitution violations to justify.
