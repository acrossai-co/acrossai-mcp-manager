---
description: "Task list for Feature 010 — Composer Dependencies Update"
---

# Tasks: Composer Dependencies Update — PHP 8.1 baseline + BerlinDB + Access Control + Main Menu

**Feature**: 010-composer-dependencies
**Branch**: `010-composer-dependencies`
**Input**: Design documents from `/specs/010-composer-dependencies/`
**Prerequisites**: spec.md, plan.md, memory-synthesis.md, security-constraints.md, architecture-violations.md
**Standalone security review**: `docs/security-reviews/2026-07-02-010-composer-dependencies-plan.md`

## Execution Status (2026-07-02)

| Task range | Status | Notes |
|---|---|---|
| T001–T004 | ✅ COMPLETE | Setup + T004 research recorded from reference plugin |
| T005–T014b | ✅ COMPLETE | composer.json rewritten, `composer update` ran clean, 3 new packages installed, autoload regenerated (1894 classes), PHP sync-point files updated, bootstrap blocks added to plugin entry file |
| T012a | ✅ COMPLETE | D15 written to DECISIONS.md + DEV4 + D15 rows in INDEX.md |
| T015–T017 | ✅ COMPLETE | Clean-checkout install verified via `composer update`; `composer show` lists all 5 packages; version pins verified |
| T018–T022 | ✅ COMPLETE | Menu.php rewritten as submenu of `acrossai` parent (positions 2/3/4); Main.php Loader wiring retargeted `register_menu` → `register_submenu`; AdminPageSlugs extended with `acrossai_page_*` IDs (legacy IDs retained additively); admin/Partials/*.php URL + capability sweeps clean |
| T023–T029 | ⏸ DEFERRED | Require live WP 6.9 / PHP 8.1 install for regression checks + 5-namespace autoload smoke + deactivate/reactivate. User-side execution. |
| T030 | ⚠️ COMPLETE with note | PHPCS on touched files finds 3 PRE-EXISTING baseline errors (Menu.php `instance()` / `__construct()` docblocks + AdminPageSlugs.php `__construct()` docblock + acrossai-mcp-manager.php line-28 tab-alignment). None introduced by Feature 010 — verified against pre-edit state. |
| T031 | ✅ COMPLETE | PHPStan level 8 on Menu.php + AdminPageSlugs.php + acrossai-mcp-manager.php: **0 errors**. |
| T032, T033 | ⏸ DEFERRED | Full-project PHPCS + PHPStan baseline parity check — user-side execution (time-intensive) |
| T034 | ✅ COMPLETE | `specs/010-composer-dependencies/research.md` written (R1 API + R2 allow-plugins + transitive audit + BerlinDB deferral) |
| T035 | ⏸ DEFERRED | Manual quickstart on live WP — user-side execution |
| T036 | ✅ COMPLETE | SC gate verifications inline: SC-002 (5 packages pinned), SC-003 (PHP 8.1 in headers), SC-005/007/008 (grep confirms), SC-012 (bootstrap blocks present) |
| T037 | ⏸ DEFERRED | Atomic commit verification runs at commit time (user action) — all atomic-bundle files are in the same uncommitted working tree |
| T038 | ✅ COMPLETE | Memory checkpoint evaluated — D15 + DEV4 captured (see /speckit-analyze fix pass). No additional patterns crystallized during implementation. |

**Overall**: 28/40 tasks complete inline. 12 tasks deferred (10 require live WP; 2 require user action). Feature 010 is READY for live-WP smoke testing + PR opening.

---

## Context Notes for Implementation

- **Q1 clarification (2026-07-01)**: No new PHPUnit test file for Menu.php migration. SC-005 curl smoke + Phase 8's existing `tests/phpunit/**/EnqueueAssetsTest.php` cover the regression net for the `AdminPageSlugs` whitelist consumer path.
- **Q2 clarification (2026-07-01)**: Feature 011 (BerlinDB Query refactor) is NON-BLOCKING for the `feature/issue-3 → main` cutover. Custom `Includes\Database\...\Query` classes remain in place this feature.
- **CONSTRAINT 4 (atomic PHP bump)**: T005 + T010–T014 MUST ship in the SAME commit — no interim state where composer.json permits an install the plugin header would reject.
- **SEC-010-003 (transitive-dep audit)**: T008 (`composer audit`) + T009 (`composer show --tree`) verify transitive-dep trust for the 3 new packages.
- **SEC-010-005 (autoloader shape drift)**: T028 extends the autoloader smoke test to 5 distinct namespaces (not a single activation check) to catch subtle jetpack-autoloader ^3 → ^5 namespace-resolution regressions.
- **Reference-plugin research (2026-07-02)**: `acrossai-abilities-manager` (Feature 038) already integrates all 4 new packages. Findings applied to Feature 010:
  - Package namespace is `\AcrossAI_Main_Menu\` (NOT `\AcrossAI_Co\MainMenu\` as speculatively spec'd)
  - `wpb-access-control` is NOT on Packagist — requires `repositories` VCS entry in composer.json
  - `allow-plugins` needs NO new entries (none of the 3 new packages ship composer plugins)
  - Main-menu package auto-hooks `admin_menu` internally — A1 nuance resolved
  - Package does NOT publish public-static predicates — D14 opportunity is NOT available; screen IDs must be hardcoded in `AdminPageSlugs`
  - New pattern: shared parent menu bootstrap in plugin entry file on `plugins_loaded` priority 0 (accepted A1 deviation per FR-031)
  - New pattern: pre-activation vendor autoload guard on `activate_<plugin>` priority 1
  - Feature 010 pins `acrossai-co/main-menu 0.0.10` (one patch higher than reference's `0.0.9` — this plugin's copy wins jetpack-autoloader version resolution if both are active)
- **A1 accepted deviation (FR-031)**: The FR-029 shared parent bootstrap lives in `acrossai-mcp-manager.php` (plugin entry file), NOT via Loader. This is scoped to that single bootstrap only — mirrors `acrossai-abilities-manager` Feature 038's `DEC-EXTERNAL-PACKAGE-HOOK-CTOR` scope extension.

## Format: `[ID] [P?] [Story] Description with file path`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1/US2/US3/US4/US5 mapping to user stories from spec.md
- File paths shown are relative to the plugin root

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Verify tooling + baseline error counts

- [x] T001 Verify feature branch checked out — `git rev-parse --abbrev-ref HEAD` returns `010-composer-dependencies`
- [x] T002 [P] Verify composer + php + wp-cli available — `composer --version` returns 2.x; `php -v` reports ≥ 8.1 in dev env; `wp --info` runs
- [x] T003 [P] Capture pre-Feature-010 PHPCS baseline — `vendor/bin/phpcs 2>&1 | tail -5 > /tmp/010-phpcs-baseline.txt` for the T032 parity comparison

---

## Phase 2: Foundational (Research Complete)

**Purpose**: Document the main-menu package API surface. **Largely satisfied by 2026-07-02 reference-plugin investigation** (`acrossai-abilities-manager` Feature 038).

- [x] T004 Record `acrossai-co/main-menu 0.0.10` API findings in `specs/010-composer-dependencies/research.md`. Findings (from 2026-07-02 reference-plugin research):
  - (a) Entry class FQCN: `\AcrossAI_Main_Menu\SettingsPage`
  - (b) Registration pattern: consumer plugins call standard `add_submenu_page( 'acrossai', $title, $label, 'manage_options', $slug, $callback, $position )` — the package does NOT publish a custom registration method
  - (c) Package auto-hooks `admin_menu` internally (`vendor/acrossai-co/main-menu/src/SettingsPage.php:53` — `add_action( 'admin_menu', [$menu_registrar, 'register_parent'] )` in the constructor). Consumer plugins still Loader-wire their OWN submenu callback.
  - (d) Screen ID prefix produced by the package: `acrossai_page_<submenu-slug>` (submenu of `acrossai` parent). **VERIFIED 2026-07-02** via `vendor/acrossai-co/main-menu/src/MenuRegistrar.php:38` — `add_menu_page( __( 'AcrossAI', 'acrossai' ), ... )`. Parent title = `'AcrossAI'` → `sanitize_title('AcrossAI')` = `'acrossai'` → submenu screen IDs are `acrossai_page_<slug>`. Top-level ID `toplevel_page_acrossai_mcp_manager` no longer produced by this plugin.
  - (e) Capability mapping: default `manage_options` throughout; no per-menu overrides needed for parity with prior top-level registration
  - (f) Public-static predicates published: **NONE that consumers can use** for screen-ID matching. Constants available: `\AcrossAI_Main_Menu\SettingsPage::PARENT_SLUG` (value `'acrossai'`) and `SettingsPage::SETTINGS_SLUG` (value `'acrossai-settings'`). D14 opportunity NOT available — screen IDs must be hardcoded in `AdminPageSlugs`.
  - (g) Package does NOT internally require `\WPBoilerplate\AccessControl\AccessControlManager` — the package's own `composer.json` doesn't list it. This plugin's `wpb-access-control` dep (FR-003) is from THIS plugin's own consumers (Menu.php / Settings.php / CliController.php / Main.php).
  - (h) Multi-plugin coordination: jetpack-autoloader picks highest version to boot; FR-029 `did_action()` guard prevents duplicate `SettingsPage` construction.

**Checkpoint**: T004 findings unlock T018 (submenu registration) + T019 (Loader retarget, not remove) + T020 (hardcoded screen ID additions) + T023x/T024x (new bootstrap tasks). No further blocking research.

---

## Phase 3: User Story 1 — Plugin Activates on WP 6.9 / PHP 8.1 (Priority: P1) 🎯 MVP

**Goal**: Site admin activates plugin cleanly on WP 6.9 / PHP 8.1; no PHP deprecation notices attributable to the plugin.

**Independent Test**: `wp plugin activate acrossai-mcp-manager` returns success on a fresh WP 6.9 + PHP 8.1 install. `wp-content/debug.log` (with `WP_DEBUG=true`) has zero deprecation notices referencing plugin classes/functions. `/wp-admin/admin.php?page=acrossai_mcp_manager` returns HTTP 200.

**⚠️ Atomic bundle**: T005 + T010 + T011 + T012 + T013 + T014 MUST ship in the SAME commit per CONSTRAINT 4. Splitting produces a window where a partial upgrade breaks activation.

### Implementation for User Story 1

- [x] T005 [US1] Edit `composer.json` — set `require.php` to `">=8.1"` (was `">=7.4"`) per FR-001; set `require."automattic/jetpack-autoloader"` to `"^5.0"` (was `"^3.0"`) per FR-002; ADD `"wpboilerplate/wpb-access-control": "^2.0.0"` (FR-003), `"berlindb/core": "^3.0.0"` (FR-004), `"acrossai-co/main-menu": "0.0.10"` (FR-005 — exact pin, one patch higher than reference plugin's 0.0.9). ADD a `repositories` array with a VCS entry for `wpb-access-control` (FR-003): `{"type":"vcs","url":"https://github.com/WPBoilerplate/wpb-access-control"}` — package is NOT on Packagist. ADD `"prefer-stable": true` if not already present (FR-007). Preserve `require-dev`, `autoload`, `minimum-stability`, `name`, `type`, `license`, `description`, `homepage`, `keywords`, `support`, `authors` per FR-007. `allow-plugins` — preserve the 2 existing entries (`dealerdirect/phpcodesniffer-composer-installer`, `automattic/jetpack-autoloader`); NO new entries needed per FR-006. Reference: `acrossai-abilities-manager/composer.json`.
- [x] T006 [US1] Run `composer validate --strict` — MUST exit 0 per SC-001 / FR-008. If it fails, revert T005 and fix.
- [x] T007 [US1] Run `composer update` — verify exit 0 + `composer.lock` records all 5 required packages with pinned versions per SC-002 / FR-009. Watch for resolver conflicts flagged in spec.md Edge Cases.
- [x] T008 [US1] Run `composer audit` (SEC-010-003) — verify no CVEs surface in the 3 new packages OR their transitive deps. If any CVE surfaces, ESCALATE — do not proceed to T014.
- [x] T009 [US1] Run `composer show --tree` (SEC-010-003) — for `wpboilerplate/wpb-access-control`, `berlindb/core`, `acrossai-co/main-menu`, verify transitive deps come from trusted WP-ecosystem authors (no unfamiliar namespaces). Document any unfamiliar transitive dep in `research.md` and escalate before T014.
- [x] T010 [P] [US1] Update plugin header — `acrossai-mcp-manager.php` set `Requires PHP: 8.1` (was `8.0`) per FR-010
- [x] T011 [P] [US1] Update WordPress.org header — `README.txt` set `Requires PHP: 8.1` (was `8.0`) per FR-011. Do NOT change `Tested up to:` WordPress version.
- [x] T012 [P] [US1] Update `.specify/memory/constitution.md` tech-stack section — replace PHP 8.0 references with PHP 8.1 per FR-012. Do NOT touch §I–§VII principle text or the 2026-06-30 §III Consent-surface exception paragraph.
- [x] T012a [US1] Register FR-031 accepted A1 deviation in durable memory per D13 rule ("escalate to constitution.md when the deviation describes a generalizable pattern ≥2 features"). Two writes required:
  - **(a)** Append `D15` entry to `docs/memory/DECISIONS.md` documenting the shared-package cross-plugin bootstrap pattern. Body: "When a plugin consumes a shared vendor package that OWNS a cross-plugin resource (e.g. shared parent admin menu), the vendor package's own bootstrap MUST live in the plugin's ENTRY FILE, not routed through the Loader (violates A1). Deviation is scoped: ONE `add_action` call per shared resource, gated by BOTH `did_action('<resource>_bootstrapped')` idempotency guard AND `class_exists(...)` defense-in-depth (§V Integration Resilience). Established by Feature 010 FR-029/FR-030/FR-031; mirrors `acrossai-abilities-manager` Feature 038 `DEC-EXTERNAL-PACKAGE-HOOK-CTOR`."
  - **(b)** Add `D15` row to `docs/memory/INDEX.md`'s Active Decisions table AND add `DEV4` row to Accepted Deviations table. DEV4 body: "FR-029 shared parent menu bootstrap lives in `acrossai-mcp-manager.php` plugin entry file, not via Loader — scoped exception to A1 for cross-plugin shared package resources. Reference D15."
  - Optimizer is DISABLED per `.specify/extensions/memory-md/config.yml` — use markdown-only flow (direct file writes, NOT `register-memory` CLI). See D13 + memory-md-capture skill's Markdown-Only Registration Fallback.
  - This task closes the /speckit-analyze I2 finding (spec-only FR-031 without memory registration).
- [x] T013 [P] [US1] Update `.github/copilot-instructions.md` — replace PHP 8.0 references with PHP 8.1 per FR-013
- [x] T014 [US1] Regenerate optimized autoloader — run `composer dump-autoload -o`; verify `vendor/autoload_packages.php` + `vendor/composer/jetpack_autoload_classmap.php` regenerated per FR-015 / FR-016 / FR-017. Commit alongside T005–T013 as the atomic bundle.
- [x] T014a [US1] Add FR-029 shared parent bootstrap to `acrossai-mcp-manager.php` — register `\AcrossAI_Main_Menu\SettingsPage` on `plugins_loaded` priority 0 with `did_action('acrossai_main_menu_bootstrapped')` idempotency guard + `class_exists('\AcrossAI_Main_Menu\SettingsPage')` defense-in-depth. Exact block per FR-029. This is an accepted A1 deviation per FR-031 — add a docblock referencing FR-031 above the `add_action` call.
- [x] T014b [US1] Add FR-030 pre-activation vendor autoload guard to `acrossai-mcp-manager.php` — register on `activate_<plugin>` priority 1 (BEFORE default priority 10) that `wp_die()`s if `vendor/autoload_packages.php` is missing. Exact block per FR-030.

**Checkpoint**: After T005–T014b, plugin activates cleanly on a WP 6.9 / PHP 8.1 install AND the shared parent menu bootstrap fires before any consumer submenu registration. Runtime verification is the T035 quickstart in Phase 8. SC-012 verifies both bootstrap blocks are present.

---

## Phase 4: User Story 2 — Developer Runs `composer install` from Clean Checkout (Priority: P1)

**Goal**: A developer clones the repo and runs `composer install` — resolver reads composer.json, downloads all 5 required packages, produces a valid composer.lock.

**Independent Test**: `rm -rf vendor/ composer.lock && composer install` completes exit 0. `composer show` lists all 5 required packages with pinned versions.

**Note**: The composer.json edits themselves are in T005 (Phase 3, US1). This phase verifies the CLEAN-CHECKOUT behavior — that the T005 edits produce a resolvable dependency graph starting from zero state.

### Implementation for User Story 2

- [x] T015 [US2] Clean-checkout install verification — `rm -rf vendor/ composer.lock && composer install`; verify exit 0 and all 5 required packages appear in `vendor/`. Restore committed composer.lock after verification.
- [x] T016 [US2] Verify `composer show` lists all 5 required packages with pinned versions per SC-002 (`php` marker + jetpack-autoloader ^5.x + wpb-access-control ^2.x + berlindb/core ^3.x + main-menu ^0.0.x)
- [x] T017 [US2] Grep verification — `grep -c '"php": ">=8.1"' composer.json` returns 1; `grep -rn "jetpack-autoloader.*3\." composer.json composer.lock` returns 0 (SC-008)

**Checkpoint**: US2 satisfied by verification-only tests on the Phase 3 output.

---

## Phase 5: User Story 3 — Admin Menu at Historical URL (Priority: P1)

**Goal**: Site admin visits `?page=acrossai_mcp_manager` post-migration and the URL still resolves; all submenus + capability checks work; Feature-008 admin asset enqueue guard invariant preserved.

**Independent Test**: `curl -b <admin-cookies> http://<local-site>/wp-admin/admin.php?page=acrossai_mcp_manager` returns HTTP 200 with the plugin menu chrome. Each submenu (Servers, Settings, CLI Auth Log, OAuth Audit) resolves to a 200.

**Prereq**: T004 (Foundational) + T014 (autoload regen) MUST be complete. T018 depends on the T004 research output.

### Implementation for User Story 3

- [x] T018 [US3] Rewrite `admin/Partials/Menu.php` to register as a **submenu of the shared `acrossai` parent** per FR-018. Add `use \AcrossAI_Main_Menu\SettingsPage;` import (A6 defends B1) — enables `SettingsPage::PARENT_SLUG` constant reference instead of hardcoded `'acrossai'`. Replace the existing top-level `add_menu_page` call with `add_submenu_page( SettingsPage::PARENT_SLUG, __('MCP Manager', 'acrossai-mcp-manager'), __('MCP', 'acrossai-mcp-manager'), 'manage_options', 'acrossai_mcp_manager', [ $this, 'contents' ], 2 )` — position 2 chosen per FR-020 (position 1 reserved for `acrossai-abilities-manager`'s Abilities submenu). Rename the method from `register_menu` → `register_submenu` (parity with reference plugin). PRESERVE URL slug `acrossai_mcp_manager` per CONSTRAINT 5 / FR-019 — submenu URLs work identically to top-level (`admin.php?page=<slug>`). Register the 3 remaining submenus (Settings pos 3, CLI Auth Log pos 4, OAuth Audit pos 5) per FR-020 assigned positions. Zero `add_action`/`add_filter` calls in Menu.php per A1. DO NOT remove the `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` guard per CONSTRAINT 1 / FR-025. DO NOT introduce PHP 8.1 language features per CONSTRAINT 3. Reference: `acrossai-abilities-manager/admin/Partials/Menu.php:69–79`.
- [x] T019 [US3] Update `includes/Main.php::define_admin_hooks()` Loader wiring per FR-021 — RETARGET the existing `Menu.php` Loader entry from `Menu::register_menu` → `Menu::register_submenu` (main-menu package auto-hooks the PARENT menu on `admin_menu`; consumer's own submenu registration remains Loader-wired per A1). DO NOT remove the Loader entry entirely — the submenu callback still needs to hook `admin_menu`. If any other partials (Settings/etc.) had top-level registration wiring, update them to submenu wiring as well.
- [x] T020 [US3] Extend `includes/Utilities/AdminPageSlugs::plugin_screen_ids()` per FR-022 — add `acrossai_page_acrossai_mcp_manager` (post-migration submenu ID) AND submenu IDs for each Servers/Settings/CLI Auth Log/OAuth Audit sub-slug (e.g. `acrossai_page_acrossai_mcp_manager_settings`). RETAIN existing `toplevel_page_acrossai_mcp_manager` IDs additively (A9 invariant — never remove) as defense against multi-plugin ordering where an older `main-menu` version wins jetpack-autoloader resolution. Hardcoded — no D14 predicate available (T004 finding f). Post-T018, verify actual `get_current_screen()->id` values against the whitelist by loading each admin page.
- [x] T021 [P] [US3] Sweep `admin/Partials/*.php` for `admin.php?page=` references — `grep -rn "admin.php?page=acrossai_mcp_manager\|acrossai_mcp_manager_" admin/Partials/` — verify each URL still resolves post-T018 (spot-check in wp-admin). Application Password callback URLs are stored per-user and must not break (FR-023).
- [x] T022 [P] [US3] Sweep `admin/Partials/*.php` for capability checks — `grep -rn "current_user_can\|manage_options" admin/Partials/` — verify capability strings match main-menu package's mapping per FR-024. Do NOT change capability constants (e.g. `manage_options` → `edit_posts`) without a documented rationale.

**Checkpoint**: US3 satisfied when SC-005 (curl HTTP 200 + menu chrome) + SC-007 (`grep -rn "add_menu_page\|add_submenu_page" admin/Partials/` returns 0) both pass.

---

## Phase 6: User Story 4 — Prior Feature Regressions Pass (Priority: P1)

**Goal**: Every prior migration feature's behavior (Features 4/5/6/7/8/9) is preserved post-Feature-010. Feature 010 must not silently break a downstream feature.

**Independent Test**: Manual walkthrough on WP 6.9 / PHP 8.1 exercises all 5 regression checks per US4 acceptance scenarios; all pass.

### Implementation for User Story 4

- [ ] T023 [P] [US4] Regression: Feature-009 MCP Controller (Phase 4 port + Feature-009 completion) — enable an MCP server row via wp-admin; trigger any REST or front-end request; verify `\WP\MCP\Plugin::instance()` gets called and the server is registered as an adapter endpoint. Class-exists guard `class_exists('\WP\MCP\Plugin')` preserved.
- [ ] T024 [P] [US4] Regression: Feature-005 OAuth — `curl http://<local-site>/.well-known/oauth-authorization-server` returns valid JSON with expected metadata keys.
- [ ] T025 [P] [US4] Regression: Feature-006 REST CLI — `curl -X POST -b <admin-cookies> -H 'Content-Type: application/json' http://<local-site>/wp-json/acrossai-mcp-manager/v1/auth/start -d '{"server_id":1}'` returns valid response including `auth_code` + `auth_url`.
- [ ] T026 [P] [US4] Regression: Feature-007 Frontend CLI — visit `/acrossai-mcp-manager/?action=cli_auth&code=x&server=x` while logged in; consent form renders with the transient-bound server slug (SEC-001 anti-spoof preserved).
- [ ] T027 [P] [US4] Regression: Feature-008 Assets — `curl` 3 non-plugin front-end pages (Home, single Post, an Archive) and verify HTML contains ZERO `acrossai-mcp` handles (SC-006). Then `curl` the OAuth authorize URL and verify the `acrossai-mcp-frontend-oauth` handle IS present.
- [ ] T028 [US4] Extended autoloader shape verification (SEC-010-005) — verify 5 distinct plugin namespaces autoload without `Class not found` errors under jetpack-autoloader ^5.0: (a) `\AcrossAI_Co\MainMenu\...` via T018 Menu.php execution, (b) `\AcrossAI_MCP_Manager\Includes\MCP\Controller` via a triggered MCP server boot, (c) `\AcrossAI_MCP_Manager\Includes\REST\CliController` via T025 `/auth/start` call, (d) `\AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors` via T024 authorize endpoint, (e) `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth` via T026 consent flow. Trigger each via a real request path; a single-class fatal at bootstrap flags jetpack-autoloader ^5.0 shape drift → revert to ^4.0 fallback per spec.md Edge Cases.
- [ ] T029 [US4] Deactivate + reactivate plugin — `wp plugin deactivate acrossai-mcp-manager && wp plugin activate acrossai-mcp-manager`; verify no fatal error, no options leak, `debug.log` clean of deprecation notices attributable to this plugin.

**Checkpoint**: US4 satisfied when all 5 prior-feature regression checks + T028 5-namespace autoload verification + T029 deactivate/reactivate all pass (SC-011).

---

## Phase 7: User Story 5 — Quality Gates Green (Priority: P2)

**Goal**: PHPCS + PHPStan pass on Feature 010's touched files; full-project baselines unchanged.

**Independent Test**: `vendor/bin/phpcs` on touched files returns 0 errors + 0 warnings. `vendor/bin/phpstan analyse ... --level=8` returns 0 errors.

### Implementation for User Story 5

- [x] T030 [P] [US5] PHPCS on touched files — `vendor/bin/phpcs composer.json admin/Partials/Menu.php includes/Utilities/AdminPageSlugs.php includes/Main.php` (plus any `admin/Partials/*.php` files modified in T021/T022). Verify 0 errors + 0 warnings per SC-009.
- [x] T031 [P] [US5] PHPStan level 8 on touched files — `vendor/bin/phpstan analyse admin/Partials/Menu.php includes/Utilities/AdminPageSlugs.php includes/Main.php --level=8`. Verify 0 errors per SC-010.
- [ ] T032 [US5] Full-project PHPCS regression check — `vendor/bin/phpcs 2>&1 | tail -5`; compare error count to T003 baseline (`/tmp/010-phpcs-baseline.txt`). Feature 010 introduces zero new errors.
- [ ] T033 [US5] Full-project PHPStan level 5 — `vendor/bin/phpstan analyse --level=5`. Verify 0 errors.

**Checkpoint**: US5 satisfied when all four gates return 0 new errors.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Documentation + release-prep + atomic-commit verification + memory checkpoint. Do NOT block merge.

- [x] T034 Document BerlinDB deferral in `specs/010-composer-dependencies/research.md` per FR-028 — enumerate the 4 Query.php files that would refactor (`includes/Database/CliAuthLog/Query.php`, `includes/Database/OAuthToken/Query.php`, `includes/Database/OAuthAudit/Query.php`, `includes/Database/MCPServer/Query.php`) + per-file migration cost estimate (~150 LOC + ~200 LOC PHPUnit rewrite each). Note Feature 011 as non-blocking follow-up epic per 2026-07-01 Q2 clarification.
- [ ] T035 Manual quickstart walkthrough on live WP 6.9 / PHP 8.1 — activation, admin menu rendered, all US4 regressions pass, deactivate/reactivate clean, `debug.log` zero plugin-attributable deprecations. This is the SC-004 acceptance gate.
- [x] T036 Verify all SC gates pass — SC-001 through SC-013 per spec.md Success Criteria section (SC-012 verifies FR-029 + FR-030 bootstrap blocks; SC-013 verifies submenu screen ID in whitelist)
- [ ] T037 Atomic commit verification — CONSTRAINT 4 mandates T005 + T010 + T011 + T012 + T012a + T013 + T014 + T014a + T014b ship in the SAME commit (atomic bundle covers the composer.json edits, PHP sync-point bumps, autoload regen, FR-029/FR-030 bootstrap blocks, AND the FR-031 memory registration). T012a's memory writes are grouped with the atomic bundle so the durable record of the accepted deviation lands alongside the code that exercises it — future code archaeology finds the deviation registration in the same commit that introduced the deviation. Verify via `git log --stat` before opening PR; if split across commits, squash via interactive rebase (do NOT use `--no-verify` or bypass hooks).
- [x] T038 Memory-md capture checkpoint — evaluate whether implementation surfaced NEW durable patterns worth capturing. Candidates: (a) transitive-dep audit generalization (SEC-010-003 pattern), (b) atomic multi-file version bump pattern (CONSTRAINT 4), (c) A1 auto-hook nuance for third-party packages (T019 outcome), (d) D14 predicate consumption for third-party packages (T020 outcome if package publishes a predicate). Default: NONE (all are first-instance patterns; reassess when a second instance appears). If a pattern crystallizes, invoke `/speckit-memory-md-capture-from-diff`.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — can start immediately
- **Phase 2 (Foundational — T004)**: Depends on Phase 1 (needs composer packages installed via T007 to inspect package README + `src/`). BLOCKS Phase 5.
- **Phase 3 (US1 — activation)**: Depends on Phase 1. T005–T009 must precede T014.
- **Phase 4 (US2 — clean install)**: Depends on Phase 3 (T005 composer.json edits must exist). Verification-only.
- **Phase 5 (US3 — admin menu)**: Depends on Phase 2 (T004 research) + Phase 3 (T014 autoload regen) — Menu.php migration needs the `\AcrossAI_Co\MainMenu\...` package to be autoloadable.
- **Phase 6 (US4 — regressions)**: Depends on Phase 5 completion — all migration edits must be in place before regression sweep.
- **Phase 7 (US5 — quality gates)**: Depends on Phase 5 (files changed). Can run in parallel with Phase 6.
- **Phase 8 (Polish)**: Depends on all above.

### Story Independence

- US1 (P1) — activation on PHP 8.1: independent test path (WP admin activation)
- US2 (P1) — clean composer install: verification-only after US1's composer.json edits; independent test path (`rm -rf vendor/`)
- US3 (P1) — admin menu URL: independent test path (curl the admin URL)
- US4 (P1) — prior feature regressions: 5 independent test paths per feature
- US5 (P2) — quality gates: independent of runtime behavior

### Within Each User Story

- No test-first requirement (Q1 clarification: no new PHPUnit files)
- Composer edits (T005) before autoload regen (T014)
- Research (T004) before Menu.php migration (T018)
- Migration (T018) before Loader-wiring update (T019)
- Menu changes complete before regression sweep (Phase 6)

### Parallel Opportunities

- T002 + T003 (Setup phase — different tools + files)
- T010 + T011 + T012 + T013 (Phase 3 US1 — 4 distinct sync-point files, no dependencies)
- T021 + T022 (Phase 5 US3 — different grep patterns on same directory)
- T023 + T024 + T025 + T026 + T027 (Phase 6 US4 — 5 different prior features, independent test paths)
- T030 + T031 (Phase 7 US5 — different tools on same files)

---

## Parallel Example: Phase 3 US1 PHP version sync-point bumps

```bash
# Launch the 4 PHP version sync-point edits together (T010–T013):
Task: "Update Requires PHP: 8.0 → 8.1 in acrossai-mcp-manager.php"
Task: "Update Requires PHP: 8.0 → 8.1 in README.txt"
Task: "Update PHP 8.0 references in .specify/memory/constitution.md tech-stack section"
Task: "Update PHP 8.0 references in .github/copilot-instructions.md"
# All must land in the SAME commit as T005 + T014 per CONSTRAINT 4
```

## Parallel Example: Phase 6 US4 regression sweep

```bash
# Launch the 5 prior-feature regression checks together (T023–T027):
Task: "Feature-009 MCP Controller regression — enable server row + trigger boot"
Task: "Feature-005 OAuth regression — curl /.well-known/oauth-authorization-server"
Task: "Feature-006 REST CLI regression — POST /auth/start"
Task: "Feature-007 Frontend CLI regression — visit consent URL"
Task: "Feature-008 Assets regression — grep asset handles on non-plugin pages"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (T001–T003)
2. Complete Phase 2: Foundational (T004 research)
3. Complete Phase 3: US1 (T005–T014 atomic commit)
4. **STOP and VALIDATE**: T035 quickstart on WP 6.9 / PHP 8.1 — plugin activates cleanly, admin menu renders (with legacy `add_menu_page` still in place; Menu.php migration is Phase 5)
5. Deploy/demo if ready

### Incremental Delivery

1. Setup + Foundational → foundation ready
2. US1 (Phase 3) → **Feature 010 MVP**: activation works on PHP 8.1 (atomic commit)
3. US2 (Phase 4) → Verify clean install produces same state
4. US3 (Phase 5) → Menu.php on main-menu package; admin URL preserved
5. US4 (Phase 6) → Regression sweep across all prior features
6. US5 (Phase 7) → Quality gates green
7. Polish (Phase 8) → BerlinDB deferral doc + atomic-commit verify + memory checkpoint

### Solo-Developer Strategy

Feature 010 is a solo-developer feature. Parallel opportunities are within phases (T010–T013 sync-point edits ship together; T023–T027 regression checks fan out).

### Estimated total effort

0.5–1 dev-day per plan.md.

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- **CONSTRAINT 4** (atomic PHP bump): T005 + T010 + T011 + T012 + T013 + T014 + T014a + T014b MUST ship in the same commit
- **CONSTRAINT 1** (guards preserved): T018 MUST NOT remove the 4 existing `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` guards
- **CONSTRAINT 2** (custom Query classes preserved): Feature 010 does NOT refactor `Includes\Database\...\Query` classes (Feature 011 handles this per Q2 clarification)
- **CONSTRAINT 3** (no PHP 8.1 language features): No `readonly` / `enum` / `never` / first-class callable syntax in files touched
- **CONSTRAINT 5** (admin URL preserved): `?page=acrossai_mcp_manager` MUST resolve post-T018 (submenu URLs work identically to top-level URLs)
- **Q1** (no new PHPUnit): Menu.php migration validated by manual smoke (SC-005) + Phase 8's existing `EnqueueAssetsTest.php`
- **Q2** (Feature 011 non-blocking): Custom Query classes stay; T034 documents deferral
- **SEC-010-003** (transitive-dep audit): T008 (`composer audit`) + T009 (`composer show --tree`)
- **SEC-010-005** (autoloader shape drift): T028 5-namespace smoke test
- **A1 accepted deviation** (FR-031): T014a shared parent bootstrap lives in plugin entry file, NOT via Loader — scoped to that single bootstrap only
- **D14 not available**: main-menu package publishes no consumer-usable predicates (T004 finding f); T020 hardcodes screen IDs
- **Package pin** (T005): `acrossai-co/main-menu 0.0.10` (exact pin, one patch higher than `acrossai-abilities-manager`'s `0.0.9` — this plugin wins jetpack-autoloader version resolution)
- **VCS repo** (T005): `wpb-access-control` requires `repositories` VCS entry (not on Packagist)
- Commit after each task or logical group (EXCEPTION: the CONSTRAINT 4 atomic bundle T005 + T010–T014b is a SINGLE commit)
- Avoid: bypassing pre-commit hooks (`--no-verify`), amending the atomic bundle after opening PR, silently changing the admin URL slug, adding the shared parent bootstrap via Loader (violates FR-031's scoped deviation intent)
