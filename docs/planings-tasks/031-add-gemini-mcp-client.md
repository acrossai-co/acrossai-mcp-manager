# Planning: Add Gemini CLI as an MCP Client (Feature 031)

## Context

The server-edit **Clients** tab (`admin.php?page=acrossai_mcp_manager&action=edit&server={id}&tab=clients`) currently offers copy-paste MCP configurations for 7 AI clients: Claude Code, Claude Desktop, VS Code, GitHub Copilot, OpenAI Codex, Cursor, and a generic Custom client. Google's Gemini CLI is not yet represented — this feature adds an 8th card.

Gemini CLI's MCP config uses the same `mcpServers` top-level key + `command` / `args` / `env` shape as Claude Desktop (config file: `~/.gemini/settings.json`), so the implementation is a **near-verbatim mirror of `ClaudeDesktopClient` with different slug / name / metadata**. Same npx bridge (`@automattic/mcp-wordpress-remote@latest`), same WP Application Password Basic auth via env vars — no new abstraction, no new auth mechanism, no new render path.

Post-ship: the plugin ships 8 MCP clients + 1 generic Custom option.

---

## Files to touch

| File | Change |
|---|---|
| `includes/MCPClients/GeminiClient.php` | **NEW** (~55 lines). Copy `ClaudeDesktopClient.php` verbatim; rename class to `GeminiClient`; slug → `'gemini'`; name → `'Gemini CLI'`; update docblock to reference `~/.gemini/settings.json`. `get_config_snippet()` body identical. |
| `public/Renderers/MCPClientsBlock.php` | Two small deltas: (a) add `'gemini' => [ 'emoji' => '💎', 'description' => …, 'config_file' => '~/.gemini/settings.json', 'top_level_key' => 'mcpServers', 'instructions' => … ]` entry to the `CLIENT_META` const; (b) add `\AcrossAI_MCP_Manager\Includes\MCPClients\GeminiClient::class` to the `$default_classes` array next to `ClaudeDesktopClient::class`. |
| `tests/phpunit/MCPClients/fixtures/gemini-empty-token.json` | **NEW**. Byte-for-byte copy of `claude-desktop-empty-token.json`. |
| `tests/phpunit/MCPClients/fixtures/gemini-with-token.json` | **NEW**. Byte-for-byte copy of `claude-desktop-with-token.json`. |
| `tests/phpunit/MCPClients/ConcreteClientsTest.php` | Add `[ GeminiClient::class, 'gemini', 'Gemini CLI' ]` row to the `#[DataProvider]`. Add `use` import for `GeminiClient` if needed. |
| `tests/phpunit/MCPClients/AbstractMCPClientTest.php` | Canary: `assertCount( 7, … )` → `assertCount( 8, … )`. |
| `README.txt` | Add one bullet under `= Unreleased =` summarising Feature 031. |

---

## Reference files (READ, do not modify)

- `includes/MCPClients/AbstractMCPClient.php` — base contract + helper methods (`derive_server_key`, `current_username`, `safe_token`, factory `get_all_clients`). Do not touch.
- `includes/MCPClients/ClaudeDesktopClient.php` — the 58-line template GeminiClient mirrors.
- `public/Renderers/MCPClientsBlock.php` `CLIENT_META` const + `$default_classes` array + render path — render is client-agnostic; no changes needed to the rendering code, only metadata registration.
- `tests/phpunit/MCPClients/fixtures/claude-desktop-*.json` — the two fixtures Gemini's copies mirror.

## Constraints

- **Do NOT touch `AbstractMCPClient`.** Factory auto-discovers via glob — new class just needs to sit in the directory.
- **Do NOT add a singleton to `GeminiClient`.** MCPClients are stateless value producers per A11 exception; ctor omitted, no `$_instance` / `instance()`.
- **Do NOT add WordPress hooks in `GeminiClient`.** Pure service layer per FR-008 / FR-009 — no `add_action` / `add_filter` anywhere in the class.
- **Do NOT diverge from `ClaudeDesktopClient`'s `mcpServers` shape.** Gemini's `get_config_snippet()` output MUST be structurally identical (only the fixture filename differs from `claude-desktop-*`).
- **Do NOT touch the 7 existing golden fixtures.** Regression protection for the other clients.
- **Do NOT introduce a Gemini `AbstractConnectorProfile`.** AI Connectors (Feature 021) are OAuth-issuer profiles — orthogonal to MCP Clients. Easy to confuse; don't.
- **PHPStan L8 + PHPCS must pass on every touched file** per §VII DoD.
- **`mcpclients` PHPUnit suite must remain WP-free (SC-003).** No new test that requires the WordPress bootstrap.

---

## Verification

1. **PHPUnit `mcpclients` suite passes with 8 clients**:
   ```
   composer run test -- --testsuite mcpclients
   ```
   Expected: all existing assertions still green + new Gemini row exercised in `ConcreteClientsTest` + canary asserts 8 clients.

2. **PHPCS + PHPStan L8** clean on touched files:
   ```
   composer run phpcs && composer run phpstan
   ```

3. **Admin smoke test** on `admin.php?page=acrossai_mcp_manager&action=edit&server=1&tab=clients`:
   - Gemini card (💎 pill) appears in the sub-nav alongside Claude Desktop.
   - Clicking the Gemini pill shows: description, `~/.gemini/settings.json` config-file row, `mcpServers` top-level-key row, Configuration JSON textarea with the standard placeholder, "Generate New Application Password" button, "Copy Configuration" button, and the instructions callout.
   - Clicking "Generate New Application Password" — JS replaces the `(paste generated password here)` placeholder in the textarea with the real password.
   - Clicking "Copy Configuration" — clipboard receives the ready-to-paste JSON.

4. **README.txt changelog** — new Feature 031 bullet present under `= Unreleased =`.
