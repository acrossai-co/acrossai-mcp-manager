# Memory Synthesis

## Current Scope

Feature 026 (branch `026-abilities-into-tool-registration`) adds one new stateless method `ToolPolicy::compose_effective_tools_for_row( Row ): string[]` to `includes/Database/MCPServer/ToolPolicy.php` and swaps two call sites in `includes/MCP/Controller.php` (lines 142 + 247). The new method widens F025's `compose_for_row()` output by iterating `wp_get_abilities()` and appending every ability where `ExposureResolver::resolve( $server_id, $slug, $meta )` returns true. Fail-open when `wp_get_abilities()` is unavailable. Reuses F025's `acrossai_mcp_manager_server_tools` filter with widened pre-filter input; no new hook. REST GET `/tools` keeps calling `compose_for_row()` — Tools tab UX unchanged. No schema, no new file, no JS, no vendor edits.

Affected modules: `Database/MCPServer` (one file, one new method), `MCP/Controller` (two-line swap + docblock), plus PHPUnit extensions to two existing test files.

## Relevant Decisions

- **DEC-ABILITY-OVERRIDE-RESOLUTION (F017)** (Reason Included: F026 REUSES `ExposureResolver::resolve()` verbatim as the canonical per-(server, ability) decision-maker. Row-in-`wp_acrossai_mcp_server_abilities` beats `meta.mcp.public`; per-request static cache handles scale; no re-derivation of fallback logic. Status: Active F017; Source: DECISIONS.md).
- **DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED** (Reason Included: F026 extends F025's composer with a THIRD source (F017-effective abilities) alongside the existing two (enabled protocol columns + curated presence rows). Same union-and-dedup pattern; new source honors F017's own single-resolver contract. F026 does NOT add a fourth storage layer — just calls the existing F017 resolver. Status: Active F025 [extended F026]; Source: DECISIONS.md).
- **DEC-BERLINDB-TABLE-REQUEST-BOOT (F011)** (Reason Included: F026 reads from `wp_acrossai_mcp_server_abilities` via `MCPServerAbility\Query::instance()->query()` (inside `ExposureResolver::resolve()`). The F011 request-time boot pattern already instantiates that Table subclass per request via `Main::bootstrap_database_tables()` — F026 needs no new wiring. Status: Active F011; Source: DECISIONS.md).
- **DEC-WP-DATAVIEWS-OVER-REACT (F017)** (Reason Included: F026 has ZERO JS changes; the constraint is satisfied vacuously. Called out because the Abilities tab that operators use to make F017 exposure decisions is a `@wordpress/dataviews` surface, and F026 preserves it verbatim. Status: Active F017; Source: DECISIONS.md).
- **DEC-F020-TOOL-ENFORCEMENT-PRIORITY (F020)** (Reason Included: F026 does NOT add a new gate on `mcp_adapter_pre_tool_call` — the priority slot map (10/20/30) stays intact. F017's `AbilityExposureGate` at priority 20 continues to enforce per-server ability visibility at call-time, mirroring F026's advertisement-time visibility. Deny precedence honored: F017 gate at call-time can still deny an ability F026 advertised, matching spec §Edge Cases §3 (documented accepted inconsistency for curated + F017-hidden). Status: Active F020; Source: DECISIONS.md).

## Active Architecture Constraints

- **A1** — All hook registration in `Main.php` via `define_admin_hooks()`/`define_public_hooks()` (Reason: F026 adds ZERO new hooks. F025's existing `add_filter( 'mcp_adapter_default_server_config', ... )` at `Main.php:514` continues to wire the same callback whose body now calls the new composer method. No `Main.php` edit; Source: ARCHITECTURE.md).
- **A2** — Singleton `instance()` pattern (Reason: `Controller` remains a singleton. No changes to its instance-management shape; Source: ARCHITECTURE.md).
- **A6** — Cross-namespace refs use `use` imports (Reason: `ToolPolicy.php` gains `use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;` alongside the existing `MCPServerTool\Query` import. Watch B1 for bare relative-name silent failures; Source: ARCHITECTURE.md).
- **A11** — Pure service class exemption (Reason: F026's new method `compose_effective_tools_for_row()` is `public static` on the already-stateless `ToolPolicy` final class. No singleton needed; matches `DefaultServerSeeder` and F025's existing `compose_for_row()`/`split_payload()` shape; Source: ARCHITECTURE.md).
- **A4** — New admin UI uses DataForm/DataViews (Reason: F026 makes NO admin UI changes. Constraint is satisfied vacuously — no new UI to gate. The Abilities tab (`@wordpress/dataviews`) and Tools tab (React with `@wordpress/components`) both remain untouched; Source: ARCHITECTURE.md).

## Accepted Deviations

None directly relevant to F026. `DEV1` (MCP Manager parent menu `WP_List_Table`) does not apply. F025's soft-conflict `DEC-TOOL-SELECTION-PRESENCE-MODEL` deviation is orthogonal — F026 does not add a new storage layer.

## Relevant Security Constraints

- **S2** — All REST routes MUST have explicit `permission_callback` (Reason: F026 modifies NO REST routes. `ToolsController` GET/POST retain their F025-shipped `manage_options` check; `AbilitiesController` GET/POST retain their F017-shipped check; no new endpoints introduced; Source: CONSTITUTION.md §III).
- **S4** — All DB queries MUST use `$wpdb->prepare()` (Reason: F026 reads via `MCPServerAbility\Query::instance()->query()` inside `ExposureResolver::resolve()` — BerlinDB Kern prepared path. No raw SQL added; Source: CONSTITUTION.md §III).
- **S6** — Singleton `__construct()` MUST be private (Reason: `ToolPolicy` is A11-exempt (no ctor); `Controller` singleton unchanged. `ExposureResolver` is A11-exempt too — F026 consumes it verbatim; Source: PROJECT_CONTEXT.md).

## Related Historical Lessons

- **B18** — `$wpdb` returns TINYINT as string (Reason: `wp_acrossai_mcp_server_abilities.is_exposed` is `tinyint(1)`. F017's `ExposureResolver::resolve()` already casts to `bool` at line 69 (`(bool) $rows[0]->is_exposed`). F026 does NOT need to re-defend — the resolver's return is already correctly typed. Cross-reference for future maintainers).
- **B24** — Vendor accessor via `method_exists`, not `instanceof` (Reason: F026 touches ZERO vendor objects. Both call sites in `Controller` swap only the composer method name; the `$adapter->create_server(...)` call and its arguments are unchanged from F025. No new vendor-accessor surface).
- **B29** — Vendor `add_action` inside `__construct` misses actions that already fired (Reason: F026 iterates `wp_get_abilities()` which returns third-party abilities registered on `wp_abilities_api_init`. That action fires during WP `init`, well before F026's registration-time code runs on `mcp_adapter_init` — so third-party abilities ARE present. The three vendor MCP protocol slugs (which B29 covered) are NOT relevant to F026 since they come from `ToolPolicy::PROTOCOL_TOOLS` columns, not from `wp_get_abilities()`. F026's fail-open branch (FR-003) provides belt-and-braces safety for edge cases where the Abilities API isn't bootstrapped).

## Conflict Warnings

- **None.** F026 introduces no new decisions that conflict with existing memory. It EXTENDS `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` (adds a third composition source) and REUSES `DEC-ABILITY-OVERRIDE-RESOLUTION` (calls the canonical resolver). Both extensions are strict additions; no memory entry needs to be deprecated or superseded.

## Retrieval Notes

- Config: `.specify/extensions/memory-md/config.yml` present; `optimizer.enabled: false` — proceeded with markdown-only, index-first retrieval.
- Read: `docs/memory/INDEX.md` (from earlier F025 turn — still current after F025 captures on 2026-07-14 added D-F025-* rows + B29).
- Selected: 5 decisions, 5 architecture constraints, 0 deviations, 3 security constraints, 3 bug patterns (as related lessons), 2 worklog items (implicit via F025 + F017 references). Under budget on every axis.
- Deferred / not loaded: full `DECISIONS.md`, full `ARCHITECTURE.md`, `BUGS.md`, `WORKLOG.md` — the INDEX summaries plus F025's synthesis (which is closely parallel) were sufficient.
- No feature `memory.md` present at `specs/026-abilities-into-tool-registration/memory.md`.
- Word count: ~880 (under the 900-word budget).
- No hard conflicts. No soft conflicts. Proceed to `/speckit-plan`.
