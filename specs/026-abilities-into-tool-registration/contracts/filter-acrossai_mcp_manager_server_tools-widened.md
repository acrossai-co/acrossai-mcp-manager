# Contract: `acrossai_mcp_manager_server_tools` filter (widened by F026)

**Type**: WordPress filter (`apply_filters` / `add_filter`)
**Introduced**: Feature 025 (F025).
**Amended**: Feature 026 (F026) — widens the pre-filter composed set from `(protocol + curated)` to `(protocol + curated + F017-effective abilities)`.
**Stability**: `@since 0.1.0 (F025)` — signature unchanged; strict-superset input change is backwards-compatible for all existing consumers.

## What DIDN'T change

- Filter name.
- Filter signature.
- Filter call site (still `Controller::register_database_servers()` at `includes/MCP/Controller.php:162`).
- Filter firing rules (once per enabled database server per `mcp_adapter_init` action).
- Return contract (defensive re-normalize at Controller.php:163 handles hostile returns).
- Interaction with the vendor `mcp_adapter_default_server_config` filter for the default server (still separate — no double-firing).

## What DID change

The **pre-filter composed set** `$tools` array now widens to include a third source: F017-effective abilities. Specifically:

**Before F026** (F025 shipping code):
```
$tools = ToolPolicy::compose_for_row( $server )
       = (enabled protocol columns) ∪ (curated slugs from wp_acrossai_mcp_server_tools)
```

**After F026** (F026 shipping code):
```
$tools = ToolPolicy::compose_effective_tools_for_row( $server )
       = (enabled protocol columns) ∪ (curated slugs from wp_acrossai_mcp_server_tools) ∪
         { $slug : $ability ∈ wp_get_abilities(),
                   $slug = $ability->get_name(),
                   ExposureResolver::resolve( $server->id, $slug, $ability->get_meta() ) = true }
```

The `ExposureResolver::resolve()` decision follows F017's canonical rule per `DEC-ABILITY-OVERRIDE-RESOLUTION`:
- If a row exists in `wp_acrossai_mcp_server_abilities` for `(server_id, ability_slug)`: `is_exposed` wins (cast to bool per B18 defense).
- Else: `meta.mcp.public` fallback (`! empty( $meta['mcp']['public'] )`).

Fail-open: when `! function_exists( 'wp_get_abilities' )`, the F017 pass is skipped and `$tools` equals the F025 output (no F017-effective additions).

## Signature (unchanged)

```php
apply_filters(
    'acrossai_mcp_manager_server_tools',
    string[] $tools,
    \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server
): string[];
```

## Where it fires

Inside `\AcrossAI_MCP_Manager\Includes\MCP\Controller::register_database_servers( \WP\MCP\Core\McpAdapter $adapter ): void`, on the `mcp_adapter_init` action at priority 11 (after the vendor's `DefaultServerFactory` at priority 10).

Fires exactly once per enabled database-registered server per adapter boot.

## Where it does NOT fire

Unchanged from F025:
- Not fired for the default server. Companion plugins targeting the default server MUST hook the vendor filter `mcp_adapter_default_server_config` instead.
- Not fired for disabled server rows.
- Not fired when the MCP adapter package (`\WP\MCP\Plugin`) is absent.

## Arguments

### `$tools` — `string[]` (post-F026: WIDER pre-filter set)

The composed tool list the server will register with the adapter, computed by `ToolPolicy::compose_effective_tools_for_row( $server )`:

- Deduped, `array_values()`-normalized.
- Contains three sources:
  1. **Protocol slugs** (per `ToolPolicy::COLUMN_MAP`) — appear first, in `COLUMN_MAP` iteration order, only for columns where the row value is `1`.
  2. **Curated slugs** (from `MCPServerToolQuery::get_added_slugs( $server->id )`) — appear next, in row-insertion order returned by the query.
  3. **F017-effective abilities** (new in F026) — appear last, in `wp_get_abilities()` iteration order. Each such slug is one where `ExposureResolver::resolve( $server->id, $slug, $meta )` returned true.

Callbacks receive the FULL pre-filter list. There is no separate "F017-effective abilities" vs. "F025 tools" distinction visible to a filter callback — the composition happens before `apply_filters()` fires.

**Order is stable but non-contractual.** Callbacks MUST NOT depend on the order of entries in `$tools`; they SHOULD treat it as an unordered set.

**Strict superset property.** Any slug that was in the pre-F026 `$tools` array is still in the post-F026 array. Companion plugins that only `array_diff` or `array_filter` continue to work correctly on the wider input.

### `$server` — `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row` (unchanged)

The BerlinDB Row object being registered. Public properties (see F025's `data-model.md` for the extended shape):

- `id: int`
- `server_name: string`
- `server_slug: string`
- `description: string`
- `is_enabled: int` (0 or 1)
- `registered_from: string` — always `'database'` on this callback.
- `server_route_namespace: string`
- `server_route: string`
- `server_version: string`
- `tool_discover_abilities: int` (F025)
- `tool_get_ability_info: int` (F025)
- `tool_execute_ability: int` (F025)
- `created_at: string`

Callbacks MAY gate on any field. Reading `$server->tool_discover_abilities` etc. is legal but redundant — the composed `$tools` array already reflects the column state (and the F017 override state).

## Return contract (unchanged from F025)

Return type: **array** (or coercible to one).

The plugin defensively re-normalizes the return before passing to `$adapter->create_server()`:

```php
$tools = apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $server );
$tools = array_values( array_unique( array_map( 'strval', (array) $tools ) ) );
```

Consequences of returning:

- **An array of strings**: honored verbatim (after dedup + `array_values`).
- **An array containing non-string entries**: entries `strval`'d, then deduped.
- **A non-array scalar** (`null`, `false`, `''`, `0`): coerced to `[]` via `(array) null` → `[]`.
- **A `WP_Error`**: NOT special-cased — treated as any other non-array value and coerced.
- **A `\Throwable`**: propagates. Standard WordPress filter behavior.

## Interaction with the vendor filter (unchanged from F025)

The default-server path uses the vendor filter `mcp_adapter_default_server_config`. The plugin's callback `Controller::filter_default_server_config( $config )` REPLACES `$config['tools']` with `ToolPolicy::compose_effective_tools_for_row( $default_server_row )` — the same widened composer as the database-server path. The vendor filter's signature is unchanged.

Companion plugins targeting the default server hook `mcp_adapter_default_server_config` directly. Companion plugins targeting database servers hook `acrossai_mcp_manager_server_tools`. Companion plugins that want to touch every server hook BOTH.

## Backwards compatibility guarantees

**For companion-plugin authors who hooked the F025 filter before F026:**

1. **Your callback's signature does not need to change.** Still `(string[] $tools, MCPServer\Row $server)`.
2. **Your callback continues to work with unmodified logic.** The strict-superset input property guarantees no slug you saw pre-F026 disappears post-F026.
3. **New slugs may appear.** If the operator has enabled abilities via the Abilities tab OR the ability has `mcp.public = true`, those slugs are now in `$tools` before your callback runs. If your callback naively appends slugs, `array_unique` handles dedup. If your callback validates against an allowlist, your allowlist may need extending — but this is a UX choice, not a compat break.
4. **Companion plugins that hard-coded expected input length would break** — but no such callback is expected to exist since F025 shipped only recently and length was never part of the contract.

## Interaction with the Abilities tab (new — F026 documentation)

Toggling an ability's exposure on the Abilities tab (`?tab=abilities`) writes/updates a row in `wp_acrossai_mcp_server_abilities`. `ExposureResolver::resolve()` reads that row on the next request. If the ability was previously visible (`meta.mcp.public = true` and no override, or override `is_exposed = 1`) and is now hidden (`is_exposed = 0`), it disappears from the next `tools/list` response.

This is by design — F026 makes the Abilities tab a live surface for what advertises in `tools/list`, complementing F017's existing call-time gate (which continues to enforce at `mcp_adapter_pre_tool_call` priority 20).

**Curated + F017-hidden edge case**: if an ability is a curated pick (F020 presence row) AND its F017 effective exposure is false (`is_exposed = 0` row), the ability appears in `tools/list` (because F020 curation wins at advertisement time) but `tools/call` returns 403 `acrossai_mcp_ability_not_exposed` (F017 gate wins at call-time). This inconsistency is documented as accepted in spec §Edge Cases §3.

## Examples

### 1. Existing F025 callback continues to work unchanged

```php
// Written before F026 — no changes needed after F026.
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, Row $server ): array {
    if ( 'Marketing' === $server->server_name && ! in_array( 'my-plugin/notes', $tools, true ) ) {
        $tools[] = 'my-plugin/notes';
    }
    return $tools;
}, 10, 2 );
```

### 2. New F026-aware callback that specifically strips F017-effective abilities

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, Row $server ): array {
    if ( str_contains( $server->server_slug, 'read-only' ) ) {
        // For read-only servers, strip everything except protocol slugs and explicit F020 curated picks.
        $curated = \AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query::instance()->get_added_slugs( (int) $server->id );
        $allowlist = array_merge(
            \AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::PROTOCOL_TOOLS,
            $curated
        );
        return array_values( array_intersect( $tools, $allowlist ) );
    }
    return $tools;
}, 10, 2 );
```

### 3. Reset the composed set to protocol-only (unchanged from F025 pattern)

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, Row $server ): array {
    if ( 'protocol-only-server' === $server->server_slug ) {
        return \AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::PROTOCOL_TOOLS;
    }
    return $tools;
}, 10, 2 );
```

## Grep audits (post-F026 — MUST PASS)

- `grep -n "apply_filters( 'acrossai_mcp_manager_server_tools'" includes/` — exactly **1** match (inside `Controller::register_database_servers()`).
- `grep -n "compose_effective_tools_for_row" includes/` — exactly **3** matches (1 definition in `ToolPolicy.php`, 2 call sites in `Controller.php`).
- `grep -n "compose_for_row" includes/` — exactly **3** matches (1 definition in `ToolPolicy.php`, 1 REST GET in `ToolsController.php`, 1 internal seed call inside `compose_effective_tools_for_row()`).
