# Implementation Plan: MCP Client Classes — Pure Service Layer

**Branch**: `004-mcp-clients` | **Date**: 2026-06-17 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/004-mcp-clients/spec.md`

---

## Summary

Build 8 PHP files under `includes/MCPClients/`:
`AbstractMCPClient.php` + 7 concrete clients (ClaudeCode, ClaudeDesktop,
Codex, Cursor, Custom, GitHubCopilot, VSCode). Each concrete class is a
**stateless value producer**: given a server URL and an auth token, it
returns a copy-paste configuration snippet shaped to its AI tool's
documented format. No hooks, no DB, no HTTP, no global state.

**This is an interface-redesign port, not a 1:1 file copy** (per V2 of
the governance-plan turn 2026-06-17). The source repo's `src/MCPClients/`
ships a 6-method abstract interface (`get_id` / `get_label` / `get_icon`
/ `get_top_level_key` / `get_config_file` / `get_description`). This
phase replaces it with the spec's 3-method interface (`get_client_slug`
/ `get_client_name` / `get_config_snippet`). The source is consulted for
**canonical AI-tool envelope shapes** (paths, top-level keys, command
templates), not for code structure.

The consumer entry point is `AbstractMCPClient::get_all_clients(): array`
(V3=both — public static factory whose internal mechanism is the
file-scan + `is_subclass_of` discovery from the original FR-010). Phase
2's `Admin\Partials\ApplicationPasswords::render_for_server` is amended
in a separate follow-up (RT-3) to consume this method.

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.0+ (constitution target; plugin minimum 7.4) |
| Primary dependencies | `automattic/jetpack-autoloader ^5.0` (PSR-4 for the new files); no new composer deps |
| Storage | None (pure service classes, no state) |
| Testing | PHPUnit with golden-fixture comparison (one fixture per client per token-state); tests run **without WordPress bootstrap** to prove FR-008 architectural purity |
| Target platform | WordPress 6.9+ admin context (no admin UI of its own; consumed by Phase 2's Tokens-tab renderer) |
| Project type | WordPress plugin module — Includes namespace |
| Performance goals | `get_config_snippet()` returns in ≤1 ms p95 — these are pure string/array builders, no I/O |
| Constraints | FR-008 zero hooks/DB/HTTP/cookies/raw-output in `includes/MCPClients/`; FR-009 no singleton; FR-012 namespace `AcrossAI_MCP_Manager\Includes\MCPClients` |
| Scale / scope | 8 PHP files; ~80 + 7×~50 = ~430 lines total; 7 golden fixture pairs (string-snippet + array-snippet variants) |

### Hard prerequisite (P0 dependency)

**PHPUnit harness** (`tests/`, `phpunit.xml.dist`, autoload bootstrap)
must exist before the DoD gate can run. Same dependency model Phase 2
used for the BerlinDB Query classes. If the harness doesn't yet exist
when implementation begins, T000 of this phase's task list MUST set it
up first — this is a coordination prerequisite, not a code defect.

Status as of 2026-06-17: harness **does not yet exist** in the repo
(verified by `ls tests/` at planning time). The /speckit-tasks step
will include either a self-setup task or an external dependency on the
Phase 2 RT-4 follow-up.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| I. Modular Architecture | ✅ | 8 self-contained files in a dedicated module; no sibling deps |
| II. WordPress Standards | ✅ | PHPCS WPCS strict, PHPStan L8 mandated; no deprecated functions used |
| III. Security First | ✅ (boundary check) | No user input crosses a sanitization boundary in this module — caller (Phase 2) sanitizes; output escaping is consumer's responsibility (spec security checklist) |
| IV. User-Centric Design | ✅ | No new admin UI; consumed by existing Tokens tab |
| V. Extensibility Without Core Modification | ✅ | Adding a new client = exactly one new file (SC-002); no other file change required |
| VI. Reusability & DRY | ✅ | 3 shared helpers on the abstract base (`build_server_url`, `derive_server_key`, `redact_token`); no duplication across concrete clients |
| VII. Definition of Done | ✅ | PHPUnit golden fixtures lock per-client correctness |
| Boot Flow Rule (singleton + named variable + private ctor) | ⚠️ **Soft conflict A2 vs FR-009** | A2 mandates singleton for feature classes; FR-009 forbids singleton on MCPClients (stateless value producers). **Same logical exemption A10 documents for `WP_List_Table`** (different rationale: there it's WP-core ctor; here it's "no instance state to share"). Plan justification: A11 candidate captured in `memory-synthesis.md` Conflict Warnings — recommend post-implementation memory entry to elevate this exemption from spec-level to durable. **Not blocking.** |
| Module Contract item 2 (deps via `::instance()`, never constructor injection) | ✅ (V1 resolution) | Phase 4 user input had constructor-injection sketch; resolved per V1=singleton: Settings stays a no-arg singleton; consumer resolves MCPClients at use-site via `AbstractMCPClient::get_all_clients()` (parallel to Phase 2's vendor-singleton-at-use-site pattern, research.md R4). |
| A1 — Hook registration only via Loader | ✅ | Zero hooks in module (FR-008) |
| A6 — `use` imports / leading-`\` FQN inside `Includes\*` | ✅ | Abstract base + 7 subclasses + Phase 2 consumer all reference via `use` imports |
| B1 — Namespace silent-fail risk | ✅ | Mitigated by `use` imports throughout |

**Result**: All gates pass. **Complexity Tracking section is empty.**

## Project Structure

### Documentation (this feature)

```text
specs/004-mcp-clients/
├── plan.md              # THIS FILE
├── spec.md              # Feature spec (3 clarifications)
├── research.md          # Phase 0 output — per-client canonical envelope shapes
├── quickstart.md        # Phase 1 output — PHPUnit golden-fixture + grep gate procedure
├── memory-synthesis.md  # Memory-driven constraint synthesis (already produced)
├── checklists/
│   └── requirements.md  # Quality checklist (from /speckit-specify)
└── tasks.md             # Phase 2 output (NOT created by /speckit-plan)
```

Notes on absent artifacts:

- **`data-model.md` omitted** — no persistent storage; only one ephemeral entity (the client instance) already captured in spec.md §Key Entities. A separate file would just restate.
- **`contracts/` omitted** — no REST routes, no JS↔PHP contracts, no admin-ajax endpoints. The method-level contract is the spec's FR-001 + FR-002 signature block; no additional document needed.

### Source Code (repository root)

```text
includes/
├── MCPClients/                          # NEW MODULE
│   ├── AbstractMCPClient.php            # NEW — base + 3 protected helpers + get_all_clients() static factory
│   ├── ClaudeCodeClient.php             # NEW — string snippet (CLI install command)
│   ├── ClaudeDesktopClient.php          # NEW — array snippet (claude_desktop_config.json)
│   ├── CodexClient.php                  # NEW — array snippet
│   ├── CursorClient.php                 # NEW — array snippet (~/.cursor/mcp.json)
│   ├── CustomClient.php                 # NEW — array snippet (generic template)
│   ├── GitHubCopilotClient.php          # NEW — array snippet
│   └── VSCodeClient.php                 # NEW — array snippet

tests/                                    # PREREQUISITE (Phase 2 RT-4 or this phase's T000)
└── phpunit/
    └── MCPClients/
        ├── AbstractMCPClientTest.php    # NEW — helpers (build_server_url, derive_server_key, redact_token, get_all_clients)
        ├── ClaudeCodeClientTest.php     # NEW — golden-fixture: with token + empty token
        ├── ClaudeDesktopClientTest.php  # ...
        └── …                            # one test class per concrete client
        └── fixtures/
            ├── claude-code-with-token.txt
            ├── claude-code-empty-token.txt
            ├── claude-desktop-with-token.json
            ├── claude-desktop-empty-token.json
            └── …

# DOWNSTREAM CONSUMER (NOT in this phase — Phase 2 RT-3 amendment):
# admin/Partials/ApplicationPasswords.php::render_for_server()
#   amended to call AbstractMCPClient::get_all_clients() and surface
#   per-client snippet picker UI in the Tokens tab.
```

**Structure Decision**: Standard WordPress-plugin layout (constitution
Architecture & UI Standards). All new files in one module
(`includes/MCPClients/`). No edits to existing files in this phase —
Phase 2's amendment is a separate task.

## Phase 0 — Outline & Research

Four research outputs land in `research.md`:

### R1 — Per-client canonical envelope shapes (from source)

For each of the 7 concrete clients, read
`../acrossai-mcp-manager/src/MCPClients/<ClientName>Client.php` and
`../acrossai-mcp-manager/src/Admin/ApplicationPasswords.php::generate_mcp_server_config()`
to capture the canonical JSON envelope (for array-returning clients) or
CLI command template (for string-returning clients) the AI tool expects.

These shapes are external to this plugin — they come from the AI
vendors' own documentation, replicated in the source repo. We don't
invent them. R1 produces a 7-entry table mapping:
`{client_slug, return_type, envelope_template}`.

### R2 — `derive_server_key()` algorithm

Inputs: `$server_url` (string, may be empty or malformed).
Process:
1. Strip query string: `strtok($server_url, '?')`.
2. Strip trailing slash: `rtrim(..., '/')`.
3. Split on `/`: `explode('/', ...)`.
4. Return last non-empty segment.
5. Fallback to `'wordpress-mcp'` if no usable segment (empty URL, single
   path, etc.).

Edge cases:
- `''` → `'wordpress-mcp'`
- `'https://example.com/wp-json/mcp/foo'` → `'foo'`
- `'https://example.com/wp-json/mcp/foo/'` → `'foo'`
- `'https://example.com/wp-json/mcp/foo?x=1'` → `'foo'`
- `'foo'` (no scheme) → `'foo'`
- `'https://example.com/'` → `'wordpress-mcp'` (no path segments)

### R3 — Empty-token placeholder location

Per Q2 clarification, empty tokens render as `(paste generated password
here)`. The placeholder lives at the **token slot only** — the URL is
still embedded as-is. Helper for clarity:

```php
protected function safe_token(string $token): string {
    return '' === $token ? '(paste generated password here)' : $token;
}
```

Each concrete client calls `$this->safe_token($auth_token)` at the
token slot before composing the snippet. Centralizing the substitution
in the abstract base avoids 7-way drift (one source of truth for the
placeholder string).

### R4 — `get_all_clients()` internal mechanism (V3=both)

```php
public static function get_all_clients(): array {
    $clients = [];
    $module_dir = __DIR__;
    foreach ( glob( $module_dir . '/*.php' ) as $file ) {
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

Tradeoffs:
- File-scan is run on every call → tiny overhead (~7 file stats + 7
  `class_exists` checks). At most one call per admin page render →
  negligible.
- `glob()` results are alphabetical on most filesystems → deterministic
  order without explicit sort.
- No autoloader assumptions: `class_exists($fqn)` triggers the Composer
  autoloader naturally.

## Phase 1 — Design & Contracts

### Method signatures (frozen from spec)

```php
namespace AcrossAI_MCP_Manager\Includes\MCPClients;

abstract class AbstractMCPClient {

    abstract public function get_client_slug(): string;
    abstract public function get_client_name(): string;
    abstract public function get_config_snippet(
        string $server_url,
        string $auth_token
    ): string|array;

    public static function get_all_clients(): array;

    protected function build_server_url(
        string $base_rest_url,
        string $route_namespace,
        string $route
    ): string;

    protected function derive_server_key( string $server_url ): string;

    protected function safe_token( string $token ): string;

    protected function redact_token( string $token ): string;
}
```

Note: `safe_token` is NEW vs the spec — added per R3 to centralize the
placeholder substitution. Pure helper, no behavior change beyond DRY.

### Quickstart manual verification

See `quickstart.md` for the runnable procedure. Brief:

1. Drop the 8 files in place.
2. Run `vendor/bin/phpunit tests/phpunit/MCPClients/` — all golden
   fixture assertions pass.
3. Run the FR-008 grep gate:
   `grep -rnE 'add_action|add_filter|\$wpdb|wp_remote_(get|post)|setcookie' includes/MCPClients/` → empty.
4. Run the FR-009 grep gate:
   `grep -rn 'public static function instance' includes/MCPClients/` →
   empty.
5. Manual smoke from a CLI script (no WP bootstrap):
   `require 'AbstractMCPClient.php'; require 'ClaudeDesktopClient.php';
    var_dump( ( new ClaudeDesktopClient() )->get_config_snippet(
    'https://x.test/wp-json/mcp/foo', 'secret123' ) );`

### Agent context update

Update `.github/copilot-instructions.md` so downstream agents pick up
`specs/004-mcp-clients/plan.md` as the active plan.

## Complexity Tracking

> *Empty by design — Constitution Check passes without justified deviation.*
> The A2 vs FR-009 soft conflict is documented as an acceptable
> exemption parallel to A10 (`WP_List_Table` carve-out); not a violation.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| *(none)* | *(n/a)* | *(n/a)* |
