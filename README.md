# MCP Manager

A production-ready WordPress plugin for enabling/disabling MCP (Model Context Protocol) Adapter integration.

This plugin standardizes on **`@automattic/mcp-wordpress-remote` + WordPress Application Passwords** for the default remote MCP client flow. It also includes an **optional experimental direct Claude Connectors mode** that uses a WordPress-hosted OAuth approval flow.

## Features

- **Toggle-based MCP Integration** — Enable or disable MCP adapter from WordPress admin settings
- **Modern OOP Architecture** — Built with singleton pattern and proper namespacing
- **PSR-4 Autoloading** — Full Composer integration with Jetpack autoloader support
- **Settings API** — WordPress-standard settings management with proper sanitization
- **Admin UI** — Clean admin settings page with enable/disable checkbox
- **LLM Configuration** — Dynamically generates JSON configuration for VS Code/Copilot integration
- **Safety First** — Graceful handling of missing dependencies with admin notices
- **Output Escaping** — All HTML properly escaped using WordPress functions
- **PHPDoc Documentation** — Complete documentation on all public methods

## Installation

1. Plugin is already in: `/wp-content/plugins/mcp-manager/`
2. Dependencies installed via Composer: `vendor/autoload.php` and Jetpack autoloader

## Activation

1. Go to **WordPress Admin** → **Plugins**
2. Find **MCP Manager**
3. Click **Activate**

## Configuration

1. Navigate to **MCP Manager** in the WordPress admin sidebar
2. Check the box to **Enable MCP Adapter**
3. Click **Save Changes**
4. Copy the JSON configuration from the **LLM Configuration** section

## CLI Connection and Authorization Flow

This plugin supports a browser-assisted CLI connection flow for:

- Claude Desktop
- Claude Code
- VS Code
- GitHub Copilot
- Cursor
- Codex
- Custom MCP clients

Use this command shape from the server edit screen:

```bash
npx -y @acrossai/mcp-manager --siteurl=https://example.com --server=default-mcp-server
```

### Terminology

- **Sign in / Log in** = the user authenticates into WordPress
- **Connect** = the CLI starts linking a local MCP client to the site
- **Authorize / Approve access** = the signed-in WordPress user grants the CLI access

The browser step is an authorization flow, not a generic "login" flow. The only real login step is the redirect to `wp-login.php` when the user is not already signed in.

### End-to-end sequence

1. The user enables **CLI Connections** in plugin settings.
2. The user runs `npx -y @acrossai/mcp-manager --siteurl=... --server=...`.
3. The CLI checks `GET /wp-json/acrossai-mcp-manager/v1/health`.
4. The CLI starts an auth session with `POST /wp-json/acrossai-mcp-manager/v1/auth/start`.
5. The plugin returns:
   - `auth_code`
   - `auth_url`
   - `expires_in`
6. The CLI opens `auth_url` in the browser. This URL points to the frontend page at `/acrossai-mcp-manager/`, not to `wp-admin`.
7. If the user is not signed in, WordPress redirects to `wp-login.php`, then returns to the frontend approval page.
8. The signed-in user approves access on the frontend page.
9. The CLI polls `GET /wp-json/acrossai-mcp-manager/v1/auth/status?code=...&server=...` until the code is approved.
10. After approval, the CLI receives a short-lived session token.
11. The CLI calls `GET /wp-json/acrossai-mcp-manager/v1/servers` with the session token to fetch the servers that user is allowed to access.
12. The CLI exchanges the approved code with `POST /wp-json/acrossai-mcp-manager/v1/auth/exchange`.
13. The plugin creates a WordPress Application Password for the approving user and returns it once.
14. The CLI writes the MCP config entry into the selected client config file.

### Important security and behavior rules

- The frontend auth page sends `nocache_headers()` and **must never be cached**.
- `auth_url` must always point to the frontend approval page from `FrontendAuth::get_base_url()`.
- Auth codes are **single-use**. A successful exchange deletes both the auth-code transient and the session-token transient.
- `GET /servers` is access-control-aware and only returns enabled servers available to the approved user.
- `POST /auth/exchange` only succeeds for the approved server and only when that server is still accessible to that user.
- The generated config key uses the format `{siteSlug}-{serverSlug}`.
- The internal option key is still `acrossai_mcp_npm_login_enabled` for backward compatibility, but user-facing copy should say **CLI Connections**, not **npm Login**.
- JSON configs generated in the MCP Clients tab explicitly disable OAuth discovery in `@automattic/mcp-wordpress-remote` and use WordPress Application Passwords instead.
- Per-server **Access Control** still applies after authentication. A saved MCP config does not bypass access rules.

## Experimental Direct Claude Connectors Mode

The plugin now also includes an **optional** direct Claude Connectors mode behind the **Claude Connectors Screen (Experimental)** setting.

When the global feature toggle is enabled and a specific server is configured in its **Claude Connector** tab with an OAuth client ID + redirect URI, the plugin provides:

- `/.well-known/oauth-authorization-server`
- `/.well-known/oauth-protected-resource?resource=<mcp-url>`
- browser authorization at `/acrossai-mcp-connectors/oauth/authorize/`
- token exchange at `/wp-json/acrossai-mcp-manager/v1/connector/oauth/token`

Behavior notes:

- This mode is **off by default**
- The existing Application Password flow remains the recommended low-risk option
- The master experimental toggle is global, but OAuth client settings are now **per server**
- Direct Claude connector approval signs Claude in as a **WordPress user**
- Per-server **Access Control** is still checked on every MCP request after OAuth
- Public HTTPS is recommended for hosted connector use

### Response shapes used by the CLI

`GET /health`

```json
{
  "plugin_installed": true,
  "plugin_active": true,
  "version": "1.6.0",
  "site_slug": "wordpress"
}
```

`POST /auth/start`

```json
{
  "auth_code": "abc123",
  "auth_url": "https://example.com/acrossai-mcp-manager/?action=cli_auth&code=abc123&server=default-mcp-server",
  "expires_in": 300
}
```

`GET /auth/status`

```json
{
  "approved": true,
  "token": "session-token"
}
```

`GET /servers`

```json
{
  "servers": [
    {
      "id": "default-mcp-server",
      "name": "Default MCP Server",
      "description": "Default MCP server",
      "enabled": true,
      "mcp_url": "https://example.com/wp-json/mcp/default-mcp-server"
    }
  ]
}
```

`POST /auth/exchange`

```json
{
  "app_password": "xxxx xxxx xxxx xxxx xxxx xxxx",
  "username": "admin"
}
```

## File Structure

```
mcp-manager/
├── mcp-manager.php          (Main plugin file with hooks)
├── composer.json            (Composer configuration)
├── composer.lock            (Composer lock file)
├── vendor/                  (Composer dependencies - generated)
│   ├── autoload.php        (PSR-4 autoloader)
│   ├── autoload_packages.php (Jetpack autoloader)
│   ├── automattic/
│   ├── jetpack-autoloader/
│   └── wordpress/
└── src/
    ├── Core/
    │   └── Plugin.php       (Singleton plugin class)
    ├── Admin/
    │   ├── Settings.php     (Settings API implementation)
    │   └── SettingsRenderer.php (JSON config generator)
    └── MCP/
        └── Controller.php   (MCP adapter initialization)
```

## Architecture

### Core Components

- **Plugin.php** — Singleton that initializes all plugin components
- **Settings.php** — Handles admin menu, settings registration, and field rendering
- **SettingsRenderer.php** — Generates MCP configuration JSON
- **Controller.php** — Manages conditional MCP adapter initialization

### Namespacing

All classes use the `MCP_MANAGER\` namespace:
- `MCP_MANAGER\Core\Plugin`
- `MCP_MANAGER\Admin\Settings`
- `MCP_MANAGER\Admin\SettingsRenderer`
- `MCP_MANAGER\MCP\Controller`

### Hooks Used

- `plugins_loaded` — Initialize plugin singleton
- `admin_menu` — Register admin menu
- `admin_init` — Register settings and fields
- `init` (priority 20) — Initialize MCP adapter if enabled

## Configuration Storage

Settings are stored in WordPress options table:
- `mcp_manager_enabled` (boolean) — Enable/disable MCP adapter

## Dependencies

- **automattic/jetpack-autoloader** (^2.0) — Handles Composer autoloading
- **wordpress/mcp-adapter** (>=0.4.1) — MCP adapter integration

## Requirements

- PHP 8.0+
- WordPress 5.9+
- Composer (for development/installation)

## Security

- All output is properly escaped using WordPress functions:
  - `esc_html()` — For HTML content
  - `esc_attr()` — For HTML attributes
  - `esc_textarea()` — For textarea values
  - `wp_kses_post()` — For formatted text

- All input is sanitized via Settings API callbacks
- Capability checks ensure only admin users can access settings
- Safe MCP adapter initialization with try-catch error handling

## Development

To modify or extend the plugin:

1. Edit files in `src/` directory
2. If adding dependencies, update `composer.json`
3. Run `composer install` to regenerate autoloaders
4. Test in WordPress admin

## Status Codes

The MCP Controller provides status reporting:
- `'running'` — MCP adapter is initialized and running
- `'disabled'` — MCP is disabled in settings
- `'not-found'` — MCP adapter class not installed
- `'error'` — Error during MCP adapter initialization
- `'unknown'` — Status not yet determined

## Version

Current Version: 1.0.0

## License

GPL-2.0-or-later
