# Implementation Plan: Core Boot Flow — WPBoilerplate Loader Migration

**Branch**: `feature/issue-3` | **Date**: 2026-05-29 | **Spec**: [spec.md](spec.md)
**Memory Synthesis**: [memory-synthesis.md](memory-synthesis.md)
**Input**: `specs/001-core-boot-flow/spec.md` (FR-001 to FR-010, 3 clarifications)

## Summary

Migrate the core plugin boot flow from the legacy flat `src/Core/Plugin.php`
singleton to the WPBoilerplate Loader pattern. Extends `includes/Main.php` with
full hook-registration stubs, fixes a null-property constant bug, creates
`includes/Compat.php` (ported from source), and extends `includes/Activator.php`
with idempotent DB table bootstrapping and rewrite-rule registration.

`src/Core/Plugin.php` is NOT deleted in this phase — it remains running code
until all feature modules are migrated in Phases 3–8.

## Technical Context

**Language/Version**: PHP 8.0+ (`Requires PHP: 8.0` in plugin header); PHP 7.4
compat polyfills available via `Compat` class (`PHP_MIN = '7.4'`)
**Primary Dependencies**: `automattic/jetpack-autoloader ^5.0` (Composer);
`wordpress/mcp-adapter 0.5.0` (Phase 4); `wpboilerplate/wpb-access-control ^1.0`
**Storage**: Three custom DB tables via `dbDelta()` (schema in Phase 4; bootstrapped here)
**Testing**: `vendor/bin/phpcs` (WPCS strict), `vendor/bin/phpstan --level=8`;
no PHPUnit in this phase
**Target Platform**: WordPress 6.9+ / PHP 8.0+ / Linux server (WP hosting)
**Project Type**: WordPress plugin (admin + public + REST API)
**Performance Goals**: Activation must complete without timeout; `maybe_create_table()`
uses `dbDelta()` which is idempotent and safe on repeated calls
**Constraints**: Zero `add_action()`/`add_filter()` in any constructor (A1);
named variable required before every Loader call (A2); ABSPATH guard on all PHP files;
no new REST routes, no new admin UI in this phase
**Scale/Scope**: Single-site install; ~4 PHP files changed; no JS/CSS

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Gate | Status | Notes |
|---|---|---|
| A1: All hooks via Loader in Main.php only | REQUIRED | All wiring in define_admin_hooks() / define_public_hooks() only |
| A2: Singleton pattern + named variable before Loader | REQUIRED + FIX | Admin\Main, Menu, Public\Main use `new`; convert to `::instance()` |
| A3: Admin classes in admin/Partials/ | SATISFIED | Admin\Main, Menu already there; no change |
| III: ABSPATH guard on all PHP files | REQUIRED | New Compat.php must include it |
| III: S4 — wpdb->prepare() | DEFERRED | DB table creation in Phase 4 Query classes; any direct $wpdb call uses prepare() |
| FR-003: null-property constant bug | FIX REQUIRED | define_constants() must use literal 'acrossai-mcp-manager', not $this->plugin_name |
| FR-003: $this->version uninitialised | FIX REQUIRED | Set after define_constants() in constructor |
| I: Compat.php placement exception | JUSTIFIED | Boot-time compat shim must load before Utilities/ classes; documented deviation |
| Namespace resolution (Activator.php) | CRITICAL | Must use `use` imports or FQN with leading \ for all DB class references |

**Constitution Check post-design**: All gates SATISFIED. No unjustified violations.

## Project Structure

### Documentation (this feature)

```text
specs/001-core-boot-flow/
├── spec.md              # Feature specification (complete, 3 clarifications)
├── plan.md              # This file
├── research.md          # Phase 0: Compat methods, hook inventory, rewrite rules
├── data-model.md        # Phase 1: DB tables bootstrapped at activation
├── quickstart.md        # Phase 1: Boot verification steps
├── memory-synthesis.md  # Memory context for this plan
├── security-constraints.md  # Security review findings
└── checklists/
    └── requirements.md
```

### Source Code (repository root — WordPress plugin)

```text
# EXTEND (do not replace — these files already exist)
includes/Main.php
    ├─ define_constants()        fix: use literal for PLUGIN_NAME_SLUG; set $this->version
    ├─ define_admin_hooks()      fix: convert new→::instance(); add TODO stubs for phases 3–7
    └─ define_public_hooks()     fix: convert new→::instance(); add TODO stub for FrontendAuth

includes/Activator.php
    └─ activate()                add: DB table class_exists guards + flush_rewrite_rules()

acrossai-mcp-manager.php         verify: PLUGIN_FILE constant + activation hook (no changes needed)

# NEW FILE
includes/Compat.php              new: port from src/Core/Compat.php, update namespace

# NOT TOUCHED in this phase
src/Core/Plugin.php              still running; do NOT delete until all modules migrated
admin/Main.php                   boilerplate stub; only conversion from new to ::instance()
admin/Partials/Menu.php          boilerplate stub; only conversion from new to ::instance()
public/Main.php                  boilerplate stub; only conversion from new to ::instance()
```

**Structure Decision**: Single-project WordPress plugin layout.
Hook registration exclusively in `Main.php`; utility class in `includes/`;
activation logic in `Activator.php`.

## Complexity Tracking

> No constitution violations requiring justification in this phase.

One accepted deviation from Principle I (Compat.php placement):

| Deviation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| `includes/Compat.php` (not `includes/Utilities/`) | Compat is a boot-time shim loaded before Utilities/ classes via Jetpack autoloader init order | Moving to `Utilities/` risks class-not-found on PHP < 8.0 before the full autoloader has resolved the Utilities namespace |

---

## Phase 0 — Research (complete)

See [research.md](research.md) for full findings. Summary:

| Item | Decision |
|---|---|
| Compat methods | Port all 8 verbatim from `src/Core/Compat.php` |
| ClaudeConnectors hooks | 10 hooks enumerated; all become TODO stubs (Phase 6) |
| FrontendAuth hooks | 5 hooks enumerated; all become TODO stubs (Phase 3) |
| Rewrite rules | 4 literal string rules in Activator (no class constant dependency) |
| DB bootstrap order | MCPServer → CliAuthLog → ConnectorAuditLog; all `class_exists()`-guarded |
| Namespace in Activator | MUST use `use` imports; bare relative names resolve incorrectly |
| `$this->version` | Set after `define_constants()`; ACROSSAI_MCP_MANAGER_VERSION |
| `new` vs `::instance()` | Convert all 3 boilerplate classes to singleton pattern in this phase |

---

## Phase 1 — Design

See [data-model.md](data-model.md) and [quickstart.md](quickstart.md).

No new REST interfaces or external contracts are introduced in this phase —
see [contracts/](contracts/) (empty; to be populated by Phase 5).

---

## Implementation Design

### Phase A — `includes/Main.php`

**Action**: Extend (do NOT replace)

**Change 1 — Fix `define_constants()` null-property bug (FR-003)**:
```php
// BEFORE (bug: $this->plugin_name is null here)
$this->define( 'ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG', $this->plugin_name );

// AFTER
$this->define( 'ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG', 'acrossai-mcp-manager' );
```

**Change 2 — Set `$this->version` in constructor**:
```php
public function __construct() {
    $this->define_constants();
    $this->version     = ACROSSAI_MCP_MANAGER_VERSION;   // ← ADD
    $this->plugin_name = 'acrossai-mcp-manager';
    $this->plugin_dir  = ACROSSAI_MCP_MANAGER_PLUGIN_PATH;
    // ... rest unchanged
}
```

**Change 3 — Convert `define_admin_hooks()` to singleton pattern + add stubs**:
```php
private function define_admin_hooks() {

    $plugin_admin = \AcrossAI_MCP_Manager\Admin\Main::instance();
    $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
    $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

    $main_menu = \AcrossAI_MCP_Manager\Admin\Partials\Menu::instance();
    $this->loader->add_action( 'admin_menu', $main_menu, 'main_menu' );
    $this->loader->add_action( 'plugin_action_links', $main_menu, 'plugin_action_links', 1000, 2 );

    // TODO: wire Admin\Partials\Settings after phase 3
    // $settings = \AcrossAI_MCP_Manager\Admin\Partials\Settings::instance();
    // $this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );
    // $this->loader->add_action( 'admin_init', $settings, 'register_settings' );

    // TODO: wire Admin\Partials\ApplicationPasswords after phase N

    // TODO: wire Includes\MCP\Controller after phase 4
    // $mcp_controller = \AcrossAI_MCP_Manager\Includes\MCP\Controller::instance();
    // $this->loader->add_action( 'rest_api_init', $mcp_controller, 'initialize_adapter' );

    // TODO: wire REST\CliController after phase 5
    // $cli_controller = \AcrossAI_MCP_Manager\Includes\REST\CliController::instance();
    // $this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );

    // TODO: wire Includes\OAuth\ClaudeConnectors after phase 6
    // $claude = \AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors::instance();
    // $this->loader->add_action( 'init', $claude, 'register_rewrite_rules' );
    // $this->loader->add_action( 'init', $claude, 'maybe_flush_rewrite_rules', 20 );
    // $this->loader->add_filter( 'query_vars', $claude, 'add_query_vars' );
    // $this->loader->add_filter( 'redirect_canonical', $claude, 'disable_canonical_redirects', 10, 2 );
    // $this->loader->add_action( 'wp_enqueue_scripts', $claude, 'enqueue_assets' );
    // $this->loader->add_action( 'template_redirect', $claude, 'handle_frontend_request' );
    // $this->loader->add_action( 'rest_api_init', $claude, 'register_rest_routes' );
    // $this->loader->add_filter( 'determine_current_user', $claude, 'determine_current_user_from_bearer', 20 );
    // $this->loader->add_filter( 'rest_post_dispatch', $claude, 'decorate_mcp_response', 10, 3 );
    // $this->loader->add_action( 'acrossai_mcp_access_denied', $claude, 'log_access_denied_event', 10, 4 );

    // TODO: wire rest_pre_dispatch access-control filter after phase 7
    // $access_control = \WPBoilerplate\AccessControl\AccessControlManager::instance( 'acrossai_mcp_access_control_providers' );
    // $this->loader->add_filter( 'rest_pre_dispatch', $access_control, 'enforce_access', 10, 3 );
}
```

**Change 4 — Convert `define_public_hooks()` to singleton pattern + add stubs**:
```php
private function define_public_hooks() {

    $plugin_public = \AcrossAI_MCP_Manager\Public\Main::instance();
    $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
    $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

    // TODO: wire Public\Partials\FrontendAuth after phase 3
    // $frontend_auth = \AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::instance();
    // $this->loader->add_action( 'init', $frontend_auth, 'register_rewrite_rule' );
    // $this->loader->add_action( 'init', $frontend_auth, 'maybe_flush_rewrite_rules', 20 );
    // $this->loader->add_filter( 'query_vars', $frontend_auth, 'add_query_var' );
    // $this->loader->add_action( 'wp_enqueue_scripts', $frontend_auth, 'enqueue_assets' );
    // $this->loader->add_action( 'template_redirect', $frontend_auth, 'handle_request' );
}
```

**Constraint checks**: A1 ✓ (all hooks via Loader); A2 ✓ (named variables, `::instance()`);
ABSPATH guard already present ✓.

---

### Phase B — `includes/Compat.php` (new file)

**Action**: New file — port from `src/Core/Compat.php`

Changes from source:
1. Namespace: `ACROSSAI_MCP_MANAGER\Core` → `AcrossAI_MCP_Manager\Includes`
2. ABSPATH guard: replace `if ( ! defined(...) ) { exit; }` with `defined('ABSPATH') || exit;`
3. All 8 static methods: port verbatim (no changes to method bodies)
4. Constants: `PHP_MIN = '7.4'`, `PHP_MAX = '8.5'` — port unchanged
5. No `require_once` — PSR-4 auto-loaded by Jetpack autoloader

---

### Phase C — `includes/Activator.php`

**Action**: Extend existing `activate()` static method

```php
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLog\Query as ConnectorAuditLogQuery;

// (inside the class body, at the top of Activator.php — after namespace declaration)

public static function activate() {

    // 1. DB table bootstrapping — silently skip if class absent (FR-009).
    if ( class_exists( MCPServerQuery::class ) ) {
        MCPServerQuery::maybe_create_table();
    }
    if ( class_exists( CliAuthLogQuery::class ) ) {
        CliAuthLogQuery::maybe_create_table();
    }
    if ( class_exists( ConnectorAuditLogQuery::class ) ) {
        ConnectorAuditLogQuery::maybe_create_table();
    }

    // 2. Register rewrite rules with placeholder query vars.
    //    Handler classes (FrontendAuth, ClaudeConnectors) do not need to exist —
    //    requests return graceful 404 until Phase 3/6 implement the handlers.
    add_rewrite_rule(
        '^acrossai-mcp-manager/?$',
        'index.php?mcp_frontend_auth=1',
        'top'
    );
    add_rewrite_rule(
        '^acrossai-mcp-connectors/oauth/authorize/?$',
        'index.php?mcp_oauth_authorize=1',
        'top'
    );
    add_rewrite_rule(
        '^\.well-known/oauth-authorization-server/?$',
        'index.php?mcp_oauth_metadata=1',
        'top'
    );
    add_rewrite_rule(
        '^\.well-known/oauth-protected-resource/?$',
        'index.php?mcp_oauth_metadata_resource=1',
        'top'
    );

    // 3. Flush rewrite rules immediately so routes take effect on first request.
    flush_rewrite_rules();
}
```

**Note on `insert_default_server()`**: `MCPServerTable::maybe_create_table()`
in the source calls `insert_default_server()` internally (line 297). When the
target `MCPServerQuery::maybe_create_table()` is implemented in Phase 4, it
MUST follow the same pattern. The Activator does NOT call `insert_default_server()`
directly — it is an internal concern of the Query class.

---

### Phase D — `acrossai-mcp-manager.php`

**Action**: Verify only — no changes expected

Confirm these already exist (they do, confirmed in previous session):
- `define('ACROSSAI_MCP_MANAGER_PLUGIN_FILE', __FILE__)` at file root
- `register_activation_hook( __FILE__, 'AcrossAI_MCP_Manager\acrossai_mcp_manager_activate' )`
- `acrossai_mcp_manager_run()` → `Main::instance()` called on `plugins_loaded`

---

## Validation Steps

```bash
# PHPCS — run after every file change
vendor/bin/phpcs includes/Main.php includes/Compat.php includes/Activator.php

# PHPStan level 8 — run after all files are changed
vendor/bin/phpstan analyse includes/Main.php includes/Compat.php includes/Activator.php --level=8

# Hook-call audit (should return zero outside Loader.php and Main.php)
grep -rn "add_action\|add_filter" includes/ admin/ public/ | grep -v "Loader.php\|Main.php"

# Smoke test — activate plugin with WP_DEBUG=true, check debug.log
```

---

## Risks & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `Admin\Main` / `Menu` / `Public\Main` don't yet implement `::instance()` | High | Medium | Add singleton to all three as part of Phase A |
| Namespace resolution error in Activator DB calls | High without fix | Silent failure | Use `use` import aliases (researched in Phase 0) |
| `$this->version` null causes warnings in Admin\Main constructor | Medium | Low | Fixed in Phase A change 2 |
| `maybe_create_table()` not yet implemented on target Query classes | Expected | Low | `class_exists()` guard ensures silent skip; tables created when Phase 4 merges |
| `flush_rewrite_rules()` performance on large sites | Low | Low | One-time activation cost; unavoidable |
