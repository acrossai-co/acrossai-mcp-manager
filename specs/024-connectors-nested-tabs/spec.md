# Feature 024 — AI Connectors Nested Tabs (Level 2 + Level 3)

**Created**: 2026-07-11
**Depends on**: F021 (OAuth 2.1) + F021 Phase 9 (Connector Card Shell)
**Consumer plugins**: `acrossai-claude-connectors` (Claude), future ChatGPT / Gemini / Copilot companions.

## Summary

Replace the single flat AI Connectors tab (one card per connector) with a nested-tab admin UI:

- **Level 1** (existing): server tabs — Overview | npm | MCP Clients | AI Connectors | ...
- **Level 2** (new): one sub-tab per registered connector — Claude | ChatGPT | Gemini | ...
- **Level 3** (new): three panels per connector — Generate | Connections | Settings

## URL contract

```
?page=acrossai_mcp_manager
&action=edit
&server=1
&tab=ai-connectors
&connector={slug}      ← Level 2 (default: first profile alphabetically)
&panel={generate|connections|settings}   ← Level 3 (default: generate)
```

Full page reload on tab change — matches F013/F019 Level 1 pattern.

## Requirements

### Level 2 sub-tab bar

- **FR-024-001**: When zero connector profiles are registered, the AI Connectors tab MUST render the F021 Phase 8 empty state (`render_empty_state`) — no sub-tab bar, no panels.
- **FR-024-002**: When one or more profiles are registered, the tab MUST render a WP `.nav-tab-wrapper` sub-tab row with one `.nav-tab` per profile. The active tab is the one matching `?connector=`, or the first profile alphabetically if none.
- **FR-024-003**: Sub-tab labels are `$profile->get_name()`; sub-tab URLs preserve every existing query arg and set `?connector={slug}&panel=generate`.

### Level 3 panel bar

- **FR-024-004**: Inside the selected connector, render a second `.nav-tab-wrapper` with three tabs: Generate | Connections | Settings.
- **FR-024-005**: The active panel is the one matching `?panel=`, or `generate` by default.

### Generate panel

- **FR-024-006**: Renders the MCP URL for this server with a Copy button.
- **FR-024-007**: Renders the connector-specific setup HTML from `$profile->get_mcp_url_setup_html( $mcp_url )` (new profile method with a generic default).
- **FR-024-008**: Includes a collapsible "Advanced: pre-generate credentials manually" section containing the F021 Phase 9 admin-generate flow (kept as a fallback for AI clients that don't support DCR).

### Connections panel

- **FR-024-009**: Renders a table of every OAuth client whose tokens' `resource` includes this server's MCP endpoint URL AND whose `connector_slug` equals this connector's slug OR whose DCR metadata matches this profile via `$profile->matches_dcr_client( $client_name, $redirect_uris )`.
- **FR-024-010**: Table columns: Client ID, Client name, Registered via (`DCR` / `Admin`), Active tokens count, Owner user(s), Issued at, Actions.
- **FR-024-011**: Per-row Actions: **Revoke tokens** (bulk-revoke all non-revoked tokens for this client, fires `token_revoked` per row with reason `'client_action'`) + **Delete client** (also removes the client row after confirmation).
- **FR-024-012**: Empty state: "No AI clients have connected via {connector name} yet."

### Settings panel

- **FR-024-013**: Two setting fields per `(server_id, slug)` pair, stored as `wp_option` keyed `acrossai_mcp_connector_settings_{server_id}_{slug}` = `array{ enabled: bool, require_admin_approval: bool }`.
- **FR-024-014**: **Enable this connector on this server** (checkbox, default `true`). When flipped from `true` to `false`, the plugin MUST bulk-revoke every non-revoked token whose client belongs to this connector on this server, and fire `acrossai_mcp_manager_oauth_token_revoked` per row with reason `'connector_disabled'`.
- **FR-024-015**: **Require admin approval for new connections** (checkbox, default `false`). When enabled, `AuthorizationController::handle_get` MUST check `acrossai_mcp_connector_approved_users_{server_id}_{slug}` (a `wp_option` list of user IDs) BEFORE rendering the consent screen. Unlisted users MUST see a "pending admin approval" template + get added to `acrossai_mcp_connector_pending_approvals_{server_id}_{slug}`. Admins approve via a list rendered inline in the Settings panel.
- **FR-024-016**: **Revoke all connections for this connector** (button, with `window.confirm`). Same effect as flipping Enable from true to false, but without changing the enabled state.
- **FR-024-017**: When `TokenValidator::authenticate` runs, if the token's client belongs to a connector whose `enabled` setting is `false`, the callback MUST return `$user_id` unchanged (defensive layer — the mass-revoke on disable already flips `revoked=1`, but this catches races).

### Public API additions

- **FR-024-018**: `AbstractConnectorProfile::matches_dcr_client( string $client_name, array<int, string> $redirect_uris ): bool` — new concrete method, default returns `false`. Companion plugins override to claim DCR-registered clients whose metadata matches their brand.
- **FR-024-019**: `AbstractConnectorProfile::get_mcp_url_setup_html( string $mcp_url ): string` — new concrete method, default returns a generic "paste this URL into your AI client's connector settings" HTML. Companion plugins override for connector-specific instructions. Output MUST be passed through `wp_kses_post` before rendering.

### REST endpoints

- **FR-024-020**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/connector-settings` — save settings for `(server_id, slug)`. Admin only (`manage_options` + nonce).
- **FR-024-021**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/revoke-client-tokens` — revoke every non-revoked token for a `client_id`. Admin only. Fires `token_revoked` per row with reason `'admin_revoke'`.
- **FR-024-022**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/delete-client` — revoke tokens then delete the client row. Admin only.
- **FR-024-023**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/approve-pending-consent` — admin approves a specific user's pending consent for a `(server, connector)` pair. Removes from pending, adds to approved.

## Success criteria

- **SC-024-001**: With multiple connector profiles registered (Claude + a stub), the AI Connectors tab renders a Level 2 sub-tab row with both, and switching between them updates the URL + rendered content.
- **SC-024-002**: The Generate panel shows the MCP URL with Copy; Copy button uses the same clipboard behavior as Phase 9.
- **SC-024-003**: The Connections panel lists at least one client after an admin has run Generate on the Advanced fallback, or after a DCR-capable AI client has completed registration.
- **SC-024-004**: Flipping Enable from true to false immediately revokes every active token for that connector on that server (verified by `SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens WHERE ... AND revoked=0` returning 0).
- **SC-024-005**: Toggling Require admin approval on and then attempting `/authorize` as a non-approved user renders the "pending admin approval" template, NOT the consent screen. Admin can then approve, and the user can complete the flow on next attempt.
