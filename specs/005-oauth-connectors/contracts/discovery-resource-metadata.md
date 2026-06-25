# Contract — Discovery: Protected Resource Metadata

**Date**: 2026-06-18 | **RFC**: 9728 | **FR**: FR-002 + FR-003

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/.well-known/oauth-protected-resource` |
| Method | `GET` |
| Auth | none (public) |
| `Content-Type` (response) | `application/json` |
| `Cache-Control` (response) | `public, max-age=86400` |

## Response — HTTP 200

```json
{
  "resource": "https://example.com/wp-json/mcp",
  "authorization_servers": ["https://example.com"],
  "bearer_methods_supported": ["header"]
}
```

## Server-side handler

`Includes\OAuth\ClaudeConnectors::serve_rs_metadata()` is wired on
`template_redirect`. Same short-circuit pattern as
`serve_as_metadata`, but:
- `resource` is `rest_url('mcp')` (no trailing slash via `untrailingslashit`)
- `authorization_servers` is `[home_url()]` — single-issuer array per
  RFC 9728 §2

## Golden fixture

`tests/phpunit/OAuth/fixtures/discovery-rs.json` with `{ISSUER}` and
`{RESOURCE}` placeholder substitution.
