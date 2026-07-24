# Planning: F030 Permission-Callback Wrapper Fix — Args Forwarding + WP_Error Preservation (Feature 033)

**Feature Branch**: `fix/f030-permission-callback-bypass`
**PR**: [#45](https://github.com/acrossai-co/acrossai-mcp-manager/pull/45)
**Status**: Implementation shipped; docs backfilled for spec-kit alignment
**Type**: Security fix (regression on F030)

---

## Problem statement

`AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor::inject_override` (shipped in F030) wraps every WordPress ability's `permission_callback` in a closure that fires at ability-call time and consults the six defensive layers of `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS`. Two mutually reinforcing bugs in that wrapper's fall-through path effectively neutralised every ability's permission check on the default MCP server:

1. **Closure signature dropped its arguments.** The wrapper was declared `static function () use ( $slug, $original )` — zero parameters. When WP core invoked the wrapped callback with the ability's input, those args were silently discarded. Downstream callbacks that read their input — most notably `Execute::check_permission` inspecting `$input['ability_name']` — received an empty array and returned `WP_Error('missing_ability_name')`.

2. **`call_original` cast the WP_Error return to boolean `true`.** The helper did `return (bool) call_user_func( $original );`. In PHP, casting any object (including `WP_Error`) to bool always yields `true`. The vendor's ToolsHandler in `mcp-adapter/vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:148` then read `if ( true !== $permission )` as "permission granted" and proceeded to `execute()`.

**Net impact**: any authenticated WordPress user (down to `subscriber`) could invoke any registered ability through the `mcp-adapter/execute-ability` meta-tool on the default MCP server, even when the admin had explicitly disabled the ability via the Abilities tab (`is_exposed = 0` row) and the ability itself declared `meta.mcp.public = false`.

## Reproduction (live-verified)

1. Fresh install with `mcp-adapter-default-server` seeded (`registered_from = 'plugin'`, `override_abilities_permission = 0`).
2. Sibling plugin `acrossai-abilities-manager` registers `acrossai-abilities-manager/site-title-get` with `meta.mcp.public = false`, `meta.mcp.type = 'tool'`.
3. Admin visits the Abilities tab for server 1, verifies `site-title-get` is unchecked. The DB reflects this: `wp_acrossai_mcp_server_abilities` has a row for `(server_id=1, ability_slug='acrossai-abilities-manager/site-title-get', is_exposed=0)`.
4. A subscriber-level user connects via MCP and issues:
   ```json
   {"jsonrpc":"2.0","method":"tools/call","id":17,
    "params":{"name":"mcp-adapter-execute-ability",
     "arguments":{"ability_name":"acrossai-abilities-manager/site-title-get","parameters":{}}}}
   ```
5. **Expected**: 403 with `acrossai_mcp_ability_not_exposed_for_server`.
   **Actual (pre-fix)**: 200 with the site title in the response body.

## Root cause trace

The request path is:

- `ToolsHandler::call_tool` invokes `$mcp_tool->check_permission( $args )`.
- `McpTool::check_permission` (line 355) delegates to `$this->ability->check_permissions( $args )` for ability-backed tools.
- `WP_Ability::check_permissions` calls the registered `permission_callback` with the args.
- The registered callback is the F030 wrapper closure. Since `override_abilities_permission = 0` on server 1, the wrapper should fall through to `call_original( $original )`, which invokes our replacement `Execute::check_permission`.
- **Bug 1**: The closure signature `function ()` accepted zero args, so `Execute::check_permission` received `$input = array()` (its default). It failed the `if ( '' === $ability_name )` guard and returned `new WP_Error( 'missing_ability_name', ... )`.
- **Bug 2**: `call_original` did `(bool) call_user_func( $original )`. The `WP_Error` object was cast to `true`, returned as the closure's result, propagated up through `WP_Ability::check_permissions` and `McpTool::check_permission` unchanged.
- `ToolsHandler::call_tool` line 148: `if ( true !== $permission )` — the check passed. Line 189: `$mcp_tool->execute( $args )` — with the real args — was invoked, executing the ability and returning the site title.

Confirmed by targeted `error_log()` instrumentation during the investigation session:

```
[MCP-DEBUG] Execute::check_permission ENTRY — ability_name= user_id=2
[MCP-DEBUG] Execute::check_permission RAW INPUT=[]
[MCP-DEBUG] Execute::execute ENTRY — ability_name=acrossai-abilities-manager/site-title-get RAW INPUT={"ability_name":"...","parameters":[]}
```

The empty `check_permission` input, followed by the fully-populated `execute` input, is the smoking gun: the args existed at the vendor boundary but were discarded on the way into the permission callback.

## Fix summary

Two changes in `includes/Abilities/PermissionOverrideProcessor.php`:

1. Closure declared as `static function ( ...$callback_args ) use ( $slug, $original )` and every `call_original(...)` invocation now forwards `$callback_args` as its second argument.
2. `call_original` signature changed to `call_original( $original, array $args = array() ): bool|WP_Error`. Uses `call_user_func_array`. Returns `WP_Error` unchanged; only scalar returns are coerced to bool.

Existing six-layer semantics are preserved; the fix only affects the fall-through path.

## Regression tests

Three new tests in `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php`:

- `test_closure_forwards_args_to_original_callback` — captures original's `$input`, asserts args match the wrapper's caller.
- `test_wp_error_from_original_is_preserved_not_coerced_to_true` — original returns `WP_Error`, asserts result `instanceof WP_Error`.
- `test_wrapper_preserves_role_gated_denials` — `@dataProvider` over subscriber / contributor / author / editor / administrator; original callback mirrors `Execute::check_permission`'s shape (`WP_Error` on empty input, `current_user_can('manage_options')` otherwise). Only administrator receives `true`.

## Follow-up (not this feature)

- **Filter-time eligibility gate** — reshape `inject_override` to only wrap abilities that could ever satisfy layers 1 + 4, eliminating the wrapper for the vast majority of abilities. Tracked as a separate GitHub issue.
- **Strict allowlist** — change `ExposureResolver::resolve` to treat "no row" as deny (currently falls back to `meta.mcp.public`). Separate concern; unaffected by this fix.

## References

- Vulnerable file: `includes/Abilities/PermissionOverrideProcessor.php`
- Vendor call site: `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:148`
- Vendor permission delegation: `vendor/wordpress/mcp-adapter/includes/Domain/Tools/McpTool.php:355`
- Replacement callback that returned the swallowed `WP_Error`: `includes/Abilities/Execute.php:36`
- Original F030 spec: `specs/030-per-server-permission-override/`
- PR: [#45](https://github.com/acrossai-co/acrossai-mcp-manager/pull/45)
