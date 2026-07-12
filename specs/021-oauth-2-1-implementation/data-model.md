# Phase 1 — Data Model

F021 introduces three new persistence surfaces, all BerlinDB modules following the F011 pattern (Table/Schema/Row/Query + phantom-version guard + request-time boot).

---

## Entity 1: `OAuthClients`

**Purpose**: Persist issued OAuth clients (both admin-generated and DCR-issued). Row presence + `client_secret_hash` NULL determines public (`token_endpoint_auth_method='none'`) vs confidential (`client_secret_post`) client.

**Storage**: BerlinDB v3 (`\BerlinDB\Database\Kern\{Table,Schema,Query,Row}`).

### Columns

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint(20)` unsigned auto_increment | NO | — | Primary key. |
| `client_id` | `varchar(64)` | NO | `''` | Two formats coexist (Q2): admin = `server-{id}-{slug}-{rand8}`; DCR = 32-hex opaque. |
| `client_secret_hash` | `char(64)` | **YES** | `NULL` | SHA-256 hex of raw secret. NULL when `token_endpoint_auth_method='none'`. |
| `client_name` | `varchar(255)` | NO | `''` | Human-readable from DCR metadata. |
| `redirect_uris` | `text` | NO | `''` | JSON-encoded array. |
| `grant_types` | `varchar(255)` | NO | `'authorization_code refresh_token'` | Space-separated. |
| `token_endpoint_auth_method` | `varchar(32)` | NO | `'none'` | `'none'` or `'client_secret_post'`. |
| `connector_slug` | `varchar(64)` | NO | `''` | Empty for DCR-issued; set for admin-generated. Indexed. |
| `metadata_fingerprint` | `char(64)` | NO | `''` | SHA-256 of canonical metadata for DCR dedupe. |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `'created' => true`. |

### Indexes

| Name | Type | Columns | Purpose |
|---|---|---|---|
| `primary` | PRIMARY | `id` | Row identity. |
| `client_id` | UNIQUE | `client_id` | Fast lookup; enforces uniqueness. |
| `connector_slug` | KEY | `connector_slug` | `AIConnectorsTab` `LIKE 'server-{id}-{slug}-%'` query (Q2). |
| `metadata_fingerprint` | KEY | `metadata_fingerprint` | DCR dedupe short-circuit. |

### Bespoke Query methods

- `find_by_client_id( string $client_id ): ?Row` — lookup for `/authorize` + `/token` client verification.
- `find_by_fingerprint( string $fingerprint ): ?Row` — DCR dedupe short-circuit.
- `create( array $data ): int` — insert wrapper (Repository layer wraps this).

### Data invariants

- `client_secret_hash` is `char(64)` NULLABLE — width preserved as SHA-256 SEC invariant (FR-040, S3, B20).
- `metadata_fingerprint` is `char(64)` — same invariant.
- No `updated_at` column — clients are immutable once issued (revocation via row deletion or the tokens table).

---

## Entity 2: `OAuthTokens`

**Purpose**: Persist access AND refresh tokens in a single table with `token_type` discriminator. Presence-based auth (row exists + `!revoked + !expired` = valid).

### Columns

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint(20)` unsigned auto_increment | NO | — | Primary key. |
| `token_hash` | `char(64)` | NO | `''` | SHA-256 hex of raw token. |
| `token_type` | `varchar(16)` | NO | `'access'` | `'access'` or `'refresh'`. |
| `client_id` | `varchar(64)` | NO | `''` | FK-by-value to `OAuthClients.client_id`. |
| `user_id` | `bigint(20)` unsigned | NO | `0` | WordPress user id this token authenticates as. |
| `scope` | `varchar(255)` | NO | `'mcp'` | Single scope in v1. |
| `resource` | `varchar(500)` | NO | `''` | **RFC 8707 audience URL — enforced at call time by `TokenValidator` per Q1**. |
| `expires_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | Expiry (access = 1h, refresh = 30d). |
| `revoked` | `tinyint(1)` | NO | `0` | Boolean. B18 (`$wpdb` returns tinyint as string) — cast at boundary. |
| `token_family_id` | `char(36)` | NO | `''` | **UUIDv4 identifying the lineage of tokens descended from one auth code (SEC-021-001 / RFC 9700 §2.2.2).** Access + refresh issued from the same code share it; every refresh rotation carries it forward. Empty string = pre-family-revocation row (migrated data would use this, though F021 ships greenfield). |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `'created' => true`. |

### Indexes

| Name | Type | Columns | Purpose |
|---|---|---|---|
| `primary` | PRIMARY | `id` | Row identity. |
| `token_hash` | UNIQUE | `token_hash` | Fast lookup for `TokenValidator`. |
| `client_id` | KEY | `client_id` | Client-scoped queries. |
| `user_id` | KEY | `user_id` | User-scoped bulk revoke (FR-042 cascade). |
| `expires_at` | KEY | `expires_at` | Cron cleanup range scan. |
| `token_type` | KEY | `token_type` | Type-scoped queries. |
| `token_family_id` | KEY | `token_family_id` | **SEC-021-001** — Family-scoped bulk revoke on refresh reuse detection. |

### Bespoke Query methods

- `find_by_hash( string $token_hash ): ?Row` — `TokenValidator` lookup.
- `revoke_by_hash( string $token_hash ): bool` — single-row revocation. Returns true iff `$wpdb->rows_affected === 1`.
- `revoke_by_user_id( int $user_id ): int` — bulk revocation for FR-042 (`deleted_user` cascade, per Q4). Returns count.
- `revoke_by_client_id( string $client_id ): int` — bulk revocation on client regeneration.
- **`revoke_by_family_id( string $family_id, string $reason ): int`** — **SEC-021-001** family bulk revoke on refresh reuse detection. `UPDATE ... SET revoked = 1 WHERE token_family_id = %s AND revoked = 0`. Returns `$wpdb->rows_affected`. Caller (`TokenController::handle_refresh_token`) iterates the SELECT of the same predicate BEFORE the UPDATE to capture `$token_id`s so `acrossai_mcp_manager_oauth_token_revoked` can fire per row with the caller-supplied reason (typically `'family_reuse_detected'`).
- `delete_expired( string $cutoff ): int` — cron cleanup.
- `issue( array $data ): int` — insert wrapper. **New requirement**: caller MUST supply `token_family_id` — either a freshly-generated UUIDv4 (via `wp_generate_uuid4()`) on initial code→token exchange, or the parent refresh's `token_family_id` on rotation. Empty string is only permitted for backward-compatibility test fixtures and MUST warn under `WP_DEBUG`.

### Data invariants

- `token_hash` is `char(64)` — SHA-256 SEC invariant (FR-040, S3, B20).
- `resource` is `varchar(500)` — accommodates long REST URLs. Enforced by `TokenValidator` audience check (Q1).
- `revoked` is TINYINT — B18 applies; consumers cast `(bool)` at boundary.
- `token_family_id` is `char(36)` — matches WordPress `wp_generate_uuid4()` output format. All tokens descended from a single auth code share this value. **Never narrow** — the width is a security invariant.

---

## Entity 3: `OAuthAuthCodes`

**Purpose**: Persist pending authorization codes (short-lived, single-use). Atomic consumption via `UPDATE ... WHERE used=0 AND expires_at > %s` (B10 pattern).

### Columns

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint(20)` unsigned auto_increment | NO | — | Primary key. |
| `code_hash` | `char(64)` | NO | `''` | SHA-256 hex of raw code. |
| `client_id` | `varchar(64)` | NO | `''` | Which client issued this code. |
| `user_id` | `bigint(20)` unsigned | NO | `0` | Consenting user. |
| `redirect_uri` | `varchar(500)` | NO | `''` | Must byte-match at `/token`. |
| `code_challenge` | `char(43)` | NO | `''` | **PKCE S256 invariant — 43 chars matches CliAuthLog (F011)**. |
| `code_challenge_method` | `varchar(16)` | NO | `'S256'` | Always `'S256'` per DEC-OAUTH-PKCE-S256-MANDATORY (planned). |
| `scope` | `varchar(255)` | NO | `'mcp'` | Carried forward to issued tokens. |
| `resource` | `varchar(500)` | NO | `''` | RFC 8707 audience — carried forward to tokens (Q1). |
| `used` | `tinyint(1)` | NO | `0` | Boolean. Atomic CAS on this column. |
| `expires_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | TTL 600s. |
| `created_at` | `datetime` | NO | `CURRENT_TIMESTAMP` | `'created' => true`. |

### Indexes

| Name | Type | Columns | Purpose |
|---|---|---|---|
| `primary` | PRIMARY | `id` | Row identity. |
| `code_hash` | UNIQUE | `code_hash` | Fast lookup + collision impossible. |
| `expires_at` | KEY | `expires_at` | Cron cleanup range scan. |
| `user_id` | KEY | `user_id` | Bulk delete on user deletion (FR-042). |

### Bespoke Query methods

- `consume_atomic( string $code_hash, string $now ): ?Row` — **B10 canonical CAS**. Single `UPDATE ... SET used=1 WHERE code_hash=%s AND used=0 AND expires_at > %s`; if `1 === (int) $wpdb->rows_affected`, `SELECT` and return the row; else return `null`. Prevents replay under concurrent POSTs. Modeled EXACTLY on `CliAuthLog\Query::redeem_atomic`.
- `delete_by_user_id( int $user_id ): int` — bulk delete for FR-042 cascade.
- `delete_expired( string $cutoff ): int` — cron cleanup (also purges `used=1` rows).
- `create( array $data ): int` — insert wrapper.

### Data invariants

- `code_hash` is `char(64)` — SHA-256 SEC invariant.
- `code_challenge` is `char(43)` — PKCE S256 invariant, matches F011 CliAuthLog byte-for-byte.
- `used` is TINYINT — B18 applies. Atomic-CAS relies on `1 === (int) $wpdb->rows_affected` semantic, NOT on reading the `used` value after.

---

## Referenced Entities (pre-existing, unchanged)

- **`MCPServer` rows** (F011 table `wp_acrossai_mcp_servers`): the `resource` param on `/authorize` MUST match an MCP endpoint URL derived from a row in this table (typically `rest_url() . 'mcp/v1/' . $server->server_slug` or similar). `TokenValidator` may need to resolve the request's target URL back to a specific `MCPServer` row to validate audience — implementation detail deferred to tasks.md.
- **`wp_users`**: `user_id` on tokens + codes references WP users. `deleted_user` action cascades per FR-042 (Q4).

---

## Runtime State Transitions

### Auth Code lifecycle

`(created, used=0, unexpired)` → **atomic consume** → `(used=1)` → **cron cleanup** → row deleted.
Replayed consumption → `rows_affected === 0` → returns `null` → `/token` returns `invalid_grant`.

### Access Token lifecycle

`(created, revoked=0, unexpired)` → **/token issue** → **request auth via TokenValidator with audience match** → resource-scoped user identified.
Cascade `deleted_user` → `revoked=1`.
Cron cleanup: rows with `(expires_at < NOW AND revoked=1) OR (expires_at < NOW - 30d)`.

### Refresh Token lifecycle

Same as access, plus **rotation on `/token` refresh grant**: presented token → `revoke_by_hash` → new pair issued with same `resource` + `scope`.
Reuse detection: second presentation of a rotated token → `find_by_hash` returns `revoked=1` → `invalid_grant`.

---

## Data Model → Requirements Trace

| Element | Requirement covered |
|---|---|
| Three tables + `char(64)` widths | FR-039, FR-040, S3, B20 |
| UNIQUE(client_id) / UNIQUE(token_hash) / UNIQUE(code_hash) | Correctness invariants |
| `resource` column enforced at call time | Q1 audience-binding, FR-024, SC-007 |
| `consume_atomic` shape | FR-014, SC-006, B10 |
| `revoke_by_user_id` bulk method | FR-042, Q4 |
| `find_by_fingerprint` | FR-022 DCR dedupe |
| Two `client_id` formats coexisting | Q2, FR-023, FR-035 |
| Phantom-version guard on all three tables | F011 pattern reuse |
