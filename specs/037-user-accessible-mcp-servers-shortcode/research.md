# Phase 0 — Research

**Feature**: F038 — User-Accessible MCP Servers Shortcode + Reusable Base Class
**Status**: All spec-level NEEDS CLARIFICATION resolved during `/speckit.specify` (6 Q&A pairs in `spec.md` §Clarifications). This document records the composition decisions the plan depends on.

Every "unknown" F038 might have surfaced is bounded by a shipped upstream contract. Research reduces to justifying **why delegate vs. re-implement** at each seam.

---

## R1 — Server enumeration source

- **Decision**: Use `MCPServerQuery::instance()->query( [ 'is_enabled' => 1, 'number' => -1 ] )` (F011).
- **Rationale**: Canonical BerlinDB Query surface. Handles the `is_enabled` filter server-side via the `is_enabled` column added by F011. `number => -1` returns every match without pagination (typical fleets are 1–20 servers; scaling is not a design concern per spec §Performance Goals).
- **Alternatives considered**:
  - Direct `$wpdb->get_results()` on `wp_acrossai_mcp_servers` — rejected: bypasses BerlinDB's `Row` type coercion (B18), violates §III (raw SQL) unless prepared, and duplicates the Query wrapper's schema knowledge (§VI DRY).
  - `WPBoilerplate\McpServersList\McpServersList::instance()->collect()` (constitution §Integration Resilience) — rejected: that helper returns adapter-registered servers keyed by slug, not DB rows with `id` / `server_name` / `description`. It is the wrong surface for F038's per-DB-row iteration.

## R2 — Access-control gate

- **Decision**: Call `AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, $server_id )` per server row.
- **Rationale**: Canonical F015 / F032 helper. Fail-open when wpb-access-control absent, admin-bypass inside v2 vendor manager, defensive re-query on server-row race (Q2). Per D33, this same helper backs the OAuth authorize gate, CLI device-grant gate, and Application Password gate — F038 becomes the fourth call site of an already-battle-tested shared helper.
- **Alternatives considered**:
  - Read the `wp_mcp_access_control` table directly — rejected: violates DEC-ACCESS-CONTROL-V2-ADOPTION (wrapper pattern), duplicates fail-open logic (§VI), and misses the v2 admin-bypass hierarchy that's implemented inside the vendor manager.
  - Compute a per-user allow-list once at the top of `get_accessible_servers()` — rejected: the vendor manager already memoizes per-request internally; a wrapper-level cache would be redundant. Also complicates the "delegate, never re-implement" invariant.

## R3 — Per-DTO gate + master toggle

- **Decision**: Call `AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $dto_slug )` per (server, transport, DTO) triple. Let this call subsume the master-toggle check (its Gate 1).
- **Rationale**: R2-memoized per-request per F037. Handles the master `_embeds_enabled` toggle + per-DTO membership in `_embeds_clients` JSON in one memoized call. Per B32, the canonical resolver is the source of truth — F038 never re-derives from raw meta.
- **Alternatives considered**:
  - Short-circuit at the server level by reading `_embeds_enabled` directly and skipping the transport loop when OFF — rejected: violates FR-024 grep-gate + B32. The R2 memoization already handles this cheaply (first `is_enabled_for_server` call primes the master read; subsequent calls for the same server hit the cache).
  - Fetch `_embeds_clients` JSON once per server and iterate in F038 code — rejected: same B32 violation, and duplicates F037's `entry_enables_slug` decoder.

## R4 — Transport enumeration source

- **Decision**: Call `AbstractEmbedTransport::get_all_registered_transports()` once per `get_accessible_servers()` invocation. Iterate transports in the returned priority-ASC order.
- **Rationale**: Canonical F037 enumeration per D35 (self-contained subsystem contract). Fires `acrossai_mcp_embed_transports` filter, validates FQNs, dedups by key, sorts deterministically. Third-party companion plugins register a fourth transport once via this filter and it surfaces in F038 automatically (User Story 2, SC-005).
- **Alternatives considered**:
  - Hardcode the three built-in transports (`NpmEmbedTransport`, `ClientEmbedTransport`, `AiConnectorEmbedTransport`) — rejected: kills User Story 2 filter-driven extensibility, and B32's canonical-resolver principle.
  - Re-fire `acrossai_mcp_embed_transports` inside F038 with a seed derived from `get_all_registered_transports()` output — rejected: violates D35 delegation rule (FR-023 grep-gate).

## R5 — DTO source

- **Decision**: Call `$transport->get_dtos()` inside the per-transport loop. Consume each DTO's `slug` / `name` / `icon` / `description` / `meta` fields verbatim.
- **Rationale**: Each built-in transport routes `get_dtos()` through F035's `ConnectionMethodRegistry` (which delegates to `AbstractMCPClient::get_all_registered_clients()` for clients, `ConnectorProfileRegistry::get_profiles()` for AI connectors, and `NpmClientBlock::get_default_npm_method()` for NPM). F035 owns the DTO shape freeze at 1.0.0 per DEC-CLIENT-RENDERER-PUBLIC-API — F038 is downstream of that contract.
- **Alternatives considered**:
  - Call `ConnectionMethodRegistry::instance()->get_all()` directly instead of per-transport `get_dtos()` — rejected: skips the transport-level abstraction that lets companion plugins register their own transports outside the three built-in F035 categories. F038 must be filter-driven for the fourth-transport case (SC-005).

## R6 — Ordering

- **Decision**: Sort servers alphabetically by `server_name` (case-insensitive). Transports preserve F037's priority-ASC order. DTOs within a transport preserve `get_dtos()`'s emission order.
- **Rationale**: Deterministic default matches user expectations for a list rendered on their profile. Case-insensitive per typical UI convention. Alphabetical is unambiguous; date-of-access / creation-date orderings introduce hidden state that surprises admins auditing the surface. Third parties needing custom ordering hook `acrossai_mcp_user_accessible_servers` and re-sort (documented in User Story 3).
- **Alternatives considered**:
  - Order by `id` (creation order) — rejected: exposes an internal detail (server creation sequence) that has no user-facing semantic.
  - Server_slug alphabetical — rejected: `server_slug` is a route identifier optimized for URLs, not display. Human users read `server_name`.
  - Introduce a shortcode `order_by` attribute — rejected: MVP simplicity. The filter is the extension seam per §V.

## R7 — Styling delivery

- **Decision**: Inline `<style>` block emitted from `render_shortcode()` on first invocation per request (private static `$style_emitted` flag). No external CSS file. No `wp_enqueue_style`.
- **Rationale**: Match user directive ("styling is in shortcode"). `wp_enqueue_style` fires on every page load regardless of shortcode presence — wasteful when the shortcode is placed on 1–2 pages. Inline `<style>` costs one small block once per request. Class prefix `acrossai-mcp-servers` prevents theme selector collisions.
- **Alternatives considered**:
  - Enqueue via `wp_enqueue_scripts` action — rejected: fires site-wide even when the shortcode isn't rendered. Wasteful.
  - `wp_register_style` + `wp_enqueue_style` inside `render_shortcode()` — rejected: `wp_enqueue_style` inside the shortcode callback fires after `wp_head` on most themes → `<style>` lands in `<body>` at a random point → potential FOUC. Direct inline emit at the render point is deterministic.
  - Ship a separate `.css` file — rejected: violates user directive; adds a build artifact for ~30 lines of scoped CSS.

## R8 — Anonymous handling

- **Decision**: `get_accessible_servers()` returns `[]` when `$user_id <= 0`. `render_shortcode()` returns `''` when `get_current_user_id() === 0` (silent no-render).
- **Rationale**: Matches F037's `EmbedBlockRenderer::render_shortcode()` convention (silent no-render for anonymous). Access control is a logged-in-user concept; "anonymous" is a no-user state, not an access-denied state. The empty-state wrapper `--empty` is reserved for the different case of a logged-in user with zero accessible servers (User Story 1 scenario 5).
- **Alternatives considered**:
  - Render an empty-state message for anonymous ("Please log in to see your servers") — rejected: leaks the existence of gated content to unauthenticated visitors. Silent no-render matches WordPress convention for restricted shortcodes.
  - Return `null` from `get_accessible_servers()` for anonymous — rejected: forces every caller to null-check. `[]` is the natural "no results" value; callers can `count()` or `foreach()` uniformly.

## R9 — Testing surface

- **Decision**: Register a new `user-servers` PHPUnit suite in `phpunit.xml.dist` pointing at `tests/phpunit/Public/Renderers/UserServers/`. Bootstrap via `tests/bootstrap-wp.php` (needs WP option store + BerlinDB tables for `MCPServerQuery` + F037 meta reader).
- **Rationale**: Matches F036 test-infrastructure precedent (`discovery` suite for `ConnectionMethodRegistry`) and F037 (`embeds` suite for `EmbedsTab`). Fully-WP bootstrap required because gates transitively touch F011 Query, F037 meta reader, F015 access-control provider chain — none of which mock cleanly at the pure-PHP boundary. Not a candidate for A12 pure-PHP or A18 stubbed-WP.
- **Alternatives considered**:
  - Extend the existing `renderers` PHPUnit suite — rejected: suite naming convention is one-per-feature (F035 `discovery`, F037 `embeds`, F038 `user-servers`). Consistency with the CI job matrix.
  - Pure-PHP suite with in-memory stubs of `MCPServerQuery` + F037 meta reader — rejected: composition is dense enough that stub maintenance overhead exceeds test cost. WP bootstrap is fast (~2s cold; parallel with existing suites).

---

## Consolidated dependencies list (post-research)

Every symbol F038 code touches, and where it comes from:

| Symbol | Origin | Contract stability |
|--------|--------|---------------------|
| `\AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query()` | F011 | Stable |
| `\AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance()->user_has_server_access()` | F015 / F032 | Stable |
| `\AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport::get_all_registered_transports()` | F037 | `@experimental` until 1.0.0 |
| `\AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport::is_enabled_for_server()` | F037 | `@experimental` until 1.0.0 |
| `AbstractEmbedTransport::get_transport_key()`, `get_checkbox_label()`, `get_priority()`, `get_dtos()` (instance) | F037 | `@experimental` until 1.0.0 |
| F035 DTO shape (`slug` / `name` / `icon` / `description` / `meta`) | F035 | `@experimental` until 1.0.0 |
| `get_current_user_id()`, `add_shortcode()`, `shortcode_atts()`, `esc_html()`, `esc_attr()`, `esc_url()`, `apply_filters()`, `__()` | WordPress core | Stable |

Every `@experimental` upstream contract is documented in F038's plan as inherited experimental status. If any upstream shape drifts before 1.0.0, F038 tracks the drift via its own experimental docblock.

---

**Phase 0 result**: no unresolved unknowns. Proceed to Phase 1 (data-model.md + contracts/ + quickstart.md).
