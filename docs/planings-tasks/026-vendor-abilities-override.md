# 026 — Vendor abilities override (per-server filtering for `mcp-adapter/{discover,get-info,execute}-ability`)

**Type**: TEMPORARY module — sunset by [WordPress/mcp-adapter#243](https://github.com/WordPress/mcp-adapter/issues/243)
**Grep marker**: `ACROSSAI_MCP_MANAGER_VENDOR_OVERRIDE_243`
**Shipped alongside**: F026 (`026-abilities-into-tool-registration`), separate commit on the same branch

---

## Problem

The vendored `wordpress/mcp-adapter` package registers three default abilities on `wp_abilities_api_init` at priority 10 via `McpAdapter::register_default_abilities()`:

- `mcp-adapter/discover-abilities` — lists all globally-public abilities (`meta.mcp.public = true` AND `mcp.type = 'tool'`)
- `mcp-adapter/get-ability-info` — returns info on any globally-public ability
- `mcp-adapter/execute-ability` — executes any globally-public ability

All three are **server-blind**. When invoked via `POST /wp-json/mcp/{server-slug}/mcp`, they read the GLOBAL public set and ignore per-server visibility. That's the concern tracked upstream at mcp-adapter#243.

Meanwhile the plugin already has per-server ability visibility (F017 storage `wp_acrossai_mcp_server_abilities` + F026's `AbilityDiscovery::for_server( int, string )` composer). This module bridges the gap for the three vendor abilities.

## Design (intercept-only, no unregister)

Uses two existing vendor filter hooks that both carry `$mcp_server` context — no ability unregister, no vendor class copying, no schema-drift risk:

- **`mcp_adapter_pre_tool_call`** (`vendor/.../ToolsHandler.php:182`) at priority 40 — blocks `mcp-adapter/execute-ability` calls whose target isn't in the server's effective set.
- **`mcp_adapter_tool_call_result`** (`vendor/.../ToolsHandler.php:205`) at priority 40 — rewrites `mcp-adapter/discover-abilities` output to the per-server subset; replaces `mcp-adapter/get-ability-info` output with a `WP_Error` for hidden abilities (prevents metadata enumeration).

Priority 40 sits after F015 access-control (10), F017 exposure gate (20), F020 tool-curation gate (30) — inherits the deny-precedence chain from `DEC-F020-TOOL-ENFORCEMENT-PRIORITY`.

## Plugin-owned filter

```php
apply_filters(
    'acrossai_mcp_manager_vendor_override_effective_slugs',
    string[]    $slugs,        // default: AbilityDiscovery::for_server( $server_id, 'tool' )
    int         $server_id,
    string      $context,      // 'discover' | 'get_info' | 'execute'
    ?WP_Ability $target        // null for discover; the target ability for get_info/execute
): string[];
```

Companion plugins can `array_merge`/`array_diff` freely. Return is defensively re-normalized (deduped, string-normalized, zero-indexed) before use.

## Files added

- `includes/VendorOverrides/VendorAbilityInterceptor.php` — single class, two filter callbacks
- `tests/phpunit/VendorOverrides/VendorAbilityInterceptorTest.php` — 12 cases
- `docs/planings-tasks/026-vendor-abilities-override.md` — this doc

Plus two `add_filter` lines in `includes/Main.php` alongside the existing F017/F020 wire (find via `grep VENDOR_OVERRIDE_243 includes/Main.php`).

## How to remove when mcp-adapter#243 lands

```bash
# Confirm no external references remain (should be limited to the module + tests + main.php wires + this doc)
grep -r ACROSSAI_MCP_MANAGER_VENDOR_OVERRIDE_243 .

# Remove
rm -rf includes/VendorOverrides
rm -rf tests/phpunit/VendorOverrides
rm docs/planings-tasks/026-vendor-abilities-override.md

# Delete the two add_filter lines in includes/Main.php (bracketed by the VENDOR_OVERRIDE_243 comment)
```

Post-removal verification: `composer phpstan`, `composer phpcs`, `composer test` all clean.

## Out of scope

- No new schema
- No new REST routes or admin UI
- No changes to F017 storage / `ExposureResolver` / `AbilityExposureGate`
- No changes to F026 `AbilityDiscovery` / `ToolPolicy`
- No changes to any file under `vendor/`
- No `wp_unregister_ability` calls — vendor registration is untouched
