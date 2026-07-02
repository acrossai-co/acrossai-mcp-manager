# Implementation Plan: Composer Dependencies Update — PHP 8.1 baseline + BerlinDB + Access Control + Main Menu

**Branch**: `010-composer-dependencies` | **Date**: 2026-07-01 (refreshed 2026-07-02) | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/010-composer-dependencies/spec.md`
**Memory synthesis**: [memory-synthesis.md](./memory-synthesis.md) — 11 durable-memory entries selected (D5, D13, D14, A1, A6, A9, DEV3, S9, §III guard, B11, B12).
**Reference-plugin research (2026-07-02)**: `acrossai-abilities-manager` Feature 038 already integrates all 4 packages. Findings resolved R1 + R2 questions inline; namespace corrected to `\AcrossAI_Main_Menu\`; A1 auto-hook CONFIRMED; D14 predicate UNAVAILABLE; new FR-029/FR-030/FR-031 added (shared parent bootstrap + pre-activation vendor guard + accepted A1 deviation). Standalone security review at `docs/security-reviews/2026-07-02-010-composer-dependencies-plan.md`.

---

## Summary

Feature 010 is the last non-optional migration piece before the `feature/issue-3 → main` cutover. Three concrete work groups:

1. **`composer.json` edits + PHP 8.1 atomic sync** — 5 require changes (php `>=8.1`, jetpack-autoloader `^5.0`, plus 3 new packages) synced across composer.json + plugin header + README.txt + constitution + copilot-instructions in a single commit per CONSTRAINT 4.
2. **`admin/Partials/Menu.php` migration to submenu of shared `acrossai` parent** *(refined 2026-07-02)* — `acrossai-co/main-menu` (namespace `\AcrossAI_Main_Menu\`) owns the shared parent menu across AcrossAI plugins. Our admin surface migrates from top-level `add_menu_page` to `add_submenu_page( 'acrossai', ..., position 2 )` under that parent. Preserve URL slug `?page=acrossai_mcp_manager` (trivially satisfied — submenu URLs work identically to top-level).
3. **`AdminPageSlugs::plugin_screen_ids()` extension** *(no longer conditional)* — top-level → submenu transition changes `get_current_screen()->id` from `toplevel_page_acrossai_mcp_manager` to `acrossai_page_acrossai_mcp_manager`. Whitelist extends ADDITIVELY per A9 canonical-whitelist preservation and Phase 8 admin asset enqueue guard invariant; retain legacy `toplevel_page_*` IDs defensively.
4. **Shared parent bootstrap + pre-activation vendor guard in plugin entry file** *(new 2026-07-02)* — `acrossai-mcp-manager.php` gains: (a) `\AcrossAI_Main_Menu\SettingsPage` bootstrap on `plugins_loaded` priority 0 with `did_action()` + `class_exists()` guards (FR-029); (b) pre-activation guard on `activate_<plugin>` priority 1 that `wp_die()`s if `vendor/autoload_packages.php` is missing (FR-030). The bootstrap is an accepted A1 deviation per FR-031, registered as D15 in memory + DEV4 in INDEX.md via T012a.

Zero-touch surfaces (verify only, do not modify):
- `includes/MCP/Controller.php` (Feature-009) — MCP adapter boot untouched
- `includes/OAuth/ClaudeConnectors.php` (Feature-005/007) — OAuth surfaces untouched
- `includes/REST/CliController.php` (Feature-006) — REST + `class_exists` guard preserved
- `public/Partials/FrontendAuth.php` (Feature-007) — Consent-surface exception scope untouched
- `public/Main.php` (Feature-008) — OAuth-scoped enqueue untouched
- `includes/Database/*/Query.php` (Feature-002) — Custom BerlinDB-style Query classes NOT refactored (deferred to Feature 011)
- All 4 `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guards PRESERVED (CONSTRAINT 1)

### Plan-time decisions carried forward from spec (§Clarifications)

Two clarifications baked in per 2026-07-01 session:

- **Test coverage** (Q1): no new PHPUnit test file added by Feature 010. `admin/Partials/Menu.php` migration is validated by SC-005 curl smoke test + Phase 8's existing enqueue-guard tests as the regression net for `AdminPageSlugs` whitelist consumers.
- **Feature 011 sequencing** (Q2): non-blocking post-cutover polish. `feature/issue-3 → main` can merge with custom Query classes in place.

### Research-time decisions (RESOLVED 2026-07-02 via reference-plugin inspection)

Two questions initially deferred to Phase 0 research were resolved out-of-band by inspecting `acrossai-abilities-manager` Feature 038:

- **R1 (resolved)** — Namespace is `\AcrossAI_Main_Menu\` (NOT speculative `\AcrossAI_Co\MainMenu\`). Entry class `\AcrossAI_Main_Menu\SettingsPage` — auto-hooks `admin_menu` internally via `MenuRegistrar::register_parent()`. Consumer plugins register their own submenus via standard `add_submenu_page('acrossai', ...)`. Screen ID prefix `acrossai_page_*` (verified — parent title `__( 'AcrossAI', 'acrossai' )`). Package does NOT publish consumer-usable public-static predicates for screen matching (D14 UNAVAILABLE). Full findings in tasks.md T004.
- **R2 (resolved)** — NO new `allow-plugins` entries needed. None of `wpb-access-control`, `berlindb/core`, or `main-menu` ship composer plugins (confirmed via reference plugin's `composer.json`). Existing 2 entries preserved. **New finding**: `wpb-access-control` is NOT on Packagist — requires a `repositories` VCS entry in `composer.json` (`{"type":"vcs","url":"https://github.com/WPBoilerplate/wpb-access-control"}`). This is a NEW composer.json edit not originally in the plan (now covered by FR-003 + T005).

No further blocking research. T004 records the findings as the durable Phase 0 output.

## Technical Context

| Field | Value |
|---|---|
| Language / version | **PHP 8.1+ (bumped this feature)**; JavaScript untouched |
| Primary dependencies | `automattic/jetpack-autoloader ^5.0` (bumped), `wpboilerplate/wpb-access-control ^2.0.0` via VCS repo (NEW), `berlindb/core ^3.0.0` (NEW), `acrossai-co/main-menu 0.0.10` exact pin (NEW). `composer.json` also gains `prefer-stable: true`. No `require-dev` changes. |
| Storage | None new. No DB, no options, no transient. |
| Testing | **Per §Clarifications Q1** — SC-005 curl smoke test (HTTP 200 + menu chrome). Phase 8's `admin/Main.php` enqueue-guard tests via `AdminPageSlugs` serve as regression net. PHPCS WPCS strict + PHPStan L8 on touched files. No new PHPUnit files this feature. |
| Target platform | WordPress 6.9+; single-site only |
| Project type | WordPress plugin — dependency/config finalization |
| Performance goals | Zero measurable regression (plugin activation time; admin menu render latency) |
| Constraints | A1 (Loader-based hook wiring, with FR-031 scoped deviation for FR-029 shared parent bootstrap), A6 (`use` imports), A9 (`AdminPageSlugs::plugin_screen_ids()` additive extension only), D5 (no new PHPCS exclusions), D13 (accepted deviation escalated to memory per generalizable-pattern rule), CONSTRAINT 1 (class_exists guards preserved), CONSTRAINT 2 (Query classes untouched), CONSTRAINT 3 (no PHP 8.1 language features), CONSTRAINT 4 (atomic PHP bump), CONSTRAINT 5 (admin URL preserved) |
| Scale / scope | 1 composer.json edit + 4 sync-point files + 1 file rewrite (Menu.php) + 1 conditional file (AdminPageSlugs.php) + 1 conditional file (includes/Main.php Loader wiring). Estimated ~150–200 LOC net. |

### Hard prerequisites (P0)

All shipped on `feature/issue-3` (verified 2026-07-01):

1. `admin/Partials/Menu.php` currently exists using raw `add_menu_page`/`add_submenu_page` (Phase 2 shipped) ✅
2. `includes/Utilities/AdminPageSlugs::plugin_screen_ids()` exists and is consumed by `admin/Main.php` enqueue guards (Phase 2/3 shipped) ✅
3. `includes/Main.php::define_admin_hooks()` wires `Menu.php` on `admin_menu` action via Loader (Phase 2 shipped) ✅
4. 4 `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guards present at all 4 documented call sites ✅
5. `composer.json` currently declares `"php": ">=7.4"` + `"automattic/jetpack-autoloader": "^3.0"` — the pre-Feature-010 baseline ✅
6. Plugin header (`acrossai-mcp-manager.php`) declares `Requires PHP: 8.0` — will be bumped to 8.1 ✅
7. `.specify/memory/constitution.md` + `.github/copilot-instructions.md` reference PHP 8.0 ✅
8. `wordpress/mcp-adapter` package's `\WP\MCP\Plugin` guard pattern (Feature-009) preserved — this feature does NOT touch `includes/MCP/Controller.php` ✅

### Test surface

Per §Clarifications Q1, no new PHPUnit files added by Feature 010. Existing test coverage remains authoritative:

- `tests/phpunit/FrontendAuth/EnqueueAssetsTest.php` (Phase 8) — asserts admin/frontend enqueue guard behavior via `AdminPageSlugs` — regression net for Feature 010's Menu.php migration
- `tests/phpunit/RestCli/*` (Phase 6) — REST route + BearerAuth coverage; unaffected
- `tests/phpunit/OAuth/*` (Phase 5) — OAuth flow coverage; unaffected
- `tests/phpunit/MCP/ControllerTest.php` (Feature-009) — MCP boot state machine; unaffected
- `tests/phpunit/FrontendAuth/*` (Phase 7) — consent surface; unaffected

**Optional PHPUnit** — T020 REQUIRES extending `AdminPageSlugs::plugin_screen_ids()` (top-level → submenu transition guaranteed). Per Q1 clarification, no new PHPUnit files added by Feature 010; SC-013 manual verification + Phase 8's existing `EnqueueAssetsTest.php` serve as the regression net. Reconsider a small `tests/phpunit/Utilities/AdminPageSlugsTest.php` if regression surfaces during T020 execution.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| I. Modular Architecture | ✅ | Feature 010 preserves module boundaries. Menu.php remains an `admin/Partials/` class; delegates to `\AcrossAI_Main_Menu\SettingsPage` (vendor namespace); does not expand its own responsibilities. |
| II. WordPress Standards | ✅ | PHPCS WPCS strict + PHPStan L8 gates preserved. No deprecated fns. |
| III. Security First | ✅ | §III surfaces are structurally null this feature (no forms/REST/DB/transient/consent surface changes). `class_exists` guards preserved (FR-025). Consent-surface exception (Feature-007 amendment) untouched. FR-030 pre-activation vendor guard is defense-in-depth per §V Integration Resilience. |
| IV. User-Centric Design (DataForm) | ✅ N/A | No new admin UI screens. Menu.php migration rewires REGISTRATION, not screen rendering. |
| V. Extensibility Without Core Modification | ✅ | All non-deviation wiring via Loader; no core mod. FR-030 pre-activation guard graceful-degrades on missing vendor. |
| VI. Reusability & DRY | ✅ | `AdminPageSlugs::plugin_screen_ids()` remains canonical (A9); consumes vendor `\AcrossAI_Main_Menu\SettingsPage::PARENT_SLUG` constant via `use` import instead of duplicating menu-registration boilerplate. |
| VII. Definition of Done | ✅ | 13 SC gates listed in spec §Success Criteria (SC-001..SC-013); 4 pre-ship validation scripts from Phase 8 run at release-prep. |
| **A1** — Hooks via Loader | ✅ **ACCEPTED DEVIATION (scoped) via FR-031** | Reference-plugin research (2026-07-02) CONFIRMED main-menu package auto-hooks `admin_menu` internally. Loader wiring for Menu.php RETARGETED from `register_menu` → `register_submenu` (submenu callback still Loader-wired per A1). NEW: FR-029 shared parent bootstrap in `acrossai-mcp-manager.php` (plugin entry file) is an accepted A1 deviation — scoped to ONE bootstrap only, gated by `did_action()` + `class_exists()` per §V. Registered as D15 + DEV4 via T012a. |
| **A6** — `use` imports in `Admin\*` | ✅ | Menu.php migration adds `use \AcrossAI_Main_Menu\SettingsPage;` at top of file per FR-018. Prevents B1. |
| **A9** — Shared constants in `includes/Utilities/` | ✅ | `AdminPageSlugs::plugin_screen_ids()` remains canonical; whitelist extended ADDITIVELY per FR-022 (add `acrossai_page_*` IDs; retain legacy `toplevel_page_*` defensively). |
| **B11** — Defensive triple-check on structured reads | ✅ N/A | Not triggered — no runtime consumption of `.asset.php`/transient this feature. |
| **B12** — `wp_enqueue_scripts` non-firing on `template_redirect` exit | ✅ N/A | No `template_redirect` handlers touched. Admin menu registration fires on `admin_menu` (reliable). |
| **D5** — PHPCS baseline exceptions | ✅ | No new PHPCS exclusions introduced. Existing baseline preserved. |
| **D13** — Constitution amend vs. DEV | ✅ **APPLIED** | FR-031 A1 deviation IS generalizable (Feature 038 + Feature 010) — per D13, escalated to durable memory (D15 in DECISIONS.md + DEV4 in INDEX.md). FR-012 constitution edit remains docs-only (PHP version). |
| **D14** — Cross-phase state observation via public-static predicate | ❌ **NOT AVAILABLE (verified)** | Reference-plugin research (2026-07-02) confirmed `acrossai-co/main-menu` publishes constants (`PARENT_SLUG`, `SETTINGS_SLUG`) but NO consumer-usable predicates for screen matching. `AdminPageSlugs::plugin_screen_ids()` remains canonical per A9; T020 hardcodes screen IDs. |
| **DEV3** — Bidirectional Phase 6 ↔ Phase 7 coupling | ✅ | Not triggered. Menu.php is a leaf consumer; clean unidirectional dep tree. No parallel bidirectional coupling created. |
| **NEW DEV4** (Feature 010) — Shared package bootstrap in plugin entry file | ✅ **REGISTRATION REQUIRED** | T012a writes D15 to DECISIONS.md + DEV4 row to INDEX.md. |

**Result**: All gates pass. Zero blocking violations. Plan-time drift findings RESOLVED by 2026-07-02 reference-plugin research: A1 shape confirmed (auto-hook + FR-031 scoped deviation); D14 unavailable; new FRs 029–031 documented. One new memory registration required (T012a) per D13 rule.

## Project Structure

### Documentation (this feature)

```text
specs/010-composer-dependencies/
├── plan.md                       # THIS FILE
├── spec.md                       # 5 US + 28 FRs + 11 SCs + 5 CONSTRAINTS + §Clarifications (already written)
├── memory-synthesis.md           # 11 durable-memory entries selected (already written)
├── research.md                   # Phase 0 — R1 main-menu API + R2 allow-plugins verification + TASK-8 BerlinDB deferral notes
├── data-model.md                 # Phase 1 — dependency graph + screen ID whitelist shape + admin URL contract
├── contracts/                    # Phase 1
│   ├── composer-json.md          # Exact composer.json diff contract
│   ├── main-menu-registration.md # Menu.php ↔ acrossai-co/main-menu API contract (post-R1)
│   └── php-version-sync.md       # Atomic 5-file PHP version bump contract
├── quickstart.md                 # Phase 1 — dev walkthrough: composer update → npm build (unchanged) → smoke on WP 6.9 / PHP 8.1
├── security-constraints.md       # Feature 010 security review output (governed-plan orchestrator)
├── architecture-violations.md    # Feature 010 architecture review output
└── tasks.md                      # /speckit-tasks output (NOT created here)
```

### Source Code (repository root)

```text
composer.json                     # EXTEND — 5 require edits + allow-plugins additions (TASK-2)
composer.lock                     # REGENERATE via `composer update` (TASK-2 output)
vendor/autoload_packages.php      # REGENERATE via `composer dump-autoload -o` (TASK-4)
vendor/composer/*                 # REGENERATE — same

acrossai-mcp-manager.php          # EXTEND — (a) plugin header Requires PHP: 8.0 → 8.1 (T010) + (b) FR-029 shared parent bootstrap on plugins_loaded priority 0 (T014a) + (c) FR-030 pre-activation vendor guard on activate_<plugin> priority 1 (T014b)
README.txt                        # EXTEND — plugin header Requires PHP: 8.0 → 8.1 (T011)
.specify/memory/constitution.md   # EXTEND — tech-stack section PHP 8.1 (T012); do NOT touch §I–§VII principle text
.github/copilot-instructions.md   # EXTEND — PHP pin (T013)

docs/memory/DECISIONS.md          # APPEND — D15 shared-package bootstrap pattern per D13 + FR-031 (T012a)
docs/memory/INDEX.md              # EXTEND — D15 row + DEV4 accepted-deviation row (T012a)

admin/Partials/Menu.php           # REWRITE — migrate to submenu of shared 'acrossai' parent via add_submenu_page + \AcrossAI_Main_Menu\SettingsPage::PARENT_SLUG import (T018). Positions 2/3/4 per FR-020 (MCP Manager main / CLI Auth Log / Access Control conditional).
includes/Main.php                 # EXTEND — retarget Menu.php Loader entry from register_menu → register_submenu in define_admin_hooks() (T019). Package auto-hooks parent; consumer's submenu registration still Loader-wired per A1.
includes/Utilities/AdminPageSlugs.php   # EXTEND (REQUIRED) — add acrossai_page_acrossai_mcp_manager submenu ID + retain legacy toplevel_page_* IDs additively per A9 (T020)

admin/Partials/*.php              # VERIFY ONLY (T021, T022) — sweep for admin URL + capability references
```

**Structure Decision**: Feature 010 is a **dependency + configuration finalization** phase. 1 file rewrite (Menu.php) + 2 conditional edits (Main.php Loader wiring, AdminPageSlugs) + 5 documentation-scale edits (composer.json + plugin header + README.txt + constitution + copilot-instructions). No new PHPUnit files. No new namespaces. No REST routes. No DB tables. No transient state.

## Complexity Tracking

**One scoped, formally-registered deviation** — FR-031 accepts an A1 (all hooks via Loader) exception for the FR-029 shared parent bootstrap in `acrossai-mcp-manager.php`. Rationale: shared vendor package owning a cross-plugin resource must be canonically owned by the entry file, not by any single Loader lifecycle. Per D13 (generalizable pattern, ≥2 features across the codebase family — Feature 038 + Feature 010), registered as **D15** in `docs/memory/DECISIONS.md` and **DEV4** in `docs/memory/INDEX.md`'s Accepted Deviations table via T012a. Deviation is scoped to ONE `add_action` call, gated by BOTH `did_action('acrossai_main_menu_bootstrapped')` idempotency AND `class_exists()` defense-in-depth.

All other FRs align with Constitution + memory-synthesized constraints. Plan-time drift findings (A1 auto-hook nuance, D14 predicate opportunity) RESOLVED by 2026-07-02 reference-plugin research — no longer deferred.

## Phase 0 Output Plan (Research — RESOLVED)

Phase 0's research goals were resolved out-of-band by the 2026-07-02 reference-plugin inspection (`acrossai-abilities-manager` Feature 038). Findings recorded in tasks.md T004:

1. **R1 — main-menu package API surface** ✅ RESOLVED
   - Entry class: `\AcrossAI_Main_Menu\SettingsPage` (not the speculative `\AcrossAI_Co\MainMenu\Registry`)
   - Registration pattern: consumers call standard `add_submenu_page('acrossai', ...)` — no custom API
   - `admin_menu` hook: **auto-hooked internally** (`SettingsPage::__construct` → `MenuRegistrar::register_parent`)
   - Screen ID prefix: `acrossai_page_<submenu-slug>` (verified via parent title `'AcrossAI'` in `MenuRegistrar.php:38`)
   - Public-static predicates: **NONE consumer-usable** for screen matching (D14 UNAVAILABLE); only constants `PARENT_SLUG` + `SETTINGS_SLUG`
   - Internal deps: does NOT require `\WPBoilerplate\AccessControl\...`

2. **R2 — `allow-plugins` verification** ✅ RESOLVED
   - `wpboilerplate/wpb-access-control ^2.0.0` — no composer plugin; **NOT on Packagist** — requires VCS `repositories` entry
   - `berlindb/core ^3.0.0` — no composer plugin
   - `acrossai-co/main-menu 0.0.10` — no composer plugin
   - **NO new `allow-plugins` entries needed**

3. **TASK-8 — BerlinDB deferral doc** — remains as-planned (T034 in tasks.md, produces `specs/010-composer-dependencies/research.md`)

Phase 0 is COMPLETE at plan time. No blocking research remains before T005 execution.

## Phase 1 Output Plan (Design & Contracts)

Phase 1 produces:

- **`data-model.md`** — the dependency graph (5 required + 5 require-dev packages), the screen-ID whitelist shape (post-TASK-6), the admin URL contract (`?page=acrossai_mcp_manager` preservation invariant), and the PHP version sync-point matrix.
- **`contracts/composer-json.md`** — exact diff contract for `composer.json` edits (per FR-001 through FR-007 + R2 outcomes).
- **`contracts/main-menu-registration.md`** — Menu.php ↔ `\AcrossAI_Co\MainMenu\...` API consumption contract (per R1 outcome). Explicit `admin_menu` hook shape (auto-hook removes Loader entry / manual-hook updates it).
- **`contracts/php-version-sync.md`** — atomic 5-file PHP 8.1 bump contract (composer.json + plugin header + README.txt + constitution + copilot-instructions). CONSTRAINT 4 enforced by grep gate.
- **`quickstart.md`** — dev walkthrough: `composer update` → verify autoload regen → activate on WP 6.9 / PHP 8.1 → walk admin menu → run 5 prior-feature regression checks (US4).

Agent context (`CLAUDE.md` or copilot-instructions) gets a single-line plan-link update inside `<!-- SPECKIT START -->...<!-- SPECKIT END -->` markers.

## Phase 1 Re-Check of Constitution Gates

After Phase 1 design, no new violations expected. The three new artifacts (data-model.md, contracts/*, quickstart.md) are documentation only and add no code surface. All memory-informed constraints (A1/A6/A9/D5/D14/CONSTRAINTS 1–5) carry forward unchanged.

**Result**: gates remain green; ready for `/speckit-tasks` after Phase 0 research + Phase 1 design complete.
