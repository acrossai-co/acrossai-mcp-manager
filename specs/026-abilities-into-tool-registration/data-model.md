# Phase 1 — Data Model

**Feature**: 026-abilities-into-tool-registration

## Runtime primary object

**Composed tool list (F026-widened)** — the union of three sources for one server. Not stored in a single field; materialized on demand by `ToolPolicy::compose_effective_tools_for_row( MCPServer\Row $row ): string[]`.

Consumers:
- `Controller::register_database_servers()` at `includes/MCP/Controller.php:142` — passes as the 10th argument to `\WP\MCP\Core\McpAdapter::create_server()`.
- `Controller::filter_default_server_config()` at `includes/MCP/Controller.php:247` — writes into `$config['tools']` before returning.

Non-consumers (deliberately preserved on the old F025 `compose_for_row()`):
- `ToolsController::get_tools()` at `includes/REST/ToolsController.php:201` — REST GET response's `tools` array stays scoped to protocol + curated only.

Shape: `array<int, string>` — deduped, `array_values()`-normalized, `strval`'d entries.

**Composition order (stable, non-contractual)**:
1. Protocol slugs from `ToolPolicy::COLUMN_MAP` (F025) — only for columns where the row value is `1`. Iteration order matches `COLUMN_MAP` array-key order (`tool_discover_abilities` → `tool_get_ability_info` → `tool_execute_ability`).
2. Curated slugs from `MCPServerToolQuery::instance()->get_added_slugs( $row->id )` (F020) — insertion order returned by the query.
3. F017-effective abilities — every slug from `\wp_get_abilities()` iteration where `ExposureResolver::resolve( $row->id, $slug, $meta )` returns true. Order matches `wp_get_abilities()` iteration order (usually registration order).

Downstream dedup via `array_values( array_unique( array_map( 'strval', $tools ) ) )` — a slug that appears as both a curated pick and an F017-effective ability collapses to one entry (first occurrence wins per `array_unique` semantics).

## Storage layers (all unchanged)

**Zero schema deltas.** F026 introduces no migrations, no `ALTER TABLE`, no new columns.

| Layer | Table | Source | F026 access |
|---|---|---|---|
| Protocol tool enablement | `wp_acrossai_mcp_servers` (F025 columns: `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`) | F025 (see `specs/025-server-tools-registration-hooks/data-model.md`) | READ via `Row->{$column}` inside `compose_for_row()` (delegated by `compose_effective_tools_for_row()`). |
| Curated ability picks | `wp_acrossai_mcp_server_tools` (F020 presence rows) | F020 | READ via `MCPServerToolQuery::instance()->get_added_slugs()` inside `compose_for_row()` (delegated). |
| Per-server ability visibility overrides | `wp_acrossai_mcp_server_abilities` (F017: `is_exposed` tinyint(1)) | F017 (see `specs/017-per-server-ability-selection/data-model.md`) | READ via `MCPServerAbility\Query::instance()->query()` inside `ExposureResolver::resolve()` (canonical resolver, called by F026). |

**No write paths.** F026 does not INSERT, UPDATE, or DELETE any row in any table. All access is read-only.

## Row shape delta — `AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row`

**Zero changes.** The three F025 tinyint properties + F011 baseline properties are all unchanged. F026 reads them via `compose_for_row()` (delegated).

## Method delta — `AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy`

**One new public static method.** No changes to existing methods, constants, or file structure.

### `compose_effective_tools_for_row( Row $row ): array`

```php
/**
 * Compose the effective tool list for a server row, INCLUDING F017
 * per-server ability exposure state.
 *
 * Superset of `compose_for_row()`:
 *   1. Enabled protocol columns (F025) — three tool_* boolean columns.
 *   2. Curated ability slugs (F020) — presence rows in wp_acrossai_mcp_server_tools.
 *   3. F017-effective abilities — every ability where
 *      ExposureResolver::resolve( $server_id, $slug, $meta ) === true.
 *
 * @since 0.1.0 (Feature 026)
 * @param Row $row The server row.
 * @return string[] The composed tool list including F017-effective abilities.
 */
public static function compose_effective_tools_for_row( Row $row ): array {
    $tools = self::compose_for_row( $row );

    if ( ! function_exists( 'wp_get_abilities' ) ) {
        return $tools; // Fail-open — Abilities API not bootstrapped.
    }

    $server_id = (int) $row->id;
    foreach ( \wp_get_abilities() as $ability ) {
        $slug = (string) $ability->get_name();
        if ( '' === $slug ) {
            continue;
        }
        $meta = $ability->get_meta();
        if ( ExposureResolver::resolve( $server_id, $slug, is_array( $meta ) ? $meta : array() ) ) {
            $tools[] = $slug;
        }
    }

    return array_values( array_unique( array_map( 'strval', $tools ) ) );
}
```

Required imports:
- `use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;` (added alongside the existing `MCPServerTool\Query` import).

## Contract with F017 (`ExposureResolver::resolve()`)

F026 relies on F017's canonical single-resolver contract per `DEC-ABILITY-OVERRIDE-RESOLUTION`:

- Precedence: row-in-`wp_acrossai_mcp_server_abilities` beats `meta.mcp.public` fallback.
- Return type: `bool`.
- Caching: per-request static cache keyed by `"{$server_id}:{$ability_slug}"`. First call for a pair costs O(1 BerlinDB query); subsequent calls are O(1) in-memory.
- Concurrency: within a single request, the cache is not invalidated. Across requests, the cache is naturally reset (new PHP process → fresh static state).
- Fail modes: `ExposureResolver::resolve()` does not throw. If the BerlinDB query returns empty, the fallback `! empty( $meta['mcp']['public'] )` evaluates cleanly on any array (including the empty-array default F026 passes when `get_meta()` returns non-array).

F026's caller-side responsibilities:
- Skip empty ability slugs (`'' === $slug` guard).
- Pass an array to `resolve()`'s `$meta` param — coerce `get_meta()` return via `is_array( $meta ) ? $meta : array()`.
- Do NOT swallow the resolver's boolean return — every `true` result appends the slug.

## Contract with F025 (`ToolPolicy::compose_for_row()`)

F026's new method **calls** `compose_for_row()` internally to seed the union. It does NOT duplicate F025's logic.

- F025's `compose_for_row()` returns `array<int, string>` deduped and normalized.
- F026 appends to the returned array and re-normalizes at the end.
- If F025 ever changes `compose_for_row()`'s output shape, F026 automatically inherits the change — no dual maintenance required.

## Interaction contract (companion plugins hooking `acrossai_mcp_manager_server_tools`)

Before F026, callbacks received `$tools = (protocol columns) ∪ (curated presence rows)`.

After F026, callbacks receive `$tools = (protocol columns) ∪ (curated presence rows) ∪ (F017-effective abilities)`.

**Strict superset** — any slug that was in the pre-F026 array is still in the post-F026 array. New slugs may appear if the operator has enabled abilities via the Abilities tab OR if the ability has `mcp.public = true` as its default.

Existing callbacks that only `array_diff` or `array_filter` continue to work correctly on the wider input. Callbacks that `array_merge` protocol slugs onto the input see the same protocol slugs (dedup handles duplicates). Callbacks that hard-coded expected input length would break — but no such callback is expected to exist since F025 shipped only recently.

## Order-of-operations at server registration time

**Default server** (once per `mcp_adapter_init` firing):
1. Vendor `DefaultServerFactory` runs on `mcp_adapter_init` priority 10.
2. Vendor fires `apply_filters( 'mcp_adapter_default_server_config', $config )`.
3. Plugin's `Controller::filter_default_server_config()` callback runs — looks up default server row by slug, calls `ToolPolicy::compose_effective_tools_for_row( $rows[0] )`, replaces `$config['tools']` with the result. **Empty-set fallback semantic shift (SEC-026-v2-1 / SEC-TASKS-026-2)**: the `if ( empty( $tools ) ) { return $config; }` guard preserved from F025 now fires only when protocol + curated + F017-effective are all empty. On any install with at least one `mcp.public = true` ability (or one `is_exposed = 1` override), the fallback becomes unreachable. Deliberate — reflects the widened source set; the fallback's original purpose (return vendor defaults when the operator explicitly emptied every source) is preserved.
4. Vendor `wp_parse_args( $config, $defaults )` merges.
5. Vendor calls `create_server()` with the composed set.

**Database servers** (per server per `mcp_adapter_init` firing):
1. Plugin's `Controller::register_database_servers()` runs on `mcp_adapter_init` priority 11.
2. For each enabled `registered_from = 'database'` row: `$tools = ToolPolicy::compose_effective_tools_for_row( $server );`.
3. `apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $server )` — companion plugins get widened input.
4. `array_values( array_unique( array_map( 'strval', (array) $tools ) ) )` — defensive re-normalize.
5. `$adapter->create_server( ..., $tools, ... )` — 10th argument.

Both paths call the new method exactly once per server per request.

## Test data model (PHPUnit fixtures)

Extended test files reuse F025's fixtures:
- Existing `setUp()` seeds a server row via `MCPServerQuery::instance()->add_item()`.
- Existing `truncate_tables()` in `tearDown()` clears `wp_acrossai_mcp_servers` + `wp_acrossai_mcp_server_tools`.

New F026 fixture requirements:
- Add `TRUNCATE wp_acrossai_mcp_server_abilities` to `truncate_tables()` (F017 storage is cleaned between tests to prevent cross-test cache pollution).
- Add `ExposureResolver::_reset_cache_for_tests();` to `setUp()` after truncation (resets F017's per-request static cache so each test sees fresh state).
- Test helper to register a scratch ability: `function register_scratch_ability( string $slug, bool $mcp_public ): void { \wp_register_ability( $slug, [ 'label' => ucfirst( $slug ), 'meta' => [ 'mcp' => [ 'public' => $mcp_public ] ] ] ); }` — skipped via `markTestSkipped()` if `! function_exists( 'wp_register_ability' )`.
- Test helper to write an F017 override row: `MCPServerAbility\Query::instance()->upsert( $server_id, $slug, $is_exposed );`.

The four new `test_compose_effective_*` cases and one new `test_register_database_servers_produces_f017_widened_composed_set()` case exercise the matrix in spec §User Story 1 §Acceptance Scenarios.
