# Phase 1 Data Model — Feature 016

Feature 016 is a schema-retirement feature. There are no new entities. This document specifies the pre/post shape of the affected schema.

## Entity: MCPServer (`wp_acrossai_mcp_servers`)

### Pre-Feature-016 (13 columns, version `0.0.1`)

| # | Column | Type | Default | Nullable | Sortable/Searchable |
|---|--------|------|---------|----------|---------------------|
| 1 | `id` | `bigint(20) UNSIGNED AUTO_INCREMENT` | – | NO | Sortable |
| 2 | `server_name` | `varchar(255)` | – | NO | – |
| 3 | `server_slug` | `varchar(255)` | `''` | NO | Sortable, Searchable |
| 4 | `description` | `varchar(500)` | `''` | NO | – |
| 5 | `is_enabled` | `tinyint(1)` | `0` | NO | – |
| 6 | `registered_from` | `varchar(50)` | `'plugin'` | NO | – |
| 7 | `server_route_namespace` | `varchar(100)` | `'mcp'` | NO | – |
| 8 | `server_route` | `varchar(255)` | `''` | NO | – |
| 9 | `server_version` | `varchar(50)` | `'v1.0.0'` | NO | – |
| 10 | `claude_connector_client_id` | `varchar(255)` | `''` | NO | – |
| 11 | `claude_connector_client_secret` | `varchar(255)` | `''` | NO | – |
| 12 | `claude_connector_redirect_uri` | `varchar(500)` | `''` | NO | – |
| 13 | `created_at` | `datetime` | `CURRENT_TIMESTAMP` | NO | Sortable, date_query |

**Indexes** (all preserved verbatim by Feature 016):
- `PRIMARY KEY (id)`
- `KEY server_slug (server_slug)`

**BerlinDB metadata**:
- `$version = '0.0.1'`
- `$db_version_key = 'acrossai_mcp_manager_db_version'`

### Post-Feature-016 (10 columns, version `0.0.2`)

Columns 10–12 (all three `claude_connector_*`) are dropped. All other columns retain their exact types, defaults, nullability, and index membership.

| # | Column | Type | Default | Nullable | Sortable/Searchable |
|---|--------|------|---------|----------|---------------------|
| 1 | `id` | `bigint(20) UNSIGNED AUTO_INCREMENT` | – | NO | Sortable |
| 2 | `server_name` | `varchar(255)` | – | NO | – |
| 3 | `server_slug` | `varchar(255)` | `''` | NO | Sortable, Searchable |
| 4 | `description` | `varchar(500)` | `''` | NO | – |
| 5 | `is_enabled` | `tinyint(1)` | `0` | NO | – |
| 6 | `registered_from` | `varchar(50)` | `'plugin'` | NO | – |
| 7 | `server_route_namespace` | `varchar(100)` | `'mcp'` | NO | – |
| 8 | `server_route` | `varchar(255)` | `''` | NO | – |
| 9 | `server_version` | `varchar(50)` | `'v1.0.0'` | NO | – |
| 10 | `created_at` | `datetime` | `CURRENT_TIMESTAMP` | NO | Sortable, date_query |

**Indexes** (unchanged — same `PRIMARY KEY (id)` + `KEY server_slug (server_slug)`).

**BerlinDB metadata**:
- `$version = '0.0.2'` (bumped)
- `$db_version_key = 'acrossai_mcp_manager_db_version'` (unchanged)
- `Table::maybe_upgrade()` override preserved verbatim (phantom-version guard from Feature 011 — reference `docs/planings-tasks/011-berlindb-migration.md` and F011 WORKLOG entry 2026-07-02).

### Row invariants (FR-010)

- `SELECT COUNT(*) FROM {prefix}acrossai_mcp_servers` MUST be equal pre- and post-migration.
- The surviving 10 columns' `SHOW CREATE TABLE` output MUST match the pre-migration DDL byte-for-byte (types, lengths, defaults, nullability, index names). Any incidental drift fires an unwanted `ALTER TABLE` on healthy installs.

---

## Entity: OAuth Token (`wp_acrossai_mcp_oauth_tokens`)

### Pre-Feature-016 (9 columns, version `0.0.1`)

Table shape reproduced here for reference so post-migration verification can confirm the exact table was dropped, not merely one of similar name.

| # | Column | Type | Default | Nullable |
|---|--------|------|---------|----------|
| 1 | `id` | `bigint(20) UNSIGNED AUTO_INCREMENT` | – | NO |
| 2 | `access_token_hash` | `char(64)` | – | NO |
| 3 | `server_id` | `bigint(20) UNSIGNED` | `0` | NO |
| 4 | `user_id` | `bigint(20) UNSIGNED` | `0` | NO |
| 5 | `issued_from_code_id` | `bigint(20) UNSIGNED` | `0` | NO |
| 6 | `scope` | `varchar(64)` | `'mcp'` | NO |
| 7 | `created_at` | `datetime` | `CURRENT_TIMESTAMP` | NO |
| 8 | `expires_at` | `datetime` | – | NO |
| 9 | `revoked_at` | `datetime` | NULL | YES |

**Indexes** (all dropped with the table):
- `PRIMARY KEY (id)`, `UNIQUE KEY access_token_hash`, `KEY server_expires (server_id, expires_at)`, `KEY user_created (user_id, created_at)`, `KEY issued_from_code (issued_from_code_id)`.

### Post-Feature-016

Entity RETIRED. Table dropped via `Activator::activate()` (`DROP TABLE IF EXISTS`) AND `uninstall.php`. Option `acrossai_mcp_oauth_tokens_db_version` deleted. All rows (including historical tokens) discarded — no data-preservation step (attestation basis: no live connector tokens exist).

---

## Entity: OAuth Audit (`wp_acrossai_mcp_oauth_audit`)

### Pre-Feature-016 (9 columns, version `0.0.1`)

| # | Column | Type | Default | Nullable |
|---|--------|------|---------|----------|
| 1 | `id` | `bigint(20) UNSIGNED AUTO_INCREMENT` | – | NO |
| 2 | `event_type` | `varchar(64)` | – | NO |
| 3 | `server_id` | `bigint(20) UNSIGNED` | `0` | NO |
| 4 | `user_id` | `bigint(20) UNSIGNED` | `0` | NO |
| 5 | `client_id` | `varchar(255)` | `''` | NO |
| 6 | `token_hash_prefix` | `char(8)` | `''` | NO |
| 7 | `endpoint` | `varchar(255)` | `''` | NO |
| 8 | `details_json` | `text` | NULL | YES |
| 9 | `created_at` | `datetime` | `CURRENT_TIMESTAMP` | NO |

**Indexes** (all dropped with the table):
- `PRIMARY KEY (id)`, `KEY event_created (event_type, created_at)`, `KEY server_created (server_id, created_at)`, `KEY user_created (user_id, created_at)`.

### Post-Feature-016

Entity RETIRED. Table dropped via `Activator::activate()` AND `uninstall.php`. Option `acrossai_mcp_oauth_audit_db_version` deleted. All rows discarded.

---

## WordPress Options (Feature 016 delta)

| Option | Pre-016 | Post-016 |
|--------|---------|----------|
| `acrossai_mcp_manager_db_version` | `'0.0.1'` | `'0.0.2'` (bumped via BerlinDB `$version`) |
| `acrossai_mcp_oauth_tokens_db_version` | `'0.0.1'` (if present) | DELETED |
| `acrossai_mcp_oauth_audit_db_version` | `'0.0.1'` (if present) | DELETED |
| `acrossai_mcp_claude_connectors_enabled` | boolean (if present) | DELETED |
| `acrossai_mcp_cli_auth_log_db_version` | (unchanged) | (unchanged) |
| `acrossai_mcp_uninstall_delete_data` | (unchanged) | (unchanged — controls uninstall opt-in gate per `DEC-UNINSTALL-OPT-IN-GATE`) |

## WordPress Cron Events (Feature 016 delta)

| Event | Pre-016 | Post-016 |
|-------|---------|----------|
| `acrossai_mcp_oauth_cleanup` | Daily | UNSCHEDULED (removed from `Activator::activate()` schedule call; removed from `Deactivator::deactivate()` clear-hook call; hook registration removed from `Main.php`) |

## Rewrite Rules (Feature 016 delta)

Three rules previously registered by `ClaudeConnectors::register_rewrite_rules()`:
- `^\.well-known/oauth-authorization-server/mcp/([^/]+)/?$`
- `^\.well-known/oauth-protected-resource/mcp/([^/]+)/?$`
- `^acrossai-mcp-oauth/([^/]+)/([^/]+)/?$`

All three cleared by `flush_rewrite_rules()` at end of `Activator::activate()` on next reactivation, because the registering method is gone.

## Data flow (retirement mechanics)

```text
Plugin update ──▶ user activation ──▶ Activator::activate()
                                       │
                                       ├─ DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_tokens
                                       ├─ DROP TABLE IF EXISTS wp_acrossai_mcp_oauth_audit
                                       ├─ delete_option('acrossai_mcp_oauth_tokens_db_version')
                                       ├─ delete_option('acrossai_mcp_oauth_audit_db_version')
                                       ├─ delete_option('acrossai_mcp_claude_connectors_enabled')
                                       ├─ MCPServerTable::instance()->maybe_upgrade()   ← version diff drops 3 columns
                                       │   └── [FALLBACK if diff engine skips DROP COLUMN:
                                       │        ConnectorColumnMigration::run() issues
                                       │        idempotent ALTER TABLE ... DROP COLUMN]
                                       ├─ CliAuthLogTable::instance()->maybe_upgrade()   (unchanged)
                                       ├─ DefaultServerSeeder::seed()                    (uses 10-column shape)
                                       └─ flush_rewrite_rules()                         ← 3 OAuth rules gone

Plugin update ──▶ user uninstall ──▶ uninstall.php
                                     │
                                     ├─ IF acrossai_mcp_uninstall_delete_data !== 1 → return  (opt-in gate)
                                     ├─ DROP TABLE wp_acrossai_mcp_oauth_tokens   (safety net)
                                     ├─ DROP TABLE wp_acrossai_mcp_oauth_audit    (safety net)
                                     ├─ delete_option(three OAuth-era options)   (safety net)
                                     └─ [remaining unchanged uninstall.php content]
```

## State transitions

The MCPServer entity has no lifecycle state. The OAuth Token and OAuth Audit entities are retired — they have no post-016 state to transition to.
