# MCP Manager — AI Agent Reference

> This file is intended for AI agents, code assistants, and automated tools. It provides a complete, structured reference for the MCP Manager WordPress plugin — its architecture, features, files, APIs, hooks, and data model.

---

## 1. Plugin Identity

| Field | Value |
|-------|-------|
| Plugin Name | AcrossAI MCP Manager |
| Slug | acrossai-mcp-manager |
| Version | 1.0.0 |
| Author | raftaar1191 |
| License | GPL-2.0-or-later |
| Text Domain | acrossai-mcp-manager |
| Requires PHP | 7.4+ |
| Requires WP | 5.9+ |
| Tested Up To | 7.0 |
| Plugin URI | https://wordpress.org/plugins/mcp-manager/ |

**Purpose:** Enables seamless integration between a WordPress site and Model Context Protocol (MCP) clients (VS Code Copilot, Claude Desktop, GitHub Codex, ChatGPT, custom clients) by managing multiple MCP server entries in a custom DB table, generating secure Application Passwords, and producing ready-to-paste JSON configurations.

---

## 2. Directory Structure

```
acrossai-mcp-manager/
├── acrossai-mcp-manager.php          # Entry point — constants, activation/deactivation hooks, bootstrap
├── composer.json                     # Composer config (dependencies, autoload)
├── composer.lock                     # Locked dependency versions
├── README.md                         # Human-readable feature documentation
├── readme.txt                        # WordPress.org plugin readme
├── LICENSE / LICENSE.txt             # GPL-2.0-or-later
├── agent.md                          # THIS FILE — AI agent reference
│
├── assets/
│   ├── admin.css                     # Admin UI styles (tabs, config boxes, responsive)
│   └── admin.js                      # Admin UI JavaScript (password gen, REST calls, clipboard)
│
├── languages/                        # Translation directory (empty — no .po/.mo yet)
│
├── src/
│   ├── Core/
│   │   └── Plugin.php                # Singleton plugin coordinator
│   ├── Admin/
│   │   ├── Settings.php              # Admin menu, page routing, asset enqueueing
│   │   ├── SettingsRenderer.php      # Reserved for future server-side rendering helpers (currently empty)
│   │   ├── MCPServerListTable.php    # WP_List_Table subclass — renders the server list
│   │   └── ApplicationPasswords.php  # Application password CRUD + REST API endpoints
│   ├── Database/
│   │   └── MCPServerTable.php        # Custom DB table manager (CRUD, schema migrations)
│   └── MCP/
│       └── Controller.php            # MCP adapter lifecycle (init when any server is enabled)
│
└── vendor/                           # Composer packages (do not edit)
    ├── autoload.php
    ├── autoload_packages.php         # Jetpack autoloader entry point
    ├── automattic/jetpack-autoloader/
    └── wordpress/mcp-adapter/        # MCP Adapter package v0.4.1+
```

---

## 3. PHP Namespace Map

All plugin classes live under the `ACROSSAI_MCP_MANAGER\` root namespace.

| Class (FQCN) | File | Responsibility |
|---|---|---|
| `ACROSSAI_MCP_MANAGER\Core\Plugin` | `src/Core/Plugin.php` | Singleton; owns Settings & Controller instances |
| `ACROSSAI_MCP_MANAGER\Admin\Settings` | `src/Admin/Settings.php` | Admin menu, page routing, asset enqueue |
| `ACROSSAI_MCP_MANAGER\Admin\SettingsRenderer` | `src/Admin/SettingsRenderer.php` | Reserved — currently empty placeholder |
| `ACROSSAI_MCP_MANAGER\Admin\MCPServerListTable` | `src/Admin/MCPServerListTable.php` | WP_List_Table for the MCP server list page |
| `ACROSSAI_MCP_MANAGER\Admin\ApplicationPasswords` | `src/Admin/ApplicationPasswords.php` | App password generation, REST endpoints, client config |
| `ACROSSAI_MCP_MANAGER\Database\MCPServerTable` | `src/Database/MCPServerTable.php` | Custom table CRUD and schema management |
| `ACROSSAI_MCP_MANAGER\MCP\Controller` | `src/MCP/Controller.php` | MCP adapter init, status tracking |

---

## 4. Constants

Defined in `acrossai-mcp-manager.php`:

| Constant | Value |
|---|---|
| `ACROSSAI_MCP_MANAGER_VERSION` | `'1.0.0'` |
| `ACROSSAI_MCP_MANAGER_FILE` | Absolute path to `acrossai-mcp-manager.php` |
| `ACROSSAI_MCP_MANAGER_DIR` | Absolute directory path (no trailing slash) |
| `ACROSSAI_MCP_MANAGER_URL` | Plugin URL (trailing slash) |

---

## 5. Bootstrap & Initialization Flow

```
plugins_loaded (priority 10)
  ├── MCPServerTable::maybe_create_table()     # Create/upgrade DB table; seed default row
  └── ACROSSAI_MCP_MANAGER\Core\Plugin::instance()
        ├── new Settings()
        │     ├── new ApplicationPasswords()
        │     │     └── rest_api_init  → register_rest_routes()
        │     ├── admin_init (priority 5) → handle_actions()
        │     ├── admin_menu             → register_menu()
        │     └── admin_enqueue_scripts  → enqueue_assets()
        └── new Controller()
              └── init (priority 1) → initialize_adapter()

Activation hook → MCPServerTable::create_table() + insert_default_server()
Deactivation hook → (intentionally empty)
```

---

## 6. Custom Database Table

**Table:** `{prefix}acrossai_mcp_servers`  
**Managed by:** `src/Database/MCPServerTable.php`

### Schema

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK auto-increment |
| `server_name` | VARCHAR(255) | Human-readable name |
| `description` | VARCHAR(500) | Optional description (defaults to `''`) |
| `is_enabled` | TINYINT(1) | 1 = active, 0 = inactive |
| `created_at` | DATETIME | Row creation timestamp |

### Schema versioning

- Version stored in `acrossai_mcp_manager_db_version` option.
- Current version: `1.1.0`.
- `maybe_create_table()` runs `create_table()` (dbDelta) when the stored version differs.
- Bump `MCPServerTable::DB_VERSION` whenever the schema changes.

### Key static methods

| Method | Description |
|---|---|
| `create_table()` | Run dbDelta to create/upgrade the table |
| `maybe_create_table()` | Create/upgrade only when schema version changed; always seeds the default row |
| `insert_default_server()` | Insert the "Default MCP Server" row when the table is empty (idempotent) |
| `get_all()` | Return all rows ordered by id ASC |
| `get_enabled_servers()` | Return rows where is_enabled = 1 |
| `get_by_id( $id )` | Return one row by PK or null |
| `toggle_status( $id )` | Flip is_enabled between 0 and 1 |
| `update_server( $id, $data )` | Update server_name and/or description |
| `has_any_enabled()` | Return true if at least one row is enabled |

---

## 7. Admin Interface

### 7.1 Menu Registration

- **Menu Type:** Top-level page (`add_menu_page`)
- **Menu Title / Page Title:** AcrossAI MCP Manager
- **Slug:** `acrossai_mcp_manager`
- **Capability:** `manage_options`
- **Icon:** `dashicons-hammer`
- **Position:** `99`

### 7.2 URL Routing

| URL | View |
|---|---|
| `?page=acrossai_mcp_manager` | Server list (`WP_List_Table`) |
| `?page=acrossai_mcp_manager&action=edit&server=ID` | Tabbed edit page for one server |
| `?page=acrossai_mcp_manager&action=toggle_status&server=ID&_wpnonce=…` | Toggle is_enabled, then redirect |

### 7.3 Server List Page

Displays all servers in a `WP_List_Table` with columns:

| Column | Content |
|---|---|
| Name | Server name (links to edit page) with Edit row action |
| Description | `description` field from the DB row |
| Status | Active / Inactive badge |
| Actions | Enable / Disable button |

### 7.4 Edit Page — Tabs

Tab selection is stored in the `?tab=` query parameter (sanitized with `sanitize_key()`).

| Tab ID | Label | Content |
|---|---|---|
| `overview` | Overview | Server name, description, live status, toggle link, info about app passwords |
| `vscode` | VS Code | Config + password generation |
| `claude` | Claude | Config + password generation |
| `codex` | GitHub Codex | Config + password generation |
| `chatgpt` | OpenAI ChatGPT Codex | Config + password generation |
| `custom` | Custom Client | Config + password generation |

Each client tab renders:
1. Client description
2. "Generate New Application Password" button (data attributes: `data-client`, `data-server`)
3. Config file path (populated by JS via REST)
4. Top-level JSON key hint (populated by JS)
5. Full configuration JSON textarea (populated by JS, password injected after generation)
6. Copy-to-clipboard button
7. Setup instructions

---

## 8. REST API Endpoints

Base namespace: `acrossai-mcp-manager/v1`

All endpoints require `manage_options` capability.

### 8.1 POST `/acrossai-mcp-manager/v1/generate-app-password`

**Purpose:** Create a new WordPress Application Password for the given client and optional server.

| Param | Type | Required | Description |
|---|---|---|---|
| `client` | string | yes | One of: `vscode`, `claude`, `codex`, `chatgpt`, `custom` |
| `server_id` | int | no (default 0) | DB ID of the MCP server — appended to the password name for clarity |

**Success Response (200):**
```json
{
  "success": true,
  "password": "xxxx xxxx xxxx xxxx xxxx xxxx",
  "username": "admin",
  "client": "claude",
  "app_id": "uuid-string",
  "message": "Application Password created. Store it safely — it is shown only once."
}
```

Password is shown **once only** — not retrievable after this response.

---

### 8.2 GET `/acrossai-mcp-manager/v1/get-client-config/{client}`

**Purpose:** Return the full MCP JSON configuration for a client.

| Param | Location | Description |
|---|---|---|
| `client` | URL (regex `[a-z\-]+`) | Client ID |
| `server_id` | Query | Optional DB server ID (for future per-server URLs; currently all share the same adapter URL) |

**Success Response (200):**
```json
{
  "success": true,
  "client": "claude",
  "mcp_config": {
    "command": "npx",
    "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
    "env": {
      "WP_API_URL": "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server",
      "WP_API_USERNAME": "admin",
      "WP_API_PASSWORD": "(paste generated password here)"
    }
  },
  "full_config": {
    "mcpServers": {
      "mcp-wordpress": { "...": "same as mcp_config" }
    }
  },
  "username": "admin",
  "top_level_key": "mcpServers",
  "config_file_path": "~/Library/Application Support/Claude/claude_desktop_config.json"
}
```

---

### 8.3 GET `/acrossai-mcp-manager/v1/list-app-passwords`

**Purpose:** List Application Passwords created by this plugin for the current user.

Filters passwords by name prefix `"AcrossAI MCP Manager"` — unrelated passwords are never returned.

**Success Response (200):**
```json
{
  "success": true,
  "passwords": [
    { "name": "AcrossAI MCP Manager - Claude (Default MCP Server)", "uuid": "…", "created": "…" }
  ]
}
```

---

## 9. Supported MCP Clients

| ID | Label | Top-Level JSON Key | Config File Path |
|---|---|---|---|
| `vscode` | VS Code | `servers` | `~/.config/Code/User/globalStorage/Copilot.copilot-chat/mcp.json` |
| `claude` | Claude | `mcpServers` | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| `codex` | GitHub Codex | `servers` | `~/.gh-copilot/config.json` |
| `chatgpt` | OpenAI ChatGPT Codex | `servers` | `~/.config/chatgpt/config.json` |
| `custom` | Custom Client | `mcpServers` | `./your-project/.mcp/config.json` |

**MCP server name (always):** `mcp-wordpress`

---

## 10. Application Password Integration

- Uses WordPress native `WP_Application_Passwords` class (WP 5.6+).
- Password name format: `"AcrossAI MCP Manager - {ClientLabel}"` or `"AcrossAI MCP Manager - {ClientLabel} ({ServerName})"` when a server_id is supplied.
- Passwords are listed and revocable from **Users → Profile → Application Passwords**.
- Generation: `WP_Application_Passwords::create_new_application_password( $user_id, $data )`.
- The raw password is returned **once only** — never stored by this plugin.

---

## 11. MCP Adapter Controller

**File:** `src/MCP/Controller.php`

Boots the `\WP\MCP\Plugin` singleton on the `init` hook (priority 1) when at least one server row has `is_enabled = 1`.

**Status values:**

| Status | Meaning |
|---|---|
| `'running'` | `\WP\MCP\Plugin::instance()` initialized successfully |
| `'disabled'` | No enabled server rows in the DB |
| `'not-found'` | `\WP\MCP\Plugin` class does not exist (adapter not installed) |
| `'error'` | Exception thrown during initialization |
| `'unknown'` | `initialize_adapter()` not yet called |

**Status flow:**
```
init (priority 1) → initialize_adapter()
  ├─ MCPServerTable::has_any_enabled() == false  → status = 'disabled'
  ├─ class \WP\MCP\Plugin not found              → status = 'not-found'
  ├─ \WP\MCP\Plugin::instance() OK               → status = 'running'
  └─ Exception thrown                            → status = 'error'
                                                    + fires acrossai_mcp_manager_adapter_init_error (WP_DEBUG only)
```

---

## 12. Hooks Reference

### Actions (plugin registers)

| Hook | Priority | Callback | Description |
|---|---|---|---|
| `plugins_loaded` | 10 | `MCPServerTable::maybe_create_table` + `Plugin::instance()` | Bootstrap plugin |
| `admin_menu` | default | `Settings::register_menu()` | Add admin menu page |
| `admin_init` | 5 | `Settings::handle_actions()` | Process toggle_status before HTML output |
| `admin_enqueue_scripts` | default | `Settings::enqueue_assets()` | Enqueue CSS/JS on plugin page |
| `admin_notices` | default | `Settings::render_missing_adapter_notice()` | Show missing adapter warning |
| `rest_api_init` | default | `ApplicationPasswords::register_rest_routes()` | Register REST endpoints |
| `init` | 1 | `Controller::initialize_adapter()` | Init MCP adapter |

### Custom Actions (plugin fires)

| Action | When Fired | Args |
|---|---|---|
| `acrossai_mcp_manager_adapter_init_error` | Adapter init exception (WP_DEBUG only) | `$exception` |

---

## 13. Frontend Assets

### admin.css (`assets/admin.css`)

- Tab navigation (`.nav-tab-wrapper`, `.nav-tab`, `.nav-tab-active`) — URL-based, no JS switching
- Tab content panels with CSS fade-in animation
- Configuration JSON textarea blocks
- Password actions section
- Copy button states
- Info/status boxes and badges
- Responsive breakpoints at `max-width: 768px`

### admin.js (`assets/admin.js`)

IIFE with `MCPAdmin` object. Localized data at `acrossaiMcpManagerData`:

| Key | Type | Content |
|---|---|---|
| `acrossaiMcpManagerData.nonce` | string | `wp_create_nonce('wp_rest')` |
| `acrossaiMcpManagerData.rest_url` | string | REST API base URL (trailing slash) |
| `acrossaiMcpManagerData.current_user` | object | Current user data |
| `acrossaiMcpManagerData.server_id` | int | DB ID of the server being edited (0 on list page) |
| `acrossaiMcpManagerData.clients` | string[] | Array of client ID strings (e.g. `['vscode','claude',…]`) |

**Key methods:**

| Method | Description |
|---|---|
| `init()` | Entry point — skips config/password loading when server_id is 0 |
| `restUrl(path, extra)` | Builds a full REST URL with nonce appended |
| `get(url)` | Fetch GET → JSON |
| `post(url, params)` | Fetch POST (URL-encoded body) → JSON |
| `loadExistingPasswords()` | GET `list-app-passwords`; marks buttons that already have a password |
| `loadClientConfigurations()` | Loads configs for all clients from `acrossaiMcpManagerData.clients` |
| `loadClientConfiguration(clientId, serverId)` | GET `get-client-config/{client}?server_id=…`, populates textarea and metadata |
| `setupGeneratePassword()` | Wires `.generate-app-password` button clicks |
| `generatePassword(clientId, serverId)` | POST `generate-app-password`, injects password into config JSON |
| `updateConfig(clientId, password)` | Replaces `WP_API_PASSWORD` placeholder in the config textarea |
| `markPasswordExists(clientId)` | Updates button label to "Regenerate Application Password" |
| `showNotice(clientId, message, type)` | Displays inline WP-style notice below the generate button (auto-removes after 5 s) |
| `setupClipboardButtons()` | Wires `.copy-to-clipboard` buttons |

**Notes:**
- Tab navigation is URL-based (PHP renders the active tab server-side). There is no JS tab switching.
- No `sessionStorage` usage — passwords are not persisted across page navigations.
- All REST calls include `server_id` so configs can be server-scoped in future.

---

## 14. Security Model

| Concern | Implementation |
|---|---|
| Capability check | `current_user_can('manage_options')` on all admin pages and REST endpoints |
| Input sanitization | `sanitize_text_field()`, `sanitize_key()`, `absint()` |
| Output escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` |
| CSRF (admin form actions) | `check_admin_referer('acrossai_mcp_toggle_{id}')` before toggle_status |
| CSRF (REST calls) | `wp_create_nonce('wp_rest')` passed via `acrossaiMcpManagerData.nonce`; sent as `_wpnonce` query param |
| Password storage | Never stored in DB by plugin — only WordPress core `WP_Application_Passwords` table |
| Password display | Shown once in browser session via REST response; never re-exposed by server |

---

## 15. Composer Dependencies

| Package | Version | Purpose |
|---|---|---|
| `automattic/jetpack-autoloader` | ^2.0 | PSR-4 autoloading; prevents dependency conflicts across plugins |
| `wordpress/mcp-adapter` | >=0.4.1 | MCP protocol server implementation for WordPress |

**Autoload entry point:** `vendor/autoload_packages.php` (Jetpack style, included in `acrossai-mcp-manager.php`).

---

## 16. MCP Adapter Package (`wordpress/mcp-adapter` v0.4.1+)

Main class: `\WP\MCP\Plugin` (singleton)

- The plugin calls `\WP\MCP\Plugin::instance()` to initialize the adapter.
- The adapter registers its own REST route and handles inbound MCP protocol requests.

**MCP API URL:** `{site_url}/wp-json/mcp/mcp-adapter-default-server`

> **Multi-server note:** The adapter currently exposes a single endpoint regardless of how many DB server rows are enabled. `server_id` is threaded through the REST API to prepare for future per-server adapter URLs. When that feature is introduced, update `ApplicationPasswords::generate_mcp_server_config()` to derive the URL from the server row.

---

## 17. Localization

- **Text domain:** `acrossai-mcp-manager`
- **Domain path:** `/languages`
- All user-facing strings wrapped in `__()`, `esc_html__()`, `esc_html_e()`
- No translation files yet — PRs welcome

---

## 18. User Setup Workflow (End-to-End)

1. Install and activate the plugin.
2. Go to **WordPress Admin → MCP Manager** — a default server row is created automatically.
3. Click the server name to open the edit page.
4. On the **Overview** tab click **Enable** to activate the MCP adapter.
5. Click the tab for your MCP client (e.g. **Claude**).
6. Click **Generate New Application Password** — a one-time password is injected into the config JSON.
7. Click **Copy Configuration** to copy the full JSON block.
8. Open the config file path shown in the UI and paste the JSON under the displayed top-level key.
9. Restart the MCP client.
10. The client can now interact with WordPress via the MCP protocol.

---

## 19. Known Limitations / Edge Cases

- Application Passwords require **HTTPS** on production (WordPress enforces this).
- The MCP adapter (`wordpress/mcp-adapter`) must be bundled via Composer — it is not a separate plugin.
- All DB server rows currently share the same adapter URL (`mcp/mcp-adapter-default-server`). Per-server URLs are not yet implemented.
- Passwords are displayed **once** in the config JSON. If lost, delete and regenerate via the plugin UI or **Users → Profile**.
- `acrossai_mcp_manager_adapter_init_error` custom action fires **only when `WP_DEBUG` is true**.
- Languages directory is empty — the plugin is not yet translated.
- No uninstall hook — the DB table and `acrossai_mcp_manager_db_version` option persist after plugin deletion unless manually removed.

---

## 20. Extension Points for Developers

| Point | How |
|---|---|
| React to adapter init error | Hook `acrossai_mcp_manager_adapter_init_error` action (passes `$exception`) |
| Add more REST endpoints | Hook `rest_api_init` and call `register_rest_route('acrossai-mcp-manager/v1', …)` |
| Add more MCP clients | Extend `ApplicationPasswords::$clients` array |
| Per-server adapter URLs | Update `ApplicationPasswords::generate_mcp_server_config()` to derive URL from `$server_id` |
| Add server-side config renderers | Add static methods to `SettingsRenderer` |

---

## 21. File Line Counts (approximate)

| File | Lines |
|---|---|
| `acrossai-mcp-manager.php` | ~80 |
| `src/Core/Plugin.php` | ~77 |
| `src/Admin/Settings.php` | ~545 |
| `src/Admin/SettingsRenderer.php` | ~18 |
| `src/Admin/MCPServerListTable.php` | ~189 |
| `src/Admin/ApplicationPasswords.php` | ~270 |
| `src/Database/MCPServerTable.php` | ~284 |
| `src/MCP/Controller.php` | ~97 |
| `assets/admin.css` | ~337 |
| `assets/admin.js` | ~260 |

---

*Last updated: 2026-04-21. Reflects codebase after multi-server cleanup and JS rewrite.*
