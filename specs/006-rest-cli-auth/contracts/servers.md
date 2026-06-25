# Contract — Server Inventory (Bearer-auth)

**Date**: 2026-06-25 | **FR**: FR-004

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/wp-json/acrossai-mcp-manager/v1/servers` |
| Method | `GET` |
| Auth | Bearer session_token (`permission_callback: verify_session_token`) |
| `Content-Type` (response) | `application/json` |
| `Cache-Control` (response) | `private, no-store` (response is user-scoped) |

## Request — required headers

| Header | Format |
|---|---|
| `Authorization` | `Bearer <session_token>` (32 hex chars; case-insensitive `Bearer` prefix) |

## Success response — HTTP 200

Per **Q4 Clarification** the session token is bound to ONE `server_id` (the one the admin consented to in `/auth/start`). This endpoint returns AT MOST one entry in `servers` — the consented server.

```json
{
  "servers": [
    {
      "id": 5,
      "name": "WordPress Default Server",
      "description": "Default WP REST + MCP adapter bridge",
      "enabled": true,
      "version": "v1.0.0",
      "namespace": "mcp",
      "route": "wordpress-default-server",
      "mcp_url": "https://example.com/wp-json/mcp/wordpress-default-server"
    }
  ]
}
```

| Field | Type | Source |
|---|---|---|
| `id` | `int` | `$row->id` (PK) |
| `name` | `string` | `$row->server_name` |
| `description` | `string` | `$row->description` |
| `enabled` | `bool` | `(bool) $row->is_enabled` |
| `version` | `string` | `$row->server_version` |
| `namespace` | `string` | `$row->server_route_namespace` |
| `route` | `string` | `$row->server_route` |
| `mcp_url` | `string` | `rest_url( $row->server_route_namespace . '/' . $row->server_route )` |

Empty success: `{"servers": []}` — HTTP 200 (NOT an error).

## Permission callback (FR-004)

`Includes\REST\CliController::verify_session_token( WP_REST_Request $request )`:

```php
$header = $_SERVER['HTTP_AUTHORIZATION']
       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
       ?? '';

if ( 0 !== stripos( $header, 'Bearer ' ) ) {
    return new \WP_Error( 'rest_unauthorized', 'Missing Bearer token.', array( 'status' => 401 ) );
}

$token = trim( substr( $header, 7 ) );
if ( '' === $token || strlen( $token ) > 64 ) {
    return new \WP_Error( 'rest_unauthorized', 'Malformed Bearer token.', array( 'status' => 401 ) );
}

// Per Q4 — payload is array{user_id: int, server_id: string}
$payload = get_transient( self::SESSION_TRANSIENT_PREFIX . $token );
if ( ! is_array( $payload )
     || ! isset( $payload['user_id'], $payload['server_id'] )
     || ! is_numeric( $payload['user_id'] )
) {
    return new \WP_Error( 'rest_unauthorized', 'Invalid or expired session token.', array( 'status' => 401 ) );
}

wp_set_current_user( (int) $payload['user_id'] );
$request->set_param( '_bound_server_id', (string) $payload['server_id'] );
return true;
```

## Endpoint body (refreshed per Q4 — single-server lookup)

```php
// Bound server_id was stashed by verify_session_token() — read it back.
$bound_server_id = (string) $request->get_param( '_bound_server_id' );

$query = new \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query();
$rows  = $query->query( array(
    'server_slug' => $bound_server_id,   // ← single-server constraint per Q4
    'is_enabled'  => 1,
    'number'      => 1,
) );

// Server was disabled or deleted between approval and /servers call.
if ( empty( $rows ) ) {
    return new \WP_REST_Response( array( 'servers' => array() ), 200 );
}

$row     = $rows[0];
$user_id = get_current_user_id();
$ns      = (string) $row->server_route_namespace;
$route   = (string) $row->server_route;

// Apply AccessControlManager filter on the single row (if present).
if ( class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
    $allowed = \WPBoilerplate\AccessControl\AccessControlManager::instance()
        ->user_has_access( $user_id, $ns, $route );
    if ( ! $allowed ) {
        return new \WP_REST_Response( array( 'servers' => array() ), 200 );
    }
}

return new \WP_REST_Response(
    array(
        'servers' => array(
            array(
                'id'          => (int) $row->id,
                'name'        => (string) $row->server_name,
                'description' => (string) $row->description,
                'enabled'     => (bool) $row->is_enabled,
                'version'     => (string) $row->server_version,
                'namespace'   => $ns,
                'route'       => $route,
                'mcp_url'     => rest_url( $ns . '/' . $route ),
            ),
        ),
    ),
    200
);
```

## Negative paths

| Scenario | Response |
|---|---|
| `Authorization` header missing | HTTP 401 `{"code":"rest_unauthorized","message":"Missing Bearer token.", ...}` |
| `Authorization` is `Basic ...` or other non-Bearer scheme | HTTP 401 (same envelope) |
| `Authorization: Bearer <token>` but session token unknown / expired | HTTP 401 (same envelope) |
| Token > 64 chars (pathological input guard) | HTTP 401 (same envelope) |
| Valid token but the bound server was disabled / deleted | HTTP 200 `{"servers": []}` |
| Valid token but AccessControlManager filters the bound server out | HTTP 200 `{"servers": []}` |
| Valid token + AccessControlManager absent + bound server still enabled | HTTP 200 with the SINGLE bound server (graceful degrade per Constitution §V) |

## Test invariants

- The header-parsing fallback from `HTTP_AUTHORIZATION` to `REDIRECT_HTTP_AUTHORIZATION` is exercised. PHPUnit `VerifySessionTokenTest::test_redirect_http_authorization_fallback` (mirror of Phase 5's `BearerHeaderParsingTest`).
- `wp_set_current_user()` is called BEFORE the body runs — `wp_get_current_user()->ID` inside the body MUST equal the granting user's ID. PHPUnit `ServersEndpointTest::test_current_user_is_granting_user`.
- AccessControlManager absence does NOT throw or return 500 — it returns the FULL list. PHPUnit `ServersEndpointTest::test_no_acm_returns_full_list`.
- AccessControlManager presence filters out unauthorized rows. PHPUnit `ServersEndpointTest::test_acm_filters_by_user_access` (with a test-double ACM).

## Golden fixtures

`tests/phpunit/RestCli/fixtures/servers-success.json`:
```json
{
  "servers": [
    {
      "id": {SERVER_ID},
      "name": "{SERVER_NAME}",
      "description": "{DESCRIPTION}",
      "enabled": true,
      "version": "{VERSION}",
      "namespace": "mcp",
      "route": "{ROUTE}",
      "mcp_url": "{MCP_URL}"
    }
  ]
}
```

`tests/phpunit/RestCli/fixtures/servers-unauthorized.json`:
```json
{
  "code": "rest_unauthorized",
  "message": "Missing Bearer token.",
  "data": { "status": 401 }
}
```
