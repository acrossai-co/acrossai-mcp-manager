# Implementation Plan — F071 Additional MCP Client Integrations

**Branch**: `071-add-more-mcp-clients` | **Date**: 2026-08-19 | **Spec**: [spec.md](./spec.md)

## Summary

Pure additive change to the `includes/MCPClients/` module. Eight new `final class` files matching the shape established by F034's self-contained subsystem contract (`D35`). One-line addition to `AbstractMCPClient::DEFAULT_CLIENT_CLASSES` to register them. Two test-count assertions bumped from 8 to 16. No new dependencies, no schema changes, no REST changes, no wizard React changes — every consumer (wizard Step 7 / Step 11, admin snippet display, future `/clients` endpoint) picks up the new clients automatically via `get_all_registered_clients()`.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline)
**Primary Dependencies**: none new — reuses existing `AbstractMCPClient` base + `@automattic/mcp-wordpress-remote` npx bridge (shipped by upstream, referenced only in `get_config_snippet()` output strings)
**Storage**: none — client definitions are code, no DB rows
**Testing**: PHPUnit `mcp` suite — extends `GetAllRegisteredClientsTest`
**Constraints**: **Strictly additive** — no changes to `AbstractMCPClient`'s public API, no changes to `ConnectionMethodRegistry`, no changes to REST routes. Preserves `D35` (self-contained subsystem contract) and `A11` (pure service class exemption).
**Scale/Scope**: 8 new files × ~100 LOC each = ~800 LOC net addition. Zero user-visible behavioral change beyond "more clients in the picker".

## Constitution Check

*Every principle evaluated. All pass without deviations.*

**I. Modular Architecture** — Each new client is a self-contained `final class` in `includes/MCPClients/`. No cross-module references.

**II. Additive Only** — Zero changes to existing behavior. New classes cannot regress old ones because they live in separate files.

**III. Security** — No new inputs, no new outputs, no new auth. Every `get_config_snippet()` output goes through `safe_token()` for the app password (empty-placeholder guard) and `current_username()` for the WP user (defensive against unauthenticated calls). No SQL, no HTTP calls, no cookie/session state.

**IV. UI Components** — No admin UI changes. The wizard's existing DataViews-based picker picks up the new clients from `ConnectionMethodRegistry::get_clients()` without React updates.

**V. Extensibility** — F034's `acrossai_mcp_client_classes` filter still gates all client contributions. Third-party plugins can still add or replace any of the new 8 clients.

**VI. DRY** — Reuses `AbstractMCPClient` template method + `derive_server_key()` + `safe_token()` + `current_username()` for every new class. Zero duplicated logic.

**VII. Tests First** — PHPUnit test-count assertions updated in the same PR as the client-class additions. `GetAllRegisteredClientsTest` proves priority-sort ordering + total count.

## Constitution-adjacent memory guidance

- **D35** (F034 self-contained subsystem contract) — every new client class owns its own metadata. No renderer or admin partial hardcodes knowledge of any specific new client.
- **A11** (pure service class exemption from singleton rule) — every new client extends `AbstractMCPClient`, which itself is exempt from `A2` under `A11`. New classes follow suit.
- **DEC-CLIENT-RENDERER-PUBLIC-API** — public Renderer layer stays `@experimental until 1.0.0`. Adding clients doesn't change the public API surface.

## Project Structure

### Documentation (this feature)

```
specs/071-add-more-mcp-clients/
├── spec.md                    # Feature specification (this feature's user stories + FRs)
├── plan.md                    # This file
└── tasks.md                   # Ordered task list with per-task file/verification
```

### Source Code Changes

```
includes/MCPClients/
├── AbstractMCPClient.php      # MODIFIED: add 8 new FQNs to DEFAULT_CLIENT_CLASSES
├── WindsurfClient.php         # NEW
├── ZedClient.php              # NEW  ← non-standard `context_servers` + `source`/`enabled`
├── ClineClient.php            # NEW
├── RooCodeClient.php          # NEW
├── KiloCodeClient.php         # NEW
├── AmazonQClient.php          # NEW
├── OpenCodeClient.php         # NEW  ← non-standard `mcp` + `type:local` + `command:[]` + `environment`
└── AntigravityClient.php      # NEW

tests/phpunit/MCPClients/
└── GetAllRegisteredClientsTest.php    # MODIFIED: bump 8→16 in three assertCount() calls + update expected slug order array
```

## Design decisions

### D-071-01 — All 8 new clients ship in one PR, not split by client

**Decision**: bundle all 8 in a single PR rather than 8 separate PRs.

**Rationale**: Each client is ~100 LOC of near-identical structure (only slug, name, icon, description, paths, top_level_key, entry shape differ). Splitting would create 8 PRs with 90% duplicate review overhead. The test-count assertion + `DEFAULT_CLIENT_CLASSES` registration are single-point changes touched once, not eight times.

**Trade-off**: Larger diff (~800 LOC) vs faster reviews per client. Acceptable given the mechanical uniformity.

### D-071-02 — Priorities 72-79, keeping CustomClient at 80

**Decision**: New clients occupy priority slots 72-79 (interleaved between GeminiClient at 70 and CustomClient at 80).

**Rationale**: CustomClient must remain LAST in the sub-nav so users see all named integrations before the manual-config fallback. Slotting new clients at 72-79 achieves this without disturbing the original 10-80 spacing. Order within 72-79 reflects estimated user-base size (Windsurf > Zed > Cline > Roo > Kilo > Amazon Q > OpenCode > Antigravity).

**Trade-off**: If we ever add a 9th new client, we're out of integer slots. Fine — reserve 72-79 for THIS batch, use 82-89 or similar for the next.

### D-071-03 — Zed's `source`/`enabled` + OpenCode's `type`/`command`-as-array kept inside each class's `get_config_snippet()`

**Decision**: The two non-standard entry shapes (Zed's `context_servers` + `source`/`enabled` prefix; OpenCode's `mcp` + `type:local` + `command:[]` + `environment`) live inline in each class's `get_config_snippet()` method — NOT extracted into a shared "entry builder" helper.

**Rationale**: Only 2 of 16 clients deviate from the standard shape. Extracting a shared builder abstraction would introduce indirection that pays off only if a 3rd or 4th shape emerges. Inline shape means each client class is fully self-describing per `D35`; readers don't have to jump to a helper file to understand what a client will emit.

**Trade-off**: Duplication of the standard `command:'npx' + args + env` skeleton across 14 clients. Accepted — the skeleton is 6 lines; if we ever refactor, `AbstractMCPClient::build_standard_entry()` can absorb them mechanically.

## Phase 0 — Research

Nothing new to research. All 8 clients' config file paths + top-level keys are documented in their respective vendor docs and verified against a competing WordPress MCP plugin's implementation. Vendor bridge (`@automattic/mcp-wordpress-remote`) is the same one already used by all existing clients.

## Phase 1 — Implementation

See `tasks.md` for the ordered task list.
