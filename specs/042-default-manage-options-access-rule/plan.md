# Implementation Plan: Runtime-Filter Default of `manage_options` for MCP Servers

**Branch**: `feat/042-default-manage-options-access-rule` | **Date**: 2026-08-09 | **Spec**: [spec.md](./spec.md)

## Summary

Register a single filter callback on the vendor `mcp_adapter_default_transport_permission_user_capability` filter that returns `'manage_options'` when the current server has no wpb-ac rule configured, otherwise returns the vendor default (`'read'`) so the existing F015 wrapper's `gate_mcp_tool_call` (on `mcp_adapter_pre_tool_call`) handles real per-rule enforcement.

**Rejected alternative (Q1)**: seeding a `wp_capability = manage_options` row into `wp_mcp_access_control` at server-creation time. The vendor UI's admin state model treats seeded rows differently than we expected — the "Who can access" dropdown still displayed "No user access added by admin" after our write, producing misleading operator UX and coupling our defaults to the operator-editable table. The runtime-filter approach has zero DB writes and applies uniformly to all servers.

## Technical Context

**Language/Version**: PHP 7.4+ (plugin min), tested on PHP 8.1+/8.4.
**Primary Dependencies**: `wordpress/mcp-adapter` (owns the filter this feature hooks) + `wpboilerplate/wpb-access-control` v2/v3 (rule read via `RuleQuery::get_rule()`). Both already loaded via composer.
**Storage**: Reads only. One `RuleQuery::get_rule()` call per unique route per MCP HTTP request (transient-cached inside the vendor).
**Testing**: 2 PHPUnit test files under `tests/phpunit/Includes/AccessControl/` (picked up by the existing `admin` suite — no `phpunit.xml.dist` change):
- `TransportPermissionDefaultTest.php` — 14 unit tests covering every branch of `filter_default_capability()` in isolation. Real `MCPServerQuery` + real vendor `RuleQuery`, no mocks. Transactional DB rollback per test.
- `TransportPermissionRoleMatrixTest.php` — 12 composed integration tests exercising **both filters end-to-end** via a `user_can_reach()` helper that mirrors `HttpTransport::check_permission` (layer 1) + `gate_mcp_tool_call` (layer 2). Covers 6 user roles × 4 rule shapes (none / wp_role / wp_user / wp_capability / `authenticated` / `everyone`) × ≥4 servers per test, including a 5×4 truth-table matrix and a multi-server user-ID rule test that directly proves per-server independence.

Manual verification recipe in `spec.md` SC-001..SC-006 remains as an operator smoke test.
**Target Platform**: WordPress admin + REST, any host.
**Project Type**: WordPress plugin — 1 new file (`TransportPermissionDefault.php`) + 2 edited (`Main.php`, `AccessControlTab.php`).
**Performance Goals**: One `MCPServerQuery::query()` + one `RuleQuery::get_rule()` per unique route per MCP HTTP request. Memoized per-request in a private array. Vendor `get_rule()` uses a transient cache under the hood.
**Constraints**:

- MUST hook via the plugin Loader per A1 — no direct `add_filter()`.
- MUST use the 5-arg Loader signature `($hook, $component, $callback_string, $priority, $accepted_args)` to avoid B43 (wrapping callback in array — silent latent fatal at fire time).
- MUST match the namespace literal `'acrossai-mcp-manager'` used by `AcrossAI_MCP_Access_Control` lines 243/304 — no new constant introduced.
- MUST NOT write to `wp_mcp_access_control` from any plugin code path — the prior DB-seeder approach is fully removed.
- MUST fail-open when the wpb-ac vendor is absent — mirrors F015's `is_available()` contract.
- MUST NOT modify `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` — the tool-call gate is the correct enforcement layer for operator-configured rules and stays unchanged.

**Scale/Scope**: 1 new file (~140 lines w/ docblocks), 2 edited files (~5 lines in `Main.php` for filter wiring, ~20 lines in `AccessControlTab.php` for banner method + call). Net ~165 LoC added.

## Constitution Check

*Evaluated 2026-08-09 against project constitution v1.1.0.*

| Principle | Applicability | Verdict |
|---|---|---|
| I — Modular Architecture (feature classes in `includes/`, admin in `admin/Partials/`, hooks in `Main.php` only) | Yes — new class in `includes/AccessControl/`; UI banner in `admin/Partials/ServerTabs/`; filter wired ONLY in `Main::define_public_hooks()` | PASS |
| II — Singleton + private `__construct()` | Yes — `TransportPermissionDefault::instance()` follows the plugin-wide pattern | PASS |
| III — Security (nonces, capability, sanitization, escaping) | Yes — read-only filter callback; no user input reaches it; UI banner uses `esc_html__()` and hardcodes `<code>` markers | PASS |
| IV — DataForm / DataViews UI contract | N/A — no form/table added; a static banner div is not a form | PASS |
| V — Extensibility via filters | Yes — the whole feature IS a filter callback; consumers who want a different default hook the same vendor filter at priority > 10 | PASS |
| VI — WordPress packages first | N/A — no JS/CSS changes | PASS |
| VII — Package hierarchy | N/A — no JS package added | PASS |

**Constitution invariants checked against memory:**
- **A1** (hooks in `Main.php` only) — filter wiring is in `define_public_hooks()`. ✓
- **A6** (imports required, no bare relative namespace) — `use HttpRequestContext`, `use MCPServerQuery`, `use RuleQuery` at the top of the new file. ✓
- **B43** (Loader signature) — using 5-arg form `add_filter( 'hook', $instance, 'method', 10, 2 )`, not `add_filter( 'hook', array( $instance, 'method' ), 10, 2 )`. ✓
- **D19** (fail-open observability) — behavior fails open on unknown route / missing vendor / missing server; no `do_action` observability fire because this is a plugin-wide default rather than a per-request access decision. ✓

**No constitution deviations. No memory-hub decision-level entry warranted** — this feature applies a known-good runtime-filter pattern; the durable lesson is the "vendor-UI-owns-the-table → don't write to that table" note captured briefly in the planings-tasks nav.

## Two-filter per-server architecture (deep dive)

The completed feature ships **two vendor-filter callbacks running side-by-side, each server-scoped, together forming a defense-in-depth stack** for MCP HTTP requests:

### Filter 1 — Transport permission (F042, this feature)

- **Vendor filter**: `mcp_adapter_default_transport_permission_user_capability`
- **Fires at**: `vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:119`, inside the REST `permission_callback` for every MCP request.
- **Signature**: `(string $default, HttpRequestContext $context): string`
- **Callback**: `\AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault::filter_default_capability`
- **Registration**: `includes/Main.php:459` — `$this->loader->add_filter( 'mcp_adapter_default_transport_permission_user_capability', $transport_default, 'filter_default_capability', 10, 2 )`
- **How it resolves the current server**: extracts route via `$context->request->get_route()` → splits on first `/` into `(namespace, route)` → `MCPServerQuery::instance()->query([ 'server_route_namespace' => $ns, 'server_route' => $path ])` → uses matched row's `server_slug`.
- **Verdict shape**: returns a WP capability string; the vendor caller runs `current_user_can( <returned-cap> )`. `false` → HTTP 401.

### Filter 2 — Tool-call gate (F015, pre-existing, unchanged)

- **Vendor filter**: `mcp_adapter_pre_tool_call`
- **Fires at**: `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:182`, immediately before every MCP tool execution.
- **Signature**: `(array $args, string $tool_name, McpTool $tool, McpServer $server): array|WP_Error`
- **Callback**: `\AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::gate_mcp_tool_call`
- **Registration**: `includes/Main.php:453` — `$this->loader->add_filter( 'mcp_adapter_pre_tool_call', $access_control, 'gate_mcp_tool_call', 10, 4 )` (priority 10; F017 + F020 layer on at priorities 20 + 30 per DEC-F020-TOOL-ENFORCEMENT-PRIORITY).
- **How it resolves the current server**: reads `$server->get_server_id()` directly from the McpServer instance passed as the 4th filter arg → cross-verifies via `MCPServerQuery` → uses the row's `server_slug`.
- **Verdict shape**: returns `$args` on allow; returns `WP_Error( 'acrossai_mcp_access_denied', ..., array( 'status' => 403 ) )` on deny; fires `acrossai_mcp_access_control_denied` observability action before every deny return.

### Combined per-request flow

```
Incoming MCP HTTP request → /wp-json/mcp/<some-server-slug>
                    │
                    ▼
[WP REST router matches route registered by HttpTransport::register_routes]
                    │
                    ▼
[HttpTransport::check_permission() → apply_filters( filter-1 )]  ◄─ FILTER 1
   ├─ TransportPermissionDefault resolves server via URL route
   ├─ Looks up wpb-ac rule for that server_slug
   ├─ No rule  → returns 'manage_options' → current_user_can('manage_options')
   │            → subscriber/editor/etc: FALSE → HTTP 401 (request stops here)
   │            → admin: TRUE → continues
   └─ Rule set → returns 'read' → current_user_can('read')
                → any authenticated user: TRUE → continues
                → anonymous: FALSE → HTTP 401
                    │
                    ▼
[MCP request handler routes to tools/call handler]
                    │
                    ▼
[ToolsHandler::call_tool() → apply_filters( filter-2 )]  ◄─ FILTER 2
   ├─ gate_mcp_tool_call resolves server via $server->get_server_id()
   ├─ Looks up wpb-ac rule for that server_slug
   ├─ No rule  → user_has_access() returns TRUE (vendor fail-open)
   │            → returns $args (allow) → tool executes
   └─ Rule set → user_has_access() evaluates rule against current user
                → allow: returns $args → tool executes
                → deny: returns WP_Error 403 (rule violation)
```

### Why both filters are needed (defense-in-depth)

- Filter 1 alone can't do rule enforcement — it only returns a *capability string*, which can't express "role editor OR user id 5 OR capability edit_pages". So we defer to filter 2 for real rule work.
- Filter 2 alone was fail-open on rules-less servers — any authenticated user could reach any un-configured server. Filter 1 closes that gap.
- Together: rules-less servers are admin-only (filter 1 hard-stops non-admins); rule-configured servers get precise enforcement (filter 2 evaluates the rule).
- **Neither filter has any hardcoded server slug or "default server special case"** — both resolve the current server independently per request from the request context.

### Test coverage of the two-filter, per-server property

| Property | Test file:method |
|---|---|
| Filter 1 wired correctly, callable via `apply_filters()` | `TransportPermissionDefaultTest::test_filter_is_wired_and_intercepts_vendor_default` |
| Filter 1 admin-only when no rule | `TransportPermissionDefaultTest::test_returns_manage_options_when_server_exists_with_no_rule` |
| Filter 1 defers when rule exists | `TransportPermissionDefaultTest::test_returns_default_when_server_has_any_rule` |
| Filter 1 per-server memoization has no cross-server bleed | `TransportPermissionDefaultTest::test_memo_keys_are_per_route_and_do_not_collide` |
| Filter 2 wired correctly (pre-existing, still passing) | `AcrossAI_MCP_Access_Control_Test::test_gate_mcp_tool_call_*` (F015 legacy tests) |
| Both filters composed end-to-end, per (user, server) verdict | `TransportPermissionRoleMatrixTest::user_can_reach()` — used by every test in that file |
| Multi-server independence (5×4 truth table) | `TransportPermissionRoleMatrixTest::test_multi_server_multi_user_matrix_evaluates_each_pair_independently` |
| Same user, multiple servers with different wp_user rules | `TransportPermissionRoleMatrixTest::test_single_user_reaches_only_servers_whose_user_id_rule_lists_them` |
| Rule mutation between simulated requests takes effect on both filters | `TransportPermissionRoleMatrixTest::test_rule_change_between_requests_takes_effect_after_memo_reset` |

## Implementation Notes

### File 1: `includes/AccessControl/TransportPermissionDefault.php` (NEW)

Singleton. Constants:
- `NAMESPACE_SLUG = 'acrossai-mcp-manager'` (private const).
- `ADMIN_ONLY_CAPABILITY = 'manage_options'` (private const).

Per-request memoization: `private array $memo = []` keyed by `"$ns/$route"`.

One public method: `filter_default_capability( string $default, HttpRequestContext $context ): string`. Early returns:
1. Route is empty → `$default`.
2. Route doesn't split into 2 non-empty parts on the first `/` → `$default`.
3. No MCPServer row matches (`namespace, route`) → `$default` (route belongs to some other consumer of the vendor filter).
4. `server_slug` is empty → `$default` (defensive).
5. Vendor `RuleQuery` class missing → `$default` (fail-open).
6. Existing rule (`get_rule()['key'] !== ''`) → `$default` (defer to `gate_mcp_tool_call`).
7. Otherwise → `'manage_options'`.

Every branch writes to `$this->memo[$memo]` before returning.

### File 2: `includes/Main.php` (EDITED, ~5 lines added)

Inside `define_public_hooks()`, immediately after the existing `mcp_adapter_pre_tool_call` filter registration (line 453), add:

```php
$transport_default = \AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault::instance();
$this->loader->add_filter( 'mcp_adapter_default_transport_permission_user_capability', $transport_default, 'filter_default_capability', 10, 2 );
```

The two access-control filters now sit together — mental grouping for future maintainers.

### File 3: `admin/Partials/ServerTabs/AccessControlTab.php` (EDITED, ~20 lines added)

1. Prepend `$this->render_default_policy_notice();` at the top of `render_body()`.
2. Add a new `private function render_default_policy_notice(): void` between `render_body()` and `render_pom_header()` that emits the info-banner div with the "Default policy: administrators only." headline and body text explaining the "No user access added by admin" ↔ admin-only mapping.

## Files removed vs pre-042 shape

None. This branch was reset to `main` before applying the filter approach, so no reverts are needed — the DB-seeder code from the prior PR #71 attempt never lands on main.

## Quality Gates

- `php -l` on the 3 files → No syntax errors.
- `vendor/bin/phpcs --standard=phpcs.xml.dist` on `TransportPermissionDefault.php` + `AccessControlTab.php` → 0 errors (Main.php has pre-existing baseline exceptions on unrelated lines, per PHPCS baseline).
- `vendor/bin/phpstan analyse` (L8) on all 3 files → exit 0, no errors.
- Manual recipe in `spec.md` SC-001..SC-006 on local site → operator to run post-merge.

## PR mechanics

The prior PR #71 shipped the DB-seeder approach. This plan replaces those commits via a `git reset --hard origin/main` + rebuild + `git push --force-with-lease`. The PR body is updated via `gh pr edit` to describe the runtime-filter approach. No new PR number is issued; PR #71 stays the same URL.

## References

- **Vendor filter**: `vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:119` (`apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', $context )`).
- **Vendor HttpRequestContext shape**: `vendor/wordpress/mcp-adapter/includes/Transport/Infrastructure/HttpRequestContext.php:15` (public `$request` WP_REST_Request + method + session_id + body + protocol_version + accept_header).
- **Vendor RuleQuery**: `vendor/wpboilerplate/wpb-access-control/src/Database/Rule/RuleQuery.php:163` (`get_rule` returns `['key' => '', 'value' => []]` on empty; transient-cached).
- **F015 wrapper**: `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (namespace hardcoding at lines 243/304; `TABLE_SLUG` at line 65; unchanged tool-call gate at line 260).
- **Access Control tab URL**: `/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=<id>&tab=access-control`.
