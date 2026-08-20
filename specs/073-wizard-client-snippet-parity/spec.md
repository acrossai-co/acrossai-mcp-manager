# Feature 073 — Wizard Client Snippet Parity

**Status**: Implemented
**Branch**: `072-quick-setup-entry-points` (see plan.md § Branch / commit hygiene)
**Date**: 2026-08-20

## Summary

Bring the Quick Setup wizard's **Step 11 (MCP Client detail)** to full parity with the per-server edit page's **Clients** tab by threading a server context through `ConnectionMethodRegistry::get_clients()` so both surfaces render byte-identical Configuration JSON, Config File path, Top-Level Key, and per-client Instructions callout from one source of truth (`AbstractMCPClient` public methods). Zero changes to client classes, no new REST endpoint, no new React component.

## User Stories

**US1 — Operator on the wizard (top priority)** — As an operator setting up MCP for the first time via the Quick Setup wizard, I want to see the exact same Configuration JSON, Config File path, Top-Level Key, and paste instructions on Step 11 as I would on the per-server Clients tab, so I can complete setup without needing to open a second admin page.

**US2 — Operator switching between clients** — As an operator on Step 11, I want to click each of the 16 client pills (Claude Desktop, Claude Code, VS Code, GitHub Copilot, Codex, Cursor, Gemini CLI, Windsurf, Zed, Cline, Roo Code, Kilo Code, Amazon Q Developer, OpenCode, Antigravity, Custom Client) and see the correctly-shaped per-client JSON (including non-standard shapes like Zed's `context_servers` + `source: 'custom'` + `enabled: true` and OpenCode's `mcp` top-level key + `type: 'local'` + `command` array + `environment` env vars).

**US3 — Third-party integrator (Constitution §V Extensibility)** — As a companion-plugin author who registers a new client via the `acrossai_mcp_client_classes` filter (per F034), I want my client to auto-appear on Step 11 with the correct JSON + Config File + Top-Level Key + Instructions the moment my class is registered, with no wizard-side code change required.

## Functional Requirements

**FR-001** — MUST extend `ConnectionMethodRegistry::get_clients()` to accept an optional `?array $server = null` parameter. When `$server` is a raw MCPServer row (as returned by `MCPServerQuery::query()[0]->to_array()`), each returned client DTO MUST include a `config` field holding a pre-encoded JSON string produced via `$client->get_config_snippet( $server_url, '' )` using `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` — identical to the encoding at `MCPClientsBlock.php:230`.

**FR-002** — MUST resolve `$server_url` inside `get_clients()` identically to `MCPClientsBlock.php:224-226`: `rest_url( trailingslashit( $server['server_route_namespace'] ) . $server['server_route'] )`. No variant, no branching, no case-specific overrides.

**FR-003** — MUST always populate a new top-level DTO field `instructions` with `$client->get_instructions()`, regardless of whether `$server` was provided. The field is additive; existing consumers ignoring it MUST remain functional.

**FR-004** — MUST NOT populate the `config` field when `$server` is null. Backward-compat contract for the existing zero-arg call site in `ConnectionMethodRegistry::get_all()` (line 95) — memoized global discovery response stays byte-stable.

**FR-005** — `QuickSetupController::handle_state()` MUST resolve the wizard's active server from `$wizard_state['server_id']`, fetch the raw MCPServer row via `MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )[0]->to_array()`, and pass it to `ConnectionMethodRegistry::get_clients()`, replacing `$methods['clients']` with the server-scoped variant. When no server is selected yet (`server_id === 0`), the fallback (server-less DTOs, no `config` field) MUST apply.

**FR-006** — `Step11_ClientDetail.jsx` MUST render, in this order below the App Password notice: (a) Config File `qs-meta-row` (from `activeClient.meta.config_file`), (b) Top-Level Key `qs-meta-row` (from `activeClient.meta.top_level_key`, wrapped in `"`), (c) `<CodeBlock variant="pane">` with `activeClient.config`, (d) Instructions callout (`<Notice status="info">` with per-client `activeClient.instructions` paragraph + shared Access Control paragraph). Each of (a), (b), (d) MUST be conditionally rendered — omitted entirely if the corresponding DTO field is empty (mirrors PHP tab's `if ( '' !== $instructions )` guard at `MCPClientsBlock.php:252`).

**FR-007** — The shared Access Control paragraph in the Instructions callout MUST use the exact same translation key string as `MCPClientsBlock.php:256`, so translators only key it once.

**FR-008** — SCSS additions in `src/scss/quick-setup.scss` MUST define only `.qs-meta-row`, `.qs-meta-label`, `.qs-meta-value`. No changes to existing classes. Label width fixed at 120px so `Config File` and `Top-Level Key` values align between rows.

**FR-009** — MUST NOT add a new REST route. All new data flows through the existing `GET /wp-json/acrossai-mcp-manager/v1/quick-setup/state` response body under `methods.clients[]`.

**FR-010** — MUST NOT modify `AbstractMCPClient`, any of the 16 concrete client classes, `ClientsTab.php`, `MCPClientsBlock.php`, or `AbstractClientRenderer.php`. Zero risk to the existing Clients tab.

## Success Criteria

**SC-001** — For any given server and any given client, the JSON body rendered inside Step 11's `<CodeBlock>` is byte-identical to the body rendered inside the Clients tab's `<textarea>`. Verifiable via `curl /wp-json/acrossai-mcp-manager/v1/quick-setup/state` (with a valid nonce) and view-source of the Clients tab.

**SC-002** — Step 11's Config File row displays the exact string returned by `AbstractMCPClient::get_config_file()` for the active client. Step 11's Top-Level Key row displays the same string returned by `get_top_level_key()`, wrapped in double quotes.

**SC-003** — Step 11's Instructions callout renders the per-client string returned by `AbstractMCPClient::get_instructions()` when non-empty. When `get_instructions()` returns `''`, the entire callout (including the Access Control paragraph) is omitted — matches Clients tab behavior at `MCPClientsBlock.php:252`.

**SC-004** — PHPCS on the two touched PHP files (`public/Discovery/ConnectionMethodRegistry.php`, `includes/REST/QuickSetupController.php`) reports zero new violations beyond the pre-existing baseline (3 `error_log()` warnings in `QuickSetupController.php` — pre-F073).

**SC-005** — Registering a new `AbstractMCPClient` subclass via the `acrossai_mcp_client_classes` filter (per F034) causes it to appear on Step 11 with correct JSON + Config File + Top-Level Key + Instructions without any wizard-side code change.

## Out of Scope

- Reflowing the Clients tab UI. F073 is additive-only on the wizard side; the Clients tab is untouched.
- Introducing a shared React component. Both surfaces are already rendered by their native engine (PHP for the tab, React for the wizard); the parity is achieved at the DTO layer, not the UI layer.
- Adding a new REST route (e.g. `/quick-setup/clients/{server_id}`). The existing `/state` endpoint already carries `methods.clients`; scoping it per-server via the wizard's own `server_id` is the natural extension.
- OAuth Connector snippet parity on Step 10. The Connectors path is companion-plugin-owned (F040); a similar-shaped fix belongs there, not here.
- Copy-button behavior parity. The wizard's `CodeBlock` copy button already uses the platform's native clipboard API; the Clients tab's `<textarea>` + `copy-to-clipboard` handler is a separate implementation and stays as-is.
- Unit tests for `Step11_ClientDetail.jsx`. The step is a display-only React component with no branching logic worth asserting; manual smoke check (see tasks.md T005) suffices.
