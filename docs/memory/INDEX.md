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
| D11 | Phase X.0 absorption — when a phase's P0 prereq doesn't yet exist, absorb its setup as a sub-phase in the consuming phase, not a separate dedicated phase | Process | phase, prereq, p0-gate | Active | DECISIONS.md |
| D12 | Bulk task-status updates MUST be followed by a re-audit of environment-dependent gates | Process | tasks, completion, ci-gates | Active | DECISIONS.md |

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
| A12 | Pure-PHP modules claiming WP-independence MUST have a tests/bootstrap.php that loads ONLY composer autoload — the test harness is the proof of the architectural claim | Plugin-wide | test-harness, purity, bootstrap, wp-free | ARCHITECTURE.md |
| A13 | RFC-prescribed forms (e.g. OAuth consent) are exempted from A4 DataForm mandate — cite the RFC section in the rendering class docblock | Admin UI | data-form, rfc, oauth, exception | ARCHITECTURE.md |
| A14 | WP-CLI dispatch classes follow A11-style exemption — stateless, no singleton, instantiated via `new self()` inside `\WP_CLI::add_command()` | Plugin-wide | wp-cli, singleton, exception, pure | ARCHITECTURE.md |
| A15 | Database-namespace audit-recorder static helpers (e.g. `<Module>\Recorder`) follow A11/A14 family — stateless static methods wrap `Query::add_item` for audit writes inside `try/catch` | Database, audit | recorder, audit, singleton, exception, pure | ARCHITECTURE.md |

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
| B9 | PHPUnit 13+ silently ignores `@dataProvider` annotations — use `#[DataProvider]` PHP attribute instead. Same for `@depends`, `@group`, `@test` | PHPUnit tests | testing, phpunit, attributes | BUGS.md |
| B10 | Check-then-act on one-shot credentials under concurrency → use atomic single-statement CAS (`UPDATE ... WHERE id = :id AND completed_at IS NULL`), not `SELECT` + `UPDATE` | OAuth, custom DB writes | concurrency, race, atomic-cas, one-shot | BUGS.md |
| B11 | Transient-stored associative arrays MUST be defensively validated with `is_array() + isset() + is_numeric()` triple-check on read — defends against partial writes, type drift, transient corruption | Transient readers, OAuth, CLI auth | transient, defensive, validation, partial-write | BUGS.md |

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
| S7 | OAuth token endpoint is the documented exception to S2 — `__return_true` is allowed because RFC 6749 §2.3.1 specifies auth via POST body; exactly one match permitted across `includes/OAuth/` | REST API, OAuth | permission_callback, rfc-6749, exception | PROJECT_CONTEXT.md |
| S8 | Body-authenticated mutating REST routes broader than S7 — `__return_true` permitted on CLI device-code-grant flows when Content-Type allow-list rejects missing/unknown headers BEFORE field validation AND downstream credential is bound to consented resource scope | REST API, CLI auth | permission_callback, content-type, server-binding, exception | PROJECT_CONTEXT.md |
