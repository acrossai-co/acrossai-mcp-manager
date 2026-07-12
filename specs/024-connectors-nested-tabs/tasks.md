# Tasks: F024 — AI Connectors Nested Tabs

**Input**: [spec.md](spec.md) + [plan.md](plan.md)
**Shipped**: 2026-07-11 (inline with F021 Phase 9 iteration)
**Status**: ✅ COMPLETE

Every task below is marked done. This document records what shipped rather than driving a fresh implementation.

## Format: `[ID] [P?] Description with file path`

---

## Phase 1: Data + service layer

- [x] T024-001 Add `mcp_url_for_server( array $server ): string` protected static helper on `AbstractConnectorProfile` — joins `server_route_namespace + server_route` via `rest_url()` per F011 CliController pattern. Fixes the `$server['mcp_url']` non-existent-column bug.
- [x] T024-002 Create `includes/Connectors/ConnectorSettings.php` — options-backed CRUD for `enabled`, `require_admin_approval`, plus adjacent approved/pending user_id lists.
- [x] T024-003 Add `matches_dcr_client( string, array<int, string> ): bool` to `AbstractConnectorProfile` (default false) — companion profiles override to claim DCR-registered clients.
- [x] T024-004 Add `get_mcp_url_setup_html( string $mcp_url ): string` to `AbstractConnectorProfile` (generic default) — companion profiles override for branded step-by-step instructions.
- [x] T024-005 Add `find_admin_clients_for_server_connector( int, string ): array<Row>` + `find_dcr_clients(): array<Row>` + `delete_by_id( int ): bool` methods to `OAuthClients\Query`.

## Phase 2: Admin REST endpoints

- [x] T024-010 Create `includes/OAuth/ConnectorAdminController.php` singleton with `register_routes()` + shared `admin_permission()` callback (`manage_options` + `X-WP-Nonce`).
- [x] T024-011 `POST /oauth/connector-settings` — save (enabled, require_admin_approval). On `enabled` flipping true→false, call `mass_revoke_connector_tokens()` with reason `'connector_disabled'`.
- [x] T024-012 `POST /oauth/revoke-client-tokens` — revoke every token for a `client_id`, fire `token_revoked` per row with reason `'admin_revoke'`.
- [x] T024-013 `POST /oauth/delete-client` — revoke tokens then delete the client row via `OAuthClients\Query::delete_by_id`.
- [x] T024-014 `POST /oauth/revoke-connector-tokens` — nuclear mass-revoke for a (server, connector) pair, reason `'admin_nuclear_revoke'`.
- [x] T024-015 `POST /oauth/approve-pending-consent` — admin approves a specific user's pending consent; removes from pending list + adds to approved list.
- [x] T024-016 Wire `ConnectorAdminController::register_routes` on `rest_api_init` from `Main.php::define_admin_hooks`.

## Phase 3: Authorization enforcement

- [x] T024-020 In `AuthorizationController::handle_get`, after PKCE + resource validation and before `render_consent`, add:
  - Resolve `server_id` via new `server_id_from_client_and_resource( ClientRow, string )` helper (parse `server-{id}` prefix for admin clients; walk MCPServer rows matching resource URL for DCR clients).
  - Resolve `slug` via `client->connector_slug` OR `infer_slug_from_dcr_client( ClientRow )` (walks profiles asking `matches_dcr_client`).
  - If `ConnectorSettings::is_enabled( server_id, slug ) === false`: `redirect_error( access_denied )`.
  - If `require_admin_approval` is true AND current user not in approved list: add user to pending list + call `render_pending_approval` (renders a lightweight standalone HTML page).
- [x] T024-021 Add `render_pending_approval( ClientRow, array )` method — self-contained HTML with `noindex, nofollow` + no admin frame.

## Phase 4: Admin UI — nested tabs

- [x] T024-030 Rewrite `AIConnectorsTab::render_body`:
  - Parse `?connector=X&panel=Y` query params (defaults: first profile alphabetically, `generate`).
  - Render Level 2 nav bar via `render_level2_bar` — one `.nav-tab` per profile.
  - Render Level 3 nav bar via `render_level3_bar` — Generate | Connections | Settings.
  - Dispatch to the active panel renderer via `render_panel`.
- [x] T024-031 Implement `render_generate_panel` — MCP URL + Copy + connector-specific setup HTML (`get_mcp_url_setup_html`) + collapsible "Advanced: pre-generate credentials manually" containing the F021 Phase 9 admin-generate flow via `render_tab_section`.
- [x] T024-032 Implement `render_connections_panel` — table of every OAuth client for this (server, connector). Merges admin-generated clients (via `find_admin_clients_for_server_connector`) with DCR-registered clients that the profile claims (via `matches_dcr_client`). Empty state + per-row Revoke tokens + Delete client actions.
- [x] T024-033 Implement `render_settings_panel` — form with `enabled` + `require_admin_approval` checkboxes + Save button + pending approvals list with per-user Approve buttons + nuclear "Revoke all connections" button.
- [x] T024-034 Add `panel_url( array $server, string $slug, string $panel ): string` helper for consistent URL construction across the nav bars.

## Phase 5: Shared JS + CSS additions

- [x] T024-040 Extend `src/js/ai-connectors.js` delegated click handler to route the 4 new admin-action selectors: `.acrossai-mcp-connector-panel__revoke-btn`, `__delete-btn`, `__nuclear-btn`, `__approve-btn`.
- [x] T024-041 Add settings form submit handler via `DOMContentLoaded` listener — POST to `/oauth/connector-settings` with `{ server_id, connector_slug, enabled, require_admin_approval }`.
- [x] T024-042 Add `adminBase()` + `postAdmin()` helpers deriving the REST base URL from the localized `restEndpoint` (strips `/oauth/generate-client` suffix).
- [x] T024-043 Add `~130 lines` of SCSS to `src/scss/ai-connectors.scss` — Level 2/3 tab bar overrides, panel container, table styling, settings form spacing, pending list, nuclear button red styling.
- [x] T024-044 Rebuild bundle via `npm run build` — `build/js/ai-connectors.{js,css,asset.php}` regenerated.

## Phase 6: Companion plugin updates

- [x] T024-050 Add `matches_dcr_client` to `ClaudeConnectorProfile` — case-insensitive substring match on `'claude'` or `'anthropic'` in the DCR-submitted `client_name` + `redirect_uris`.
- [x] T024-051 Add `get_mcp_url_setup_html` to `ClaudeConnectorProfile` — 4-step branded HTML `<ol>` with inline `<code>` for the URL, targeting the Claude Add-connector browser flow.

## Phase 7: Runtime verification (post-lint)

- [x] T024-060 Verify all 5 new REST routes appear at `/wp-json/acrossai-mcp-manager/v1` via `curl`. All 5 present.
- [x] T024-061 Verify permission callback correctly rejects unauthenticated POST to `/oauth/connector-settings` with 403 + custom error code (proves callback executes, doesn't crash).
- [x] T024-062 Verify DB state — no orphaned rows, tables intact, MCPServer schema confirms `server_route_namespace` + `server_route` columns.
- [x] T024-063 Verify webpack build succeeds + bundle grew appropriately (JS 4KB → 7.7KB, CSS → 6KB).
- [x] T024-064 Verify F021 governance gates all pass (A1 constructor grep, Repository/`$wpdb` layering, column widths, raw-secret leaks).
- [x] T024-065 Verify PHPStan level 8 exit 0 across `includes/OAuth`, `includes/Connectors`, `admin/Partials/ServerTabs/AIConnectorsTab.php`.

## Bugs found during T024-060..T024-065 (runtime verification)

- [x] **BUG-024-001**: `$server['mcp_url']` used a non-existent column. Fixed by inlining the F011 CliController pattern in `render_generate_panel` + adding `mcp_url_for_server` helper on `AbstractConnectorProfile`. Both callers now derive the URL correctly.
- [x] **BUG-024-002**: `server_id_from_resource` regex `/server-(\d+)/` never matched real URL paths (the actual URL is `.../wp-json/mcp/mcp-adapter-default-server`, no numeric server-id). Replaced with `server_id_from_client_and_resource(ClientRow, string)` that parses `server-{id}` from admin client_ids AND walks MCPServer rows matching the resource URL for DCR clients.

## Deferred / Not-shipped-in-F024

None. All 8 spec success criteria (SC-024-001..008 covered inline) verifiable via the manual verification checklist in plan.md.

## Public API additions (permanent, marked `@experimental` until 1.0.0)

- `AbstractConnectorProfile::matches_dcr_client( string, array<int, string> ): bool`
- `AbstractConnectorProfile::get_mcp_url_setup_html( string ): string`
- `AbstractConnectorProfile::mcp_url_for_server( array ): string` (protected)
- `OAuthClients\Query::find_admin_clients_for_server_connector( int, string ): array<Row>`
- `OAuthClients\Query::find_dcr_clients(): array<Row>`
- `OAuthClients\Query::delete_by_id( int ): bool`
- CSS class contracts: `.acrossai-mcp-ai-connectors__level2`, `__level3`, `.acrossai-mcp-connector-panel__*` family
- `data-acrossai-*` attribute contracts (see spec.md FR-024-018..023)
- 5 REST endpoints (see spec.md FR-024-020..023)
