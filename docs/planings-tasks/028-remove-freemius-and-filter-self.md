# Planning: Retire Freemius integration; consume main-menu 0.0.22 filter-driven Add-ons page (Feature 028)

F022 (`docs/planings-tasks/022-addons-page-registration.md`, shipped as v1.0.x) wired this plugin into a Freemius-backed umbrella product (id 34418, slug `acrossai-add-ons`) via `\AcrossAI_Addon\AddonsPage` — an external class bundled inside `acrossai-co/main-menu` 0.0.15–0.0.21 that self-registered ~10 WordPress hooks in its constructor and forwarded credentials to `fs_dynamic_init()`. The wiring shipped alongside two DEC entries (`DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`, `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT`) and one bug entry (`B28` — Freemius two-level submenu enablement).

Upstream `acrossai-co/main-menu` 0.0.22 removes the Freemius integration wholesale: `freemius/wordpress-sdk` is dropped from `require`, the `AcrossAI_Addon\` PSR-4 namespace is deleted, and the Add-ons page is re-implemented as a filter-driven card grid (`AddonsPageRenderer` runs `apply_filters( 'acrossai_addons', self::ADDONS )` — no license/opt-in state anywhere). F028 consumes 0.0.22 in this plugin and drops every trace of the F022 Freemius surface.

Feature 028 covers:

- Bump `acrossai-co/main-menu` from `0.0.18` → `0.0.22` in `composer.json`; `composer update` uninstalls `freemius/wordpress-sdk 2.13.4` transitively (nothing else in the tree requires it).
- Delete the 94-line `class_exists( \AcrossAI_Addon\AddonsPage::class ) { new AddonsPage(...) }` block from `Main::define_admin_hooks()`, plus its accompanying `fs_product_id` / `fs_public_key` / `fs_slug` / `fs_menu` / `fs_has_addons` config.
- Add `admin/Partials/AddonsFilter.php` — singleton that hooks `acrossai_addons` and strips the entry with `slug === 'acrossai-mcp-manager'` from the array (an already-active plugin should not advertise itself as an installable add-on). Wire via `$this->loader->add_filter()` in `Main::define_admin_hooks()`.
- Add `tests/phpunit/Admin/AddonsFilterTest.php` — four cases: strip + reindex, no-op when own slug absent, non-array input normalization, drop non-array entries.
- Mark `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT`, `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT`, and `B28` as **Superseded (F028)** in `docs/memory/INDEX.md` + their source entries; add a WORKLOG line for the arc.

The change is **behavior-visible in exactly one way**: the Add-ons submenu disappears from this plugin's AcrossAI parent menu until an active plugin (`acrossai-abilities-manager`, `acrossai-model-manager`, `turn-off-ai-features`, or any consumer registered via `acrossai_addons`) publishes the shared Add-ons page. This is intentional — the umbrella Freemius product retires alongside the SDK. Everything else stays: `AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu`'s tab wiring on the shared Settings page is untouched, the MCP servers UI, OAuth, and CLI paths do not touch main-menu's Add-ons surface.

The retirement is **fresh-install-friendly** — no user data lives in `wp_options.fs_accounts` for the umbrella product that a consumer cares about post-uninstall of Freemius; Freemius' own uninstall hooks (which we no longer load) would normally purge those keys, but the operator can drop `wp_options` rows starting `fs_*` manually if they want a clean slate. Documented as an out-of-scope recipe below.

---

## Speckit Workflow

```markdown
# 1. Branch
/speckit.git.feature "remove-freemius"

# 2. Specify
/speckit.specify "In composer.json bump acrossai-co/main-menu from '0.0.18'
to '0.0.22'. Run 'composer update acrossai-co/main-menu' — the lock file
regenerates and freemius/wordpress-sdk 2.13.4 is uninstalled transitively
because no other package requires it.

Delete the entire Freemius integration block in
includes/Main.php::define_admin_hooks(). That block spans ~94 lines starting
at the docblock immediately after \$this->loader->add_action('admin_init',
\$settings_menu, 'register_settings'); it contains a
class_exists(\\AcrossAI_Addon\\AddonsPage::class) guard, a try{} block that
calls 'new \\AcrossAI_Addon\\AddonsPage(ACROSSAI_MCP_MANAGER_PLUGIN_FILE,
\$args)' with fs_product_id/fs_public_key/fs_slug/fs_menu/fs_has_addons
in \$args, and a catch{} block that registers an admin_notices closure. The
class no longer exists in main-menu 0.0.22 so the class_exists guard falls
false permanently anyway — delete the whole block for source hygiene.

In the same spot in define_admin_hooks(), wire the new consumer-side filter:
create admin/Partials/AddonsFilter.php as a singleton with a
public function remove_self( \$addons ): array method that (a) short-circuits
to array() when input is not an array, (b) array_values( array_filter(...) )
to drop entries where slug === 'acrossai-mcp-manager', (c) tolerates
non-array entries by dropping them. Register via
\$this->loader->add_filter( 'acrossai_addons',
\\AcrossAI_MCP_Manager\\Admin\\Partials\\AddonsFilter::instance(),
'remove_self' ). This is admin-scoped even though it lands in
define_admin_hooks() — main-menu's AddonsPageRenderer::get_addons() runs the
filter once per request when the Add-ons page renders, and register-time is
cheap.

Add tests/phpunit/Admin/AddonsFilterTest.php with four cases extending
WP_UnitTestCase (belongs to the 'admin' testsuite):
test_remove_self_strips_own_slug_and_reindexes,
test_remove_self_is_noop_when_own_slug_absent,
test_remove_self_normalizes_non_array_input (null/'oops'/false),
test_remove_self_drops_non_array_entries. Use array_keys() to assert
reindex.

Update docs/memory/INDEX.md rows for DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT,
DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT, and B28 from 'Active (F022)' to
'Superseded (F028)'. Add matching Status: line flips to DECISIONS.md
(the two DEC entries) and BUGS.md (B28 row). Add a WORKLOG.md entry dated
2026-07-17 for F028 covering the retirement + the filter-driven Add-ons page
+ consumer self-exclusion pattern.

Do NOT touch admin/Partials/SettingsMenu.php or the acrossai_settings_tabs
filter wiring — main-menu's Settings page surface is unchanged. Do NOT
edit vendor/. Do NOT modify uninstall.php — freemius/wordpress-sdk's own
uninstall hooks were what wrote fs_* wp_options keys, and we no longer load
them; existing keys are orphan and harmless. Do NOT introduce a data
migration for the fs_* wp_options rows — F016's fresh-install-only pattern
(D21) applies (operator can DELETE FROM wp_options WHERE option_name LIKE
'fs_%' if they want a clean slate; not our concern in-plugin)."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize the following:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, A1 hook-registration-in-Main-only rule, Before-Commit Checklist.
> 2. `docs/planings-tasks/022-addons-page-registration.md` — F022 spec that shipped the AddonsPage integration being retired. F028 is a subtractive-edit against F022.
> 3. `docs/memory/DECISIONS.md` — read `DEC-ADDONS-PAGE-VENDOR-CTOR-BOOT` + `DEC-FREEMIUS-DOUBLE-OPTIN-GATES-ACCOUNT` entries in full. Both mark Superseded here; keep their bodies verbatim (per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION they stay for context until an audit prunes them).
> 4. `docs/memory/BUGS.md` — `B28` (Freemius two-level menu enablement). Same supersession treatment.
> 5. `vendor/acrossai-co/main-menu/src/AddonsPageRenderer.php` — read the `ADDONS` baseline array + `get_addons()` (runs the `acrossai_addons` filter) + `find_addon()`. The four slugs shipped in the baseline are `acrossai-abilities-manager`, `acrossai-mcp-manager`, `acrossai-model-manager`, `turn-off-ai-features`. Our filter removes only our own row.
> 6. `docs/memory/INDEX.md` — its `Superseded (F###)` convention is documented at the top (Active → Needs Review → Superseded → pruned). Keep at most 3–5 Superseded entries for context per the audit-hygiene note.

## Manual verification

1. `composer run phpcs` on `admin/Partials/AddonsFilter.php` + `includes/Main.php` → clean.
2. `composer run phpstan` → level 8 clean.
3. `composer run test --testsuite admin` → new AddonsFilterTest 4 cases pass; existing SettingsMenuTest untouched.
4. Post-merge deploy → open the AcrossAI Add-ons page in wp-admin (rendered by any active consumer of main-menu 0.0.22+) and confirm the "AcrossAI MCP Manager" card is absent from the grid.
5. Deactivate this plugin → confirm the card *reappears* (the filter unhooks when the plugin's `Main::load_hooks()` no longer runs).

## Out of scope

- **Orphan `fs_*` wp_options rows**: The Freemius SDK stored `fs_accounts`, `fs_active_plugins`, `fs_api_cache`, `fs_cache_*`, `fs_debug_mode`, etc. Since no other in-repo consumer of these keys exists post-retirement, they're harmless. Operators wanting a clean slate: `DELETE FROM wp_options WHERE option_name LIKE 'fs_%';`. Not shipping a migration per D21 (fresh-install-only retirement pattern from F016).
- **Historical decision cleanup**: The `specs/022-addons-page-registration/` directory + F022's `docs/planings-tasks/022-addons-page-registration.md` are historical record and stay as-is. Only the *live* memory (INDEX/DECISIONS/BUGS) flips Superseded.
