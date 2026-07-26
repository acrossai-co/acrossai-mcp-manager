# Phase 1 — Data Model

**Feature**: F034 MCP Client Metadata + Filter-Aware Enumeration Refactor
**Date**: 2026-07-25

F034 has **zero persistent state**. There is no database schema, no WP option, no transient, no cache row. What follows is the class-contract model — the post-refactor shape of `AbstractMCPClient` and its consumers that other code depends on.

---

## Entity: `AbstractMCPClient` (post-refactor)

Abstract base class. Namespace `AcrossAI_MCP_Manager\Includes\MCPClients`. Stateless pure service per A11 (no instance state, no ctor args, no hook registration). Every concrete client extends this base.

### Constants (unchanged from pre-refactor)

| Constant | Type | Value | Purpose |
|---|---|---|---|
| `EMPTY_TOKEN_PLACEHOLDER` | string | `'(paste generated password here)'` | Rendered in snippet output when Application Password not yet generated |
| `SERVER_KEY_FALLBACK` | string | `'wordpress-mcp'` | Fallback server-key when URL parsing fails |

### Constants (added by F034)

| Constant | Type | Value | Visibility | Purpose |
|---|---|---|---|---|
| `DEFAULT_CLIENT_CLASSES` | `string[]` | Eight built-in FQNs in visual-order sequence | `public` (visible to consumers who want to inspect the built-in list) | Seed value passed to `apply_filters('acrossai_mcp_client_classes', ...)` by `get_all_registered_clients()`. Ordered by insertion (matches pre-refactor `MCPClientsBlock` default array order). Actual runtime sort uses `get_priority()` per FR-010. |

### Abstract methods (unchanged from pre-refactor)

Concrete subclasses MUST implement all three:

| Method | Signature | Contract |
|---|---|---|
| `get_client_slug` | `(): string` | Stable machine identifier. Kebab-case, `/^[a-z0-9-]{1,64}$/`. Consumed by `get_all_registered_clients()` for validation + dedup + tiebreaker sort. |
| `get_client_name` | `(): string` | Human-readable display name. Rendered in sub-nav. |
| `get_config_snippet` | `(string $server_url, string $auth_token): string\|array` | The copy-paste payload. Union return type reflects per-client format choices (JSON-config → array, CLI-install → string). MUST embed both args. When `$auth_token === ''`, MUST render `EMPTY_TOKEN_PLACEHOLDER` via `safe_token()`. |

### Concrete methods added by F034

Concrete subclasses MAY override; all six have safe defaults:

| Method | Signature | Default | Purpose |
|---|---|---|---|
| `get_icon` | `(): string` | `''` | Emoji or short display marker. Rendered next to the client name in the sub-nav. Empty → no icon glyph. |
| `get_description` | `(): string` | `''` | One-line translated description shown below the client name in its panel. Empty → no description text. |
| `get_config_file` | `(): string` | `''` | Config file path hint (e.g. `'~/.claude.json'`). Untranslated (technical string). Rendered in the "paste under" instructions. |
| `get_top_level_key` | `(): string` | `''` | JSON/TOML top-level key the snippet is pasted under (e.g. `'mcpServers'`). Untranslated. Rendered in the paste instructions. |
| `get_instructions` | `(): string` | `''` | Multi-step setup instructions (translated). Rendered as a paragraph below the config snippet. Empty → no instructions block. |
| `get_priority` | `(): int` | `100` | Sub-nav slot preference. Lower = earlier. Consumed by `get_all_registered_clients()` sort. WP-idiomatic default (matches `add_action` and `ServerTabs\Registry` conventions). |

### Protected helpers (unchanged from pre-refactor)

Concrete subclasses MAY call these from their `get_config_snippet()` implementations. NOT part of the public contract; not consumed by `get_all_registered_clients()`:

- `build_server_url( string $base_rest_url, string $route_namespace, string $route ): string`
- `derive_server_key( string $server_url ): string`
- `safe_token( string $token ): string`
- `current_username(): string`
- `redact_token( string $token ): string`

### Canonical enumeration method (added by F034)

| Method | Signature | Contract |
|---|---|---|
| `get_all_registered_clients` | `(): AbstractMCPClient[]` | Static. Sole entry point for "which MCP clients are registered on this site." See procedure below. |

**Procedure** (mirrors `ConnectorProfileRegistry::get_profiles()` at `includes/Connectors/ConnectorProfileRegistry.php:57-118`):

1. `$candidates = (array) apply_filters( 'acrossai_mcp_client_classes', self::DEFAULT_CLIENT_CLASSES )`.
2. For each FQN in `$candidates`:
   - If `! is_string( $fqn )` → silently skip (SEC-013-008).
   - If `! class_exists( $fqn )` → silently skip.
   - If `! is_subclass_of( $fqn, self::class )` → silently skip.
   - Instantiate: `$instance = new $fqn()`.
   - Read `$slug = $instance->get_client_slug()`.
   - If `'' === $slug || ! preg_match( '/\A[a-z0-9-]{1,64}\z/', $slug )` → fire `_doing_it_wrong( 'AcrossAI_MCP_Manager\\Includes\\MCPClients\\AbstractMCPClient::get_all_registered_clients', <translated message>, '0.1.7' )` under `WP_DEBUG` and skip.
   - If `isset( $seen[ $slug ] )` → fire `_doing_it_wrong( ..., '0.1.7' )` under `WP_DEBUG`. Set `$seen[ $slug ] = $instance` regardless (later-wins per FR-009).
   - Else `$seen[ $slug ] = $instance`.
3. Sort `$seen`: primary key `$instance->get_priority()` ascending; tiebreaker `$slug` ascending. `usort` with a comparator returning `($a_pri <=> $b_pri) ?: ($a_slug <=> $b_slug)`.
4. Return `array_values( $seen )`.

**Deleted by F034**: `AbstractMCPClient::get_all_clients()` (the glob-based path) is removed entirely.

---

## Entity: 8 Concrete Client Classes (post-refactor)

Each of the following gets six method overrides:

| Class | Slug | Priority | Icon | Config file | Top-level key |
|---|---|---|---|---|---|
| `ClaudeDesktopClient` | `claude-desktop` | 10 | 🍰 | `~/Library/Application Support/Claude/claude_desktop_config.json` | `mcpServers` |
| `ClaudeCodeClient` | `claude-code` | 20 | 📄 | `~/.claude.json` | `mcpServers` |
| `VSCodeClient` | `vscode` | 30 | ▤ | `~/.vscode/mcp.json` | `servers` |
| `GitHubCopilotClient` | `github-copilot` | 40 | 🐱 | `~/.vscode/mcp.json` | `servers` |
| `CodexClient` | `codex` | 50 | 🐙 | `~/.codex/config.toml` | `mcp_servers` |
| `CursorClient` | `cursor` | 60 | ⚡ | `~/.cursor/mcp.json` | `mcpServers` |
| `GeminiClient` | `gemini` | 70 | 💎 | `~/.gemini/settings.json` | `mcpServers` |
| `CustomClient` | `custom` | 80 | ⚙ | `depends on your client` | `depends on your client` |

`description` and `instructions` values migrate verbatim from `MCPClientsBlock::CLIENT_META[$slug]['description' \| 'instructions']` wrapped in `__(..., 'acrossai-mcp-manager')`.

---

## Entity: `MCPClientsBlock` (post-refactor Renderer — consumer only)

Namespace `AcrossAI_MCP_Manager\Public\Renderers`. Singleton. Public API preserved verbatim: `instance()`, `slug(): string` (returns `'clients'`). What changes:

**Deleted**:
- `CLIENT_META` private const (lines 55–112 pre-refactor).
- Inline default-classes array + filter loop in `render_body()` (lines 167–198 pre-refactor).

**Changed**:
- `render_body()` now opens with `$clients = AbstractMCPClient::get_all_registered_clients();` — single call replaces the entire enumeration block.
- Every `self::CLIENT_META[$slug]['<key>']` lookup replaced with the corresponding client-instance method call:
  - `['emoji']` → `$client->get_icon()`
  - `['description']` → `$client->get_description()`
  - `['config_file']` → `$client->get_config_file()`
  - `['top_level_key']` → `$client->get_top_level_key()`
  - `['instructions']` → `$client->get_instructions()`

**Unchanged**: `slug()`, `instance()`, private constructor, empty-client-list guard, sub-client-slug selection logic, and every render helper below `render_body()` (their signatures are preserved).

---

## Relationships & lifecycle

- No entity persistence. Every `AbstractMCPClient` instance is created fresh inside `get_all_registered_clients()` per call.
- No state transitions.
- No cross-request cache. Each admin request that hits the Clients tab calls `get_all_registered_clients()` once (called from `MCPClientsBlock::render_body()`); the result is not memoized (matches `ConnectorProfileRegistry` pattern for the connector side, which DOES memoize — but F034 defers memoization to a future feature to avoid scope creep).
- No validation rules on `get_priority()` return value beyond `int` type — any integer accepted; developers pick sensible slots (per FR-018).
