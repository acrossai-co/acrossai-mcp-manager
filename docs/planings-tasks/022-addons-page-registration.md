# Planning: Register the shared AcrossAI Add-ons submenu (Feature 022)

Wire the plugin's admin-menu bootstrap so the shared **AcrossAI → Add-ons** submenu
(vendored inside `acrossai-co/main-menu` and exposed as
`\AcrossAI_Addon\AddonsPage`) actually appears when this plugin is active. The
class is already present in `vendor/acrossai-co/main-menu/src/Addons/` and is
autoloaded via `vendor/autoload_packages.php`, but no code in the consumer plugin
ever calls `new AddonsPage(...)`. Because `AddonsPage::__construct()`
self-registers every one of its WordPress hooks (`AddonsPage.php:104-115`), the
`add_submenu_page( 'acrossai', ..., 'acrossai-addons', ... )` call in
`MenuRegistrar::register()` (`MenuRegistrar.php:27-45`) never runs.

Symptom (user report, 2026-07-12): visiting
`/wp-admin/admin.php?page=acrossai-addons` after a fresh activation of
`acrossai-mcp-manager` renders nothing — the submenu is missing from the sidebar
and the direct URL returns the default "You do not have permission" screen
because WordPress has no registered callback for that slug.

The sibling plugin `acrossai-abilities-manager` wires this correctly at
`includes/Main.php:316-349` — that block is the canonical template for this
feature. Copy the pattern verbatim (guarded `class_exists`, `try/catch`, and a
graceful admin-notice degradation on constructor throw) into this plugin's
`AcrossAI_MCP_Manager\Includes\Main::define_admin_hooks()`, using the
plugin-specific Freemius product credentials the user provided
(`fs_product_id = 34418`, `fs_public_key = pk_d61a7ddb1a619f7697fbb4fc397b6`,
`fs_slug = acrossai-add-ons`).

The change is strictly additive:

- **No vendor code is touched** — the AddonsPage / MenuRegistrar / FreemiusBridge
  classes shipped by `acrossai-co/main-menu` remain unchanged.
- **No shared parent menu edits** — the `acrossai` parent is already booted from
  `acrossai-mcp-manager.php:149-161` via the shared package's `SettingsPage`.
- **No new hook wiring** — `AddonsPage::boot()` (`AddonsPage.php:104-115`) owns
  all six of its own `admin_menu`, `admin_init`, `admin_enqueue_scripts`,
  `admin_notices`, `wp_ajax_*`, and `admin_post_*` registrations. The consumer
  only needs to construct the object once inside
  `Main::define_admin_hooks()`.
- **No database migration.** The vendor uses Freemius's own storage.
- **No user-facing capability change.** The submenu inherits the vendor's
  `install_plugins` requirement (`MenuRegistrar.php:39`).

The single-registration guard `MenuRegistrar::$registered` (`MenuRegistrar.php:9-10`)
means that when more than one AcrossAI plugin (e.g. `acrossai-abilities-manager`
+ `acrossai-mcp-manager`) is active simultaneously, only the first-registered
plugin adds the nav entry — the second plugin still contributes its Freemius
product config to the shared page but does not duplicate the submenu row. That
is the intended cross-plugin coordination; do not attempt to defeat it.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "addons-page-registration"

# 2. Specify
/speckit.specify "Wire the shared AcrossAI Add-ons submenu into
acrossai-mcp-manager by instantiating \\AcrossAI_Addon\\AddonsPage from
includes/Main.php::define_admin_hooks(). The class is shipped by the
acrossai-co/main-menu vendor package (vendor/acrossai-co/main-menu/src/Addons/
AddonsPage.php) and self-registers all WordPress hooks in its constructor.
Mirror the sibling plugin acrossai-abilities-manager/includes/Main.php lines
316-349 pattern exactly: class_exists guard, try/catch with an admin-notice
fallback that only prints to manage_options users, and pass the plugin's
own Freemius credentials via the second constructor argument
(fs_product_id 34418, fs_public_key pk_d61a7ddb1a619f7697fbb4fc397b6,
fs_slug acrossai-add-ons). The constructor's first positional argument
is ACROSSAI_MCP_MANAGER_PLUGIN_FILE. Do not add the block via the Loader —
follow the sibling plugin's Accepted Deviation from Boot Flow Rule
(DEC-EXTERNAL-PACKAGE-HOOK-CTOR) because the vendor's public API does not
expose per-hook registration methods. Insert the block after the
$settings_menu wiring (~line 352) and before the Admin notices block
(~line 354). Update README.txt Unreleased changelog with one bullet
describing the new submenu. Update docs/planings-tasks/README.md feature-index
row for 022. Do not modify vendor code, do not modify the shared parent slug
'acrossai', do not alter capability requirements, do not touch the existing
$settings_menu, $settings, Menu, or SettingsMenu wiring — those blocks stay
byte-identical. PHPStan level 8 + PHPCS gates must stay green. Add a
DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT entry to docs/memory/DECISIONS.md (or the
plugin's memory hub equivalent) explaining that AddonsPage self-registers
hooks in its constructor, hence the exception to the Boot Flow Rule."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all of these
> governing documents in full:**
>
> 1. `AGENTS.md` — hook registration rule ("`includes/Main.php` is the ONLY file
>    that calls `$this->loader->add_action()` / `$this->loader->add_filter()`"),
>    naming prefix, singleton convention, Before Commit Checklist.
> 2. Sibling plugin reference — the canonical instantiation block at
>    `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-abilities-manager/includes/Main.php`
>    lines 316-349 (constructor call + try/catch + admin-notice fallback +
>    inline docblock describing `DEC-EXTERNAL-PACKAGE-HOOK-CTOR`).
> 3. Vendor entrypoint —
>    `vendor/acrossai-co/main-menu/src/Addons/AddonsPage.php` lines 24-115.
>    Constructor signature, throws contract (`InvalidArgumentException` on
>    empty `fs_product_id`/`fs_public_key`; `RuntimeException` on WordPress
>    < 6.0 or unresolvable consumer file), and the `boot()` private method
>    that owns every downstream `add_action()`.
> 4. Vendor menu registrar — `vendor/acrossai-co/main-menu/src/Addons/MenuRegistrar.php`
>    lines 5-50. The `SUBMENU_SLUG = 'acrossai-addons'` constant, the
>    `install_plugins` capability requirement, the parent slug default
>    (`'acrossai'`), and the `self::$registered` process-wide dedup guard
>    that lets multiple AcrossAI plugins coexist without stacking duplicate
>    nav entries.
> 5. This plugin's own `includes/Main.php` lines 285-374 — the current
>    `define_admin_hooks()` shape. Insertion point is between the
>    `$settings_menu` block ending at line 352 and the "Admin notices" block
>    starting at line 354.
> 6. The shared-parent bootstrap already present at
>    `acrossai-mcp-manager.php` lines 149-161 — nothing about the parent
>    menu changes; this feature only adds a submenu under it.
>
> Every design decision — where to put the instantiation, what the
> `try/catch` catches, why the `class_exists` guard exists, why the notice
> is gated on `manage_options` — must be justified against the above. If a
> choice is not explicitly covered, default to the sibling plugin's shape.
>
> **Freemius credentials for this plugin** (user-provided, 2026-07-12):
>
> ```
> fs_product_id = 34418
> fs_public_key = pk_d61a7ddb1a619f7697fbb4fc397b6
> fs_slug       = acrossai-add-ons
> ```
>
> Store these inline in `includes/Main.php` — the sibling plugin does the
> same at lines 328-332. Do not add a filter or option-based indirection.
>
> **Public API artifacts to preserve verbatim** (grep-gate before + after):
>
> - Every existing entry inside `define_admin_hooks()`:
>   - `Admin\Main::enqueue_styles` + `enqueue_scripts` hooks
>   - `Admin\Partials\Menu::register_submenu` on `admin_menu`
>   - `plugin_action_links_<basename>` filter
>   - `Admin\Partials\Settings::maybe_seed_default_server` @ 4
>   - `Admin\Partials\Settings::handle_actions` @ 5
>   - `Admin\Partials\SettingsMenu::register_tab` on `acrossai_settings_tabs`
>   - `Admin\Partials\SettingsMenu::register_settings` on `admin_init`
>   - Every admin-notice hook below the insertion point (unchanged)
>
> Pre-flight grep (records the wiring calls whose ordering must be unchanged
> after this feature):
>
> ```
> grep -n "loader->add_action\|loader->add_filter" \
>     includes/Main.php
> ```
>
> Every hit that surfaces here MUST still exist in the same order after
> TASK-1. Any diff there means TASK-1 disturbed unrelated wiring.
>
> ---
>
> **TASK-1 — Instantiate `AddonsPage` inside `Main::define_admin_hooks()`**
>
> Files: `includes/Main.php`
>
> Read `includes/Main.php` lines 285-374 (the current `define_admin_hooks()`
> body) and `acrossai-abilities-manager/includes/Main.php` lines 316-349
> (the canonical block to mirror) BEFORE editing.
>
> Insert the following block AFTER the `$settings_menu` register_settings
> line (currently line 352) and BEFORE the "Admin notices" comment header
> (currently line 354):
>
> ```php
> /**
>  * Add-ons submenu page — bundled in acrossai-co/main-menu (\AcrossAI_Addon\AddonsPage).
>  *
>  * The AddonsPage constructor self-registers all WordPress hooks
>  * (admin_menu, admin_init, admin_enqueue_scripts, admin_notices,
>  * wp_ajax_acrossai_addons_*, admin_post_acrossai_addons_connect_again)
>  * — no Loader wiring needed. Accepted deviation from Boot Flow Rule
>  * (see DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT) because the external package's
>  * public API does not expose individual hook methods. Guarded per
>  * Constitution §V Integration Resilience — fails gracefully when the
>  * vendor package is stripped from a build. Freemius credentials are
>  * scoped to this plugin's Freemius product (id 34418).
>  * Mirrors acrossai-abilities-manager Feature 038 DEC-EXTERNAL-PACKAGE-HOOK-CTOR.
>  */
> if ( class_exists( \AcrossAI_Addon\AddonsPage::class ) ) {
>     try {
>         new \AcrossAI_Addon\AddonsPage(
>             ACROSSAI_MCP_MANAGER_PLUGIN_FILE,
>             array(
>                 'fs_product_id' => '34418',
>                 'fs_public_key' => 'pk_d61a7ddb1a619f7697fbb4fc397b6',
>                 'fs_slug'       => 'acrossai-add-ons',
>             )
>         );
>     } catch ( \Throwable $e ) {
>         $error_message = $e->getMessage();
>         add_action(
>             'admin_notices',
>             function () use ( $error_message ) {
>                 if ( ! current_user_can( 'manage_options' ) ) {
>                     return;
>                 }
>                 printf(
>                     '<div class="notice notice-error"><p><strong>AcrossAI MCP Manager:</strong> %s</p></div>',
>                     esc_html( $error_message )
>                 );
>             }
>         );
>     }
> }
> ```
>
> Do NOT:
>
> - Move any adjacent block. The `$settings`, `$menu`, `$settings_menu`,
>   and admin-notice wiring stays byte-identical.
> - Use `$this->loader->add_action()` for any of AddonsPage's hooks. The
>   vendor owns them.
> - Suppress the `try/catch`. The constructor throws are load-bearing on a
>   broken vendor install.
> - Wrap the block in an additional `is_admin()` check. `define_admin_hooks()`
>   is already the admin-side wiring path.
> - Add any is_null / empty check on `ACROSSAI_MCP_MANAGER_PLUGIN_FILE` —
>   the constant is defined at the top of the plugin entry file (line 49)
>   before `Main::instance()` runs.
>
> ---
>
> **TASK-2 — Changelog + docs + memory hub**
>
> Files:
>
> - `README.txt`
> - `docs/planings-tasks/README.md`
> - `docs/memory/DECISIONS.md` (if the file exists; skip if absent)
> - `docs/memory/INDEX.md` (if it exists; skip if absent)
>
> `README.txt` — insert as the FIRST bullet under `= Unreleased =` at line 185:
>
> ```
> * **Feature 022 — Shared AcrossAI Add-ons submenu.** The plugin now
>   registers the shared "Add-ons" nav entry under the AcrossAI top-level
>   menu, powered by Freemius for product id 34418. The page requires
>   `install_plugins`; when a companion AcrossAI plugin is active
>   simultaneously only one plugin contributes the nav entry (the shared
>   package coordinates this so operators never see duplicate submenu rows).
> ```
>
> `docs/planings-tasks/README.md` — append one row to the Feature Specs
> table (currently ends at row 021 at line 49):
>
> ```
> | 022 | addons-page-registration | 2026-07-12 | Planned | [022-addons-page-registration.md](022-addons-page-registration.md) |
> ```
>
> `docs/memory/DECISIONS.md` — if the file exists, add:
>
> ```
> ### DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT (Active — Feature 022)
>
> `\AcrossAI_Addon\AddonsPage` (bundled in `acrossai-co/main-menu`)
> self-registers every one of its WordPress hooks in its constructor.
> Consumer plugins MUST instantiate it inside
> `Main::define_admin_hooks()` (or the equivalent admin-menu wiring path)
> under a `class_exists` guard + `try/catch`, passing the plugin's own
> Freemius credentials. Do NOT re-register the same hooks via `$this->loader`
> — the vendor owns them. Accepted deviation from Boot Flow Rule (AC-HOOKS-MAIN).
>
> Reference: `vendor/acrossai-co/main-menu/src/Addons/AddonsPage.php:104-115`;
> `acrossai-abilities-manager/includes/Main.php:316-349` (sibling reference —
> DEC-EXTERNAL-PACKAGE-HOOK-CTOR); this plugin's insertion at
> `includes/Main.php` ~line 353.
> ```
>
> `docs/memory/INDEX.md` — if it exists, append a row under Active Decisions
> pointing at DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT with a one-line hook.
>
> ---
>
> **TASK-3 — Grep + syntax sweep (zero code)**
>
> Files: none — this is a verification-only task.
>
> Run each grep + expect the listed count:
>
> ```
> grep -rEn 'AcrossAI_Addon\\\\AddonsPage' includes/ admin/ acrossai-mcp-manager.php
> # expected: exactly 1 hit — the TASK-1 insertion in includes/Main.php.
>
> grep -rEn 'fs_product_id' includes/ admin/ acrossai-mcp-manager.php
> # expected: exactly 1 hit — the TASK-1 insertion.
>
> grep -rEn "acrossai-addons" includes/ admin/ acrossai-mcp-manager.php
> # expected: 0 hits — the slug lives only inside vendor/.
>
> php -l includes/Main.php
> # expected: "No syntax errors detected".
> ```
>
> Any mismatch triggers a fix pass on TASK-1 (do NOT change vendor code).
>
> ---
>
> **CONSTRAINTS**
>
> - **Do not modify vendor code.** Everything under `vendor/acrossai-co/main-menu/`
>   is external-package territory.
> - **Do not change the shared parent slug.** `'acrossai'` is coordinated
>   across every AcrossAI plugin via
>   `\AcrossAI_Main_Menu\SettingsPage` (bootstrapped at
>   `acrossai-mcp-manager.php:149-161`).
> - **Do not lower the capability requirement.** `install_plugins` is set by
>   the vendor at `MenuRegistrar.php:39` and is the correct guard for a
>   plugin-installer surface.
> - **Do not add the block to the Loader.** `Main::define_admin_hooks()` may
>   ONLY dispatch `AddonsPage` via a direct `new` — the vendor owns hook
>   registration.
> - **Do not remove the `class_exists` guard.** Per Constitution §V
>   Integration Resilience, absent vendor must degrade silently rather than
>   fatal.
> - **Do not remove the `try/catch`.** `AddonsPage::__construct()` throws
>   `InvalidArgumentException` on missing credentials and `RuntimeException`
>   on WP < 6.0 or an unresolvable consumer file. The admin-notice fallback
>   is the accepted degradation.
> - **Do not touch adjacent wiring blocks** (`$plugin_admin`, `$menu`,
>   `$settings`, `$settings_menu`, admin-notice hooks). This feature is a
>   single-block insertion.
> - **Do not add a data migration step.** No tables are involved.
> - **PHPStan level 8 + PHPCS gates stay green.** The `\Throwable` catch and
>   `esc_html()` escaping match the sibling plugin's shape exactly — no new
>   suppressions or baseline entries permitted.
> - **Text domain stays `'acrossai-mcp-manager'`** for any `__()` calls in
>   this feature (the vendor's own strings use `'acrossai'` — that's the
>   vendor's contract, not ours).

```markdown
# 3. Plan + guard + security
/speckit.memory-md.plan-with-memory
/speckit.architecture-guard.governed-plan
/speckit.security-review.plan

# 4. Tasks + guard
/speckit.tasks
/speckit.architecture-guard.governed-tasks

# 5. Implement + quality checks
/speckit.architecture-guard.governed-implement
composer dump-autoload
composer run phpcs
composer run phpstan

# 6. Review + memory + commit
/speckit.analyze
/speckit.architecture-guard.architecture-review
/speckit.security-review.staged
/speckit.memory-md.capture-from-diff
/speckit.git.commit
```

---

## Manual Verification Checklist

### TASK-1 — AddonsPage instantiation
- [ ] `includes/Main.php` contains exactly one `class_exists(
      \AcrossAI_Addon\AddonsPage::class )` block, inserted inside
      `define_admin_hooks()` after the `$settings_menu` register_settings
      call and before the "Admin notices" comment header.
- [ ] The `new \AcrossAI_Addon\AddonsPage( ... )` call passes
      `ACROSSAI_MCP_MANAGER_PLUGIN_FILE` as the first positional argument.
- [ ] The `$args` array contains `fs_product_id => '34418'`,
      `fs_public_key => 'pk_d61a7ddb1a619f7697fbb4fc397b6'`, and
      `fs_slug => 'acrossai-add-ons'`.
- [ ] The `catch ( \Throwable $e )` branch registers an `admin_notices`
      closure that gates on `current_user_can( 'manage_options' )` and
      passes `esc_html( $error_message )` into `printf`.
- [ ] No changes elsewhere in `define_admin_hooks()` — pre-flight grep of
      `loader->add_action|loader->add_filter` inside the method returns the
      same call list in the same order.
- [ ] `php -l includes/Main.php` reports zero syntax errors.
- [ ] Reload wp-admin as a `install_plugins`-capable user; the
      **AcrossAI → Add-ons** submenu appears in the sidebar.
- [ ] Visit `/wp-admin/admin.php?page=acrossai-addons` directly; page
      renders (Freemius opt-in banner on first visit for product 34418,
      then the add-ons grid).
- [ ] Log in as an Editor (no `install_plugins`); confirm the submenu is
      hidden and the direct URL redirects to
      "Sorry, you are not allowed to access this page."
- [ ] Deactivate `acrossai-abilities-manager` (if active); reload wp-admin.
      The Add-ons submenu still appears — proves this plugin's registration
      is independent of the sibling plugin.

### TASK-2 — Changelog + memory hub
- [ ] `README.txt = Unreleased =` section contains the Feature 022 bullet
      as the FIRST entry.
- [ ] `docs/planings-tasks/README.md` Feature Specs table has one new row
      for `022 | addons-page-registration | 2026-07-12 | Planned |`.
- [ ] `docs/memory/DECISIONS.md` (if the file exists) contains the
      `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT (Active — Feature 022)` entry.
- [ ] `docs/memory/INDEX.md` (if it exists) has the DEC row appended
      under Active Decisions with a one-line hook.

### TASK-3 — Sweep
- [ ] `grep -rEn 'AcrossAI_Addon\\\\AddonsPage' includes/ admin/ acrossai-mcp-manager.php`
      returns exactly one hit (the TASK-1 insertion).
- [ ] `grep -rEn 'fs_product_id' includes/ admin/ acrossai-mcp-manager.php`
      returns exactly one hit.
- [ ] `grep -rEn "acrossai-addons" includes/ admin/ acrossai-mcp-manager.php`
      returns zero hits.
- [ ] `php -l includes/Main.php` — zero syntax errors.

### Quality gates (all green before commit)
- [ ] `composer run phpcs` — zero errors.
- [ ] `composer run phpstan` — zero errors at level 8.
- [ ] Manual smoke E2E on the local WP 7.0 install — submenu appears,
      page renders, credential-blank fault path fires the admin notice.

---

## Emergent Fixes (post-plan — 2026-07-12)

### T035-T036 — Vendor bump `acrossai-co/main-menu` `0.0.16` → `0.0.17` (disable vendor Add-ons submenu)

**Symptom** (operator decision, 2026-07-13): with the umbrella model in place — Freemius product 34418 (`acrossai-add-ons`) owning the single ecosystem-wide Add-ons page — the vendor's own `MenuRegistrar::register()` and Freemius's `menu.addons` submenu together produced a duplicate Add-ons row under the AcrossAI menu.

**Root cause**: `\AcrossAI_Addon\MenuRegistrar::register()` (`vendor/acrossai-co/main-menu/src/Addons/MenuRegistrar.php:35-42`) calls `add_submenu_page('acrossai', ..., 'acrossai-addons', ...)` unconditionally. Meanwhile the plugin's `fs_menu.addons = true` tells Freemius to also register its own Add-ons submenu at `acrossai-add-ons-addons` (or similar Freemius-generated slug). Two distinct rows, same page family — bad UX.

**Fix** (chosen path — retire the vendor's custom Add-ons registration and let Freemius own it):
1. In the vendor repo at `/wp-content/main-menu/`, comment out the `$this->hook_suffix = add_submenu_page(...)` block inside `MenuRegistrar::register()`. Preserve the `self::$registered` guard so cross-plugin coordination still short-circuits on subsequent calls. Commit `d467f83` on the vendor's `main` branch.
2. Tag `0.0.17` on the same commit; push both to `origin`.
3. Plugin: bump `composer.json` constraint from `"0.0.16"` to `"0.0.17"`. `composer update acrossai-co/main-menu` — installs 0.0.17 against commit `d467f83`.

**Cross-consumer impact**: every consumer of `acrossai-co/main-menu` that bumps to `0.0.17` loses the vendor-owned Add-ons submenu row. Consumers using Freemius's own `menu.addons` (recommended umbrella pattern) see one Add-ons row. Consumers who set `fs_menu.addons = false` AND relied on the vendor's row will now see ZERO Add-ons rows — they need to flip `fs_menu.addons` back to `true` to restore.

**Spec/tasks fold-back**: spec.md FR-014 bumped from 0.0.16 to 0.0.17; plan.md Technical Context Primary Dependencies extended with the 0.0.17 rationale; tasks.md Phase 4d added with T035-T036; README.txt Unreleased bullet extended.

---

### T029-T034 — Vendor bump `acrossai-co/main-menu` `0.0.15` → `0.0.16` (per-consumer `fs_menu` override)

**Symptom** (operator request, 2026-07-12): after 0.0.15 flipped the three defaults on at the package level, operator asked "make it dynamic so the plugin can decide which submenus to show or hide."

**Root cause**: 0.0.15's fix was a global policy change of the shared package. It didn't give individual consumer plugins a way to disagree.

**Fix** — introduce a per-consumer `fs_menu` override key on `AddonsPage`'s `$args`:
1. In the vendor, extract the six-key `menu` array into `FreemiusInitializer::DEFAULT_MENU`, add an `array $menu_overrides = []` param to `init()`, `unset( $menu_overrides['slug'] )` before the merge (slug derives from `$menu_slug`, cannot be overridden this way), `array_merge` in the order `DEFAULT_MENU` → overrides → `[ 'slug' => $menu_slug ]`.
2. Thread `fs_menu` through `AddonsPage::__construct()` (`$fs_menu = isset( $args['fs_menu'] ) && is_array( $args['fs_menu'] ) ? $args['fs_menu'] : array();`).
3. Document the new key in `README.md` §Add-ons page with a full example.
4. Tag `0.0.16` (commit `0fb50ea`).
5. Bump the plugin to `0.0.16` and extend the `AddonsPage(...)` `$args` block in `includes/Main.php` with an explicit `'fs_menu' => [ 'account' => true, 'contact' => true, 'support' => true, 'upgrade' => false, 'pricing' => false, 'addons' => false ]` block that mirrors the vendor defaults but declares the plugin's intent explicitly at the call site.

**Cross-consumer impact**: every existing 0.0.15 consumer keeps working without a call-site change because the vendor's `DEFAULT_MENU` matches the 0.0.15 hardcode. New consumers can override any subset of the six keys by passing `fs_menu` in `$args`. Unknown keys pass through verbatim so future Freemius menu-config extensions work without a package bump.

**Spec/tasks fold-back**: spec.md Clarification Q3 added; FR-016 added; FR-014 amended from 0.0.15 → 0.0.16; tasks.md Phase 4c added with T029-T034; README.txt Unreleased bullet extended.

---

### T024-T028 — Vendor bump `acrossai-co/main-menu` `0.0.14` → `0.0.15`

**Symptom** (operator report, 2026-07-12): after F022 landed the Add-ons submenu correctly, operator noticed the standard Freemius Account / Contact Us / wp.org Support Forum submenus were missing from the AcrossAI menu.

**Root cause**: `\AcrossAI_Addon\FreemiusInitializer::init()` at `vendor/acrossai-co/main-menu/src/Addons/FreemiusInitializer.php:57-65` hardcoded all three submenus to `false`. The Freemius wizard checkboxes shown in the operator dashboard are cosmetic — actual runtime behavior is driven entirely by the vendor's `fs_dynamic_init()` menu config.

**Fix** (chosen path — modify vendor at the shared-package layer, not per-consumer):
1. In the vendor repo at `/wp-content/main-menu/`, flip `account` / `contact` / `support` from `false` to `true` in `FreemiusInitializer::init()`. Leave `upgrade` / `pricing` / `addons` as `false` (pricing/upgrade served by the Add-ons page itself; a second `addons` row would duplicate the vendor's own Add-ons registration).
2. Commit direct to `main` (repo convention) — commit `a58dec9`.
3. Annotate tag `0.0.15` on the same commit. Push both to `origin`.
4. Back in the plugin: bump `composer.json` constraint from `"0.0.14"` to `"0.0.15"`. Add explicit VCS `repositories` entry for `https://github.com/acrossai-co/main-menu` so composer resolves deterministically without waiting on Packagist sync (initial resolution attempt failed with "found 0.0.1, ..., 0.0.14" — Packagist hadn't yet indexed the new tag).
5. `composer clear-cache && composer update acrossai-co/main-menu` — installs 0.0.15 against commit `a58dec9`.

**Cross-consumer impact**: every consumer of `acrossai-co/main-menu` (including `acrossai-abilities-manager`) that bumps to `0.0.15` inherits the same three-submenu-visible behavior. This is by design — the decision is a shared policy of the package, not a per-plugin override.

**Spec/tasks fold-back**: spec.md Clarification Q2 added; FR-014 + FR-015 added; tasks.md Phase 4b added with T024-T028; README.txt Unreleased bullet extended with the vendor-bump narrative.

---

## Pre-flight Attestation

**Captured**: 2026-07-12 via `AskUserQuestion` during this feature's plan
phase.

**Attestation**: This is an additive, no-schema-change feature. No install
carries pre-migration data that this change could orphan. The Freemius
credentials (`fs_product_id = 34418`,
`fs_public_key = pk_d61a7ddb1a619f7697fbb4fc397b6`) belong to the
"AcrossAI MCP Manager" Freemius product owned by the user
(`raftaar1191@gmail.com`) and are safe to store inline in `includes/Main.php`
alongside the sibling plugin's precedent.

**Basis for**: FR-001 — additive-only change; no compat-drop authorization
required.

**Attesting user**: raftaar1191@gmail.com

**Validity window**: 2026-07-12 → Feature 022 merge.
