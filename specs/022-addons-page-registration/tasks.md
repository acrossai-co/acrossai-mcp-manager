---
description: "Tasks for Feature 022 — Register shared AcrossAI Add-ons submenu"
---

# Tasks: Register shared AcrossAI Add-ons submenu

**Input**: Design documents in `/specs/022-addons-page-registration/`
**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md)

**Tests**: **Not applicable.** This feature is a single external-package instantiation wrapped in a `class_exists` + `try/catch`. Vendor code has its own coverage; error paths are exercised by manual E2E per §Testing Strategy in `plan.md`. PHPCS + PHPStan level 8 + `php -l` + manual smoke are the DoD gates.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1 | US2 — maps to spec.md user stories
- Setup / Polish phases have **no** story label

## Path Conventions

Existing WordPress-plugin layout: `admin/`, `includes/`, `public/`, `docs/`. All paths in this document are relative to the plugin root (`.../plugins/acrossai-mcp-manager/`).

---

## Phase 1: Setup (Pre-flight audit)

**Purpose**: Capture the pre-change state so §Success Criteria SC-004 (adjacent-wiring integrity) can be verified after the edit.

- [ ] **T001** Capture pre-flight grep of every hook wiring call inside `Main::define_admin_hooks()`:
      ```
      grep -nE "loader->add_action|loader->add_filter" includes/Main.php > /tmp/f022-preflight.txt
      wc -l /tmp/f022-preflight.txt
      ```
      Save the output alongside this tasks file (e.g. paste as a fenced code block at the bottom of `plan.md` §Design → Reference reads) so `T007` can diff against it.

- [ ] **T002** [P] Confirm `\AcrossAI_Addon\AddonsPage` autoloads:
      ```
      composer -d . dump-autoload
      php -r 'require "vendor/autoload_packages.php"; var_dump( class_exists( "AcrossAI_Addon\\AddonsPage" ) );'
      # expected: bool(true)
      ```
      Fails only if the vendor package is stripped from the current install — in which case installation via composer must precede this feature.

- [ ] **T003** [P] Confirm the sibling plugin's canonical block still lives at its documented lines:
      ```
      grep -n "AcrossAI_Addon\\\\AddonsPage" \
          ../acrossai-abilities-manager/includes/Main.php
      # expected: hit around line 324; if the sibling has since refactored, re-read
      # its Main.php before writing TASK-1.
      ```

**Checkpoint**: Phase 1 complete when pre-flight grep captured, vendor class confirmed autoloadable, sibling reference block located.

---

## Phase 2: Foundational

**Not applicable** — this feature has zero foundational infra work. Proceed to Phase 3 (User Story 1).

---

## Phase 3: User Story 1 — Submenu appears on activation (P1)

- [ ] **T010** [US1] Edit `includes/Main.php` — insert the block from `plan.md` §Design → Exact block AFTER the `$settings_menu` register_settings line (~line 352) and BEFORE the "Admin notices" comment header (~line 354). Copy the block byte-for-byte from `plan.md`. Do NOT alter surrounding lines.

- [ ] **T011** [US1] Run `php -l includes/Main.php` — expected `No syntax errors detected in includes/Main.php`.

- [ ] **T012** [US1] Manual E2E — happy path:
      1. Reload wp-admin at `/wp-admin/` as `raftaar1191` (admin, has `install_plugins`).
      2. Assert **AcrossAI → Add-ons** appears in the sidebar.
      3. Click it. Assert the page renders (Freemius opt-in banner or add-ons grid).
      4. Screenshot for the PR description.

- [ ] **T013** [US1] Manual E2E — capability gate:
      1. Log in as an Editor (or run `wp user create tester tester@example.test --role=editor`).
      2. Assert the Add-ons submenu is hidden.
      3. Visit `/wp-admin/admin.php?page=acrossai-addons` directly — expected "Sorry, you are not allowed to access this page."

- [ ] **T014** [US1] Manual E2E — sibling coexistence:
      1. Activate `acrossai-abilities-manager` alongside this plugin.
      2. Reload wp-admin — assert exactly ONE Add-ons row appears (vendor dedup).
      3. Deactivate the sibling — reload wp-admin — assert the Add-ons row still appears (this plugin owns it independently now).

**Checkpoint**: SC-001 + SC-002 verified.

---

## Phase 4: User Story 2 — Graceful degradation (P2)

- [ ] **T020** [US2] Manual E2E — bad-credentials fault path:
      1. Locally blank `'fs_product_id' => '34418'` → `'fs_product_id' => ''` in `includes/Main.php`.
      2. Reload wp-admin as admin. Assert (a) NO php fatal, (b) `notice-error` banner with the exception message renders, (c) Add-ons submenu is absent.
      3. Log in as Editor — assert no notice visible.
      4. Revert the credential.

- [ ] **T021** [P] [US2] Manual E2E — missing-vendor fault path:
      1. Temporarily rename `vendor/acrossai-co/main-menu/` → `vendor/acrossai-co/main-menu.disabled/` (do NOT git rm).
      2. Reload wp-admin — assert `class_exists` guard skips silently, MCP Manager submenu still loads, no notice fires.
      3. Restore the directory name.

**Checkpoint**: SC-003 verified.

---

## Phase 4b: Vendor bump — enable Freemius auto-submenus (folded in mid-flow, 2026-07-12)

Rationale: the vendored `\AcrossAI_Addon\FreemiusInitializer` hardcoded `account`/`contact`/`support` to `false`. Operator explicitly wants all three visible under the AcrossAI menu. Fixed at the shared-package layer (rather than per-plugin) because the decision is a policy for every consumer plugin — see spec.md §Clarifications Q2 and DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT extended commentary in the memory hub.

- [x] **T024** Edit `/wp-content/main-menu/src/Addons/FreemiusInitializer.php` in the vendor repo — flip `'account' => true`, `'contact' => true`, `'support' => true` in the `menu` config array. Leave `upgrade` / `pricing` / `addons` as `false` (pricing/upgrade served by the Add-ons page itself; a second `addons` row would duplicate the vendor's own Add-ons registration).
- [x] **T025** Commit to `main` on the vendor repo (repo convention is direct-to-main; commit `a58dec9`). Annotate tag `0.0.15` (`0.0.15 — enable Freemius Account/Contact/Support submenus by default (AddonsPage)`). Push both to `origin`.
- [x] **T026** Bump `composer.json` — `acrossai-co/main-menu` from `0.0.14` to `0.0.15`.
- [x] **T027** Add an explicit `repositories` VCS entry for `https://github.com/acrossai-co/main-menu` in `composer.json` (deterministic resolution independent of Packagist sync lag).
- [x] **T028** `composer clear-cache && composer update acrossai-co/main-menu` — confirms 0.0.15 installed against commit `a58dec9`. Verified via `grep -A8 "'menu'" vendor/.../FreemiusInitializer.php` — three defaults now `true`.

## Phase 4e: Vendor bump — expose fs_has_addons override (folded in, 2026-07-13)

Rationale: after Phase 4d, the vendor's own Add-ons submenu was disabled and Freemius's `menu.addons` was expected to fill the gap. But `fs_menu.addons = true` alone didn't produce the row — Freemius's SDK gates its Add-ons submenu on `if ( $this->has_addons() )` (`vendor/freemius/wordpress-sdk/includes/class-freemius.php:18964`), and the vendored `FreemiusInitializer` hardcoded `has_addons => false`. Both need to be `true` together.

- [x] **T037** Edit `/wp-content/main-menu/src/Addons/FreemiusInitializer.php` — add `bool $has_addons = false` as a new optional parameter to `init()`, wire it through to `fs_dynamic_init()`'s `has_addons` field. Default `false` preserves backwards compatibility for consumers that don't need the Add-ons row.
- [x] **T038** Edit `/wp-content/main-menu/src/Addons/AddonsPage.php` — extract `$fs_has_addons = ! empty( $args['fs_has_addons'] );`, pass it as the seventh positional argument to `FreemiusInitializer::init()`. Update the constructor docblock.
- [x] **T039** Edit `/wp-content/main-menu/README.md` §Add-ons page — document the new `fs_has_addons` key with an example.
- [x] **T040** Commit to `main` (commit `a6a35ff`). Tag `0.0.18` (`0.0.18 — AddonsPage 'fs_has_addons' arg unblocks Freemius Add-ons submenu`). Push both.
- [x] **T041** Plugin: bump `composer.json` from `0.0.17` to `0.0.18`. `composer update acrossai-co/main-menu` — confirms 0.0.18 installed against commit `a6a35ff`.
- [x] **T042** Plugin: add `'fs_has_addons' => true` to the `AddonsPage(...)` `$args` in `includes/Main.php` with an inline comment explaining Freemius's `has_addons()` gate + the umbrella-consumer requirement.

Manual verification (post-implement):
- After Freemius opt-in for product 34418, reload wp-admin as `install_plugins`-capable user. Confirm the **Add-ons** row appears under the AcrossAI menu (renders Freemius' "no add-ons yet" placeholder while the umbrella product's Freemius add-on catalog is empty).

---

## Phase 4d: Vendor bump — disable vendor Add-ons submenu (folded in, 2026-07-13)

Rationale: with the umbrella model in place (Freemius product 34418 = `acrossai-add-ons` = single Add-ons surface for the ecosystem) and `fs_menu.addons = true` on the plugin, keeping the vendor's own `MenuRegistrar::register()` alive would produce a duplicate Add-ons row. Vendor 0.0.17 disables that registration (comments out the `add_submenu_page()` call inside `MenuRegistrar::register()`).

- [x] **T035** Edit `/wp-content/main-menu/src/Addons/MenuRegistrar.php` — comment out the `$this->hook_suffix = add_submenu_page(...)` block; preserve the `self::$registered` guard so cross-plugin coordination still short-circuits future calls. Tag commit `d467f83` as `0.0.17`.
- [x] **T036** Plugin: bump `composer.json` from `0.0.16` to `0.0.17`. `composer update acrossai-co/main-menu` — confirmed 0.0.17 installed against commit `d467f83`. Verified `grep -n "add_submenu_page" vendor/.../MenuRegistrar.php` shows the call is now commented (line 36).

Manual verification (post-implement):
- Reload wp-admin as `install_plugins`-capable user. Confirm exactly ONE Add-ons row appears under the AcrossAI menu — sourced from Freemius (product 34418) via `fs_menu.addons = true`, no longer the vendor's `MenuRegistrar`.

---

## Phase 4c: Vendor bump — per-consumer `fs_menu` override (folded in, 2026-07-12)

Rationale: operator asked "make it dynamic so the plugin can decide which submenus to show or hide". Turning the vendor-level hardcode into a per-consumer knob preserves the sensible defaults for every plugin that doesn't opt in, and lets each plugin make its intent explicit at the call site — see spec.md §Clarifications Q3 and FR-016.

- [x] **T029** Edit `/wp-content/main-menu/src/Addons/FreemiusInitializer.php` — extract the six-key `menu` array into a private `DEFAULT_MENU` constant, add an optional `array $menu_overrides = []` parameter to `init()`, `unset( $menu_overrides['slug'] )` before the merge (`slug` derives from `$menu_slug` and cannot be overridden this way), and `array_merge` in the order `DEFAULT_MENU` → `$menu_overrides` → `[ 'slug' => $menu_slug ]` so slug always wins.
- [x] **T030** Edit `/wp-content/main-menu/src/Addons/AddonsPage.php` — extract `$fs_menu = isset( $args['fs_menu'] ) && is_array( $args['fs_menu'] ) ? $args['fs_menu'] : array();`, thread it through the `FreemiusInitializer::init()` call, update the constructor docblock.
- [x] **T031** Edit `/wp-content/main-menu/README.md` §Add-ons page — document the new `fs_menu` key with a full example showing every accepted key and its default, plus the note that `slug` cannot be overridden this way.
- [x] **T032** Commit to `main` (commit `0fb50ea`). Tag `0.0.16` (`0.0.16 — AddonsPage 'fs_menu' arg lets consumers override Freemius menu submenus`). Push both.
- [x] **T033** Plugin: bump `composer.json` from `0.0.15` to `0.0.16`. `composer update acrossai-co/main-menu` — confirms 0.0.16 installed against commit `0fb50ea`.
- [x] **T034** Plugin: extend the `AddonsPage(...)` args in `includes/Main.php` with an explicit `'fs_menu' => [...]` block containing all six standard keys. Values mirror the vendor `DEFAULT_MENU` today, but the explicit form is preserved so future maintainers see the choice at the call site.

Manual verification (post-implement):
- Reload wp-admin as `install_plugins`-capable user. Confirm the AcrossAI menu shows **Account**, **Contact Us**, and **wp.org Support Forum** submenu rows in addition to the existing plugin submenus (MCP, Add-ons, Settings).
- To later hide one, flip its `fs_menu` key to `false` in `includes/Main.php`, reload wp-admin, confirm the row disappears without any other plugin surface changing.

---

## Phase 5: Polish (docs + memory hub)

- [ ] **T030** [P] Edit `README.txt` — insert as the FIRST bullet under `= Unreleased =` at line 185:
      ```
      * **Feature 022 — Shared AcrossAI Add-ons submenu.** The plugin now
        registers the shared "Add-ons" nav entry under the AcrossAI top-level
        menu, powered by Freemius for product id 34418. The page requires
        `install_plugins`; when a companion AcrossAI plugin is active
        simultaneously only one plugin contributes the nav entry (the shared
        package coordinates this so operators never see duplicate submenu rows).
      ```

- [ ] **T031** [P] Edit `docs/planings-tasks/README.md` — append to the Feature Specs table (currently ends at line 49 with the 021 row):
      ```
      | 022 | addons-page-registration | 2026-07-12 | Planned | [022-addons-page-registration.md](022-addons-page-registration.md) |
      ```

- [ ] **T032** [P] If `docs/memory/DECISIONS.md` exists, add the DEC entry from `docs/planings-tasks/022-addons-page-registration.md` §TASK-2 (DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT). Skip if the file is absent.

- [ ] **T033** [P] If `docs/memory/INDEX.md` exists, append a one-line Active Decisions row pointing at DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT. Skip if absent.

---

## Phase 6: Verification sweep + gate

- [ ] **T040** Run `grep -rEn 'AcrossAI_Addon\\\\AddonsPage' includes/ admin/ acrossai-mcp-manager.php` — expected **exactly one** hit (the T010 insertion in `includes/Main.php`).

- [ ] **T041** Run `grep -rEn 'fs_product_id' includes/ admin/ acrossai-mcp-manager.php` — expected **exactly one** hit (the T010 insertion).

- [ ] **T042** Run `grep -rEn "acrossai-addons" includes/ admin/ acrossai-mcp-manager.php` — expected **zero** hits (slug lives only inside vendor/).

- [ ] **T043** Re-run the pre-flight grep from T001:
      ```
      grep -nE "loader->add_action|loader->add_filter" includes/Main.php
      ```
      Diff against `/tmp/f022-preflight.txt` — expected **zero differences** (SC-004 gate).

- [ ] **T044** Run `composer run phpcs` — expected zero errors.

- [ ] **T045** Run `composer run phpstan` — expected zero errors at level 8.

- [ ] **T046** Final sanity: reload `/wp-admin/admin.php?page=acrossai-addons` — page still renders. Reload `/wp-admin/admin.php?page=acrossai_mcp_manager` — MCP Manager list still renders. Reload `/wp-admin/admin.php?page=acrossai-settings` — Settings tabs still render. Zero regression in adjacent surfaces.

---

## Merge Gate

All checkboxes above must be filled before merge. The 6-item gate that BLOCKS merge:

| Gate | Task | Status |
|---|---|---|
| Insertion correct + syntax clean | T010 + T011 | [ ] PASS |
| SC-001 (happy path) | T012 | [ ] PASS |
| SC-002 (sibling coexistence) | T014 | [ ] PASS |
| SC-003 (graceful degradation) | T020 + T021 | [ ] PASS |
| SC-004 (adjacent-wiring integrity) | T043 | [ ] PASS |
| Quality (PHPCS + PHPStan L8) | T044 + T045 | [ ] PASS |

**Merge decision**: [ ] APPROVE [ ] BLOCK — signature + date required when all 6 gates are green.
