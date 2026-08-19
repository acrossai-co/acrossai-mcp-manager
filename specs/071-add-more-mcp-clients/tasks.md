# Tasks — F071 Additional MCP Client Integrations

**Feature**: [spec.md](./spec.md) · [plan.md](./plan.md)
**Branch**: `071-add-more-mcp-clients`
**Total**: 12 tasks (10 implementation + 2 verification)

## Phase 1 — Client Classes (parallel-safe)

Every task in this phase creates ONE new file. All can land in parallel — no cross-file dependencies. Order within the phase = priority order of the target sub-nav slot.

- [x] **T001** [P] Create `includes/MCPClients/WindsurfClient.php` — slug `windsurf`, name "Windsurf", icon 🏄, priority 72, config file `~/.codeium/windsurf/mcp_config.json`, top-level key `mcpServers`, standard `command:'npx' + args + env` entry shape.

- [x] **T002** [P] Create `includes/MCPClients/ZedClient.php` — slug `zed`, name "Zed", icon ⚡, priority 73, config file `~/.config/zed/settings.json`, top-level key `context_servers`. **FR-005 non-standard**: entry MUST include `source: 'custom'` + `enabled: true` prefix before the standard `command`/`args`/`env` fields.

- [x] **T003** [P] Create `includes/MCPClients/ClineClient.php` — slug `cline`, name "Cline", icon 🤖, priority 74, config file `cline_mcp_settings.json` (via VS Code sidebar UI — extension globalStorage path is OS-variant; instructions field directs to sidebar), top-level key `mcpServers`, standard entry shape.

- [x] **T004** [P] Create `includes/MCPClients/RooCodeClient.php` — slug `roo-code`, name "Roo Code", icon 🦘, priority 75, config file `.roo/mcp.json` (project-scoped example; global path via VS Code sidebar), top-level key `mcpServers`, standard entry shape.

- [x] **T005** [P] Create `includes/MCPClients/KiloCodeClient.php` — slug `kilo-code`, name "Kilo Code", icon ⚙️, priority 76, config file `.kilocode/mcp.json` (project-scoped example; global path via VS Code sidebar), top-level key `mcpServers`, standard entry shape.

- [x] **T006** [P] Create `includes/MCPClients/AmazonQClient.php` — slug `amazon-q`, name "Amazon Q Developer", icon ☁️, priority 77, config file `~/.aws/amazonq/mcp.json`, top-level key `mcpServers`, standard entry shape.

- [x] **T007** [P] Create `includes/MCPClients/OpenCodeClient.php` — slug `opencode`, name "OpenCode", icon 📟, priority 78, config file `~/.config/opencode/opencode.json`, top-level key `mcp`. **FR-006 non-standard**: entry MUST use `type: 'local'`, `command` as JSON array of tokens `['npx', '-y', '@automattic/mcp-wordpress-remote@latest']`, env vars under `environment` (not `env`).

- [x] **T008** [P] Create `includes/MCPClients/AntigravityClient.php` — slug `antigravity`, name "Antigravity", icon 🛰️, priority 79, config file `~/.gemini/config/mcp_config.json`, top-level key `mcpServers`, standard entry shape. Same entry covers Antigravity IDE + CLI (both share config location).

## Phase 2 — Registry Wiring

Sequential — depends on Phase 1 completion.

- [x] **T009** Update `AbstractMCPClient::DEFAULT_CLIENT_CLASSES` in `includes/MCPClients/AbstractMCPClient.php` — insert the 8 new FQNs between `GeminiClient::class` and `CustomClient::class` in priority order:
  ```
  WindsurfClient::class,
  ZedClient::class,
  ClineClient::class,
  RooCodeClient::class,
  KiloCodeClient::class,
  AmazonQClient::class,
  OpenCodeClient::class,
  AntigravityClient::class,
  ```
  CustomClient stays last at priority 80 so it renders as the manual-config fallback tile at the bottom of the sub-nav.

## Phase 3 — Test Updates

Sequential — depends on T009.

- [x] **T010** Update `tests/phpunit/MCPClients/GetAllRegisteredClientsTest.php`:
  - Rename `testDefaultStateReturnsEightBuiltinsInPriorityOrder` → `testDefaultStateReturnsBuiltinsInPriorityOrder`.
  - Bump `assertCount( 8, $clients, ... )` → `assertCount( 16, $clients, ... )` in the default-state test.
  - Update the expected slug array to list all 16 slugs in the new priority order.
  - Bump `assertCount( 8, $clients, ... )` in `testDuplicateSlugLaterWinsAndTakesOverrideClassesPriority` → `assertCount( 16, ... )` (dedup test — total stays at 16 because the fake dup takes over an existing slot, not adds one).
  - Bump `assertCount( 8, $clients, ... )` in the invalid-FQN-skip test → `assertCount( 16, ... )` (invalid entries filtered; 16 built-ins remain).
  - Bump `assertCount( 8, $slugs, ... )` in the bad-slug-reject test → `assertCount( 16, ... )`.

## Phase 4 — Verification

- [x] **T011** Run PHPCS on all touched files:
  ```
  ./vendor/bin/phpcs includes/MCPClients/WindsurfClient.php \
                     includes/MCPClients/ZedClient.php \
                     includes/MCPClients/ClineClient.php \
                     includes/MCPClients/RooCodeClient.php \
                     includes/MCPClients/KiloCodeClient.php \
                     includes/MCPClients/AmazonQClient.php \
                     includes/MCPClients/OpenCodeClient.php \
                     includes/MCPClients/AntigravityClient.php \
                     includes/MCPClients/AbstractMCPClient.php \
                     tests/phpunit/MCPClients/GetAllRegisteredClientsTest.php
  ```
  MUST return zero errors. Warnings acceptable (translated strings without translator comments are the usual noise floor).

- [x] **T012** Run `npm run build` — must exit 0. Wizard's Step 7 method grid and Step 11 client detail sub-nav auto-pick up the 8 new clients via `ConnectionMethodRegistry::get_clients() → AbstractMCPClient::get_all_registered_clients()`; no React-side code changes.

## Format Validation

Every task above follows `- [x] TXXX [P?] Description with file path`:
- All 12 tasks start with `- [x]` (implementation complete before this doc landed — spec-kit-shaped retrospective documentation)
- All 12 tasks have sequential IDs T001-T012
- `[P]` marker only on parallelizable tasks (Phase 1 — 8 independent file creates)
- Every task cites a concrete file path or shell command

**Note on retrospective documentation**: This spec was written after the client classes had already been implemented in an earlier session (as part of exploratory work). Per the plugin's spec-kit convention, all shipped features get spec/plan/tasks docs before merging, so this documentation is being landed alongside the implementation in the same PR.
