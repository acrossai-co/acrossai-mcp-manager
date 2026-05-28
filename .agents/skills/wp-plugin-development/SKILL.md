---
name: wp-plugin-development
description: "Use for all development work on plugins built with WPBoilerplate/wordpress-plugin-boilerplate: hooks via the Loader singleton, PSR-4 namespace layout, security baseline (nonces/capabilities/escaping), settings API, REST endpoints, lifecycle, i18n, multisite, performance, and the @wordpress/scripts build pipeline."
compatibility: "WordPress 6.9+ (PHP 8.0+). Uses @wordpress/scripts build pipeline, Jetpack autoloader, and the namespaced PSR-4 layout from the main branch."
---

# WP Plugin Development (WPBoilerplate)

## When to use

This skill applies to **all development work** on plugins built with
[WPBoilerplate/wordpress-plugin-boilerplate](https://github.com/WPBoilerplate/wordpress-plugin-boilerplate).
Every plugin in this project uses this boilerplate — there is no detection step.

Use it for tasks such as:

- adding or moving WordPress hooks/actions/filters
- registering admin pages, menus, or submenu pages
- enqueuing admin or frontend assets (CSS/JS)
- defining new plugin constants
- adding activation, deactivation, or uninstall logic
- adding or modifying i18n / text-domain loading
- implementing settings pages and options
- adding REST API endpoints
- handling file uploads securely
- ensuring multisite compatibility
- refactoring existing code into the namespaced PSR-4 layout
- adding new Gutenberg blocks or block styles
- scaffolding a new plugin via `init-plugin.sh`

## Procedure

### 0) Rename the boilerplate (new plugins only)

If `init-plugin.sh` has **not** yet been run, run it once from the plugin parent directory:

```bash
bash wordpress-plugin-boilerplate/init-plugin.sh
```

The script prompts for a **Title Case** plugin name (e.g. `My Awesome Plugin`) and derives
every identifier automatically:

| Variable | Example | Used for |
|---|---|---|
| `slug` | `my-awesome-plugin` | directory name, text domain, handle |
| `prefix` | `my_awesome_plugin` | function prefixes, option names |
| `define` | `MY_AWESOME_PLUGIN` | PHP constants |
| `namespace` / `class` | `My_Awesome_Plugin` | PSR-4 namespace prefix |

It then does `git mv` + `git grep`/`sed` replacements across every file in the repo.
**After running it**, all boilerplate identifiers (`WordPress_Plugin_Boilerplate`, etc.) are
replaced with your plugin's identifiers. Verify with:

```bash
grep -r "WordPress_Plugin_Boilerplate" . --include="*.php" | grep -v vendor
# should return no results
```

The script also optionally installs extra Composer packages (GitHub updater, etc.) and
removes dev tooling (PHPCS, PHPStan) if you decline them during the interactive prompts.

> **Never** run `init-plugin.sh` on a plugin that is already in production — it does
> in-place find-and-replace across all tracked files.

### 1) Respect the namespace + folder layout

- `Admin\*` classes → `admin/`
- `Includes\*` classes → `includes/`
- `Public\*` classes → `public/`
- New classes must match the PSR-4 map in `composer.json` (and the custom `Autoloader.php`).
- Run `composer dump-autoload` after adding a new class file.

See: `references/structure.md`

### 2) Follow the boot flow; register hooks via the Loader

- Only `WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_FILE` is defined in the bootstrap.
- All other constants belong in `includes/Main.php::define_constants()` using the private
  `define($name, $value)` guard. Never define constants elsewhere.
- **`includes/Main.php` is the single and only place where hooks enter WordPress.**
  `define_admin_hooks()` and `define_public_hooks()` are the only methods that call
  `$this->loader->add_action()` / `$this->loader->add_filter()`, whether directly or by
  delegating to a module's `register_hooks( Loader $loader )` method.
- Feature modules may expose a `register_hooks( Loader $loader )` method, but they must
  **never** call `Loader::instance()` themselves. `includes/Main.php` passes `$this->loader`
  to them — the module does not reach for the Loader on its own.
- `load_dependencies()` is for wiring up the Loader singleton and loading files only.
  **Never call `boot()`, `register_hooks()`, or any hook-registering method from `load_dependencies()`.**
- The `apply_filters('wordpress-plugin-boilerplate-load', true)` gate in `load_hooks()` is the
  supported kill switch for third-party integrations.
- Hook naming convention: `{plugin-prefix}/{context}/{event}` (e.g. `myplugin/admin/before_render`).
- Always return the value in filter callbacks; never `echo` inside a filter.

See: `references/boot-flow.md`, `references/hooks.md`

### 3) Add admin pages and enqueues correctly

- Create new screen classes under `admin/Partials/` with namespace `...\Admin\Partials`.
  **This applies to feature modules too.** If a module has admin UI (menu page, asset enqueue,
  page renderer), that class belongs in `admin/Partials/`, not in `includes/Modules/`.
  `includes/` is for shared, context-neutral code only.
- Instantiate the class in `includes/Main.php::define_admin_hooks()` — not in `load_dependencies()`.
  `load_dependencies()` is only for wiring up the Loader and loading files; hook registration
  belongs in `define_admin_hooks()` / `define_public_hooks()`.
- Register every hook through `$this->loader->add_action(...)` — never directly.
- When replacing or extending an existing menu class (e.g. the boilerplate `Admin\Partials\Menu`),
  **remove the old class and its hook registrations entirely**. Two classes registering
  `add_menu_page()` with the same slug causes silent conflicts — WordPress silently drops one.
- Enqueue assets only on the pages that need them; guard with `get_current_screen()` or the
  `$hook_suffix` argument. **Never enqueue globally on all admin pages.**
- Read asset versions and dependency arrays from the sibling `build/*.asset.php` manifest.
  **Never hardcode the dependency array or version string.**
- Never put `<style>` or `<script>` tags inline in a page-render callback. Enqueue via
  `wp_enqueue_style()` / `wp_enqueue_script()` in the `admin_enqueue_scripts` hook instead.
- Add entry points to `webpack.config.js` and enqueue via `Admin\Main` using the `*.asset.php` manifest.
- Follow notice and UX patterns: dismissible notices, no aggressive upsells, one top-level menu item.

See: `references/admin.md`, `references/admin-ux.md`

### 4) Security baseline (always)

Before shipping any feature:

- Validate/sanitize input early (`wp_unslash()` + `sanitize_*()`) — never trust `$_POST`/`$_GET` raw.
- Escape output late and close to the point of output (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`).
- Nonce on every state-changing form and AJAX handler (`wp_nonce_field()` / `check_admin_referer()`).
- Capability check before every admin action or data mutation (`current_user_can()`).
- Use `$wpdb->prepare()` for SQL — never string concatenation.
- Every `register_rest_route()` must have a `permission_callback`.

Run the security scanner before committing:

```bash
node skills/wp-plugin-development/scripts/validate-security.mjs --dir=.
```

See: `references/security.md`

### 5) Settings API

- Register every option with `register_setting()` + a `sanitize_callback`.
- Use `add_settings_section()` + `add_settings_field()` for the settings UI.
- Option group: `{plugin_prefix}_options`. Always supply a default in `get_option('key', $default)`.
- Store related settings as a single serialized array to avoid autoload bloat.
- Set `autoload = false` for options not needed on every page load.

See: `references/settings-api.md`

### 6) Data storage, cron, migrations

- Prefer options (`get_option` / `update_option`) for small config; custom tables only if the data volume or query patterns make options impractical.
- For custom tables, install via [`berlindb/core`](https://github.com/berlindb/core) as a Composer package — it provides query, schema, and row classes that wrap `$wpdb` safely and consistently.
- For cron tasks, ensure idempotency (safe to run twice) and provide a manual run path via WP-CLI or an admin action.
- For schema changes, write upgrade routines keyed on a stored schema version option; never assume the DB matches the current code.

See: `references/lifecycle.md`, `references/performance.md`

### 7) REST API endpoints

- Register on `rest_api_init`; namespace format: `{plugin-prefix}/v1`.
- Always provide `permission_callback` — never omit, never `__return_true` on mutating routes.
- Sanitize all args via `sanitize_callback` in the route schema.
- Return `WP_REST_Response` or `WP_Error`.

Detect registered endpoints:

```bash
node skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
```

See: `references/rest-api.md`

### 8) Internationalization

- Text domain must equal the plugin slug (directory name).
- Load on `init` with `load_plugin_textdomain()` — not `plugins_loaded`.
- Always escape after translating: `esc_html__()`, `esc_attr__()`, `esc_html_e()`. Never `echo __()`.
- JS strings: `wp_set_script_translations()` + JSON file in `languages/`.
- **Never call `get_plugin_data()` with `$translate = true` (the default) before `init`.**
  Doing so causes WordPress 6.7+ to trigger JIT translation loading and log a `_doing_it_wrong`
  notice. Always pass `get_plugin_data( $file, false, false )` when reading headers early in the
  boot sequence, or avoid `get_plugin_data()` entirely and hardcode the version constant.

See: `references/i18n.md`

### 9) Multisite

- Gate multisite-specific code with `is_multisite()`.
- Use `get_site_option()` / `update_site_option()` for network-wide settings.
- Always pair `switch_to_blog()` with `restore_current_blog()` in a `finally` block.
- Handle both per-site and network activation in the activation hook.

See: `references/multisite.md`

### 10) Frontend code under public/

- All frontend classes go under `public/` with namespace `...\Public`.
- Instantiate in `includes/Main.php::define_public_hooks()` and register via the Loader.
- Source JS → `src/js/`, source SCSS → `src/scss/`. Never edit `build/` directly.
- Read asset versions and dependencies from `build/*.asset.php` — never hardcode.
- **Do not enqueue frontend assets globally.** `Public\Main::enqueue_styles()` and
  `enqueue_scripts()` must scope their output with WordPress conditional tags
  (`is_singular()`, `is_page()`, `is_post_type_archive()`, etc.) unless the plugin
  genuinely needs the asset on every front-end page. Unconditional enqueue on every
  page load wastes bandwidth and risks conflicts.

See: `references/public.md`

### 11) Lifecycle: Activator, Deactivator, uninstall

- Activation setup (tables, options, roles) → `Includes\Activator::activate()`.
- Deactivation lightweight cleanup (cron, transients) → `Includes\Deactivator::deactivate()`.
- Data removal (delete_option, drop tables) → `uninstall.php` only.
- On multisite: delete both site-level and network-level options in uninstall.

See: `references/lifecycle.md`

### 12) Build assets through @wordpress/scripts

- Run `npm run build` (production) or `npm run start` (watch) before testing.
- Standard JS/SCSS entries, custom blocks (`src/blocks/**/block.json`), and block stylesheets
  (`src/scss/blocks/core/`) are all picked up automatically by `webpack.config.js`.
- Static assets (`src/media/`, `src/fonts/`) are copied to `build/` by CopyPlugin.

**To add a new JS or CSS file**, follow this 5-step workflow:

1. **Create** the source file in `src/js/<name>.js` and/or `src/scss/<name>.scss`.
2. **Register** a new entry in `webpack.config.js` `entry:` object.
3. **Load the manifest** — add `include …build/js/<name>.asset.php` in the relevant `Main` constructor.
4. **Enqueue** — use the manifest arrays for dependencies and version. Never hardcode either.
5. **Build** — run `npm run build` and confirm `build/js/<name>.js` + `build/js/<name>.asset.php` are present.

See: `references/build-system.md`

## Pre-ship checklist

Run these scripts from the plugin root before shipping:

```bash
# Structure validation
node skills/wp-plugin-development/scripts/validate-structure.mjs --dir=.

# Security scan
node skills/wp-plugin-development/scripts/validate-security.mjs --dir=.

# Deprecated function scan
node skills/wp-plugin-development/scripts/detect-deprecations.mjs --dir=.

# REST endpoint audit (exits 1 if any endpoint is missing permission_callback)
node skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
```

## Verification

- `composer dump-autoload` completes with no errors.
- `npm run build` produces `*.asset.php` for every enqueued entry.
- Plugin activates with no PHP fatals or notices (`WP_DEBUG=true`).
- Admin menu item appears at `/wp-admin/admin.php?page=<plugin-slug>`.
- Frontend assets enqueue only on the correct pages.
- Settings save and read correctly (capability check + nonce enforced).
- Uninstall removes all intended data — and nothing else.
- PHPCS passes: `./vendor/bin/phpcs` (WordPress-Extra + WordPress-Docs + PHPCompatibility).
- PHPUnit passes (if present); JS build succeeds if the plugin ships assets.
- All four pre-ship scripts above exit 0.

## Failure modes / debugging

- **PHP fatal on every page load** — `build/*.asset.php` missing; run `npm run build`.
- **Class not found** — namespace mismatch in file path, or `composer dump-autoload` not run.
- **Constants double-defined notice** — constant defined outside `define_constants()`; move inside and use the private `define()` guard.
- **Hooks not firing** — registered with bare `add_action` instead of through the Loader, or `apply_filters('wordpress-plugin-boilerplate-load', true)` filtered to `false`.
- **Asset 404 after build** — check `WORDPRESS_PLUGIN_BOILERPLATE_PLUGIN_URL` (see `boot-flow.md`).
- **Activation hook not firing** — hook not registered at plugin file root scope; must be top-level, not inside a class constructor or `init` callback.
- **Activation / deactivation callback not running** — bootstrap registers namespaced functions, not class methods; the Autoloader is not active at that point. Do not refactor these to instance methods.
- **Settings not saving** — option not registered with `register_setting()`, wrong option group, missing capability, or nonce failure.
- **Security regression** — nonce without capability check (or vice versa); input sanitized but output not escaped.
- **Text-domain not loading** — `do_load_textdomain()` moved to `plugins_loaded`; it must stay on `init`.
- **`_load_textdomain_just_in_time` notice (WP 6.7+)** — a translation function or `get_plugin_data()` (with `$translate=true`) is being called before `init`. Fix: ensure no `__()` / `_e()` / `get_plugin_data()` call with the plugin's text domain runs before `init`. Version constants must be hardcoded, not read via `get_plugin_data()`.
- **Vendor conflict** — run `composer install` then `composer dump-autoload`; Mozart may need to re-scope namespaces.

See: `references/debugging.md`

## Escalation

- Boilerplate `agents.md`: `https://github.com/WPBoilerplate/wordpress-plugin-boilerplate/blob/main/agents.md`
- Boilerplate README: `https://github.com/WPBoilerplate/wordpress-plugin-boilerplate/blob/main/README.md`
- WordPress Plugin Handbook: `https://developer.wordpress.org/plugins/`
