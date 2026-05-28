# Migration Planning: Main Branch → WPBoilerplate Structure

## What This Is

This directory contains spec-kit prompts for migrating every module from the
**main branch** (`acrossai-mcp-manager`) into the **feature/issue-3 branch**
(`acrossai-mcp-manager-new`) that follows the WPBoilerplate / `wp-plugin-development`
skill structure.

Each phase file contains the exact prompt text to paste into your spec-kit
session for that migration chunk.

---

## Source → Target: The Core Difference

| Aspect | Main Branch (old) | New Branch (target) |
|---|---|---|
| Namespace | `ACROSSAI_MCP_MANAGER\` | `AcrossAI_MCP_Manager\` |
| Boot pattern | Direct singleton `Core\Plugin` | Loader singleton `Includes\Main` |
| Hook registration | `add_action()` inside class constructors | Only via `$this->loader->add_action()` in `Main::define_admin_hooks()` / `define_public_hooks()` |
| Folder layout | `src/Admin/`, `src/Core/`, `src/Database/` … | `admin/Partials/`, `includes/`, `public/Partials/` |
| Assets | Flat `assets/*.css` / `assets/*.js` | `src/js/` + `src/scss/` → `build/` via webpack |
| Activation logic | Anonymous closures in plugin root | `includes/Activator::activate()` |

---

## Module Inventory

```
Main branch (src/)          →  New branch target location
─────────────────────────────────────────────────────────
Core/Plugin.php             →  includes/Main.php (merged into Loader boot)
Core/Compat.php             →  includes/Compat.php
Core/polyfills.php          →  included via load_composer_dependencies()

Admin/Settings              →  admin/Partials/Settings.php
Admin/SettingsRenderer      →  admin/Partials/SettingsRenderer.php
Admin/ApplicationPasswords  →  admin/Partials/ApplicationPasswords.php
Admin/MCPServerListTable    →  admin/Partials/MCPServerListTable.php
Admin/CliAuthLogListTable   →  admin/Partials/CliAuthLogListTable.php
Admin/ConnectorAuditLogListTable → admin/Partials/ConnectorAuditLogListTable.php

MCP/Controller              →  includes/MCP/Controller.php
MCPClients/Abstract*        →  includes/MCPClients/AbstractMCPClient.php
MCPClients/Claude*          →  includes/MCPClients/Claude*.php  (×2)
MCPClients/Codex*           →  includes/MCPClients/CodexClient.php
MCPClients/Cursor*          →  includes/MCPClients/CursorClient.php
MCPClients/Custom*          →  includes/MCPClients/CustomClient.php
MCPClients/GitHubCopilot*   →  includes/MCPClients/GitHubCopilotClient.php
MCPClients/VSCode*          →  includes/MCPClients/VSCodeClient.php

OAuth/ClaudeConnectors      →  includes/OAuth/ClaudeConnectors.php
OAuth/AuthorizationCode*    →  includes/OAuth/AuthorizationCodeResponseType.php
OAuth/AuthorizeController   →  includes/OAuth/AuthorizeController.php
OAuth/Storage               →  includes/OAuth/Storage.php

Frontend/FrontendAuth       →  public/Partials/FrontendAuth.php

REST/CliController          →  includes/REST/CliController.php

assets/admin.css            →  src/scss/backend.scss  → build/css/backend.css
assets/admin.js             →  src/js/backend.js      → build/js/backend.js
assets/frontend-auth.css    →  src/scss/frontend.scss → build/css/frontend.css
assets/frontend-oauth.css   →  src/scss/frontend-oauth.scss
```

---

## Migration Phases

```
Brownfield (pre-flight)
  ↓
Phase 0 (constitution)
  ↓
Phases 1–8 (implement each module, refining the Brownfield-generated specs)
```

Each phase 1–8 follows the spec-kit workflow:
`/speckit.specify` → `/speckit.plan` → `/speckit.tasks` → `/speckit.implement` → `/speckit.analyze`

| Phase | File | What it covers |
|---|---|---|
| Brownfield | `phase-brownfield.md` | Scan source repo + reverse-engineer specs for all modules |
| 0 | `phase-0-foundation.md` | Constitution + project context memo |
| 1 | `phase-2-core-boot.md` | Plugin singleton → Loader boot flow |
| 2 | `phase-3-admin.md` | Admin Settings + list tables |
| 3 | `phase-4-mcp-controller.md` | MCP Controller (server boot/registration) |
| 4 | `phase-5-mcp-clients.md` | MCP Client classes (7 clients) |
| 5 | `phase-6-oauth.md` | OAuth / Claude Connectors |
| 6 | `phase-7-frontend.md` | Frontend auth page |
| 7 | `phase-8-rest-api.md` | CLI REST controller |
| 8 | `phase-9-assets.md` | Assets → webpack build pipeline |

---

## Key Rules to Enforce in Every Phase (from wp-plugin-development skill)

1. **Never call `add_action()` directly inside a class constructor.** All hook registration
   must go through `$this->loader->add_action()` / `add_filter()` in `Main.php`.
2. **Constants only in `Main::define_constants()`** using the private `define()` guard.
3. **Admin UI classes live in `admin/Partials/`**, even if they belong to a feature module.
4. **`includes/` is for context-neutral shared code only** — no admin-specific classes.
5. **Activation logic belongs in `includes/Activator::activate()`**, not in anonymous closures.
6. **Assets read version + deps from `build/*.asset.php`** — never hardcoded.
7. **Text domain = `acrossai-mcp-manager`; load on `init`**, not `plugins_loaded`.
8. **Every REST endpoint must have a `permission_callback`** — never `__return_true` on mutating routes.
9. **Namespace is `AcrossAI_MCP_Manager\`** (new) not `ACROSSAI_MCP_MANAGER\` (old).

---

## Repo Paths (for agent reference)

```
SOURCE repo (read from, never modify):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

TARGET repo (this repo — write here):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/
```

Full file-by-file mapping: [`source-map.md`](source-map.md)

---

## How to Use These Files

1. **Start with `phase-brownfield.md`** — run the four Brownfield commands to scan the source repo and auto-generate draft specs for every module. This gives each subsequent phase a starting point instead of a blank page.
2. **Run `phase-0-foundation.md`** — set up the constitution and project context memo.
3. For each remaining phase:
   - Read the **Source Files to Read First** block — exact files to open from the source repo.
   - The Brownfield command will have already created a draft `spec.md` in `specs/` — refine it using the phase file's `/speckit.specify` prompt before running `/speckit.plan`.
   - Run `/speckit.plan` → `/speckit.tasks` → `/speckit.implement` → `/speckit.analyze`.
   - Check the **Success Criteria** before moving to the next phase.
4. After all phases: run the four pre-ship scripts from the skill.

```bash
node .agents/skills/wp-plugin-development/scripts/validate-structure.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/validate-security.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-deprecations.mjs --dir=.
node .agents/skills/wp-plugin-development/scripts/detect-rest-endpoints.mjs --dir=.
```
