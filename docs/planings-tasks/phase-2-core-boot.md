# Phase 2 — Core Boot Flow Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/Core/Plugin.php          ← old singleton; use as checklist for Main.php wiring
  src/Core/Compat.php          ← move to includes/Compat.php
  src/Core/polyfills.php       ← move to includes/polyfills.php
  acrossai-mcp-manager.php     ← plugin header, activation hooks
  composer.json                ← vendor packages to carry over

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Extend (do NOT replace) these existing files:
  includes/Main.php
  includes/Activator.php
  includes/Deactivator.php
  includes/I18n.php
  acrossai-mcp-manager.php
```

---

## What this phase covers

The old `src/Core/Plugin.php` is a manual singleton that:
- Constructs all dependencies directly in `__construct()`
- Calls `add_action()` directly inside the constructor
- Mixes hook registration with object wiring

The new branch already has the correct `includes/Main.php` Loader singleton.
This phase wires the existing boilerplate `Main.php` to boot all the modules
that the old `Core\Plugin` used to instantiate, following the WPBoilerplate
boot flow strictly.

### Old code to retire

- `src/Core/Plugin.php` — replaced by `includes/Main.php` + `define_admin_hooks()` / `define_public_hooks()`
- `src/Core/Compat.php` — moves to `includes/Compat.php`
- `src/Core/polyfills.php` — loaded inside `Main::load_composer_dependencies()`

### Boot flow changes

```
OLD (main branch)                     NEW (boilerplate)
─────────────────────────────────     ────────────────────────────────────────
plugins_loaded                        acrossai_mcp_manager_run() [at file scope]
  └─ MCPServerTable::maybe_create()     └─ Main::instance()
  └─ Plugin::instance()                   └─ define_constants()
       └─ new Settings()                  └─ load_composer_dependencies()
            └─ add_action(admin_init)     └─ load_dependencies()  ← Loader only
            └─ add_action(admin_menu)     └─ set_locale()
            └─ add_action(admin_enqueue)  └─ load_hooks()
       └─ new Controller()                   └─ define_admin_hooks()
       └─ new CliController()                   └─ $loader->add_action(...)
       └─ new FrontendAuth()               └─ define_public_hooks()
       └─ new ClaudeConnectors()               └─ $loader->add_action(...)
       └─ new AccessControlManager()    plugins_loaded → $plugin->run()
       └─ add_action(rest_api_init)
       └─ add_filter(rest_pre_dispatch)
```

---

## Spec-Kit Steps

### Step 1: `/speckit.specify`

```
/speckit.specify

Feature: Core Boot Flow — WPBoilerplate Loader Migration
Feature number: 002

We are completing the core boot setup for the migrated plugin. The existing
includes/Main.php has the correct boilerplate structure. This spec covers what
the boot flow must initialise and how, per the wp-plugin-development skill rules.

Requirements:

1. Boot sequence must follow this exact order:
   define_constants() → load_composer_dependencies() → load_dependencies() →
   set_locale() → load_hooks() → [plugins_loaded fires] → run()

2. define_constants() defines these constants using the private define() guard:
   ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME, ACROSSAI_MCP_MANAGER_PLUGIN_PATH,
   ACROSSAI_MCP_MANAGER_PLUGIN_URL, ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG,
   ACROSSAI_MCP_MANAGER_PLUGIN_NAME, ACROSSAI_MCP_MANAGER_VERSION.
   No constants may be defined anywhere else in the plugin.

3. load_dependencies() creates only the Loader singleton. It MUST NOT call any
   boot(), register_hooks(), or hook-registering method.

4. define_admin_hooks() instantiates admin objects and registers their hooks
   via $this->loader->add_action() / add_filter(). The following modules
   must be wired here (each will be implemented in later phases):
   - Admin\Main (enqueue styles/scripts on admin_enqueue_scripts)
   - Admin\Partials\Menu (admin_menu)
   - Admin\Partials\Settings (admin_init for handle_actions + register_settings)
   - Admin\Partials\ApplicationPasswords (its hooks)
   - REST\CliController (rest_api_init)
   - Includes\MCP\Controller (rest_api_init)
   - Includes\OAuth\ClaudeConnectors (rest_api_init + template_redirect + init)
   - Access control: rest_pre_dispatch filter

5. define_public_hooks() instantiates public objects:
   - Public\Main (wp_enqueue_scripts)
   - Public\Partials\FrontendAuth (init, template_redirect)

6. Activation (register_activation_hook at file root — not inside a class):
   calls AcrossAI_MCP_Manager\acrossai_mcp_manager_activate() which requires
   includes/Activator.php and calls Activator::activate().

7. includes/Compat.php moves here: a static helper class with methods for
   WordPress version compatibility checks. Loaded in load_dependencies().

8. The apply_filters('acrossai_mcp_manager_load', true) kill switch in
   load_hooks() must be preserved for third-party integrations.
```

### Step 2: `/speckit.plan`

```
/speckit.plan

Technical approach for the core boot migration:

1. includes/Main.php — extend the existing file:
   - Add define_constants() with all 6 constants (already partially done)
   - In load_hooks(), ensure apply_filters('acrossai_mcp_manager_load', true) gate
   - In define_admin_hooks(): add stubs for each module's hooks as TODO comments
     if the module has not been migrated yet (phases 3–8). Stubs use
     // TODO: wire after phase N once that class exists.
   - In define_public_hooks(): add stub for FrontendAuth.

2. includes/Compat.php — new file:
   - Move static helper methods from src/Core/Compat.php verbatim
   - Update namespace from ACROSSAI_MCP_MANAGER\Core to AcrossAI_MCP_Manager\Includes

3. includes/Activator.php — extend existing file:
   - Add calls to all DB Query::maybe_create_table() methods — MCPServer\Query and CliAuthLog\Query (created in this same phase)
   - Add rewrite rule registration for FrontendAuth::PAGE_SLUG
   - Add rewrite rule for ClaudeConnectors::AUTHORIZE_PATH
   - Add flush_rewrite_rules()
   - Add MCPServer\Query::insert_default_server()

4. Plugin root file (acrossai-mcp-manager.php) — update:
   - define('ACROSSAI_MCP_MANAGER_PLUGIN_FILE', __FILE__) at top
   - acrossai_mcp_manager_activate() and deactivate() functions (already there)
   - register_activation_hook and register_deactivation_hook (already there)
   - acrossai_mcp_manager_run() kicks off Main::instance() (already there)

5. Remove src/Core/Plugin.php entirely once all modules have been moved to
   their new locations in later phases. Do NOT delete it in this phase — it is
   still the running code until all modules are migrated.

Namespace note: Main.php uses AcrossAI_MCP_Manager\Includes namespace.
Compat.php namespace: AcrossAI_MCP_Manager\Includes.
```

### Step 3 + 4: `/speckit.tasks` then `/speckit.implement`

Run spec-kit tasks generation, then implement each task.

---

## Success Criteria

- [ ] `includes/Main.php` has `define_constants()` with all 6 constants using `define()` guard
- [ ] `load_dependencies()` only creates the Loader — no boot/register calls
- [ ] `load_hooks()` has the `acrossai_mcp_manager_load` filter gate
- [ ] `includes/Compat.php` exists with updated namespace
- [ ] `includes/Activator.php` calls DB table bootstrappers and rewrite rule setup
- [ ] Plugin activates with WP_DEBUG=true — no fatal errors or notices
- [ ] No `define()` calls outside `Main::define_constants()`
