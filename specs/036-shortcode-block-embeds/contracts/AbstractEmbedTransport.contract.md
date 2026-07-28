# Contract: `AbstractEmbedTransport`

**Feature**: F037 | **Date**: 2026-07-27 | **Plan**: [../plan.md](../plan.md)

Normative reference for the abstract base class's public API + subclass contract. Every clause is a testable invariant that `/speckit-tasks` maps to test tasks.

> **Contract-drift note (2026-07-27)**: This contract was authored pre-Pivot-A + pre-Pivot-B. The public surface widened materially post-`/speckit-implement`:
> - `is_enabled_for_server()` grew a 3rd argument `$dto_slug` (per-DTO gate, not per-category — Pivot A).
> - New instance methods `get_storage_key()`, `is_single_item()`, `get_dtos()` — subclass hooks for storage-blob shape + DTO enumeration (Pivots A + B).
> - New static helpers `get_items_for_server()`, `save_items_for_server()`, `entry_enables_slug()`, `meta_for()` — replace the retired `ServerEmbedTransports\Query` static surface.
> Every section below reflects the SHIPPED contract; historical differences are called out inline.

---

## Namespace + File

- **Namespace**: `AcrossAI_MCP_Manager\Includes\Embeds`
- **File**: `includes/Embeds/AbstractEmbedTransport.php`
- **PHPDoc tag**: `@experimental until plugin 1.0.0` (F037's `public/Renderers/EmbedBlock/EmbedBlockRenderer` inherits DEC-CLIENT-RENDERER-PUBLIC-API; `AbstractEmbedTransport` under `includes/` is a plugin-internal extension seam — third-party plugins subclass it but the shape is documented as stable via memory-hub D35 pattern application).

---

## Abstract Base Shape

```php
abstract class AbstractEmbedTransport {

    public const DEFAULT_TRANSPORT_CLASSES = array(
        NpmEmbedTransport::class,
        ClientEmbedTransport::class,
        AiConnectorEmbedTransport::class,
    );

    // Meta storage keys (post-Pivot B)
    public const META_KEY_MASTER = '_embeds_enabled';
    public const META_KEY_ITEMS  = '_embeds_clients';

    private static array $enabled_cache = array();
    private static ?array $meta_map     = null;

    // Identity (abstract — subclass MUST override)
    abstract public function get_transport_key(): string;
    abstract public function get_checkbox_label(): string;

    // Concrete with defaults (subclass MAY override)
    public function get_priority(): int    { return 100; }
    public function get_description(): string { return ''; }
    public function get_storage_key(): string { return $this->get_transport_key(); }
    public function is_single_item(): bool { return false; }
    public function get_dtos(): array      { return array(); }

    // Statics — enumeration + gate + storage helpers
    public static function get_all_registered_transports(): array;
    public static function is_enabled_for_server( int $server_id, string $transport_key, string $dto_slug ): bool;
    public static function entry_enables_slug( $entry, string $dto_slug, bool $is_single ): bool;
    public static function meta_for( string $transport_key ): array;
    public static function get_items_for_server( int $server_id ): array;
    public static function save_items_for_server( int $server_id, array $items ): void;
    public static function garbage_collect_orphans(): int;
    public static function flush_cache(): void;
}
```

**Public method count**: 5 abstract/concrete instance methods (was 4 pre-Pivot-A) + 8 static class methods (was 4 pre-Pivot-B). Storage-facing key constants exported for consumer use (README + noscript fallback + external tooling).

Every concrete subclass MUST be declared `final class` per FR-012 / D36 (extension via filter, not subclass-of-subclass).

---

## Instance Method Contracts

### `abstract public function get_transport_key(): string`

- **Post**: Returns a machine identifier matching regex `/\A[a-z0-9_-]{1,64}\z/`. Enforced by `get_all_registered_transports()` — subclasses returning non-matching keys are silently skipped + `_doing_it_wrong` under `WP_DEBUG`. Regex includes underscore (F037-only divergence from F034's hyphens-only pattern) to accommodate F035 DTO `category` field values like `ai_connector`.
- **Built-in values**: `'npm'`, `'client'`, `'ai_connector'` (singular per Clarifications Q1 — align with F035 DTO `category` field).

### `abstract public function get_checkbox_label(): string`

- **Post**: Returns a translated display string via `__()` with `'acrossai-mcp-manager'` text domain (in built-ins) or the companion plugin's own text domain (in third-party subclasses).
- **UI usage**: rendered inside `<label>` tag next to the checkbox input. Consumers own escape at render.

### `public function get_priority(): int` (default 100)

- **Post**: Returns display sort order. Lower runs earlier. Built-ins: 10, 20, 30. Third-party contributions default to 100 → appear after all built-ins with slug-alphabetical tiebreak.
- **Post**: Value MUST be an integer. The `get_all_registered_transports()` comparator MUST defensively coerce via `(int)` cast before `usort` (see §Static Method Contracts → `get_all_registered_transports()`) so a companion plugin returning `null` / `false` / a string does NOT fatal the admin tab render. Closes SEC-037-002.

### `public function get_description(): string` (default `''`)

- **Post**: Returns a translated one-line explanation OR empty string. Optional; unused by base plugin's `EmbedsTab` rendering (present for symmetry with F034 pattern + potential future UI enrichment).

### `public function get_storage_key(): string` (default `$this->get_transport_key()`) — added per Pivot B

- **Post**: Returns the category-key used inside the `_embeds_clients` JSON blob. Defaults to `$this->get_transport_key()`. Concrete transports MAY override for a friendlier storage-facing alias:
  - `NpmEmbedTransport::get_storage_key()` → `'npm'` (same as transport_key)
  - `ClientEmbedTransport::get_storage_key()` → `'mcp-client'` (transport_key is `'client'`; storage-facing alias is more descriptive)
  - `AiConnectorEmbedTransport::get_storage_key()` → `'connectors'` (transport_key is `'ai_connector'`; storage-facing alias uses plural collective)
- **Post**: Companion-plugin transports whose transport_key is already storage-friendly can accept the default (no override needed). Alias name choice is subclass-owned.

### `public function is_single_item(): bool` (default `false`) — added per Pivot A

- **Post**: Returns `true` when the transport carries a single logical DTO and its storage entry should be represented as an int shorthand (`1` = present, absent = off) rather than an array-of-enabled-slugs. Only `NpmEmbedTransport` returns `true` today (single npx bridge DTO).
- **Effect on storage**: When `true`, `_embeds_clients[storage_key]` is `0|1|absent`. When `false`, `_embeds_clients[storage_key]` is `['slug1', 'slug2', ...]|absent`.
- **Effect on gate**: `entry_enables_slug()` branches on this flag — see below.

### `public function get_dtos(): array` (default `[]`) — added per Pivot A

- **Post**: Returns the list of F035 DTOs this transport gates. Each DTO is an assoc array with at least `slug`, `name`, `icon` (per F035 DTO shape contract). Consumers iterate the returned list and pass `$dto['slug']` to `is_enabled_for_server()`.
- **Post**: Default `[]` for companion-plugin transports with no known DTOs — the transport still gets a checkbox row (via `get_transport_key()` + `get_checkbox_label()`) BUT the row renders as an empty category. Companion plugin is expected to override with its own DTO source.
- **Built-ins delegate to `ConnectionMethodRegistry`**:
  - `NpmEmbedTransport::get_dtos()` → `ConnectionMethodRegistry::instance()->get_npm_methods()`
  - `ClientEmbedTransport::get_dtos()` → `ConnectionMethodRegistry::instance()->get_clients()`
  - `AiConnectorEmbedTransport::get_dtos()` → `ConnectionMethodRegistry::instance()->get_ai_connectors()`

---

## Static Method Contracts

### `public static function get_all_registered_transports(): array`

Direct application of D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT. Mirrors `AbstractMCPClient::get_all_registered_clients()` line-for-line.

- **Post**: Fires `acrossai_mcp_embed_transports` filter exactly once per call, seeded with `self::DEFAULT_TRANSPORT_CLASSES`.
- **Post-SEC-013-008**: For each FQN in the filter result:
  1. Silent-skip if not a string OR class does not exist OR is not a subclass of `AbstractEmbedTransport`.
  2. Instantiate via `new $fqn()`.
  3. Silent-skip + `_doing_it_wrong( 'AbstractEmbedTransport::get_all_registered_transports', __( '...', 'acrossai-mcp-manager' ), '0.1.10' )` under `WP_DEBUG` if `get_transport_key()` does not match `/\A[a-z0-9_-]{1,64}\z/`. (Regex includes underscore per F037-only divergence from F034 — needed for `ai_connector` key alignment with F035 DTO `category` field.)
  4. Dedup by `get_transport_key()` — later-wins. `_doing_it_wrong` under `WP_DEBUG` on duplicates.
- **Post-SEC-037-002**: Sort surviving instances by `(get_priority() ASC, get_transport_key() ASC)` — comparator MUST cast `get_priority()` return via `(int)` before comparison to prevent a companion plugin returning non-int from fataling the admin render. Optionally emit `_doing_it_wrong( 'AbstractEmbedTransport::get_all_registered_transports', __( '...', 'acrossai-mcp-manager' ), '0.1.10' )` under `WP_DEBUG` when `is_int()` fails, so companion-plugin authors see the signal during development.

  ```php
  usort( $instances, static function ( AbstractEmbedTransport $a, AbstractEmbedTransport $b ): int {
      $pa = (int) $a->get_priority();  // coerce, don't fatal
      $pb = (int) $b->get_priority();
      return ( $pa <=> $pb ) ?: strcmp( $a->get_transport_key(), $b->get_transport_key() );
  } );
  ```
- **Post**: Returns `array_values` (integer-keyed array of `AbstractEmbedTransport` instances).
- **NOT memoized**: matches F034's shape (recomputes every call; consumers memoize if needed). Cost is O(n) where n = built-ins + filter contributions (typical ceiling ~10).

### `public static function is_enabled_for_server( int $server_id, string $transport_key, string $dto_slug ): bool` — **3 args post-Pivot A**

The FR-009 gate. Two-check cascade with per-DTO granularity:

- **Pre**: `$server_id > 0`, `$transport_key` any string, `$dto_slug` any string.
- **Post**: Returns `true` if AND ONLY IF:
  1. The meta row `(server_id, '_embeds_enabled')` exists AND its `meta_value === '1'` (master gate).
  2. The decoded `_embeds_clients` JSON blob contains `$dto_slug` under `meta_for($transport_key)['storage_key']`:
     - If `is_single === true`: entry MUST be int-truthy (`1 === (int) $entry`).
     - If `is_single === false`: entry MUST be an array AND `in_array($dto_slug, $entry, true)`.
- **Post-Memoized**: Result cached in `self::$enabled_cache` keyed on `"{server_id}:{transport_key}:{dto_slug}"` (3-part key). `flush_cache()` resets. Second call for same triple in same request returns cached value with zero DB reads.
- **Post**: Missing master row → `false`. Missing category in blob → `false`. Missing slug in category array → `false`. No `_doing_it_wrong` on any miss (silent gate — production frontend must never leak signal).
- **Historical**: pre-Pivot-A signature was `(int, string)` — 2 args, cache key was `"{server_id}:{transport_key}"`. External callers on the pre-Pivot signature will get a `TypeError` — spec + changelog note the break.

### `public static function entry_enables_slug( $entry, string $dto_slug, bool $is_single ): bool` — added per Pivot A

Uniform helper used by both the runtime gate + REST diff logic. Consumers pass a decoded blob entry (may be `null`, `int`, or `array`) and get a boolean.

- **Post**: When `$is_single === true`: returns `null !== $entry && 1 === (int) $entry`. `$dto_slug` is ignored (single-item categories have one logical DTO — the int shorthand covers every slug).
- **Post**: When `$is_single === false`: returns `is_array( $entry ) && in_array( $dto_slug, $entry, true )`.

### `public static function meta_for( string $transport_key ): array{storage_key: string, is_single: bool}` — added per Pivot B

Memoized runtime lookup used by static callers who don't hold a transport instance (e.g. public shortcode renderer, third-party frontend renderers).

- **Post**: Returns the transport's `storage_key` + `is_single` flag as an assoc array. Built once per request from `get_all_registered_transports()` — the map is populated on first call, cached in `self::$meta_map`, reset by `flush_cache()`.
- **Post**: Unknown transport_key falls through to `['storage_key' => $transport_key, 'is_single' => false]` — a mid-request unregister does NOT fatal.

### `public static function get_items_for_server( int $server_id ): array` — added per Pivot B

- **Post**: Fetches the `_embeds_clients` meta row for the server and JSON-decodes it. Returns the decoded array OR `[]` when the row is missing / value is empty / JSON decode fails (defensive against manual DB edits + corruption).

### `public static function save_items_for_server( int $server_id, array $items ): void` — added per Pivot B

- **Post**: `wp_json_encode` + `update_meta` when `$items` is non-empty. `delete_meta` (removes the row entirely) when `$items` is empty — presence model at the row level, matching FR-005.
- **Post**: Silently no-ops when `wp_json_encode` fails (returns `false` on non-encodable content).

### `public static function garbage_collect_orphans(): int`

The FR-023 optional cleanup helper. Post-Pivot-B, iterates the `_embeds_clients` blob per server rather than a junction table.

- **Post**: For every server with an `_embeds_clients` meta row: decodes the blob; drops any category-key whose transport is no longer registered (via `get_all_registered_transports()`); writes the pruned blob back (via `save_items_for_server()`).
- **Post**: Returns total count of pruned category-keys across all servers.
- **Post**: Second call returns 0 (idempotent).
- **Post**: NEVER called from production paths inside this plugin. Documented supported surface for companion plugins' `uninstall.php` + a future `wp acrossai embeds gc` WP-CLI command.
- **Historical**: pre-Pivot-B enumerated distinct `transport_key` values in the junction table and deleted rows whose key wasn't registered. Semantics unchanged; storage substrate changed.

### `public static function flush_cache(): void`

- **Post**: Sets `self::$enabled_cache = array()` AND `self::$meta_map = null` — both memoized structures are invalidated together.
- **Usage**: PHPUnit `setUp()` for test isolation. Companion plugins that mutate state mid-request. NEVER called from production paths.

---

## Filter Contract (defined by F037)

### `acrossai_mcp_embed_transports`

- **Fired from**: `AbstractEmbedTransport::get_all_registered_transports()` exactly once per call.
- **Signature**: `array apply_filters( 'acrossai_mcp_embed_transports', array $fqns );`
- **Seed**: `self::DEFAULT_TRANSPORT_CLASSES` — array of 3 built-in FQNs.
- **Consumer contract**: MAY append or replace entries. Each entry MUST be a fully-qualified class name (string). Entries silently dropped on any of: not a string, class does not exist, class is not a subclass of `AbstractEmbedTransport`, `get_transport_key()` fails the slug regex, duplicate key already accepted (later-wins).

---

## Observability Actions (defined by F037)

Fired from `admin/Partials/ServerTabs/EmbedsTab.php` save handler, NOT from `AbstractEmbedTransport`. Contracts documented here for centralization.

### `acrossai_mcp_embed_master_toggled`

- **Signature**: `do_action( 'acrossai_mcp_embed_master_toggled', int $server_id, bool $enabled, int $user_id );`
- **Fires**: On actual value transition (0 → 1 OR 1 → 0). No-op saves emit nothing.
- **Timing**: AFTER DB commit; per-listener `try/catch` per R3.

### `acrossai_mcp_embed_transport_toggled`

- **Signature**: `do_action( 'acrossai_mcp_embed_transport_toggled', int $server_id, string $transport_key, bool $enabled, int $user_id );`
- **Fires**: Once per changed transport row per save.
- **Timing**: AFTER row's DB commit; per-listener `try/catch`.

---

## Preserved Invariants (delegation, not re-implementation)

- `acrossai_mcp_client_classes` (F034) — NOT re-fired inside `includes/Embeds/`, `admin/Partials/ServerTabs/EmbedsTab.php`, `public/Renderers/EmbedBlock/`.
- `acrossai_mcp_manager_connector_profiles` (F021) — NOT re-fired.
- `acrossai_mcp_npm_methods` + `acrossai_mcp_connection_methods` (F035) — NOT re-fired.

**Grep gate (SC-005)**: `grep -rEn 'apply_filters.*acrossai_mcp_(client_classes|manager_connector_profiles|npm_methods|connection_methods)' includes/Embeds/ admin/Partials/ServerTabs/EmbedsTab.php public/Renderers/EmbedBlock/` MUST return zero hits.

## Preserved Layering

**Grep gate (SC-006)**: `grep -rEn '\bConnectionMethodRegistry\b' includes/Embeds/` MUST return zero hits. Only `public/Renderers/EmbedBlock/EmbedBlockRenderer.php` imports F035; `includes/Embeds/` is the pure-domain state layer and MUST NOT reach across into `public/`.
