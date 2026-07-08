# Phase 0 — Research

All Technical Context choices reviewed for open questions. Every candidate `NEEDS CLARIFICATION` was closed either during `/speckit-clarify` (3 clarifications, captured in `spec.md` §Clarifications) or by the memory-informed synthesis (`memory-synthesis.md`). Zero unknowns remain that block Phase 1 design.

## Decisions (grouped by source)

### From `/speckit-clarify` Session 2026-07-09

- **Decision**: MCP server deletion cascades to `wp_acrossai_mcp_server_tools` — Feature 020 owns the cleanup via a WordPress action hook on the server-deletion event. (FR-026.)
  **Rationale**: Presence-based storage means an orphaned row would look like an "added tool for a non-existent server" — semantically meaningless and a source of confusion for anyone auditing the table directly. Auto-cleanup keeps the table lean and predictable across server recreation.
  **Alternatives considered**: (a) DB-level `ON DELETE CASCADE` — requires a foreign-key constraint that BerlinDB doesn't emit natively; would fragment the schema-generation convention. (b) Leave rows orphaned — accumulates dead data; violates "no third state" semantics.

- **Decision**: Draft state is ephemeral to the current React mount — reload = clean slate, no `sessionStorage` rehydration, no `beforeunload` prompt. (FR-027.)
  **Rationale**: Matches F017's mount-fresh-from-server pattern; the underlying ability catalog can change between page loads and rehydrating a stale draft would show phantom rows. The Save button is one click away — the operator's mental model is "if I want to keep it, I click Save".
  **Alternatives considered**: (a) `sessionStorage` rehydration — introduces stale-draft bugs when the ability catalog mutates between sessions. (b) `beforeunload` prompt — annoying and unusual for a WP admin screen; violates least-surprise.

- **Decision**: Uninstall drops the table and deletes the `db_version_key` option only when the operator has explicitly opted in via `acrossai_mcp_uninstall_delete_data === 1`. The `DROP TABLE` + `delete_option` statements live BELOW the existing opt-in short-circuit in `uninstall.php`; F020 adds no second gate. (FR-028.)
  **Rationale**: Enforced by `DEC-UNINSTALL-OPT-IN-GATE` (Active F012). Matches the four existing BerlinDB tables verbatim. Preserve-by-default satisfies WordPress.org guideline #5.
  **Alternatives considered**: Unconditional DROP on uninstall — hard conflict with DEC-UNINSTALL-OPT-IN-GATE; the plan-with-memory phase surfaced this and it was corrected in-spec before this phase.

### From memory synthesis (durable pattern re-use)

- **Decision**: Extend `\BerlinDB\Database\Kern\*` via leading-`\` FQN — no `use BerlinDB\Database\Kern\Table;` etc.
  **Rationale**: Enforced by `DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION` (Active F011). Local subclass names match the parent class names (`Table`, `Schema`, `Query`, `Row`), so a `use` would fatal with "Cannot redeclare class".
  **Alternatives considered**: Alias imports (`use \BerlinDB\Database\Kern\Table as BerlinDBTable;`) — legal but adds import noise; F017 didn't do it, F020 mirrors.

- **Decision**: Instantiate the new Table subclass at BOTH `Activator::activate()` AND `Main::bootstrap_database_tables()`.
  **Rationale**: Enforced by `DEC-BERLINDB-TABLE-REQUEST-BOOT` (Active F011). Activation-only boot leaves BerlinDB's DB interface empty on subsequent requests and Query falls back to `$table_alias` in FROM.
  **Alternatives considered**: None — this is a well-established anti-regression rule from F011.

- **Decision**: Use `'modified' => true` on the `updated_at` datetime column, NOT `'date_updated'`.
  **Rationale**: Bug pattern B21 — BerlinDB v3's recognized column flags do not include `date_updated`; the auto-update-on-write flag is spelled `modified`. Passing an unrecognized flag creates a dynamic property and trips PHP 8.2+ deprecation notices at every column boot.
  **Alternatives considered**: Auto-stamping `updated_at` manually inside `Query::add_item()` / `update_item()` overrides — legal but adds two overrides; the `'modified' => true` flag is the sanctioned path.

- **Decision**: Localize `restApiRoot` via `untrailingslashit( rest_url() )` in `Admin\Main::maybe_enqueue_tools_app()`.
  **Rationale**: Bug pattern B17 — `rest_url()` returns a trailing-slash URL; concatenation in JS produces `//`-double-slash paths that WordPress doesn't route → 404. F017 already applies this pattern.
  **Alternatives considered**: Use `rest_url( 'acrossai-mcp-manager/v1' )` — WordPress joins correctly, but the JS side already assembles the path from `namespace + serverId`, and threading the base namespace through PHP would require re-plumbing.

- **Decision**: Filter POST payload against `wp_get_abilities()` catalog before persisting; unknown slugs reject the entire batch with HTTP 400.
  **Rationale**: Bug pattern B7 — mass-assignment via forged POST keys. The React app can only produce slugs from the catalog it just fetched, but the REST endpoint is public API and must not trust its callers. All-or-nothing avoids partial-state confusion.
  **Alternatives considered**: Silently drop unknown slugs — surfaces no error to legitimate callers writing a valid + one-typo payload; violates least-surprise.

- **Decision**: Runtime string-key lookup against `wp.data.select('core/abilities')` with REST fallback via `?include_abilities=1`, not build-time import of `@wordpress/abilities`.
  **Rationale**: Bug pattern B22 — `@wordpress/*` v0.x packages not yet in `@wordpress/scripts` externals map. Build-time import silently bundles under an unregistered handle → runtime store lookup returns undefined → React app boots but never populates. F017 applies this pattern; F020 mirrors verbatim.
  **Alternatives considered**: Declare `@wordpress/abilities` as an explicit dependency + externals override — brittle across `@wordpress/scripts` upgrades. Runtime lookup is forward-compatible.

### From spec derivation (baseline architecture)

- **Decision**: Presence-based storage (row exists = added, no row = not added). No `is_exposed` boolean column. No `ExposureResolver` fallback layer.
  **Rationale**: Captured in `DEC-TOOL-SELECTION-PRESENCE-MODEL` (planning doc; to be captured in `docs/memory/DECISIONS.md` post-implement). The shuttle-picker UX has two states — in-list vs not-in-list — and a boolean would introduce a third state ("row exists but false") with no UI representation.
  **Alternatives considered**: Boolean-column model (mirror F017's `MCPServerAbility.is_exposed`) — F017 needs it because per-server abilities have a fallback layer (`meta.mcp.public`). F020 does not — the whole point is operator-curated presence.

- **Decision**: Explicit Save changes / Cancel batch commit (POST full desired set; server diffs).
  **Rationale**: Captured in `DEC-TOOL-EDIT-EXPLICIT-BATCH-COMMIT` (planning doc; to be captured post-implement). The mockup requires it; a shuttle picker without an undo affordance is worse UX than a toggle grid; server-side diff via `Query::replace_set()` is trivial.
  **Alternatives considered**: Optimistic per-toggle POST (mirror F017) — loses batch-undo; F017 works well for its DataViews grid, but the shuttle picker's mental model is "commit or discard a session".

- **Decision**: Hand-rolled two-column shuttle picker, not `@wordpress/dataviews`.
  **Rationale**: Soft deviation from Principle IV / DEC-WP-DATAVIEWS-OVER-REACT — justified in `plan.md` §Complexity Tracking. Captured post-implement as `DEC-TOOL-SHUTTLE-PICKER-OVER-DATAVIEWS`.
  **Alternatives considered**: (a) Two disjoint DataViews grids; (b) One DataViews grid with a boolean toggle column. Both materially degrade the mockup UX — see Complexity Tracking table.

## Open Questions Resolved

Zero. All `NEEDS CLARIFICATION` candidates were closed during `/speckit-clarify` or by memory synthesis before entering this planning phase. Any remaining edge cases are already documented in `spec.md` §Edge Cases (8 total) and do not require research investment.

## Next Phase

Phase 1 — Design & Contracts: `data-model.md`, `contracts/rest-api.md`, `contracts/js-hooks.md`, `quickstart.md`.
