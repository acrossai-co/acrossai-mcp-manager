# Memory Index

This is a compact routing map for durable memory. Keep it short. It points to source entries and helps agents decide what to read; it does not replace the source memory files.

## Active Decisions
| ID | Title | Scope | Tags | Status | Source |
|---|---|---|---|---|---|

## Architecture Constraints
| ID | Constraint | Scope | Tags | Source |
|---|---|---|---|---|
| A1 | All hook registration lives exclusively in `includes/Main.php` via `define_admin_hooks()` / `define_public_hooks()` | Plugin-wide | boot, hooks | ARCHITECTURE.md |
| A2 | Every feature class uses the singleton `instance()` pattern | Plugin-wide | singleton, DI | ARCHITECTURE.md |
| A3 | All admin UI classes live in `admin/Partials/` with namespace `AcrossAI_MCP_Manager\Admin\Partials` | Admin | namespace, structure | ARCHITECTURE.md |
| A4 | All new data forms use `DataForm` from `@wordpress/dataviews`; all new lists use `DataViews` | Admin UI | UI, components | ARCHITECTURE.md |
| A5 | MCP server listing MUST use `wpboilerplate/wpb-mcp-servers-list` package, not direct adapter calls | MCP module | integration | ARCHITECTURE.md |

## Bug Patterns
| ID | Pattern | Affected Area | Tags | Source |
|---|---|---|---|---|

## Accepted Deviations
| ID | Deviation | Scope | Expiry/Review | Source |
|---|---|---|---|---|
| DEV1 | MCP Manager parent menu (`?page=acrossai_mcp_manager`) uses `WP_List_Table` instead of DataViews | Admin UI | Never expires — pre-approved exception | CONSTITUTION.md §IV |

## Security Constraints
| ID | Constraint | Scope | Tags | Source |
|---|---|---|---|---|
| S1 | All forms and AJAX endpoints MUST verify a nonce before processing data | Plugin-wide | nonce, security | CONSTITUTION.md §III |
| S2 | All REST routes MUST have an explicit `permission_callback` — `__return_true` only on public read routes | REST API | permissions | CONSTITUTION.md §III |
| S3 | OAuth tokens and Application Passwords MUST be stored hashed (SHA-256 min) — never plaintext | OAuth, Auth | secrets | CONSTITUTION.md §III |
| S4 | All DB queries MUST use `$wpdb->prepare()` | Database | sql-injection | CONSTITUTION.md §III |
