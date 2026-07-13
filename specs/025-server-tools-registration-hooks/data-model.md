# Phase 1 — Data Model

**Feature**: 025-server-tools-registration-hooks

## Runtime primary object

**Composed tool list** — the union of the enabled built-in defaults and the curated picks for one server. Not stored anywhere in a single field; materialized on demand by `ToolPolicy::compose_for_row( MCPServer\Row $row ): string[]`. This is the object that:

- `Controller::register_database_servers()` passes to `\WP\MCP\Core\McpAdapter::create_server()` as the 10th positional argument.
- `Controller::filter_default_server_config()` writes into `$config['tools']` before returning.
- `ToolsController::get_tools()` returns as the response's `tools` array.

Shape: `array<int, string>` — deduped, `array_values()`-ed, `strval`'d entries. Order stability: protocol slugs appear in `ToolPolicy::COLUMN_MAP` key order; curated slugs follow in insertion order returned by `MCPServerToolQuery::get_added_slugs()`.

## Storage layer 1 — `wp_acrossai_mcp_servers` schema delta

**Table**: `{wpdb->prefix}acrossai_mcp_servers` (existing since Feature 011).

**Schema class**: `AcrossAI_MCP_Manager\Includes\Database\MCPServer\Schema`.

**BerlinDB version bump**: `MCPServer\Table::$version` `1.0.0` → `1.1.0`. Triggers `maybe_upgrade()` via `dbDelta` on next request-time boot. Wired via existing `Main::bootstrap_database_tables()`.

**Delta** — three new columns appended after the existing `server_version` column, before `created_at`:

| Column | Type | Nullability | Default | Purpose |
|---|---|---|---|---|
| `tool_discover_abilities` | `TINYINT(1)` | NOT NULL | `1` | Enablement flag for MCP protocol tool `mcp-adapter/discover-abilities` |
| `tool_get_ability_info` | `TINYINT(1)` | NOT NULL | `1` | Enablement flag for MCP protocol tool `mcp-adapter/get-ability-info` |
| `tool_execute_ability` | `TINYINT(1)` | NOT NULL | `1` | Enablement flag for MCP protocol tool `mcp-adapter/execute-ability` |

**BerlinDB `$columns` entries** (append to the existing array):

```php
array(
    'name'    => 'tool_discover_abilities',
    'type'    => 'tinyint',
    'length'  => '1',
    'default' => 1,
),
array(
    'name'    => 'tool_get_ability_info',
    'type'    => 'tinyint',
    'length'  => '1',
    'default' => 1,
),
array(
    'name'    => 'tool_execute_ability',
    'type'    => 'tinyint',
    'length'  => '1',
    'default' => 1,
),
```

**Indexes**: no change. The three new columns are per-row scalar flags — no index needed.

**Migration behavior on ALTER**: MySQL's `ADD COLUMN ... DEFAULT 1` populates every existing row with `1` during the schema update — no separate backfill helper required. This is the sole backwards-compat mechanism.

## Storage layer 2 — `wp_acrossai_mcp_server_tools` schema delta

**Zero-delta**. Feature 020's presence-based storage is preserved verbatim. F025 does not touch:

- The table schema.
- `MCPServerToolQuery::instance()` public API (`get_added_slugs()`, `replace_set()`, `delete_items_for_server()`, cascade hook).
- The `mcp_server_deleted` cascade wiring.

## Row shape delta — `AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row`

Three new public int properties, each defaulting to `1`:

```php
/** @var int */ public $tool_discover_abilities = 1;
/** @var int */ public $tool_get_ability_info   = 1;
/** @var int */ public $tool_execute_ability    = 1;
```

Constructor MUST cast each to `(int)` before use, per bug pattern `B18` (`$wpdb` returns TINYINT as string):

```php
$this->tool_discover_abilities = (int) $this->tool_discover_abilities;
$this->tool_get_ability_info   = (int) $this->tool_get_ability_info;
$this->tool_execute_ability    = (int) $this->tool_execute_ability;
```

`to_array()` MUST include the three new keys with their int values.

## New service — `AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy`

**Type**: stateless helper class (A11 pure-service exemption — no singleton, no constructor state).

**Public constants**:

```php
public const PROTOCOL_TOOLS = array(
    'mcp-adapter/discover-abilities',
    'mcp-adapter/get-ability-info',
    'mcp-adapter/execute-ability',
);

public const COLUMN_MAP = array(
    'tool_discover_abilities' => 'mcp-adapter/discover-abilities',
    'tool_get_ability_info'   => 'mcp-adapter/get-ability-info',
    'tool_execute_ability'    => 'mcp-adapter/execute-ability',
);
```

**Public static methods**:

### `compose_for_row( MCPServer\Row $row ): array`

Returns the composed tool list for one server row.

Behavior:
1. Iterate `self::COLUMN_MAP`. For each `$column => $slug`, include `$slug` in the accumulator iff `! empty( $row->$column )` (defensive int check per B18).
2. Append `MCPServerToolQuery::instance()->get_added_slugs( (int) $row->id )` to the accumulator.
3. Return `array_values( array_unique( array_map( 'strval', $accumulator ) ) )`.

Return: `string[]` — deduped, `array_values()`-normalized, protocol slugs first, curated after.

### `split_payload( array $tools ): array`

Splits a POST body's `tools` array into the two storage layers.

Behavior:
1. Cast every entry to string, dedupe, filter empty.
2. Build `$columns` by iterating `self::COLUMN_MAP`: for each column, `$columns[$column] = in_array( $slug, $normalized, true ) ? 1 : 0`.
3. Build `$curated` = `array_values( array_diff( $normalized, self::PROTOCOL_TOOLS ) )`.
4. Return `[ 'columns' => $columns, 'curated' => $curated ]`.

Return shape:

```php
array{
    columns: array{
        tool_discover_abilities: 0|1,
        tool_get_ability_info: 0|1,
        tool_execute_ability: 0|1,
    },
    curated: string[],
}
```

## Two-write POST path — accepted race

`ToolsController::post_tools()` writes both layers sequentially:

1. `MCPServerQuery::instance()->update_item( $server_id, $columns )` — one UPDATE, three column values.
2. `MCPServerToolQuery::instance()->replace_set( $server_id, $curated )` — F020's transactional path (`START TRANSACTION` + `SELECT ... FOR UPDATE` on the tools table).

**Race window**: two concurrent saves on the same server may leave the columns from writer A and the curated rows from writer B. The window is small; the Tools tab is single-operator in practice; the operator would see the divergence on the next page load and can Reset to a known-good state. Documented in code as a known accepted race. Not remediated in this feature.

If a future feature needs a stricter guarantee, wrap both writes in an explicit `START TRANSACTION` at the controller layer (out of scope for F025).

## Observability event

Existing `acrossai_mcp_tools_changed` action reused for column flips (per FR-016). Firing rules on POST save:

- For each column whose new value differs from its pre-save value: do one `do_action( 'acrossai_mcp_tools_changed', [ 'server_id' => $server_id, 'ability_slug' => COLUMN_MAP[column], 'operation' => $newValue === 1 ? 'added' : 'removed' ] )`.
- Curated-side flips continue firing per F020's `MCPServerToolQuery::replace_set()`'s existing per-slug loop.

Ordering: fire all column bullets first (in `COLUMN_MAP` order), then let `replace_set()` fire its own bullets in its return-diff order. Per-bullet try/catch inherited from F020 keeps observer errors from bubbling to the REST response.

## Compatibility

- Existing installs on upgrade: all three columns default to `1` on the ALTER; every existing server retains its pre-upgrade advertised tool set. Per FR-011 + SC-003.
- Existing REST clients: request/response shape on the wire is unchanged. Per FR-012 + FR-013.
- Existing `ToolExposureGate::EXCLUDED_SLUGS` bypass: preserved as a belt-and-braces safety net for cached AI clients — vestigial but harmless (adapter refuses unregistered tools anyway).
