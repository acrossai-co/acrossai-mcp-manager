# Source Map — File Locations for Migration

## Repository Roots

```
SOURCE (read from, do not modify):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

TARGET (write into, this repo):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/
```

> Phase files: `phase-2-core-boot.md`, `phase-3-admin.md`, `phase-mcp.md`, `phase-6-oauth.md`, `phase-cli-auth.md`
> Phase 1 does not exist — the database migration phase was intentionally removed.
> Database classes are re-implemented fresh as part of Phase 2 (Core Boot / Activator).
> Assets (Phase 9) are documented in README.md — no separate phase file.

---

## Phase 2 — Core Boot (`phase-2-core-boot.md`)

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/acrossai-mcp-manager.php` | `acrossai-mcp-manager-new/acrossai-mcp-manager.php` |
| `acrossai-mcp-manager/src/Core/Plugin.php` | _(merged into target `includes/Main.php` — no 1:1 copy)_ |
| `acrossai-mcp-manager/src/Core/Compat.php` | `acrossai-mcp-manager-new/includes/Compat.php` |
| `acrossai-mcp-manager/src/Core/polyfills.php` | `acrossai-mcp-manager-new/includes/polyfills.php` |
| `acrossai-mcp-manager/composer.json` | reference only — update target `composer.json` |
| `acrossai-mcp-manager/src/Database/MCPServerTable.php` | `acrossai-mcp-manager-new/includes/Database/MCPServer/Query.php` _(new BerlinDB style)_ |
| `acrossai-mcp-manager/src/Database/CliAuthLogTable.php` | `acrossai-mcp-manager-new/includes/Database/CliAuthLog/Query.php` _(new BerlinDB style)_ |

Already exists in target (extend, do not replace):
- `acrossai-mcp-manager-new/includes/Main.php`
- `acrossai-mcp-manager-new/includes/Loader.php`
- `acrossai-mcp-manager-new/includes/Activator.php`  ← add DB bootstraps + rewrite rule here
- `acrossai-mcp-manager-new/includes/Deactivator.php`
- `acrossai-mcp-manager-new/includes/I18n.php`

---

## Phase 3 — Admin UI (`phase-3-admin.md`)

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

> The list tables (`CliAuthLogListTable`, `MCPServerListTable`) call `MCPServer\Query` and
> `CliAuthLog\Query` — these must exist from Phase 2 before this phase runs.

Already exists in target (extend, do not replace):
- `acrossai-mcp-manager-new/admin/Main.php`
- `acrossai-mcp-manager-new/admin/Partials/Menu.php`

---

## Phase MCP — Controller + Clients (`phase-mcp.md`)

### MCP Controller

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/MCP/Controller.php` | `acrossai-mcp-manager-new/includes/MCP/Controller.php` |

### MCP Clients

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

## Phase 6 — OAuth / Claude Connectors (`phase-6-oauth.md`)

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/OAuth/ClaudeConnectors.php` | `acrossai-mcp-manager-new/includes/OAuth/ClaudeConnectors.php` |
| `acrossai-mcp-manager/src/OAuth/AuthorizationCodeResponseType.php` | `acrossai-mcp-manager-new/includes/OAuth/AuthorizationCodeResponseType.php` |
| `acrossai-mcp-manager/src/OAuth/AuthorizeController.php` | `acrossai-mcp-manager-new/includes/OAuth/AuthorizeController.php` |
| `acrossai-mcp-manager/src/OAuth/Storage.php` | `acrossai-mcp-manager-new/includes/OAuth/Storage.php` |

---

## CLI Auth — Frontend + REST (`phase-cli-auth.md`)

> Implement REST controller first (Part B), then FrontendAuth (Part A).

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/src/REST/CliController.php` | `acrossai-mcp-manager-new/includes/REST/CliController.php` |
| `acrossai-mcp-manager/src/Frontend/FrontendAuth.php` | `acrossai-mcp-manager-new/public/Partials/FrontendAuth.php` |
| `acrossai-mcp-manager/src/Database/MCPServerTable.php` | reference — calls replaced by `Database\MCPServer\Query::instance()` |
| `acrossai-mcp-manager/assets/frontend-auth.css` | reference only — source for `src/scss/frontend.scss` (Assets step) |

> REST namespace is `acrossai-mcp-manager/v1` (not `acrossai-mcp/v1`).
> Auth is transient-based — no CliAuthLog\Query injection in CliController.
> `static approve_auth_code()` must stay static (called from FrontendAuth).

---

## Assets (documented in `README.md` — no separate phase file)

| Read this (source) | Write here (target) |
|---|---|
| `acrossai-mcp-manager/assets/admin.css` | `acrossai-mcp-manager-new/src/scss/backend.scss` |
| `acrossai-mcp-manager/assets/admin.js` | `acrossai-mcp-manager-new/src/js/backend.js` |
| `acrossai-mcp-manager/assets/frontend-auth.css` | `acrossai-mcp-manager-new/src/scss/frontend.scss` |
| `acrossai-mcp-manager/assets/frontend-oauth.css` | `acrossai-mcp-manager-new/src/scss/frontend-oauth.scss` |

---

## Database Classes — Context Only

> These are NOT a standalone phase. They are re-implemented from scratch as part of
> **Phase 2 (Core Boot)** inside `includes/Database/`.

| Read this (source) | Implement here (target) | Created in |
|---|---|---|
| `acrossai-mcp-manager/src/Database/MCPServerTable.php` | `acrossai-mcp-manager-new/includes/Database/MCPServer/Query.php` | Phase 2 |
| `acrossai-mcp-manager/src/Database/CliAuthLogTable.php` | `acrossai-mcp-manager-new/includes/Database/CliAuthLog/Query.php` | Phase 2 |

Key invariants from source:
- `MCPServerTable` uses object cache group `acrossai_mcp` for read methods
- `CliAuthLogTable::record_success/approved/failed()` are static audit methods
- Seeds a default server row if the table is empty on first boot
- `maybe_create_table()` uses `dbDelta()` — idempotent, safe to run on every `plugins_loaded`

---

## Additional Reference Files (read for context at any phase)

| File | What it tells you |
|---|---|
| `acrossai-mcp-manager/acrossai-mcp-manager.php` | Plugin header, version, constants, activation hooks |
| `acrossai-mcp-manager/composer.json` | Vendor packages required (Jetpack autoloader, OAuth libs) |
| `acrossai-mcp-manager/src/Core/Plugin.php` | Full list of what the old singleton wired — use as checklist for Main.php |
| `acrossai-mcp-manager/AGENTS.md` | Key invariants: server key format, transient keys, auth URL rules |
