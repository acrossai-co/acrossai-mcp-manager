# REST Contract — POST /oauth/revoke-client-tokens

**Route**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/revoke-client-tokens`
**Auth**: `manage_options` capability (S2 — unchanged from F021)
**Nonce**: `acrossai_mcp_manager_connector` (unchanged from F024)
**Handler**: `ConnectorAdminController::handle_revoke_client_tokens( \WP_REST_Request $request )`
**F032 change**: Requires new `server_id` param. Returns 403 on server_id ↔ client_id mismatch. Fires observability action on every 403.

## Request

```json
{
  "server_id": 1,
  "client_id": "server-1-claude-ai-abc123"
}
```

| Field | Type | Required | Constraints |
|---|---|---|---|
| `server_id` | integer | **YES (F032 NEW)** | > 0; sanitized via `(int)` cast |
| `client_id` | string | YES | Non-empty; sanitized via `self::sanitize_client_id()` (existing F021 helper) |

## Responses

### 200 OK — Tokens Revoked (own-server valid request)

```json
{
  "revoked_count": 3
}
```

`revoked_count` is the number of tokens updated to `revoked = 1` by `TokensQuery::revoke_by_client_id( $client_id, $server_id )`. Zero is a valid response (client exists but has no active tokens).

### 400 Bad Request — Missing/invalid `server_id` or `client_id`

```json
{
  "code": "invalid_request",
  "message": "Missing server_id or client_id.",
  "data": { "status": 400 }
}
```

Triggered when `$server_id <= 0` OR `$client_id === ''`. Distinct from cross-server case — the missing-param path is a genuine request-formation error, not authorization.

### 403 Forbidden — Cross-server mismatch (NEW F032)

```json
{
  "code": "acrossai_mcp_oauth_cross_server",
  "message": "This client does not belong to the specified server.",
  "data": { "status": 403 }
}
```

Triggered when `ClientsQuery::find_by_client_id_and_server_id( $client_id, $server_id )` returns `null` — i.e., no row exists with the (client_id, server_id) composite. The response body does NOT confirm whether the client exists on any other server (403 is opaque to cross-server existence per R8).

**Side effect (FR-023)**: BEFORE returning the WP_Error, the handler MUST fire:

```php
do_action(
    'acrossai_mcp_oauth_cross_server_attempted',
    string  $client_id,          // exactly as submitted
    int     $server_id,          // requested (from body)
    int     $user_id,            // get_current_user_id()
    int     $timestamp           // time()
);
```

Note the 4-arg signature (per SEC-032-001 remediation, 2026-07-21) — the action does NOT include the actual owning `server_id` of the requested client. Emitting that to any listener would recreate a cross-server oracle (any WordPress plugin can hook any action; the observability action's argument set would leak `client_id → owning_server_id` mapping to hostile plugins). Operators who need the owning server for forensic analysis can query the DB directly from within their listener.

The action is fire-and-forget; the plugin does NOT require a listener. Operators may attach any logger (Query Monitor, custom audit table, syslog, webhook).

### 403 Forbidden — Nonce/capability failure (existing shape)

Standard WordPress REST 403 when `manage_options` or nonce check fails. Unchanged from F024.

## Test Cases

| Test ID | Scenario | Expected |
|---|---|---|
| RCT-001 | Valid own-server request | 200 + revoked_count matches DB count |
| RCT-002 | Missing `server_id` (body has only client_id) | 400 `invalid_request` |
| RCT-003 | Empty string `client_id` | 400 `invalid_request` |
| RCT-004 | Cross-server (server_id=1, client_id belongs to server 2) | 403 `acrossai_mcp_oauth_cross_server` + observability fires with 4-arg signature (no owning-server disclosure) |
| RCT-005 | Non-existent client_id anywhere | 403 `acrossai_mcp_oauth_cross_server` (indistinguishable from cross-server per R8) + observability fires with same 4-arg signature |
| RCT-006 | Valid request but no active tokens | 200 + `revoked_count: 0` |
| RCT-007 | Invalid nonce | 403 (standard WP REST shape) |
| RCT-008 | Subscriber-role caller | 403 (standard WP REST shape) |
