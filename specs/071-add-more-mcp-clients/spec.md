# Feature 071 — Additional MCP Client Integrations

**Status**: Implemented
**Branch**: `071-add-more-mcp-clients`
**Date**: 2026-08-19

## Summary

Add 8 new MCP client integrations to the plugin's `MCPClients` module so the WordPress admin's client picker and (future) `/wp-json/acrossai-mcp-manager/v1/clients` endpoint expose config templates for a broader set of AI coding tools. This closes the biggest visible gaps against competing WordPress MCP plugins for free-tier IDE users, while intentionally NOT overlapping with the 5 OAuth-based one-click Connectors that ship as part of the paid `acrossai-pro` companion (Claude / ChatGPT / Grok / Gemini / Cursor).

## User Stories

**US1 — Windsurf user (top priority)** — As a Windsurf IDE user, I want the plugin to generate a valid `~/.codeium/windsurf/mcp_config.json` snippet for my MCP server so I can connect Windsurf to my WordPress site without hand-editing config files.

**US2 — Zed user (top priority)** — As a Zed editor user, I want the plugin to generate a valid `~/.config/zed/settings.json` snippet using the `context_servers` top-level key with the required `source: 'custom'` + `enabled: true` prefix so my Zed instance can invoke my MCP server.

**US3 — Cline / Roo Code / Kilo Code user** — As a user of any of the three Cline-family VS Code extensions, I want the plugin to generate a valid `mcpServers` config so I can paste it into the extension's MCP settings without translating between formats.

**US4 — Amazon Q Developer user** — As an AWS Amazon Q Developer user, I want the plugin to generate a valid `~/.aws/amazonq/mcp.json` snippet so my Q instance can invoke my MCP server.

**US5 — OpenCode user** — As an OpenCode terminal user, I want the plugin to generate a valid `~/.config/opencode/opencode.json` snippet using the `mcp` top-level key + `type: 'local'` + `command: [array]` shape so my OpenCode instance can invoke my MCP server.

**US6 — Antigravity user** — As an Antigravity IDE/CLI user, I want the plugin to generate a valid `~/.gemini/config/mcp_config.json` snippet so my Antigravity instance can invoke my MCP server.

## Functional Requirements

**FR-001** — MUST add 8 new final concrete classes extending `AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient`:
- `WindsurfClient` (slug: `windsurf`, priority 72)
- `ZedClient` (slug: `zed`, priority 73)
- `ClineClient` (slug: `cline`, priority 74)
- `RooCodeClient` (slug: `roo-code`, priority 75)
- `KiloCodeClient` (slug: `kilo-code`, priority 76)
- `AmazonQClient` (slug: `amazon-q`, priority 77)
- `OpenCodeClient` (slug: `opencode`, priority 78)
- `AntigravityClient` (slug: `antigravity`, priority 79)

**FR-002** — MUST use the shared `@automattic/mcp-wordpress-remote` npx bridge with `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD` env vars in every entry. Do NOT invent bespoke bridge packages per client.

**FR-003** — MUST register all 8 new classes in `AbstractMCPClient::DEFAULT_CLIENT_CLASSES` between `GeminiClient` and `CustomClient` so `CustomClient` stays the last-sorted fallback and priority order is preserved.

**FR-004** — MUST NOT add clients that overlap with the acrossai-pro OAuth Connectors (Claude / ChatGPT / Grok / Gemini / Cursor) — those ship as one-click cloud OAuth in the paid companion, not as JSON-config generators.

**FR-005** — Zed's entry MUST use `context_servers` top-level key AND include `source: 'custom'` + `enabled: true` at the entry level (required by Zed's runtime to load user-configured MCP servers).

**FR-006** — OpenCode's entry MUST use the `mcp` top-level key AND the entry MUST have `type: 'local'`, `command` as an array of tokens (not the standard `command: 'npx' + args: [...]` split), and env vars under `environment` (not `env`) — OpenCode's config format differs from every other client.

**FR-007** — Every new class MUST implement the F034 metadata contract in full: `get_client_slug()`, `get_client_name()`, `get_config_snippet()`, `get_icon()`, `get_description()` (translated), `get_config_file()` (path hint), `get_top_level_key()`, `get_instructions()` (translated), `get_priority()`. Empty-string defaults from `AbstractMCPClient` are NOT acceptable — every field must be populated.

**FR-008** — MUST NOT break the F034 self-contained subsystem contract (`D35`). Renderers, admin partials, and other consumers MUST continue to delegate to `AbstractMCPClient::get_all_registered_clients()` — no consumer should hardcode a knowledge of any specific new client.

## Success Criteria

**SC-001** — `AbstractMCPClient::get_all_registered_clients()` returns 16 clients (was 8) in the deterministic priority-sorted order: `claude-desktop`, `claude-code`, `vscode`, `github-copilot`, `codex`, `cursor`, `gemini`, `windsurf`, `zed`, `cline`, `roo-code`, `kilo-code`, `amazon-q`, `opencode`, `antigravity`, `custom`.

**SC-002** — PHPUnit `GetAllRegisteredClientsTest` `testDefaultStateReturnsBuiltinsInPriorityOrder` passes with `assertCount(16, ...)` and the full expected slug array.

**SC-003** — Zed's `get_config_snippet()` output serializes to JSON containing `"context_servers"` at top level AND every server entry has `"source": "custom"` and `"enabled": true`.

**SC-004** — OpenCode's `get_config_snippet()` output serializes to JSON containing `"mcp"` at top level AND every server entry has `"type": "local"`, `"command"` is a JSON array, and env vars are under `"environment"` (not `"env"`).

**SC-005** — PHPCS clean on all 8 new client files + AbstractMCPClient + updated test.

**SC-006** — The wizard's Step 7 (`Step7_MethodGrid`) and Step 11 (`Step11_ClientDetail`) surface the 8 new clients without any code change — they consume the client list via `ConnectionMethodRegistry::get_clients()` which already delegates to `get_all_registered_clients()`.

## Out of Scope

- **Companion plugin OAuth Connectors** — Claude / ChatGPT / Grok / Gemini / Cursor as one-click OAuth ship in `acrossai-pro`, not this plugin. FR-004 explicitly excludes them.
- **CLI (`@acrossai/mcp-manager`) parity** — the parallel `CLIENTS` object in the npm package's `lib/clients.js` was already updated in a separate release (v1.0.18/1.0.19). Unifying the two registries into a single JSON source of truth is the scope of the deferred F070 issue (#83), not this feature.
- **New OS-path variants or probing** — CLI-side XDG_CONFIG_HOME + VS Code Insiders/Snap/Flatpak probing is CLI concern, not plugin concern.
