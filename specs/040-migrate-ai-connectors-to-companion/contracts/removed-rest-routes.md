# Contract: REST Routes Removed From mcp-manager

**Feature**: 040-migrate-ai-connectors-to-companion
**Phase**: 1 (Design & Contracts)
**Purpose**: Enumerate the REST routes this feature REMOVES from mcp-manager. All URLs listed below continue to be served post-migration by the `acrossai-ai-connectors` companion plugin under the **same URLs** (same REST namespace `acrossai-mcp-manager/v1`, same paths, same HTTP methods, same auth semantics).

This is a migration document, not a new-API design: nothing new is being defined. The contract that MUST hold is **URL-level continuity** — external OAuth clients see zero change.

---

## Routes Removed From mcp-manager (Served by Companion Post-Migration)

### OAuth 2.1 core flow

| Method | URL | Purpose | Auth (post-migration) |
|--------|-----|---------|----------------------|
| `GET`  | `/wp-json/acrossai-mcp-manager/v1/oauth/authorize`   | OAuth authorization endpoint (browser-mediated consent) | Session (`is_user_logged_in()`) — consent-surface exception |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/token`       | Token exchange (auth-code → access-token; refresh) | `__return_true` (validates `client_secret` + PKCE `code_verifier` in body) |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/register`    | Public Dynamic Client Registration (DCR) — RFC 7591 | `__return_true` (rate-limited public endpoint) |

**Note**: `authorize` and `token` are actually served via **rewrite rules** in the companion (dispatched through `OAuthRouter`), not via `register_rest_route`. The URLs are unchanged; the internal dispatch mechanism differs. Discovery clients (RFC 8414) see identical URLs either way.

### Discovery

| Method | URL | Purpose | Auth (post-migration) |
|--------|-----|---------|----------------------|
| `GET`  | `/wp-json/acrossai-mcp-manager/v1/oauth/discovery` | RFC 8414 authorization server metadata | `__return_true` (public) |
| `GET`  | `/.well-known/oauth-authorization-server`           | RFC 8414 well-known alias for the discovery endpoint | `__return_true` (public) |
| `GET`  | `/.well-known/oauth-protected-resource`             | RFC 8707 well-known — server metadata for resource-server clients | `__return_true` (public) |

**Note**: `.well-known/*` URLs are served via rewrite rules by the companion's `OAuthRouter`. mcp-manager currently registers the rewrite rules in its Activator; companion's Activator does the same after migration (audited PASS).

### Admin connector management

| Method | URL | Purpose | Auth |
|--------|-----|---------|------|
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client` | Admin generates a Claude/ChatGPT/Grok DCR client for a connector on a server | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/register`         | (Duplicate above — public DCR endpoint; listed for completeness) | `__return_true` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/connector-settings` | Save per-connector settings | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/revoke-client-tokens` | Revoke all tokens for a client_id | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/delete-client` | Delete a client + cascade revoke | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/revoke-connector-tokens` | Revoke all tokens for a connector on a server | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/revoke-client-tokens-all-servers` | Revoke a client_id's tokens across every server | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/approve-pending-consent` | Explicit-allowlist admin approval for a pending user request | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/deny-pending-consent` | Explicit-allowlist admin denial | `manage_options` |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/revoke-user-approval` | Revoke a previously-granted explicit-allowlist approval | `manage_options` |

**All admin routes preserve their `manage_options` capability check.** Companion's `ConnectorAdminController` registers them under the same namespace with the same `permission_callback` semantics.

---

## Routes NOT Removed (Preserved in mcp-manager)

The `acrossai_mcp_manager_server_tabs` filter registration in `Registry.php` continues to fire. This is not a REST route, but is the extension point through which the companion's `AIConnectorsTab` gets injected into the tab list on `?page=acrossai_mcp_manager&action=edit&server=X` admin pages. **MUST NOT be removed** (FR-017).

All other mcp-manager REST routes (CLI auth, MCP endpoints, servers CRUD, embeds, discovery of connection methods, user-servers shortcode) are unrelated to this feature and remain in mcp-manager.

---

## URL-Level Invariants (Merge-Blocking)

Post-migration verification recipes (see `quickstart.md`) will check ALL of the following. Any failure blocks release:

1. `curl https://site/.well-known/oauth-authorization-server` returns HTTP 200 with a JSON payload whose `authorization_endpoint`, `token_endpoint`, and `registration_endpoint` all point to `/wp-json/acrossai-mcp-manager/v1/oauth/*` URLs — byte-identical to pre-migration.
2. `curl -H 'Authorization: Bearer <pre-migration-token>' https://site/wp-json/acrossai-mcp-manager/v1/mcp/<server-slug>` returns HTTP 200 (not 401) — bearer tokens issued before the migration continue to authenticate.
3. `curl -X POST -H 'Content-Type: application/json' https://site/wp-json/acrossai-mcp-manager/v1/oauth/register -d '{"client_name":"test","redirect_uris":["https://example.com/cb"]}'` returns HTTP 201 with a `client_id` + `client_secret` payload — DCR continues to work.
4. Admin visiting `?page=acrossai_mcp_manager&action=edit&server=1&tab=ai-connectors` (with add-on active) sees the AI Connectors tab UI rendered by the companion — same URL, same visual result.

---

## Rollback Contract

If this feature must be rolled back (e.g., critical bug discovered post-merge), the rollback is **file-level only** — no data migration is required to undo. Rolling back mcp-manager to `0.1.9` restores all deleted files; the companion's self-disable probe re-detects `AuthorizationController` and hands OAuth ownership back to mcp-manager automatically. Bearer tokens continue to work throughout.

This is only possible because of the data-preservation invariants documented in `data-model.md` — no ALTER, no DROP, no rename during the migration means the rollback is a code-only operation.
