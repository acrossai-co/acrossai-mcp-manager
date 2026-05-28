# Phase 5 — MCP Client Classes Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/MCPClients/AbstractMCPClient.php
  src/MCPClients/ClaudeCodeClient.php
  src/MCPClients/ClaudeDesktopClient.php
  src/MCPClients/CodexClient.php
  src/MCPClients/CursorClient.php
  src/MCPClients/CustomClient.php
  src/MCPClients/GitHubCopilotClient.php
  src/MCPClients/VSCodeClient.php

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new files into:
  includes/MCPClients/AbstractMCPClient.php
  includes/MCPClients/ClaudeCodeClient.php
  includes/MCPClients/ClaudeDesktopClient.php
  includes/MCPClients/CodexClient.php
  includes/MCPClients/CursorClient.php
  includes/MCPClients/CustomClient.php
  includes/MCPClients/GitHubCopilotClient.php
  includes/MCPClients/VSCodeClient.php
```

---

## What this phase covers

Migrates 8 MCP client classes from `src/MCPClients/` to `includes/MCPClients/`.
These are pure service classes (no hooks) — they represent different AI tool clients
that can connect to the MCP server (Claude Desktop, Claude Code, Cursor, etc.).

### Files to migrate

| Old | New |
|---|---|
| `src/MCPClients/AbstractMCPClient.php` | `includes/MCPClients/AbstractMCPClient.php` |
| `src/MCPClients/ClaudeCodeClient.php` | `includes/MCPClients/ClaudeCodeClient.php` |
| `src/MCPClients/ClaudeDesktopClient.php` | `includes/MCPClients/ClaudeDesktopClient.php` |
| `src/MCPClients/CodexClient.php` | `includes/MCPClients/CodexClient.php` |
| `src/MCPClients/CursorClient.php` | `includes/MCPClients/CursorClient.php` |
| `src/MCPClients/CustomClient.php` | `includes/MCPClients/CustomClient.php` |
| `src/MCPClients/GitHubCopilotClient.php` | `includes/MCPClients/GitHubCopilotClient.php` |
| `src/MCPClients/VSCodeClient.php` | `includes/MCPClients/VSCodeClient.php` |

### Why this is simpler than other phases

These classes have **no WordPress hooks**. They are pure data/service classes
that generate configuration snippets or connection strings for each AI client.
The only change needed is updating the namespace prefix.

---

## Spec-Kit Steps

### Step 1: `/speckit.specify`

```
/speckit.specify

Feature: MCP Client Classes — Pure Service Layer
Feature number: 005

The plugin supports multiple AI tool clients that can connect to an MCP server.
Each client class generates the configuration snippet or connection information
a user needs to set up that particular client.

Supported clients:
- AbstractMCPClient   — base class with shared logic (getServerUrl, getAuthToken, etc.)
- ClaudeCodeClient    — Claude Code CLI configuration
- ClaudeDesktopClient — Claude Desktop claude_desktop_config.json snippet
- CodexClient         — OpenAI Codex configuration
- CursorClient        — Cursor IDE MCP configuration
- CustomClient        — Generic/custom client template
- GitHubCopilotClient — GitHub Copilot MCP configuration
- VSCodeClient        — VS Code MCP extension configuration

Functional requirements:
1. Each concrete client extends AbstractMCPClient.
2. Each class exposes at minimum:
   - get_config_snippet(string $server_url, string $auth_token): string|array
     Returns the formatted configuration the user copies into their client.
   - get_client_name(): string  — human-readable label
   - get_client_slug(): string  — machine slug (e.g. 'claude-code')
3. No WordPress hooks — these are pure service classes.
4. AbstractMCPClient provides shared helper methods:
   - build_server_url(array $server_row): string
   - redact_token(string $token): string (for display in UI)
5. These classes are instantiated by the Admin Settings renderer to show
   per-client setup instructions on the server edit page.
6. Namespace: AcrossAI_MCP_Manager\Includes\MCPClients
   Files: includes/MCPClients/*.php
```

### Step 2: `/speckit.plan`

```
/speckit.plan

Migration approach:
- Copy the 8 files from src/MCPClients/ to includes/MCPClients/
- Replace namespace from ACROSSAI_MCP_MANAGER\MCPClients
  to AcrossAI_MCP_Manager\Includes\MCPClients in every file
- Update any internal cross-references between the client classes
- Update composer.json PSR-4 autoload map to include:
  "AcrossAI_MCP_Manager\\Includes\\MCPClients\\": "includes/MCPClients/"
- Run composer dump-autoload

No hook wiring needed in Main.php — these are injected into Settings when needed:
  $settings = new Admin\Partials\Settings(
      new Includes\Database\MCPServer\Query(),
      new Admin\Partials\ApplicationPasswords(),
      Includes\MCPClients\AbstractMCPClient::get_all_clients()  // factory method
  );
```

### Step 3 + 4: `/speckit.tasks` then `/speckit.implement`

---

## Success Criteria

- [ ] All 8 files exist in `includes/MCPClients/`
- [ ] Namespace updated in all files: `AcrossAI_MCP_Manager\Includes\MCPClients`
- [ ] `composer dump-autoload` exits 0
- [ ] No WordPress hooks in any client class
- [ ] Admin settings page can instantiate all clients and render config snippets
