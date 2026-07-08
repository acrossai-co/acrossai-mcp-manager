# Planning: Bump `acrossai-co/main-menu` to 0.0.13 and adopt the tab-scoped Settings API (Feature 018)

Upgrade the vendor package `acrossai-co/main-menu` from `0.0.11` to `0.0.13` in
`composer.json`, refresh `composer.lock` + `vendor/`, and update the MCP
Settings tab so both the Settings API registration site AND the tab-page-slug
lookup site match the 0.0.13 contract. The bump is not optional: the sibling
plugin `acrossai-abilities-manager` already pins `0.0.13`, and the shared
Jetpack Autoloader loads the newest copy of `\AcrossAI_Main_Menu\SettingsPage`
across every active plugin — so when both plugins are active, this plugin's
code runs against `0.0.13` regardless of what its own `composer.json` says.
Feature 018 makes the code and the pin agree with the class version that is
actually loaded at runtime.

Two breaking changes between `0.0.11` and `0.0.13` need to land together:

1. `SettingsPage::tab_page_slug()` was **removed as a static helper on
   `SettingsPage`** and moved to an instance method on `SettingsPageRenderer`,
   accessible via `SettingsPage::get_settings_renderer()`. The old static call
   throws `Call to undefined method` at `admin_init` — a fatal that white-screens
   every wp-admin page.
2. `TabbedPageRenderer::render()` now emits the form's `option_page` hidden
   field as the **tab-scoped slug** (`acrossai-settings-mcp`), not the shared
   page slug (`acrossai-settings`). Consumer plugins must register their
   settings against that same tab-scoped slug or `options.php` rejects the
   POST with "The acrossai-settings-mcp options page is not in the allowed
   options list." This was a deliberate fix in `0.0.13` for a cross-tab
   option-clobber bug (documented at vendor `src/TabbedPageRenderer.php:87–94`).

Both breakages are already observed on the developer's local install. Feature
018 is the compatibility patch, not a feature addition. The fix is confined to
`admin/Partials/SettingsMenu.php::register_settings()` and `composer.json`;
no other consumer of the vendor package exists in this plugin.

The migration is **backwards-compatible with existing user data**: option
names (`acrossai_mcp_npm_login_enabled`, `acrossai_mcp_uninstall_delete_data`)
are unchanged; only the `option_group` argument and the tab-page-slug lookup
change. Neither is stored anywhere — they exist only for the Settings API's
`options.php` handoff at request time.

---

## Speckit Workflow

```markdown
# 1. Branch — piggybacks on the open 017 branch/PR (approved verbally)
# No new branch; this hotfix lands on 017-per-server-ability-selection so
# PR #22 carries both changes to main.

# 2. Specify
/speckit.specify "Upgrade the composer requirement acrossai-co/main-menu from
0.0.11 to 0.0.13, refresh composer.lock and vendor/, and update
admin/Partials/SettingsMenu.php::register_settings() so both the
add_settings_section() page argument AND the register_setting() option_group
argument use the tab-scoped page slug returned by
SettingsPage::get_settings_renderer()->tab_page_slug(). Fall back to
SettingsPage::SETTINGS_SLUG . '-' . sanitize_key(TAB_SLUG) if
get_settings_renderer() returns null (guards the edge case where the vendor
SettingsPage was not bootstrapped this request — keeps admin_init non-fatal).
Update the two docblocks in SettingsMenu.php that describe the shared
'acrossai-settings' option_group behaviour to describe the tab-scoped
behaviour instead. Do NOT rename any registered option
(acrossai_mcp_npm_login_enabled, acrossai_mcp_uninstall_delete_data stay).
Do NOT touch the register_tab() method, the two field-render methods, the
sanitize_uninstall_flag() method, or the TAB_SLUG constant. Do NOT bump the
plugin version — this is a bugfix. Do NOT downgrade acrossai-abilities-
manager's pin. Do NOT introduce a class_exists() guard around
\\AcrossAI_Main_Menu\\SettingsPage — it is a hard-require via composer.json
and adding a guard would silently mask future dependency breakage."
```

### Detailed Description for `/speckit.specify`

> **Before writing a single line of code, read and internalize all four of
> these governing documents in full:**
>
> 1. `AGENTS.md` — this plugin's singleton pattern, hook registration rules,
>    and Before Commit Checklist.
> 2. `docs/planings-tasks/010-composer-dependencies.md` — established the
>    contract that vendor upgrades in this plugin are pinned to exact versions
>    (not caret ranges) for third-party deps we do not control the release
>    cadence of.
> 3. `docs/planings-tasks/012-mcp-settings-tab.md` — the parent feature that
>    added the MCP Settings tab and the original `register_settings()`
>    method now being patched.
> 4. Vendor `src/TabbedPageRenderer.php:60–103` in the `0.0.13` release —
>    the `render()` method authoritatively describes what `option_page` the
>    tab form posts, which drives what `option_group` `register_setting()`
>    must use.
>
> Every decision — whether the fallback branch is worth carrying, whether
> the docblocks should be updated, whether the docblocks should reference
> vendor version numbers, whether to snapshot the pre-fix and post-fix
> `phpstan` output — must be justified against the above. Default:
> **align this plugin's code with the class version that actually loads
> at runtime under Jetpack Autoloader.** Anything else is guesswork.
>
> **Public API surfaces preserved (grep-gate before + after — no surviving
> renamed / removed consumer permitted):**
>
> - `\AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu::TAB_SLUG` — unchanged
>   value `'mcp'`.
> - `\AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu::register_tab()` —
>   unchanged signature, unchanged returned array shape.
> - `\AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu::register_settings()` —
>   signature unchanged, behaviour changes to match the `0.0.13` contract.
> - Option keys `acrossai_mcp_npm_login_enabled`,
>   `acrossai_mcp_uninstall_delete_data` — names unchanged, defaults unchanged.
> - Sanitize callbacks — signatures unchanged.
>
> **Runtime contract with vendor 0.0.13 (must remain satisfied):**
>
> - `add_settings_section( $section_id, $title, $callback, $page )` — `$page`
>   MUST equal the tab-scoped slug returned by
>   `SettingsPageRenderer::tab_page_slug( 'mcp' )`.
> - `add_settings_field( $field_id, $title, $callback, $page, $section )` —
>   `$page` MUST equal the same tab-scoped slug.
> - `register_setting( $option_group, $option_name, $args )` — `$option_group`
>   MUST equal the same tab-scoped slug (NOT `'acrossai-settings'`), so
>   `options.php` walks the correct whitelist when the tab form is submitted.
>
> ---
>
> **TASK-1 — bump the composer dependency.** Edit `composer.json` line ~20:
> replace `"acrossai-co/main-menu": "0.0.11"` with
> `"acrossai-co/main-menu": "0.0.13"`. Do NOT convert the pin to a caret range
> — `0.0.z` versions are pre-release and API-unstable per the `0.0.11 → 0.0.13`
> break we are patching here. Then run
> `composer update acrossai-co/main-menu --no-interaction` from the plugin
> root. Verify `composer.lock` records `0.0.13` for the package. Verify
> `vendor/acrossai-co/main-menu/src/SettingsPage.php` no longer contains a
> static `tab_page_slug` method and that
> `vendor/acrossai-co/main-menu/src/TabbedPageRenderer.php` exists.
>
> **TASK-2 — patch the call site.** Edit
> `admin/Partials/SettingsMenu.php::register_settings()`. Replace the single
> `$page_slug = \AcrossAI_Main_Menu\SettingsPage::tab_page_slug( self::TAB_SLUG );`
> line with:
>
> ```php
> $renderer  = \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer();
> $page_slug = $renderer
>     ? $renderer->tab_page_slug( self::TAB_SLUG )
>     : \AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG . '-' . sanitize_key( self::TAB_SLUG );
> ```
>
> Then introduce `$option_group = $page_slug;` and pass `$option_group` (NOT
> the literal `'acrossai-settings'`) as the first argument to BOTH
> `register_setting()` calls. Leave the `add_settings_section()` +
> `add_settings_field()` calls unchanged — they already take `$page_slug`.
>
> **TASK-3 — refresh the two docblocks that describe the old behaviour.**
> (a) The class-level docblock at lines 1–14 still says "The option group
> stays the shared 'acrossai-settings' so the vendor's settings_fields() emit
> + options.php handoff resolve for every tab." That is now false. Rewrite
> the paragraph to describe the `0.0.13` tab-scoped `option_group`, and
> reference `SettingsPage::get_settings_renderer()`.
> (b) The `register_settings()` docblock at lines ~95–110 still describes
> the shared `'acrossai-settings'` option_group and still references
> `SettingsPage::tab_page_slug()`. Rewrite to describe the tab-scoped slug
> and the `SettingsPageRenderer::tab_page_slug()` instance method, and to
> reference vendor `TabbedPageRenderer::render()` as the authority for why
> the tab-scoped slug is required.
>
> **TASK-4 — verify.** Run these gates from the plugin root:
> - `php -l admin/Partials/SettingsMenu.php` — no syntax errors.
> - `composer phpcs` — zero errors.
> - `composer phpstan` — level 8, zero errors, no new baseline entries.
> - Reload any wp-admin URL after the fix — no `Call to undefined method`
>   fatal on `admin_init`.
> - Navigate to Settings → AcrossAI → MCP tab, toggle "Enable CLI Connections"
>   → Save Changes → success notice, `get_option('acrossai_mcp_npm_login_enabled')`
>   returns `true`. Repeat with "Delete all data on uninstall" toggle.
>
> ---
>
> **CONSTRAINTS (violations = defect):**
>
> - MUST NOT downgrade `acrossai-abilities-manager`'s pin, add a fork, or
>   otherwise force `0.0.11` to load. The correct fix is to move this plugin
>   forward to the class version that actually loads under Jetpack Autoloader.
> - MUST NOT change option names, defaults, or sanitize callbacks. Any
>   existing site with values persisted under those keys continues to work.
> - MUST NOT introduce a `class_exists( \AcrossAI_Main_Menu\SettingsPage::class )`
>   guard around the call. The package is a hard-require in `composer.json`;
>   the guard would silently mask future dependency breakage.
> - MUST NOT introduce a `method_exists()` guard for `get_settings_renderer()`.
>   The pin is `0.0.13` and that method is guaranteed present. Fallback
>   handles the null-return case only, not a missing-method case.
> - MUST NOT bump the plugin's own version, edit the changelog, or ship a
>   release. This is a bugfix that lands on the open 017 branch/PR alongside
>   the ability-selection work.
> - Grep audit AFTER the patch — `grep -n "'acrossai-settings'" admin/Partials/SettingsMenu.php`
>   MUST return zero results (any surviving literal reference to the shared
>   option_group is a defect).
