# Contract: kill-switch disabled notice (HTTP 503)

**Path**: any URL under `/acrossai-mcp-manager/` when the `acrossai_mcp_npm_login_enabled` option is falsy (default state)
**Method**: `GET`
**Authentication**: Logged-in user (the unauthenticated path redirects to login BEFORE the kill-switch check runs)
**Mutating**: No
**Reached via**: `maybe_render_page()` → `render_disabled_notice()` after `is_user_logged_in()` succeeds but before action dispatch

## Response (HTTP 503)

*(2026-06-30 amendment per SEC-005 — CWE-1004: added `Retry-After` header and `noindex` meta to prevent search-engine indexing and signal retry timing to caches/clients)*

```text
HTTP/1.1 503 Service Unavailable
Cache-Control: no-cache, must-revalidate, max-age=0
Retry-After: 3600
Content-Type: text/html; charset=UTF-8

<!DOCTYPE html>
<html lang="<bloginfo-language>">
<head>
  <meta charset="utf-8">
  <title>AcrossAI MCP Manager</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <link rel="stylesheet" id="acrossai-mcp-frontend-css" href="<plugin_url>/build/css/frontend.css?ver=<asset_version>">
</head>
<body>
  <h1>CLI Login Not Enabled</h1>
  <p>The CLI login flow is currently disabled on this site. Contact your administrator.</p>
</body>
</html>
```

The `503` status code is emitted via `status_header( 503 )` BEFORE any output. The `Retry-After` value is operator-tunable (3600 = 1 hour default). The `noindex,nofollow` meta tag is emitted inside `<head>` to prevent crawlers from indexing the disabled-notice page if the kill switch is briefly enabled then disabled by mistake.

## Operator action to disable

Default state of the option is `false`. The page is disabled out-of-the-box on a fresh install.

## Operator action to enable

```sh
wp option update acrossai_mcp_npm_login_enabled 1
```

Or programmatically in MU plugin:

```php
update_option( 'acrossai_mcp_npm_login_enabled', true );
```

## Test assertions

- Response code is `503`
- Response body contains `CLI Login Not Enabled`
- Response body does NOT contain any approve buttons or auth-code references (the kill switch short-circuits BEFORE action dispatch)
- Response headers contain `Retry-After: 3600` (2026-06-30 amendment — SEC-005)
- Response body contains `<meta name="robots" content="noindex,nofollow">` (2026-06-30 amendment — SEC-005)
- The dispatch switch is not reached — verify by spying on `handle_cli_auth`, `handle_approve`, `handle_approved` and asserting none are called
