# Implementation Plan: F024 — AI Connectors Nested Tabs

**Branch**: `main` (landed inline with F021 Phase 9 follow-up)
**Date**: 2026-07-11
**Spec**: [spec.md](spec.md)
**Depends on**: F021 (OAuth 2.1) + F021 Phase 9 (Connector Card Shell)
**Companion plugins updated**: `acrossai-claude-connectors`

## Summary

Replace the single-list AI Connectors admin tab with a **nested tab structure**:

- **Level 1** (existing) — server tabs
- **Level 2** (new) — one sub-tab per registered connector profile
- **Level 3** (new) — three panels per connector: Generate | Connections | Settings

Migration is backwards-compatible: companion plugins that ship a `render_tab_section` override still work — their override is invoked inside the "Advanced: pre-generate credentials manually" collapsible on the Generate panel.

## Constitution Check

Six principles pass. No hard violations.

- **Principle I — Modular Architecture**: `ConnectorSettings`, `ConnectorAdminController`, and the new `AbstractConnectorProfile` protected methods are self-contained. Controllers → Repositories → BerlinDB Queries layering preserved. No `$wpdb` in controller layer (governance gate T118c still passes).
- **Principle II — WordPress Standards**: PHPCS + PHPStan level 8 clean. Text domain `acrossai-mcp-manager` on every `__()`. Uses standard `.nav-tab-wrapper` markup for Level 2 + Level 3 tab bars.
- **Principle III — Security First**: All 5 new REST endpoints gate on `manage_options` + `X-WP-Nonce`. `AuthorizationController::handle_get` gains connector-disabled + admin-approval gates BEFORE `render_consent`. Pending-approval page uses `nocache_headers()`. Every dynamic value escaped at output.
- **Principle V — Extensibility**: 2 new `@experimental` methods on `AbstractConnectorProfile` (`matches_dcr_client`, `get_mcp_url_setup_html`). CSS class names + `data-*` attributes documented as public API. Companion plugins can override at any granularity.
- **Principle VI — Reusability**: `mcp_url_for_server()` protected helper deduplicates the URL construction across Generate panel + shared shell.
- **Principle VII — Definition of Done**: PHPCS + PHPStan + F021 governance gates all pass. Manual verification checklist below.

## Complexity Tracking

Two deviations from the "cards" pattern of F021 Phase 9, both justified:

| Deviation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Nested `<nav class="nav-tab-wrapper">` for Level 2 + Level 3 | Reuse WP core's `.nav-tab-wrapper` styling and semantics — familiar admin idiom. | JS-only tab switching would require in-page state management and break bookmarkability. Full page reloads on switch match F013/F019's Level 1 pattern for consistency. |
| Options-backed settings (`wp_options` rows) instead of BerlinDB table | Settings are per-`(server, connector)` and small (< 5 rows per pair). Adding a BerlinDB table for a few boolean flags is overkill. | A new BerlinDB module would add ~4 files + schema migration risk for no additional query power. Options are fine at this scale. |

## Project Structure

**New files** (base plugin):

```
includes/Connectors/ConnectorSettings.php       ← settings CRUD + approved/pending lists
includes/OAuth/ConnectorAdminController.php     ← 5 admin REST endpoints
```

**Modified files** (base plugin):

```
includes/Connectors/AbstractConnectorProfile.php  ← +2 concrete methods + mcp_url_for_server helper
includes/Database/OAuthClients/Query.php          ← +find_admin_clients_for_server_connector, find_dcr_clients, delete_by_id
includes/OAuth/AuthorizationController.php        ← +connector-disabled gate + admin-approval gate
includes/Main.php                                 ← wire ConnectorAdminController on rest_api_init
admin/Partials/ServerTabs/AIConnectorsTab.php     ← full rewrite of render_body → nested router
src/scss/ai-connectors.scss                       ← +130 lines for panels + tables + forms
src/js/ai-connectors.js                           ← +150 lines for admin action handlers
build/js/ai-connectors.{js,css,asset.php}         ← rebuilt via npm run build
```

**Modified files** (companion plugin `acrossai-claude-connectors`):

```
includes/ClaudeConnectorProfile.php               ← +matches_dcr_client + get_mcp_url_setup_html
```

## Data Model

**No new tables.** Everything via `wp_options`:

| Option key pattern | Value shape | Purpose |
|---|---|---|
| `acrossai_mcp_connector_settings_{server_id}_{slug}` | `array{enabled: bool, require_admin_approval: bool}` | The two toggles from the Settings panel. Defaults on cache miss. |
| `acrossai_mcp_connector_approved_users_{server_id}_{slug}` | `array<int, int>` (list of user_ids) | Users pre-approved to complete OAuth for this connector. |
| `acrossai_mcp_connector_pending_approvals_{server_id}_{slug}` | `array<int, int>` (list of user_ids) | Users waiting for an admin's approve click. |

All three are `autoload=false` to keep the options cache lean.

## Public API additions

Marked `@experimental May change without notice before 1.0.0` per the F021 Phase 9 convention:

- `AbstractConnectorProfile::matches_dcr_client( string $client_name, array $redirect_uris ): bool`
- `AbstractConnectorProfile::get_mcp_url_setup_html( string $mcp_url ): string`
- `AbstractConnectorProfile::mcp_url_for_server( array $server ): string` (protected)
- CSS classes: `.acrossai-mcp-ai-connectors__level2`, `__level3`, `.acrossai-mcp-connector-panel__revoke-btn`, `__delete-btn`, `__nuclear-btn`, `__approve-btn`, `__settings-form`
- Data attributes: `data-acrossai-client-id`, `data-acrossai-user-id`, `data-acrossai-connector-slug`, `data-acrossai-confirm`
- REST endpoints (5 new POST routes under `acrossai-mcp-manager/v1/oauth/`)

## Runtime verification (post-implement)

Verified against live install on 2026-07-11:

- ✅ REST discovery lists all 5 new routes at `/wp-json/acrossai-mcp-manager/v1`
- ✅ Unauthenticated POST to `/oauth/connector-settings` returns 403 with custom error code (permission callback runs correctly)
- ✅ MCP URL construction now uses `server_route_namespace + server_route` (matching F011 CliController pattern) — verified against actual default server row (`mcp` + `mcp-adapter-default-server`)
- ✅ `server_id_from_client_and_resource` handles both admin-generated clients (parse `server-{id}` prefix) AND DCR-registered clients (walk MCPServer rows matching resource URL)

## Two bugs found during runtime verification

1. **`$server['mcp_url']` used a non-existent column.** Fixed by inlining the F011 CliController pattern (`rest_url( trailingslashit( $namespace ) . $route )`) and abstracting into `mcp_url_for_server` helper.
2. **`server_id_from_resource` regex `/server-(\d+)/` never matched real URLs.** Fixed by replacing with `server_id_from_client_and_resource(ClientRow, string)` that parses from client_id for admin clients + walks MCPServer rows for DCR clients.

Both discovered by consulting the live DB after linting passed, before shipping.

## Manual verification checklist

1. **Empty registry state**: Deactivate all connector companion plugins. Load AI Connectors tab. See F021 Phase 8 empty state, no sub-tab bar.
2. **Single-profile state**: Reactivate `acrossai-claude-connectors`. Load AI Connectors tab. See Level 2 bar with only `[Claude]`, Level 3 bar with `[Generate] [Connections] [Settings]`.
3. **URL routing**: Click Connections → URL changes to `?...&connector=claude&panel=connections`; Connections panel renders.
4. **Generate panel**: MCP URL shown correctly as `https://<site>/wp-json/mcp/mcp-adapter-default-server`. Copy button flashes "Copied!". Claude's branded 4-step ordered list appears in the accent callout. "Advanced: pre-generate credentials manually" collapsible opens and reveals the F021 Phase 9 admin-generate flow.
5. **Connections panel**: Empty state visible: "No AI clients have connected via Claude yet."
6. **Settings panel**: Both checkboxes render checked/unchecked from defaults (`enabled=true`, `require_admin_approval=false`). Save Settings persists to `wp_options` (check DB); reload shows new state.
7. **Mass-revoke on disable**: Seed one admin-generated client + one active token. Uncheck "Enable this connector on this server" → Save. Reload Connections panel → the token is now revoked (0 active). Fire history includes `token_revoked` with reason `'connector_disabled'`.
8. **Admin approval gate**: Toggle `require_admin_approval` ON. As a non-approved user, hit `/authorize?client_id=...`. See "Waiting for admin approval" page. Log in as admin → Settings panel shows pending user in the list → click Approve. Retry `/authorize` as the previously-pending user → consent screen now renders normally.

## Next Command

`/speckit-tasks` (produces `tasks.md`). Since this feature landed inline with F021 Phase 9 iteration, the tasks.md in this directory documents what shipped rather than driving a fresh implementation.
