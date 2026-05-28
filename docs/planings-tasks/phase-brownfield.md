# Phase Brownfield — Auto-Discover Source Architecture

> **Normally run before Phase 0 — but Phase 0 is already complete.**
> The Brownfield extension is installed at:
> `.specify/extensions/brownfield/`
>
> It gives every subsequent `/speckit.specify` phase a pre-built picture of
> what the source plugin actually does, so specs are written from real code
> rather than memory.

> **Status as of 2026-05-29:**
> Phase 0 (constitution + memory bootstrap) is **already complete**.
> - `.specify/memory/CONSTITUTION.md` v1.0.0 — ratified
> - `docs/memory/` — PROJECT_CONTEXT, ARCHITECTURE, DECISIONS, INDEX all populated
>
> Steps 1–3 below (scan → bootstrap → validate) can still be run to auto-generate
> draft `specs/` folders. Step 2 (`bootstrap`) will not overwrite the existing
> constitution — it generates spec-kit config templates only.
> Start directly at **Step 1** if you want the draft spec artifacts.
> Skip to **Step 4** if you prefer to write specs from the phase files directly.

---

## Why this phase exists

The four Brownfield commands work together as a pre-flight pipeline:

```
/speckit.brownfield.scan       ← reads source repo, builds project profile
         ↓
/speckit.brownfield.bootstrap  ← generates tailored spec-kit config for THIS repo
         ↓
/speckit.brownfield.validate   ← confirms the config matches the actual code
         ↓
/speckit.brownfield.migrate    ← reverse-engineers spec/plan/tasks for each module
```

After this phase you will have a `specs/` folder with draft `spec.md`, `plan.md`,
and `tasks.md` files for each source module. Each subsequent phase then
**refines** those drafts into the WPBoilerplate target structure — instead of
writing specs from scratch.

---

## Repo Paths

```
SOURCE repo (scan and migrate FROM this):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

TARGET repo (run all commands from here — where .specify/ lives):
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/
```

---

## Step 1 — Scan the source repo

Run from the **target** repo. Point the scan at the source repo directory.

```
/speckit.brownfield.scan /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

**What to expect:** A Project Profile report covering:
- Tech stack: PHP 7.4+, WordPress 5.9+, Jetpack autoloader, `bshaffer/oauth2-server-php`, `wordpress/mcp-adapter 0.5.0`, `wpboilerplate/wpb-access-control`
- Architecture pattern: WordPress plugin (singleton boot, direct add_action in constructors)
- Module map: Core, Admin, Database, Frontend, MCP, MCPClients, OAuth, REST
- Conventions: `ACROSSAI_MCP_MANAGER\` namespace, `src/` layout, flat `assets/`
- REST namespace: `acrossai-mcp-manager/v1` with 5 endpoints
- Auth: entirely transient-based (`acrossai_cli_auth_*`, `acrossai_session_*`), TTL 300 s / 600 s
- No separate DB table for auth codes — transients only

Save the output — you'll reference it in Step 2.

---

## Step 2 — Bootstrap tailored spec-kit config

```
/speckit.brownfield.bootstrap
```

This reads the scan output and generates spec-kit configuration tailored to
the source plugin's patterns. It may update:
- `.specify/memory/` context files
- Template defaults

Review what it generates. If it tries to enforce the OLD plugin's conventions
(e.g., `src/` layout, `ACROSSAI_MCP_MANAGER\` namespace), override them:
the constitution already defines the NEW target conventions.

---

## Step 3 — Validate the config

```
/speckit.brownfield.validate
```

Verifies the bootstrap output matches the actual project structure.
Fix any mismatches it reports before moving to Step 4.

---

## Step 4 — Migrate each source module

Run `/speckit.brownfield.migrate` once per module. Each run reverse-engineers
`spec.md`, `plan.md`, and `tasks.md` for that module's existing code.

### 4a — Core Boot

```
/speckit.brownfield.migrate src/Core — migrate the Core module (Plugin.php, Compat.php, polyfills.php) from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/001-core-boot/spec.md`, `plan.md`, `tasks.md` (all tasks marked `[x]` — already built)

### 4b — Admin UI

```
/speckit.brownfield.migrate src/Admin — migrate the Admin module (Settings, SettingsRenderer, ApplicationPasswords, list tables) from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/002-admin-ui/`

### 4c — MCP Controller

```
/speckit.brownfield.migrate src/MCP — migrate the MCP Controller from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/003-mcp-controller/`

### 4d — MCP Clients

```
/speckit.brownfield.migrate src/MCPClients — migrate all 8 MCP client classes from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/004-mcp-clients/`

### 4e — OAuth / Claude Connectors

```
/speckit.brownfield.migrate src/OAuth — migrate the OAuth module (ClaudeConnectors, AuthorizationCodeResponseType, AuthorizeController, Storage) from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/005-oauth/`

### 4f — Frontend Auth

```
/speckit.brownfield.migrate src/Frontend — migrate the FrontendAuth page from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/006-frontend/`

### 4g — REST API

```
/speckit.brownfield.migrate src/REST — migrate the CLI REST controller from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/007-rest-api/`

### 4h — Database (context only — no target module)

> The source plugin has two DB table classes (`MCPServerTable`, `CliAuthLogTable`)
> in `src/Database/`. There is **no separate Database migration phase** — these classes
> will be re-implemented fresh inside `includes/Database/` as part of the Core Boot
> phase (Activator) and consumed by Admin and REST phases.
>
> Run this migrate step to capture what those classes do; mark all tasks `[x]`
> since the behaviour is already documented in `docs/memory/` and `AGENTS.md`.

```
/speckit.brownfield.migrate src/Database — read-only scan of MCPServerTable and CliAuthLogTable from /Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/
```

Expected output: `specs/008-database/` — mark all tasks `[x]` (informational only).
Target files will be created in `includes/Database/MCPServer/` and `includes/Database/CliAuthLog/`
as part of **Phase 2 (Core Boot)**, not as a standalone phase.

---

## What to do with the migrated specs

The migrated `spec.md` / `plan.md` / `tasks.md` files describe what the **old code does**.
Before running `/speckit.implement` for each phase, update them to describe
what the **new code should do** (WPBoilerplate structure, correct namespace,
Loader hook registration, singleton pattern). Use the relevant phase file in
`docs/planings-tasks/` as the guide for those changes.

The Brownfield artifacts are your starting point — the phase files are your
refining instructions.

---

## Success Criteria

- [ ] `/speckit.brownfield.scan` produces a Project Profile for the source repo
- [ ] `/speckit.brownfield.bootstrap` runs without errors (constitution is NOT overwritten)
- [ ] `/speckit.brownfield.validate` reports no mismatches
- [ ] `specs/001-core-boot/` through `specs/007-rest-api/` all contain `spec.md`, `plan.md`, `tasks.md`
- [ ] `specs/008-database/` exists with all tasks marked `[x]` (informational context only)
- [ ] Each `tasks.md` has all tasks marked `[x]` (existing code) and lists any gaps found
- [ ] No source files in `acrossai-mcp-manager/` were modified (scan and migrate are non-destructive)
