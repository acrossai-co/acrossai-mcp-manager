# Feature Specification: Core Boot Flow — WPBoilerplate Loader Migration

**Feature Number**: 001
**Feature Branch**: `feature/issue-3`
**Created**: 2026-05-29
**Status**: Draft
**Spec**: `specs/001-core-boot-flow/spec.md`

---

## Clarifications

### Session 2026-05-29

- Q: When rewrite rules for `/mcp-auth/` and OAuth paths are registered during activation (FR-009), but handler classes (`FrontendAuth`, `ClaudeConnectors`) don't exist yet, should the Activator register rules immediately or defer? → A: Register rewrite rules immediately with placeholder query vars; handler resolves to graceful WordPress 404 until classes are implemented in later phases.

- Q: When `class_exists()` returns false in `Activator::activate()` (DB classes not yet implemented), what should the Activator do? → A: Silently skip — pure no-op per class. Guard pattern: `if ( class_exists( Includes\Database\MCPServer\Query::class ) ) { ... }`. No log, no notice, no flag in wp_options.

- Q: Should `get_plugin_name()` return `$this->plugin_name` or the constant `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` after FR-003 is applied? → A: Keep returning `$this->plugin_name` — same value, zero functional difference; switching the return source would touch every caller, update PHPDoc, and risk an ordering bug in test bootstraps that don't load the full plugin. FR-003 only concerns the constant definition.

- Q: Who is responsible for inserting the default MCP server row — Activator directly, or `MCPServerQuery::maybe_create_table()` internally? → A: `MCPServerQuery::maybe_create_table()` handles default row insertion internally in Phase 4; Activator does NOT call `insert_default_server()` directly. FR-009 sub-requirement removed from this phase.

- Q: Should the Access Control TODO stub in `define_admin_hooks()` use the vendor package namespace `\WPBoilerplate\AccessControl\AccessControlManager` or an internal plugin class? → A: Use the vendor package namespace `\WPBoilerplate\AccessControl\AccessControlManager` per `wpb-access-control ^1.0` composer dependency declared in source repo. Phase 7 consumes the package; no internal class is created.

- Q: Which method names should ClaudeConnectors and FrontendAuth TODO stubs use — plan.md names or the names used during implementation? → A: plan.md method names are canonical. Updated stubs to use: `decorate_mcp_response`, `log_access_denied_event`, `register_rest_routes`, `handle_frontend_request`, `determine_current_user_from_bearer`, `disable_canonical_redirects`, `enqueue_assets` (ClaudeConnectors); `add_query_var` (singular), `enqueue_assets` (FrontendAuth). Plan.md is the source of truth for all Phase 6/3 stub method names.

- Q: Should PHPCS pre-existing violations (filename casing, `$_instance` prefix, missing file doc, `namespace Public` reserved keyword) be excluded as a baseline or fixed in this phase? → A: Excluded as documented baseline exceptions in `phpcs.xml.dist`. These are structural boilerplate violations that cannot be fixed without renaming files or restructuring the namespace — both out of scope for a boot-flow migration. Seven exclusion rules added to `phpcs.xml.dist`. PHPCS now exits 0 on all 6 modified files. Spec DoD gate updated to reflect this.

- Q: US6 Scenario 2 says WordPress routes `/mcp-auth/` to the frontend auth handler — but the Edge Cases section says it returns a graceful 404 until FrontendAuth is implemented. Which is correct for Phase 1? → A: Scenario 2 is forward-looking (Phase 3+). Reworded to add precondition "AND `FrontendAuth` has been implemented (Phase 3+)" so it is not a Phase 1 acceptance criterion.

## User Scenarios & Testing

### User Story 1 — Plugin Boots Without Errors (P1)

A developer activating the migrated plugin on WordPress 6.9 / PHP 8.0 sees no
fatal errors or PHP notices, and the AcrossAI MCP Manager admin menu appears.

**Acceptance Scenarios**:

1. **Given** the plugin is inactive, **When** a site admin activates it, **Then**
   activation completes without fatal errors, and the admin menu is visible.
2. **Given** the plugin is active, **When** any admin page loads, **Then** no PHP
   notices or warnings appear in the debug log with `WP_DEBUG=true`.
3. **Given** `apply_filters('acrossai_mcp_manager_load', false)` is set by a
   third-party plugin, **When** WordPress loads plugins, **Then** neither
   `define_admin_hooks()` nor `define_public_hooks()` executes — the plugin
   silently skips hook registration.

---

### User Story 2 — Boot Sequence Completes in Strict Order (P1)

A developer reading `includes/Main.php` can confirm the constructor calls
five methods in this exact sequence: `define_constants()` →
`load_composer_dependencies()` → `load_dependencies()` → `set_locale()` →
`load_hooks()`. The plugin then waits for the `plugins_loaded` event before
calling `run()`.

**Acceptance Scenarios**:

1. **Given** `Main::instance()` is called, **When** the constructor runs, **Then**
   each of the five initialisation steps executes in order with no step skipped
   or reordered.
2. **Given** `load_dependencies()` runs, **When** its body is inspected, **Then**
   it contains only `$this->loader = Loader::instance()` — no `boot()`,
   `register_hooks()`, or hook-registration calls.
3. **Given** `Main::instance()` returns, **When** the `plugins_loaded` event has
   not yet fired, **Then** no WordPress hooks are registered yet.

---

### User Story 3 — All Six Constants Available Everywhere in the Plugin (P1)

Any plugin class can reference `ACROSSAI_MCP_MANAGER_PLUGIN_PATH`,
`ACROSSAI_MCP_MANAGER_VERSION`, and the other four plugin constants without
calling any function — because they are defined once, at boot, from a single
location.

**Acceptance Scenarios**:

1. **Given** `Main::instance()` has run, **When** `defined('ACROSSAI_MCP_MANAGER_VERSION')`
   is checked, **Then** it returns `true` and the value is non-empty.
2. **Given** any plugin class file is opened, **When** it is searched for
   `define(` calls, **Then** zero results are found — all constants are
   defined only in `Main::define_constants()`.
3. **Given** `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` is read, **When** compared
   to the plugin's text domain, **Then** it equals `'acrossai-mcp-manager'`
   (a literal string — not derived from an uninitialised property).

---

### User Story 4 — All Module Hooks Wired Through the Loader (P1)

A developer inspecting `admin/`, `includes/`, and `public/` can confirm that
zero `add_action()` or `add_filter()` calls appear in any class constructor.
Every hook is registered exclusively through the Loader inside
`define_admin_hooks()` or `define_public_hooks()`.

**Acceptance Scenarios**:

1. **Given** `define_admin_hooks()` is invoked, **When** the Loader runs, **Then**
   hooks for `admin_enqueue_scripts`, `admin_menu`, `admin_init`, `rest_api_init`,
   and `rest_pre_dispatch` are all registered.
2. **Given** `define_public_hooks()` is invoked, **When** the Loader runs, **Then**
   hooks for `wp_enqueue_scripts`, `init`, and `template_redirect` are registered.
3. **Given** any feature-module class file, **When** its constructor is read,
   **Then** no `add_action()` or `add_filter()` call appears.

---

### User Story 5 — Compat Helpers Available to All PHP 7.4–8.5 Classes (P2)

Any plugin class can call `Compat::str_contains()`, `Compat::supports()`, and
sibling static helpers without guarding on PHP version or requiring any file.

**Acceptance Scenarios**:

1. **Given** PHP 7.4 is running, **When** `Compat::str_contains('hello', 'ell')`
   is called, **Then** it returns `true` without a fatal.
2. **Given** PHP 8.0+ is running, **When** `Compat::supports('8.0')` is called,
   **Then** it returns `true`.
3. **Given** `includes/Compat.php` is opened, **When** its namespace declaration
   is read, **Then** it equals `AcrossAI_MCP_Manager\Includes` (updated from
   the source `ACROSSAI_MCP_MANAGER\Core`).

---

### User Story 6 — Activation Bootstraps DB Tables and Rewrite Rules (P2)

A site admin activating the plugin on a fresh install has all required database
tables created and the frontend auth and
OAuth virtual paths reachable immediately — without a manual permalink flush.

**Acceptance Scenarios**:

1. **Given** a fresh WP install, **When** the plugin is activated, **Then**
   `Activator::activate()` runs without fatal errors and the MCP server,
   CLI auth log, and connector audit log tables are present in the DB.
2. **Given** the plugin is activated AND `FrontendAuth` has been implemented (Phase 3+), **When** `/mcp-auth/` is visited, **Then**
   WordPress routes to the frontend auth handler, not a 404. *(This scenario is a Phase 3 responsibility — in Phase 1 the path returns a graceful WordPress 404 by design.)*
3. **Given** the plugin is re-activated (tables already exist), **When**
   `Activator::activate()` runs again, **Then** it completes without errors —
   table creation is idempotent.

---

### Edge Cases

- **Missing vendor/autoload_packages.php** (Composer not installed): `load_composer_dependencies()`
  guards with `file_exists()` — plugin fails gracefully with no fatal.
- **Uninitialised `$this->plugin_name` in `define_constants()`**: `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG`
  must be set to the literal `'acrossai-mcp-manager'` — `$this->plugin_name` is
  null at the time `define_constants()` is called.
- **Unmigrated module classes** (phases 3–8 not yet complete): `define_admin_hooks()`
  and `define_public_hooks()` use `// TODO: wire after phase N` stub comments
  for classes that do not yet exist — no instantiation attempt on missing classes.
- **Rewrite rules registered before handler classes exist**: `add_rewrite_rule()`
  and `flush_rewrite_rules()` run at activation. Requests to `/mcp-auth/` and
  the OAuth authorize path return a graceful WordPress 404 until `FrontendAuth`
  and `ClaudeConnectors` are implemented in later phases. This is intentional.
- **`ACROSSAI_MCP_MANAGER_PLUGIN_FILE` undefined**: The plugin root file always
  defines it at file scope before `acrossai_mcp_manager_run()` is called —
  structural enforcement, not runtime guard.
- **Re-activation (tables exist)**: Each table creation call must be idempotent
  (uses a `maybe_create_table()` style guard — no `DROP` or duplicate-table error).

---

## Requirements

### Functional Requirements

- **FR-001**: The boot sequence inside `Main::__construct()` MUST follow this
  exact order: `define_constants()` → `load_composer_dependencies()` →
  `load_dependencies()` → `set_locale()` → `load_hooks()`.
  The constructor MUST set `$this->plugin_name` only AFTER `define_constants()`
  has run.

- **FR-002**: `define_constants()` MUST define all six constants using the
  private `define()` guard:
  `ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME`, `ACROSSAI_MCP_MANAGER_PLUGIN_PATH`,
  `ACROSSAI_MCP_MANAGER_PLUGIN_URL`, `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG`,
  `ACROSSAI_MCP_MANAGER_PLUGIN_NAME`, `ACROSSAI_MCP_MANAGER_VERSION`.
  No `define()` calls may appear anywhere else in the plugin.

- **FR-003**: `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` MUST be defined as the
  literal string `'acrossai-mcp-manager'` — not as `$this->plugin_name`
  (which is uninitialised when `define_constants()` is called in the constructor).
  `get_plugin_name()` MUST continue returning `$this->plugin_name` (not the
  constant) to avoid touching every caller and to prevent an ordering bug in
  test bootstraps that don't load the full plugin. The property and constant
  hold identical values — this is an explicit, deliberate design decision.

- **FR-004**: `load_dependencies()` MUST contain only `$this->loader = Loader::instance()`.
  It MUST NOT call any `boot()`, `register_hooks()`, or hook-registering method.

- **FR-005**: `load_hooks()` MUST gate all hook registration behind
  `apply_filters('acrossai_mcp_manager_load', true)`. If the filter returns
  falsy, neither `define_admin_hooks()` nor `define_public_hooks()` is called.

- **FR-006**: `define_admin_hooks()` MUST wire the following modules through
  `$this->loader->add_action()` / `$this->loader->add_filter()`. Modules not
  yet migrated MUST use a `// TODO: wire after phase N` stub comment instead
  of instantiation:
  - `Admin\Main` → `admin_enqueue_scripts` (enqueue_styles, enqueue_scripts)
  - `Admin\Partials\Menu` → `admin_menu` + `plugin_action_links`
  - `Admin\Partials\Settings` → `admin_init` (handle_actions at priority 5,
    register_settings)
  - `Admin\Partials\ApplicationPasswords` → its required hooks
  - `REST\CliController` → `rest_api_init`
  - `Includes\MCP\Controller` → `rest_api_init`
  - `Includes\OAuth\ClaudeConnectors` → `rest_api_init`, `init`,
    `template_redirect`, `query_vars`, `wp_enqueue_scripts`,
    `determine_current_user`, `rest_post_dispatch`, `acrossai_mcp_access_denied`
    *(Canonical callback method names are defined in plan.md Phase A Change 3;
    use those names verbatim when implementing Phase 6)*
  - Access control `rest_pre_dispatch` filter — stub MUST reference
    `\WPBoilerplate\AccessControl\AccessControlManager::instance()`
    (vendor package `wpb-access-control ^1.0`; Phase 7 wires this dependency)

- **FR-007**: `define_public_hooks()` MUST wire the following modules through
  the Loader. Modules not yet migrated MUST use `// TODO` stubs:
  - `Public\Main` → `wp_enqueue_scripts` (enqueue_styles, enqueue_scripts)
  - `Public\Partials\FrontendAuth` → `init`, `template_redirect`

- **FR-008**: `includes/Compat.php` MUST be created with:
  - Namespace `AcrossAI_MCP_Manager\Includes`
  - All static helper methods ported verbatim from `src/Core/Compat.php`:
    `str_contains()`, `str_starts_with()`, `str_ends_with()`,
    `array_is_list()`, `array_key_first()`, `array_key_last()`,
    `supports()`, `in_range()`, and the `PHP_MIN`/`PHP_MAX` constants.
  - The class is available to all plugin classes via PSR-4 autoloading —
    no manual `require_once` in `load_dependencies()`.

- **FR-009**: `includes/Activator.php` MUST, inside `activate()`:
  - Bootstrap all DB tables (idempotent — skip if already exist).
    Each call MUST be guarded by `class_exists()` — silently skip (no log,
    no notice) if the class is absent. The three guarded calls are:
    - `Includes\Database\MCPServer\Query::maybe_create_table()`
    - `Includes\Database\CliAuthLog\Query::maybe_create_table()`
    - `Includes\Database\ConnectorAuditLog\Query::maybe_create_table()`
  - *Default MCP server row insertion is an internal concern of*
    *`MCPServerQuery::maybe_create_table()` (Phase 4) — Activator does NOT call*
    *`insert_default_server()` directly. This sub-requirement is removed from Phase 1.*
  - Register rewrite rules for the frontend auth path and the OAuth
    authorize path, including the `/.well-known/oauth-authorization-server` route,
    using `add_rewrite_rule()` with placeholder query vars. The handler classes
    (`FrontendAuth`, `ClaudeConnectors`) do not need to exist at activation time —
    requests to these paths return a graceful WordPress 404 until the handler
    classes are implemented in later phases.
  - Call `flush_rewrite_rules()` immediately after registering the rules.

- **FR-010**: `register_activation_hook()` MUST be called at file root in
  `acrossai-mcp-manager.php`, not inside any class. The activation function
  MUST require `includes/Activator.php` and call `Activator::activate()`.

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ (Requires PHP header in plugin root) |
| WordPress version | 6.9+ (Requires at least header) |
| Multisite | Not in scope for this phase |
| Required Composer packages | `automattic/jetpack-autoloader ^5.0` |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `includes/Main.php` | `AcrossAI_MCP_Manager\Includes` | Extend existing |
| `includes/Compat.php` | `AcrossAI_MCP_Manager\Includes` | New — port from `src/Core/Compat.php` |
| `includes/Activator.php` | `AcrossAI_MCP_Manager\Includes` | Extend existing |
| `acrossai-mcp-manager.php` | `AcrossAI_MCP_Manager` | Extend existing (minor fixes) |

**Hook Registration Rule**: ALL `add_action` / `add_filter` calls MUST be wired
only through the Loader inside `Main::define_admin_hooks()` or
`Main::define_public_hooks()`. Zero hook calls may appear in any class constructor.

### Admin UI Requirements

This phase adds no admin screens. No DataForm / DataViews requirement applies.

### REST API Contract

This phase adds no REST routes. Route registration is covered by phases 3–8.

### Database / Storage

Activation bootstraps three custom tables (all carried over from the source repo):

| Table suffix | Purpose | Created by |
|---|---|---|
| `acrossai_mcp_servers` | MCP server configurations | `Includes\Database\MCPServer\Query` — silently skipped if absent |
| `acrossai_mcp_cli_auth_log` | CLI authentication log | `Includes\Database\CliAuthLog\Query` — silently skipped if absent |
| `acrossai_mcp_connector_audit_log` | OAuth connector audit log | `Includes\Database\ConnectorAuditLog\Query` — silently skipped if absent |

Justification: High-volume append-only logs and relational server-config data
cannot be modelled efficiently with the WordPress options / post-meta API.

### Security Checklist

- [x] `ABSPATH` guard at top of every PHP file (`defined('ABSPATH') || exit`)
- [x] No direct user input processed — this phase is pure boot wiring
- [x] No output generated — this phase is pure boot wiring
- [x] Activation hook registered at file root via `register_activation_hook()`
- [x] All plugin constants defined only via the private `define()` guard

---

## Success Criteria

### Definition of Done Gates

- [ ] PHPCS: zero errors and zero warnings (`vendor/bin/phpcs`) — pre-existing structural violations (filename casing, `$_instance` prefix, file docblocks, `namespace Public` reserved keyword) documented as baseline exclusions in `phpcs.xml.dist` (Phase 2 exception, 2026-05-29)
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] All 6 constants defined in `Main::define_constants()` with `define()` guard
- [ ] `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` set to literal `'acrossai-mcp-manager'`
- [ ] Zero `add_action` / `add_filter` calls in any class constructor
- [ ] `includes/Compat.php` exists, namespace `AcrossAI_MCP_Manager\Includes`, all 8 methods present
- [ ] `includes/Activator.php` bootstraps all 3 DB tables and rewrite rules
- [ ] `apply_filters('acrossai_mcp_manager_load', false)` kills hook registration
- [ ] Plugin activates on WordPress 6.9 / PHP 8.0 with `WP_DEBUG=true` — no errors or notices
- [ ] No `define()` calls outside `Main::define_constants()`

### Measurable Outcomes

- **SC-001**: Plugin activation completes with zero PHP errors or notices logged
  (verified with `WP_DEBUG=true`, `WP_DEBUG_LOG=true`)
- **SC-002**: `grep -rn "add_action\|add_filter" includes/ admin/ public/` returns
  zero matches outside `Loader.php` and `Main.php`
- **SC-003**: All 6 `ACROSSAI_MCP_MANAGER_*` constants are defined and non-empty
  immediately after `Main::instance()` returns

---

## Assumptions

- `ACROSSAI_MCP_MANAGER_PLUGIN_FILE` is always defined at file scope in
  `acrossai-mcp-manager.php` before `acrossai_mcp_manager_run()` is called.
  This is already correct in the target repo.
- The following classes do NOT exist in this phase and MUST use TODO stubs
  in `define_admin_hooks()` / `define_public_hooks()`:
  `Admin\Partials\Settings`, `Admin\Partials\ApplicationPasswords`,
  `REST\CliController`, `Includes\MCP\Controller`,
  `Includes\OAuth\ClaudeConnectors`, `Public\Partials\FrontendAuth`.
- `vendor/autoload_packages.php` is present after `composer install` — the
  `file_exists()` guard in `load_composer_dependencies()` handles the case
  where it is absent (e.g. in a fresh checkout before install).
- `src/Core/Plugin.php` in the source repo is NOT deleted in this phase — it
  remains the running code until all feature modules have been migrated.
- PHPUnit test scaffolding is out of scope for this phase.
