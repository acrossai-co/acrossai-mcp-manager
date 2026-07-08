# Phase 1 — Data Model

**Feature**: 017 — Per-server Ability Selection

## Entity: ServerAbilityOverride

A per-`(server_id, ability_slug)` decision about whether the ability is exposed on the given MCP server. Existence of the row is the operator's explicit intent; absence falls back to the ability's own `meta[mcp][public]` (FR-007).

### Physical Storage

- **Table**: `{wpdb->prefix}acrossai_mcp_server_abilities`
- **Engine**: InnoDB (WP default) with utf8mb4 charset — the `varchar(191)` slug length is deliberate so `UNIQUE(server_id, ability_slug)` fits the 767-byte key limit on MySQL 5.6+.
- **BerlinDB module**: `AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\{Schema, Table, Query, Row}`
- **Version option**: `acrossai_mcp_server_abilities_db_version` (initial value `1.0.0`)

### Columns

| Column | Type | Nullability | Default | BerlinDB flags | Notes |
|---|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NOT NULL | AUTO_INCREMENT | `sortable`, `extra: auto_increment` | Surrogate PK. |
| `server_id` | `bigint(20) unsigned` | NOT NULL | — | `searchable` | Foreign reference to `acrossai_mcp_servers.id`. No physical FK — WP convention. Cascade-delete is a follow-up (see §Non-Goals). |
| `ability_slug` | `varchar(191)` | NOT NULL | `''` | `searchable` | Ability name as returned by `\WP_Ability::get_name()`. 191 chars fit the composite unique key under utf8mb4. |
| `is_exposed` | `tinyint(1)` | NOT NULL | `0` | — | `1` = expose, `0` = hide. Returned as string by `$wpdb` — consumers MUST cast (B18). |
| `created_at` | `datetime` | NOT NULL | — | `created`, `date_query`, `sortable` | Set on INSERT by BerlinDB's `created` timestamping. |
| `updated_at` | `datetime` | NOT NULL | — | `modified` | Set on every UPDATE by BerlinDB's `modified` timestamping (BerlinDB v3 flag — do NOT use `date_updated`, which is unrecognized and trips PHP 8.2 dynamic-property deprecations). |

### Indexes

| Name | Type | Columns | Purpose |
|---|---|---|---|
| `primary` | PRIMARY | `(id)` | Row identity. |
| `server_ability` | UNIQUE | `(server_id, ability_slug)` | Enforces at most one row per pair. Drives the `Query::upsert()` lookup. |
| `server_id` | KEY | `(server_id)` | Per-server list read; supports the REST GET's join with `wp_get_abilities()`. |

### DDL preview (illustrative — BerlinDB generates the actual `CREATE TABLE` from the Schema)

```sql
CREATE TABLE `wp_acrossai_mcp_server_abilities` (
    `id`            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `server_id`     bigint(20) unsigned NOT NULL,
    `ability_slug`  varchar(191)        NOT NULL DEFAULT '',
    `is_exposed`    tinyint(1)          NOT NULL DEFAULT 0,
    `created_at`    datetime            NOT NULL,
    `updated_at`    datetime            NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `server_ability` (`server_id`, `ability_slug`),
    KEY `server_id` (`server_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Row Lifecycle

```text
                       ┌─────────────────────────────┐
                       │ Admin toggles exposure via  │
                       │ Abilities tab UI            │
                       └──────────────┬──────────────┘
                                      │
                                      ▼
                       ┌─────────────────────────────┐
                       │ POST /abilities  (batch)    │
                       │  handler validates slugs    │
                       └──────────────┬──────────────┘
                                      │
                                      ▼
       ┌───────────── row exists?  ───┴─────  row absent? ─────────────┐
       │                                                                 │
       ▼                                                                 ▼
UPDATE `is_exposed` + `updated_at`               INSERT (`server_id`, `ability_slug`,
via Query::update_item(); if the effective         `is_exposed`, `created_at`,
value changed, fire                                `updated_at`) via Query::add_item();
`acrossai_mcp_ability_exposure_changed`.           always fire the action (row is new).
       │                                                                 │
       └────────────────────────────┬────────────────────────────────────┘
                                    ▼
                       ┌─────────────────────────────┐
                       │ ExposureResolver in-request │
                       │ cache invalidated (not      │
                       │ needed — POST is a fresh    │
                       │ request; cache is empty)    │
                       └─────────────────────────────┘
```

### State Transitions

- **absent → exposed=1** — first explicit "Expose" toggle.
- **absent → exposed=0** — first explicit "Hide" toggle on a previously-inherited-public ability.
- **exposed=1 → exposed=0** — direct hide.
- **exposed=0 → exposed=1** — direct expose.
- **any → absent** — NOT reachable from Feature 017's REST surface. Rows are never deleted by the UI; explicit purge is deferred to a follow-up (see §Non-Goals).
- **exposed=X → orphaned** — occurs asynchronously when the ability's slug is unregistered upstream. Row persists silently (FR-025). READ endpoint filters orphans out of the response; row silently reactivates on re-registration.

### Validation Rules

| Rule | Enforced By | Failure Mode |
|---|---|---|
| `server_id` must reference an existing `wp_acrossai_mcp_servers.id` | REST handler (both GET and POST): looks up via `MCPServer\Query::instance()` | 404 with code `acrossai_mcp_server_not_found` (FR-013) |
| `ability_slug` must be present in `wp_get_abilities()` output at write-time | REST POST handler: `array_map( fn ( $a ) => $a->get_name(), \wp_get_abilities() )` allow-list | 400 with code `acrossai_mcp_invalid_payload` — whole batch rejected, zero rows written (FR-011) |
| `is_exposed` must be interpretable as bool | REST arg schema (`type: boolean`); cast `(bool)` before persist | 400 by WP core schema validator |
| `UNIQUE(server_id, ability_slug)` | InnoDB index + `Query::upsert()` pre-check | Second concurrent write becomes an UPDATE (last-write-wins per FR-idempotent semantics) |
| Character set / length | Schema `varchar(191)` + BerlinDB's default utf8mb4 | Truncation is not reachable — WP ability slugs are well under 191 chars |

## Row Shape (returned by REST GET and passed through the row filter)

Each merged row in the `abilities[]` array returned by `GET /servers/{id}/abilities`:

```json
{
  "slug":         "core/get-user-info",       // string, ability name
  "label":        "Get User Information",     // string, human-readable
  "type":         "tool",                     // enum: tool | prompt | resource
  "category":     "user",                     // string, ability's own category
  "description":  "Returns basic ...",        // string
  "is_exposed":   true,                       // bool — resolved via ExposureResolver
  "has_override": false                       // bool — true iff a row exists in acrossai_mcp_server_abilities
}
```

The `acrossai_mcp_ability_row` PHP filter (FR-027) may add extra keys but the controller re-asserts the seven keys above via `array_merge( $filtered, $row )` so extensions cannot overwrite them.

## Relationships

- **`ServerAbilityOverride.server_id → wp_acrossai_mcp_servers.id`** — logical FK. No physical constraint. Server deletion is a known follow-up (§Non-Goals in `spec.md`).
- **`ServerAbilityOverride.ability_slug → \WP_Ability::get_name()`** — logical reference to the site's registered abilities. Orphaned rows are silently preserved (FR-025). No physical constraint — abilities are registered at runtime, not stored in a DB table.

## Non-Goals

- No cascade delete on `MCPServer\Query::delete_item()` — deferred; documented in `spec.md` §Assumptions.
- No object-cache layer on the `ExposureResolver` in-request cache — deferred; per-request static suffices for the current runtime model.
- No cross-site aggregation view (multisite network admin) — each site owns its own table (`$global = false`).
