# Feature Specification: MCP Client Classes — Pure Service Layer

**Feature Number**: 004
**Feature Branch**: `004-mcp-clients`
**Created**: 2026-06-17
**Status**: Draft
**Spec**: `specs/004-mcp-clients/spec.md`
**Input**: User description: "MCP Client Classes — Pure Service Layer"

---

## Clarifications

### Session 2026-06-17

- Q: For JSON-envelope clients (Claude Desktop, Cursor, Codex, VS Code, GitHub Copilot, Custom), what should the inner server-key be inside `mcpServers: { <KEY>: {...} }`? The signature has only two args (`$server_url`, `$auth_token`), so the key must come from one of them. → A: **Derive from the URL's last path segment.** Parse `$server_url`, strip any query string and trailing slash, take the final path segment. For `https://example.com/wp-json/mcp/wordpress-default-server` → key is `wordpress-default-server`. Self-contained (no signature change), deterministic (same URL → same key), and matches the AI-tool URL the user already sees. Fall back to `wordpress-mcp` if the URL has no usable path segment.
- Q: How should an empty `$auth_token` render in `get_config_snippet()` output? → A: **Marked placeholder string `(paste generated password here)`.** Matches the source-repo behavior so users copying a snippet before generating a token see a self-documenting gap they must fill in. An empty-string token would silently produce a broken config; the placeholder forces visible action. Applies to every token-bearing field in every client's output (`WP_API_PASSWORD` in JSON envelopes, the token slot in CLI-command strings).
- Q: Consumer discovery — file-scan + `is_subclass_of()` (per original FR-010), a static factory `AbstractMCPClient::get_all_clients()`, or both? → A: **Both.** Public consumer API is `AbstractMCPClient::get_all_clients(): array` (one static call from the Tokens-tab renderer). Internal implementation uses the original FR-010 mechanism: scan `includes/MCPClients/*.php`, autoload each, instantiate when `class_exists()` AND `is_subclass_of(…, AbstractMCPClient::class)` AND not the abstract class itself. Cleaner public surface (IDE-discoverable, refactor-safe, testable without filesystem mocks) while preserving SC-002 "adding a new client = exactly one new file" — no edits to `get_all_clients()` needed because discovery is file-scan based.

---

## User Scenarios & Testing

### User Story 1 — Site Admin Copies a Working Config Into Their AI Tool (P1)

A site administrator opens the Tokens tab on an MCP server's edit page, picks
their AI tool from a list (Claude Desktop, Claude Code, Cursor, VS Code,
GitHub Copilot, Codex, or Custom), and copies a ready-to-paste configuration
snippet. The snippet embeds the live server URL and a newly-generated
Application Password so the AI tool can authenticate immediately.

**Why this priority**: This is the end-user value the entire client-registry
exists to deliver. Every other story exists to enable this one.

**Independent Test**: With this feature shipped + Phase 2 admin UI consuming
the registry, generate an Application Password from the Tokens tab, click
"Show config for Claude Desktop", copy the snippet, paste it into
`~/Library/Application Support/Claude/claude_desktop_config.json`, restart
Claude, and verify Claude lists the MCP server's tools.

**Acceptance Scenarios**:

1. **Given** an MCP server is configured with route `mcp/wordpress-default-server`
   and an Application Password has been generated for the current admin user,
   **When** the admin requests the Claude Desktop config snippet, **Then** the
   returned payload is a JSON object whose top-level key matches Claude's
   `mcpServers` convention, contains the live server URL derived from the
   server row, and includes the auth token.
2. **Given** the same setup, **When** the admin requests the Claude Code
   config snippet, **Then** the returned payload is a shell-command string
   the admin can paste into a terminal (the Claude Code CLI's idiomatic
   config method), with the server URL and token embedded.
3. **Given** the same setup, **When** the admin requests the Custom Client
   snippet, **Then** the returned payload is a generic JSON template the
   admin can adapt to any MCP-compatible tool the plugin does not natively
   recognise.
4. **Given** the auth token contains characters that need URL or JSON
   escaping, **When** any client builds its snippet, **Then** the token
   appears correctly escaped in the output — never raw with un-encoded
   special characters.

---

### User Story 2 — Each Supported Client Has a Correct Snippet (P1)

A developer or QA reviewer can run each of the seven concrete clients and
inspect that the returned snippet matches the AI tool's documented
configuration format. The snippet must work as-is when copied into the
tool's config file (or pasted into the tool's CLI).

**Why this priority**: Without per-client correctness the feature provides
no value — a wrong-shape snippet is worse than none.

**Independent Test**: For each of the 7 clients, call `get_config_snippet`
with a fixed test URL and token, and assert the output against a golden
fixture file. A snippet that doesn't match its golden file fails the build.

**Acceptance Scenarios**:

1. **Given** the registry contains 7 concrete clients
   (ClaudeCode, ClaudeDesktop, Codex, Cursor, Custom, GitHubCopilot,
   VSCode), **When** each client's `get_client_slug()` is called, **Then**
   the returned slug uniquely identifies that client (e.g. `claude-code`,
   `claude-desktop`, `codex`, `cursor`, `custom`, `github-copilot`,
   `vscode`).
2. **Given** the registry contains the 7 concrete clients, **When** each
   client's `get_client_name()` is called, **Then** the returned
   human-readable name matches the AI tool's marketing name as shown to
   end users (e.g. "Claude Code", "Claude Desktop", "Cursor", "VS Code",
   "GitHub Copilot", "Codex", "Custom Client").
3. **Given** a client returns an array snippet, **When** the array is
   serialised to JSON with the WordPress core `wp_json_encode()` helper,
   **Then** the resulting JSON is a syntactically valid, pretty-printable
   document matching the AI tool's expected config shape (e.g. Claude
   Desktop's `{"mcpServers": {…}}` envelope).
4. **Given** a client returns a string snippet, **When** the string is
   inspected, **Then** it contains the server URL, the auth token, and is
   formatted as the AI tool's documented CLI invocation (e.g. for
   Claude Code: `claude mcp add <name> -- npx -y @automattic/mcp-wordpress-remote …`).

---

### User Story 3 — Adding a New AI Client Requires Only One New File (P2)

A developer adding support for a new AI tool (e.g. Windsurf) creates one
new file under `includes/MCPClients/` extending `AbstractMCPClient` and
implementing the three required methods. No registry edits in other files,
no hooks to register, no database migration. The new client appears in the
Tokens tab as soon as the file exists and the autoloader picks it up.

**Why this priority**: Long-term maintenance cost. The MCP ecosystem will
add new clients; this story is about keeping that growth cheap.

**Independent Test**: Drop a `WindsurfClient.php` file extending
`AbstractMCPClient` into `includes/MCPClients/`, refresh the admin Tokens
tab, and confirm a "Windsurf" card appears with its snippet.

**Acceptance Scenarios**:

1. **Given** a new client file is added that extends `AbstractMCPClient`
   and implements `get_client_slug()`, `get_client_name()`, and
   `get_config_snippet()`, **When** the admin Tokens tab renders, **Then**
   the new client appears in the picker without any other file change.
2. **Given** the new client file overrides only the three required methods
   (no constructor, no extra dependencies), **When** the file is loaded by
   the autoloader, **Then** it instantiates cleanly with `new
   WindsurfClient()` — no boot-time side effects.

---

### User Story 4 — Pure Service Classes (No Side Effects) (P2)

A developer auditing `includes/MCPClients/` confirms that no constructor or
method body contains `add_action()`, `add_filter()`, any DB call, any HTTP
call, any session/cookie write, or any echo/print statement. The classes
are pure value producers — given inputs `(server_url, auth_token)` they
return a deterministic snippet.

**Why this priority**: Architectural invariant. Phase 2 established the
Loader contract (Constitution A1). MCPClients must not regress it. Pure
service classes are also trivially unit-testable.

**Independent Test**:
`grep -rnE 'add_action|add_filter|\$wpdb|wp_remote_(get|post)|setcookie|echo |print ' includes/MCPClients/` returns empty.

**Acceptance Scenarios**:

1. **Given** any class file under `includes/MCPClients/`, **When** the
   file is grep'd for hook calls, DB calls, HTTP calls, cookie writes, or
   raw output, **Then** zero results are found.
2. **Given** the same set of files, **When** their constructors are read,
   **Then** none takes WordPress-specific dependencies (no `$wpdb`, no
   `WP_REST_Request`, no admin globals).
3. **Given** the test for a client `MyClient::get_config_snippet()` runs
   in isolation (no WordPress bootstrap), **When** invoked with a fixed
   URL + token, **Then** it returns the expected output without requiring
   a WP environment.

---

### Edge Cases

- **Empty auth token**: `get_config_snippet()` MUST still produce a
  syntactically valid snippet, with the token field rendered as the
  literal string `'(paste generated password here)'` (Q2 clarification
  2026-06-17). Applies to every token-bearing field across every client
  output — JSON envelopes (`WP_API_PASSWORD`) and CLI-command strings.
  MUST NOT throw, MUST NOT return null, MUST NOT silently render an
  empty string.
- **Server URL missing scheme**: If the caller passes a bare host
  (`example.com/wp-json/mcp/foo`), the client classes MUST NOT silently
  rewrite — they treat the URL as opaque and embed it as-is. URL hygiene
  is the caller's responsibility.
- **Special characters in auth token** (spaces, quotes, control bytes):
  Array-returning clients delegate escaping to `wp_json_encode()` at
  serialisation; string-returning clients (CLI commands) apply shell-safe
  escaping inside the snippet builder (e.g. `escapeshellarg()` equivalent
  semantics).
- **Adding a malformed client class** (extends `AbstractMCPClient` but
  doesn't implement an abstract method): PHP raises a fatal at autoload
  time. This is acceptable — the developer sees the error immediately;
  the production site never serves it because the autoloader fails fast.
- **`build_server_url()` called with insufficient server-row data**:
  Helper methods on `AbstractMCPClient` MUST validate their inputs and
  return a safe placeholder (e.g. `'(server-url-unavailable)'`) rather
  than throw, so a partial server row still renders a snippet the user
  can hand-edit.

---

## Requirements

### Functional Requirements

#### The AbstractMCPClient base class

- **FR-001**: `Includes\MCPClients\AbstractMCPClient` MUST be an `abstract
  class` declaring three abstract methods that every concrete client MUST
  implement:
  - `abstract public function get_client_slug(): string` — unique
    machine-readable identifier (kebab-case, e.g. `claude-desktop`)
  - `abstract public function get_client_name(): string` — human-readable
    name as the AI tool markets itself (e.g. "Claude Desktop")
  - `abstract public function get_config_snippet(string $server_url,
    string $auth_token): string|array` — the copy-paste payload the user
    pastes into their AI tool

- **FR-002**: `AbstractMCPClient` MUST declare two `public const` for
  canonical strings (so subclasses and consumers can reference them by
  name rather than re-quoting literals):
  - `EMPTY_TOKEN_PLACEHOLDER = '(paste generated password here)'` — the
    literal the snippet renders in the token slot when `$auth_token` is
    empty (Q2 clarification 2026-06-17).
  - `SERVER_KEY_FALLBACK = 'wordpress-mcp'` — the literal
    `derive_server_key()` returns when the URL has no usable path
    segment.

  And MUST provide **four** `protected` helper methods for use by
  subclasses:
  - `protected function build_server_url(string $base_rest_url, string
    $route_namespace, string $route): string` — concatenates a base
    `rest_url()` value (passed in by the caller, not derived) with the
    server row's namespace + route. Pure string composition; no
    `get_option()`, no `home_url()`, no WP globals.
  - `protected function derive_server_key(string $server_url): string` —
    parses `$server_url`, strips any query string and trailing slash, and
    returns the final path segment (e.g.
    `https://example.com/wp-json/mcp/wordpress-default-server` →
    `wordpress-default-server`). Falls back to `SERVER_KEY_FALLBACK` when
    the URL has no usable path segment or is unparsable. Used by JSON-
    envelope clients to populate the inner `mcpServers: { <KEY>: {...} }`
    key per Q1 clarification 2026-06-17.
  - `protected function safe_token(string $token): string` — returns the
    token verbatim when non-empty, or `EMPTY_TOKEN_PLACEHOLDER` when
    empty. This is the **only** path that emits plaintext to a snippet;
    every concrete client MUST call `$this->safe_token($auth_token)` at
    the token slot rather than embedding `$auth_token` directly. Q2
    clarification 2026-06-17.
  - `protected function redact_token(string $token): string` — returns a
    log-safe representation of a token: the first 4 characters followed
    by `'…' . substr($token, -2)` (or `'(empty)'` when the token is empty).
    Used by client implementations for log lines / debug strings — never
    for the actual snippet payload. **Do NOT confuse** with `safe_token` —
    `redact_token` is log-only; `safe_token` is snippet-output-only.

- **FR-003**: `AbstractMCPClient` MUST NOT register any WordPress hooks,
  declare any `add_action`/`add_filter` calls, take any constructor
  arguments, or store mutable state. Subclass constructors MUST also be
  free of side effects (if present at all).

#### The 7 concrete client classes

- **FR-004**: Seven concrete client classes MUST exist under
  `includes/MCPClients/`, each extending `AbstractMCPClient`:
  - `ClaudeCodeClient` — slug `claude-code`, name `"Claude Code"`
  - `ClaudeDesktopClient` — slug `claude-desktop`, name `"Claude Desktop"`
  - `CodexClient` — slug `codex`, name `"Codex"`
  - `CursorClient` — slug `cursor`, name `"Cursor"`
  - `CustomClient` — slug `custom`, name `"Custom Client"`
  - `GitHubCopilotClient` — slug `github-copilot`, name `"GitHub Copilot"`
  - `VSCodeClient` — slug `vscode`, name `"VS Code"`

- **FR-005**: Each concrete client's `get_config_snippet()` MUST produce
  output that matches its AI tool's documented configuration format:
  - Clients whose tools consume JSON config files (Claude Desktop, Cursor,
    Codex, VS Code, GitHub Copilot, Custom) MUST return an `array` shaped
    as the tool's full config envelope. The inner `<server-key>` MUST be
    obtained by calling `$this->derive_server_key($server_url)` (FR-002,
    Q1 2026-06-17) — never hardcoded, never invented from other inputs.
    Example for Claude Desktop:
    `[ 'mcpServers' => [ $this->derive_server_key($server_url) =>
    [ 'command' => 'npx', 'args' => […], 'env' => [ 'WP_API_URL' =>
    $server_url, 'WP_API_PASSWORD' => $auth_token, … ] ] ] ]`.
  - Clients whose tools accept a CLI install command (Claude Code) MUST
    return a `string` containing the exact shell invocation, with the
    server-key from `derive_server_key()` used as the local name
    argument (e.g. `claude mcp add wordpress-default-server -- npx -y
    @automattic/mcp-wordpress-remote …`). Shell metacharacters in the
    URL/token MUST be escape-safe.

- **FR-006**: Each concrete client's snippet MUST embed BOTH the
  `$server_url` and `$auth_token` provided by the caller — never embed
  hardcoded URLs, never read environment variables for the token, never
  consult `get_option()` for the password. When `$auth_token === ''`,
  the token slot MUST contain `AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER`
  (the literal `'(paste generated password here)'`) instead of an empty
  value (Q2 clarification 2026-06-17). In practice this is enforced by
  every concrete client calling `$this->safe_token($auth_token)` at the
  token slot (FR-002). Golden fixtures for the empty-token case pin this
  exact substitution.

- **FR-007**: Each concrete client's `get_client_slug()` return value MUST
  be **kebab-case, lowercase, and ASCII-only** (matches FR-004 list). Slugs
  MUST be unique across the 7 clients — no two clients share a slug.

#### Purity invariants (architecture)

- **FR-008**: No file under `includes/MCPClients/` MUST contain any of:
  `add_action`, `add_filter`, `$wpdb`, `wp_remote_get`, `wp_remote_post`,
  `get_option`, `update_option`, `setcookie`, `header(`, raw `echo` or
  `print` (other than inside docblocks). The directory is verified by a
  grep DoD gate.

- **FR-009**: No class under `includes/MCPClients/` MAY implement the
  singleton `instance()` pattern. Concrete clients are created via
  `new ClientName()` per use-site. Holding a long-lived instance would
  serve no purpose because the classes have no instance state.

#### Consumer contract (for Admin Settings renderer integration)

- **FR-010**: `AbstractMCPClient` MUST expose a `public static function
  get_all_clients(): array` method returning a list of `AbstractMCPClient`
  instances — one per concrete client present under
  `includes/MCPClients/`. The consumer (Phase 2's
  `Admin\Partials\ApplicationPasswords::render_for_server`) invokes this
  method once per render and iterates the returned array.

  Internal discovery mechanism (decided per Q3 clarification 2026-06-17 =
  "both"): inside `get_all_clients()`, scan `includes/MCPClients/*.php`,
  exclude `AbstractMCPClient.php`, autoload each remaining file, and
  instantiate only when `class_exists()` returns true AND
  `is_subclass_of($class_name, AbstractMCPClient::class)` returns true.
  This preserves the file-scan discovery semantics from the original FR-010
  (so adding a new client = exactly one new file, no edits to the
  abstract class — preserves SC-002) while giving the consumer a clean,
  refactor-safe public API.

- **FR-011**: When Phase 2's `Admin\Partials\ApplicationPasswords::
  render_for_server()` is amended to consume the client registry,
  invoking `new SomeClient()` followed by
  `->get_config_snippet($url, $token)` MUST be the entire interaction
  with this module. No other public methods are required by the
  consumer.

#### Namespace + placement

- **FR-012**: Every class file under `includes/MCPClients/` MUST declare
  namespace `AcrossAI_MCP_Manager\Includes\MCPClients`. File names match
  class names (`AbstractMCPClient.php`, `ClaudeCodeClient.php`, etc.).

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ |
| WordPress version | 6.9+ |
| Multisite | Not applicable (pure service classes, no site-scoped state) |
| Required Composer packages | `automattic/jetpack-autoloader ^5.0` (for PSR-4 autoloading of new files) |
| Optional integrations | None — these classes have no integration surface |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `includes/MCPClients/AbstractMCPClient.php` | `AcrossAI_MCP_Manager\Includes\MCPClients` | New — abstract base, ~80 lines |
| `includes/MCPClients/ClaudeCodeClient.php` | same | New — concrete, ~30 lines |
| `includes/MCPClients/ClaudeDesktopClient.php` | same | New — concrete, ~40 lines |
| `includes/MCPClients/CodexClient.php` | same | New — concrete, ~40 lines |
| `includes/MCPClients/CursorClient.php` | same | New — concrete, ~40 lines |
| `includes/MCPClients/CustomClient.php` | same | New — concrete, ~40 lines |
| `includes/MCPClients/GitHubCopilotClient.php` | same | New — concrete, ~40 lines |
| `includes/MCPClients/VSCodeClient.php` | same | New — concrete, ~40 lines |

**Hook Registration Rule**: ZERO `add_action` / `add_filter` calls anywhere
under `includes/MCPClients/`. The classes are not wired through the Loader
because they have no hooks to register.

### Admin UI Requirements

This phase adds no admin screens of its own. The classes are consumed by
the Tokens tab on the per-server edit page (shipped in Phase 2). A separate
follow-up (post-this-phase) will amend
`Admin\Partials\ApplicationPasswords::render_for_server()` to enumerate
the registry and surface per-client snippet pickers.

### REST API Contract

This phase adds no REST routes. Phase 2's
`POST /acrossai-mcp-manager/v1/generate-app-password` endpoint already
provides the auth token; this phase's classes consume that token but
register no endpoints themselves.

### Database / Storage

This phase introduces no persistent storage. The classes hold no instance
state and read no options, transients, or user-meta.

### Security Checklist

*(Derived from Constitution §III — verify all that apply)*

- [ ] No user input crosses a sanitization boundary — the caller is
      responsible for passing already-sanitized `$server_url` and
      `$auth_token` values
- [ ] Output escaping is the caller's responsibility — when the consumer
      renders a snippet array, it MUST `wp_json_encode()` it; when
      rendering a string snippet inside HTML, it MUST `esc_html()` it
- [ ] No DB queries — no SQL surface
- [ ] No HTTP calls — no SSRF surface
- [ ] No filesystem writes — no path-traversal surface
- [ ] No deserialization (`unserialize`, `maybe_unserialize`) — no
      RCE-via-deserialization surface
- [ ] The `redact_token()` helper is for log-safe representation only
      and MUST NEVER be used as the actual snippet token — confusing the
      two would silently break auth

### Key Entities

- **MCP Client (in-memory class instance)**: A concrete subclass of
  `AbstractMCPClient` representing one AI tool that can consume MCP
  servers. Has no persistent identity beyond the class name + its
  `get_client_slug()` return value. Stateless — instances are
  interchangeable.

---

## Success Criteria

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [x] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs
      includes/MCPClients/`) — **verified 2026-06-17**
- [x] PHPStan level 8: zero errors (`vendor/bin/phpstan analyse
      includes/MCPClients/ --level=8`) — **verified 2026-06-17**
- [x] PHPUnit tests written and passing for `AbstractMCPClient`'s helpers
      and for each of the 7 concrete clients (golden-fixture snippet
      assertions) — **67 tests / 111 assertions, all green 2026-06-17**
- [x] `grep -rnE 'add_action|add_filter|\$wpdb|wp_remote_(get|post)|setcookie' includes/MCPClients/` returns zero matches (FR-008) — **verified 2026-06-17**
- [x] `grep -rn 'public static function instance' includes/MCPClients/`
      returns zero matches (FR-009) — **verified 2026-06-17**
- [x] All 7 concrete classes file-exist and declare the required
      namespace (FR-012) — **verified 2026-06-17**
- [x] All 7 concrete `get_client_slug()` returns are unique (FR-007) — **verified 2026-06-17**
- [ ] `npm run validate-packages` passes — (not run; this phase introduces no new npm packages)
- [ ] All standards in `AGENTS.md` are met — (continuous; not a binary checkpoint)

### Measurable Outcomes

- **SC-001**: An admin can generate a snippet for any of the 7 supported
  clients and successfully connect that AI tool to the MCP server,
  verified by the tool listing the server's tools after restart.
- **SC-002**: Adding a new client requires modifying exactly **one** file
  in `includes/MCPClients/` (the new class file). The Tokens-tab picker
  picks it up automatically.
- **SC-003**: A unit test running in **isolation from WordPress**
  (`vendor/bin/phpunit --no-configuration tests/phpunit/MCPClients/`)
  can instantiate any concrete client and assert its snippet output. This
  proves architectural purity (FR-008, FR-009).
- **SC-004**: Each concrete client's snippet output exactly matches its
  golden fixture file, verified per-client by a PHPUnit `assertEquals`.

---

## Assumptions

- **`get_config_snippet` return type union**: The `string|array` return
  type reflects per-client format choices. JSON-config tools (Claude
  Desktop, Cursor, etc.) return arrays; CLI-install tools (Claude Code)
  return strings. The consumer differentiates by `is_array()`.
- **Snippet content is canonical via golden fixtures**: This spec does
  not enumerate the per-client envelope shapes line-by-line — they are
  fixed by the AI tools' own documentation, which is the source of truth.
  Plan + tests use golden fixtures captured during implementation.
- **Server URL composition is the caller's job at consumption time**:
  When the Admin Settings renderer enumerates clients, it computes a
  single `$server_url` (using `rest_url($namespace . '/' . $route)`)
  from the server row and passes the same URL to every client's
  `get_config_snippet()`. This phase's `AbstractMCPClient::build_server_url`
  helper exists as a fallback for clients that need to recompose the URL
  (e.g. when the AI tool requires a different protocol or path suffix).
- **The 7-client list is the launch set, not a permanent ceiling**: FR-004
  enumerates the seven AI tools currently in scope. Adding an 8th client
  (e.g. Windsurf, Zed) in a future phase is intentionally cheap (US3) and
  does not require a constitution amendment.
- **`AbstractMCPClient::redact_token` is a debug helper, not a security
  control**: A 4-character prefix + 2-character suffix preview is enough
  to identify a token in a log line. It is NOT enough to prevent
  brute-force recovery; treat redacted tokens as still-sensitive in logs.
- **PHPUnit harness exists by the time this phase ships**: The DoD gate
  requires running PHPUnit. If the harness from Phase 2 follow-up
  (`tests/`, `phpunit.xml`, `wp-phpunit`) has not yet landed, this
  feature blocks on that prerequisite — same dependency model Phase 2
  used for the BerlinDB Query classes (FR-023 there).
- **Phase 2's `ApplicationPasswords` amendment is NOT in this phase's
  scope**: Wiring the registry into the Tokens-tab UI is a Phase 2
  follow-up (RT-3 in the architecture review). This phase ships the
  service layer only — the consumer change is tracked separately.
- **Multisite is not in scope**: Pure service classes carry no
  site-scoped state; the spec is single-site implicitly.
