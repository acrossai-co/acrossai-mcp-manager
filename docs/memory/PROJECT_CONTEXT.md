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

## Security Constraints

- **S5** [Feature-001, 2026-05-29]: `admin_url()` MUST always be wrapped in `esc_url()` before use in HTML — it is filterable via the `admin_url` hook and its output can be XSS-exploitable if unescaped.
- **S6** [Feature-001, 2026-05-29]: Singleton class `__construct()` MUST be `private`. A public constructor allows duplicate instantiation, causing all hooks registered in `Main.php` to fire twice. A `final` class modifier alone is not sufficient.
- **S7** [Feature-005, 2026-06-25]: S2's `__return_true`-ban does NOT apply to RFC-mandated body-authenticated endpoints. The OAuth token endpoint at `/wp-json/acrossai-mcp/v1/token` is the canonical exemption — RFC 6749 §2.3.1 specifies authentication via `client_id` + `client_secret` POST body, not session or header. Exemption requires: (a) the RFC section cited in the class docblock, (b) a documented in-callback validation chain that preserves S2's intent, (c) exactly one `__return_true` match across `includes/OAuth/` (T079 polish grep enforces). See `includes/OAuth/TokenController.php` + `specs/005-oauth-connectors/contracts/token-endpoint.md`.
- **S8** [Feature-006, 2026-06-25]: S7's exemption generalizes to non-OAuth body-authenticated mutating REST routes (e.g. CLI device-code-grant flows) when ALL hold: (a) credential is in request BODY with ≥128-bit CSPRNG entropy; (b) a `check_content_type()`-equivalent helper rejects missing/unknown Content-Type with HTTP 400 BEFORE field validation (inherits Phase 5 SEC-002); (c) any downstream credential (session token, transient handle) is BOUND to the consented resource scope — `array{user_id, server_id}` payload shape, not bare `user_id` (matches Phase 5 FR-015 cross-server defense); (d) class docblock cites S7 precedent AND the specific FR. Removes S7's "exactly one match" limit — every route in the class must satisfy (a)-(d). See `includes/REST/CliController.php` + `specs/006-rest-cli-auth/spec.md` FR-015 + FR-008.
- **S9** [Feature-007, 2026-06-30]: Consent surfaces that render attacker-controlled context (server slug, scope name, requested capability, OAuth client name) MUST source the displayed value from the server-side authoritative store (transient, option, DB row) keyed by the unforgeable code, NOT from URL parameters. URL-supplied consent context is attacker-controllable in any deep-link / device-grant flow; rendering it verbatim — even escaped — creates a UI-misrepresentation / confused-deputy attack (the user authorizes one thing while the downstream binding maps to another). Add a read-only helper on the controller that owns the transient (e.g. `CliController::peek_pending_server( string $code ): ?string`, `OAuthController::peek_authorization_subject( string $state ): ?array`) and route the consent UI through it. Escape at output as defense-in-depth, never as the sole defense. See `docs/security-reviews/2026-06-30-007-frontend-cli-auth-plan.md` SEC-001 (CWE-451 / CWE-441).
