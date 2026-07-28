# Phase 1 Data Model — Per-Server Embeds

**Feature**: F037 | **Date**: 2026-07-27 (revised for Pivots A + B + C) | **Plan**: [plan.md](./plan.md)

F037 stores per-server enable state in a WP-canonical meta table + one new abstract-class contract + one reusable admin-tab base class. This document specifies each surface plus the observability action payloads.

> **Post-plan pivot notice**: This document was originally written against a junction-table + column design. Pivots A + B moved storage to a meta blob; §1 + §2 below reflect the SHIPPED state. Historical sections retained inline for traceability.

---

## 1. Master toggle — meta row `_embeds_enabled` (shipped state)

Master enable/disable state per server is stored in the new WP-canonical meta table `wp_acrossai_mcp_servers_meta` (see §2) as a single row per enabled server:

| Field | Value |
|-------|-------|
| `server_id` | Target server's PK |
| `meta_key` | `'_embeds_enabled'` (constant `AbstractEmbedTransport::META_KEY_MASTER`) |
| `meta_value` | `'1'` when ON |

**Presence model**: The row is DELETED entirely when the master toggle is turned OFF. Absence of a row for `(server_id, '_embeds_enabled')` → master is OFF (default).

**Consumer read pattern**: `MCPServerMeta\Query::get_meta( $server_id, '_embeds_enabled' )` returns `'1'|null`. Cast to bool via `null !== $val && '1' === (string) $val`.

**Historical (Pivot B — retired)**: Original design added an `embeds_enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0` column to `wp_acrossai_mcp_servers`. Migration path shipped:
- `MCPServer\Table::$version 1.1.2 → 1.1.3` — added the column via `upgrade_to_1_1_3()`.
- `MCPServer\Table::$version 1.1.3 → 1.1.4` — DROPped the column via `upgrade_to_1_1_4()` when Pivot B moved storage to the meta table. `upgrade_to_1_1_4()` ALSO DROPs the retired `wp_acrossai_mcp_server_embed_transports` table + `delete_option()` for its stale `db_version` key.

---

## 2. Per-DTO state — meta row `_embeds_clients` JSON blob (shipped state)

Per-DTO enable state is stored in a SINGLE meta row per server whose `meta_value` is a JSON-encoded map:

| Field | Value |
|-------|-------|
| `server_id` | Target server's PK |
| `meta_key` | `'_embeds_clients'` (constant `AbstractEmbedTransport::META_KEY_ITEMS`) |
| `meta_value` | JSON-encoded blob (see shape below) |

**Blob shape**:
```json
{
  "npm": 1,
  "mcp-client": ["claude-desktop", "vscode"],
  "connectors": ["chatgpt", "grok"]
}
```

**Category-key mapping** (transport_key → storage_key, resolved per `AbstractEmbedTransport::meta_for()`):
| transport_key | storage_key | Shape |
|---------------|-------------|-------|
| `npm` | `npm` | int (`1` present = ON, absent = OFF) — `is_single_item() === true` |
| `client` | `mcp-client` | array of enabled DTO slugs — presence-model |
| `ai_connector` | `connectors` | array of enabled DTO slugs — presence-model |
| (companion plugin) | subclass's `get_storage_key()` (defaults to transport_key) | array unless `is_single_item() === true` |

**Presence model (nested)**:
- Category-key absent from blob → every DTO in that category is OFF.
- Category-key present + empty array → same as absent (writer drops empty categories).
- Category-key present with slugs array → only listed slugs are ON.
- Whole `_embeds_clients` meta row DELETED when `$new_items` is empty across all categories.

**Consumer read pattern**: `AbstractEmbedTransport::get_items_for_server( $server_id ): array` — fetches + `json_decode`s the row, returns `[]` on missing or malformed value (defensive against manual DB edits).

**Consumer write pattern**: `AbstractEmbedTransport::save_items_for_server( $server_id, $items ): void` — encodes + writes, or `delete_meta` when `$items` is empty.

**Historical (Pivots A + B — retired)**:
- Original design used a dedicated BerlinDB junction table `wp_acrossai_mcp_server_embed_transports` with columns `id / server_id / transport_key / is_enabled / date_created / date_modified` and `UNIQUE(server_id, transport_key)`. Module created (`includes/Database/ServerEmbedTransports/{Schema,Table,Row,Query}.php`) at F037 initial ship, then DELETED when Pivot B consolidated storage into the meta table.
- Intermediate iteration between Pivots A and B briefly stored per-DTO state as individual rows with `meta_key = '_embed_dto:{transport_key}:{dto_slug}'`; these rows are cleaned up by `MCPServerMeta\Table::upgrade_to_1_0_1()` (targeted DELETE by LIKE-prefix) at first admin_init@3 after Pivot B.

---

## 2b. Meta table shape — `wp_acrossai_mcp_servers_meta` (new BerlinDB module)

Standard WordPress meta-table shape mirroring `wp_postmeta` / `wp_usermeta`:

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `meta_id` | `BIGINT UNSIGNED AUTO_INCREMENT` | No | — | Primary key. |
| `server_id` | `BIGINT UNSIGNED` | No | 0 | FK-ish to `wp_acrossai_mcp_servers.id`. |
| `meta_key` | `VARCHAR(255)` | Yes | — | F037 uses `'_embeds_enabled'` + `'_embeds_clients'`. Table is intentionally generic to hold future per-server settings. |
| `meta_value` | `LONGTEXT` | Yes | — | Scalar OR JSON-encoded — consumer's choice. |

**Indexes**:
- Primary: `meta_id`
- `KEY(server_id)` — accelerates per-server bulk lookups.
- `KEY(meta_key(191))` — prefix index for utf8mb4 safety (191 × 4 = 764 < 767 InnoDB limit).

**NO `UNIQUE(server_id, meta_key)` constraint** — matches `wp_postmeta` convention. F037 enforces single-value-per-key semantic in application code via `Query::update_meta()` (SELECT existence check + INSERT-or-UPDATE, atomic per WP `$wpdb` semantics).

**Table lifecycle**:
- Created on plugin activation via BerlinDB `maybe_upgrade()` (F011 phantom-version guard pattern applies).
- Instantiated at request time via `Main::load_hooks()` per DEC-BERLINDB-TABLE-REQUEST-BOOT.
- Version bumps: `1.0.0` (initial) → `1.0.1` (`upgrade_to_1_0_1` cleans up stale `_embed_dto:*` rows from intermediate iteration).
- Server deletion: `MCPServerMeta\Query::delete_by_server_id( $server_id )` invoked by `Main::cleanup_embed_transports_on_server_delete()` on the `acrossai_mcp_server_deleted` action (FR-017).
- Dropped on F012 opt-in uninstall (see research.md R4).

---

## 3. Runtime class — `AbstractEmbedTransport` DTO / API shape (post-Pivots A + B)

Not a database data model — a class contract. See `contracts/AbstractEmbedTransport.contract.md` for the full normative spec. Summary post-Pivots:

- **Abstract methods** (subclass MUST override): `get_transport_key(): string`, `get_checkbox_label(): string`.
- **Concrete-with-default instance methods**:
  - `get_priority(): int` (default 100)
  - `get_description(): string` (default '')
  - **`get_storage_key(): string`** (default `$this->get_transport_key()`) — added per Pivot B; category-key inside `_embeds_clients` blob
  - **`is_single_item(): bool`** (default `false`) — added per Pivot A; controls int-vs-array shape
  - **`get_dtos(): array`** (default `[]`) — added per Pivot A; DTOs the transport gates
- **Static class methods**:
  - `get_all_registered_transports(): array<int, AbstractEmbedTransport>` — mirrors F034 shape line-for-line (SEC-037-002 comparator coercion applied)
  - **`is_enabled_for_server( int $server_id, string $transport_key, string $dto_slug ): bool`** — 3-arg signature per Pivot A; the FR-009 gate
  - **`entry_enables_slug( $entry, string $dto_slug, bool $is_single ): bool`** — uniform helper for both runtime gate + REST diff
  - **`meta_for( string $transport_key ): array{storage_key, is_single}`** — memoized runtime lookup for static callers without an instance
  - **`get_items_for_server( int $server_id ): array`** — fetch + decode `_embeds_clients` blob
  - **`save_items_for_server( int $server_id, array $items ): void`** — encode + write (or delete row when empty)
  - `garbage_collect_orphans(): int` — FR-023 opt-in cleanup (semantic preserved; substrate is now the blob, not the junction table)
  - `flush_cache(): void` — resets both `$enabled_cache` AND `$meta_map`
- **Class constants**:
  - `DEFAULT_TRANSPORT_CLASSES` — seed FQNs for the filter
  - `META_KEY_MASTER = '_embeds_enabled'`
  - `META_KEY_ITEMS = '_embeds_clients'`

Every subclass MUST be `final class` per FR-012 / D36.

Transport-key + storage-key alignment (Clarifications Q1 + Pivot B):
| Transport Key | Storage Key | F035 DTO Category | Class Name | Priority | Single? | Label |
|---------------|-------------|-------------------|------------|----------|---------|-------|
| `npm` | `npm` | `npm` | `NpmEmbedTransport` | 10 | ✅ true | "NPM Methods" |
| `client` | `mcp-client` | `client` | `ClientEmbedTransport` | 20 | false | "MCP Clients" |
| `ai_connector` | `connectors` | `ai_connector` | `AiConnectorEmbedTransport` | 30 | false | "AI Connectors" |

- **transport_key** aligns 1:1 with F035 DTO `category` field values (Clarifications Q1).
- **storage_key** is the human-friendlier alias inside `_embeds_clients` JSON (Pivot B).
- Consumers holding an F035 DTO call `is_enabled_for_server( $server_id, $dto['category'], $dto['slug'] )` (3 args).

---

## 4. Observability action payloads

### `acrossai_mcp_embed_master_toggled`

- **Signature**: `do_action( 'acrossai_mcp_embed_master_toggled', int $server_id, bool $enabled, int $user_id )`
- **Fires**: ONCE per save that changes `wp_acrossai_mcp_servers.embeds_enabled` from 0 → 1 OR 1 → 0. NOT fired on no-op saves.
- **Timing**: AFTER the DB commit; inside per-listener `try/catch` per R3.
- **Args**:
  - `$server_id` — target server's primary key.
  - `$enabled` — the NEW value (true if now-ON, false if now-OFF).
  - `$user_id` — `get_current_user_id()` at save time (0 if anonymous, though the surrounding save handler enforces `manage_options` so should always be a real user).

### `acrossai_mcp_embed_transport_toggled` — **5-arg signature post-Pivot A**

- **Signature**: `do_action( 'acrossai_mcp_embed_transport_toggled', int $server_id, string $transport_key, string $dto_slug, bool $enabled, int $user_id )`
- **Fires**: ONCE per DTO membership transition in a save. A save that toggles 3 DTOs fires this 3 times. A save that changes 2 no-ops on 1 and toggles 1 fires exactly once.
- **Timing**: AFTER the meta write commits; inside per-listener `try/catch` per R3.
- **Args**:
  - `$server_id` — target server's primary key.
  - `$transport_key` — the transport category (e.g. `'client'`; matches `get_transport_key()`).
  - `$dto_slug` — the F035 DTO slug (e.g. `'claude-desktop'`; matches `$dto['slug']`).
  - `$enabled` — the NEW value.
  - `$user_id` — `get_current_user_id()` at save time.

**BREAKING CHANGE alert**: pre-Pivot-A signature was `(server_id, transport_key, enabled, user_id)` — 4 args. `$dto_slug` was inserted as the 3rd argument. External audit-log consumers relying on the 4-arg signature will bind `$enabled` where `$dto_slug` lives now, and `$user_id` where `$enabled` lives now — arguments silently misaligned. Consumers MUST update listener signatures to the 5-arg shape. README `= Unreleased =` changelog entry called out.

---

## 5. Memoization state

Class-level cache on `AbstractEmbedTransport`:

```php
private static array $enabled_cache = array();  // "{server_id}:{transport_key}" => bool
```

- Populated on first call to `is_enabled_for_server()` for a given `(server_id, transport_key)` pair.
- Reset by `AbstractEmbedTransport::flush_cache()` — called in test `setUp()` for isolation; NOT called from production paths.
- Companion plugins that mutate state mid-request (very rare — normally admin form save + full-request cycle) can call `flush_cache()` before their next lookup.

---

## 6. GC helper (`garbage_collect_orphans`) behavior

Contract per FR-023:

1. `$known_keys = array_map( fn( $t ) => $t->get_transport_key(), AbstractEmbedTransport::get_all_registered_transports() );`
2. `$stored_keys = SELECT DISTINCT transport_key FROM wp_acrossai_mcp_server_embed_transports;`
3. `$orphan_keys = array_diff( $stored_keys, $known_keys );`
4. `if ( empty( $orphan_keys ) ) return 0;`
5. `DELETE FROM wp_acrossai_mcp_server_embed_transports WHERE transport_key IN (…orphan_keys…);` (parameterized via `$wpdb->prepare` with `%s` placeholders per B39 pattern to avoid PHPCS `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`).
6. Return `$wpdb->rows_affected` (the number of pruned rows).

Idempotent: second call returns 0.
