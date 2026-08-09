# Planning: Runtime-Filter Default of `manage_options` for MCP Servers (Feature 042)

Hook the vendor `mcp_adapter_default_transport_permission_user_capability` filter with a rule-aware callback. When the wpb-ac "Who can access" dropdown reads "No user access added by admin" (no rule configured) → the transport permission defaults to `'manage_options'`. When any rule exists (Anyone / Authenticated / role / user / capability) → the transport permission defaults to `'read'` (vendor default) and the existing F015 wrapper's `gate_mcp_tool_call` handles enforcement per the operator's rule.

**Rejected earlier approach (Q1)**: seeding a `wp_capability = manage_options` row into `wp_mcp_access_control` at server-creation time. The vendor admin UI still displayed "No user access added by admin" after the write, producing misleading operator UX. Runtime filter has zero DB writes, applies uniformly to every server (pre-042 legacy + new + user-created + seeded default), and never conflicts with the vendor's admin state model.

## Authoritative sources

- Spec: [`specs/042-default-manage-options-access-rule/spec.md`](../../specs/042-default-manage-options-access-rule/spec.md)
- Plan: [`specs/042-default-manage-options-access-rule/plan.md`](../../specs/042-default-manage-options-access-rule/plan.md)
- Tasks: [`specs/042-default-manage-options-access-rule/tasks.md`](../../specs/042-default-manage-options-access-rule/tasks.md)
- PR: https://github.com/acrossai-co/acrossai-mcp-manager/pull/71

## Two filters, both per-server (the architectural picture)

The completed feature runs **two vendor-filter callbacks side-by-side**, each server-scoped, together forming a defense-in-depth stack:

| Layer | Vendor filter | Callback | Server resolved via | Verdict |
|---|---|---|---|---|
| **1** — Transport permission (F042, **added just now**) | `mcp_adapter_default_transport_permission_user_capability` | `TransportPermissionDefault::filter_default_capability` @ `Main.php:459` | `HttpRequestContext->request->get_route()` → `MCPServerQuery` lookup | Capability string → `current_user_can()` |
| **2** — Tool-call gate (F015, **old one, pre-existing**) | `mcp_adapter_pre_tool_call` | `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` @ `Main.php:453` | `$server->get_server_id()` from the McpServer instance passed as filter arg | `$args` on allow, `WP_Error 403` on deny |

Both callbacks resolve the current server independently per request. Neither has any hardcoded server slug or "default server special case" — add a new server via **Add New Server** and both filters pick it up on the next request with no code change.

**Per-request flow**: request lands → filter 1 decides "admin-only or defer?" based on rule presence for THIS server → if pass, request routes to tool-call handler → filter 2 decides "allow or deny?" based on the rule for THIS server (fail-open if none). Rules-less servers are hard-blocked to admins at filter 1; rule-configured servers get precise enforcement at filter 2.

## Final scope

Retained:
- New `\AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault` singleton — one public method wired via Loader on `mcp_adapter_default_transport_permission_user_capability`.
- Info banner on `AccessControlTab::render_body()` describing the runtime-filter default policy.
- **26 PHPUnit tests** across 2 files at `tests/phpunit/Includes/AccessControl/`:
  - `TransportPermissionDefaultTest.php` — 14 unit tests for the filter callback in isolation.
  - `TransportPermissionRoleMatrixTest.php` — 12 composed tests exercising BOTH filters end-to-end across 6 user roles × multiple rule shapes × multi-server matrices.

Not in scope:
- DB writes of any kind to `wp_mcp_access_control` (removed with the earlier DB-seeder approach — never merged).
- Backfill of pre-042 servers (runtime filter applies to every server automatically, so no backfill needed).
- New constant class for the namespace literal (`'acrossai-mcp-manager'` matches the existing wrapper hardcoding at lines 243/304).
- Modifications to `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` (F015 wrapper) — unchanged; filter 2 keeps running as-is next to filter 1.

## Durable lesson

**When a vendor package owns the admin UI over a shared table, prefer a runtime filter over DB-row writes for default state.** The vendor's read path may pre-filter or interpret rows in ways the operator UI depends on; writing "our" rows through the vendor API doesn't guarantee the vendor UI treats them as operator-configured (see PR #71 initial attempt: seeded rows enforced correctly at the AC layer but the dropdown still displayed "No user access added by admin"). Runtime filters sit above the vendor's read path entirely, apply uniformly to every row, and avoid coupling defaults to the operator-editable table. Applicable to any future default-policy layer where the vendor owns the admin UI (embed settings, per-server metadata, etc.).

## Reference code

```php
// includes/AccessControl/TransportPermissionDefault.php — the core method
public function filter_default_capability( string $default, HttpRequestContext $context ): string {
    $route = ltrim( (string) $context->request->get_route(), '/' );
    if ( '' === $route ) {
        return $default;
    }

    $parts = explode( '/', $route, 2 );
    if ( count( $parts ) !== 2 || '' === $parts[0] || '' === $parts[1] ) {
        return $default;
    }

    // Memoized per-request; lookup MCPServer row by (namespace, route);
    // read wpb-ac rule for the resolved server_slug; return manage_options
    // when no rule exists, else return $default so the F015 tool-call gate
    // does the real per-rule enforcement.
    // [full implementation — see file for the 7-branch early-return chain]
}
```

Wired in `includes/Main.php::define_public_hooks()`:
```php
$transport_default = TransportPermissionDefault::instance();
$this->loader->add_filter(
    'mcp_adapter_default_transport_permission_user_capability',
    $transport_default,
    'filter_default_capability',
    10,
    2
);
```

Added: 2026-08-09 via branch `feat/042-default-manage-options-access-rule` (PR #71, force-push replacing an earlier DB-seeder attempt).
