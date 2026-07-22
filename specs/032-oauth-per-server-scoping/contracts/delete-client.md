# REST Contract — POST /oauth/delete-client

**Route**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/delete-client`
**Auth**: `manage_options` capability (S2 — unchanged from F021)
**Nonce**: `acrossai_mcp_manager_connector` (unchanged from F024)
**Handler**: `ConnectorAdminController::handle_delete_client( \WP_REST_Request $request )`
**F032 change**: Requires new `server_id` param. Returns 403 on server_id ↔ client_id mismatch. Fires observability action on every 403.

## Request

```json
{
  "server_id": 1,
  "client_id": "server-1-chatgpt-xyz789"
}
```

Same schema as `revoke-client-tokens` (see that contract for field constraints).

## Responses

### 200 OK — Client Deleted

```json
{
  "deleted": true,
  "revoked_token_count": 5
}
```

Client row deleted from `oauth_clients`; associated tokens revoked (cascade-style: same server-scoped `revoke_by_client_id` call runs before the DELETE). `revoked_token_count` is the number of tokens revoked as part of the delete cascade.

### 400 Bad Request

Same shape as `revoke-client-tokens` for missing/invalid params.

### 403 Forbidden — Cross-server mismatch (NEW F032)

```json
{
  "code": "acrossai_mcp_oauth_cross_server",
  "message": "This client does not belong to the specified server.",
  "data": { "status": 403 }
}
```

Same trigger + side effect as `revoke-client-tokens` — fires `acrossai_mcp_oauth_cross_server_attempted` (4-arg signature per SEC-032-001 remediation: `$client_id, $server_id_requested, $user_id, $timestamp`) before returning the WP_Error.

## Test Cases

| Test ID | Scenario | Expected |
|---|---|---|
| DC-001 | Valid own-server delete | 200 + client row absent post-request |
| DC-002 | Cross-server delete attempt | 403 + observability fires + target row unchanged |
| DC-003 | Delete of client with 0 tokens | 200 + `revoked_token_count: 0` |
| DC-004 | Delete of client with N tokens | 200 + `revoked_token_count: N` + all tokens `revoked = 1` |
| DC-005 | Missing `server_id` | 400 `invalid_request` |
