# Contract: `acrossai_mcp_manager_server_tools` filter

**Type**: WordPress filter (`apply_filters` / `add_filter`)
**Introduced**: Feature 025
**Stability**: `@since 0.0.1 (F025)` — semver-stable within the plugin's own versioning after 1.0.0.

## Signature

```php
apply_filters(
    'acrossai_mcp_manager_server_tools',
    string[] $tools,
    \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server
): string[];
```

## Where it fires

Inside `\AcrossAI_MCP_Manager\Includes\MCP\Controller::register_database_servers( \WP\MCP\Core\McpAdapter $adapter ): void`, on the `mcp_adapter_init` action at priority 11 (after the vendor's `DefaultServerFactory` at priority 10).

Fires **exactly once per enabled database-registered server per adapter boot** — a single REST request that reaches the adapter with N enabled database servers triggers N `apply_filters()` calls.

## Where it does NOT fire

- Not fired for the default server (`registered_from = 'plugin'`, slug `mcp-adapter-default-server`). Companion plugins targeting the default server MUST hook the vendor filter `mcp_adapter_default_server_config` instead — see the accompanying `filter_default_server_config()` callback (below).
- Not fired for disabled server rows.
- Not fired when the MCP adapter package (`\WP\MCP\Plugin`) is absent — the entire adapter path short-circuits at `Controller::initialize_adapter()`.

## Arguments

### `$tools` — `string[]`

The composed tool list the server will register with the adapter, computed by `ToolPolicy::compose_for_row( $server )`:

- Deduped, `array_values()`-normalized.
- Protocol slugs (per `ToolPolicy::COLUMN_MAP`) appear first, in map-key order, **but only** for columns whose row value is `1` (non-empty).
- Curated slugs (from `MCPServerToolQuery::get_added_slugs( $server->id )`) appear after.

Callbacks receive the FULL pre-filter list — there is no separate "protocol tools" vs. "curated tools" distinction visible to a filter callback. This is deliberate: the storage split is an internal implementation detail; the extension surface is a single uniform array.

### `$server` — `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row`

The BerlinDB Row object being registered. Public properties (see [data-model.md](../data-model.md) for the extended shape):

- `id: int` (cast to int in constructor)
- `server_name: string`
- `server_slug: string`
- `description: string`
- `is_enabled: int` (0 or 1)
- `registered_from: string` — always `'database'` on this callback (never `'plugin'`)
- `server_route_namespace: string`
- `server_route: string`
- `server_version: string`
- `tool_discover_abilities: int` (F025)
- `tool_get_ability_info: int` (F025)
- `tool_execute_ability: int` (F025)
- `created_at: string`

Callbacks MAY gate on any field. Reading `$server->tool_discover_abilities` etc. is legal but redundant — the composed `$tools` array already reflects the column state.

## Return contract

Return type: **array** (or coercible to one).

The plugin defensively re-normalizes the return before passing to `$adapter->create_server()`:

```php
$tools = apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $server );
$tools = array_values( array_unique( array_map( 'strval', (array) $tools ) ) );
```

Consequences of returning:

- **An array of strings**: honored verbatim (after dedup + `array_values`).
- **An array containing non-string entries**: entries `strval`'d, then deduped.
- **A non-array scalar** (`null`, `false`, `''`, `0`): coerced to `[]` via `(array) null` → `[]`; server registers with an empty tool list. `tools/list` returns an empty array.
- **A `WP_Error`**: NOT special-cased — treated as any other non-array value and coerced. Callbacks that want to abort should throw.
- **A `\Throwable`**: propagates. Standard WordPress filter behavior. Companion authors are responsible for their own throw safety.

## Interaction with the vendor filter

The default-server path uses the vendor filter `mcp_adapter_default_server_config` (declared at `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:88`). The plugin's callback:

```php
Controller::filter_default_server_config( array $config ): array
```

REPLACES `$config['tools']` with `ToolPolicy::compose_for_row( $default_server_row )` and returns. Signature comes from the vendor:

```php
/**
 * @param array $config {
 *     @type string   $server_id
 *     @type string   $server_route_namespace
 *     @type string   $server_route
 *     @type string   $server_name
 *     @type string   $server_description
 *     @type string   $server_version
 *     @type string[] $mcp_transports
 *     @type string   $error_handler
 *     @type string   $observability_handler
 *     @type string[] $tools
 *     @type string[] $resources
 *     @type string[] $prompts
 * }
 */
```

Defensive short-circuits (return `$config` untouched):

- `$config` not an array.
- `$config['tools']` not an array.
- Default server row cannot be located by slug (`DefaultServerSeeder::SLUG`) — unseeded install, unexpected.
- Composed set is empty — vendor's defaults win as the safer fallback.

## Composability

A companion plugin that wants to touch every server hooks both filters:

```php
add_filter(
    'acrossai_mcp_manager_server_tools',
    static function ( array $tools, Row $server ): array {
        // Database-server modifications.
        return $tools;
    },
    10,
    2
);

add_filter(
    'mcp_adapter_default_server_config',
    static function ( array $config ): array {
        // Default-server modifications. Runs AFTER Controller::filter_default_server_config()
        // if hooked at a later priority; before if earlier. Default priority 10 in both cases —
        // order depends on registration order.
        if ( is_array( $config['tools'] ?? null ) ) {
            $config['tools'] = array_values( array_diff( $config['tools'], [ 'mcp-adapter/execute-ability' ] ) );
        }
        return $config;
    },
    20
);
```

The two filters never double-fire for the same server row.

## Examples

### 1. Add a "Notes" ability to every database server named "Marketing"

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, Row $server ): array {
    if ( 'Marketing' === $server->server_name ) {
        $tools[] = 'my-plugin/notes';
    }
    return $tools;
}, 10, 2 );
```

### 2. Strip the execute-ability protocol tool from audit-only servers

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, Row $server ): array {
    if ( str_contains( $server->server_slug, 'audit' ) ) {
        $tools = array_values( array_diff( $tools, [ 'mcp-adapter/execute-ability' ] ) );
    }
    return $tools;
}, 10, 2 );
```

### 3. Fully replace the tool list for a specific server

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, Row $server ): array {
    if ( 'read-only-inspector' === $server->server_slug ) {
        return [ 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info' ];
    }
    return $tools;
}, 10, 2 );
```
