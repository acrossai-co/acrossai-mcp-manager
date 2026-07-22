# Phase 1: Data Model

Three existing BerlinDB tables gain one column each (`server_id BIGINT UNSIGNED NOT NULL`, added as NULL transiently within the upgrade callback and MODIFYed to NOT NULL as the final callback step). One table gains a composite UNIQUE that replaces a standalone UNIQUE. One table gains a composite KEY for query performance.

## Entity 1 — OAuth Client (`{$wpdb->prefix}acrossai_mcp_oauth_clients`)

### Post-migration schema (final state — Schema.php declares this)

| Column | Type | Nullability | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NOT NULL | AUTO_INCREMENT | PRIMARY KEY (existing) |
| `client_id` | `varchar(191)` | NOT NULL | — | External-facing client identifier (existing) |
| **`server_id`** | **`bigint(20) unsigned`** | **NOT NULL** | **—** | **NEW (F032). References `{$wpdb->prefix}acrossai_mcp_servers.id` logically; no FK constraint (matches existing plugin convention).** |
| `client_secret` | `char(64)` | NOT NULL | — | SHA-256 hashed per S3 (existing per F021 / F029) |
| `client_name` | `varchar(191)` | NOT NULL | `''` | Human-readable name (existing) |
| `connector_slug` | `varchar(64)` | NOT NULL | `''` | F029 connector attribution (existing) |
| `token_endpoint_auth_method` | `varchar(32)` | NOT NULL | `'none'` | F027 DCR default (existing) |
| `date_created` | `datetime` | NOT NULL | `CURRENT_TIMESTAMP` | (existing) |
| ... | (other existing columns preserved verbatim) | | | |

### Indexes (post-migration)

| Index | Columns | Type | Notes |
|---|---|---|---|
| `PRIMARY` | `(id)` | PRIMARY | Existing |
| `client_id_server_id` | `(client_id, server_id)` | UNIQUE | **NEW (F032)** — replaces the existing `client_id` standalone UNIQUE. Enables same DCR `client_id` on multiple servers as distinct rows. |
| `~~client_id~~` | ~~(client_id)~~ | ~~UNIQUE~~ | **DROPPED (F032)** after composite is added. Order matters: ADD composite → verify → DROP standalone. |

### Upgrade callback ordering (`OAuthClients\Table::upgrade_to_<v>()`)

1. ADD COLUMN `server_id BIGINT UNSIGNED DEFAULT NULL` if missing (idempotency: `INFORMATION_SCHEMA.COLUMNS` gate).
2. Backfill from prefix WITH orphan-server guard (per SEC-032-003 remediation, 2026-07-21): `UPDATE ... SET server_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(client_id, '-', 2), '-', -1) AS UNSIGNED) WHERE server_id IS NULL AND client_id LIKE 'server-%' AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(client_id, '-', 2), '-', -1) AS UNSIGNED) IN (SELECT id FROM {$wpdb->prefix}acrossai_mcp_servers)`. Rows whose parsed server_id doesn't match a real server row are LEFT NULL — they proceed to the PURGE step.
3. PURGE: `DELETE FROM ... WHERE server_id IS NULL` (removes both legacy pre-F032 DCR rows AND any admin client rows whose parsed prefix pointed at a non-existent server).
4. ADD UNIQUE `client_id_server_id` if missing (idempotency: `INFORMATION_SCHEMA.STATISTICS` gate). Then DROP standalone `client_id` UNIQUE if present.
5. MODIFY `server_id` to `NOT NULL` (idempotency: `IS_NULLABLE = 'YES'` gate).
6. Fire `do_action( 'acrossai_mcp_oauth_legacy_dcr_purged', $clients_deleted, $tokens_deleted, $auth_codes_deleted )` iff any of the three purge counts > 0. Pull tokens/auth_codes counts from `OAuthTokens\Table::instance()->get_last_purge_count()` + `OAuthAuthCodes\Table::instance()->get_last_purge_count()`.

### Row.php delta

```php
public $server_id = 0;                    // NEW property; (int) cast in ctor per B18 defense
// Post-migration invariant: never NULL (SQL-enforced by NOT NULL constraint)
```

### Query.php deltas

| Method | Signature Change | Rationale |
|---|---|---|
| `find_admin_clients_for_server_connector` | Body change only: filter by `server_id` column instead of `client_id LIKE 'server-<id>-%'` prefix pattern. Signature unchanged. | Prefix parsing was the pre-F032 workaround; column filter is authoritative + indexed. |
| `find_dcr_clients` | Signature gains optional `int $server_id = 0` param (0 = all servers). | Enables per-server DCR enumeration for F032 REST endpoints. |
| `find_by_client_id` | Gains **required** `int $server_id` param. **BREAKING** for every caller in `includes/OAuth/*`; every call site MUST update. | Cross-server bypass prevention; single canonical lookup shape. |
| `find_by_client_id_and_server_id` (NEW) | `( string $client_id, int $server_id ): ?Row` | New composite-key helper; per-request cache mirrors F017 `ExposureResolver::resolve()` shape. |

**Note (per SEC-032-001 remediation, 2026-07-21)**: The originally-planned `find_by_client_id_any_server( string ): ?Row` helper has been REMOVED from the design. It would have been an internal-only observability helper (single caller in ConnectorAdminController's 403 path, populating `$server_id_actual` for the do_action fire), but any listener on the resulting `acrossai_mcp_oauth_cross_server_attempted` action would receive the actual owning server_id — a cross-server oracle. The revised FR-023 fires the action with 4 args (client_id, server_id_requested, user_id, timestamp) — no owning-server disclosure. Operators who need the owning server for forensic analysis can query the DB directly from within their listener.

---

## Entity 2 — OAuth Token (`{$wpdb->prefix}acrossai_mcp_oauth_tokens`)

### Post-migration schema (final state)

| Column | Type | Nullability | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NOT NULL | AUTO_INCREMENT | PRIMARY (existing) |
| `client_id` | `varchar(191)` | NOT NULL | — | (existing) |
| **`server_id`** | **`bigint(20) unsigned`** | **NOT NULL** | **—** | **NEW (F032)** — copied from `oauth_auth_codes.server_id` at code-exchange, from prior token at refresh |
| `user_id` | `bigint(20) unsigned` | NOT NULL | — | (existing) |
| `access_token` | `char(64)` | NOT NULL | — | SHA-256 hashed per S3 (existing) |
| `refresh_token` | `char(64)` | NOT NULL | — | SHA-256 hashed (existing) |
| `expires_at` | `datetime` | NOT NULL | — | (existing) |
| `revoked` | `tinyint(1)` | NOT NULL | 0 | (existing; B18 defensive cast applies) |
| ... | (other existing columns preserved) | | | |

### Indexes (post-migration)

| Index | Columns | Type | Notes |
|---|---|---|---|
| `PRIMARY` | `(id)` | PRIMARY | Existing |
| `server_id_client_id` | `(server_id, client_id)` | KEY | **NEW (F032)** — accelerates `WHERE server_id = ? AND client_id = ?` lookups (the primary access pattern for F032 revoke endpoints). |
| (existing indexes preserved verbatim) | | | |

### Upgrade callback ordering (`OAuthTokens\Table::upgrade_to_<v>()`)

1. ADD COLUMN `server_id BIGINT UNSIGNED DEFAULT NULL` if missing.
2. ADD KEY `server_id_client_id` if missing.
3. Backfill via JOIN: `UPDATE ... t INNER JOIN oauth_clients c ON t.client_id = c.client_id SET t.server_id = c.server_id WHERE t.server_id IS NULL AND c.server_id IS NOT NULL`. **MUST run before OAuthClients callback's purge step** — see R2.
4. PURGE: `DELETE FROM ... WHERE server_id IS NULL` (removes tokens for legacy DCR clients). Store count in `$this->last_purge_count` for OAuthClients callback to read.
5. MODIFY `server_id` to `NOT NULL` (idempotency: `IS_NULLABLE = 'YES'` gate).

### Row.php delta

Same shape as OAuthClients — `public $server_id = 0;` + `(int)` cast + `to_array()` entry.

### Query.php deltas

| Method | Signature Change | Rationale |
|---|---|---|
| `revoke_by_client_id` | Gains **required** `int $server_id` param. **BREAKING** for every caller. | Cross-server revoke prevention. |
| `get_active_user_ids_by_client_id` | **Renamed** to `get_active_user_ids_by_client_id_and_server_id`, gains **required** `int $server_id` param. **BREAKING**. | Closes the read-side "authorized users" cross-server display leak. |
| `query` | Gains optional `server_id` filter arg. | Composable filter for admin UI + tests. |
| `revoke_by_user_id( int $user_id )` | **NO CHANGE** — signature preserved verbatim. | Site-wide cascade per FR-042 (user deletion). Regression test protects this in PerServerIsolationTest #6. |

---

## Entity 3 — OAuth Auth Code (`{$wpdb->prefix}acrossai_mcp_oauth_auth_codes`)

### Post-migration schema (final state)

| Column | Type | Nullability | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint(20) unsigned` | NOT NULL | AUTO_INCREMENT | PRIMARY (existing) |
| `client_id` | `varchar(191)` | NOT NULL | — | (existing) |
| **`server_id`** | **`bigint(20) unsigned`** | **NOT NULL** | **—** | **NEW (F032)** — resolved from RFC 8707 `resource` param at authorize time |
| `user_id` | `bigint(20) unsigned` | NOT NULL | — | (existing) |
| `code` | `char(64)` | NOT NULL | — | Hashed one-shot credential (existing) |
| `code_challenge` | `varchar(191)` | NOT NULL | — | PKCE S256 (existing) |
| `expires_at` | `datetime` | NOT NULL | — | (existing) |
| ... | (other existing columns preserved) | | | |

### Indexes (post-migration)

Existing indexes preserved verbatim. No new indexes required (auth-code lookups are always via `code` primary attribute; JOIN performance handled by client-side index).

### Upgrade callback ordering (`OAuthAuthCodes\Table::upgrade_to_<v>()`)

Same shape as OAuthTokens (see above). 5 steps: ADD COLUMN → backfill via JOIN → PURGE → MODIFY NOT NULL. Store `$this->last_purge_count` for aggregate signal.

### Row.php delta

Same shape as OAuthClients / OAuthTokens.

### Query.php deltas

| Method | Signature Change | Rationale |
|---|---|---|
| Every mutating helper accepting `client_id` | Gains **required** `int $server_id` param. | Cross-server prevention. |
| `delete_by_user_id( int $user_id )` | **NO CHANGE** — signature preserved verbatim. | Site-wide cascade (same rationale as tokens `revoke_by_user_id`). |

---

## Cross-Entity Relationships

```text
oauth_servers (existing MCPServer table)
  │
  │ 1:N (logical, no FK)
  ▼
oauth_clients
  ├── server_id (NEW F032, NOT NULL post-migration)
  │
  │ 1:N (logical, no FK; JOIN on client_id + server_id via composite index)
  ├─▶ oauth_tokens.server_id (NEW F032, copied from auth_code at exchange, from prior_token at refresh)
  │
  └─▶ oauth_auth_codes.server_id (NEW F032, resolved from RFC 8707 resource at authorize)
```

**No foreign-key constraints** — matches existing plugin convention (no OAuth table has FK constraints; referential integrity enforced application-side by Repository classes). This preserves the compatibility surface with any WordPress hosting environment that disables FK enforcement.

## Validation Rules (from FRs)

1. **FR-004**: `UNIQUE(client_id, server_id)` on `oauth_clients` — enforced by composite index at SQL layer. INSERT attempting a duplicate (client_id, server_id) pair fails with `WPDB::last_error` set to a MySQL duplicate-key error.
2. **FR-014 + FR-027**: DCR endpoint MUST reject request when `resource` URL (a) has an origin (scheme+host+port) that does not match `home_url()`, OR (b) has a matching origin but does not resolve to a known MCP server. Both cases return `WP_Error( 'invalid_target', 400 )`, no row inserted. Origin verification precedes path resolution.
3. **FR-016**: `ConnectorAdminController::handle_revoke_client_tokens()` MUST look up client via `find_by_client_id_and_server_id( $client_id, $server_id )` before mutating. Null result → `WP_Error( 'acrossai_mcp_oauth_cross_server', 403 )` + `do_action( 'acrossai_mcp_oauth_cross_server_attempted', $client_id, $server_id_requested, $user_id, $timestamp )` fire per FR-023 (4-arg shape, no owning-server disclosure).
4. **FR-026**: Post-upgrade, `INFORMATION_SCHEMA.COLUMNS.IS_NULLABLE = 'NO'` on `server_id` for all three tables — INSERT missing `server_id` fails at SQL layer with constraint violation (verified via SC-009 test).
5. **FR-028**: DCR endpoint MUST verify `server_id` column presence via `INFORMATION_SCHEMA.COLUMNS` before INSERT. Column absent → `WP_Error( 'service_unavailable', 503 )` — do NOT INSERT. Prevents legitimate DCR registrations from being silently destroyed by the auto-purge step during the deploy → migration race window.
6. **Post-upgrade invariant**: `SELECT COUNT(*) FROM oauth_clients WHERE server_id NOT IN (SELECT id FROM oauth_servers) = 0`. Enforced by the FR-005 orphan-guard clause on the backfill UPDATE + FR-007 PURGE step. Verified via SC-011.

## State Transitions

**OAuth Client** — post-F032 lifecycle:
- **CREATED (DCR)**: `handle_register` → `ClientRegistrationController::resolve_server_id_from_resource_url()` → INSERT with `server_id`. Fails with `invalid_target` if resource URL unresolvable.
- **CREATED (admin)**: `handle_admin_generate` → INSERT with `server_id` from admin form context.
- **DELETED (admin)**: `handle_delete_client( server_id, client_id )` → validate via `find_by_client_id_and_server_id` → DELETE. Cross-server attempt returns 403 + fires observability action.

**OAuth Token** — post-F032 lifecycle:
- **CREATED (auth_code exchange)**: `TokenController::handle_authorization_code` → copy `server_id` from `$auth_code_row->server_id` onto token.
- **CREATED (refresh)**: `TokenController::handle_refresh_token` → copy `server_id` from `$prior_token_row->server_id` onto new token.
- **REVOKED (admin per-client)**: `handle_revoke_client_tokens( server_id, client_id )` → `TokensQuery::revoke_by_client_id( client_id, server_id )` → UPDATE `revoked = 1` scoped to `(client_id, server_id)`.
- **REVOKED (cascade on user deletion)**: `UserLifecycle::on_user_deleted( user_id )` → `TokensQuery::revoke_by_user_id( user_id )` — **site-wide, no server_id filter** (regression-tested).

**OAuth Auth Code** — post-F032 lifecycle:
- **CREATED (authorize)**: `AuthorizationController::handle_consent_post` → resolve `server_id` from RFC 8707 `resource` → INSERT with `server_id`.
- **CONSUMED (token exchange)**: single-use — CAS-based UPDATE per B10 pattern. Token inherits `server_id`.
- **DELETED (cascade on user deletion)**: `AuthCodesQuery::delete_by_user_id( user_id )` — site-wide, mirrors tokens.

## Data Preservation Contract

| Table | `$wpdb->prefix` prefix | `db_version_key` option | `$version` bump |
|---|---|---|---|
| OAuthClients | `acrossai_mcp_oauth_clients` | `wpdb_acrossai_mcp_oauth_clients_version` | `<current> + 0.0.1` (read current at implement time) |
| OAuthTokens | `acrossai_mcp_oauth_tokens` | `wpdb_acrossai_mcp_oauth_tokens_version` | `<current> + 0.0.1` |
| OAuthAuthCodes | `acrossai_mcp_oauth_auth_codes` | `wpdb_acrossai_mcp_oauth_auth_codes_version` | `<current> + 0.0.1` |

Table names + option names are **preserved byte-for-byte** — no data loss on upgrade. Fresh installs get the final NOT NULL schema directly (no legacy rows to purge; version stamped at Activator time via `Table::maybe_upgrade()` walking `$upgrades` on the fresh table).
