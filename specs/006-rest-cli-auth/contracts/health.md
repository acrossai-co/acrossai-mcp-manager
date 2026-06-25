# Contract — Discovery: Plugin Health

**Date**: 2026-06-25 | **FR**: FR-001

## Endpoint

| Property | Value |
|---|---|
| URL | `https://{site_url}/wp-json/acrossai-mcp-manager/v1/health` |
| Method | `GET` |
| Auth | none (`permission_callback: __return_true`) |
| `Content-Type` (response) | `application/json` |

## Response — HTTP 200

```json
{
  "plugin_installed": true,
  "plugin_active": true,
  "version": "0.0.1",
  "site_slug": "example-site"
}
```

| Field | Type | Source |
|---|---|---|
| `plugin_installed` | `bool` | always `true` (the route only registers if the plugin booted) |
| `plugin_active` | `bool` | always `true` (same — implied by route registration) |
| `version` | `string` | `ACROSSAI_MCP_MANAGER_VERSION` constant |
| `site_slug` | `string` | `sanitize_title( get_bloginfo( 'name' ) )` |

## Server-side handler

`Includes\REST\CliController::handle_health( WP_REST_Request $request )` returns:

```php
return new WP_REST_Response( array(
    'plugin_installed' => true,
    'plugin_active'    => true,
    'version'          => defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) ? (string) ACROSSAI_MCP_MANAGER_VERSION : '0.0.0',
    'site_slug'        => sanitize_title( get_bloginfo( 'name' ) ),
), 200 );
```

## Negative paths

| Scenario | Response |
|---|---|
| Plugin deactivated → route not registered | WordPress default 404 |
| `get_bloginfo('name')` returns empty | `site_slug` is `''` (acceptable — CLI tools can treat empty as "no slug configured") |
| Plugin loaded but `ACROSSAI_MCP_MANAGER_VERSION` not defined (boot order anomaly) | `version` is `"0.0.0"` |

## Golden fixture

`tests/phpunit/RestCli/fixtures/health.json`:
```json
{
  "plugin_installed": true,
  "plugin_active": true,
  "version": "{VERSION}",
  "site_slug": "{SITE_SLUG}"
}
```

The `{VERSION}` and `{SITE_SLUG}` placeholders are substituted at test runtime.
