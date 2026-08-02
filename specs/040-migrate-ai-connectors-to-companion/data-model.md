# Data Model: Preserved Invariants Reference

**Feature**: 040-migrate-ai-connectors-to-companion
**Phase**: 1 (Design & Contracts)
**Purpose**: Document the data-layer artifacts this feature EXPLICITLY DOES NOT TOUCH so future maintainers have a single-place-to-look for "did feature 040 preserve X?"

This feature adds no new tables, columns, options, or data structures. It only **preserves** the existing OAuth-related data under new ownership (companion plugin). This document is prescriptive: any change to the entities below during implementation is a spec violation.

---

## Preserved BerlinDB Tables (4)

The companion `acrossai-ai-connectors` v0.5.0 has been audited to declare byte-identical Table subclasses (same `$name`, `$version`, `$db_version_key`) as mcp-manager's current implementation. BerlinDB's `maybe_upgrade()` is therefore a no-op when the companion takes over — no ALTER, no DROP.

### 1. `{wpdb->prefix}acrossai_mcp_oauth_clients`

| Field | Companion value | mcp-manager (current) | Match? |
|-------|-----------------|----------------------|--------|
| `$name` | `acrossai_mcp_oauth_clients` | `acrossai_mcp_oauth_clients` | ✅ |
| `$version` | `1.0.1` | `1.0.1` | ✅ |
| `$db_version_key` | `acrossai_mcp_oauth_clients_db_version` | `acrossai_mcp_oauth_clients_db_version` | ✅ |

Represents: OAuth registered clients (DCR-registered or admin-generated). Stores `client_id`, hashed `client_secret_hash`, `redirect_uris`, `connector_slug`, `server_id`, etc.
Written by: companion post-migration.
Read by: companion post-migration.

### 2. `{wpdb->prefix}acrossai_mcp_oauth_tokens`

| Field | Companion value | mcp-manager (current) | Match? |
|-------|-----------------|----------------------|--------|
| `$name` | `acrossai_mcp_oauth_tokens` | `acrossai_mcp_oauth_tokens` | ✅ |
| `$version` | `1.0.1` | `1.0.1` | ✅ |
| `$db_version_key` | `acrossai_mcp_oauth_tokens_db_version` | `acrossai_mcp_oauth_tokens_db_version` | ✅ |

Represents: OAuth access + refresh tokens. Stores SHA-256 `token_hash` (never plaintext), `user_id`, `client_id`, `scope`, `expires_at`, refresh-token family info.
Written by: companion post-migration.
Read by: companion post-migration.

### 3. `{wpdb->prefix}acrossai_mcp_oauth_auth_codes`

| Field | Companion value | mcp-manager (current) | Match? |
|-------|-----------------|----------------------|--------|
| `$name` | `acrossai_mcp_oauth_auth_codes` | `acrossai_mcp_oauth_auth_codes` | ✅ |
| `$version` | `1.0.1` | `1.0.1` | ✅ |
| `$db_version_key` | `acrossai_mcp_oauth_auth_codes_db_version` | `acrossai_mcp_oauth_auth_codes_db_version` | ✅ |

Represents: OAuth authorization codes (short-lived, ~10min, PKCE-bound). Stores SHA-256 `code_hash`, `client_id`, `user_id`, `code_challenge`, `code_challenge_method`, `expires_at`, `server_id`.
Written by: companion post-migration.
Read by: companion post-migration (single-use — consumed at token exchange).

### 4. `{wpdb->prefix}acrossai_mcp_connector_approved_users`

| Field | Companion value | mcp-manager (current) | Match? |
|-------|-----------------|----------------------|--------|
| `$name` | `acrossai_mcp_connector_approved_users` | `acrossai_mcp_connector_approved_users` | ✅ |
| `$version` | `1.0.0` | `1.0.0` | ✅ |
| `$db_version_key` | `acrossai_mcp_connector_approved_users_db_version` | `acrossai_mcp_connector_approved_users_db_version` | ✅ |

Represents: Per-connector explicit-allowlist entries for the "explicit allowlist" access-control mode. Stores `connector_slug`, `user_id`, `server_id`, approval timestamps.
Written by: companion post-migration.
Read by: companion at auth-code exchange (permission gate).

---

## Preserved Cron Event (1)

| Hook name | Recurrence | Original scheduler | Post-migration scheduler |
|-----------|-----------|-------------------|-------------------------|
| `acrossai_mcp_manager_oauth_cleanup` | daily | mcp-manager's `Activator.php` (being deleted) + `Main.php` (also being unwired) | companion's `Activator.php` + `Main.php` (audited PASS) |

Callback: `\AcrossAI_AI_Connectors\Includes\OAuth\Cleanup::instance()->run()` post-migration (companion). Deletes expired auth codes + expired access tokens + expired refresh tokens.

**Invariant**: hook name MUST NOT change (FR-004). Any rename orphans the pre-existing scheduled event on premium-user sites that already have it registered.

---

## Preserved Option Keys (1 prefix)

| Option name pattern | Purpose | Written/read by |
|---------------------|---------|-----------------|
| `acrossai_mcp_connector_%` | Per-connector-profile settings: enabled state, redirect-URI whitelist overrides, access-control mode selection, etc. | companion post-migration |

**Invariant**: The `acrossai_mcp_%` LIKE-sweep retained in mcp-manager's `uninstall.php` MUST NOT match `acrossai_mcp_connector_%` keys (FR-003). Recommended narrower pattern OR explicit exclusion clause.

---

## Preserved REST Namespace (1)

| Namespace | Registered where post-migration |
|-----------|--------------------------------|
| `acrossai-mcp-manager/v1` | Companion registers all OAuth routes under this namespace (NOT `acrossai-ai-connectors/v1`) |

**Invariant**: Namespace MUST NOT change (FR-005). In-field DCR clients depend on `.well-known/oauth-authorization-server` returning `authorization_endpoint`, `token_endpoint`, `registration_endpoint` URLs under this exact namespace for RFC 8414 discovery compatibility.

---

## Preserved Extension Filter (1)

| Filter | Fired by | Post-migration hooked by |
|--------|---------|-------------------------|
| `acrossai_mcp_manager_server_tabs` | mcp-manager's `Registry.php::all_tabs()` — preserved unchanged | companion's `Main.php` at priority 35 (audited PASS) |

**Invariant**: This filter's registration site MUST NOT be deleted (FR-009, FR-017). Removing the built-in `AIConnectorsTab` entry from `all_tabs()` (which IS in scope) is different from removing the filter fire (which is NOT in scope).

---

## What This Feature Does NOT Touch

For clarity, the following data artifacts are **explicitly out of scope** — no migration, no rename, no modification:

- The `{wpdb->prefix}acrossai_mcp_servers` table (owned by mcp-manager, unrelated to OAuth).
- Any `acrossai_mcp_%` option that does NOT match `acrossai_mcp_connector_%` (owned by mcp-manager: settings, feature flags, etc. Kept by the `uninstall.php` LIKE-sweep.).
- The `AbstractServerTab` base class (`admin/Partials/ServerTabs/AbstractServerTab.php`) — the companion's `AIConnectorsTab` extends it, so it must stay in mcp-manager.
- The tab framework (`Registry.php`'s filter mechanics, tab discovery/rendering pipeline).
- Any user meta, transient, or option key not explicitly listed above.

**Any deviation from this scope during implementation is a spec violation and MUST be justified via a spec amendment before merge.**
