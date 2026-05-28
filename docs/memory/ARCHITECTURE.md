# Architecture — AcrossAI MCP Manager

Last reviewed: 2026-05-29 (Feature 001 index-project)

## System Overview

WordPress plugin with a PSR-4 WPBoilerplate layout. Provides MCP server management,
OAuth/Claude Connectors, CLI auth, frontend auth, and access control via a versioned
REST API. All wiring flows through a single `Main.php` entry point.

## Directory Layout

```
admin/
└── Partials/       # All admin-facing classes: menu, page renderers, asset enqueues

includes/
├── Main.php        # Single source of all hook registration
├── Loader.php      # Collects and fires add_action / add_filter calls
├── i18n.php        # Internationalization
├── Utilities/      # Shared utility functions, helpers, formatters
├── MCP/            # MCP server boot and registration
├── MCPClients/     # AI client configuration generators (one class per client)
├── OAuth/          # Claude Connectors OAuth 2.0 flow
└── REST/           # REST API controllers (CLI auth, access control)

public/
└── Partials/       # Frontend-facing classes (FrontendAuth virtual page)

src/
├── js/             # JavaScript source files (compiled by @wordpress/scripts)
└── scss/           # Stylesheet source files (compiled by @wordpress/scripts)

tests/
├── phpunit/        # PHP unit and integration tests
└── jest/           # JavaScript unit tests
```

## Boot Flow

```
acrossai-mcp-manager.php (plugin root)
  → acrossai_mcp_manager_run()
  → Main::instance()  [includes/Main.php]
      → define_constants()
      → load_composer_dependencies()   ← Jetpack autoloader only; NO hook calls
      → load_dependencies()            ← Loader singleton only; NO hook calls
      → set_locale()                   ← attaches I18n to Loader
      → load_hooks()
          → apply_filters('acrossai_mcp_manager_load', true)  ← kill switch
          → define_admin_hooks()       ← ALL admin + REST hook wiring via Loader
          → define_public_hooks()      ← ALL frontend hook wiring via Loader
  → add_action('plugins_loaded', [$plugin, 'run'])
  → Loader::run()  ← fires all registered hooks
```

## Singleton Pattern (Plugin-wide Convention)

Every feature class implements:

```php
protected static $_instance = null;

public static function instance(): self {
    if ( null === self::$_instance ) {
        self::$_instance = new self();
    }
    return self::$_instance;
}

private function __construct() { /* dependencies via OtherClass::instance() only */ }
```

`Main.php` always assigns to a named variable before wiring to the Loader:

```php
$mcp_controller = MCP\Controller::instance();
$this->loader->add_action( 'rest_api_init', $mcp_controller, 'register_servers' );
```

Passing `FeatureClass::instance()` inline as the second argument to `add_action` is prohibited.

## PHP Namespace Map

| Directory | Namespace |
|---|---|
| `includes/Main.php` | `AcrossAI_MCP_Manager\Includes` |
| `includes/MCP/` | `AcrossAI_MCP_Manager\Includes\MCP` |
| `includes/MCPClients/` | `AcrossAI_MCP_Manager\Includes\MCPClients` |
| `includes/OAuth/` | `AcrossAI_MCP_Manager\Includes\OAuth` |
| `includes/REST/` | `AcrossAI_MCP_Manager\Includes\REST` |
| `includes/Utilities/` | `AcrossAI_MCP_Manager\Includes\Utilities` |
| `admin/Partials/` | `AcrossAI_MCP_Manager\Admin\Partials` |
| `public/Partials/` | `AcrossAI_MCP_Manager\Public\Partials` |
| `includes/Database/MCPServer/` | `AcrossAI_MCP_Manager\Includes\Database\MCPServer` |
| `includes/Database/CliAuthLog/` | `AcrossAI_MCP_Manager\Includes\Database\CliAuthLog` |
| `includes/Database/ConnectorAuditLog/` | `AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLog` |

Always derive namespace from the full directory path — never invent short namespaces.

## Major Components

- **Main.php** — singleton, single source of all hook registration
- **MCP/Controller** — MCP server boot and registration via `wpb-mcp-servers-list` package
- **MCPClients/** — one class per AI client (e.g., `ClaudeCodeClient`)
- **OAuth/ClaudeConnectors** — OAuth 2.0 authorization code flow
- **REST/** — versioned REST API; split into per-domain sub-controllers at ~400 lines
- **admin/Partials/Settings** — admin menu, list table, settings pages
- **public/Partials/FrontendAuth** — virtual page for AI client auth

## Integrations

- **WPBoilerplate Access Control** — optional; wrapped in availability check
- **wpboilerplate/wpb-mcp-servers-list** — required Composer package for MCP server listing
- **Jetpack Autoloader** — wired via `vendor/autoload_packages.php`
- **@wordpress/scripts** — asset build pipeline
- **@wordpress/dataviews** — `DataViews` for lists, `DataForm` for forms (all new admin UIs)

## Boundaries

- `includes/` classes are context-neutral — MUST NOT contain admin-specific logic
- `admin/Partials/` owns all `add_menu_page()`, `wp_enqueue_*()`, and HTML rendering
- REST sub-controllers MUST NOT register WordPress hooks directly
- Feature modules MUST NOT depend on sibling modules directly — only on `includes/Utilities/`
- Optional integrations MUST be wrapped in availability checks; absent integrations must not fatal

## Risks / Complexity Hotspots

- OAuth token lifecycle: storage, refresh, revocation — hash-before-store requirement
- MCP server auto-discovery: MUST NOT block admin page rendering
- Jetpack autoloader versioning conflicts when the package list changes

## Keep Here

- Stable system boundaries and directory ownership
- Boot flow sequence (order of `load_*` calls is load-bearing)
- Namespace map (PSR-4 must mirror directory structure exactly)
- Integration constraints that affect multiple features

## Never Store Here

- Step-by-step implementation plans
- One-off feature details
- Stale diagrams that no longer reflect actual boundaries

Update the review date when boundaries, ownership, or integrations materially change.


## Module Inventory (as of Feature 001)

### Existing (boilerplate stubs — Phase 1)

| Class FQN | File | Status | Notes |
|---|---|---|---|
| `AcrossAI_MCP_Manager\Includes\Main` | `includes/Main.php` | Exists | Singleton; all hook registration |
| `AcrossAI_MCP_Manager\Includes\Loader` | `includes/Loader.php` | Exists | Singleton; collects add_action/add_filter calls |
| `AcrossAI_MCP_Manager\Includes\I18n` | `includes/I18n.php` | Exists | Wired via Loader on `init` |
| `AcrossAI_MCP_Manager\Includes\Activator` | `includes/Activator.php` | Exists | Static; called by activation hook |
| `AcrossAI_MCP_Manager\Includes\Deactivator` | `includes/Deactivator.php` | Exists | Static; called by deactivation hook |
| `AcrossAI_MCP_Manager\Admin\Main` | `admin/Main.php` | Exists | Enqueue admin assets |
| `AcrossAI_MCP_Manager\Admin\Partials\Menu` | `admin/Partials/Menu.php` | Exists | Admin menu + plugin action links |
| `AcrossAI_MCP_Manager\Public\Main` | `public/Main.php` | Exists | Enqueue public assets |

### Created in Feature 001

| Class FQN | File | Status |
|---|---|---|
| `AcrossAI_MCP_Manager\Includes\Compat` | `includes/Compat.php` | NEW — Phase 1 |

### Planned (TODO stubs in Main.php)

| Class FQN | Target File | Target Phase |
|---|---|---|
| `AcrossAI_MCP_Manager\Admin\Partials\Settings` | `admin/Partials/Settings.php` | Phase 3 |
| `AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords` | `admin/Partials/ApplicationPasswords.php` | Phase N |
| `AcrossAI_MCP_Manager\Public\Partials\FrontendAuth` | `public/Partials/FrontendAuth.php` | Phase 3 |
| `AcrossAI_MCP_Manager\Includes\MCP\Controller` | `includes/MCP/Controller.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query` | `includes/Database/MCPServer/Query.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query` | `includes/Database/CliAuthLog/Query.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLog\Query` | `includes/Database/ConnectorAuditLog/Query.php` | Phase 4 |
| `AcrossAI_MCP_Manager\Includes\REST\CliController` | `includes/REST/CliController.php` | Phase 5 |
| `AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors` | `includes/OAuth/ClaudeConnectors.php` | Phase 6 |

## Module Dependencies (Feature 001)

```
Main (includes/Main.php)
  ├─ depends on: Loader::instance()
  ├─ depends on: Admin\Main::instance()
  ├─ depends on: Admin\Partials\Menu::instance()
  ├─ depends on: Public\Main::instance()
  └─ [TODO stubs]: Settings, AppPasswords, FrontendAuth, MCP\Controller,
                   REST\CliController, OAuth\ClaudeConnectors, AccessControl

Activator (includes/Activator.php)
  ├─ optional: Database\MCPServer\Query (class_exists guard)
  ├─ optional: Database\CliAuthLog\Query (class_exists guard)
  └─ optional: Database\ConnectorAuditLog\Query (class_exists guard)

Compat (includes/Compat.php) — no dependencies
I18n (includes/I18n.php) — no dependencies
Loader (includes/Loader.php) — no dependencies
```

## Critical Namespace Rule (A6)

Any PHP file in namespace `AcrossAI_MCP_Manager\Includes` that references a
class in a sub-namespace MUST use `use` imports or a fully-qualified name
with a leading `\`. Example:

```php
// WRONG — resolves to AcrossAI_MCP_Manager\Includes\Includes\Database\...
class_exists( Includes\Database\MCPServer\Query::class )

// CORRECT
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
class_exists( MCPServerQuery::class )
```

See BUGS.md (B1) for the failure pattern this prevents.

## Constants Rule (A7) [Feature-001, 2026-05-29]

All 6 plugin constants (`PLUGIN_FILE`, `PLUGIN_DIR`, `PLUGIN_URL`, `PLUGIN_BASENAME`, `VERSION`, `PLUGIN_NAME_SLUG`) are defined exclusively inside `Main::define_constants()` via a private `define()` helper with an `if (!defined(…))` guard. Zero `define()` calls are permitted anywhere in `includes/`, `admin/`, or `public/`.

## Vendor Package Integration (A8) [Feature-001, 2026-05-29]

Access control wiring (Phase 7) MUST use `\WPBoilerplate\AccessControl\AccessControlManager` from `wpboilerplate/wpb-access-control ^1.0` Composer package — not an internal class.
