# Contract — Auth Exchange (issue Application Password)

**Date**: 2026-06-25 | **FR**: FR-005, FR-006, FR-007

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/wp-json/acrossai-mcp-manager/v1/auth/exchange` |
| Method | `POST` |
| Auth | none (`permission_callback: __return_true` — **body-as-credential**, S2 exemption per S7 precedent) |
| `Content-Type` (request) | `application/json` OR `application/x-www-form-urlencoded` |
| `Content-Type` (response) | `application/json` |
| `Cache-Control` (response) | `no-store` |
| `Pragma` (response) | `no-cache` |

## Request body — required fields

| Field | Type | Validation |
|---|---|---|
| `code` | `string` | `sanitize_text_field` |
| `server_id` | `string` | `sanitize_title` (URL-safe slug) |

## Success response — HTTP 200

```json
{
  "app_password": "abcd 1234 efgh 5678 ijkl 9012",
  "username": "admin",
  "user_id": 1,
  "expires_in": 2592000,
  "server_id": "wordpress-default-server"
}
```

| Field | Type | Source |
|---|---|---|
| `app_password` | `string` | Element 0 of `WP_Application_Passwords::create_new_application_password()` return (R4) — raw password, spaces included |
| `username` | `string` | `$user->user_login` from `get_userdata( $user_id )` |
| `user_id` | `int` | from the validated transient |
| `expires_in` | `int` | always `2592000` (30 days — WP-core default) |
| `server_id` | `string` | echoed from the validated request body |

## Validation chain (FR-006, order is load-bearing)

```
Step 0 — Content-Type allow-list check (per FR-015 / Q2 Clarification)
  Allowed: application/json OR application/x-www-form-urlencoded (each with optional ;charset=...)
  Missing header OR any other value → HTTP 400 { "error": "invalid_request" }
  Runs BEFORE field validation so attackers cannot probe per-step error envelopes
  via malformed bodies under bogus Content-Types.

Step 1 — Read transient acrossai_cli_auth_<code>
  Absent → HTTP 400 { "error": "invalid_code" }

Step 2 — Status check
  status !== 'approved' → HTTP 400 { "error": "not_approved" }

Step 3 — User existence
  get_userdata( stored_user_id ) === false → HTTP 400 { "error": "invalid_user" }

Step 4 — WP-Apps capability
  class_exists( 'WP_Application_Passwords' ) === false → HTTP 501 { "error": "not_supported" }
  (Audit row is NOT written for this case.)

Step 5 — server_id present
  request server_id empty → HTTP 400 { "error": "missing_server" }

Step 6 — server_id matches transient
  hash_equals( $stored_server_id, $request_server_id ) === false → HTTP 400 { "error": "server_mismatch" }
  (Transients NOT deleted — legitimate retry possible.)

Step 7 — server_id resolves to enabled MCP server
  MCPServer\Query::query( ['server_slug' => $server_id, 'is_enabled' => 1, 'number' => 1] ) empty → HTTP 403 { "error": "invalid_server" }

Step 8 (success path) — create + delete + audit + respond
  • $result = WP_Application_Passwords::create_new_application_password(
        $user_id,
        [ 'name' => 'AcrossAI MCP Manager CLI - ' . $server_slug . ' - ' . substr( $code, 0, 8 ) ]
    )    // per Q3 — code prefix suffix for uniqueness
  • if is_wp_error( $result ) → HTTP 500 { "error": "server_error" } (transients NOT deleted)
  • delete_transient( AUTH_TRANSIENT_PREFIX . $code )
  • delete_transient( SESSION_TRANSIENT_PREFIX . $session_token )
  • try { CliAuthLog\Query::record_success( $user_id, $server_id, sha256($code) ); } catch (\Throwable) { error_log(...); }
  • return HTTP 200 + success envelope (above)
```

## Error response envelope

Every error response (HTTP 400 / 403 / 500 / 501) has shape:

```json
{ "error": "<error_code>" }
```

| `error` code | HTTP | Step | Notes |
|---|---|---|---|
| `invalid_request` | 400 | 0 | Content-Type missing or not in allow-list (`application/json`, `application/x-www-form-urlencoded`) per FR-015 / Q2 |
| `invalid_code` | 400 | 1 | Transient missing (expired or never issued) |
| `not_approved` | 400 | 2 | Transient exists but status is `pending` |
| `invalid_user` | 400 | 3 | User deleted between approval and exchange |
| `not_supported` | 501 | 4 | WP installation lacks `WP_Application_Passwords` |
| `missing_server` | 400 | 5 | Request body lacks `server_id` |
| `server_mismatch` | 400 | 6 | Request body `server_id` ≠ transient's stored `server_id` |
| `invalid_server` | 403 | 7 | `server_id` doesn't resolve to an enabled server row |
| `server_error` | 500 | 8a | `random_bytes` / `WP_Application_Passwords::create_new_application_password` failed |

## S2 exemption documentation

`permission_callback: __return_true` violates memory rule S2 literally ("never `__return_true` on mutating routes"). The exemption is RFC-OAuth-precedent inherited from Phase 5's S7 (token endpoint) AND extended: the body's `code` field IS the authentication credential. The validation chain inside the callback preserves S2's intent — no Application Password is created without a valid code AND a server-binding match AND a real user.

**Capture queued post-implementation**: S8 — "Body-authenticated mutating REST routes are exempt from S2 when the mutation is bounded AND no PII is involved in the failure paths." Broader than S7 (OAuth-specific) — covers CLI device-code-grant-style flows generally.

## Golden fixtures

`tests/phpunit/RestCli/fixtures/auth-exchange-success.json`:
```json
{
  "app_password": "{APP_PASSWORD}",
  "username": "{USERNAME}",
  "user_id": {USER_ID},
  "expires_in": 2592000,
  "server_id": "{SERVER_ID}"
}
```

Plus 7 error fixtures (one per `error` code above):
- `auth-exchange-error-invalid_code.json` → `{"error":"invalid_code"}`
- `auth-exchange-error-not_approved.json` → `{"error":"not_approved"}`
- `auth-exchange-error-invalid_user.json` → `{"error":"invalid_user"}`
- `auth-exchange-error-not_supported.json` → `{"error":"not_supported"}`
- `auth-exchange-error-missing_server.json` → `{"error":"missing_server"}`
- `auth-exchange-error-server_mismatch.json` → `{"error":"server_mismatch"}`
- `auth-exchange-error-invalid_server.json` → `{"error":"invalid_server"}`

## Test invariants

- After a successful exchange, calling `/auth/exchange` AGAIN with the same `code` returns 400 `invalid_code` (single-use enforcement). PHPUnit `AuthExchangeEndpointTest::test_single_use_after_success`.
- After step-6 (`server_mismatch`), the transients are STILL present and the next call with the correct server_id MUST succeed. PHPUnit `AuthExchangeEndpointTest::test_server_mismatch_preserves_transients`.
- After step-4 (`not_supported`), NO audit row is written. PHPUnit `AuthExchangeEndpointTest::test_not_supported_no_audit`.
- After step-8a (WP-Apps creation failure), transients are NOT deleted (legitimate retry path). PHPUnit `AuthExchangeEndpointTest::test_wp_apps_failure_preserves_transients`.
