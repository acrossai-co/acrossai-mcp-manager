# Contract — Auth Status (poll approval state)

**Date**: 2026-06-25 | **FR**: FR-003

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/wp-json/acrossai-mcp-manager/v1/auth/status` |
| Method | `GET` |
| Auth | none (`permission_callback: __return_true`) |
| `Content-Type` (response) | `application/json` |

## Query parameters — required

| Param | Type | Validation |
|---|---|---|
| `code` | `string` | `sanitize_text_field` |
| `server` | `string` | `sanitize_text_field` |

## Success responses — HTTP 200

### Pending (or server-mismatch oracle-defense)

```json
{ "approved": false }
```

### Approved

```json
{
  "approved": true,
  "token": "1f2e3d4c5b6a7980716253443536271809"
}
```

| Field | Type | Source |
|---|---|---|
| `approved` | `bool` | `true` only when transient `status === 'approved'` AND server matches |
| `token` | `string` (32 hex chars) | the stored `session_token` from the transient (when approved) |

## Server-side handler

`Includes\REST\CliController::handle_auth_status( WP_REST_Request $request )`:

```php
$code   = sanitize_text_field( (string) $request->get_param( 'code' ) );
$server = sanitize_text_field( (string) $request->get_param( 'server' ) );

$payload = get_transient( self::AUTH_TRANSIENT_PREFIX . $code );
if ( false === $payload || ! is_array( $payload ) ) {
    return new \WP_Error( 'auth_code_not_found', 'Authorization code not found.', array( 'status' => 404 ) );
}

if ( 'approved' === ( $payload['status'] ?? '' )
     && hash_equals( (string) ( $payload['server_id'] ?? '' ), $server )
) {
    return new \WP_REST_Response( array(
        'approved' => true,
        'token'    => (string) ( $payload['session_token'] ?? '' ),
    ), 200 );
}

return new \WP_REST_Response( array( 'approved' => false ), 200 );
```

## Negative paths

| Scenario | Response |
|---|---|
| `code` query param missing | HTTP 400 `rest_missing_callback_param` (WP REST default) |
| `server` query param missing | HTTP 400 `rest_missing_callback_param` |
| Transient absent (expired or never issued) | HTTP 404 `{"code":"auth_code_not_found", ...}` |
| Transient present, `status === 'pending'` | HTTP 200 `{"approved": false}` |
| Transient present, `status === 'approved'` but `server` mismatches stored `server_id` | HTTP 200 `{"approved": false}` (oracle-defense — same shape as pending; does NOT leak "code exists for a different server") |

## Test invariants

- The polling endpoint MUST return `{"approved": false}` (HTTP 200) when the server mismatches, NOT 404. Asserted by `tests/phpunit/RestCli/AuthStatusEndpointTest::test_server_mismatch_returns_pending_no_oracle`.
- `hash_equals` is used for the server comparison (constant-time defense-in-depth). Asserted by `tests/phpunit/RestCli/AuthStatusEndpointTest::test_server_comparison_uses_hash_equals` (via reflective grep of the implementation).
- Multiple consecutive `/auth/status` calls do NOT consume the transient — the endpoint is idempotent until `/auth/exchange` deletes the transient.

## Golden fixtures

`tests/phpunit/RestCli/fixtures/auth-status-pending.json`:
```json
{ "approved": false }
```

`tests/phpunit/RestCli/fixtures/auth-status-approved.json`:
```json
{
  "approved": true,
  "token": "{SESSION_TOKEN}"
}
```
