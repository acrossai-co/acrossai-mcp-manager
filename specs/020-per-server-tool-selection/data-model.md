# Phase 1 — Data Model

Feature 020 introduces exactly one new persistence surface: the `MCPServerTool` BerlinDB module. Every other data source it reads (MCP servers, WordPress abilities) is pre-existing.

---

## Entity: `MCPServerTool`

**Purpose**: Persist per-server, presence-based tool selections. A row for `(server_id, ability_slug)` **is** the "added as tool" flag. Absence of a row means "not added". No third state.

**Storage**: BerlinDB v3 (`\BerlinDB\Database\Kern\Table` / `Schema` / `Query` / `Row`), consistent with F011 (four modules) and F017 (`MCPServerAbility`).

### Columns

| Column        | Type          | Nullable | Default             | Notes                                                                                                                                                        |
|---------------|---------------|----------|---------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `id`          | `bigint(20)` unsigned auto_increment | NO   | —                   | Primary key. Sortable. Populated by BerlinDB `add_item()`.                                                                                                    |
| `server_id`   | `bigint(20)` unsigned                 | NO   | `0`                 | Foreign reference to `wp_acrossai_mcp_servers.id`. Sortable. No DB-level FK (BerlinDB doesn't emit them); referential integrity via FR-026 cleanup hook.       |
| `ability_slug`| `varchar(191)`                        | NO   | `''`                | Namespaced WordPress ability slug (e.g., `acrossai-core-abilities/create-post`). Searchable. Length 191 fits InnoDB utf8mb4 767-byte composite-key limit.      |
| `created_at`  | `datetime`                            | NO   | `CURRENT_TIMESTAMP` | Insert timestamp. Sortable + date_query.                                                                                                                      |
| `updated_at`  | `datetime`                            | NO   | `CURRENT_TIMESTAMP` | Modify timestamp. Sortable + date_query. **BerlinDB flag: `'modified' => true`** (NOT `'date_updated'` — see B21). Auto-stamps on `update_item()`.             |

### Indexes

| Name              | Type    | Columns                         | Purpose                                                                                                                                     |
|-------------------|---------|---------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------|
| `primary`         | PRIMARY | `id`                            | Row identity.                                                                                                                               |
| `server_ability`  | UNIQUE  | `server_id, ability_slug`       | **Correctness invariant** — enforces "at most one row per (server, ability) pair" at the DB level. Prevents duplicate rows under race.       |
| `server_id`       | KEY     | `server_id`                     | Fast lookup of "all tools for a server" — the hot query path (`Query::get_added_slugs()`).                                                   |

### Table metadata

- **Name**: `{wpdb->prefix}acrossai_mcp_server_tools`
- **`db_version_key`**: `acrossai_mcp_server_tools_db_version`
- **`$version`**: `1.0.0`
- **`$global`**: `false` (per-site — multisite installs get one table per site)
- **Phantom-version self-heal**: `Table::maybe_upgrade()` override checks `! $this->exists()` and `delete_option( $db_version_key )` before delegating to `parent::maybe_upgrade()`. Silent per F011 Clarification Q1 (no error_log, no admin notice, no transient). See DEC-BERLINDB-TABLE-REQUEST-BOOT.

### Query API

**Inherited from `\BerlinDB\Database\Kern\Query`** (unchanged public surface):

- `query( array $args ): Row[]`
- `add_item( array $data ): int|false`
- `update_item( int $id, array $data ): bool`
- `delete_item( int $id ): bool`
- `get_item( int $id ): Row|false`

**Bespoke helpers added on top**:

- `instance(): self` — singleton (private constructor).
- `get_added_slugs( int $server_id ): string[]` — returns the ordered list of ability_slugs currently added for the server. Wraps `query()` with `'number' => 0` (unlimited).
- `replace_set( int $server_id, array $desired_slugs ): array{added: string[], removed: string[]}` — the transactional Save operation. **MUST be wrapped in an explicit DB transaction with an exclusive row-range lock** (FR-030) to prevent concurrent-editor race producing a set-union superset final state:
  1. Normalize `$desired_slugs` up-front (outside the transaction — pure function): `strval` → `array_filter( strlen )` → `array_unique` → reindex. Collapses duplicates, drops empty strings.
  2. `$wpdb->query( 'START TRANSACTION' );`
  3. In a `try` block:
     - **Acquire exclusive row-range lock** on all rows for this server_id:
       ```php
       $wpdb->query( $wpdb->prepare(
           "SELECT id FROM %i WHERE server_id = %d FOR UPDATE",
           $wpdb->prefix . 'acrossai_mcp_server_tools',
           $server_id
       ) );
       ```
       Overlapping transactions on the same `server_id` block here until the earlier one commits. Overlapping transactions on DIFFERENT `server_id` values proceed independently (row-range lock, not table lock).
     - Fetch current set via `get_added_slugs()`. After the `FOR UPDATE` acquired the lock, this snapshot is guaranteed to include any writes committed by the previous serialized transaction.
     - Compute diff: `$added = desired \ current`; `$removed = current \ desired`.
     - Apply inserts (`add_item`) for each `$added` slug.
     - Apply deletes (`delete_item`) for each `$removed` slug (looked up by (`server_id`, `ability_slug`) pair).
     - `$wpdb->query( 'COMMIT' );`
     - Return `[ 'added' => [...], 'removed' => [...] ]` for the controller to fire the `acrossai_mcp_tools_changed` action from.
  4. `catch ( \Throwable $e )`: `$wpdb->query( 'ROLLBACK' );` then `throw $e;` — the controller catches, `error_log`'s the specific exception server-side, and returns a **generic HTTP 500** to the client with no exception detail leakage (see SEC-020-010 remediation in `contracts/rest-api.md §Route 2 §Response — 500`).

  **Concurrency contract**: Two overlapping POSTs to the same `server_id` MUST produce a deterministic last-committer-wins final state — the DB value after both commits MUST equal exactly the second-committing request's desired set, never a set-union superset. Verified by SC-011. Lock-wait timeout on the second transaction (extreme contention scenario) MUST surface as HTTP 500 with the generic response body; the client can retry. Documented in `contracts/rest-api.md`.

- `delete_items_for_server( int $server_id ): int` — bulk-delete helper called by the FR-026 cascade cleanup. **Uses a single `$wpdb->delete()` statement with a `WHERE server_id = %d` clause**, NOT a per-row `delete_item()` loop (SEC-020-011 remediation). One SQL round-trip per server deletion regardless of the row count. Rationale: the cascade path fires on the `mcp_server_deleted` action, which BerlinDB fires AFTER the server row is deleted — no cache-invalidation ordering concern applies to child rows because the parent is already gone. Per-item cache entries for the deleted tool rows can be flushed in one call to `wp_cache_flush_group( 'acrossai_mcp_server_tool' )` after the bulk delete.

  Shape:

  ```php
  public function delete_items_for_server( int $server_id ): int {
      global $wpdb;
      // BerlinDB v3: table name is $this->apply_prefix( $this->table_name ) with $wpdb->prefix.
      $table = $wpdb->prefix . 'acrossai_mcp_server_tools';
      $count = $wpdb->delete(
          $table,
          array( 'server_id' => $server_id ),
          array( '%d' )
      );
      wp_cache_flush_group( 'acrossai_mcp_server_tool' );
      return (int) ( false === $count ? 0 : $count );
  }
  ```

  NOT wrapped in a transaction — deletion is idempotent (missing rows are a no-op) and does not require snapshot isolation. Bulk-server-delete of N servers × M tools is now `N` single DELETE statements instead of `N × M` round-trips.

### Row shape (`\BerlinDB\Database\Kern\Row` subclass)

Public properties matching schema columns: `int $id`, `int $server_id`, `string $ability_slug`, `string $created_at`, `string $updated_at`.

**Note on B18 (tinyint-as-string)**: not applicable to this schema — no tinyint columns exist. No boolean flag = no B18 hazard.

**Helper**: `to_array(): array` returns an associative array with `id` and `server_id` cast to `int`. Used by tests + potential future REST expansion; NOT used by the current F020 REST controller (which returns only `tools: string[]`, no row shape leakage).

---

## Referenced Entities (pre-existing, unchanged)

### `MCPServer` (Table: `wp_acrossai_mcp_servers`)

Read-only reference for F020. The `server_id` foreign-key reference points here. F026 lookup performed at REST layer to reject unknown `server_id` values with 404. No new columns, no new indexes.

**Deletion hook**: F020 consumes the **BerlinDB-native `mcp_server_deleted` action** fired by `MCPServer\Query::delete_item()` — see `vendor/berlindb/core/src/Database/Kern/Query.php:2807-2823`. The action name is derived from BerlinDB's `apply_prefix( item_name . '_deleted' )` — `MCPServer\Query` has `$item_name = 'mcp_server'` and no `$prefix`, so the concrete action is literal `mcp_server_deleted`.

Signature: `do_action( 'mcp_server_deleted', int $item_id, bool $result )`. F020's callback receives `$item_id` (the deleted server's ID) and `$result` (whether the DB delete succeeded). Callback MUST:

- No-op if `$result` is `false` — a failed server delete MUST NOT trigger cascade cleanup.
- Call `MCPServerToolQuery::instance()->delete_items_for_server( $item_id )` when `$result` is `true`.

Both server-deletion code paths route through `MCPServer\Query::delete_item()`:

- Single-row delete: `admin/Partials/Settings.php:129` — user clicks "Delete" on the WP_List_Table row action.
- Bulk delete: `admin/Partials/Settings.php:223` — user selects "Delete" from bulk-actions dropdown.

No wrapper action, no admin-handler modification, no new fire site — F020 hooks the built-in BerlinDB action once and covers both. Wired in `includes/Main.php::define_admin_hooks()` per A1.

### `WP_Ability` (via `wp_get_abilities()`)

Runtime-registered abilities. Read via `wp_get_abilities()` server-side (for REST GET's `abilities` payload when `?include_abilities=1`) and via `wp.data.select('core/abilities')` client-side (for the picker's left column). No persistent storage owned by F020 — abilities are ephemeral to the request. Fields consumed by F020 (each may be absent on any given ability):

- `name` — namespaced slug (e.g., `acrossai-core-abilities/create-post`). Required — every ability has one.
- `label` — human-readable title. Falls back to `name` if empty.
- `description` — short prose. Falls back to empty string.
- `meta.mcp.type` — one of `Tool`, `Prompt`, `Resource`. Falls back to empty string (renders no badge). Concrete field path verified during implementation (may be `meta.mcp.primitive` — flagged in tasks.md).
- `meta.mcp.category` — grouping label. Falls back to empty string.

**Excluded abilities**: The three MCP-adapter protocol tools (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) are filtered out server-side (`ToolsController::get_tools`) and client-side (`src/js/tools.js` EXCLUDED_SLUGS constant) before rendering. Duplication is intentional per F017 precedent + Complexity Tracking §DRY tension.

---

## Runtime Enforcement Consumer

Beyond the admin CRUD paths (REST controller + React app), F020 exposes ONE runtime data consumer: the **`mcp_adapter_pre_tool_call` filter callback** that enforces the tool selection at the AI-client boundary (FR-029).

**Class**: `AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate` (new file — mirrors the placement of F017's `AbilityExposureGate`).

**Method**: `public static function filter_pre_tool_call( $result, string $tool_name, $mcp_tool, $server )`

**Wired**: `includes/Main.php::define_public_hooks()` with priority 30:

```php
$this->loader->add_filter(
    'mcp_adapter_pre_tool_call',
    ToolExposureGate::instance(),
    'filter_pre_tool_call',
    30,
    4
);
```

**Priority stacking** (documented for future features that add more gates):

| Priority | Feature | Purpose |
|---------:|---------|---------|
|       10 | F015    | Access-control rule evaluation (WPBoilerplate Access Control) |
|       20 | F017    | Per-server ability exposure toggle (`MCPServerAbility.is_exposed` + `meta.mcp.public` fallback) |
|   **30** | **F020**| **Per-server tool curation (row presence in `wp_acrossai_mcp_server_tools`)** |

**Callback semantics**:

1. **Deny-precedence**: If `$result` is already a `WP_Error`, return it unchanged. F020 NEVER re-allows an ability that an earlier gate denied. (Matches F017 FR-030 shape, matches F015 D18 shape.)
2. **Duck-typed server resolution + fail-open**: Use `is_object( $server ) && method_exists( $server, 'get_server_id' )` to verify the accessor exists — NOT `instanceof` against any vendor class. The accessor `get_server_id(): string` returns the server SLUG (not an int). Empty slug OR unresolvable slug → fail-open (return `$result` unchanged). The slug → integer id resolution runs via `MCPServerQuery::instance()->query( [ 'server_slug' => $slug, 'number' => 1 ] )`. Fires `acrossai_mcp_tool_gate_missing_server` for observability (D19 pattern) ONLY when the resolution path failed for a non-empty slug. Feature-absent cases (no accessor, empty string) are silent. **This shape is a line-for-line mirror of F017's `AbilityExposureGate::gate_tool_call_by_exposure()` at `includes/MCP/AbilityExposureGate.php:98-119` and F015's `AcrossAI_MCP_Access_Control::gate_mcp_tool_call()` at `includes/AccessControl/AcrossAI_MCP_Access_Control.php:249-253`.** See `contracts/enforcement.md §2` for the canonical code.
3. **Protocol-tool bypass**: If `$tool_name` matches one of the three excluded protocol slugs (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`), return `$result` unchanged. Protocol tools are always callable — they are the discovery mechanism itself.
4. **Presence check**: After step 2 has produced an integer `$server_id`, check whether `$tool_name` is in the set returned by `MCPServerToolQuery::instance()->get_added_slugs( $server_id )`. Use a per-request static cache keyed by `$server_id` to avoid a query per tool call within a single request.
5. **Deny for absent slug**: If `$tool_name` is NOT in the added set, return `new \WP_Error( 'acrossai_mcp_tool_not_added', __( 'This tool is not enabled on this MCP server.', 'acrossai-mcp-manager' ), array( 'status' => 403 ) )`. This is the only path where F020 injects a new denial.
6. **Allow for present slug**: If `$tool_name` IS in the added set, return `$result` unchanged (defer to whatever later filters — if any — decide).

**Empty tool set semantics** (matches spec §User Story 1 §"Zero tools added" edge case): when `get_added_slugs()` returns `[]`, every non-protocol tool call returns 403. The mcp-adapter's `discover-abilities` still works (protocol-tool bypass), so the client can enumerate available abilities — but every attempt to invoke one is denied. This matches the UI's zero-added warning banner UX promise verbatim.

**Cache invalidation**: The per-request static cache is invalidated when `Query::replace_set()` completes successfully within the same request (e.g., a bulk REST POST from an admin does not fire the gate immediately, but if it did, the cache MUST be flushed). Cross-request cache invalidation is not needed — every request builds its own static cache from a fresh `get_added_slugs()` call.

**Test target**: `tests/phpunit/MCP/ToolExposureGateTest.php` covering all 6 semantics above (deny-precedence, fail-open, protocol-bypass, presence-allow, absence-deny, cache-hit). Matches SC-012.

---

## State Transitions

`MCPServerTool` rows have no lifecycle beyond insert / delete. There is no state machine:

- **Row does not exist** → operator saves a set containing `ability_slug` → row inserted with `created_at = updated_at = NOW()`.
- **Row exists** → operator saves a set NOT containing `ability_slug` → row deleted.
- **Row exists** → operator saves a set STILL containing `ability_slug` → row unchanged (idempotent — `replace_set()` skips already-present slugs).

`updated_at` currently only ever equals `created_at` at insert time (a re-save of the same set is a no-op, no `update_item()` call). It exists to future-proof potential audit expansion (e.g., "when was this tool selection last touched?") without a schema bump. `'modified' => true` on the column means if `update_item()` is ever called later, the timestamp will auto-refresh.

---

## Cardinality & Volume Estimate

- **Rows per server**: Bounded by the count of registered abilities minus the three excluded protocol tools. Typical WordPress site: 10–50 registered abilities → 10–50 rows per server maximum. Realistic curated tool set: 3–15 rows.
- **Servers per site**: Typical single-digit; MCP Manager operators can register any number.
- **Total row estimate**: `servers × selected_tools_per_server` ≈ `5 × 10 = 50` rows on a typical install. Well under any performance concern for the composite UNIQUE index.
- **Query hot path**: `get_added_slugs( server_id )` — indexed lookup on `server_id`, returns 0–50 rows. Fires once per Tools tab page load and once per REST POST.

## Data Model → Requirements Trace

| Data-model element                        | Requirement covered                       |
|-------------------------------------------|-------------------------------------------|
| Table `wp_acrossai_mcp_server_tools`      | FR-014 (persistence), FR-015 (per-server) |
| UNIQUE(server_id, ability_slug)           | FR-015 correctness invariant              |
| Presence-based (no `is_exposed`)          | Assumption §Presence-based storage        |
| `Query::replace_set()` (transactional)    | FR-010, FR-030 (concurrent determinism)   |
| `Query::get_added_slugs()`                | FR-029 (enforcement lookup)               |
| `Query::delete_items_for_server()`        | FR-026 (cascade cleanup)                  |
| `ToolExposureGate::filter_pre_tool_call`  | FR-029, SC-012                            |
| `mcp_server_deleted` cascade wire         | FR-026                                    |
| Phantom-version guard                     | FR-019 durability                         |
| Uninstall drops under opt-in gate         | FR-028                                    |
| BerlinDB `'modified' => true` on updated_at | B21 mitigation (planning discipline)     |
