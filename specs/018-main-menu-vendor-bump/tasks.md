---

description: "Task list — Feature 018 Bump acrossai-co/main-menu to 0.0.13 and adopt tab-scoped Settings API"
---

# Tasks: Bump `acrossai-co/main-menu` to 0.0.13 and adopt tab-scoped Settings API

**Input**: Design documents from `/specs/018-main-menu-vendor-bump/`
**Prerequisites**: `plan.md`, `spec.md`, `quickstart.md`

**Tests**: No net-new automated tests are added by Feature 018. Verification is (a) `php -l`, (b) `composer phpcs` + `composer phpstan`, (c) manual save flow per `quickstart.md`, (d) two grep audits per FR-011 / FR-012.

**Organization**: All 3 user stories are P1 and are landed together in one atomic patch. Tasks are grouped by phase (dependency-first).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel with other [P] tasks in the same phase (different files, no dependencies)
- **[Story]**: US1 fatal on admin_init / US2 Save Changes flow / US3 sibling tab regression protection
- Paths are absolute per `plan.md` §Project Structure

## Path Conventions

- Plugin root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager`
- All paths below are project-relative to that root

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish the pre-fix grep baselines so the FR-011 / FR-012 audits at Phase 4 have a reference point.

- [X] T001 Capture the pre-fix reproduction. Note in the PR body that on the pre-fix build, loading any wp-admin URL emits `Fatal error: Uncaught Error: Call to undefined method AcrossAI_Main_Menu\SettingsPage::tab_page_slug() in admin/Partials/SettingsMenu.php:113`, and that clicking Save on the MCP tab returns "Error: The acrossai-settings-mcp options page is not in the allowed options list." No file artifact is committed for T001 — the reproduction is the two errors in the PR body.
- [X] T002 [P] Confirm the vendor version pinned by the sibling plugin. Run `grep '"acrossai-co/main-menu"' ../acrossai-abilities-manager/composer.json` — expected exactly one match, pinned at `0.0.13`. Confirms the runtime class version that Jetpack Autoloader will load in a both-plugins-active install.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Bump the vendor dependency BEFORE editing the call site — the new API surface (`SettingsPage::get_settings_renderer()`) must be present in `vendor/` when the edited call site is evaluated by PHPStan.

- [X] T003 [US1] Modify `composer.json`. Change the `require` entry `"acrossai-co/main-menu": "0.0.11"` to `"acrossai-co/main-menu": "0.0.13"`. Keep the exact-version pin (no caret range) — `0.0.z` vendor releases are API-unstable per the break Feature 018 is patching.
- [X] T004 [US1] From plugin root, run `composer update acrossai-co/main-menu --no-interaction`. Verify:
  - stdout reports `Upgrading acrossai-co/main-menu (0.0.11 => 0.0.13)`.
  - `composer.lock` records `0.0.13` for the package.
  - `vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php` exists (new file in 0.0.13).
  - `vendor/acrossai-co/main-menu/src/SettingsPageRenderer.php` exists (new file in 0.0.13).
  - `grep -n 'public static function tab_page_slug' vendor/acrossai-co/main-menu/src/SettingsPage.php` returns zero matches (proves the static helper is gone).
  - `grep -n 'public function tab_page_slug' vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php` returns one match (proves the new instance method is present).
- [X] T005 [US1] Confirm the vendor's `TabbedPageRenderer::render()` emits the tab-scoped `option_page`. Run `grep -nB2 -A2 'settings_fields' vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php` — expected the `$tab_scoped = $this->tab_page_slug( $active['slug'] );` local variable is what `settings_fields()` receives inside the tabbed branch. This is the authoritative signal that consumer plugins MUST register against the tab-scoped slug.

**Checkpoint**: Vendor is at 0.0.13. Call site is still on the 0.0.11 API and will fatal on `admin_init` until Phase 3 lands. Do NOT reload wp-admin between Phase 2 and Phase 3 — you will hit the same fatal you are patching.

---

## Phase 3: User Story 1 + US2 + US3 — Align the call site with the 0.0.13 contract (Priority: P1) 🎯 MVP

**Goal**: `admin/Partials/SettingsMenu.php::register_settings()` uses `SettingsPage::get_settings_renderer()->tab_page_slug()` for the page slug and passes that same tab-scoped slug as the `$option_group` for BOTH `register_setting()` calls. Two docblocks describing the old shared-`'acrossai-settings'` behaviour are rewritten to describe the tab-scoped behaviour.

**Independent Test**: On the Feature 018 build, load any wp-admin URL — no fatal on `admin_init`. Navigate to Settings → AcrossAI → MCP, toggle "Enable CLI Connections", click Save Changes — success notice. Toggle "Delete all data on uninstall", click Save — success notice.

- [X] T006 [US1] [US2] Modify `admin/Partials/SettingsMenu.php::register_settings()` (method around lines ~110–170).
  - **Replace** the single-line slug lookup:
    ```php
    $page_slug = \AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG );
    ```
    **With** the accessor-plus-fallback shape:
    ```php
    $renderer  = \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer();
    $page_slug = $renderer
        ? $renderer->tab_page_slug( self::TAB_SLUG )
        : \AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG . '-' . sanitize_key( self::TAB_SLUG );
    ```
  - **Add** `$option_group = $page_slug;` immediately after the slug lookup, with an inline block-comment explaining the 0.0.13 tab-scoped `option_page` contract and citing `vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php:87–94` as the authority.
  - **Change** the first argument of BOTH `register_setting()` calls from the literal `'acrossai-settings'` to `$option_group`. Order of args, sanitize callbacks, and defaults are untouched.
- [X] T007 [US1] Rewrite the class-level docblock at `admin/Partials/SettingsMenu.php` lines 1–14. The paragraph that reads "…and wires the tab's sections + fields onto the per-tab page slug returned by `\AcrossAI_Main_Menu\SettingsPage::tab_page_slug()`. The option group stays the shared 'acrossai-settings' so the vendor's `settings_fields()` emit + `options.php` handoff resolve for every tab." is FALSE under 0.0.13 and MUST be replaced with a paragraph describing the tab-scoped `option_group` behaviour, referencing `SettingsPage::get_settings_renderer()` and `TabbedPageRenderer::render()`.
- [X] T008 [US1] Rewrite the `register_settings()` docblock at `admin/Partials/SettingsMenu.php` lines ~95–110. The paragraph that reads "Sections target the per-tab page slug derived from the host package's `SettingsPage::tab_page_slug()` helper — `option_group` stays the shared `'acrossai-settings'`…" is FALSE under 0.0.13. Replace with a paragraph describing the tab-scoped `option_group` and referencing `TabbedPageRenderer::render()` as the authority.
- [X] T009 [P] [US1] Run `php -l admin/Partials/SettingsMenu.php` — expected `No syntax errors detected`.

**Checkpoint**: Fatal is gone; Save works on both MCP toggles.

---

## Phase 4: Verification (Definition-of-Done gates)

**Purpose**: Prove the FRs are satisfied and no regression has slipped in.

- [ ] T010 [P] [US1] [US2] [US3] Post-patch grep audit — FR-011. Run `grep -n "'acrossai-settings'" admin/Partials/SettingsMenu.php` — expected zero results. A single surviving literal indicates T006 was applied incompletely.
- [ ] T011 [P] [US1] Post-patch grep audit — FR-012. Run `grep -n "SettingsPage::tab_page_slug" admin/Partials/SettingsMenu.php` — expected zero results. A surviving reference indicates T006 was applied incompletely.
- [ ] T012 [US1] Run `composer phpcs` from the plugin root — expected zero errors. If new errors surface, resolve them without weakening the `phpcs.xml.dist` ruleset.
- [ ] T013 [US1] Run `composer phpstan` from the plugin root — expected zero errors at level 8, no new baseline entries. If a new error surfaces, resolve without adding a `phpstan-baseline.neon` entry.
- [ ] T014 [US2] Manual save flow — npm toggle. Follow `quickstart.md` §Save flow (npm toggle) end-to-end on the local install. Expected: green success notice, `wp option get acrossai_mcp_npm_login_enabled` reflects the toggled state.
- [ ] T015 [US2] Manual save flow — uninstall toggle. Follow `quickstart.md` §Save flow (uninstall toggle). Expected: green success notice, `wp option get acrossai_mcp_uninstall_delete_data` reflects the toggled state.
- [ ] T016 [US3] Manual regression check — sibling tab. Navigate to any non-MCP tab on Settings → AcrossAI contributed by `acrossai-abilities-manager`, toggle a setting there, Save. Expected: success notice, no whitelist error, MCP tab still saves per T014 / T015.
- [ ] T017 [US1] Confirm `wp-content/debug.log` contains no `Call to undefined method` entries mentioning `SettingsPage::tab_page_slug` during a 5-minute soak covering the flows in T014–T016.

**Checkpoint**: All FRs proven. Ready to commit + push onto branch `017-per-server-ability-selection`.

---

## Phase 5: Land the fix on the open PR

- [ ] T018 Stage `composer.json`, `composer.lock`, `admin/Partials/SettingsMenu.php`, `docs/planings-tasks/018-main-menu-vendor-bump.md`, and `specs/018-main-menu-vendor-bump/*`. Do NOT stage `vendor/` (composer manages that). Do NOT stage `.phpunit.cache/`, `build/js/abilities.*`, `.claude/settings.local.json` — those are untracked ambient state that is not part of Feature 018.
- [ ] T019 Commit with a `[Spec Kit]` prefix mirroring the 016 / 017 style. Suggested message: `[Spec Kit] Feature 018 — Bump acrossai-co/main-menu 0.0.11 → 0.0.13 + tab-scoped Settings API compat`.
- [ ] T020 Push to `origin/017-per-server-ability-selection` so PR #22 picks up the new commit.
- [ ] T021 Append a "### Feature 018 addendum (hotfix)" section to the PR #22 body describing (a) the pre-fix fatal + save-whitelist error, (b) the vendor bump, (c) the two-line call-site change, (d) the docblock refresh. Link the section to `docs/planings-tasks/018-main-menu-vendor-bump.md` and `specs/018-main-menu-vendor-bump/spec.md`.

**Checkpoint**: PR #22 now carries both Feature 017 and Feature 018. Merge blocked until 017's remaining T062 / T064–T071 items resolve; Feature 018's Phase 4 gates are independent and can be greened first.

---

## Dependencies & Ordering

- Phase 1 (T001–T002) → Phase 2 (T003–T005) → Phase 3 (T006–T009) → Phase 4 (T010–T017) → Phase 5 (T018–T021).
- T003 blocks T004. T004 blocks T005. T005 blocks T006 (must confirm the vendor emits the tab-scoped `option_page` before we align the registration to it).
- T006 blocks T007, T008, T009, and all of Phase 4.
- Within Phase 4, T010 + T011 can run in parallel with T012 + T013; T014 + T015 + T016 are sequential (share the same browser session).
- Phase 5 is strictly sequential.
