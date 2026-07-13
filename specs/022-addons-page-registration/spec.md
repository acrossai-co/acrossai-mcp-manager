# Feature Specification: Register shared AcrossAI Add-ons submenu

**Feature Branch**: `022-addons-page-registration`
**Created**: 2026-07-12
**Status**: Draft
**Input**: User description: See `docs/planings-tasks/022-addons-page-registration.md`

## Clarifications

### Session 2026-07-12 / 2026-07-13

Rationale for the answers below is captured once in §Umbrella-product design, then referenced from each Q entry. Reading order: skim these questions first for the decisions, then read §Umbrella-product design for the "why".

- Q1: What Freemius credentials for `\AcrossAI_Addon\AddonsPage`? → A: `fs_product_id = 34418`, `fs_public_key = pk_d61a7ddb1a619f7697fbb4fc397b6`, `fs_slug = acrossai-add-ons`. Inline in `includes/Main.php`. See §Umbrella-product design for the fs_slug ≠ plugin-slug divergence.
- Q2: Should Freemius' Account / Contact Us / Support submenus surface? → A: Yes — enable via vendor default flip. Vendor 0.0.15 promoted the three defaults from `false` to `true`; this plugin then declares them explicitly via the `fs_menu` override introduced in 0.0.16. See §Umbrella-product design.
- Q3: Which `fs_menu` values for product 34418 specifically? → A: `account/contact/addons` on; `support/pricing/upgrade` off. See §Umbrella-product design for per-key rationale.
- Q4: Should menu-submenu decisions live per-consumer or per-package? → A: Per-consumer via a new `fs_menu` key on `AddonsPage`'s `$args` (introduced in vendor 0.0.16). Vendor's `FreemiusInitializer::DEFAULT_MENU` is a fallback; consumers who don't pass `fs_menu` inherit sensible defaults.

### Umbrella-product design

The Freemius product `34418` (`fs_slug = acrossai-add-ons`) is deliberately named for the umbrella, not for this plugin. The AcrossAI ecosystem centralizes add-on discovery, licensing, and support surfaces at one shared Freemius product so operators of any AcrossAI plugin see the same Add-ons page, the same Account/licenses page, and the same Contact surface. Per-plugin support content lives in each plugin's own main-menu pages — hence `fs_menu.support = false` on the umbrella (Freemius' Support submenu just links to `wp.org/support/plugin/<fs_slug>`; the umbrella isn't on WP.org so the link would be dead).

`fs_menu.pricing` and `fs_menu.upgrade` stay `false` because pricing/upgrade is a per-add-on concern, not an umbrella one. With `has_paid_plans => false` on the umbrella, Freemius would render its "no paid plans" placeholder for those rows anyway.

`fs_menu.addons = true` (plus `fs_has_addons = true` to satisfy the SDK's `has_addons()` gate) is the whole point of the umbrella: the ONE Add-ons page for the ecosystem. Vendor 0.0.17 disabled the pre-existing custom `MenuRegistrar::register()` Add-ons submenu so exactly one Add-ons row appears, sourced from Freemius. The `fs_menu` block in `includes/Main.php` spells out all six standard keys so the full menu policy is visible at the call site — flip any boolean there to change what operators see, no vendor release required.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Site administrator activates AcrossAI MCP Manager and sees the shared Add-ons page (Priority: P1)

A site administrator installs and activates `acrossai-mcp-manager` on their WordPress 7.0 site. Once activation completes, they look at the WordPress admin sidebar and see the shared **AcrossAI** top-level menu (contributed by the vendored `acrossai-co/main-menu` package). Underneath it they find the **Add-ons** submenu — same as the one that appears when `acrossai-abilities-manager` is installed. Clicking it takes them to `?page=acrossai-addons`, which renders the Freemius-backed add-ons grid scoped to this plugin's Freemius product (id `34418`).

**Why this priority**: This is the entire feature. The submenu simply must appear when the plugin is active. Without it, add-ons are undiscoverable and the user's reported bug ("I do not see the add-on pages when I just activate this plugin") is not resolved.

**Independent Test**: Fresh WP 7.0 install with `acrossai-mcp-manager` only (no sibling AcrossAI plugin active). Deactivate + reactivate the plugin. Reload wp-admin as a `install_plugins`-capable user. Assert the **AcrossAI → Add-ons** submenu is present and the direct URL `/wp-admin/admin.php?page=acrossai-addons` renders without a "You do not have permission" or 404-like screen.

**Acceptance Scenarios**:

1. **Given** the plugin is freshly activated on a site where no other AcrossAI plugin is active, **When** an `install_plugins`-capable user loads wp-admin, **Then** the **AcrossAI → Add-ons** submenu appears in the sidebar and `?page=acrossai-addons` renders the vendor's Add-ons page.
2. **Given** the plugin is active alongside `acrossai-abilities-manager`, **When** an `install_plugins`-capable user loads wp-admin, **Then** exactly ONE **Add-ons** submenu row appears. Since vendor 0.0.17 commented out `MenuRegistrar::register()`'s `add_submenu_page()` call, dedup responsibility has shifted from the vendor to the Freemius SDK: each plugin calls `fs_dynamic_init()` for its own product ID and Freemius adds an Add-ons submenu tied to that product's `fs_slug`. When both plugins target the same umbrella product (`fs_slug = acrossai-add-ons`, id 34418), Freemius' internal instance memoization means only ONE menu registration fires — the row is authored by whichever plugin's `AddonsPage` boots first.
3. **Given** the plugin is active but the current user lacks `install_plugins`, **When** they load wp-admin, **Then** the **Add-ons** submenu is hidden and visiting `/wp-admin/admin.php?page=acrossai-addons` returns "Sorry, you are not allowed to access this page." (WordPress's default capability-denied screen).
4. **Given** the vendored `acrossai-co/main-menu` package has been stripped from a build (`vendor/acrossai-co/main-menu/` absent), **When** the plugin loads, **Then** the `class_exists` guard skips instantiation silently, no PHP fatal fires, the Add-ons submenu row silently disappears (Freemius init never runs, so no `fs_menu.addons`/`fs_has_addons` chain fires), and the rest of the plugin's admin surface (MCP Manager, Settings, CLI Auth Log, OAuth) still boots normally (Constitution §V Integration Resilience). No admin notice is shown for the missing vendor case — it is a valid deployment shape.

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

### User Story 3 — Site administrator completes Freemius opt-in and Account submenu appears (Priority: P1)

A site administrator activates `acrossai-mcp-manager`. Freemius' opt-in card renders on the plugins.php redirect screen. The admin clicks **Allow & Continue** (the wp.org-compliant double-opt-in path — Freemius product 34418 has `is_wp_org_compliant: true`), which triggers Freemius to email a confirmation link to the currently-logged-in WordPress user's email address (`wp_users.user_email`, NOT `wp_options.admin_email`). The admin clicks the link inside that email, which round-trips through `checkout.freemius.com` and lands back at `wordpress-7-0.local/wp-admin/...` with a success indicator. Post-confirmation, Freemius stores `sites` and `users` entries at the top level of `wp_options.fs_accounts`, `is_registered()` returns `true`, and the **Account** submenu appears under AcrossAI alongside Add-Ons + Contact Us on the next admin page load.

**Why this priority**: SC-001's "add-ons grid renders" pass condition is *actually* gated on opt-in completion — a fresh install can pass every static check in US1 while still failing SC-001 because the grid stays hidden behind Freemius' unauthenticated placeholder. US3 makes that flow explicit and testable so the SC-001 pass is an actual end-user pass, not a code-shipped-but-invisible pass.

**Independent Test**: Reset Freemius state (`DELETE FROM wp_options WHERE option_name LIKE 'fs_%';`), reactivate the plugin, walk the opt-in card + email confirmation, then run the sanity SQL below and confirm `has_sites = 1` AND `has_users = 1`.

```sql
SELECT option_value LIKE '%"sites"%' AS has_sites,
       option_value LIKE '%"users"%' AS has_users
FROM wp_options WHERE option_name = 'fs_accounts';
```

**Acceptance Scenarios**:

1. **Given** a fresh (or reset) Freemius state on the site, **When** the plugin is activated, **Then** the Freemius opt-in card rendering "Thank you for updating to AcrossAI MCP Manager..." with **Allow & Continue** + **Skip** buttons appears on the plugins.php landing screen.
2. **Given** the opt-in card is visible, **When** the admin clicks **Allow & Continue**, **Then** a confirmation email is queued to the current WordPress user's email address (as read from `wp_users.user_email`, not `admin_email`) from `no-reply@freemius.com` (or another Freemius sender) with a subject containing "confirm" and the plugin name.
3. **Given** the confirmation email arrives, **When** the admin clicks the confirmation link, **Then** the browser round-trips through `checkout.freemius.com` and lands back at a wp-admin URL, `wp_options.fs_accounts` gains top-level `sites` + `users` keys, `is_registered()` returns `true`, and the **Account** submenu appears under AcrossAI on the next admin page load.
4. **Given** the admin clicks **Skip** instead of **Allow & Continue**, **Then** Freemius marks the site anonymous (`is_anonymous() = true`), `sites`/`users` are NOT stored, and the Account submenu remains hidden by design. This is the documented "no Account without opt-in" behavior — not a bug.
5. **Given** the LocalWP `.local` site cannot reach `api.freemius.com` (typical on air-gapped dev environments), **When** the admin clicks **Allow & Continue**, **Then** Freemius' `WP_FS__DEV_MODE` fallback (defined in `wp-config.php` per the localhost guidance) permits the flow to continue — the confirmation email still queues via Freemius' server, so email delivery is the only hard external dependency.

---

## Functional Requirements *(mandatory)*

- **FR-001**: The plugin MUST instantiate `\AcrossAI_Addon\AddonsPage` exactly once per request during admin-menu wiring, inside `AcrossAI_MCP_Manager\Includes\Main::define_admin_hooks()`.
- **FR-002**: Instantiation MUST occur AFTER the existing `$settings_menu` wiring (`Main.php:350-352`) and BEFORE the "Admin notices" wiring block (`Main.php:354+`), so it participates in the same admin-menu ordering as the sibling plugin's precedent.
- **FR-003**: The `AddonsPage` constructor MUST receive `ACROSSAI_MCP_MANAGER_PLUGIN_FILE` as its first positional argument (the consumer main-file path) so the vendor's URL/directory helpers resolve against this plugin's install path.
- **FR-004**: The `AddonsPage` constructor MUST receive an `$args` array with five keys: `fs_product_id = '34418'`, `fs_public_key = 'pk_d61a7ddb1a619f7697fbb4fc397b6'`, `fs_slug = 'acrossai-add-ons'`, `fs_menu` (associative array declaring the plugin's intent for each Freemius auto-submenu — see FR-016), and `fs_has_addons = true` (required per FR-014's main-menu 0.0.18 upgrade — Freemius SDK gates its Add-ons submenu on `has_addons()` at `class-freemius.php:18964`, so `fs_menu.addons = true` alone is insufficient). Values are stored inline (no runtime lookup, no filter indirection).
- **FR-005**: The instantiation MUST be wrapped in a `class_exists( \AcrossAI_Addon\AddonsPage::class )` guard so a stripped or unavailable vendor package degrades silently.
- **FR-006**: The instantiation MUST be wrapped in a `try { ... } catch ( \Throwable $e ) { ... }`. On catch, the plugin MUST register an `admin_notices` closure that (a) short-circuits when `current_user_can( 'manage_options' )` is false, and (b) prints a `notice-error` div containing the exception message passed through `esc_html()`.
- **FR-007**: The plugin MUST NOT re-register any of the six hooks that `AddonsPage::boot()` (`AddonsPage.php:104-115`) already owns (`admin_menu` @ 20, `admin_menu` @ 21, `admin_init`, `admin_enqueue_scripts`, `admin_notices`, four `wp_ajax_acrossai_addons_*` actions, one `admin_post_acrossai_addons_connect_again`).
- **FR-008**: The plugin MUST NOT modify vendor code under `vendor/acrossai-co/main-menu/`.
- **FR-009**: The plugin MUST NOT modify the shared parent slug `'acrossai'`. The parent menu bootstrap in `acrossai-mcp-manager.php:149-161` stays unchanged.
- **FR-010**: `README.txt` MUST document the new submenu as a bullet under `= Unreleased =`.
- **FR-011**: `docs/planings-tasks/README.md` MUST list Feature 022 in the Feature Specs table.
- **FR-012**: When `docs/memory/DECISIONS.md` exists in the repo, a `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT (Active — Feature 022)` entry MUST be added explaining that `AddonsPage` self-registers hooks in its constructor and therefore is exempted from the Boot Flow Rule that otherwise routes hooks through `Main.php`'s Loader. Rationale: mirrors the sibling plugin's DEC-EXTERNAL-PACKAGE-HOOK-CTOR.
- **FR-013**: When `docs/memory/INDEX.md` exists, a one-line row referring to DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT MUST be appended under Active Decisions.
- **FR-014**: `composer.json` MUST bump `acrossai-co/main-menu` from `0.0.14` to `0.0.18` so the vendor's `fs_menu` override API (0.0.16), `MenuRegistrar::register()` no-op (0.0.17), and `fs_has_addons` override (0.0.18 — required to actually surface the Freemius Add-ons row per Freemius SDK's `has_addons()` gate) all reach production. The resulting `composer.lock` MUST resolve `acrossai-co/main-menu` to the git ref of tag `0.0.18` (currently commit `a6a35ff`); a bump of `composer.json` alone without a matching `composer update` + committed `composer.lock` fails this requirement.
- **FR-015**: `composer.json` MUST add an explicit `repositories` VCS entry for `https://github.com/acrossai-co/main-menu` so the resolver does not depend on Packagist sync lag when future tags land.
- **FR-016**: The plugin MUST pass an explicit `fs_menu` array to `AddonsPage`'s `$args` with all six standard Freemius menu keys declared. Values per the umbrella-product model (see Clarifications Q3): `account => true` (single license-activation surface for the ecosystem), `contact => true` (shared contact channel), `addons => true` (the point of the umbrella — one Add-ons page for all AcrossAI plugins; also requires `fs_has_addons => true` per FR-004), `support => false` (Freemius links this to `wp.org/support/plugin/<fs_slug>`; the umbrella product is not on WP.org, so the link would be dead — per-plugin support belongs in each plugin's own main-menu pages), `pricing => false` and `upgrade => false` (pricing/upgrade is per-add-on, not per-umbrella; combined with `has_paid_plans => false` on the umbrella these would render Freemius' placeholder anyway). The explicit form is preferred over inheriting `FreemiusInitializer::DEFAULT_MENU` so future maintainers see the intent at the call site and can flip any single boolean without a vendor release.

## Non-Goals *(mandatory)*

- **NG-001**: This feature does NOT add new abilities, MCP servers, OAuth flow, or database tables. Zero schema impact.
- **NG-002**: This feature does NOT change the shared **AcrossAI** parent menu or any of its non-Add-ons submenus (MCP Manager, CLI Auth Log, Settings tabs).
- **NG-003**: This feature does NOT ship or register any Freemius add-on plugin definitions. The base plugin's Add-ons page will show either "No add-ons yet" or Freemius-provided add-ons depending on what has been published on the Freemius side for product id 34418. Populating the add-on catalog is out of scope.
- **NG-004**: This feature does NOT alter the capability check that gates the Add-ons submenu. After vendor 0.0.17 no-op'd `MenuRegistrar::register()`, capability enforcement moved to the Freemius SDK's `add_submenu_item()` path (`class-freemius.php:18927` uses `manage_options`). Sites that restrict `manage_options` from certain admin roles keep that restriction untouched. The Editor role (no `manage_options`) still cannot see or reach the Add-ons submenu.
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
- **SC-002**: When both `acrossai-mcp-manager` and `acrossai-abilities-manager` are active (and both target Freemius umbrella product 34418), exactly one **Add-ons** nav row appears under AcrossAI (no duplicate). Freemius SDK's per-product-ID instance memoization is the coordination mechanism (post-0.0.17). Verified visually + via a `wp shell` snippet that inspects `$GLOBALS['submenu']['acrossai']` and counts entries whose slug ends in `-addons` — expected count is `1`.
- **SC-003**: When either Freemius credential is truncated to empty in `includes/Main.php`, wp-admin does NOT fatal for any user role; instead, admins see a red banner with the constructor's exception message and Editors see nothing.
- **SC-004**: The pre-flight grep and post-implement grep of `loader->add_action|loader->add_filter` inside `Main::define_admin_hooks()` return the **same call list in the same order with byte-identical content per line**. Line NUMBERS will shift downward by ~40 (the size of the T010 insertion) — that shift is expected and does not count as a diff. Only content differences fail the gate.

## Assumptions

- The vendored `acrossai-co/main-menu` package is present at `vendor/acrossai-co/main-menu/` in every distributed build. The `class_exists` guard covers the strip case, but the happy path assumes the package ships.
- The vendored `freemius/wordpress-sdk` package is present at `vendor/freemius/wordpress-sdk/` (installed transitively via the `acrossai-co/main-menu` composer require). If it is absent (e.g. a build minifier strips it, or `composer install --no-dev` on a broken lockfile), `FreemiusInitializer::load_sdk()` throws `RuntimeException`; the outer `try/catch` around `new AddonsPage(...)` catches it and the admin-notice fallback fires for `manage_options` users. The Add-ons submenu disappears silently — the rest of the plugin still boots.
- The Freemius credentials (`34418`, `pk_d61a7ddb1a619f7697fbb4fc397b6`) are the correct pair for the "AcrossAI MCP Manager" Freemius product owned by the user. If Freemius flags them as mismatched, the constructor still succeeds (Freemius init does not validate credentials at construction time); only interactions with the Freemius product hub (opt-in, license activation, add-on installation) would then fail — that failure surfaces inside the Add-ons page itself, not on plugin boot.
- `Main::define_admin_hooks()` remains the correct wiring surface. If a future refactor moves admin-menu wiring elsewhere (e.g. to a dedicated `AdminBootstrap` class), the AddonsPage instantiation moves with it.

## Accepted Deviations

- **DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT (this feature)** — Accepted deviation from Boot Flow Rule AC-HOOKS-MAIN. The `\AcrossAI_Addon\AddonsPage` constructor self-registers all its WordPress hooks. Consumer code cannot route those hooks through `Main.php`'s Loader because the vendor's public API does not expose per-hook registration methods. Consumers instead call `new AddonsPage(...)` once inside `Main::define_admin_hooks()`. Mirrors the sibling plugin's `DEC-EXTERNAL-PACKAGE-HOOK-CTOR` (Feature 038).
