# Contract — `AbstractMCPClient` (post-F034)

**Namespace**: `AcrossAI_MCP_Manager\Includes\MCPClients`
**Kind**: Abstract class
**Stability**: `@experimental` (matches sibling `MCPClientsBlock` per DEC-CLIENT-RENDERER-PUBLIC-API). Contract frozen at plugin 1.0.0.
**A11 exemption**: pure service — stateless, no ctor args, no hook registration.

This document is normative. Any concrete subclass MUST honour it. `/speckit-tasks` will enumerate one task per contract element.

---

## Required contract (concrete subclass MUST implement)

```php
abstract public function get_client_slug(): string;
abstract public function get_client_name(): string;
abstract public function get_config_snippet( string $server_url, string $auth_token ): string|array;
```

Constraints:

- `get_client_slug()` MUST return a string matching `/^[a-z0-9-]{1,64}$/`. Enforced at enumeration time by `get_all_registered_clients()`; violators are silently skipped from enumeration with `_doing_it_wrong` under `WP_DEBUG`. Slug is the stable machine identifier — MUST NOT change across plugin versions once shipped, since third-party code may key off it.
- `get_client_name()` MAY return any non-empty string. Rendered as sub-nav pill label. SHOULD be translated via `__(..., '<consumer-plugin-text-domain>')`.
- `get_config_snippet( $server_url, $auth_token )` — union return. Array returns are JSON-encoded by the Renderer; string returns are rendered verbatim. MUST embed both args in the output (no hardcoded URLs, no reading tokens from env vars or options). When `$auth_token === ''`, the token slot MUST render `self::EMPTY_TOKEN_PLACEHOLDER` (via the protected `safe_token()` helper).

---

## Optional contract (concrete subclass MAY override)

All six have safe defaults on the abstract base. Overriding is opt-in; a subclass that only implements the three required methods will still enumerate correctly (with empty display metadata + default priority 100).

```php
public function get_icon(): string;              // default: ''
public function get_description(): string;       // default: ''
public function get_config_file(): string;       // default: ''
public function get_top_level_key(): string;     // default: ''
public function get_instructions(): string;      // default: ''
public function get_priority(): int;             // default: 100
```

Constraints:

- `get_icon()` returns emoji or short display marker. Rendered as-is in HTML (no additional escaping — the Renderer treats it as text). Untranslated (it's a visual glyph, not copy).
- `get_description()` returns a one-line translated description. SHOULD wrap in `__(..., '<consumer-plugin-text-domain>')`.
- `get_config_file()` returns a config file path hint. Untranslated (technical string). May contain `~` (rendered as-is; end user expands their own home dir).
- `get_top_level_key()` returns the JSON/TOML top-level key the snippet is pasted under. Untranslated.
- `get_instructions()` returns multi-step setup instructions. SHOULD wrap in `__(..., '<consumer-plugin-text-domain>')`. May include Unicode arrows or other formatting; rendered inside a `<p>` (whatever the Renderer wraps it with).
- `get_priority()` returns the sub-nav slot integer. Lower = earlier. No validation — any integer including negative accepted. Default `100` places third-party contributions after all built-ins (which use 10, 20, 30, …, 80). Tiebreaker for equal priorities is slug ascending.

---

## Static factory contract (added by F034)

```php
public static function get_all_registered_clients(): array;   // returns AbstractMCPClient[]
```

**Behaviour** (see `data-model.md` §"Canonical enumeration method" for the full procedure):

1. Fires `apply_filters( 'acrossai_mcp_client_classes', self::DEFAULT_CLIENT_CLASSES )` exactly once per call.
2. Validates each contributed FQN: must be a string; must be a class that exists; must extend `AbstractMCPClient`. Invalid → silently skipped (SEC-013-008).
3. Instantiates each valid FQN and validates the resulting `get_client_slug()` against `/^[a-z0-9-]{1,64}$/`. Invalid → `_doing_it_wrong` under `WP_DEBUG`, skip.
4. Dedups by slug (later-wins). Duplicate → `_doing_it_wrong` under `WP_DEBUG`.
5. Sorts by `get_priority()` ascending, tiebreaker slug ascending.
6. Returns the sorted, deduped, `array_values`-normalized list.

Consumers of this method receive an `AbstractMCPClient[]` — instances, not FQNs. Every instance is fresh (no caching).

**Deleted by F034**: `AbstractMCPClient::get_all_clients()` (the pre-refactor glob-based enumeration). Any caller using it MUST migrate to `get_all_registered_clients()`.

---

## Extension seams

**Filter**: `acrossai_mcp_client_classes` — array of FQN strings. Third-party plugins add their own concrete subclass FQN via this filter. Signature unchanged from pre-refactor:

```php
add_filter( 'acrossai_mcp_client_classes', function ( array $fqns ): array {
    $fqns[] = MyPlugin\ZedClient::class;
    return $fqns;
} );
```

No other extension points. No actions fired inside `get_all_registered_clients()` (compared to `ConnectorProfileRegistry` which also fires none — pattern parity).

---

## Preserved public API (pre-refactor consumers keep working)

| Symbol | Kind | Notes |
|---|---|---|
| `AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER` | const string | Rendered when `$auth_token === ''` |
| `AbstractMCPClient::SERVER_KEY_FALLBACK` | const string | Fallback when URL parsing fails |
| `AbstractMCPClient::get_client_slug()` | abstract method | Unchanged signature |
| `AbstractMCPClient::get_client_name()` | abstract method | Unchanged signature |
| `AbstractMCPClient::get_config_snippet(...)` | abstract method | Unchanged signature |
| `AbstractMCPClient::build_server_url(...)` | protected helper | Unchanged |
| `AbstractMCPClient::derive_server_key(...)` | protected helper | Unchanged |
| `AbstractMCPClient::safe_token(...)` | protected helper | Unchanged |
| `AbstractMCPClient::current_username()` | protected helper | Unchanged |
| `AbstractMCPClient::redact_token(...)` | protected helper | Unchanged |
| `acrossai_mcp_client_classes` filter | WP filter | Contract unchanged (array of FQN strings) |
| `MCPClientsBlock::instance()` | public static method | Unchanged |
| `MCPClientsBlock::slug()` | public method | Unchanged; returns `'clients'` |

---

## Grep-gate invariants (post-refactor)

The following greps MUST return the described results after F034 lands. Enforced by SC-002/003/004 in the spec and re-verified in the Manual Verification Checklist:

```bash
# Deleted — must be zero hits:
grep -rEn 'CLIENT_META' includes/ admin/ public/ tests/           # → 0 hits
grep -rEn '\bget_all_clients\(\)' includes/ admin/ public/ tests/  # → 0 hits

# Preserved — must still resolve to a callable path:
grep -rEn 'acrossai_mcp_client_classes' includes/ admin/ public/  # → hits only inside AbstractMCPClient::get_all_registered_clients()
grep -rEn 'get_all_registered_clients\(\)' includes/ public/       # → at least one hit inside MCPClientsBlock::render_body()
```
