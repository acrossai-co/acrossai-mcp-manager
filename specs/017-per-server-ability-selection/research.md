# Phase 0 — Research

**Feature**: 017 — Per-server Ability Selection
**Status**: All `NEEDS CLARIFICATION` markers resolved during `/speckit-clarify` (Session 2026-07-07 — three Q/A pairs). No new research questions were opened by planning. This file consolidates the technical decisions the plan depends on.

## Decisions

### D1 — Storage layer: BerlinDB module vs WordPress options/meta

- **Decision**: BerlinDB module (`MCPServerAbility` — Schema/Table/Query/Row) matching the F011 pattern.
- **Rationale**: The data is inherently two-dimensional (server × ability) with UNIQUE-constraint semantics and per-server range queries. Options/meta cannot express `UNIQUE(server_id, ability_slug)` cleanly, and per-server reads would scan the entire postmeta table under high ability counts. BerlinDB gives prepared-statement safety (S4), a phantom-version guard (DEC-BERLINDB-TABLE-REQUEST-BOOT precedent), and a sibling-plugin-consistent shape.
- **Alternatives considered**:
  - **`update_option( 'acrossai_mcp_server_' . $id . '_abilities', $array )`** — rejected: unbounded option size when abilities grow; every write invalidates option cache; no per-slug UPDATE.
  - **`update_post_meta` on a CPT `mcp_server`** — rejected: F011 already chose a BerlinDB table for the server list; a hybrid would fracture the model.

### D2 — REST namespace + route shape

- **Decision**: Extend the existing `acrossai-mcp-manager/v1` namespace with two routes: `GET|POST /servers/(?P<server_id>\d+)/abilities`.
- **Rationale**: Constitution Principle IX ("REST namespace = `acrossai-mcp-manager/v1` — never shorten") pins the namespace. The `/servers/{id}/abilities` sub-resource shape matches WP-REST conventions and the F015 access-control precedent under the same namespace.
- **Alternatives considered**:
  - **New `abilities/v1` namespace** — rejected: multiplies operator-visible surfaces without benefit.
  - **A single `POST /abilities/toggle` route** — rejected: doesn't support the bulk-write path cleanly and loses per-server 404 semantics.

### D3 — Client-side framework: `@wordpress/dataviews` vs alternatives

- **Decision**: `@wordpress/dataviews` + `@wordpress/components` + `@wordpress/element` + `@wordpress/api-fetch` + `@wordpress/i18n` + `@wordpress/hooks`. No generic React libraries.
- **Rationale**: Constitution Principle IV (User-Centric Design) mandates `DataViews` for admin listings. Principle VI (Reusability & DRY) mandates Tier 1 `@wordpress/*` first. `@wordpress/scripts` externalizes these via the asset manifest so the runtime bundle stays small.
- **Alternatives considered**:
  - **`react-table` + `react-query`** — rejected: violates Principle IV; ships duplicate React state machinery.
  - **Hand-rolled `<table>` HTML with `useState`** — rejected: reimplements what `DataViews` already handles (search, filters, sort, bulk actions, pagination).

### D4 — Extensibility surface: `@wordpress/hooks` filters

- **Decision**: Three JS filter points (`acrossaiMcpManager.abilities.{fields, actions, row}`) + one PHP filter (`acrossai_mcp_ability_row`) + one PHP action (`acrossai_mcp_ability_exposure_changed`). No custom `register()` API.
- **Rationale**: Constitution Principle V mandates that third-party integrations use WordPress action/filter hooks. `@wordpress/hooks` is the canonical JS analog. DEC-CLIENT-RENDERER-PUBLIC-API (F013) establishes the `@experimental May change without notice before 1.0.0` stability marker — F017 adopts it verbatim on every new filter/action docblock.
- **Alternatives considered**:
  - **A custom `window.acrossaiMcpAbilities.register({ column, action })` API** — rejected: violates DRY (Principle VI); companion plugins would need to learn a plugin-specific surface instead of the WP-standard hooks system.
  - **Publish React component slots (e.g., a `<Slot />` from `@wordpress/components`)** — rejected: heavier surface; couples companion plugins to specific React internals; harder to version.

### D5 — Effective-exposure resolution

- **Decision**: Single stateless service `ExposureResolver::resolve( $server_id, $ability_slug, $meta ): bool`. Returns `(bool) $row->is_exposed` when a row exists; falls back to `! empty( $meta['mcp']['public'] )` otherwise. Per-request static cache keyed by `"{$server_id}:{$ability_slug}"`.
- **Rationale**: FR-008 mandates a single resolution surface. Static cache avoids N queries during the REST GET's iteration over `wp_get_abilities()`.
- **Alternatives considered**:
  - **Inline resolution inside the REST controller** — rejected: any second consumer (PHP filter callback, WP-CLI subcommand added later) would duplicate the logic.
  - **Object cache (`wp_cache_get`)** — deferred: per-request static is sufficient given no long-lived worker processes. Add object cache only if F017's follow-up feature introduces persistent daemons.

### D6 — Orphan-row handling (Q2 clarification, FR-025)

- **Decision**: Preserve orphan rows silently — READ endpoint excludes them from the response; no cleanup UI, no admin notice. Reactivate when slug is re-registered.
- **Rationale**: Data-preservation-by-default matches F015 Access Control's "rules survive plugin deactivation" precedent. Explicit purge (Option C in the clarification prompt) would destroy operator work when a plugin is temporarily deactivated.

### D7 — Audit trail for exposure changes (Q1 clarification, FR-024)

- **Decision**: Fire `do_action( 'acrossai_mcp_ability_exposure_changed', $server_id, $ability_slug, $was, $now, $user_id )` on effective change only. No built-in persistence in F017.
- **Rationale**: D19 fail-open observability precedent (F015). Operators + third-party plugins can subscribe to record events without a hard dependency in this plugin. Persistent storage is a follow-up feature if usage warrants it.

### D8 — Filter defensive boundary

- **Decision**: Wrap every `applyFilters()` call in a `safeApplyFilters()` helper that catches, logs `console.error`, validates return type, and falls back to input. On the PHP side, discard non-array filter returns and emit `_doing_it_wrong()`. Re-assert built-in field/action/row keys via `array_merge( $filtered, $row )` so extensions cannot remove or overwrite core columns.
- **Rationale**: FR-029 mandates no white-screen from third-party failures. DEC-CLIENT-RENDERER-PUBLIC-API's "silently skip invalid" pattern is the F013 precedent — F017 strengthens it with the additive-merge invariant.

## Open Questions

**None.** Every implementation choice traces to an active decision, constitutional principle, or completed clarification recorded in `spec.md` §Clarifications.

## Best-Practices References

- **BerlinDB Kern v3** — `vendor/berlindb/core/src/Database/Kern/{Schema,Table,Query,Row}.php`. F011 module `includes/Database/MCPServer/*.php` is the copy-paste template.
- **`@wordpress/dataviews`** — `node_modules/@wordpress/dataviews/README.md` for the `fields`/`actions`/`view` contract.
- **`@wordpress/hooks`** — `node_modules/@wordpress/hooks/README.md` for `applyFilters`/`addFilter` signatures.
- **F015 access-control precedent** — `src/js/access-control.js` + `admin/Main.php::maybe_enqueue_access_control_app()` for the enqueue-guard shape.
- **F013 public API precedent** — DEC-CLIENT-RENDERER-PUBLIC-API in `docs/memory/DECISIONS.md:707` for the `@experimental` stability marker convention.
