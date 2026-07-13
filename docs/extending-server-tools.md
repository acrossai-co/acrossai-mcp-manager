# Extending per-server MCP tool lists

**Introduced**: Feature 025 (2026-07-14)
**Companion**: `docs/extending-per-server-tabs.md` (Feature 019)

Feature 025 opens the per-server tool list to third-party plugins via two filter seams — one for the default server, one for plugin-created (database) servers. This document describes the runtime contract, the storage model behind the composed tool list, and three worked examples covering the common extension patterns.

## 1. Storage model

Each MCP server exposes two orthogonal tool storage layers on the plugin side:

- **Protocol tools** — the three MCP-adapter default tools (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) are enabled per-server via three `tinyint(1) NOT NULL DEFAULT 1` columns on `wp_acrossai_mcp_servers`: `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`. `1` = enabled, `0` = the operator explicitly removed it via the Tools tab.
- **Curated abilities** — any additional WordPress ability the operator picked in the Tools tab lives as a presence row in `wp_acrossai_mcp_server_tools` (Feature 020 storage).

At MCP-adapter server-registration time (`mcp_adapter_init` action), `ToolPolicy::compose_for_row( $row )` unions the two layers into a single deduped array of ability slugs. That array is what the filter sees. The column/row split is an internal implementation detail — filter callbacks receive one uniform tool list without any distinction.

## 2. Filter contract

### `acrossai_mcp_manager_server_tools` (plugin filter — database servers only)

```php
apply_filters(
    'acrossai_mcp_manager_server_tools',
    string[] $tools,
    \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server
): string[];
```

**Where it fires**: `\AcrossAI_MCP_Manager\Includes\MCP\Controller::register_database_servers()`, on the `mcp_adapter_init` action at priority 11 (immediately after the vendor's `DefaultServerFactory` at priority 10). Fires exactly once per enabled DB-registered server per adapter boot.

**Where it does NOT fire**:
- Not fired for the default server (`registered_from = 'plugin'`, slug `mcp-adapter-default-server`). Hook the vendor filter `mcp_adapter_default_server_config` for that path.
- Not fired for disabled server rows.
- Not fired when the MCP adapter package is absent.

**Arguments**:

- `$tools`: the composed union of enabled protocol columns (in `ToolPolicy::COLUMN_MAP` key order) and curated slugs (`MCPServerToolQuery::get_added_slugs()` insertion order), deduped and `array_values()`-normalized.
- `$server`: the BerlinDB `Row` object for the server being registered. Callbacks may gate on `$server->id`, `$server->server_slug`, `$server->server_name`, or read the enablement columns directly.

**Return contract**: array (or coercible). The plugin re-normalizes with `array_values( array_unique( array_map( 'strval', (array) $return ) ) )` before passing to `$adapter->create_server()`, so non-array / non-string / duplicate returns don't corrupt the adapter call. `null` / `false` degrade to `[]` — server registers with an empty tool list.

**Throw safety**: throws propagate. Standard WordPress filter behavior — companion authors own their throw safety.

**Confused-deputy caveat**: a callback that re-adds a protocol slug the operator has explicitly removed via the Tools tab (column value `0`) will silently override the operator's UI-facing decision. Filter authors SHOULD log or documentation-cite any such override so operators can trace unexpected tool advertisements back to the responsible plugin.

### `mcp_adapter_default_server_config` (vendor filter — default server only)

Consumed by the plugin's `Controller::filter_default_server_config()` at default priority 10. Hook this filter at priority > 10 to run AFTER the plugin's callback, or < 10 to run before (in which case the plugin's REPLACE step will overwrite your `tools` key).

Signature (vendor-owned): `apply_filters( 'mcp_adapter_default_server_config', array $config ): array`. See `vendor/wordpress/mcp-adapter/includes/Servers/DefaultServerFactory.php:88` for the source.

## 3. Two-hook model

| Server source | Row's `registered_from` | Filter to hook | Priority guidance |
|---|---|---|---|
| Default server | `'plugin'` (slug `mcp-adapter-default-server`) | `mcp_adapter_default_server_config` (vendor) | Priority `>10` to modify AFTER the plugin's composed tools land |
| Plugin-created / operator-created | `'database'` | `acrossai_mcp_manager_server_tools` (plugin) | Default `10` is fine; earlier priorities see the pre-normalize state |

A companion plugin that wants to touch every server hooks BOTH filters. The two seams never double-fire for the same server row.

## 4. Worked example 1 — Add a Notes ability to every server named "Marketing"

```php
<?php
add_filter(
    'acrossai_mcp_manager_server_tools',
    static function ( array $tools, $server ): array {
        if ( 'Marketing' === $server->server_name && ! in_array( 'my-plugin/notes', $tools, true ) ) {
            $tools[] = 'my-plugin/notes';
        }
        return $tools;
    },
    10,
    2
);
```

## 5. Worked example 2 — Strip execute-ability from audit-only database servers

```php
<?php
add_filter(
    'acrossai_mcp_manager_server_tools',
    static function ( array $tools, $server ): array {
        if ( str_contains( $server->server_slug, 'audit' ) ) {
            $tools = array_values( array_diff( $tools, array( 'mcp-adapter/execute-ability' ) ) );
        }
        return $tools;
    },
    10,
    2
);
```

## 6. Worked example 3 — Same idea for the default server via the vendor filter

```php
<?php
add_filter(
    'mcp_adapter_default_server_config',
    static function ( array $config ): array {
        if ( is_array( $config['tools'] ?? null ) ) {
            $config['tools'] = array_values( array_diff(
                $config['tools'],
                array( 'mcp-adapter/execute-ability' )
            ) );
        }
        return $config;
    },
    20 // priority > 10 to run AFTER the plugin's Controller::filter_default_server_config().
);
```

## 7. Throw safety note

Neither filter is wrapped in try/catch on the plugin side. This matches standard WordPress behavior: throws propagate up the call stack. If your callback may throw:

- Wrap the risky work in your own `try { ... } catch ( \Throwable $e ) { ... }` block.
- On catch, either return the input `$tools` (or `$config`) untouched, or return a safe default.
- Log via `error_log()` — do not swallow silently.

Example:

```php
add_filter( 'acrossai_mcp_manager_server_tools', static function ( array $tools, $server ) {
    try {
        return your_risky_transform( $tools, $server );
    } catch ( \Throwable $e ) {
        error_log( sprintf(
            '[my-plugin] server_tools filter for server_id=%d threw: %s',
            (int) $server->id,
            $e->getMessage()
        ) );
        return $tools; // safe fallback — leave the composed set unchanged.
    }
}, 10, 2 );
```
