# REST Contract — POST /oauth/register (Dynamic Client Registration)

**Route**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/register`
**Auth**: `__return_true` (RFC 6749 body-auth per S8 — unchanged from F021)
**Handler**: `ClientRegistrationController::handle_register( \WP_REST_Request $request )`
**F032 change**: Requires resolvable RFC 8707 `resource` parameter. Returns 400 `invalid_target` on unresolvable resource. Persists `server_id` on the new client row.

## Request

```json
{
  "client_name": "Claude Desktop",
  "redirect_uris": ["https://claude.ai/api/mcp/auth_callback"],
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "token_endpoint_auth_method": "none",
  "scope": "mcp:read mcp:write",
  "resource": "https://example.com/wp-json/mcp/server-a-slug"
}
```

**F032-new required field**:

| Field | Type | Required | Constraints |
|---|---|---|---|
| `resource` | string (URL) | **YES (F032 NEW REQUIRED)** | RFC 8707 resource indicator; MUST resolve to a registered MCP server via `ClientRegistrationController::resolve_server_id_from_resource_url()` |

All other fields per RFC 7591 (Dynamic Client Registration Protocol), unchanged from F021.

## Responses

### 201 Created

```json
{
  "client_id": "claude-desktop-abc123",
  "client_secret": null,
  "client_name": "Claude Desktop",
  "server_id": 1,
  "token_endpoint_auth_method": "none",
  "grant_types": ["authorization_code", "refresh_token"],
  "redirect_uris": ["https://claude.ai/api/mcp/auth_callback"],
  "registration_client_uri": "..."
}
```

Client secret is `null` for public+PKCE clients (per D27 + F027 default); replaced with a hash-in-DB when `token_endpoint_auth_method` is `client_secret_basic` or `client_secret_post`.

### 400 Bad Request — Unresolvable resource (NEW F032)

```json
{
  "code": "invalid_target",
  "message": "Resource URL does not resolve to a known MCP server.",
  "data": { "status": 400 }
}
```

Triggered when either:
- `resource` param is missing/empty → `"RFC 8707 resource parameter is required."` message
- `resource` param present but `resolve_server_id_from_resource_url( $resource )` returns 0

**No client row is created** in either case (fail-closed).

### 400 Bad Request — Standard RFC 7591 errors

`invalid_client_metadata`, `invalid_redirect_uri`, etc. Unchanged from F021.

### 503 Service Unavailable — Pre-migration race window (NEW F032 per FR-028)

```json
{
  "code": "service_unavailable",
  "message": "Server initialization in progress; please retry in a few seconds.",
  "data": { "status": 503 }
}
```

Triggered when `INFORMATION_SCHEMA.COLUMNS` shows the `server_id` column is not yet present on `wp_acrossai_mcp_oauth_clients` — a rare race window between plugin file replacement and `Main::reconcile_database_schemas()` firing on the next `admin_init@3`. **No client row is created**. Compliant AI-host clients that honour `Retry-After` semantics will succeed on retry once an admin loads any wp-admin page (which triggers migration completion). Prevents legitimate DCR registrations from being silently destroyed by the F032 auto-purge step (per SEC-032-005 remediation).

Per-request cached (`INFORMATION_SCHEMA` lookup runs once per request, not once per registration attempt).

## Resource URL Resolution (per FR-027)

`ClientRegistrationController::resolve_server_id_from_resource_url( string $resource ): int` performs a MANDATORY two-step check:

**Step 1 — Origin verification** (per SEC-032-002 remediation, 2026-07-21):
```php
$resource_parts = wp_parse_url( $resource );
$home_parts     = wp_parse_url( home_url() );
if (
    empty( $resource_parts['scheme'] ) || empty( $resource_parts['host'] )
    || $resource_parts['scheme'] !== $home_parts['scheme']
    || strcasecmp( $resource_parts['host'], $home_parts['host'] ) !== 0
    || ( $resource_parts['port'] ?? null ) !== ( $home_parts['port'] ?? null )
) {
    // Log for observability differentiation from generic path-mismatch.
    do_action( 'acrossai_mcp_oauth_dcr_resource_url_origin_mismatch', $resource, get_current_user_id(), time() );
    return 0;  // Callers convert to WP_Error 'invalid_target' 400.
}
```

**Step 2 — Path resolution**: parses the URL path (e.g., `/wp-json/mcp/server-a-slug`) and looks up the matching server row via `MCPServerQuery::instance()->query()`. Returns 0 if no match. Reuses the route-matching helper from `CurrentServerHolder::capture_from_request()` (A17) for consistent normalization (trailing slash, port differences).

**Attack surface**: without Step 1's origin verification, an attacker who sends a DCR request (body-authenticated per S8, no nonce required) with `resource = "https://evil.attacker.com/wp-json/mcp/server-1-slug"` could resolve to server_id 1 on THIS site — creating a client bound to a phantom origin. Origin verification is the primary defence.

## Same-Name Different-Server Semantic (F032 NEW)

Composite `UNIQUE(client_id, server_id)` means two DCR requests with the same generated `client_id` are IMPOSSIBLE (DCR client_ids are randomly generated). But two DCR requests with the same `client_name = "Claude Desktop"` targeting different servers via different `resource` URLs succeed independently — each produces a distinct `client_id` bound to a distinct `server_id`.

## Test Cases

| Test ID | Scenario | Expected |
|---|---|---|
| DCR-001 | Valid registration with resolvable resource | 201 + client row exists with resolved `server_id` |
| DCR-002 | Missing `resource` param | 400 `invalid_target` + no client row |
| DCR-003 | Empty `resource` param | 400 `invalid_target` + no client row |
| DCR-004 | `resource` URL that doesn't match any server | 400 `invalid_target` + no client row |
| DCR-005 | Same `client_name` registered on two servers (different `resource` URLs) | Both requests succeed → 2 distinct rows with distinct `server_id` (validates FR-021 composite UNIQUE) |
| DCR-006 | RFC 8707 URL with trailing slash / port variance | Resolved via `CurrentServerHolder::capture_from_request()` normalization; matches canonical form |
| DCR-007 | `resource` URL with correct wp-json path but attacker-controlled origin (e.g. `https://evil.com/wp-json/mcp/server-1-slug`) | 400 `invalid_target` + zero client rows created + `acrossai_mcp_oauth_dcr_resource_url_origin_mismatch` action fires (per FR-027 / SEC-032-002 remediation). Verifies origin verification precedes path resolution. |
| DCR-008 | Pre-migration race: `server_id` column absent, DCR request arrives | 503 `service_unavailable` + zero client rows created. After triggering `Main::reconcile_database_schemas()`, retry succeeds with 201 (per FR-028 / SEC-032-005 remediation). |
