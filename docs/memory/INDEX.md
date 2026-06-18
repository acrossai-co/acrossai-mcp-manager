# Memory Index

This is a compact routing map for durable memory. Keep it short. It points to source entries and helps agents decide what to read; it does not replace the source memory files.

## Active Decisions
| ID | Title | Scope | Tags | Status | Source |
|---|---|---|---|---|---|
| D1 | PLUGIN_NAME_SLUG uses literal string; get_plugin_name() returns property | Boot flow | constants, boot | Active | DECISIONS.md |
| D2 | Rewrite rules registered immediately at activation with placeholder vars | Activation | rewrite, routing | Active | DECISIONS.md |
| D3 | Compat.php placed in includes/ not includes/Utilities/ (boot-time shim) | Module structure | compat, placement | Active | DECISIONS.md |
| D4 | class_exists() guards in Activator are always silent no-op | Activation | db, class-loading | Active | DECISIONS.md |
| D5 | PHPCS baseline exceptions in phpcs.xml.dist (filename, $_instance, docblocks, namespace Public, PSR12 header) | Plugin-wide | phpcs, baseline | Active | DECISIONS.md |
| D6 | Activator.php MUST use `use` imports (not bare/inline FQN) for all DB class references | Activation | namespace, fqn | Active | DECISIONS.md |
| D7 | Activator does NOT call insert_default_server() — Phase 4 MCPServerQuery::maybe_create_table() internal | Activation | db, seeding | Active | DECISIONS.md |
| D8 | AccessControl stub targets wpboilerplate/wpb-access-control ^1.0 vendor package FQN | Phase 7 prep | access-control, vendor | Active | DECISIONS.md |
| D9 | BerlinDB-style Query interface (Schema/Table/Row/Query) hand-rolled — no berlindb/core vendor dep | Database layer | berlindb, query, vendor | Active | DECISIONS.md |
| D10 | Minimal-port deferral pattern — partial port + reserved follow-up task when source class depends on un-ported siblings | Migration | port, deferral, scope | Active | DECISIONS.md |

## Architecture Constraints
| ID | Constraint | Scope | Tags | Source |
|---|---|---|---|---|
| A1 | All hook registration lives exclusively in `includes/Main.php` via `define_admin_hooks()` / `define_public_hooks()` | Plugin-wide | boot, hooks | ARCHITECTURE.md |
| A2 | Every feature class uses the singleton `instance()` pattern | Plugin-wide | singleton, DI | ARCHITECTURE.md |
| A3 | All admin UI classes live in `admin/Partials/` with namespace `AcrossAI_MCP_Manager\Admin\Partials` | Admin | namespace, structure | ARCHITECTURE.md |
| A4 | All new data forms use `DataForm` from `@wordpress/dataviews`; all new lists use `DataViews` | Admin UI | UI, components | ARCHITECTURE.md |
| A5 | MCP server listing MUST use `wpboilerplate/wpb-mcp-servers-list` package, not direct adapter calls | MCP module | integration | ARCHITECTURE.md |
| A6 | Any class in namespace `AcrossAI_MCP_Manager\\Includes` MUST use `use` imports or FQN with leading `\\` when referencing sub-namespace classes — bare relative names silently fail | Plugin-wide | namespace, fqn | ARCHITECTURE.md |
| A7 | All 6 plugin constants defined exclusively in Main::define_constants() via private define() guard — zero define() calls elsewhere | Plugin-wide | constants, boot | ARCHITECTURE.md |
| A8 | Access control wiring (Phase 7) MUST use \WPBoilerplate\AccessControl\AccessControlManager vendor package | Phase 7 | access-control, vendor | ARCHITECTURE.md |
| A9 | Shared admin constants (read by ≥2 modules) live in includes/Utilities/ as a final class, NOT on sibling feature classes | Plugin-wide | constants, coupling, utilities | ARCHITECTURE.md |
| A10 | WP_List_Table subclasses are exempted from the singleton-only rule — public ctor required by parent; instantiated per-render; never Loader-wired | Admin | list-table, singleton, exception | ARCHITECTURE.md |
| A11 | Pure service classes (stateless value producers, e.g. includes/MCPClients/) exempted from singleton rule — no instance state, no ctor args, no hook registration | Plugin-wide | singleton, service-class, exception, pure | ARCHITECTURE.md |

## Bug Patterns
| ID | Pattern | Affected Area | Tags | Source |
|---|---|---|---|---|
| B1 | Namespace relative-path bug: bare `Includes\\Database\\...` inside `AcrossAI_MCP_Manager\\Includes` → double-Includes FQN → class_exists always false | Activator, any Includes class | namespace, silent-failure | BUGS.md |
| B2 | define_constants() null-property: using `$this->property` before it is set in constructor → constant defined as empty string | Main.php boot | constants, null | BUGS.md |
| B3 | TODO stub FQN namespace drift: missing \Includes\ segment → fatal on uncomment | Main.php stubs | namespace, stub | BUGS.md |
| B4 | Unescaped dot in add_rewrite_rule() PCRE pattern: '^.well-known/' matches any char; must be '^\.well-known/' | Activator | rewrite, regex | BUGS.md |
| B5 | Public constructor on singleton allows duplicate instantiation → double hook registration | Any singleton class | singleton, hooks | BUGS.md |
| B6 | admin_url() without esc_url() → filterable value injected into HTML href → XSS | Admin Partials | xss, escaping | BUGS.md |
| B7 | Mass-assignment via forged POST keys to $wpdb->update/insert — Query writers MUST filter against Schema::columns() before persisting | Custom DB tables | mass-assignment, query | BUGS.md |
| B8 | "// esc_url'd above" comments don't enforce escaping — re-escape at output point even if redundant (esc_* is idempotent) | Admin Partials renders | xss, escaping, defense-in-depth | BUGS.md |

## Accepted Deviations
| ID | Deviation | Scope | Expiry/Review | Source |
|---|---|---|---|---|
| DEV1 | MCP Manager parent menu (`?page=acrossai_mcp_manager`) uses `WP_List_Table` instead of DataViews | Admin UI | Never expires — pre-approved exception | CONSTITUTION.md §IV |
| DEV2 | `includes/Compat.php` lives in `includes/` not `includes/Utilities/` — boot-time shim exception to Principle I | Boot flow | Review if Utilities/ autoload order changes | DECISIONS.md D3 |

## Security Constraints
| ID | Constraint | Scope | Tags | Source |
|---|---|---|---|---|
| S1 | All forms and AJAX endpoints MUST verify a nonce before processing data | Plugin-wide | nonce, security | CONSTITUTION.md §III |
| S2 | All REST routes MUST have an explicit `permission_callback` — `__return_true` only on public read routes | REST API | permissions | CONSTITUTION.md §III |
| S3 | OAuth tokens and Application Passwords MUST be stored hashed (SHA-256 min) — never plaintext | OAuth, Auth | secrets | CONSTITUTION.md §III |
| S4 | All DB queries MUST use `$wpdb->prepare()` | Database | sql-injection | CONSTITUTION.md §III |
| S5 | admin_url() MUST be wrapped with esc_url() before use in HTML — it is filterable via the admin_url hook | Admin UI | xss, escaping | PROJECT_CONTEXT.md |
| S6 | Singleton __construct() MUST be private — public constructor allows duplicate instantiation and double hook firing | Plugin-wide | singleton, security | PROJECT_CONTEXT.md |
