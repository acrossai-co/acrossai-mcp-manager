# Implementation Plan — F073 Wizard Client Snippet Parity

**Branch**: `072-quick-setup-entry-points` (see § Branch / commit hygiene) | **Date**: 2026-08-20 | **Spec**: [spec.md](./spec.md)

## Summary

Small, contained parity fix. Two PHP files + one JSX + a few SCSS lines. Zero client-class changes, zero REST changes, zero new component. Piggybacks on the existing `GET /wp-json/acrossai-mcp-manager/v1/quick-setup/state` endpoint. The wizard's Step 11 was already 80% shaped correctly (pill picker + App Password notice + `<CodeBlock variant="pane">`) — F073 populates the missing DTO fields (`config`, `instructions`) and renders the two missing rows (Config File, Top-Level Key) plus the Instructions callout.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline). ES2022 / React 18 (@wordpress/scripts).
**Primary Dependencies**: none new — reuses `AbstractMCPClient::get_config_snippet()`, `get_instructions()`, `get_config_file()`, `get_top_level_key()`; reuses `CodeBlock`, `Notice`; reuses `MCPServerQuery`.
**Storage**: none — pure data-flow change, no DB, no options, no transients.
**Testing**: PHPCS + one manual byte-identity check + one manual smoke check.
**Constraints**: Strictly additive. Backward-compatible on `ConnectionMethodRegistry::get_clients()` (optional param defaults to null). Zero touch on Clients tab renderers.
**Scale/Scope**: ~50 LOC net addition across 2 PHP + 1 JSX + a few SCSS lines.

## Constitution Check

*Every principle evaluated. All pass without deviations.*

**I. Modular Architecture** — All changes stay in existing modules (`public/Discovery/`, `includes/REST/`, `src/js/quick-setup/steps/`, `src/scss/`). No cross-module coupling, no new singletons, no new hook registrations.

**II. Additive** — `get_clients()` accepts an optional param; existing zero-arg call site continues to work. New DTO fields (`instructions`, `config`) don't remove or rename anything. Step 11 already had the fallback code path — F073 just makes the happy path actually fire.

**III. Security** — Server URL resolved via `rest_url()` on a validated MCPServer row (fetched through `MCPServerQuery`, not raw `$_GET`). `get_config_snippet( $server_url, '' )` passes empty auth token deliberately — the snippet is a template, not a live credential. React consumes `activeClient.meta.*` fields, all rendered inside JSX text nodes (auto-escaped by React).

**IV. UI Components** — No React component API changes. Reuses `CodeBlock`, `Notice`. Two SCSS utility classes added; zero existing selector modified.

**V. Extensibility** — New `AbstractMCPClient` subclasses registered via `acrossai_mcp_client_classes` filter (F034) auto-appear on Step 11. The wizard has no hardcoded knowledge of any specific client class.

**VI. DRY** — Server URL formula, JSON encoding flags, and Access Control paragraph translation key are all shared with `MCPClientsBlock`. Zero duplication across surfaces.

**VII. Tests First** — No branching logic worth unit-testing (pure display of DTO fields). PHPCS gates PHP change; manual byte-identity check covers SC-001; manual smoke covers SC-002/SC-003; existing PHPUnit for `ConnectionMethodRegistry` is untouched (still passes with null default on new param).

## Constitution-adjacent memory guidance

- **F034 self-contained subsystem contract** — every displayable client attribute is already exposed via `AbstractMCPClient` public methods; F073 consumes those methods without introducing a bypass. No renderer or admin partial hardcodes knowledge of any specific client.
- **F071** — 8 new clients added in the last PR (`WindsurfClient`, `ZedClient`, `ClineClient`, `RooCodeClient`, `KiloCodeClient`, `AmazonQClient`, `OpenCodeClient`, `AntigravityClient`). F073 makes all 8 correctly render on Step 11 without any new wizard-side code (SC-005).
- **DEC-CLIENT-RENDERER-PUBLIC-API** — `MCPClientsBlock` is `@experimental until 1.0.0`. F073 does NOT couple the wizard to that internal API; the wizard reads the shared DTO, not the block. Both surfaces float on the stable `AbstractMCPClient` public methods.

## Project Structure

### Documentation (this feature)

```
specs/073-wizard-client-snippet-parity/
├── spec.md
├── plan.md
└── tasks.md
```

### Source Code Changes

```
public/Discovery/ConnectionMethodRegistry.php     # MODIFIED — get_clients() signature + body
includes/REST/QuickSetupController.php            # MODIFIED — handle_state() overrides methods.clients when server_id > 0
src/js/quick-setup/steps/Step11_ClientDetail.jsx  # MODIFIED — 2 meta rows + 1 instructions callout
src/scss/quick-setup.scss                         # MODIFIED — .qs-meta-row + .qs-meta-label + .qs-meta-value
```

No new files. No deletions.

## Per-file change map

### `public/Discovery/ConnectionMethodRegistry.php`

`get_clients()` signature evolves from `(): array` → `(?array $server = null): array`. Body change:

1. Resolve `$server_url` via `rest_url( trailingslashit( $server['server_route_namespace'] ) . $server['server_route'] )` when `$server` is provided.
2. Add `'instructions' => $client->get_instructions()` to every DTO (regardless of server presence).
3. When `$server_url !== null`, add `'config' => wp_json_encode( $client->get_config_snippet( $server_url, '' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )` (or the raw string if `get_config_snippet()` returns a string directly).

Docblock updated to document the new `?array $server` param and the F073 semantic contract.

### `includes/REST/QuickSetupController.php::handle_state()`

Between the existing `$methods = ConnectionMethodRegistry::instance()->get_all();` and the `rest_ensure_response(...)`, insert a `server_id > 0` guard that:
1. Runs `MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )`.
2. If a row returned, calls `ConnectionMethodRegistry::instance()->get_clients( $rows[0]->to_array() )` and overrides `$methods['clients']`.

Comment references `MCPClientsBlock:224-226` so a future reader can see why this URL-resolution helper is duplicated (it isn't — the registry method already contains the copy).

### `src/js/quick-setup/steps/Step11_ClientDetail.jsx`

Below the existing App Password `<Notice>`, add three conditionally-rendered blocks:
1. `activeClient.meta.config_file` → `<div className="qs-meta-row">` with `Config File` label.
2. `activeClient.meta.top_level_key` → `<div className="qs-meta-row">` with `Top-Level Key` label (value wrapped in quotes).
3. `activeClient.instructions` → `<Notice status="info">` with per-client `<p>` + shared Access Control `<p>` (same translation key as `MCPClientsBlock.php:256`).

The existing `<CodeBlock variant="pane">` slots between (2) and (3). Every other Step 11 element (title, pill picker, missing-server notice, missing-clients notice, App Password notice) is unchanged.

### `src/scss/quick-setup.scss`

Three utility classes for the meta rows. Fixed 120px label width. Monospace value font. No changes to existing classes.

## Migration Concerns

None. Additive DTO fields + optional PHP param + JSX additions all fail-safe when unpopulated. Operators on prior versions see the old Step 11 (raw DTO dump); after F073, they see the real snippet without any DB migration, cache flush, or rewrite-rule flush.

## Rollback

Revert the four modified files. `ConnectionMethodRegistry::get_clients()` reverts to zero-arg; `QuickSetupController::handle_state()` reverts to the direct `get_all()` return; Step 11 reverts to the raw-DTO-fallback branch of its existing `configText` ternary; the two SCSS classes go unused (harmless).

## Branch / commit hygiene

F073 was implemented on the existing `072-quick-setup-entry-points` branch alongside the F072 entry-point work and several follow-on UI tweaks (ability card rework on Step 4/5, AccessControl banner simplification, `PAID` → `RECOMMENDED` badge). Before the branch is opened as a PR, the right split is:

1. `feat(quick-setup): add 4 admin entry points + repoint activation redirect (F072)` — F072 code (5 files) + F072 spec-kit artifacts.
2. `refactor(ui): simplify Step 4 ability card + banner text (F072 follow-up)` — Step 4 layout + banner text changes.
3. `feat(quick-setup): recommended badge + wizard client parity (F073)` — F073 files + F073 spec-kit artifacts + the PAID→RECOMMENDED tweak.

Alternatively the whole branch can ship as one PR — the split is a review-ergonomics choice, not a correctness one.
