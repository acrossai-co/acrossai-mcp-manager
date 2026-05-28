# Source Map — File Locations for Migration

## Repository Roots

```
SOURCE (read from, do not modify):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

TARGET (write into, this repo):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/
```

---

## Phase 1 — Core Boot

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/acrossai-mcp-manager.php` | `acrossai-mcp-manager-new/acrossai-mcp-manager.php` |
| `acrossai-mcp-manager/src/Core/Plugin.php` | _(merged into target `includes/Main.php` — no 1:1 copy)_ |
| `acrossai-mcp-manager/src/Core/Compat.php` | `acrossai-mcp-manager-new/includes/Compat.php` |
| `acrossai-mcp-manager/src/Core/polyfills.php` | `acrossai-mcp-manager-new/includes/polyfills.php` |
| `acrossai-mcp-manager/composer.json` | reference only — update target `composer.json` |

Already exists in target (extend, do not replace):
- `acrossai-mcp-manager-new/includes/Main.php`
- `acrossai-mcp-manager-new/includes/Loader.php`
- `acrossai-mcp-manager-new/includes/Activator.php`
- `acrossai-mcp-manager-new/includes/Deactivator.php`
- `acrossai-mcp-manager-new/includes/I18n.php`

---

## Phase 2 — Admin UI

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/Admin/Settings.php` | `acrossai-mcp-manager-new/admin/Partials/Settings.php` |
| `acrossai-mcp-manager/src/Admin/SettingsRenderer.php` | `acrossai-mcp-manager-new/admin/Partials/SettingsRenderer.php` |
| `acrossai-mcp-manager/src/Admin/ApplicationPasswords.php` | `acrossai-mcp-manager-new/admin/Partials/ApplicationPasswords.php` |
| `acrossai-mcp-manager/src/Admin/MCPServerListTable.php` | `acrossai-mcp-manager-new/admin/Partials/MCPServerListTable.php` |
| `acrossai-mcp-manager/src/Admin/CliAuthLogListTable.php` | `acrossai-mcp-manager-new/admin/Partials/CliAuthLogListTable.php` |
| `acrossai-mcp-manager/src/Admin/ConnectorAuditLogListTable.php` | `acrossai-mcp-manager-new/admin/Partials/ConnectorAuditLogListTable.php` |
| `acrossai-mcp-manager/assets/admin.css` | reference only — source for `src/scss/backend.scss` (Phase 9) |
| `acrossai-mcp-manager/assets/admin.js` | reference only — source for `src/js/backend.js` (Phase 9) |

Already exists in target (extend, do not replace):
- `acrossai-mcp-manager-new/admin/Main.php`
- `acrossai-mcp-manager-new/admin/Partials/Menu.php`

---

## Phase 3 — MCP Controller

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/MCP/Controller.php` | `acrossai-mcp-manager-new/includes/MCP/Controller.php` |

---

## Phase 4 — MCP Clients

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/MCPClients/AbstractMCPClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/AbstractMCPClient.php` |
| `acrossai-mcp-manager/src/MCPClients/ClaudeCodeClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/ClaudeCodeClient.php` |
| `acrossai-mcp-manager/src/MCPClients/ClaudeDesktopClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/ClaudeDesktopClient.php` |
| `acrossai-mcp-manager/src/MCPClients/CodexClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/CodexClient.php` |
| `acrossai-mcp-manager/src/MCPClients/CursorClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/CursorClient.php` |
| `acrossai-mcp-manager/src/MCPClients/CustomClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/CustomClient.php` |
| `acrossai-mcp-manager/src/MCPClients/GitHubCopilotClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/GitHubCopilotClient.php` |
| `acrossai-mcp-manager/src/MCPClients/VSCodeClient.php` | `acrossai-mcp-manager-new/includes/MCPClients/VSCodeClient.php` |

---

## Phase 5 — OAuth / Claude Connectors

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/OAuth/ClaudeConnectors.php` | `acrossai-mcp-manager-new/includes/OAuth/ClaudeConnectors.php` |
| `acrossai-mcp-manager/src/OAuth/AuthorizationCodeResponseType.php` | `acrossai-mcp-manager-new/includes/OAuth/AuthorizationCodeResponseType.php` |
| `acrossai-mcp-manager/src/OAuth/AuthorizeController.php` | `acrossai-mcp-manager-new/includes/OAuth/AuthorizeController.php` |
| `acrossai-mcp-manager/src/OAuth/Storage.php` | `acrossai-mcp-manager-new/includes/OAuth/Storage.php` |

---

## Phase 6 — Frontend Auth

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/Frontend/FrontendAuth.php` | `acrossai-mcp-manager-new/public/Partials/FrontendAuth.php` |
| `acrossai-mcp-manager/assets/frontend-auth.css` | reference only — source for `src/scss/frontend.scss` (Phase 9) |

---

## Phase 7 — REST API

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/REST/CliController.php` | `acrossai-mcp-manager-new/includes/REST/CliController.php` |

---

## Phase 8 — Assets

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/assets/admin.css` | `acrossai-mcp-manager-new/src/scss/backend.scss` |
| `acrossai-mcp-manager/assets/admin.js` | `acrossai-mcp-manager-new/src/js/backend.js` |
| `acrossai-mcp-manager/assets/frontend-auth.css` | `acrossai-mcp-manager-new/src/scss/frontend.scss` |
| `acrossai-mcp-manager/assets/frontend-oauth.css` | `acrossai-mcp-manager-new/src/scss/frontend-oauth.scss` |
| `acrossai-mcp-manager/webpack.config.js` _(if exists)_ | reference only — update `acrossai-mcp-manager-new/webpack.config.js` |

---

## Additional Reference Files (read for context at any phase)

| File | What it tells you |
|---|---|
| `acrossai-mcp-manager/acrossai-mcp-manager.php` | Plugin header, version, constants, activation hooks |
| `acrossai-mcp-manager/composer.json` | Vendor packages required (Jetpack autoloader, OAuth libs) |
| `acrossai-mcp-manager/src/Core/Plugin.php` | Full list of what the old singleton wired — use as checklist for Main.php |
| `acrossai-mcp-manager/AGENTS.md` or `agent.md` | Agent guidance from old repo |
