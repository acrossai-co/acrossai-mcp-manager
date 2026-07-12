# Feature Specification: Register shared AcrossAI Add-ons submenu

**Feature Branch**: `022-addons-page-registration`
**Created**: 2026-07-12
**Status**: Draft
**Input**: User description: See `docs/planings-tasks/022-addons-page-registration.md`

## Clarifications

### Session 2026-07-12

- Q: What Freemius credentials should the plugin pass to `\AcrossAI_Addon\AddonsPage`? → A: **User-provided real credentials**: `fs_product_id = 34418`, `fs_public_key = pk_d61a7ddb1a619f7697fbb4fc397b6`, `fs_slug = acrossai-add-ons` (deliberately distinct from the plugin's own slug — the Freemius product represents the shared add-ons surface for AcrossAI, not the plugin itself). Stored inline in `includes/Main.php` alongside the sibling plugin's precedent — no filter or option-based indirection.
- Q: Do we want Freemius's Account / Contact Us / wp.org Support Forum auto-submenus visible under the AcrossAI menu? → A: **Yes — all three.** Since the vendored `\AcrossAI_Addon\FreemiusInitializer` hardcoded all three to `false`, this feature bumps `acrossai-co/main-menu` from `0.0.14` to `0.0.15` (upstream commit `a58dec9`) which flipped the three defaults to `true` at the package level. `upgrade`, `pricing`, and `addons` stay `false` (pricing/upgrade are handled by the Add-ons page itself; a second `addons` submenu would duplicate the vendor's own Add-ons registration).
- Q: For product 34418 specifically, which Freemius auto-submenus should surface? → A: **All six on** — `account`, `contact`, `support`, `upgrade`, `pricing`, `addons` all `true`. The plugin's `fs_menu` block in `includes/Main.php` spells out every key explicitly so the visible policy lives at the call site — flip any boolean there to change operator UX without a vendor release. The vendor's `FreemiusInitializer::DEFAULT_MENU` is a fallback for consumer plugins that don't pass `fs_menu` at all — this plugin declares its own values so it never inherits from the fallback.
- Q: Should the menu-submenu decision live at the package level (one global default for every consumer plugin) or as a per-consumer knob? → A: **Per-consumer knob.** Bumps `acrossai-co/main-menu` from `0.0.15` to `0.0.16` (upstream commit `0fb50ea`) which introduces an optional `fs_menu` key on `AddonsPage`'s `$args` array. The vendor's `FreemiusInitializer::DEFAULT_MENU` constant holds the fallback defaults (account/contact/support = true; upgrade/pricing/addons = false); the consumer's `fs_menu` array is `array_merge`d over those defaults. Unknown keys pass through so future Freemius menu-config extensions work without a package bump. The `slug` key cannot be overridden this way — it is derived from the `$parent_slug` constructor argument. This plugin passes an explicit `fs_menu` array in `includes/Main.php` that mirrors the vendor defaults, so the choice is visible at the call site rather than relying on an implicit inheritance.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site administrator activates AcrossAI MCP Manager and sees the shared Add-ons page (Priority: P1)

A site administrator installs and activates `acrossai-mcp-manager` on their WordPress 7.0 site. Once activation completes, they look at the WordPress admin sidebar and see the shared **AcrossAI** top-level menu (contributed by the vendored `acrossai-co/main-menu` package). Underneath it they find the **Add-ons** submenu — same as the one that appears when `acrossai-abilities-manager` is installed. Clicking it takes them to `?page=acrossai-addons`, which renders the Freemius-backed add-ons grid scoped to this plugin's Freemius product (id `34418`).

**Why this priority**: This is the entire feature. The submenu simply must appear when the plugin is active. Without it, add-ons are undiscoverable and the user's reported bug ("I do not see the add-on pages when I just activate this plugin") is not resolved.

**Independent Test**: Fresh WP 7.0 install with `acrossai-mcp-manager` only (no sibling AcrossAI plugin active). Deactivate + reactivate the plugin. Reload wp-admin as a `install_plugins`-capable user. Assert the **AcrossAI → Add-ons** submenu is present and the direct URL `/wp-admin/admin.php?page=acrossai-addons` renders without a "You do not have permission" or 404-like screen.

**Acceptance Scenarios**:

1. **Given** the plugin is freshly activated on a site where no other AcrossAI plugin is active, **When** an `install_plugins`-capable user loads wp-admin, **Then** the **AcrossAI → Add-ons** submenu appears in the sidebar and `?page=acrossai-addons` renders the vendor's Add-ons page.
2. **Given** the plugin is active alongside `acrossai-abilities-manager`, **When** an `install_plugins`-capable user loads wp-admin, **Then** exactly ONE **Add-ons** submenu row appears (the vendor's `MenuRegistrar::$registered` process-wide guard coordinates this — whichever plugin registers first wins the nav slot; the second plugin still contributes its Freemius product config).
3. **Given** the plugin is active but the current user lacks `install_plugins`, **When** they load wp-admin, **Then** the **Add-ons** submenu is hidden and visiting `/wp-admin/admin.php?page=acrossai-addons` returns "Sorry, you are not allowed to access this page." (WordPress's default capability-denied screen).
4. **Given** the vendored `acrossai-co/main-menu` package has been stripped from a build (`vendor/acrossai-co/main-menu/` absent), **When** the plugin loads, **Then** the `class_exists` guard skips instantiation silently, no PHP fatal fires, and the rest of the plugin's admin surface still boots normally (Constitution §V Integration Resilience).

---

### User Story 2 — Bad or missing Freemius credentials degrade gracefully (Priority: P2)

A future maintainer accidentally blanks one of the Freemius credentials in `includes/Main.php` (or a build-time patcher truncates the values). The `\AcrossAI_Addon\AddonsPage::__construct()` throws `InvalidArgumentException`. Instead of the entire wp-admin fataling, the try/catch fires an admin-notices closure that prints a red banner (only to `manage_options` users) with the exception message, and every other plugin surface — MCP tab, Settings page, CLI Auth log — continues to work.

**Why this priority**: This is the safety net. It's not the happy path but it prevents a credential typo from bricking wp-admin on every site the plugin is active on.

**Independent Test**: Comment out or blank the `'fs_product_id'` value in `includes/Main.php`. Reload wp-admin as an admin. Assert (a) no PHP fatal appears, (b) a `notice-error` banner renders at the top of the admin screen with the AddonsPage constructor's exception message, (c) the Add-ons submenu does NOT appear, (d) the rest of the plugin (MCP Manager submenu, Settings page) still loads.

**Acceptance Scenarios**:

1. **Given** `fs_product_id` is empty at load time, **When** `Main::define_admin_hooks()` runs, **Then** the `AddonsPage` constructor throws `InvalidArgumentException` and the caught exception message renders as a `notice-error` admin notice for `manage_options` users only.
2. **Given** the WordPress version is below 6.0, **When** `Main::define_admin_hooks()` runs, **Then** the `AddonsPage` constructor throws `RuntimeException` and the same graceful admin-notice fallback fires.
3. **Given** an Editor (no `manage_options`) is logged in when either exception fires, **When** they load wp-admin, **Then** they do NOT see the error notice (avoid leaking internal messages to non-admins).

---

## Functional Requirements *(mandatory)*

- **FR-001**: The plugin MUST instantiate `\AcrossAI_Addon\AddonsPage` exactly once per request during admin-menu wiring, inside `AcrossAI_MCP_Manager\Includes\Main::define_admin_hooks()`.
- **FR-002**: Instantiation MUST occur AFTER the existing `$settings_menu` wiring (`Main.php:350-352`) and BEFORE the "Admin notices" wiring block (`Main.php:354+`), so it participates in the same admin-menu ordering as the sibling plugin's precedent.
- **FR-003**: The `AddonsPage` constructor MUST receive `ACROSSAI_MCP_MANAGER_PLUGIN_FILE` as its first positional argument (the consumer main-file path) so the vendor's URL/directory helpers resolve against this plugin's install path.
- **FR-004**: The `AddonsPage` constructor MUST receive an `$args` array with the four keys: `fs_product_id = '34418'`, `fs_public_key = 'pk_d61a7ddb1a619f7697fbb4fc397b6'`, `fs_slug = 'acrossai-add-ons'`, and `fs_menu` (associative array declaring the plugin's intent for each Freemius auto-submenu — see FR-016). Values are stored inline (no runtime lookup, no filter indirection).
- **FR-005**: The instantiation MUST be wrapped in a `class_exists( \AcrossAI_Addon\AddonsPage::class )` guard so a stripped or unavailable vendor package degrades silently.
- **FR-006**: The instantiation MUST be wrapped in a `try { ... } catch ( \Throwable $e ) { ... }`. On catch, the plugin MUST register an `admin_notices` closure that (a) short-circuits when `current_user_can( 'manage_options' )` is false, and (b) prints a `notice-error` div containing the exception message passed through `esc_html()`.
- **FR-007**: The plugin MUST NOT re-register any of the six hooks that `AddonsPage::boot()` (`AddonsPage.php:104-115`) already owns (`admin_menu` @ 20, `admin_menu` @ 21, `admin_init`, `admin_enqueue_scripts`, `admin_notices`, four `wp_ajax_acrossai_addons_*` actions, one `admin_post_acrossai_addons_connect_again`).
- **FR-008**: The plugin MUST NOT modify vendor code under `vendor/acrossai-co/main-menu/`.
- **FR-009**: The plugin MUST NOT modify the shared parent slug `'acrossai'`. The parent menu bootstrap in `acrossai-mcp-manager.php:149-161` stays unchanged.
- **FR-010**: `README.txt` MUST document the new submenu as a bullet under `= Unreleased =`.
- **FR-011**: `docs/planings-tasks/README.md` MUST list Feature 022 in the Feature Specs table.
- **FR-012**: When `docs/memory/DECISIONS.md` exists in the repo, a `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT (Active — Feature 022)` entry MUST be added explaining that `AddonsPage` self-registers hooks in its constructor and therefore is exempted from the Boot Flow Rule that otherwise routes hooks through `Main.php`'s Loader. Rationale: mirrors the sibling plugin's DEC-EXTERNAL-PACKAGE-HOOK-CTOR.
- **FR-013**: When `docs/memory/INDEX.md` exists, a one-line row referring to DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT MUST be appended under Active Decisions.
- **FR-014**: `composer.json` MUST bump `acrossai-co/main-menu` from `0.0.14` to `0.0.16` so the vendor's `fs_menu` override API + flipped-on `account` / `contact` / `support` defaults reach production.
- **FR-015**: `composer.json` MUST add an explicit `repositories` VCS entry for `https://github.com/acrossai-co/main-menu` so the resolver does not depend on Packagist sync lag when future tags land.
- **FR-016**: The plugin MUST pass an explicit `fs_menu` array to `AddonsPage`'s `$args` with all six standard Freemius menu keys declared — `account`, `contact`, `support` set to `true` (visible operator surfaces) and `upgrade`, `pricing`, `addons` set to `false` (owned by the Add-ons page itself; the vendor's Add-ons submenu would collide with a Freemius-added one). The explicit form is preferred over inheriting `FreemiusInitializer::DEFAULT_MENU` so future maintainers see the intent at the call site.

## Non-Goals *(mandatory)*

- **NG-001**: This feature does NOT add new abilities, MCP servers, OAuth flow, or database tables. Zero schema impact.
- **NG-002**: This feature does NOT change the shared **AcrossAI** parent menu or any of its non-Add-ons submenus (MCP Manager, CLI Auth Log, Settings tabs).
- **NG-003**: This feature does NOT ship or register any Freemius add-on plugin definitions. The base plugin's Add-ons page will show either "No add-ons yet" or Freemius-provided add-ons depending on what has been published on the Freemius side for product id 34418. Populating the add-on catalog is out of scope.
- **NG-004**: This feature does NOT alter the `install_plugins` capability requirement enforced by `MenuRegistrar.php:39`. Sites that hide `install_plugins` from non-superadmins keep that behavior.
- **NG-005**: This feature does NOT introduce a filter or option-based indirection for the Freemius credentials. Values are hardcoded inline (matches sibling plugin pattern; makes credential audit trivial).

## Definition of Done Gates *(mandatory)*

- [ ] `composer run phpcs` returns zero errors on `includes/Main.php`.
- [ ] `composer run phpstan` at level 8 returns zero errors on `includes/Main.php`.
- [ ] `php -l includes/Main.php` reports "No syntax errors detected".
- [ ] Manual E2E on the running WP 7.0 install: submenu appears, page renders for admin, is hidden for Editor, credential-blank test path fires the graceful admin notice.
- [ ] Pre-flight grep of `loader->add_action|loader->add_filter` inside `define_admin_hooks()` returns the same call list in the same order as before the change (proves no adjacent wiring disturbed).
- [ ] `README.txt` Unreleased changelog bullet present.
- [ ] `docs/planings-tasks/README.md` Feature 022 row present.
- [ ] DEC entry present in `docs/memory/DECISIONS.md` and rowed in `docs/memory/INDEX.md` if those files exist.

## Success Criteria *(mandatory)*

- **SC-001**: The user's reported symptom is gone — `/wp-admin/admin.php?page=acrossai-addons` renders the vendor's Add-ons page after a fresh activation, without any other AcrossAI plugin being present.
- **SC-002**: When both `acrossai-mcp-manager` and `acrossai-abilities-manager` are active, exactly one **Add-ons** nav row appears (no duplicate menu rows). Verified visually + via a WP-CLI `wp menu list --format=count`-equivalent inspection of the `$submenu` global.
- **SC-003**: When either Freemius credential is truncated to empty in `includes/Main.php`, wp-admin does NOT fatal for any user role; instead, admins see a red banner with the constructor's exception message and Editors see nothing.
- **SC-004**: The pre-flight grep and post-implement grep of `loader->add_action|loader->add_filter` inside `Main::define_admin_hooks()` return byte-identical call listings — proves TASK-1 did not disturb the surrounding wiring.

## Assumptions

- The vendored `acrossai-co/main-menu` package is present at `vendor/acrossai-co/main-menu/` in every distributed build. The `class_exists` guard covers the strip case, but the happy path assumes the package ships.
- The Freemius credentials (`34418`, `pk_d61a7ddb1a619f7697fbb4fc397b6`) are the correct pair for the "AcrossAI MCP Manager" Freemius product owned by the user. If Freemius flags them as mismatched, the constructor still succeeds (Freemius init does not validate credentials at construction time); only interactions with the Freemius product hub (opt-in, license activation, add-on installation) would then fail — that failure surfaces inside the Add-ons page itself, not on plugin boot.
- `Main::define_admin_hooks()` remains the correct wiring surface. If a future refactor moves admin-menu wiring elsewhere (e.g. to a dedicated `AdminBootstrap` class), the AddonsPage instantiation moves with it.

## Accepted Deviations

- **DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT (this feature)** — Accepted deviation from Boot Flow Rule AC-HOOKS-MAIN. The `\AcrossAI_Addon\AddonsPage` constructor self-registers all its WordPress hooks. Consumer code cannot route those hooks through `Main.php`'s Loader because the vendor's public API does not expose per-hook registration methods. Consumers instead call `new AddonsPage(...)` once inside `Main::define_admin_hooks()`. Mirrors the sibling plugin's `DEC-EXTERNAL-PACKAGE-HOOK-CTOR` (Feature 038).
