# Contract: `?action=cli_auth_approved` (success page)

**Path**: `/acrossai-mcp-manager/?action=cli_auth_approved`
**Method**: `GET`
**Authentication**: Logged-in user (ANY role); unauthenticated redirected to `wp-login.php`
**Mutating**: No
**Reached via**: Server-side redirect from `cli_auth_approve` success branch

## Success response (HTTP 200)

```text
HTTP/1.1 200 OK
Cache-Control: no-cache, must-revalidate, max-age=0
Content-Type: text/html; charset=UTF-8

<!DOCTYPE html>
<html lang="<bloginfo-language>">
<head>
  <meta charset="utf-8">
  <title>AcrossAI MCP Manager</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" id="acrossai-mcp-frontend-css" href="<plugin_url>/build/css/frontend.css?ver=<asset_version>">
</head>
<body>
  <h1>CLI Authorization Approved</h1>
  <p>You can now return to your CLI tool — it will detect the approval shortly.</p>
  <p>This page can be closed.</p>
</body>
</html>
```

## Side effects

None. This page is purely informational. The state mutation already happened in the preceding `cli_auth_approve` step.

## Test assertions

- Response code is `200`
- Response body contains `CLI Authorization Approved`
- Response body contains the "You can now return to your CLI tool" message
- `Cache-Control` header begins with `no-cache, must-revalidate, max-age=0`
- Response body contains the `acrossai-mcp-frontend-css` `<link>` tag (asset enqueue runs on every consent-page request, including this success page, because `get_query_var('acrossai_mcp_auth')` is still truthy)
