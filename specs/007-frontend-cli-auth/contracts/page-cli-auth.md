# Contract: `?action=cli_auth` (consent form)

**Path**: `/acrossai-mcp-manager/?action=cli_auth&code=<code>&server=<server>`
**Method**: `GET`
**Authentication**: Logged-in user (ANY role); unauthenticated redirected to `wp-login.php`
**Mutating**: No

## Success response (HTTP 200)

```text
HTTP/1.1 200 OK
Cache-Control: no-cache, must-revalidate, max-age=0
Expires: Wed, 11 Jan 1984 05:00:00 GMT
Pragma: no-cache
Content-Type: text/html; charset=UTF-8

<!DOCTYPE html>
<html lang="<bloginfo-language>">
<head>
  <meta charset="utf-8">
  <title>AcrossAI MCP Manager</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" id="acrossai-mcp-frontend-css" href="<plugin_url>/build/css/frontend.css?ver=<asset_version>">
  <style>body{font-family:system-ui,sans-serif;max-width:520px;margin:5em auto;padding:0 1em;color:#1d2327}</style>
</head>
<body>
  <h1>Authorize CLI Access</h1>
  <p>A CLI tool is requesting access to your MCP server "<escaped-server-slug>".</p>
  <p>Click Approve to grant the tool access. The session is single-use.</p>
  <p><a class="button button-primary" href="<approve_url>">Approve</a></p>
</body>
</html>
```

Where `<approve_url>` is composed via *(2026-06-30 amendment: per-code nonce per SEC-002; `server` removed from URL per SEC-001)*:

```php
add_query_arg(
    [
        'action'   => 'cli_auth_approve',
        'code'     => $code,
        '_wpnonce' => wp_create_nonce( 'cli_auth_approve_' . $code ),
    ],
    FrontendAuth::get_base_url()
);
```

The displayed `<escaped-server-slug>` in the consent body is sourced from `CliController::peek_pending_server( $code )` — the authoritative `server_id` stored in the transient — NOT from `$_GET['server']`. See SEC-001 / S9.

## Missing-parameters response (HTTP 200)

*(2026-06-30 amendment per SEC-001)*: Triggered by EITHER `$code === ''` OR `CliController::peek_pending_server( $code ) === null` (unknown / expired / non-pending code). The same HTML shell is returned with a different body:

```html
<h1>Missing Authentication Parameters</h1>
<p>This page must be opened via a link from your CLI tool.</p>
```

## Disabled response (HTTP 503) — kill switch

If `acrossai_mcp_npm_login_enabled` option is `false`/missing, the dispatch never reaches `cli_auth`; instead `render_disabled_notice()` is invoked. See `page-disabled-notice.md`.

## Unauthenticated response (HTTP 302)

```text
HTTP/1.1 302 Found
Location: <wp-login.php>?redirect_to=<urlencoded-base-url>
```

Note: `redirect_to` carries the BASE URL only (`/acrossai-mcp-manager/`), not the original `?action=...&code=...` query. See research.md §R3.

## Test assertions

- Response code is `200` for the happy path
- Response body contains literal `Authorize CLI Access`
- Response body contains the escaped server slug
- Response body contains an `href="..."` attribute whose URL contains `action=cli_auth_approve` AND `_wpnonce=` AND the original `code`
- `Cache-Control` header begins with `no-cache, must-revalidate, max-age=0`
- Response body does NOT contain `wp-emoji-release.min.js`, `<link rel="https://api.w.org/">`, or any other `wp_head()` output
- Response body contains the `<link rel="stylesheet" id="acrossai-mcp-frontend-css">` tag
