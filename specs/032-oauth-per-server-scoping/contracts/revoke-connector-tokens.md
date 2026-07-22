# REST Contract — POST /oauth/revoke-connector-tokens (nuclear)

**Route**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/revoke-connector-tokens`
**Auth**: `manage_options` capability (unchanged)
**Nonce**: `acrossai_mcp_manager_connector` (unchanged)
**Handler**: `ConnectorAdminController::handle_revoke_connector_tokens( \WP_REST_Request $request )`
**F032 change**: The DCR-filter path (inside `mass_revoke_connector_tokens()`) now filters DCR clients by `server_id` in addition to profile match. Previously the DCR loop matched by connector profile only, causing cross-server revocation.

## Request

```json
{
  "server_id": 1,
  "connector_slug": "claude-desktop"
}
```

| Field | Type | Required | Constraints |
|---|---|---|---|
| `server_id` | integer | YES (was already present in F024) | > 0 |
| `connector_slug` | string | YES | Non-empty; must match a registered connector profile |

## Behaviour (nuclear revoke)

Revokes ALL tokens for ALL clients on `server_id` matching `connector_slug`, across both admin-generated clients (prefix-matched) AND DCR-registered clients (profile-matched). Post-F032, the DCR loop filters via `ClientsQuery::find_dcr_clients( $server_id )` — the DCR clients enumerated MUST be bound to `$server_id`.

## Responses

### 200 OK

```json
{
  "revoked_count": 12,
  "clients_affected": 3
}
```

### 400 Bad Request

Standard shape for missing/invalid `server_id` or `connector_slug`.

### 403 Forbidden

Standard nonce/capability failures. Cross-server case does NOT apply here — the endpoint always operates on `$server_id`; the F032 fix is that the DCR-side enumeration now respects the same scope (was previously scope-leaking silently).

## Test Cases

| Test ID | Scenario | Expected |
|---|---|---|
| RCTn-001 | Server 1 nuclear revoke on Claude Desktop | Only server 1's Claude Desktop tokens revoked (admin + DCR); server 2's untouched |
| RCTn-002 | DCR client on server 2 with same connector_slug | NOT touched by server 1's request (validates F032's DCR-filter fix) |
| RCTn-003 | No matching clients on this server | 200 + `revoked_count: 0`, `clients_affected: 0` |
