# Contract — Auth Start (issue auth_code + auth_url)

**Date**: 2026-06-25 | **FR**: FR-002

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/wp-json/acrossai-mcp-manager/v1/auth/start` |
| Method | `POST` |
| Auth | none (`permission_callback: __return_true` — body-only, mutation is bounded) |
| `Content-Type` (request) | `application/json` OR `application/x-www-form-urlencoded` |

## Request body — required fields

| Field | Type | Validation |
|---|---|---|
| `server_id` | `string` | non-empty after `sanitize_text_field`; identifies the target MCP server SLUG |

## Success response — HTTP 200

```json
{
  "auth_code": "a1b2c3d4e5f60718293a4b5c6d7e8f90",
  "auth_url": "https://example.com/acrossai-mcp-manager/?action=cli_auth&code=a1b2c3d4...&server=wordpress-default-server",
  "expires_in": 300
}
```

| Field | Type | Source |
|---|---|---|
| `auth_code` | `string` (32 hex chars) | `bin2hex( random_bytes( 16 ) )` |
| `auth_url` | `string` | `FrontendAuth::get_base_url() . '?action=cli_auth&code=' . $auth_code . '&server=' . urlencode( $server_id )` |
| `expires_in` | `int` | always `300` (= `CliController::AUTH_CODE_TTL`) |

## Server-side handler

`Includes\REST\CliController::handle_auth_start( WP_REST_Request $request )`:

1. Read `server_id` via `(string) $request->get_param( 'server_id' )` and `sanitize_text_field` it.
2. Generate `$auth_code` via `bin2hex( random_bytes( 16 ) )`.
3. Write transient:
   ```php
   set_transient(
       self::AUTH_TRANSIENT_PREFIX . $auth_code,
       array(
           'server_id'     => $server_id,
           'status'        => 'pending',
           'user_id'       => null,
           'session_token' => null,
           'created_at'    => time(),
       ),
       self::AUTH_CODE_TTL
   );
   ```
4. Compose `auth_url` via `FrontendAuth::get_base_url() . '?' . http_build_query([...])`.
5. Return HTTP 200 with the envelope above.

## Negative paths

| Scenario | Response |
|---|---|
| `server_id` missing or empty | HTTP 400 `{"code": "rest_missing_callback_param", "message": "Missing parameter(s): server_id", ...}` (WP REST default) |
| `random_bytes(16)` throws (entropy unavailable) | HTTP 500 `{"error":"server_error"}` (catch `\Throwable`, log via `error_log()`, do NOT leak `getMessage()`) |
| `set_transient` returns false (storage failure) | HTTP 500 `{"error":"server_error"}` (same log + envelope as above) |

## Golden fixture

`tests/phpunit/RestCli/fixtures/auth-start-success.json`:
```json
{
  "auth_code": "{AUTH_CODE}",
  "auth_url": "{AUTH_URL}",
  "expires_in": 300
}
```

## Test invariants

- The returned `auth_code` matches `/^[a-f0-9]{32}$/` (T080 polish-grep regex).
- The transient `acrossai_cli_auth_<auth_code>` exists post-call with TTL ≤ 300 + 1s clock-drift.
- Calling `/auth/start` twice in succession produces two DIFFERENT codes (collision probability negligible; the test asserts inequality).
