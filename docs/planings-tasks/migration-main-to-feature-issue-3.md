# Migration Plan: main → feature/issue-3
## Migrate 27 PHP classes from old flat `src/` structure to new WPBoilerplate structure

---

## Context

The `main` branch holds the real, working plugin code (v0.0.4). The `feature/issue-3` branch introduces a new WPBoilerplate-derived folder structure but has only stub/boilerplate classes — no real functionality yet.

This document breaks the migration into 5 sequential phases. Each phase is a standalone PR. Do **not** attempt all phases at once — each phase must boot and pass basic sanity checks before the next begins.

---

## Key Structural Differences

| Concern | main (old) | feature/issue-3 (new) |
|---|---|---|
| Namespace prefix | `ACROSSAI_MCP_MANAGER\` | `AcrossAI_MCP_Manager\` |
| Autoloader | Jetpack (`vendor/autoload_packages.php`) | Same, already wired in `includes/Main.php` |
| Boot pattern | `plugins_loaded` → `Plugin::instance()` | `Main::instance()->run()` via `Loader` |
| Activation hook | Inline in entry file | `includes/Activator.php` |
| Admin classes | `src/Admin/` | `admin/` |
| Core/DB/MCP/OAuth | `src/{Core,Database,MCP,MCPClients,OAuth,REST}/` | `includes/{Database,MCP,MCPClients,OAuth,REST}/` |
| Frontend/public | `src/Frontend/` | `public/` |

---

## Phase 1 — Core Boot + Constants
**Branch:** `feature/issue-3`
**Touches:** `acrossai-mcp-manager.php`, `includes/Main.php`, `includes/Activator.php`, `includes/Deactivator.php`

### What to migrate
- `src/Core/Plugin.php` → absorb into `includes/Main.php` (already a stub)
- `src/Core/Compat.php` → `includes/Core/Compat.php`
- `src/Core/polyfills.php` → `includes/Core/polyfills.php`
- Entry file activation/deactivation hooks → `includes/Activator.php` / `includes/Deactivator.php`

### Decisions required before starting
1. Does `includes/Main.php` replace `src/Core/Plugin.php` entirely, or does Plugin.php move to `includes/Core/Plugin.php` and Main.php delegates to it?
   - **Recommended:** Merge Plugin logic directly into Main — they serve the same role.
2. How are `Compat.php` and `polyfills.php` loaded — via autoloader, or explicit `require_once` in Main?
   - **Recommended:** Explicit `require_once` inside `load_composer_dependencies()` since they are not classes.

### Namespace change for this phase
```
ACROSSAI_MCP_MANAGER\Core\Plugin  →  AcrossAI_MCP_Manager\Includes\Main
ACROSSAI_MCP_MANAGER\Core\Compat  →  AcrossAI_MCP_Manager\Includes\Core\Compat
```

### Acceptance criteria
- Plugin activates without fatal errors
- `ACROSSAI_MCP_MANAGER_PLUGIN_PATH`, `ACROSSAI_MCP_MANAGER_PLUGIN_URL`, `ACROSSAI_MCP_MANAGER_VERSION` constants are all defined
- Deactivation runs without errors

---

## Phase 2 — Database Layer
**Branch:** `feature/issue-3`
**Depends on:** Phase 1 complete
**Touches:** `includes/Database/`

### What to migrate
| Old file | New file |
|---|---|
| `src/Database/MCPServerTable.php` | `includes/Database/MCPServerTable.php` |
| `src/Database/CliAuthLogTable.php` | `includes/Database/CliAuthLogTable.php` |
| `src/Database/ConnectorAuditLogTable.php` | `includes/Database/ConnectorAuditLogTable.php` |

### Decisions required before starting
1. The old `MCPServerTable` uses static methods. Does the new structure keep them static or move to instances?
   - **Recommended:** Keep static — they don't need state and callers throughout the plugin expect `MCPServerTable::get_all()`.
2. Should `maybe_create_table()` still be called from `Activator::activate()` and from `Main::run()`, or only from Activator?
   - **Recommended:** Keep both call sites from main — Activator for fresh installs, `plugins_loaded` call for schema upgrades.

### Namespace change for this phase
```
ACROSSAI_MCP_MANAGER\Database\MCPServerTable           →  AcrossAI_MCP_Manager\Includes\Database\MCPServerTable
ACROSSAI_MCP_MANAGER\Database\CliAuthLogTable          →  AcrossAI_MCP_Manager\Includes\Database\CliAuthLogTable
ACROSSAI_MCP_MANAGER\Database\ConnectorAuditLogTable   →  AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLogTable
```

### Acceptance criteria
- `{prefix}acrossai_mcp_servers` table exists after activation
- `MCPServerTable::get_all()` returns expected rows
- No DB-related fatal errors on activation or page load

---

## Phase 3 — MCP Controller + Clients
**Branch:** `feature/issue-3`
**Depends on:** Phase 2 complete
**Touches:** `includes/MCP/`, `includes/MCPClients/`

### What to migrate
| Old file | New file |
|---|---|
| `src/MCP/Controller.php` | `includes/MCP/Controller.php` |
| `src/MCPClients/AbstractMCPClient.php` | `includes/MCPClients/AbstractMCPClient.php` |
| `src/MCPClients/ClaudeCodeClient.php` | `includes/MCPClients/ClaudeCodeClient.php` |
| `src/MCPClients/ClaudeDesktopClient.php` | `includes/MCPClients/ClaudeDesktopClient.php` |
| `src/MCPClients/CodexClient.php` | `includes/MCPClients/CodexClient.php` |
| `src/MCPClients/CursorClient.php` | `includes/MCPClients/CursorClient.php` |
| `src/MCPClients/CustomClient.php` | `includes/MCPClients/CustomClient.php` |
| `src/MCPClients/GitHubCopilotClient.php` | `includes/MCPClients/GitHubCopilotClient.php` |
| `src/MCPClients/VSCodeClient.php` | `includes/MCPClients/VSCodeClient.php` |

### Decisions required before starting
1. `Controller.php` registers hooks on `init`. With the new `Loader` pattern, should it register hooks itself or delegate to `Main::define_admin_hooks()`?
   - **Recommended:** Keep Controller self-contained (register its own hooks in `__construct`). Main just instantiates it.
2. How is `Controller` instantiated? From `Main::load_hooks()` or `Main::run()`?
   - **Recommended:** Instantiate in `Main::load_hooks()` so it's part of the deferred boot.

### Namespace change for this phase
```
ACROSSAI_MCP_MANAGER\MCP\Controller          →  AcrossAI_MCP_Manager\Includes\MCP\Controller
ACROSSAI_MCP_MANAGER\MCPClients\*            →  AcrossAI_MCP_Manager\Includes\MCPClients\*
```

### Acceptance criteria
- MCP adapter boots when at least one server is enabled
- `Controller::get_status()` returns `'running'` or `'disabled'` (not `'not-found'`)
- No hook registration errors

---

## Phase 4 — Admin UI
**Branch:** `feature/issue-3`
**Depends on:** Phase 2 complete (needs DB), Phase 3 complete (Settings reads MCP status)
**Touches:** `admin/`

### What to migrate
| Old file | New file |
|---|---|
| `src/Admin/Settings.php` | `admin/Settings.php` |
| `src/Admin/SettingsRenderer.php` | `admin/SettingsRenderer.php` |
| `src/Admin/MCPServerListTable.php` | `admin/ListTables/MCPServerListTable.php` |
| `src/Admin/CliAuthLogListTable.php` | `admin/ListTables/CliAuthLogListTable.php` |
| `src/Admin/ConnectorAuditLogListTable.php` | `admin/ListTables/ConnectorAuditLogListTable.php` |
| `src/Admin/ApplicationPasswords.php` | `admin/ApplicationPasswords.php` |

### Decisions required before starting
1. The old `admin/Partials/Menu.php` stub already exists in feature/issue-3. Should Settings.php replace it entirely, or should Menu.php remain as a thin wrapper?
   - **Recommended:** Keep Menu.php as the hook registration point (`admin_menu`), have it instantiate Settings. This matches the existing `Main::define_admin_hooks()` pattern.
2. `Settings.php` is 2,615 lines — it mixes menu registration, list rendering, and edit form rendering. Split or keep as-is for now?
   - **Recommended:** Keep as-is for this migration. Split into separate classes is a separate refactor issue.
3. `Settings.php` references `AccessControlManager` from `WPBoilerplate\AccessControl`. Is this vendor package available in feature/issue-3?
   - **Check:** Confirm `vendor/` includes `wpboilerplate/access-control` before Phase 4.

### Namespace change for this phase
```
ACROSSAI_MCP_MANAGER\Admin\Settings                  →  AcrossAI_MCP_Manager\Admin\Settings
ACROSSAI_MCP_MANAGER\Admin\SettingsRenderer           →  AcrossAI_MCP_Manager\Admin\SettingsRenderer
ACROSSAI_MCP_MANAGER\Admin\MCPServerListTable         →  AcrossAI_MCP_Manager\Admin\ListTables\MCPServerListTable
ACROSSAI_MCP_MANAGER\Admin\CliAuthLogListTable        →  AcrossAI_MCP_Manager\Admin\ListTables\CliAuthLogListTable
ACROSSAI_MCP_MANAGER\Admin\ConnectorAuditLogListTable →  AcrossAI_MCP_Manager\Admin\ListTables\ConnectorAuditLogListTable
ACROSSAI_MCP_MANAGER\Admin\ApplicationPasswords       →  AcrossAI_MCP_Manager\Admin\ApplicationPasswords
```

### Acceptance criteria
- Admin menu item "AcrossAI MCP Manager" appears under Settings
- Server list page renders with existing DB rows
- Edit/toggle actions work without nonce errors

---

## Phase 5 — OAuth, REST API, and Frontend Auth
**Branch:** `feature/issue-3`
**Depends on:** Phases 2, 3, 4 complete
**Touches:** `includes/OAuth/`, `includes/REST/`, `public/`

### What to migrate
| Old file | New file |
|---|---|
| `src/OAuth/ClaudeConnectors.php` | `includes/OAuth/ClaudeConnectors.php` |
| `src/OAuth/AuthorizeController.php` | `includes/OAuth/AuthorizeController.php` |
| `src/OAuth/AuthorizationCodeResponseType.php` | `includes/OAuth/AuthorizationCodeResponseType.php` |
| `src/OAuth/Storage.php` | `includes/OAuth/Storage.php` |
| `src/REST/CliController.php` | `includes/REST/CliController.php` |
| `src/Frontend/FrontendAuth.php` | `public/FrontendAuth.php` |

### Decisions required before starting
1. `ClaudeConnectors.php` (1,571 lines) registers rewrite rules during activation. These currently live in the main entry file. Where do they go in the new structure?
   - **Recommended:** Move to `Activator::activate()` — that's the right place for flush_rewrite_rules() calls.
2. `CliController.php` registers REST routes on `rest_api_init`. Should it self-register or go through Loader?
   - **Recommended:** Self-register in `__construct` — REST controllers are a natural self-contained unit.
3. `FrontendAuth.php` registers a custom query var and rewrite rule. It uses `FrontendAuth::PAGE_SLUG` and `FrontendAuth::QUERY_VAR` constants. The activation hook references these constants. Does moving FrontendAuth to `public/` break the Activator's reference?
   - **Check:** Ensure `Activator.php` has access to `AcrossAI_MCP_Manager\Public\FrontendAuth` before migrating.
4. `AccessControlManager` integration (`enforce_mcp_access_control` filter on `rest_pre_dispatch`) — this is wired in `Plugin::__construct()` in main. In the new structure it belongs in `Main::load_hooks()`.

### Namespace change for this phase
```
ACROSSAI_MCP_MANAGER\OAuth\ClaudeConnectors                  →  AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors
ACROSSAI_MCP_MANAGER\OAuth\AuthorizeController               →  AcrossAI_MCP_Manager\Includes\OAuth\AuthorizeController
ACROSSAI_MCP_MANAGER\OAuth\AuthorizationCodeResponseType     →  AcrossAI_MCP_Manager\Includes\OAuth\AuthorizationCodeResponseType
ACROSSAI_MCP_MANAGER\OAuth\Storage                           →  AcrossAI_MCP_Manager\Includes\OAuth\Storage
ACROSSAI_MCP_MANAGER\REST\CliController                      →  AcrossAI_MCP_Manager\Includes\REST\CliController
ACROSSAI_MCP_MANAGER\Frontend\FrontendAuth                   →  AcrossAI_MCP_Manager\Public\FrontendAuth
```

### Acceptance criteria
- `GET /wp-json/acrossai-mcp-manager/v1/health` returns `200`
- OAuth metadata endpoint `/.well-known/oauth-authorization-server` responds
- Frontend CLI auth page (`/cli-auth/`) loads without 404
- `rest_pre_dispatch` access control filter fires correctly

---

## Spec-Kit Prompt for Each Phase

When ready to implement a phase, run this in Claude Code (on `feature/issue-3` branch):

```
/speckit.specify

Phase: [PHASE NUMBER - PHASE NAME] of acrossai-mcp-manager migration from main to feature/issue-3.

Context:
- Migrating [LIST OLD FILES] from namespace ACROSSAI_MCP_MANAGER\* to AcrossAI_MCP_Manager\*
- Target directory: [TARGET DIR]
- The new structure uses AcrossAI_MCP_Manager\Includes\Main as the plugin bootstrap with a Loader pattern
- Jetpack autoloader is already wired in includes/Main.php::load_composer_dependencies()
- No logic changes — this is a namespace rename + directory move only

Decisions already made:
[PASTE RELEVANT DECISIONS FROM THIS DOC]

Acceptance criteria:
[PASTE FROM THIS DOC]
```

---

## Pre-Migration Checklist (Do Before Phase 1)

- [ ] Confirm `vendor/autoload_packages.php` exists on `feature/issue-3` (run `composer install`)
- [ ] Confirm `wpboilerplate/access-control` is in `composer.json` on `feature/issue-3`
- [ ] Confirm `\WP\MCP\Plugin` (the MCP adapter) is available via vendor on `feature/issue-3`
- [ ] Decide: will the `src/` directory be deleted after all phases are complete, or kept for a transition period?
- [ ] Confirm PHP version target: main requires PHP 7.4, feature/issue-3 boilerplate says PHP 8.0 — which wins?

---

## File Count Summary

| Phase | Files | Approx lines |
|---|---|---|
| 1 — Core Boot | 5 files | ~500 |
| 2 — Database | 3 files | ~1,400 |
| 3 — MCP + Clients | 9 files | ~1,200 |
| 4 — Admin UI | 6 files | ~3,800 |
| 5 — OAuth/REST/Frontend | 6 files | ~3,100 |
| **Total** | **29 files** | **~10,000** |

---

## Open Questions

1. **PHP version**: main targets PHP 7.4, feature/issue-3 boilerplate targets PHP 8.0. The code uses typed properties and `::class` but no PHP 8.0-only syntax — likely fine to target 8.0, but needs a quick compat scan.
2. **`src/` directory fate**: Delete after Phase 5? Or keep as legacy for one release cycle?
3. **`assets/` files**: main has `assets/admin.css`, `assets/admin.js`, `assets/frontend-auth.css`, `assets/frontend-oauth.css`. feature/issue-3 has `build/`. Are the assets already built in `build/`, or does `assets/` need migrating too?
4. **`agent.md` / `claude.md`**: main has these files. feature/issue-3 has `AGENTS.md`. Should they be merged?
5. **Spec-kit step**: Should each phase go through the full spec-kit workflow (specify → plan → tasks → implement → analyze), or just spec-kit.implement since logic is not changing?
   - **Recommended:** Use `speckit.implement` only for phases 1-4 (pure move). Use full workflow for Phase 5 (OAuth has security implications worth a security review pass).
