---
description: "Implementation task list for Feature 028 — Retire Freemius integration; consume main-menu 0.0.22+ filter-driven Add-ons page"
---

# Tasks: Retire Freemius integration + consumer self-exclusion filter

**Input**: Design documents from `/specs/028-remove-freemius-and-filter-self/`
**Prerequisites**: `plan.md` ✓, `spec.md` ✓ (3 user stories: US1 P1 Freemius retirement, US2 P2 consumer self-exclusion, US3 P3 memory supersession)

**Tests**: Included. Feature specification's Definition of Done (SC-001..SC-003) gates PHPUnit coverage — 4 new `AddonsFilterTest` cases.

**Note**: This tasks.md is reverse-engineered from PR #34 which shipped before the Spec Kit ceremony. All tasks are marked [X] — the code is in main-menu 0.0.23's PR. Task IDs and file paths match what was actually shipped.

**Organization**: Tasks grouped by user story per plan.md priorities. Foundational phase (Phase 2) covers the composer bump that every user story depends on.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 map to spec.md user stories (blank on Setup / Foundational / Polish)
- File paths are exact and repository-relative from plugin root

## Path Conventions

Single WordPress plugin project — paths shown are relative to the plugin root `acrossai-mcp-manager/` (absolute: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm branch state; no code changes.

- [X] T001 Confirm feature branch is checked out with a clean working tree (`git status`); the nominal branch name is `028-remove-freemius-and-filter-self` but shipping happened on `feature/remove-freemius` — see `plan.md` §Note on branch naming. Verify `docs/planings-tasks/028-remove-freemius-and-filter-self.md` exists (pre-Spec-Kit design doc).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Vendor bump — every user story depends on `acrossai-co/main-menu` 0.0.22+ being the installed version.

**⚠️ CRITICAL**: US1 / US2 / US3 cannot begin until this phase is complete.

- [X] T002 Bump `acrossai-co/main-menu` in `composer.json` from `0.0.18` to `0.0.23`. Run `composer update acrossai-co/main-menu` from plugin root — verify `composer.lock` shows the new version AND that `freemius/wordpress-sdk 2.13.4` was uninstalled transitively (no other package in the tree requires it). Confirm `ls vendor/freemius/` returns "No such file or directory" and `ls vendor/acrossai-co/main-menu/src/` shows `SettingsPage.php`, `Tabs.php`, `AddonsPageRenderer.php`, etc. but NO `Addons/` subdirectory.

**Checkpoint**: `acrossai-co/main-menu` is at 0.0.23, `freemius/wordpress-sdk` is gone from the tree, and `\AcrossAI_Addon\AddonsPage` no longer exists as an autoloaded class.

---

## Phase 3: User Story 1 — Plugin ships without a Freemius license/opt-in surface (Priority: P1) 🎯 MVP

**Goal**: Zero Freemius surface remains in the plugin's own source. No `\AcrossAI_Addon\AddonsPage` instantiation, no `fs_*` args, no dead `class_exists` guard around a class that will never exist again.

**Independent Test**: On a fresh WordPress install with only this plugin active, no Freemius opt-in card renders. `grep -rn 'AcrossAI_Addon\|freemius\|fs_dynamic_init\|acrossai-add-ons' includes/ admin/ public/ src/ tests/` returns zero matches. No `api.freemius.com` request in DevTools Network.

- [X] T003 [US1] Delete the entire Freemius/AddonsPage integration block in `includes/Main.php::define_admin_hooks()` (previously lines 354–446). The block spans from the docblock starting with `/** Add-ons submenu page — bundled in acrossai-co/main-menu (\AcrossAI_Addon\AddonsPage). */` through the closing `}` of the `if ( class_exists( \AcrossAI_Addon\AddonsPage::class ) ) { ... }` block. Includes: the `try { new \AcrossAI_Addon\AddonsPage( ACROSSAI_MCP_MANAGER_PLUGIN_FILE, array( 'fs_product_id' => '34418', 'fs_public_key' => 'pk_d61a7ddb1a619f7697fbb4fc397b6', 'fs_slug' => 'acrossai-add-ons', 'fs_menu' => array( 'account' => true, 'contact' => true, 'addons' => true, 'support' => false, 'pricing' => false, 'upgrade' => false ), 'fs_has_addons' => true ) ); }` call AND the `catch ( \Throwable $e ) { ... add_action( 'admin_notices', ... ) }` fallback closure. Net delete: 94 lines. Do NOT touch the preceding `SettingsMenu` block (lines 336–352) or the following `Notices` block.

**Checkpoint**: US1 is complete — the plugin's source has no residual Freemius references (verified by grep gate in SC-006).

---

## Phase 4: User Story 2 — Active plugin does not advertise itself as an installable add-on (Priority: P2)

**Goal**: Hook `acrossai_addons` and strip the entry with `slug === 'acrossai-mcp-manager'` from the array. Defensive: normalize non-array input to `array()`, drop non-array entries.

**Independent Test**: With this plugin plus at least one other AcrossAI plugin active, the Add-ons page renders without this plugin's card. Deactivate this plugin → card reappears (baseline vendor behavior).

- [X] T004 [US2] Create `admin/Partials/AddonsFilter.php` — a singleton class in namespace `AcrossAI_MCP_Manager\Admin\Partials`. Requirements: `declare( strict_types = 1 )`, `defined( 'ABSPATH' ) || exit;`, `final class AddonsFilter`, `protected static ?self $_instance = null;`, `public static function instance(): self` + `private function __construct() {}` per §II singleton pattern. One public method `public function remove_self( mixed $addons ): array` that: (a) returns `array()` if `! is_array( $addons )`, (b) resolves the own-slug via the shared global constant — `$own_slug = defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG' ) ? (string) \ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG : 'acrossai-mcp-manager';` (reuses D1's canonical constant, no third string literal per Principle VI), (c) `array_values( array_filter( $addons, static function ( $addon ) use ( $own_slug ): bool { if ( ! is_array( $addon ) ) return false; return ( $addon['slug'] ?? '' ) !== $own_slug; } ) )`. Docblocks on both `instance()` and `__construct()` (WPCS-required per Squiz.Commenting.FunctionComment.Missing). Zero `add_action` / `add_filter` in the class body — wiring lives in Main.
- [X] T005 [US2] Wire the filter in `includes/Main.php::define_admin_hooks()` — right where the deleted AddonsPage block was (after `$this->loader->add_action( 'admin_init', $settings_menu, 'register_settings' );`). Add: `$addons_filter = \AcrossAI_MCP_Manager\Admin\Partials\AddonsFilter::instance(); $this->loader->add_filter( 'acrossai_addons', $addons_filter, 'remove_self' );` with a short docblock explaining the purpose ("`acrossai_addons` filter — drop our own slug from the list rendered on the shared Add-ons page (bundled in acrossai-co/main-menu 0.0.22+). An already-active plugin should not appear as an installable add-on.").
- [X] T006 [P] [US2] Create `tests/phpunit/Admin/AddonsFilterTest.php` in namespace `AcrossAI_MCP_Manager\Tests\Admin`, extending `WP_UnitTestCase`. Four cases: (a) `test_remove_self_strips_own_slug_and_reindexes` — 3-entry input with own slug in the middle → returns 2 entries with keys [0, 1]; (b) `test_remove_self_is_noop_when_own_slug_absent` — assertSame on same-input; (c) `test_remove_self_normalizes_non_array_input` — null, `'oops'`, `false` all return `array()`; (d) `test_remove_self_drops_non_array_entries` — mixed array with a string in the middle collapses to only the array entries. File belongs to the `admin` testsuite (per `phpunit.xml.dist:<testsuite name="admin">`).

**Checkpoint**: US2 is complete — the filter is hooked, own-slug is stripped, and defensive input handling is covered.

---

## Phase 5: User Story 3 — F022 memory (decisions + bugs) is marked Superseded (Priority: P3)

**Goal**: Every live-memory pointer to the retired Freemius integration flips to `Superseded (F028)` so future readers don't mistake it for active guidance. Entry bodies preserved verbatim per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION until an audit prunes them.

**Independent Test**: `grep -n 'Superseded (F028)' docs/memory/INDEX.md docs/memory/DECISIONS.md docs/memory/BUGS.md` returns at least one line per file. `grep -n 'F028' docs/memory/WORKLOG.md` returns the new 2026-07-17 entry.

- [X] T007 [US3] Update `docs/memory/INDEX.md` — flip the Status column of three rows from `Active (F022)` to `Superseded (F028)`: `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` (Active Decisions table), `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` (Active Decisions table), `B28` (Bugs table). Also add a new row in the Worklog Entries table: `| 2026-07-17 | F028 | Retire Freemius integration; consume main-menu 0.0.22 filter-driven Add-ons page. Vendor 0.0.22 drops freemius/wordpress-sdk + AcrossAI_Addon\ namespace; plugin deletes 94-line AddonsPage instantiation block and ships new Admin\Partials\AddonsFilter singleton that hooks acrossai_addons to drop own slug. Related retired: DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT, DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT, B28 — flipped to Superseded (F028) | WORKLOG.md |`.
- [X] T008 [P] [US3] Update `docs/memory/DECISIONS.md` — flip the `**Status**:` line at two entry sources: `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` and `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT`. New shape: `**Status**: Superseded (Feature 028 — 2026-07-17). <one-sentence supersession reason>. See docs/planings-tasks/028-remove-freemius-and-filter-self.md.` followed by a `**Original status**:` line preserving the F022 stamp. Entry bodies below MUST remain verbatim.
- [X] T009 [P] [US3] Update `docs/memory/BUGS.md` — same `**Status**:` line flip at `B28`. Body preserved.
- [X] T010 [P] [US3] Add a new entry to `docs/memory/WORKLOG.md` dated `2026-07-17` titled `F028 Retire Freemius integration; consume main-menu 0.0.22 filter-driven Add-ons page`. Content covers two durable-lesson bullets: (1) vendor-shed retirement pattern (bump pin, delete instantiation block, mark related memory Superseded per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION), (2) consumer self-exclusion filter pattern (an active plugin should never advertise itself as installable — every future AcrossAI plugin added to `AddonsPageRenderer::ADDONS` should ship the same filter with its own slug). Insert immediately after the template/counter-example header block, before the F025 entry (WORKLOG is reverse-chronological — newest first).

**Checkpoint**: US3 is complete — memory hygiene reflects the retirement without deleting historical context.

---

## Phase 6: Polish & Verification

**Purpose**: Run the gates enumerated in `plan.md` §Verification and confirm CI green.

- [X] T011 Run `composer run phpcs -- admin/Partials/AddonsFilter.php includes/Main.php` — expect zero violations.
- [X] T012 Run `composer run phpstan` — expect level 8 clean.
- [X] T013 Run `composer run test -- --testsuite mcpclients` locally to confirm no cross-suite regression. (The `admin` testsuite requires the WP-PHPUnit harness at `/tmp/wordpress-tests-lib`; deferred to CI.)
- [X] T014 Confirm CI green on PR #34: 8 checks — F021 grep gates, JavaScript Lint, PHP 8.1+ Compatibility, PHPStan Static Analysis, PHPUnit (integration) — PHP 8.4 / WP latest, PHPUnit (pure) — PHP 8.4, WordPress Coding Standards, WordPress Package Hierarchy (Constitution §VI/§VII).
- [X] T015 Verify grep gate SC-006: `grep -rn 'AcrossAI_Addon\|freemius\|fs_dynamic_init\|fs_product_id\|fs_public_key\|fs_slug\|fs_menu\|fs_has_addons\|acrossai-add-ons\|acrossai_addons_' includes admin public src tests acrossai-mcp-manager.php uninstall.php composer.json` from plugin root returns zero matches. (Historical `docs/planings-tasks/022-addons-page-registration.md`, `specs/022-addons-page-registration/`, and `docs/memory/{DECISIONS,BUGS,INDEX,WORKLOG}.md` references are historical record and NOT covered by this gate.)

**Checkpoint**: All gates pass; PR #34 ready for merge.

---

## Task summary

- Total tasks: 15
- Setup: 1 (T001)
- Foundational: 1 (T002)
- US1 (P1): 1 (T003)
- US2 (P2): 3 (T004, T005, T006)
- US3 (P3): 4 (T007, T008, T009, T010)
- Polish: 5 (T011–T015)

Parallel-safe tasks marked [P]: T006 (test file, independent of T005 wiring), T008 (DECISIONS.md), T009 (BUGS.md), T010 (WORKLOG.md). All others are sequential due to file dependencies.

Coverage matrix (Requirement → Task):

| Req | Tasks | Notes |
|---|---|---|
| FR-001 (composer pin) | T002 | |
| FR-002 (freemius uninstalled) | T002 | Verified by `ls vendor/freemius` post-update. |
| FR-003 (no Freemius refs in source) | T003 | Verified by T015 grep gate. |
| FR-004 (AddonsFilter class) | T004 | |
| FR-005 (strip own slug + reindex) | T004, T006(a) | |
| FR-006 (non-array input → `array()`) | T004, T006(c) | |
| FR-007 (drop non-array entries) | T004, T006(d) | |
| FR-008 (Loader wiring, not direct `add_filter`) | T005 | |
| FR-009 (SettingsMenu untouched) | (none — explicit non-goal) | Verified by absence of SettingsMenu changes in diff. |
| FR-010 (INDEX.md Status flips) | T007 | |
| FR-011 (DECISIONS.md + BUGS.md Status flips) | T008, T009 | |
| FR-012 (WORKLOG.md entry) | T010 | |
| FR-013 (INDEX.md Worklog row) | T007 | |
| SC-001 (PHPCS) | T011 | |
| SC-002 (PHPStan L8) | T012 | |
| SC-003 (PHPUnit) | T013, T014 | Local + CI split per plan.md. |
| SC-004 (Add-ons page card absent) | (post-deploy) | Manual verification, not a task. |
| SC-005 (vendor/freemius gone) | T002 | |
| SC-006 (grep gate) | T015 | |
