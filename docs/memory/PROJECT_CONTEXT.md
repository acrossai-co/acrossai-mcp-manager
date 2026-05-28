# Project Context — AcrossAI MCP Manager

Last reviewed: 2026-05-29

## Product / Service

WordPress plugin that manages MCP (Model Context Protocol) server connections,
OAuth authentication for Claude Connectors, and CLI-based AI client auth flows.
Provides a WordPress admin interface for site administrators to manage AI tool
integrations.

## Key Constraints

- WordPress 6.9+ / PHP 8.0+ compatibility required
- Plugin slug stays: `acrossai-mcp-manager`; text domain: `acrossai-mcp-manager`
- Text domain loaded on `init` hook (not `plugins_loaded`)
- Jetpack autoloader (`vendor/autoload_packages.php`) wired in `load_composer_dependencies()`
- All functions, hooks, filters, and class names MUST be prefixed with `acrossai_mcp_`
- No `add_action()` inside class constructors — all wiring through Loader in `Main.php`
- All feature classes use the singleton `instance()` pattern
- MCP server listing via `wpboilerplate/wpb-mcp-servers-list` Composer package only
- `npm run validate-packages` must pass before every commit
- All new admin UIs (except the pre-approved MCP parent menu) use `DataForm`/`DataViews`

## Important Domains

- **MCP Server Management**: CRUD and toggle of MCP server registrations; `WP_List_Table` UI (pre-approved exception)
- **OAuth / Claude Connectors**: OAuth 2.0 authorization flow for Claude API integrations
- **CLI Auth**: Application Password creation and management for CLI-based AI clients
- **Frontend Auth**: Virtual page for AI client authentication flows
- **Access Control**: WordPress capability-based access restriction on REST and admin endpoints
- **REST API**: Versioned REST routes for all programmatic integrations

## Current Priorities

- Complete boilerplate migration from v0.0.4 flat layout to WPBoilerplate PSR-4 structure
- Establish spec-kit governance before implementing any new features
- Preserve all existing v0.0.4 features with zero regressions

## Source Repository (reference — do not modify)

```
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
Namespace: ACROSSAI_MCP_MANAGER\
Layout: src/Admin/, src/Core/, src/Database/, src/Frontend/, src/MCP/, src/MCPClients/, src/OAuth/, src/REST/
```

## Target Repository (this repo)

```
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/
Namespace: AcrossAI_MCP_Manager\
Layout: admin/Partials/, includes/, public/Partials/, src/js/, src/scss/
Boot: acrossai_mcp_manager_run() → Main::instance() → plugins_loaded → run()
```

## Constitution Reference

`.specify/memory/constitution.md` v1.0.0 — ratified 2026-05-28.
Modelled on `acrossai-abilities-manager` v1.4.2 constitution.

## Team

Solo developer — Claude Code + spec-kit for AI-assisted development.

## Keep Here

- Durable plugin-wide constraints (namespace, slug, text domain, boot order)
- Domain language: MCP, OAuth Connectors, CLI Auth, Frontend Auth, Access Control
- Migration context (source vs target repo paths and namespace differences)
- Package hierarchy rules (Tier 1 `@wordpress/*`, Tier 2 npm)

## Never Store Here

- Feature-specific acceptance criteria
- Task lists or sprint plans
- Transient implementation notes
- Changelog entries
