# REST + Rewrite-Rule Endpoint Contracts — Feature 021

Feature 021 exposes **six endpoints** across two routing mechanisms:

| Path | Method | Routing | Auth | Owner |
|---|---|---|---|---|
| `/.well-known/oauth-authorization-server` | GET | rewrite | Public | `DiscoveryController::render_authorization_server_metadata` |
| `/.well-known/oauth-protected-resource` | GET | rewrite | Public | `DiscoveryController::render_protected_resource_metadata` |
| `/authorize` | GET, POST | rewrite | Session | `AuthorizationController::handle_get` / `handle_post` |
| `/token` | POST | rewrite | Body-auth (RFC 6749 §2.3.1 / S7) | `TokenController::handle_authorization_code` / `handle_refresh_token` |
| `/wp-json/acrossai-mcp-manager/v1/oauth/register` | POST | REST | Rate-limited (S8) | `ClientRegistrationController::handle_register` |
| `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client` | POST | REST | `manage_options` + nonce | `ClientRegistrationController::handle_admin_generate` |

Rewrite endpoints are routed via `OAuthRouter::register_rewrite_rules()` on the `init` action, with `query_vars` filter adding `acrossai_mcp_oauth`, and `parse_request` dispatch to the appropriate controller.

### CORS policy (SEC-021-007)

| Endpoint | `Access-Control-Allow-Origin` | Rationale |
|---|---|---|
| `/.well-known/oauth-authorization-server` | `*` | Public metadata per RFC 8414 §3. |
| `/.well-known/oauth-protected-resource` | `*` | Public metadata per RFC 9728. |
| `/authorize` | *(none)* | Browser navigation — CORS is irrelevant on redirects. |
| `/token` | *(none)* | Blocks browser JS from posting client_secret + code from arbitrary origins. |
| `/register` (DCR) | *(none)* | Blocks browser-hosted DCR clients from cross-origin registration. Companion plugins wanting to enable this MAY register a documented CORS filter in a future release. |
| `/generate-client` (admin) | *(none)* | Admin-only + nonce + capability — same-origin only. |

---

## GET `/.well-known/oauth-authorization-server` — RFC 8414 metadata

### Response — 200 OK

Content-Type: `application/json` · Cache: `public, max-age=3600` · CORS: `Access-Control-Allow-Origin: *`

```json
{
  "issuer": "https://site.example.com",
  "authorization_endpoint": "https://site.example.com/authorize",
  "token_endpoint": "https://site.example.com/token",
  "registration_endpoint": "https://site.example.com/wp-json/acrossai-mcp-manager/v1/oauth/register",
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "response_types_supported": ["code"],
  "token_endpoint_auth_methods_supported": ["none", "client_secret_post"],
  "code_challenge_methods_supported": ["S256"],
  "scopes_supported": ["mcp"],
  "authorization_response_iss_parameter_supported": true,
  "service_documentation": "https://site.example.com/wp-admin/admin.php?page=acrossai_mcp_manager"
}
```

Field-by-field justification lives in `research.md` + planning-doc §Task-2 `DiscoveryController` block.

---

## GET `/.well-known/oauth-protected-resource` — RFC 9728 metadata

### Query params

| Name | Type | Required | Notes |
|---|---|---|---|
| `resource` | string | optional | Echoes back in the response. Falls back to the default MCP endpoint URL on this site if omitted. |

### Response — 200 OK

```json
{
  "resource": "<echoed from ?resource=... or default>",
  "authorization_servers": ["https://site.example.com"],
  "bearer_methods_supported": ["header"],
  "scopes_supported": ["mcp"]
}
```

---

## `/authorize` — GET renders consent, POST commits

### GET query params (all required unless noted)

| Name | Notes |
|---|---|
| `response_type=code` | Only `code` supported. |
| `client_id` | Must exist in `OAuthClients`. |
| `redirect_uri` | Must byte-match client's registered `redirect_uris` array OR (for admin-generated clients) the connector profile's `get_redirect_uri_whitelist()`. |
| `code_challenge` | 43-char PKCE base64url. |
| `code_challenge_method=S256` | **Mandatory**; `plain` rejected. |
| `state` | Recommended, not required. Echoed back. |
| `scope` | Optional; defaults to `'mcp'`. |
| `resource` | **Mandatory (RFC 8707)**. Must be a URL on this site or loopback. Enforced at call time on the eventual token per §Clarifications Q1. |

### GET responses

- **User not logged in**: `wp_redirect( wp_login_url( <current_authorize_url> ) )`.
- **Invalid PKCE method**: redirect to `redirect_uri` with `error=invalid_request&error_description=PKCE+S256+required&iss=<issuer>`.
- **Invalid client_id**: NEVER redirect to caller-supplied URL. Render an inline error page (400).
- **Invalid redirect_uri**: Same — never redirect to an untrusted URI. Inline error page.
- **Missing/invalid resource**: redirect to `redirect_uri` with `error=invalid_target&iss=<issuer>`.
- **All valid**: render `templates/oauth/consent.php` (HTTP 200 HTML).

### POST body (form-urlencoded)

| Name | Notes |
|---|---|
| `_wpnonce` | From `wp_nonce_field`. Verified via `wp_verify_nonce` or 403. |
| `authorize_action` | `'approve'` or `'deny'`. |
| plus all GET-echoed hidden fields | Re-validated against DB (S9 defense). |

### POST responses

- **Deny**: redirect to `redirect_uri` with `error=access_denied&state=<state>&iss=<issuer>`; fires `acrossai_mcp_manager_oauth_authorization_denied`.
- **Approve**: insert auth code row (SHA-256 hashed + PKCE challenge + resource + user_id + client_id + redirect_uri + scope + TTL 600s); redirect to `redirect_uri` with `code=<raw>&state=<state>&iss=<issuer>`.

---

## POST `/token`

### Grant type: `authorization_code`

Body (form-urlencoded, `application/x-www-form-urlencoded` OR `application/json`):

```
grant_type=authorization_code
code=<raw>
client_id=<id>
code_verifier=<43-128 chars, PKCE>
redirect_uri=<url>
client_secret=<optional; required if client is client_secret_post>
```

### Response — 200 OK

Cache: `no-store, no-cache, must-revalidate` · Pragma: `no-cache`

```json
{
  "access_token": "<64 hex>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "<64 hex>",
  "scope": "mcp",
  "resource": "<the resource URL from the auth code>"
}
```

### Grant type: `refresh_token`

Body:

```
grant_type=refresh_token
refresh_token=<raw>
client_id=<id>
client_secret=<optional; required if client is client_secret_post>
```

### Response — 200 OK (same shape as above, new pair)

The presented refresh token is atomically revoked; the new refresh token carries forward the original `resource` + `scope`.

### Error responses (all with `Cache-Control: no-store`)

- `invalid_grant` — code missing, replayed, expired, PKCE fails, client_id mismatch, redirect_uri mismatch, refresh token missing/expired/revoked.
- `invalid_client` — client_secret mismatch on `client_secret_post` auth.
- `invalid_request` — malformed body, missing required param.
- `unsupported_grant_type` — any grant_type other than the two above.

---

## POST `/wp-json/acrossai-mcp-manager/v1/oauth/register` — RFC 7591 DCR

### Rate limit: 10/IP/60s (S8 body-authenticated exception)

### Body (application/json)

```json
{
  "redirect_uris": ["https://client.example.com/callback"],
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "token_endpoint_auth_method": "client_secret_post",
  "client_name": "Example MCP Client"
}
```

### Validation

- Every `redirect_uri` MUST be HTTPS OR loopback (`127.0.0.1`, `localhost`, `::1` on any port).
- Reject invalid with 400 `error=invalid_redirect_uri`.
- Reject non-JSON Content-Type with 400 `error=invalid_request`.

### Idempotency (FR-022)

Compute `metadata_fingerprint = SHA-256( canonical_json( { redirect_uris, grant_types, response_types, token_endpoint_auth_method } ) )`. If `ClientRepository::find_by_fingerprint( $fingerprint )` returns a row, return that client's metadata; DO NOT insert a new row; DO NOT fire `token_issued` action.

### Response — 201 Created (fresh registration)

```json
{
  "client_id": "<32 hex opaque>",
  "client_secret": "<64 hex opaque, ONCE>",
  "client_id_issued_at": <unix ts>,
  "client_secret_expires_at": 0,
  "redirect_uris": ["..."],
  "grant_types": ["..."],
  "response_types": ["code"],
  "token_endpoint_auth_method": "client_secret_post",
  "client_name": "Example MCP Client"
}
```

### Response — 200 OK (dedup hit — same metadata_fingerprint)

Same shape, without `client_secret` (raw secret is only returnable at first-issue).

### Response — 429 Too Many Requests

`Retry-After: 60` · body: `{"error":"slow_down","error_description":"Rate limit exceeded; retry in 60 seconds."}`

---

## POST `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client` — Admin credential generator

### Permission

`current_user_can( 'manage_options' )` AND `wp_verify_nonce( X-WP-Nonce, 'wp_rest' )` — the endpoint is called from `AIConnectorsTab` via `apiFetch`.

### Body (application/json)

```json
{
  "server_id": 1,
  "connector_slug": "claude-desktop"
}
```

### Behavior

1. Validate `server_id` resolves to an `MCPServer` row.
2. Validate `connector_slug` matches a registered profile via `ConnectorProfileRegistry::get_profile()`.
3. If an existing client exists (`client_id LIKE 'server-{server_id}-{slug}-%'`), bulk-revoke all its tokens via `revoke_by_client_id`.
4. Generate new `client_id = 'server-{server_id}-{slug}-' . bin2hex( random_bytes(4) )` (Q2 format).
5. Generate `client_secret = bin2hex( random_bytes(32) )`; hash to `client_secret_hash`.
6. Insert row with `token_endpoint_auth_method='client_secret_post'`, `redirect_uris` = profile's whitelist, `connector_slug` = slug.
7. Return `{ client_id, client_secret }` (raw, ONCE) + profile's rendered setup instructions.

### Response — 200 OK

```json
{
  "client_id": "server-1-claude-desktop-abc12345",
  "client_secret": "<64 hex opaque, ONCE>",
  "setup_instructions_html": "<HTML from profile.get_setup_instructions()>",
  "regenerated": false
}
```

### Response — 403 Forbidden

Caller lacks `manage_options` OR nonce failed.

### Response — 404 Not Found

`server_id` doesn't resolve OR `connector_slug` not registered.
