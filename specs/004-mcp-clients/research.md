# Research — Phase 4: MCP Client Classes

**Date**: 2026-06-17 | **Branch**: `004-mcp-clients`

---

## R1. Per-client canonical envelope shapes

Read from `../acrossai-mcp-manager/src/MCPClients/*.php` +
`../acrossai-mcp-manager/src/Admin/ApplicationPasswords.php::generate_mcp_server_config()`.

The inner config block (the value of `<server-key>` inside the envelope)
is uniform across array-returning clients. Source repo template:

```php
[
    'command' => 'npx',
    'args'    => [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
    'env'     => [
        'OAUTH_ENABLED'   => 'false',
        'WP_API_URL'      => $server_url,
        'WP_API_USERNAME' => $username,    // TODO see decision below
        'WP_API_PASSWORD' => $auth_token,  // empty → safe_token() placeholder
    ],
]
```

**Decision (this phase)**: drop `WP_API_USERNAME` from the snippet.

- Source repo reads `wp_get_current_user()->user_login` and bakes it into
  the snippet at generation time. That makes the snippet **user-specific** —
  if Admin A generates and sends to Admin B, B's token + A's username
  produces auth failure.
- New design: the auth_token (an Application Password) is paired with
  the user who *generated* it via WordPress core's app-password DB row.
  The MCP remote package can derive the username from the basic-auth
  decode of the token submission, so the env var is redundant for
  Application-Password-based clients.
- If we discover during golden-fixture review that some AI tool
  *requires* WP_API_USERNAME, we re-add it then. For launch: omit.

Per-client envelope table:

| Client | Return | Envelope shape |
|---|---|---|
| ClaudeDesktop | `array` | `[ 'mcpServers' => [ $key => $inner ] ]`; target file `~/Library/Application Support/Claude/claude_desktop_config.json` |
| ClaudeCode | `string` | `claude mcp add <key> -- npx -y @automattic/mcp-wordpress-remote@latest` followed by env-var args; idiomatic per Anthropic CLI docs |
| Cursor | `array` | `[ 'mcpServers' => [ $key => $inner ] ]`; target `~/.cursor/mcp.json` |
| VSCode | `array` | `[ 'mcp.servers' => [ $key => $inner ] ]`; target `.vscode/mcp.json` per VS Code MCP extension docs |
| GitHubCopilot | `array` | `[ 'mcp' => [ 'servers' => [ $key => $inner ] ] ]`; target `.vscode/mcp.json` (Copilot reuses VS Code's MCP slot but namespaced differently per Copilot's preview spec) |
| Codex | `array` | `[ 'mcpServers' => [ $key => $inner ] ]`; target `~/.codex/config.json` |
| Custom | `array` | `[ 'mcpServers' => [ $key => $inner ] ]`; comment-style hint that the user adapts the envelope to their tool |

Rationale for keeping the table approximate rather than per-byte
canonical: AI vendors actively iterate their MCP config formats. Golden
fixtures captured during implementation are the source of truth for
exact strings; this table is a sanity-check schema.

---

## R2. `derive_server_key()` algorithm

**Decision**: parse the URL by stripping query string + trailing slash,
take the last non-empty path segment, fall back to `'wordpress-mcp'`.

```php
protected function derive_server_key( string $server_url ): string {
    $no_query  = strtok( $server_url, '?' );
    $no_slash  = rtrim( (string) $no_query, '/' );
    if ( '' === $no_slash ) {
        return 'wordpress-mcp';
    }
    $parts = explode( '/', $no_slash );
    $last  = end( $parts );
    return ( '' !== $last && false !== $last ) ? $last : 'wordpress-mcp';
}
```

**Test matrix** (golden assertions in AbstractMCPClientTest):

| Input | Output |
|---|---|
| `''` | `wordpress-mcp` |
| `'https://example.com/wp-json/mcp/foo'` | `foo` |
| `'https://example.com/wp-json/mcp/foo/'` | `foo` |
| `'https://example.com/wp-json/mcp/foo?x=1'` | `foo` |
| `'foo'` | `foo` |
| `'https://example.com/'` | `wordpress-mcp` |
| `'https://example.com'` | `example.com` (acceptable: last segment is the host) |

The last row is a small wart — a bare host returns the host as the key.
Acceptable because the spec says "the caller is responsible for passing
already-sanitized values" and an admin manually constructing a URL of
that shape gets a useful (if odd) key. Don't try to detect-and-special-
case TLDs.

**Alternatives considered**:
- `parse_url($server_url, PHP_URL_PATH)` then split: cleaner for valid
  URLs but returns `false` for malformed input and trips PHP's parse_url
  warnings under E_STRICT. Rejected — `strtok` + `rtrim` works on raw
  strings too.
- Use a regex: hard to read, no benefit over the explode approach.

---

## R3. Empty-token placeholder centralization

**Decision**: introduce a protected helper `safe_token(string $token):
string` on `AbstractMCPClient` returning `'(paste generated password
here)'` when input is empty, else returning the token verbatim.

Each concrete client calls `$this->safe_token($auth_token)` at the
exact point where it would otherwise embed `$auth_token` directly.

**Rationale**:
- One place to change the placeholder text if vendors complain.
- Prevents 7-way drift (each client otherwise re-implements the empty
  check; one client forgetting it produces silent-broken configs).
- Trivial to assert in tests — one fixture per client for the
  empty-token case verifies the substitution.

**Alternative considered**: do the substitution inside
`get_config_snippet()` of each concrete client. Rejected — DRY
violation in the most security-sensitive code path.

---

## R4. `get_all_clients()` internal mechanism (V3=both)

**Decision**: glob the module directory, skip the abstract, skip
non-subclasses, instantiate via `new $fqn()`.

```php
public static function get_all_clients(): array {
    $clients = [];
    foreach ( glob( __DIR__ . '/*.php' ) as $file ) {
        $basename = basename( $file, '.php' );
        if ( 'AbstractMCPClient' === $basename ) {
            continue;
        }
        $fqn = __NAMESPACE__ . '\\' . $basename;
        if ( ! class_exists( $fqn ) ) {
            continue;
        }
        if ( ! is_subclass_of( $fqn, self::class ) ) {
            continue;
        }
        $clients[] = new $fqn();
    }
    return $clients;
}
```

**Why glob over manual array of class names**:
- Manual array is one more file to edit when adding a client → breaks
  SC-002 "exactly one new file". glob preserves SC-002.
- glob results are alphabetically sorted on most filesystems → stable
  display order in the Tokens tab without explicit `sort()`.

**Why `is_subclass_of` over `instanceof` after instantiation**:
- `is_subclass_of` accepts a class-name string and doesn't trigger a
  constructor call for the rejection path. Cleaner.

**Why catch nothing**:
- The contract is "this method MUST return an array of
  AbstractMCPClient instances". If a malformed file triggers a fatal at
  `class_exists()` evaluation (PHP doesn't catch parse errors), the
  bug is in the new client file — the developer sees it immediately,
  the production site never serves it.

**Testing implications**:
- `AbstractMCPClientTest::testGetAllClientsReturnsExactlySevenClients`
  asserts `count() === 7`.
- `AbstractMCPClientTest::testGetAllClientsReturnsSortedSlugs` asserts
  the array is sorted by slug (deterministic order for the consumer).

---

## Cross-cutting note: testability without WP bootstrap

Per FR-008 + SC-003, this phase's tests run **without loading
WordPress**. The implications:

- No use of `__()`, `esc_html__()`, or any `wp_*` function in the
  class bodies.
- The placeholder string `(paste generated password here)` is a literal
  English constant — NOT internationalized. Acceptable because:
  - The string is a developer-facing hint inside a config snippet, not
    UI text.
  - The user copies it; they don't read it from a UI.
  - Internationalizing it would force the test harness to bootstrap WP
    for `__()` to be available.
- If a future i18n requirement emerges, wrap at the consumer boundary
  (Phase 2 amendment) — render the snippet with the placeholder
  string replaced via `str_replace(...)` at the moment of display.
