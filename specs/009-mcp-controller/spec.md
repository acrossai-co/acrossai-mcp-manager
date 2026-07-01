# Feature Specification: MCP Controller Migration (Phase 4 gap closure)

**Feature Number**: 009
**Feature Branch**: `009-mcp-controller`
**Created**: 2026-07-01
**Status**: Draft
**Input**: Migration-audit finding — v0.0.4 `src/MCP/Controller.php` was never migrated during Phase 4 (which shipped only the 8 `MCPClients/*` classes). This feature closes the gap.

---

## Context

The `feature/issue-3` migration branch is 95% complete: Phases 1–8 all shipped via PRs #5–10. A migration-completion audit (2026-07-01) surfaced a **functional regression**: v0.0.4's `src/MCP/Controller.php` (170 LOC — the class that reads enabled MCP server rows from the DB and boots the `\WP\MCP\Plugin` adapter singleton) was never migrated to `includes/MCP/Controller.php`. The `feature/issue-3` HEAD's `includes/Main.php:352–354` has an explicit commented-out TODO that was never actioned:

```
// TODO (phase 4): wire Includes\MCP\Controller.
// $mcp_controller = \AcrossAI_MCP_Manager\Includes\MCP\Controller::instance();
// $this->loader->add_action( 'rest_api_init', $mcp_controller, 'initialize_adapter' );
```

**Impact of the gap**: after upgrading from v0.0.4 to the migrated version, no MCP servers are exposed via the adapter package. The admin UI lets operators configure MCP servers but the servers are never registered as adapter endpoints — the entire "MCP Manager" behavior is silently gone. `admin/Partials/Notices.php` DETECTS adapter absence for an admin banner but does NOT boot the adapter.

**Scope**: port + wire + minimal smoke tests. No new business logic. Must land before `feature/issue-3 → main` cutover.

---

## User Scenarios & Testing

### User Story 1 — Enabled MCP server rows are booted by the adapter (Priority: P1)

A site administrator with the WP MCP adapter package installed configures ≥1 MCP server row in the admin UI and marks it enabled. On the next front-end or REST request, the `\WP\MCP\Plugin` adapter is instantiated and each enabled server row is registered as an MCP endpoint.

**Why this priority**: This IS the migration's zero-regression promise. Without this, upgrading to the migrated version silently breaks the plugin's core functionality.

**Independent Test**: With `WP\MCP\Plugin` installed as a Composer dep + ≥1 enabled server row in the DB, `Controller::instance()->get_adapter_status()` returns `'running'`. `\WP\MCP\Plugin::instance()` is called exactly once per request via the hook chain.

**Acceptance Scenarios**:

1. **Given** ≥1 enabled server row (`is_enabled = 1`, `registered_from = 'database'`) AND `\WP\MCP\Plugin` class exists, **When** the `rest_api_init` action fires, **Then** `Controller::initialize_adapter()` calls `\WP\MCP\Plugin::instance()` and hooks `register_database_servers` on `mcp_adapter_init` priority 11.
2. **Given** the same state, **When** `Controller::get_adapter_status()` is called, **Then** it returns `'running'`.
3. **Given** the enabled server row, **When** `register_database_servers` fires on `mcp_adapter_init`, **Then** `\WP\MCP\Core\McpAdapter::create_server()` is called once per row with the row's `server_slug`, `server_name`, `description`, `server_route_namespace`, `server_route`, `server_version` fields.

### User Story 2 — Zero enabled servers: adapter stays dormant (Priority: P1)

A site administrator has the MCP adapter installed but no MCP server rows are enabled. The adapter is NOT booted; no MCP REST endpoints appear.

**Independent Test**: With `\WP\MCP\Plugin` present but zero enabled rows, `Controller::get_adapter_status()` returns `'disabled'`. `\WP\MCP\Plugin::instance()` is never called.

**Acceptance Scenarios**:

1. **Given** zero enabled server rows (all rows have `is_enabled = 0` or the table is empty), **When** `rest_api_init` fires and `Controller::initialize_adapter()` runs, **Then** the method returns early, sets status to `'disabled'`, and does NOT call `\WP\MCP\Plugin::instance()`.

### User Story 3 — Adapter absent: graceful degradation (Priority: P1)

A site administrator has NOT installed the WP MCP adapter package. The plugin still activates cleanly, the admin UI still renders, and the "adapter not installed" notice appears (via existing `Notices.php` mechanism).

**Independent Test**: With `class_exists('\WP\MCP\Plugin') === false` + ≥1 enabled server row, `Controller::get_adapter_status()` returns `'not-found'`. No fatal error. No PHP warning.

**Acceptance Scenarios**:

1. **Given** `\WP\MCP\Plugin` class does not exist AND ≥1 enabled server row, **When** `Controller::initialize_adapter()` runs, **Then** it returns early with status `'not-found'`, no exception, no `error_log` output.
2. **Given** the same state, **When** the admin page loads, **Then** `Notices::render_missing_adapter_notice()` fires the existing admin banner (unchanged behavior).

### Edge Cases

- **Exception thrown by `\WP\MCP\Plugin::instance()`**: `Controller` catches the exception, sets status to `'error'`, and fires the `acrossai_mcp_manager_adapter_init_error` action only when `WP_DEBUG` is true. No fatal.
- **`Controller::get_adapter_status()` called before `initialize_adapter()`**: the method calls `initialize_adapter()` inline so the returned status reflects the current DB state.
- **Missing `server_slug`** on an enabled row: `register_database_servers` skips that row (continue) rather than calling `create_server` with an empty slug.
- **`create_server` returns `WP_Error`**: emit `_doing_it_wrong()` with the error code + message; continue with remaining rows.

---

## Requirements

### Functional Requirements

- **FR-001**: `includes/MCP/Controller.php` MUST exist with namespace `AcrossAI_MCP_Manager\Includes\MCP`.
- **FR-002**: Class follows singleton pattern: `protected static $_instance = null;`, `public static function instance(): self`, `private function __construct() {}`. Constructor body MUST be empty (A1 — no `add_action`/`add_filter`).
- **FR-003**: `public function initialize_adapter(): void` — reads enabled MCP server rows via `Includes\Database\MCPServer\Query` (NOT v0.0.4's static `MCPServerTable`); state machine returns early with `'disabled'` / `'not-found'` / `'error'` per the source semantics.
- **FR-004**: `public function register_database_servers( \WP\MCP\Core\McpAdapter $adapter ): void` — iterates enabled rows with `registered_from = 'database'` and calls `$adapter->create_server()` with the row's fields.
- **FR-005**: `public function get_adapter_status(): string` — returns one of `'running'`, `'disabled'`, `'not-found'`, `'error'`, `'unknown'`. Calls `initialize_adapter()` lazily if not yet called.
- **FR-006**: Hook wiring in `includes/Main.php::define_admin_hooks()` (or `define_public_hooks()`): `$this->loader->add_action( 'rest_api_init', $mcp_controller, 'initialize_adapter' );` — replaces the current TODO comment lines 352–354.
- **FR-007**: `use` imports at the top of the file: `AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery`, plus `WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler`, `WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler`, `WP\MCP\Transport\HttpTransport`. Follows A6.
- **FR-008**: DB reads use the target's Query API (instance method): `(new MCPServerQuery())->query(['is_enabled' => 1, 'number' => 1])` for the has-any check; `(new MCPServerQuery())->query(['is_enabled' => 1, 'registered_from' => 'database'])` for the server list.
- **FR-009**: Row property access uses public typed properties (per target Row class): `$row->server_slug`, `$row->server_name`, `$row->description`, `$row->server_route_namespace`, `$row->server_route`, `$row->server_version`.
- **FR-010**: On `create_server` returning `WP_Error`, emit `_doing_it_wrong()` with the error code + message; do NOT throw; continue with remaining rows.
- **FR-011**: On `\WP\MCP\Plugin::instance()` throwing, catch as `\Throwable`, set status to `'error'`, and fire the `acrossai_mcp_manager_adapter_init_error` action ONLY when `WP_DEBUG === true`.

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ |
| WordPress version | 6.9+ |
| Multisite | Single-site only this phase |
| Required Composer packages | `wordpress/mcp-adapter` (optional at runtime — graceful degradation if absent per US3) |
| Required existing classes | `Includes\Database\MCPServer\Query`, `Includes\Database\MCPServer\Row`, `Includes\Main::define_admin_hooks` (Loader wiring), `Admin\Partials\Notices` (adapter-missing banner — unchanged) |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `includes/MCP/Controller.php` | `AcrossAI_MCP_Manager\Includes\MCP` | **NEW** — port from v0.0.4 `src/MCP/Controller.php` with namespace + DB API + singleton adjustments |
| `includes/Main.php` | (existing) | **Extend** — uncomment lines 352–354 (the TODO block) with the corrected instance + hook wiring |
| `tests/phpunit/MCP/ControllerTest.php` | `AcrossAI_MCP_Manager\Tests\MCP` | **NEW** — 4 smoke cases: singleton stability + adapter-absent + zero-enabled-servers + enabled+adapter (skip when `WP\MCP\Plugin` not loadable) |

### Security Checklist

- [ ] No REST routes registered by this class directly (delegates entirely to `\WP\MCP\Plugin`)
- [ ] No user input consumed
- [ ] No `wp_die` / `wp_send_json` from this class
- [ ] `_doing_it_wrong` error path escapes all interpolated values (`esc_html`) — matches v0.0.4 pattern
- [ ] Adapter init exceptions caught with `\Throwable`; no partial state leak

---

## Success Criteria

- [ ] `includes/MCP/Controller.php` exists per FR-001; PHPCS + PHPStan L8 clean
- [ ] `includes/Main.php` TODO block replaced with active Loader wiring; no PHPCS regressions on the file
- [ ] `tests/phpunit/MCP/ControllerTest.php` — 4 test cases; passes when WP-PHPUnit harness is installed
- [ ] `Controller::instance()` returns stable singleton (verified by test)
- [ ] `Controller::get_adapter_status()` state machine returns the correct string in each of 3 branches (disabled, not-found, running-attempt) — verified by test
- [ ] `grep -rn 'add_action\|add_filter' includes/MCP/Controller.php` returns zero matches (A1)
- [ ] Manual quickstart: after installing `wordpress/mcp-adapter` + creating an enabled server row via the admin UI, `curl <wp-site>/wp-json/mcp/<server-slug>` returns a valid MCP response (live-env verification only)

---

## Assumptions

- The `wordpress/mcp-adapter` package's public API surface (`\WP\MCP\Plugin::instance()`, `\WP\MCP\Core\McpAdapter::create_server()`, `mcp_adapter_init` action, `HttpTransport` / `ErrorLogMcpErrorHandler` / `NullMcpObservabilityHandler` class names) matches v0.0.4 — no upstream breaking changes since v0.0.4 was written. If the upstream shape has drifted, adapt during implementation.
- `Includes\Database\MCPServer\Query::query()` accepts the argument shape `['is_enabled' => 1, 'registered_from' => 'database', 'number' => N]` per its Feature-002 spec — verified via grep of the target's Query.php (line 49+).
- `Includes\Database\MCPServer\Row` public typed properties match v0.0.4 column names (`server_slug`, `server_name`, `description`, `server_route_namespace`, `server_route`, `server_version`) — verified via grep of the target's Row.php.
- Hook priority for `rest_api_init` is default (10) — matches the TODO comment; no priority argument needed.
- No new PHPUnit fixtures required; tests use `WP_UnitTestCase` factory to seed MCP server rows.

---

## Non-Goals

- **REST endpoint testing of the actual `\WP\MCP\Plugin` behavior** — that's the adapter package's own test surface, not this migration's concern.
- **Refactoring the mcp-adapter API consumption** — port v0.0.4 verbatim (with API adjustments for the new Query pattern). Improvements are out of scope.
- **Multisite awareness** — single-site only, matching v0.0.4.
- **Additional server-registration hooks** — only `mcp_adapter_init` priority 11 (matching source), no others.
