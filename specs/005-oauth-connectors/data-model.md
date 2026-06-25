# Data Model — Phase 5: OAuth / Claude Connectors

**Date**: 2026-06-18 | **Branch**: `005-oauth-connectors`

This phase introduces 3 entity surfaces (2 new tables + 1 extended) +
1 transient shape. All persisted hashes are SHA-256 hex (64 chars,
lowercase). Raw codes / tokens / client_secrets are NEVER persisted.

---

## E1 — Authorization Code

**Table**: `{wpdb->prefix}acrossai_mcp_cli_auth_logs` (existing
Phase 2 schema, extended this phase with 4 columns)
**Owned by**: `Includes\Database\CliAuthLog\Table` (extended)
**Accessed by**: `Includes\Database\CliAuthLog\Query` (existing, used
  for both CLI auth log AND OAuth code rows — discriminated by `status`)

### New columns added this phase

| Field | Type | Notes |
|---|---|---|
| `redirect_uri` | `VARCHAR(500)` | Exact value from authorize request; token endpoint compares verbatim |
| `code_challenge` | `CHAR(43)` | base64url(sha256(verifier)); FR-002 fixed length |
| `code_challenge_method` | `VARCHAR(16)` | Only `'S256'` accepted per spec Assumption |
| `scope` | `VARCHAR(64)` | Only `'mcp'` accepted per spec Assumption |

### Row discrimination

The CliAuthLog table now hosts two row types:
- `status = 'approved' | 'success' | 'failed'` — original CLI auth flow
- `status = 'oauth_code_issued'` — OAuth flow this phase

### State transitions

```text
issued (status='oauth_code_issued', redeemed_at=NULL, expires_at=now+600s)
  ├─ redeem → redeemed (redeemed_at=now)
  ├─ expire → expired-implicit (created_at+600s < now())
  └─ replay → replayed (second redeem → FR-014 anti-replay; rejects + revokes all child tokens)
```

DB column `redeemed_at` is `completed_at` (existing CliAuthLog column,
re-purposed for OAuth rows).

### Validation rules

- Unique constraint on `auth_code_hash` (no two codes share a hash)
- `redirect_uri` MUST match the stored `claude_connector_redirect_uri`
  of the resolved server row byte-for-byte at issue time AND at token
  exchange time (FR-004, FR-012 step 6)
- Cleanup (FR-019c): delete where `created_at + 600s + 86400s < now()`
  — codes purged 24h after their 10-min expiry

---

## E2 — Access Token

**Table**: `{wpdb->prefix}acrossai_mcp_oauth_tokens` (NEW this phase)
**Owned by**: `Includes\Database\OAuthToken\Table`
**Accessed by**: `Includes\Database\OAuthToken\Query`

### Schema

| Field | Type | Notes |
|---|---|---|
| `id` | `BIGINT(20) UNSIGNED PRIMARY KEY AUTO_INCREMENT` | |
| `access_token_hash` | `CHAR(64) NOT NULL UNIQUE` | SHA-256 hex |
| `server_id` | `BIGINT(20) UNSIGNED NOT NULL` | FK to `acrossai_mcp_servers.id` (no SQL FK constraint — WordPress convention) |
| `user_id` | `BIGINT(20) UNSIGNED NOT NULL` | FK to `wp_users.id` |
| `scope` | `VARCHAR(64) NOT NULL DEFAULT 'mcp'` | |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | |
| `expires_at` | `DATETIME NOT NULL` | now+3600s by default; filterable |
| `revoked_at` | `DATETIME NULL DEFAULT NULL` | Set non-null on FR-014 anti-replay revocation |

Indexes: PK on `id`; UNIQUE on `access_token_hash`; KEY
`(server_id, expires_at)` for the Bearer-resolved fast path; KEY
`(user_id, created_at)` for "show my active tokens" UIs.

### State transitions

```text
issued (revoked_at=NULL, expires_at>now)
  ├─ expire → expired-implicit (expires_at < now())
  └─ revoke → revoked (revoked_at=now) — FR-014 anti-replay
```

### Validation rules

- Bearer-auth lookup MUST require `revoked_at IS NULL AND expires_at >
  NOW()` AND `server_id = <request-derived>` (FR-015 cross-server
  defense)
- Cleanup (FR-019c): delete where
  `(expires_at < now() - 7d) OR (revoked_at < now() - 7d)` — 7-day
  grace after expiry/revocation for forensics

---

## E3 — Audit Event

**Table**: `{wpdb->prefix}acrossai_mcp_oauth_audit` (NEW this phase)
**Owned by**: `Includes\Database\OAuthAudit\Table`
**Accessed by**: `Includes\Database\OAuthAudit\Query`

### Schema

| Field | Type | Notes |
|---|---|---|
| `id` | `BIGINT(20) UNSIGNED PRIMARY KEY AUTO_INCREMENT` | |
| `event_type` | `VARCHAR(64) NOT NULL` | Enum-locked; see research.md R5 |
| `server_id` | `BIGINT(20) UNSIGNED NULL` | NULL when event fired before server resolution |
| `user_id` | `BIGINT(20) UNSIGNED NULL` | NULL when event fired before user resolution |
| `client_id` | `VARCHAR(255) NULL` | Raw client_id from request — useful for forensics even when unresolved |
| `token_hash_prefix` | `CHAR(8) NULL` | First 8 hex chars of token's SHA-256 — log-correlation without leaking full hash |
| `endpoint` | `VARCHAR(255) NULL` | Request path for Bearer-related events |
| `details_json` | `TEXT NULL` | Event-specific structured data; NEVER raw codes/tokens/secrets |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | |

Indexes: PK on `id`; KEY `(event_type, created_at)` for
"how many rate-limit hits today?"; KEY `(server_id, created_at)` for
per-server admin forensics; KEY `(user_id, created_at)` for
per-user audit trails.

### State transitions

**None.** Append-only. No updates. No application-level deletes.
Cleanup (FR-019c): delete where `created_at < now() - 90d`.

### Privacy boundary

- `token_hash_prefix` (8 hex chars) is the only token-derived field
  written. The 8-char prefix allows log correlation against the
  tokens table while preserving the bulk of the hash's entropy as
  a secret.
- `details_json` MUST NEVER contain `client_secret`, raw `code`, raw
  `access_token`, or full token hashes — only event-specific structured
  metadata that's safe to log (e.g., `{"expected_redirect": "https://…",
  "received_redirect": "http://…"}` for `failed_redirect_mismatch`).

---

## E4 — Rate-limit Counter (transient, in-memory)

**Storage**: WordPress transient API (object cache when available;
options table fallback)
**Owned by**: `Includes\OAuth\Storage::rate_limit_*` methods

### Shape

Two transients per `(client_id, IP)` tuple:

| Key | TTL | Value |
|---|---|---|
| `oauth_rate_<sha256(cid|ip|YYYY-MM-DD-HH-MM)>` | 60s | `int` — failure count this minute |
| `oauth_rate_<sha256(cid|ip|YYYY-MM-DD-HH)>` | 3600s | `int` — failure count this hour |

### State transitions

```text
miss → 1 (on first failed validation)
N → N+1 (on subsequent failures within bucket)
N → 0 (on successful token exchange — reset both buckets)
bucket expires → next read returns miss → 1
```

### Validation rules

- Threshold A: minute-bucket count ≥ 5 → HTTP 429 + `Retry-After: 60`
- Threshold B: hour-bucket count ≥ 50 → HTTP 429 + `Retry-After: 3600`
- Both thresholds checked BEFORE the FR-012 validation chain so the
  rejected request can't probe per-step error messages

### Concurrency note

Use `wp_cache_incr()` for atomic increments when available; falls back
to `get + set` race-loss-acceptable under heavy load (worst case: one
extra failure per attacker per second slips through, which is
negligible against the threshold A bound).
