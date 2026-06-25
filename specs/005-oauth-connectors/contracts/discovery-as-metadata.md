# Contract — Discovery: Authorization Server Metadata

**Date**: 2026-06-18 | **RFC**: 8414 §3 | **FR**: FR-001 + FR-003

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/.well-known/oauth-authorization-server` |
| Method | `GET` |
| Auth | none (public) |
| `Content-Type` (response) | `application/json` |
| `Cache-Control` (response) | `public, max-age=86400` |

## Response — HTTP 200

```json
{
  "issuer": "https://example.com",
  "authorization_endpoint": "https://example.com/acrossai-mcp-oauth/",
  "token_endpoint": "https://example.com/wp-json/acrossai-mcp/v1/token",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code"],
  "code_challenge_methods_supported": ["S256"],
  "token_endpoint_auth_methods_supported": ["client_secret_post"],
  "scopes_supported": ["mcp"]
}
```

## Server-side handler

`Includes\OAuth\ClaudeConnectors::serve_as_metadata()` is wired on
`template_redirect`. It:
1. Short-circuits if `get_query_var('acrossai_mcp_oauth') !== 'as_metadata'`
2. Composes the array above using `home_url()` for `issuer` and
   `home_url('/acrossai-mcp-oauth/')` and `rest_url('acrossai-mcp/v1/token')`
   for the endpoints (so subdirectory installs and non-default REST
   prefixes work correctly)
3. Calls `wp_send_json( $payload, 200 )` then `exit`

## Negative paths

| Scenario | Response |
|---|---|
| Plugin deactivated → rewrite rule absent | WordPress 404 (default) |
| Mistyped path (e.g. `/.well-known/oauth-foo`) | WordPress 404 |
| Site URL contains a port | `issuer` includes the port (`https://example.com:8080`) |
| Subdirectory install (`/wp/`) | All URLs rooted at site URL — `issuer` = `https://example.com/wp`, endpoints absolute beneath |

## Golden fixture

`tests/phpunit/OAuth/fixtures/discovery-as.json` pins the response
shape. The fixture uses a placeholder `{ISSUER}` token that the test
substitutes at runtime to match the test site URL.
