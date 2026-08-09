# Feature Specification: Runtime-Filter Default of `manage_options` for MCP Servers

**Feature Branch**: `feat/042-default-manage-options-access-rule`
**Created**: 2026-08-09
**Status**: Shipped
**Input**: User request: "Every MCP server should default to admin-only (`manage_options`) access. When the wpb-ac 'Who can access' dropdown reads 'No user access added by admin', the rule should be admin-only; when any other access type is set, follow the access-control decision."

## Clarifications

### Session 2026-08-09

- Q1 (approach): DB-row seeding at server-creation time (write `wp_capability = manage_options` into `wp_mcp_access_control`) OR runtime filter on the vendor mcp-adapter's transport permission callback? → **A: Runtime filter (chosen).** DB-row seeding was tried first and rejected — the vendor UI dropdown still displayed "No user access added by admin" after our seeded row, producing misleading operator UX and coupling our defaults to the operator-editable table. A runtime filter has zero DB writes, applies to ALL servers (pre-042 legacy + new + user-created + seeded default), and never conflicts with the vendor's admin state model.
- Q2 (filter semantics): static `manage_options` always (breaks operator-added rules for non-admins) OR rule-aware (defer when a rule exists)? → **A: Rule-aware.** When wpb-ac has no rule for the current server, the filter returns `manage_options` (admin-only). When any rule exists, the filter returns the vendor default (`read`) so the existing F015 wrapper's `gate_mcp_tool_call` (on `mcp_adapter_pre_tool_call`) does the real per-rule enforcement. This preserves the admin UI flow: adding an editor-role rule via the vendor panel actually grants editors access.

## Summary

Hook the vendor mcp-adapter filter `mcp_adapter_default_transport_permission_user_capability` (fired at `vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:119`, defaults to `'read'`) with a rule-aware callback:

1. Parse the current request route (from `HttpRequestContext->request->get_route()`) into `(namespace, route)`.
2. Look up the matching MCP server row via `MCPServerQuery` — if none matches, pass the vendor default through unchanged (fail-open on unknown route).
3. Query wpb-ac `RuleQuery::get_rule('acrossai-mcp-manager', $server_slug)`.
4. If `$existing['key'] !== ''` (any rule configured) → return the vendor default (`'read'`) and defer to `gate_mcp_tool_call`.
5. If `$existing['key'] === ''` (no rule configured — vendor UI shows "No user access added by admin") → return `'manage_options'`.

Per-request memoization ensures the DB lookup runs at most once per unique route per request. No writes to any table. The Access Control tab gets an info banner explaining the runtime-filter default policy.

## Two-filter, per-server enforcement architecture

Every MCP HTTP request passes through **two vendor filters, both server-scoped**:

| # | Filter | Wired by | Callback | How it resolves "which server" | Verdict shape |
|---|---|---|---|---|---|
| 1 | `mcp_adapter_default_transport_permission_user_capability` | `Main.php:459` (F042 — this feature) | `TransportPermissionDefault::filter_default_capability` | Parses `HttpRequestContext->request->get_route()` into `(namespace, route)`; `MCPServerQuery` lookup → `server_slug` | Returns a WP capability string; caller runs `current_user_can()` |
| 2 | `mcp_adapter_pre_tool_call` | `Main.php:453` (F015 — pre-existing) | `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` | Reads `$server->get_server_id()` from the McpServer instance passed as filter arg → `server_slug` | Returns `$args` on allow, `WP_Error 403` on deny |

**Per-server-ness is complete** — no server is treated as special. Both callbacks resolve the current server's slug from the request context (route in filter 1, McpServer object in filter 2), then look up rules keyed on that slug in `wp_mcp_access_control`. Add a new server → both filters pick it up automatically at the next request.

**Layered decision** for any (user, server, request):
1. **Filter 1 runs first** at the WP REST `permission_callback` stage. If no rule → demands `manage_options` → subscribers/editors/authors/contributors 401. If a rule exists → returns vendor default `'read'` → any subscriber+ passes filter 1.
2. **Filter 2 runs second** at MCP tool-call dispatch. If no rule → fail-open (allow). If a rule → evaluates wpb-ac `user_has_access()` against the operator's rule → returns `WP_Error 403` on deny.

Net effect (per server): admin-only when no rule; operator's rule enforced when a rule exists. The two filters together form a **defense-in-depth** stack — filter 1 hard-blocks non-admins from ever reaching the tool-call layer on rules-less servers; filter 2 is the truth for rule-configured servers.

## User Story - MCP servers are admin-only until the operator sets a rule (Priority: P1)

An administrator activates the plugin (or updates from a pre-042 version). Every server — the seeded default, any pre-existing user-created server, any server they create after — starts admin-only at the transport layer. When they open the Access Control tab, the "Who can access" dropdown reads "No user access added by admin" (unchanged vendor UI state). A subscriber user attempting to hit any server's MCP endpoint receives 401 (blocked at the transport gate by `current_user_can('manage_options')`).

The admin then sets a rule via the vendor dropdown — say `WordPress role → Editor`, Save. The dropdown now reads "WordPress role". On the next request, the transport gate returns `'read'` (permissive), the request routes through to the tool-call handler, and `gate_mcp_tool_call` (unchanged F015 code) evaluates the editor-role rule → editors get access, non-editors get a per-tool-call 403.

**Why P1**: this is the entire feature. If either side breaks (rules-less servers not admin-only, OR rules-configured servers still admin-only), the feature is not shipped.

**Independent Test**:
1. Delete every row from `wp_mcp_access_control` (`DELETE FROM wp_mcp_access_control;`).
2. Confirm every server's Access Control tab dropdown reads "No user access added by admin".
3. `curl` the default server endpoint as anonymous → 401.
4. `curl` as subscriber → 401.
5. `curl` as admin → 200.
6. In the dropdown, set `WordPress role → Editor`, save.
7. Confirm dropdown reads "WordPress role".
8. `curl` as editor → 200. `curl` as subscriber → 403.

## Functional Requirements

- **FR-001**: A new singleton `\AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault` MUST expose `filter_default_capability( string $default, \WP\MCP\Transport\Infrastructure\HttpRequestContext $context ): string`.
- **FR-002**: The callback MUST parse the WP REST route from `$context->request->get_route()`, strip the leading `/`, split on the first `/` into `(namespace, route)`. If the split yields fewer than 2 non-empty parts → return `$default` unchanged.
- **FR-003**: The callback MUST query `MCPServerQuery::instance()->query([ 'server_route_namespace' => $ns, 'server_route' => $route, 'number' => 1 ])`. If no row matches → return `$default` (route not owned by this plugin, defer to vendor).
- **FR-004**: The callback MUST fetch `new RuleQuery( AcrossAI_MCP_Access_Control::TABLE_SLUG )->get_rule( 'acrossai-mcp-manager', $server_slug )`. If `class_exists( RuleQuery::class )` returns false → return `$default` (fail-open, matches F015 `is_available()` contract).
- **FR-005**: When `$existing['key'] !== ''` → return `$default` (rule exists; defer to `gate_mcp_tool_call`).
- **FR-006**: When `$existing['key'] === ''` → return `'manage_options'` (admin-only).
- **FR-007**: The callback MUST memoize its result per unique `"$ns/$route"` key in a private `$memo` array so repeated fires within a single MCP HTTP request cause at most one DB lookup.
- **FR-008**: The filter MUST be wired in `Main::define_public_hooks()` via `$this->loader->add_filter( 'mcp_adapter_default_transport_permission_user_capability', $instance, 'filter_default_capability', 10, 2 )` — matches A1 (Loader-only hook wiring) and avoids B43 (5-arg Loader signature, not native `add_filter` shape).
- **FR-009**: `AccessControlTab::render_body()` MUST prepend a `<div class="notice notice-info inline">` above the vendor React panel with a bold "Default policy: administrators only." headline and body text describing the "No user access added by admin" ↔ admin-only mapping and the ability to broaden access via the dropdown.
- **FR-010**: NO DB writes to `wp_mcp_access_control` from plugin code — the earlier DB-row-seeding approach (`DefaultRuleSeeder`) MUST NOT exist in the final tree. Grep gate: `git grep 'RuleQuery.*set_rule\|access_control.*INSERT'` on the branch must return only vendor code.
- **FR-011**: Both permission filters MUST resolve the current server independently per request — no hardcoded server slug, no "default server special case", no cross-server state. Filter 1 (`filter_default_capability`) resolves via URL route → `MCPServerQuery` lookup; filter 2 (`gate_mcp_tool_call`) resolves via `$server->get_server_id()`. Adding a new server via **Add New Server** MUST cause both filters to apply admin-only default to it on the next request with no code change or activation step.
- **FR-012**: Filter 1 and filter 2 MUST both be wired via the Loader in `Main::define_public_hooks()` per A1 (no direct `add_filter()` calls, 5-arg Loader signature per B43). The two registrations MUST sit adjacent in code so future maintainers see the pair.

## Non-Goals

- **No DB writes.** The prior DB-seeder approach (rejected per Q1) is deleted, not retained as a fallback.
- **No backfill of pre-042 servers.** The runtime filter applies to every server automatically, so a backfill routine would be redundant.
- **No modification to `AcrossAI_MCP_Access_Control` (F015 wrapper).** The wrapper's enforcement paths (`gate_mcp_tool_call`, `user_has_server_access`) are unchanged.
- **No new WP capability, role, or user-facing setting.** The default capability is hardcoded to `manage_options`.
- **No filter to override the default from PHP.** If a future consumer wants a different default (e.g. `edit_posts` instead of `manage_options`), they hook `mcp_adapter_default_transport_permission_user_capability` at priority > 10 and return their own value. Our callback returns from priority 10.

## Success Criteria

- **SC-001**: With every row deleted from `wp_mcp_access_control`, hitting the default server endpoint (`/wp-json/mcp/mcp-adapter-default-server`) as an anonymous user → 401.
- **SC-002**: Same setup, as a subscriber → 401 (transport gate blocks — `current_user_can('manage_options')` is false).
- **SC-003**: Same setup, as an administrator → 200.
- **SC-004**: With a `WordPress role → Editor` rule set via the vendor dropdown, hitting the endpoint as an editor → 200 (transport gate returns `read` → tool-call gate allows editors).
- **SC-005**: Same rule, as a subscriber → 403 with `WP_Error acrossai_mcp_access_denied` (transport gate passes on `read`; tool-call gate denies).
- **SC-006**: Same behavior on a newly-created server via **Add New Server** — no code path specific to the seeded default vs user-created; the filter treats every plugin-owned route identically.
- **SC-007**: PHPCS clean on the new file + edited files (relative to their pre-042 baselines); PHPStan L8 exit 0.
- **SC-008**: `git grep 'RuleQuery.*set_rule\|new RuleQuery' includes/ admin/` returns exactly one hit (`TransportPermissionDefault.php` `new RuleQuery` — for the read call) and zero writes.
- **SC-009**: `git grep -n "'mcp_adapter_pre_tool_call'\|'mcp_adapter_default_transport_permission_user_capability'" includes/Main.php` returns exactly TWO hits, both inside `define_public_hooks()`, both using the 5-arg Loader shape (`$this->loader->add_filter( 'hook', $singleton, 'method', 10, N )` — never `array( $obj, 'method' )`).
- **SC-010**: The PHPUnit suite `admin` includes two feature-042 test files at `tests/phpunit/Includes/AccessControl/`:
  - `TransportPermissionDefaultTest.php` — 14 tests covering every branch of `filter_default_capability()` in isolation (route parsing, server lookup, rule presence, default passthrough, memoization, filter-wiring regression guard).
  - `TransportPermissionRoleMatrixTest.php` — 12 tests composing both filters end-to-end via `user_can_reach()`, exercising 6 user roles × 4 rule shapes × ≥4 servers per test = 36+ (user, server) verdict assertions. Explicitly proves per-server independence via a 5×4 multi-server truth table (Group G).
- **SC-011**: Each test that creates a fixture server uses a `uniqid()`-suffixed slug (`no-rule-{uniq}`, `matrix-editor-{uniq}`, etc.) and asserts against that slug — proving the code is server-agnostic (no test hardcodes `mcp-adapter-default-server` except one dedicated regression test that names it).
