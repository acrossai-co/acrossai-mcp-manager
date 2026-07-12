# Feature Specification: OAuth 2.1 + PKCE Authorization Server

**Feature Branch**: `021-oauth-2-1-implementation`
**Created**: 2026-07-10
**Status**: Draft
**Input**: User description: See `docs/planings-tasks/021-oauth-2-1-implementation.md`

## Clarifications

### Session 2026-07-10

- Q: Does one issued OAuth access token grant access to a single MCP server or to all MCP servers on this WordPress site? → A: One token = one MCP server. The token's `resource` column (RFC 8707 audience) is authoritative and enforced at call time: `TokenValidator` MUST reject invocations whose target MCP endpoint URL does not match the token's stored `resource`. Cross-server invocation with a token issued for a different server returns 401.
- Q: What format do admin-generated (via `AIConnectorsTab`) client_ids use, versus DCR-issued client_ids? → A: **Admin clients** use the structured format `server-{server_id}-{connector_slug}-{random8}` (where `random8` = `bin2hex(random_bytes(4))`), doubling as an audit tag. **DCR clients** use the opaque random format `bin2hex(random_bytes(16))` (32 hex chars). The two formats never collide because `server-` is a reserved prefix. The `AIConnectorsTab` lookup uses this prefix directly (`client_id LIKE 'server-{id}-{connector_slug}-%'`) via the KEY(connector_slug) index.
- Q: When an AI client re-authorizes at `/authorize` for a (user, client) pair the operator has previously approved, is the consent screen shown again or skipped? → A: **Always show consent.** Every `/authorize` request renders the consent screen — no memoization of prior approvals, no `approved_at` timestamp column, no `OAuthConsents` companion table. Rationale: MCP connectors have persistent access to sensitive tool surfaces; explicit operator intent per authorization is a security feature. Re-authorization typically only fires on cache clear, re-install, or scope change — the friction cost is acceptable.
- Q: When a WordPress user account is deleted, what happens to their outstanding OAuth tokens and pending auth codes? → A: **Auto-revoke on `deleted_user` action.** The plugin MUST hook WordPress's `deleted_user` action at priority 10 and bulk-revoke every token (`UPDATE ... SET revoked=1 WHERE user_id=%d AND revoked=0`) plus bulk-delete every pending auth code (`DELETE WHERE user_id=%d`) for that user. The plugin MUST fire `acrossai_mcp_manager_oauth_token_revoked` per revoked token with reason `'user_deleted'`. Rationale: prevents ghost-user authentication and maintains audit-trail consistency (revoked with reason, not silently orphaned).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Site administrator generates connector credentials for a specific AI client (Priority: P1)

A site administrator has just installed a companion plugin that adds a connector profile for Claude Desktop. They want to hook Claude Desktop up to the MCP tools they curated for their default MCP server (via Feature 020). They navigate to the "Edit MCP Server" screen, open the new **AI Connectors** tab, and see a card for each installed connector profile — Claude, ChatGPT, Gemini, GitHub Copilot, etc. Each card shows the connector's icon, name, and a "Generate credentials" button. They click Generate for Claude, and the plugin creates a fresh OAuth client bound to that server + connector, hashes the secret at rest, and shows the raw client_id + client_secret **once** with copy-to-clipboard buttons and step-by-step setup instructions rendered by the connector profile. They paste the credentials into Claude Desktop's connector configuration; Claude launches an OAuth flow against this WordPress site, the admin sees a consent screen branded by the connector profile, approves, and Claude receives an access token. The AI can now invoke every ability the operator added as a tool for that server.

**Why this priority**: This is the entire value proposition. Without this journey no AI connector can be provisioned. Every P2 and P3 story extends or complements this one.

**Independent Test**: Install a stub connector profile via mu-plugin that registers a single `AbstractConnectorProfile` on the `acrossai_mcp_manager_connector_profiles` filter. Navigate to `?page=acrossai_mcp_manager&action=edit&tab=ai-connectors`. Verify the connector card renders. Click Generate. Verify a row appears in `wp_acrossai_mcp_oauth_clients` with `connector_slug` set + `client_secret_hash` populated (raw secret returned in response body once, never again). Verify the tab now shows the setup instructions instead of the button.

**Acceptance Scenarios**:

1. **Given** the operator has a running MCP server and at least one connector profile registered via the filter, **When** they open the AI Connectors tab, **Then** they see one card per profile with an "Generate credentials" button for each connector that doesn't yet have a client.
2. **Given** the operator clicks Generate for a connector, **When** the REST endpoint responds 200, **Then** the raw `client_id` and `client_secret` are displayed with copy buttons and the profile's setup instructions render inline — subsequent GETs to the tab show a "Regenerate" button but never the raw secret again.
3. **Given** the operator later clicks Regenerate, **When** they confirm the destructive action, **Then** the old client is revoked (all outstanding tokens revoked), a new client is issued, and the raw secret is displayed once more.
4. **Given** zero connector profiles are registered via the filter, **When** the operator opens the tab, **Then** an empty-state notice appears explaining that connector profiles are contributed by companion plugins with a link to the docs.

---

### User Story 2 - AI application auto-registers via Dynamic Client Registration (Priority: P2)

A developer building an MCP client wants their application to onboard against any WordPress site without asking users for admin credentials. Their client discovers this site's OAuth metadata via `/.well-known/oauth-authorization-server`, sees the `registration_endpoint` URL, and POSTs its client metadata (redirect URIs, grant types, token endpoint auth method) as JSON. This site responds with an issued `client_id` + `client_secret` (if the client requested confidential auth). If the same client re-registers with byte-identical metadata later (same LLM re-onboarding after a browser cache clear), the plugin returns the same credentials rather than issuing a new pair.

**Why this priority**: DCR unlocks self-service integration for the long tail of MCP clients where the admin isn't in the loop. Without it, every new integration requires an admin action.

**Independent Test**: `curl -X POST -H "Content-Type: application/json" -d '{"redirect_uris":["https://client.example.com/callback"],"grant_types":["authorization_code","refresh_token"],"token_endpoint_auth_method":"client_secret_post"}' https://site.example.com/wp-json/acrossai-mcp-manager/v1/oauth/register`. Verify 201 response with `client_id`, `client_secret`, `client_id_issued_at`. Repeat the same POST — verify identical `client_id` returned + no new row inserted (fingerprint dedup).

**Acceptance Scenarios**:

1. **Given** a well-formed DCR POST body, **When** the endpoint receives it, **Then** a new client is issued + returned as JSON per RFC 7591 shape.
2. **Given** an identical DCR POST body arrives a second time (same fingerprint), **When** the endpoint receives it, **Then** the previously-issued client's metadata is returned without a new secret issuance and no `token_issued` action fires.
3. **Given** a DCR POST with an invalid redirect_uri (HTTP for non-loopback), **When** the endpoint receives it, **Then** a 400 response with `error=invalid_redirect_uri` is returned, no row is inserted, and the request is counted against the IP's rate-limit budget.
4. **Given** the same IP has issued 10 DCR requests in the last 60 seconds, **When** the 11th arrives, **Then** a 429 response with `error=slow_down` and a `Retry-After` header is returned; no row is inserted.

---

### User Story 3 - AI client makes an MCP tool call authenticated by an OAuth access token (Priority: P1)

An AI client that has completed the OAuth handshake holds an access token. When it invokes an MCP tool over HTTP, it sets `Authorization: Bearer <token>` on the request. This site's TokenValidator hooks WordPress's `determine_current_user` filter at priority 20, extracts the token, hashes it, looks it up in the tokens table, and — on a hit for a valid, non-expired, non-revoked row — reports the WordPress user_id back to WordPress. The rest of the request pipeline (mcp-adapter's HTTP transport, F015 access control, F017 ability exposure, F020 tool curation) sees a fully authenticated user and evaluates against `current_user_can()` as if the user were logged in via cookies.

**Why this priority**: This is the runtime path that makes every user journey above pay off. Without it, tokens are cosmetic and connectors don't actually work.

**Independent Test**: Issue a token via the /token endpoint. Call an MCP tool over HTTP with `Authorization: Bearer <raw>` set. Verify the tool invocation succeeds and the current-user context inside the tool handler equals the user the token was issued for. Then revoke the token via `TokensQuery::revoke_by_hash`. Repeat the tool call — verify 401 from mcp-adapter (unauthenticated). Repeat after expiry — same 401.

**Acceptance Scenarios**:

1. **Given** an AI client holds a valid access token, **When** it invokes an MCP tool with `Authorization: Bearer <token>`, **Then** the tool executes under the user_id the token was issued for, and any F017/F020 access rules for that user's capabilities apply.
2. **Given** the token has been revoked (either by refresh rotation or explicit admin revocation), **When** the client retries, **Then** the token lookup returns null, `determine_current_user` returns the original value, and the request runs as an anonymous user (mcp-adapter's HTTP transport rejects at `current_user_can`).
3. **Given** the token has expired, **When** the client retries, **Then** the token lookup returns the row but the validator rejects it based on `expires_at`, and anonymous-user semantics apply.
4. **Given** the `Authorization` header is missing or malformed, **When** the filter runs, **Then** it returns the original `$user_id` value unchanged (pass-through).

---

### User Story 4 - Site admin approves or denies a consent screen for an incoming OAuth flow (Priority: P2)

An AI client redirects the site admin's browser to `/authorize`. The plugin looks up the client, validates PKCE parameters, validates the resource parameter, and ensures the user is logged in. If not logged in, redirects to `wp-login.php` with `?redirect_to=<authorize-url>`. Once logged in, renders a self-contained consent page (outside the WP admin frame) showing the connector's icon, name, requested scopes, and Approve/Deny buttons. The admin clicks Approve → a fresh authorization code is generated, stored as SHA-256 hash + PKCE challenge + resource + user_id, and the browser redirects to the client's `redirect_uri` with `code=<raw>&state=<state>&iss=<issuer>`.

**Why this priority**: This is the authorization step every browser-based connector must go through. It's part of every P1 setup. Priority 2 only because it can't be tested independently without P1 credentials existing.

**Independent Test**: Manually POST to `/authorize` with valid PKCE + resource + a client_id that exists. Verify (a) the consent page renders with the correct branding, (b) clicking Approve redirects to the registered redirect_uri with a `code` parameter, (c) clicking Deny redirects with `error=access_denied`, (d) an approve without wp_verify_nonce returns 403.

**Acceptance Scenarios**:

1. **Given** the operator is logged in and clicks Approve on the consent screen, **When** the form submits, **Then** an auth code row is inserted with SHA-256 hash + PKCE challenge + resource + user_id + client_id + redirect_uri, and the browser redirects to `redirect_uri` with `code=<raw>&state=<state>&iss=<issuer>`.
2. **Given** the operator is logged in and clicks Deny, **When** the form submits, **Then** no auth code is issued, the browser redirects to `redirect_uri` with `error=access_denied&state=<state>&iss=<issuer>`, and the `acrossai_mcp_manager_oauth_authorization_denied` action fires.
3. **Given** the operator is not logged in when they hit `/authorize`, **When** the request is processed, **Then** they are redirected to `wp-login.php?redirect_to=<current_authorize_url>` and, on successful login, land back on the consent screen.
4. **Given** a POST arrives without a valid WordPress nonce, **When** the endpoint receives it, **Then** a 403 response is returned and no auth code is issued.

---

### User Story 5 - Operator opts into destructive uninstall and revokes tokens (Priority: P3)

An operator decommissioning the plugin ticks the "Delete all data on uninstall" checkbox on the MCP settings tab and then deletes the plugin. The uninstall.php gate runs, drops the three new OAuth tables (clients + tokens + auth codes) along with the pre-existing plugin tables, and clears the daily cleanup cron. Every issued token becomes unusable because the underlying rows are gone; every issued client_id is dead.

**Why this priority**: Cleanup path for compliance / decommissioning. Not the happy path, but must work reliably when invoked.

**Independent Test**: Set `acrossai_mcp_uninstall_delete_data = 1`. Uninstall the plugin. Verify `SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'` returns 0 rows and `wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' )` returns false. Reset the flag to 0. Reinstall + uninstall again → verify tables + cron persist across an uninstall cycle.

**Acceptance Scenarios**:

1. **Given** the opt-in flag is set to 1, **When** the plugin is uninstalled, **Then** the three OAuth tables are dropped, the three `_db_version` options are deleted, and the daily cron is cleared.
2. **Given** the opt-in flag is 0 (default), **When** the plugin is uninstalled, **Then** tables, options, and cron entries persist — reinstalling brings all prior data back.

---

### Edge Cases

- **PKCE plain method rejected**: `code_challenge_method != 'S256'` at `/authorize` → redirect with `error=invalid_request&error_description=PKCE+S256+required`. The `code_challenge_methods_supported` metadata claim advertises `['S256']` only, so compliant clients never even try `plain`.
- **Redirect URI mismatch**: If the `redirect_uri` on `/authorize` doesn't byte-match one of the client's registered URIs (or the connector profile's whitelist for admin-generated clients), the request is rejected with `error=invalid_request` and the browser stays on the plugin's error page (never redirects to a URI it can't trust).
- **Expired auth code**: `/token` looks up the code, sees `expires_at < now`, returns `invalid_grant` and NEVER issues a token.
- **Replayed auth code**: `AuthCodesQuery::consume_atomic` uses `UPDATE ... WHERE used=0 AND expires_at > %s`. Only the FIRST successful call to `/token` gets the code; every subsequent replay sees `rows_affected == 0` and returns `invalid_grant`.
- **Missing `resource` on `/authorize`**: Redirect with `error=invalid_target`. The MCP spec mandates `resource`; there is no fallback.
- **`resource` not on this site**: Same treatment — `invalid_target`. Prevents confused-deputy attacks where a client tries to bind a token to a resource on a different site.
- **Refresh token reuse detection**: When a refresh token is presented, it's revoked and a fresh pair issued. If the SAME refresh token is presented again (would only happen if a rogue actor intercepted it), the second call returns `invalid_grant` because the row is already `revoked=1`. **SEC-021-001 defense**: The plugin MUST additionally revoke every OTHER non-revoked token sharing the same `token_family_id` (see FR-043). This caps a stolen-refresh-token attacker's window at the access-token TTL (3600s) rather than the refresh-token TTL (30 days). Per RFC 9700 (OAuth 2.1 Security BCP §2.2.2).
- **Rate limit hit on `/register`**: 429 response with RFC-6749-shaped `error=slow_down` JSON + `Retry-After: 60` header. Request is counted against the same window whether the payload was valid or not.
- **DCR dedupe**: Byte-identical repeat POST returns the existing client without firing `token_issued`. Prevents client-fingerprint drift in observability logs when connectors flap.
- **Rewrite rules not flushed after activation**: The plugin's Activator calls `flush_rewrite_rules()` (already done for FrontendAuth in prior features); the OAuth endpoints piggy-back on that flush.
- **Bearer header behind a reverse proxy**: `TokenValidator::read_authorization_header()` tries `$_SERVER['HTTP_AUTHORIZATION']`, then `REDIRECT_HTTP_AUTHORIZATION` (for `mod_rewrite` setups), then `apache_request_headers()`, then `getallheaders()` — resolves the header no matter which SAPI or proxy layer normalized it.
- **Recursion inside `determine_current_user`**: A static `$resolving` guard prevents the token lookup from re-triggering the filter if a downstream call ever fires `current_user_can()` mid-lookup.
- **Cleanup cron misses a run**: The daily job deletes rows with `expires_at < NOW()` OR `revoked=1`. A skipped day just means the next run picks up two days of expiration in one pass — no ill effect.
- **WordPress user deleted while holding an active OAuth token**: The `deleted_user` cascade hook (FR-042) fires; all outstanding tokens for that `user_id` are revoked with reason `user_deleted`, all pending auth codes for that user are deleted, and every subsequent MCP request presenting a revoked token authenticates as anonymous. No ghost-user requests reach the mcp-adapter.

---

## Requirements *(mandatory)*

### Functional Requirements

**Discovery & Metadata**

- **FR-001**: The plugin MUST expose `GET /.well-known/oauth-authorization-server` returning RFC 8414 authorization-server metadata as JSON with correct MIME type + 1-hour cache header. The endpoint MUST advertise: `issuer` (bare `home_url()`), `authorization_endpoint`, `token_endpoint`, `registration_endpoint`, `grant_types_supported = ['authorization_code','refresh_token']`, `response_types_supported = ['code']`, `token_endpoint_auth_methods_supported = ['none','client_secret_post']`, `code_challenge_methods_supported = ['S256']`, `scopes_supported = ['mcp']`, `authorization_response_iss_parameter_supported = true`, `service_documentation`.
- **FR-002**: The plugin MUST expose `GET /.well-known/oauth-protected-resource` returning RFC 9728 protected-resource metadata as JSON. Fields: `resource` (echoing the `?resource=` query param if present, else the default MCP endpoint URL on this site), `authorization_servers = [ home_url() ]`, `bearer_methods_supported = ['header']`, `scopes_supported = ['mcp']`.
- **FR-003**: Both discovery endpoints MUST send `Access-Control-Allow-Origin: *` and `Cache-Control: public, max-age=3600` headers.

**Authorization Flow**

- **FR-004**: The plugin MUST expose `GET/POST /authorize` handling the OAuth 2.1 authorization-code flow with PKCE. GET renders a consent screen; POST processes the operator's approve/deny action.
- **FR-005**: The `/authorize` endpoint MUST require and enforce PKCE with `code_challenge_method=S256`. Any request with a different method or missing challenge MUST be rejected with `error=invalid_request` and `error_description=PKCE S256 required`.
- **FR-006**: The `/authorize` endpoint MUST require the RFC 8707 `resource` parameter and MUST verify it is a valid HTTPS URL (or loopback) that lives on this WordPress site. Missing or mismatched resource MUST redirect with `error=invalid_target`.
- **FR-007**: The `/authorize` endpoint MUST validate the `redirect_uri` against either the client's registered `redirect_uris` list (exact byte match) OR — for admin-generated clients — the active connector profile's `get_redirect_uri_whitelist()`. Mismatched redirects MUST show an error page on the plugin (never redirect to an untrusted URI).
- **FR-008**: When the user is not logged in on `/authorize`, the plugin MUST redirect to `wp_login_url()` with `redirect_to` set to the current authorize URL, so the user lands back on the consent screen after login.
- **FR-009**: The consent screen MUST render outside the WP admin frame (no admin bar, no theme header) via a self-contained template. It MUST include the connector profile's icon, heading, subtitle, permissions bullet list, the logged-in user's display name + site name, a `wp_nonce_field`, hidden inputs echoing all authorize parameters, and Approve + Deny buttons. The consent screen MUST render on **every** `/authorize` request (per §Clarifications Q3) — the plugin MUST NOT memoize prior approvals; there is no `approved_at` column, no `OAuthConsents` table, and no "trust this connector for N days" affordance.
- **FR-010**: The `/authorize` POST endpoint MUST verify the nonce with `wp_verify_nonce`; failure MUST return HTTP 403 and NOT issue an auth code.
- **FR-011**: On Approve, the plugin MUST generate a raw auth code, persist its SHA-256 hash with `code_challenge`, `code_challenge_method`, `resource`, `user_id`, `client_id`, `redirect_uri`, `scope`, and `expires_at = now + 600s`, then redirect the browser to `redirect_uri` with `code=<raw>&state=<state>&iss=<issuer>`.
- **FR-012**: On Deny, the plugin MUST NOT issue an auth code, MUST redirect to `redirect_uri` with `error=access_denied&state=<state>&iss=<issuer>`, and MUST fire `do_action( 'acrossai_mcp_manager_oauth_authorization_denied', $client_id, $redirect_uri, 'user_denied' )`.

**Token Endpoint**

- **FR-013**: The plugin MUST expose `POST /token` handling `grant_type=authorization_code` and `grant_type=refresh_token`. Content-Type MAY be `application/x-www-form-urlencoded` or `application/json`.
- **FR-014**: For `authorization_code` grants, the plugin MUST atomically consume the auth code (single-use — `UPDATE ... WHERE used=0 AND expires_at > NOW()`). Second call with the same code MUST return `invalid_grant`.
- **FR-015**: For `authorization_code` grants, the plugin MUST verify: (a) `client_id` matches the code's client, (b) `redirect_uri` byte-matches, (c) for `client_secret_post` clients, `client_secret` verifies via `hash_equals`, (d) `code_verifier` produces the code's `code_challenge` via S256.
- **FR-016**: On successful `authorization_code` exchange, the plugin MUST issue an access token (TTL 3600s) + refresh token (TTL 2592000s / 30 days), both bound to the auth code's `resource` and `scope`, and return them as JSON per RFC 6749 shape with `Cache-Control: no-store` + `Pragma: no-cache`.
- **FR-017**: For `refresh_token` grants, the plugin MUST look up the refresh token by SHA-256 hash, verify client credentials, revoke the presented refresh token (single-use rotation), and issue a fresh access + refresh pair carrying forward the original `resource` and `scope`.
- **FR-018**: Every `/token` response body MUST use RFC 6749 error codes (`invalid_grant`, `invalid_client`, `invalid_request`, `unauthorized_client`, `unsupported_grant_type`, `invalid_scope`).
- **FR-019**: The plugin MUST fire `do_action( 'acrossai_mcp_manager_oauth_token_issued', $token_id, $client_id, $user_id, $connector_slug )` once per issued access token and `do_action( 'acrossai_mcp_manager_oauth_token_revoked', $token_id, $reason )` once per revoked token.

**Dynamic Client Registration**

- **FR-020**: The plugin MUST expose `POST /wp-json/acrossai-mcp-manager/v1/oauth/register` implementing RFC 7591 Dynamic Client Registration. Content-Type MUST be `application/json`; any other body content type MUST return 400 with `error=invalid_request`.
- **FR-021**: The DCR endpoint MUST validate every submitted `redirect_uri` — HTTPS is required for non-loopback URIs; loopback (`127.0.0.1`, `localhost`, `::1`) on any port is accepted with any scheme. Invalid URIs MUST reject the whole request with 400 + `error=invalid_redirect_uri`.
- **FR-022**: The DCR endpoint MUST compute a canonical fingerprint of the submitted metadata (redirect_uris + grant_types + response_types + token_endpoint_auth_method + optional connector_slug) and check for a pre-existing client with that fingerprint. When found, the pre-existing client's metadata MUST be returned without a new secret issuance and NO observability action fires.
- **FR-023**: On a fresh registration, the DCR endpoint MUST generate a random opaque `client_id = bin2hex( random_bytes(16) )` (32 hex chars) and — if `token_endpoint_auth_method != 'none'` — a random `client_secret` (64 hex chars), hash the secret to `client_secret_hash`, insert the row, and return the raw `client_id` + `client_secret` in the response body ONCE with `client_id_issued_at` and `client_secret_expires_at=0` (never expires). The `server-` prefix is reserved for admin-generated client IDs (per §Clarifications Q2 + FR-035) and MUST NOT appear on DCR-issued client IDs; if a collision were ever possible (it is not, given entropy), DCR MUST retry generation.

**Bearer Token Authentication**

- **FR-024**: The plugin MUST hook WordPress's `determine_current_user` filter at priority 20. The callback MUST extract a Bearer token from the request's `Authorization` header (trying `$_SERVER['HTTP_AUTHORIZATION']`, then `REDIRECT_HTTP_AUTHORIZATION`, then `apache_request_headers()`, then `getallheaders()`), hash it, look it up in `wp_acrossai_mcp_oauth_tokens`, and — on a valid, non-expired, non-revoked hit whose `resource` column matches the current request's target MCP endpoint URL (RFC 8707 audience-binding, per §Clarifications Q1) — return the row's `user_id` to WordPress. Audience mismatch MUST return the original `$user_id` unchanged, causing the request to run as anonymous (mcp-adapter's HTTP transport then rejects at `current_user_can`).
- **FR-025**: The `determine_current_user` callback MUST include a static recursion guard so a downstream call to `current_user_can` mid-lookup cannot re-enter the filter.
- **FR-026**: On any failure path (missing header, malformed header, unknown token, expired token, revoked token), the callback MUST return the ORIGINAL `$user_id` value unchanged — never inject a partial or false authentication.

**Rate Limiting**

- **FR-027**: The plugin MUST rate-limit `/register` at 10 requests per IP per 60-second window using WordPress transients. Excess requests MUST return HTTP 429 with RFC-6749-shaped `error=slow_down` JSON body and `Retry-After: 60` header.
- **FR-028**: The plugin MUST rate-limit `/authorize` and `/token` at 60 requests per IP per 60-second window with the same 429 response shape.

**Connector Profile Framework**

- **FR-029**: The plugin MUST expose a public filter `acrossai_mcp_manager_connector_profiles` receiving and returning an array of `AbstractConnectorProfile` instances. Companion plugins register their profiles by adding a callback to this filter.
- **FR-030**: The `ConnectorProfileRegistry::get_profiles()` method MUST memoize the filter output per-request (fires the filter exactly once regardless of caller count) and return a slug-sorted, de-duplicated array. Duplicate slugs from multiple callbacks MUST resolve via later-wins with a `_doing_it_wrong` notice under `WP_DEBUG`.
- **FR-031**: `AbstractConnectorProfile` MUST expose the following abstract methods that connector plugins implement: `get_slug()`, `get_name()`, `get_icon_url()`, `get_redirect_uri_whitelist()`, `get_setup_instructions( $server, $client_id, $client_secret )`, `render_tab_section( $server )`. It MUST expose the following non-abstract methods that connector plugins MAY override: `get_consent_branding()` (returns a default neutral heading + subtitle if not overridden).

**Admin UI (built-in tab)**

- **FR-032**: The plugin MUST add a built-in per-server tab `AIConnectorsTab` at priority slot 35 (between Clients @ 30 and WP-CLI @ 40) directly to `Registry::all_tabs()` — NOT via the `acrossai_mcp_manager_server_tabs` filter, because a built-in tab is not a third-party contribution.
- **FR-033**: The tab MUST render one card per registered connector profile. The existence lookup uses `SELECT ... WHERE client_id LIKE 'server-{server_id}-{connector_slug}-%'` (per §Clarifications Q2 admin `client_id` format), indexed via `KEY(connector_slug)`. Cards for profiles without an existing OAuth client MUST show a "Generate credentials" button; cards WITH an existing client MUST call `$profile->render_tab_section( $server_row )` to render the profile's setup instructions plus a "Regenerate" button.
- **FR-034**: When zero connector profiles are registered, the tab MUST show an empty-state notice explaining that connector profiles are contributed by companion plugins with a link to the docs page.

**Credential Generation (admin path)**

- **FR-035**: The plugin MUST expose `POST /wp-json/acrossai-mcp-manager/v1/oauth/generate-client` gated by `current_user_can( 'manage_options' )` and a WordPress nonce. It accepts `server_id` + `connector_slug` and issues a fresh `client_secret_post` client bound to the connector profile's redirect-URI whitelist. The generated `client_id` MUST use the structured admin format `server-{server_id}-{connector_slug}-{random8}` where `random8 = bin2hex( random_bytes(4) )` (per §Clarifications Q2). The response body returns the raw `client_id` and `client_secret` ONCE with the profile's rendered setup instructions.
- **FR-036**: Calling `generate-client` a second time for the same `(server_id, connector_slug)` MUST revoke every outstanding token issued to the prior client, revoke the prior client row (or supersede it with a new row + orphan the old), and issue new credentials.

**Cron Cleanup**

- **FR-037**: On activation, the plugin MUST schedule a daily cron `acrossai_mcp_manager_oauth_cleanup` if not already scheduled. On deactivation, the plugin MUST clear the schedule via `wp_clear_scheduled_hook`.
- **FR-038**: The cron handler MUST bulk-delete expired + used auth codes (`expires_at < now OR used=1`) and expired + revoked tokens (`(expires_at < now AND revoked=1) OR expires_at < (now - 30 days)`). It MUST fire `do_action( 'acrossai_mcp_manager_oauth_cleanup' )` at the start of each run so integrators can piggy-back on the schedule.

**Storage Invariants**

- **FR-039**: The plugin MUST NEVER persist raw tokens, raw client secrets, or raw auth codes at rest. Only SHA-256 hashes are stored. All secret comparison MUST use `hash_equals`.
- **FR-040**: The following column widths are cryptographic invariants and MUST NOT be narrowed on any future migration: `token_hash char(64)`, `code_hash char(64)`, `code_challenge char(43)`, `client_secret_hash char(64)` (NULLABLE for public clients).

**Uninstall Lifecycle**

- **FR-041**: On plugin uninstall, IF and only IF the operator has set `acrossai_mcp_uninstall_delete_data = 1`, the plugin MUST drop the three new OAuth tables, delete their `_db_version` options, and clear the daily cron. Deactivation MUST NOT delete data. Uninstall without the opt-in flag MUST preserve everything.
- **FR-042**: The plugin MUST hook WordPress's `deleted_user` action at priority 10 (per §Clarifications Q4). The callback MUST: (a) bulk-revoke every access + refresh token for the deleted `user_id` via `UPDATE ... SET revoked=1 WHERE user_id=%d AND revoked=0`; (b) bulk-delete every pending auth code for the deleted `user_id`; (c) fire `do_action( 'acrossai_mcp_manager_oauth_token_revoked', $token_id, 'user_deleted' )` per revoked token. This prevents authentication against a ghost `user_id` and maintains audit-trail consistency.

**Token Family Revocation (SEC-021-001)**

- **FR-043**: The plugin MUST assign a `token_family_id` (UUIDv4, generated via `wp_generate_uuid4()`) to every access + refresh token pair issued from an authorization_code grant. On refresh-token rotation, the newly-issued access + refresh pair MUST carry forward the presented refresh token's `token_family_id` unchanged. On **refresh-token reuse detection** (a refresh token whose `OAuthTokens` row already has `revoked=1` is presented at `/token`), the plugin MUST: (a) NOT issue new tokens; (b) return `invalid_grant`; (c) select every non-revoked row with the matching `token_family_id`; (d) atomically bulk-revoke all of them via `TokensQuery::revoke_by_family_id( $family_id, 'family_reuse_detected' )`; (e) fire `do_action( 'acrossai_mcp_manager_oauth_token_revoked', $token_id, 'family_reuse_detected' )` per revoked row. This limits a stolen-refresh-token attacker's window from the refresh TTL (30 days) to the access TTL (3600 s), matching RFC 9700 (OAuth 2.1 Security BCP §2.2.2).

**IP Determination for Rate Limits (SEC-021-003)**

- **FR-044**: The plugin MUST determine the client IP address for rate-limit bucketing (FR-027, FR-028) via a documented, filter-controlled strategy: (a) if the filter `acrossai_mcp_manager_trusted_proxies` returns a non-empty array of CIDR strings AND the request includes an `X-Forwarded-For` header AND `$_SERVER['REMOTE_ADDR']` is within a trusted-proxy CIDR, use the RIGHTMOST XFF entry that is NOT itself in the trusted-proxy list; (b) otherwise use `$_SERVER['REMOTE_ADDR']`. The plugin MUST NEVER trust `X-Forwarded-For` unconditionally. Rationale: without this, reverse-proxied installs bucket all requests behind the proxy's IP (rate-limit saturation) OR unconditional XFF trust lets attackers spoof the header to bypass limits. The filter default is an empty array (no trusted proxies — safe by default).

**Strict Redirect URI Scheme Validation (SEC-021-004)**

- **FR-021a** *(tightening of FR-021 per SEC-021-004)*: The DCR endpoint MUST validate every submitted `redirect_uri` using strict scheme rules: `parse_url()` MUST return a `scheme` field; the scheme MUST be exactly `https` OR the `host` field MUST be exactly one of `127.0.0.1`, `localhost`, `::1` (loopback on any port with any scheme). The plugin MUST explicitly reject the following schemes regardless of any other consideration: `javascript`, `data`, `file`, `ftp`, `gopher`, `mailto`, `about`, `chrome`, `chrome-extension` (case-insensitive comparison). Rejection returns 400 + `error=invalid_redirect_uri`. The same strict rules apply at `/authorize` time to redirect_uris embedded in registered client rows OR contributed by a `ConnectorProfile`'s `get_redirect_uri_whitelist()`. Supersedes the shorter description in FR-021.

**RFC 9700 State Parameter Policy (SEC-021-002)**

- **FR-045**: The `state` parameter on `/authorize` is RECOMMENDED per RFC 9700 §2.1 but not REQUIRED — because PKCE S256 (FR-005) already defeats code-injection attacks, requiring `state` would break older MCP clients that omit it while providing marginal additional protection. When present, `state` MUST be echoed back verbatim on both success and error redirects. When absent, the plugin MUST log a `_doing_it_wrong` notice under `WP_DEBUG` explaining that omitting `state` weakens CSRF protection on the callback endpoint even under PKCE. A future filter `acrossai_mcp_manager_require_state` (default `false`) MAY be added to let deployment operators require `state` at the plugin level; not shipped in v1.

**Connector Card Shell (Phase 9 — added 2026-07-11)**

Scope addition. F021 originally left rendering the AI Connectors tab card to each companion plugin — the base plugin's `AIConnectorsTab` was a bare mount point that called `$profile->render_tab_section( $server )`. In practice this meant every companion plugin had to ship its own CSS, JS, and duplicate the same Generate/Regenerate/Copy/Reveal event handlers. With more than one AI connector plugin planned (Claude, ChatGPT, Gemini, Copilot, …), this compounds — every new connector plugin ships ~500 LOC of near-identical JS + CSS.

Phase 9 promotes the shared shell into the base plugin.

- **FR-046**: `AbstractConnectorProfile::render_tab_section( array $server ): void` MUST provide a concrete default implementation that calls `render_default_card( $server )`. Subclasses MAY override for a fully custom UI (device flow, alternate credential model) — the abstract method is now non-abstract.
- **FR-047**: `AbstractConnectorProfile` MUST expose the following concrete protected render helpers, all marked `@experimental May change without notice before 1.0.0`:
  - `render_default_card( array $server ): void` — outer `<section>` frame + header + body + result target.
  - `render_card_header( array $server ): void` — icon + connector name.
  - `render_card_body( array $server ): void` — orchestrates URL row + credentials area + result target.
  - `render_url_row( array $server ): void` — MCP URL to paste + Copy button.
  - `render_credentials_area( array $server ): void` — chooses Generate vs. Regenerate based on existing client, respects `manage_options` capability.
  - `render_regenerate_area( string $client_id, bool $can_manage ): void` — client_id display + Regenerate button when a client already exists.
  - `render_result_target( array $server ): void` — the `<div data-acrossai-result>` target the shared JS injects response HTML into.
  - `find_existing_client_id( int $server_id ): ?string` — encapsulates the F021 `ClientRepository` lookup so subclasses don't need to import the FQN.
- **FR-048**: The base plugin MUST ship a shared CSS bundle at `build/js/ai-connectors.css` (source: `src/scss/ai-connectors.scss`) and a shared JS bundle at `build/js/ai-connectors.js` (source: `src/js/ai-connectors.js`). Both MUST be enqueued by `Admin\Main::maybe_enqueue_ai_connectors_app()` gated on `?page=acrossai_mcp_manager&action=edit&tab=ai-connectors`.
- **FR-049**: The shared JS MUST:
  - Bind a delegated click handler on every `.acrossai-mcp-ai-connectors` wrapper.
  - Handle `.acrossai-mcp-connector__generate-btn` → POST `{server_id, connector_slug}` as JSON to the localized `restEndpoint` with `X-WP-Nonce` from `data-wp-rest-nonce`.
  - Handle `.acrossai-mcp-connector__regenerate-btn` → same POST + `window.confirm( data-acrossai-confirm )` gate.
  - Handle `[data-acrossai-copy]` → clipboard via `navigator.clipboard.writeText` with `document.execCommand( 'copy' )` fallback.
  - Handle `[data-acrossai-reveal]` → toggle paired `.acrossai-mcp-connector__input--secret` between `password` and `text` types.
  - On successful Generate/Regenerate response, inject the returned `client_id` + `client_secret` + `wp_kses_post`-sanitized `setup_instructions_html` into `[data-acrossai-result]`.
- **FR-050**: The shared JS MUST use `wp_localize_script` global `acrossaiMcpConnectors` for both the REST endpoint URL and every user-visible i18n string. Companion plugins do NOT need to enqueue any JS or CSS of their own.
- **FR-051**: The following CSS class names and DOM `data-*` attributes are considered PUBLIC API for the F021 Connector Card Shell (marked `@experimental May change without notice before 1.0.0`). Companion plugins that override individual render helpers MUST produce these selectors so the shared JS routes clicks correctly:
  - `.acrossai-mcp-ai-connectors` — tab wrapper, carries `data-server-id` + `data-wp-rest-nonce`
  - `.acrossai-mcp-connector` — connector card section, carries `data-acrossai-connector-slug`
  - `.acrossai-mcp-connector__generate-btn` — primary Generate button
  - `.acrossai-mcp-connector__regenerate-btn` — secondary Regenerate button, carries `data-acrossai-confirm`
  - `[data-acrossai-copy]` — Copy button, must sit inside a `.acrossai-mcp-connector__copy-row`
  - `[data-acrossai-reveal]` — Reveal button, must sit inside a `.acrossai-mcp-connector__copy-row` containing a `.acrossai-mcp-connector__input--secret`
  - `[data-acrossai-result]` — target `<div>` for AJAX response injection
- **FR-052**: Companion plugins that adopted the F021 pre-Phase-9 pattern (their own `render_tab_section` override + their own JS/CSS) continue to work unchanged — the abstract class's default `render_tab_section` is a concrete method, not a hard requirement. Companions can migrate to the shell by deleting their `render_tab_section` override + their `enqueue_admin_assets` wiring + their `assets/` directory.

**Nested Tabs (Phase 10 — folded from F024 on 2026-07-11)**

Scope addition after F021 Phase 9. The single flat AI Connectors tab (one card per connector) is replaced with a nested-tab admin UI: **Level 1** = server tabs (existing) → **Level 2** = one sub-tab per registered connector profile → **Level 3** = three panels per connector (Generate | Connections | Settings). Migration is backwards-compatible: any companion plugin's `render_tab_section` override is invoked inside the Generate panel's "Advanced: pre-generate credentials manually" collapsible.

**URL contract**:

```
?page=acrossai_mcp_manager
&action=edit
&server=1
&tab=ai-connectors
&connector={slug}      ← Level 2 (default: first profile alphabetically)
&panel={generate|connections|settings}   ← Level 3 (default: generate)
```

Full page reload on tab change — matches F013/F019 Level 1 pattern.

*Level 2 sub-tab bar*

- **FR-024-001**: When zero connector profiles are registered, the AI Connectors tab MUST render the F021 Phase 8 empty state (`render_empty_state`) — no sub-tab bar, no panels.
- **FR-024-002**: When one or more profiles are registered, the tab MUST render a WP `.nav-tab-wrapper` sub-tab row with one `.nav-tab` per profile. The active tab is the one matching `?connector=`, or the first profile alphabetically if none.
- **FR-024-003**: Sub-tab labels are `$profile->get_name()`; sub-tab URLs preserve every existing query arg and set `?connector={slug}&panel=generate`.

*Level 3 panel bar*

- **FR-024-004**: Inside the selected connector, render a second `.nav-tab-wrapper` with three tabs: Generate | Connections | Settings.
- **FR-024-005**: The active panel is the one matching `?panel=`, or `generate` by default.

*Generate panel*

- **FR-024-006**: Renders the MCP URL for this server with a Copy button. URL construction uses `rest_url( trailingslashit( $server_route_namespace ) . $server_route )` per the F011 CliController pattern.
- **FR-024-007**: Renders the connector-specific setup HTML from `$profile->get_mcp_url_setup_html( $mcp_url )` (new profile method with a generic default). Output MUST pass through `wp_kses_post` before rendering.
- **FR-024-008**: Includes a collapsible "Advanced: pre-generate credentials manually" section containing the F021 Phase 9 admin-generate flow (kept as a fallback for AI clients that don't support DCR).

*Connections panel*

- **FR-024-009**: Renders a table of every OAuth client whose tokens' `resource` includes this server's MCP endpoint URL AND whose `connector_slug` equals this connector's slug OR whose DCR metadata matches this profile via `$profile->matches_dcr_client( $client_name, $redirect_uris )`.
- **FR-024-010**: Table columns: Client ID, Client name, Registered via (`DCR` / `Admin`), Active tokens count, Owner user(s), Issued at, Actions.
- **FR-024-011**: Per-row Actions: **Revoke tokens** (bulk-revoke all non-revoked tokens for this client, fires `token_revoked` per row with reason `'admin_revoke'`) + **Delete client** (also removes the client row after confirmation).
- **FR-024-012**: Empty state: "No AI clients have connected via {connector name} yet."

*Settings panel*

- **FR-024-013**: Two setting fields per `(server_id, slug)` pair, stored as `wp_option` keyed `acrossai_mcp_connector_settings_{server_id}_{slug}` = `array{ enabled: bool, require_admin_approval: bool }`. All connector-settings options are `autoload=false`.
- **FR-024-014**: **Enable this connector on this server** (checkbox, default `true`). When flipped from `true` to `false`, the plugin MUST bulk-revoke every non-revoked token whose client belongs to this connector on this server, and fire `acrossai_mcp_manager_oauth_token_revoked` per row with reason `'connector_disabled'`.
- **FR-024-015**: **Require admin approval for new connections** (checkbox, default `false`). When enabled, `AuthorizationController::handle_get` MUST check `acrossai_mcp_connector_approved_users_{server_id}_{slug}` (a `wp_option` list of user IDs) BEFORE rendering the consent screen. Unlisted users MUST see a "pending admin approval" template + get added to `acrossai_mcp_connector_pending_approvals_{server_id}_{slug}`. Admins approve via a list rendered inline in the Settings panel.
- **FR-024-016**: **Revoke all connections for this connector** (button, with `window.confirm`). Same effect as flipping Enable from true to false, but without changing the enabled state. Fires `token_revoked` per row with reason `'admin_nuclear_revoke'`.
- **FR-024-017**: When `TokenValidator::authenticate` runs, if the token's client belongs to a connector whose `enabled` setting is `false`, the callback MUST return `$user_id` unchanged (defensive layer — the mass-revoke on disable already flips `revoked=1`, but this catches races).

*Public API additions (Phase 10)*

- **FR-024-018**: `AbstractConnectorProfile::matches_dcr_client( string $client_name, array<int, string> $redirect_uris ): bool` — new concrete method, default returns `false`. Companion plugins override to claim DCR-registered clients whose metadata matches their brand.
- **FR-024-019**: `AbstractConnectorProfile::get_mcp_url_setup_html( string $mcp_url ): string` — new concrete method, default returns a generic "paste this URL into your AI client's connector settings" HTML. Companion plugins override for connector-specific instructions. Output MUST be passed through `wp_kses_post` before rendering.

*REST endpoints (Phase 10)*

- **FR-024-020**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/connector-settings` — save settings for `(server_id, slug)`. Admin only (`manage_options` + `X-WP-Nonce`).
- **FR-024-021**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/revoke-client-tokens` — revoke every non-revoked token for a `client_id`. Admin only. Fires `token_revoked` per row with reason `'admin_revoke'`.
- **FR-024-022**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/delete-client` — revoke tokens then delete the client row. Admin only.
- **FR-024-023**: `POST /wp-json/acrossai-mcp-manager/v1/oauth/approve-pending-consent` — admin approves a specific user's pending consent for a `(server, connector)` pair. Removes from pending, adds to approved.

### WordPress Requirements

**PHP Version**: PHP 8.1+ (constitution target). No PHP 8.2+ features.
**WordPress Version**: 6.9+.
**Multisite**: Supported — each site has its own OAuth tables (per-site prefix, `$global = false`).
**Required Plugins / Packages**: `berlindb/core: ^3.0.0` (already installed). No new composer runtime dependencies.
**Optional Integrations**: `wordpress/mcp-adapter` (already a dependency) is the runtime consumer of authenticated users. Companion connector-profile plugins are optional — the plugin degrades to an empty-state notice when none are installed.

### Module Placement

**PHP Class(es)**:

- `includes/Database/OAuthClients/{Table,Schema,Query,Row}.php` — namespace `AcrossAI_MCP_Manager\Includes\Database\OAuthClients`.
- `includes/Database/OAuthTokens/{Table,Schema,Query,Row}.php` — namespace `AcrossAI_MCP_Manager\Includes\Database\OAuthTokens`.
- `includes/Database/OAuthAuthCodes/{Table,Schema,Query,Row}.php` — namespace `AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes`.
- `includes/OAuth/{DiscoveryController,AuthorizationController,TokenController,ClientRegistrationController,TokenValidator,PKCE,OAuthRouter,Cleanup}.php` — namespace `AcrossAI_MCP_Manager\Includes\OAuth`.
- `includes/OAuth/Repositories/{AccessTokenRepository,RefreshTokenRepository,AuthCodeRepository,ClientRepository,ScopeRepository}.php` — namespace `AcrossAI_MCP_Manager\Includes\OAuth\Repositories`.
- `includes/OAuth/Security/{RateLimiter,SecretsVault}.php` — namespace `AcrossAI_MCP_Manager\Includes\OAuth\Security`.
- `includes/Connectors/{AbstractConnectorProfile,ConnectorProfileRegistry}.php` — namespace `AcrossAI_MCP_Manager\Includes\Connectors`.
- `admin/Partials/ServerTabs/AIConnectorsTab.php` — namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs`.

**Templates**:

- `templates/oauth/consent.php` — self-contained HTML, no admin frame.

**Hook Registration**: All `add_action`/`add_filter` for this feature MUST be wired in `includes/Main.php` via `define_admin_hooks()` (REST route registration on `rest_api_init`, connector registry boot on `init` priority 5) and `define_public_hooks()` (OAuth rewrite rules, query vars, parse_request dispatch, `determine_current_user` filter, cron cleanup handler).

### REST API Contract

**Root-domain endpoints** (via `add_rewrite_rule` + `parse_request`, following the Feature-007 `FrontendAuth` pattern):

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/.well-known/oauth-authorization-server` | Public | RFC 8414 AS metadata JSON |
| `GET` | `/.well-known/oauth-protected-resource` | Public | RFC 9728 protected-resource metadata JSON |
| `GET/POST` | `/authorize` | Session (user logged in) | Authorization endpoint with consent screen |
| `POST` | `/token` | Client credentials + PKCE | Token endpoint (authorization_code + refresh_token grants) |

**REST-namespaced endpoints** (via `register_rest_route`):

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/register` | Rate-limited only | RFC 7591 Dynamic Client Registration |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/oauth/generate-client` | `manage_options` + nonce | Admin-only credential generator (used by AIConnectorsTab) |

**Public API** (permanent — do not remove invocations without a major version bump):

- Filter `acrossai_mcp_manager_connector_profiles` — receives + returns `AbstractConnectorProfile[]`. Fires once per request in `ConnectorProfileRegistry::get_profiles()`.
- Action `acrossai_mcp_manager_oauth_token_issued` — args `(int $token_id, string $client_id, int $user_id, string $connector_slug)`.
- Action `acrossai_mcp_manager_oauth_authorization_denied` — args `(string $client_id, string $redirect_uri, string $reason)`.
- Action `acrossai_mcp_manager_oauth_token_revoked` — args `(int $token_id, string $reason)`.
- Action `acrossai_mcp_manager_oauth_cleanup` — daily cron hook, no args.

### Database / Storage

**Three new BerlinDB tables**:

1. `{wpdb->prefix}acrossai_mcp_oauth_clients` — issued OAuth clients. Columns per §Storage Invariants + spec details in the planning doc. Key indexes: `unique(client_id)`, `key(connector_slug)`, `key(metadata_fingerprint)`.
2. `{wpdb->prefix}acrossai_mcp_oauth_tokens` — access + refresh tokens (single table with `token_type` discriminator). Key indexes: `unique(token_hash)`, `key(user_id)`, `key(expires_at)`, `key(token_type)`.
3. `{wpdb->prefix}acrossai_mcp_oauth_auth_codes` — pending authorization codes (short-lived). Key indexes: `unique(code_hash)`, `key(expires_at)`.

Each table follows the F011 BerlinDB pattern: phantom-version guard, `$version = '1.0.0'`, `$db_version_key = 'acrossai_mcp_oauth_<table>_db_version'`, request-time boot in `Main::bootstrap_database_tables()`.

All secret material stored ONLY as SHA-256 hashes. All comparison via `hash_equals`.

### Security Checklist

*(Derived from Constitution §III — all applicable)*

- [x] All REST routes have explicit `permission_callback` — the DCR endpoint uses a rate-limiter callback that returns `WP_Error` on lockout; the admin `generate-client` endpoint uses `current_user_can( 'manage_options' )` explicitly. NO `__return_true` on mutating routes.
- [x] All form/AJAX handlers verify a nonce via `wp_verify_nonce` (consent form) or per-endpoint policy.
- [x] All admin actions enforce a capability check.
- [x] All DB queries use `$wpdb->prepare()` via BerlinDB's prepared layer.
- [x] All secrets stored SHA-256 hashed; `hash_equals` on every comparison.
- [x] File input: N/A (no file uploads).
- [x] `admin_url()` + `home_url()` wrapped with escape functions at output.
- [x] Rate-limit responses use RFC-6749-shaped error bodies + `Retry-After` headers.
- [x] Redirect URIs restricted to HTTPS or loopback; validated at both registration time and authorize time.
- [x] PKCE S256 mandatory; `plain` explicitly rejected regardless of what metadata advertises.
- [x] RFC 8707 resource parameter mandatory; token scope binds to a specific resource URL.
- [x] RFC 9207 `iss` parameter emitted on authorization callback redirects.

### Key Entities

- **OAuth Client**: An application (an AI connector or a headless integration) that has been provisioned to talk to this WordPress site's MCP server. Identified by a random `client_id` (32 hex chars). Bound to a set of `redirect_uris` and — optionally — a connector profile via `connector_slug`. Confidential clients (`token_endpoint_auth_method='client_secret_post'`) have a hashed `client_secret_hash`; public clients (`'none'`) do not.
- **Auth Code**: A short-lived (600s) single-use credential issued at the end of an authorization flow. Bound to `client_id`, `user_id`, `redirect_uri`, PKCE `code_challenge`, RFC 8707 `resource`, `scope`. Consumed atomically at `/token` (single-use via `UPDATE ... WHERE used=0`). Persisted as SHA-256 hash only.
- **Access Token**: A short-lived (3600s) bearer credential the AI client presents on every MCP tool call. Bound to `client_id`, `user_id`, `scope`, `resource` (RFC 8707 audience) — the `resource` binding is enforced at call time (§Clarifications Q1): the token is only valid against the specific MCP endpoint URL named at authorization time; cross-server requests using the same token fail with 401. Persisted as SHA-256 hash only.
- **Refresh Token**: A longer-lived (30-day) credential the AI client presents at `/token` to obtain a fresh access token pair. Single-use with rotation — every successful refresh revokes the presented token and issues a new pair carrying the same `resource` and `scope` forward.
- **Connector Profile**: A subclass of `AbstractConnectorProfile` contributed by a companion plugin. Owns display metadata (slug, name, icon), redirect-URI whitelist, setup instructions, and optional consent-screen branding. The base plugin does NOT ship any connector profiles — they are always contributed via the filter.
- **Connector Registry**: A memoizing singleton that fires the `acrossai_mcp_manager_connector_profiles` filter exactly once per request and returns the resulting profile array sorted by slug.
- **AI Connectors Tab**: A built-in per-server admin tab (priority 35) that renders one card per registered connector profile, allowing the operator to generate credentials for each AI connector.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings (`composer run phpcs`).
- [ ] PHPStan level 8: zero errors (`composer run phpstan`).
- [ ] PHPUnit tests passing for PKCE verify, token validator recursion guard, connector registry memoization, rate limiter, auth code atomic consume, discovery metadata shape, cleanup cron, authorize state policy (`composer run phpunit -- --testsuite oauth`).
- [ ] ESLint: zero errors on `src/js/**/*.js` (Constitution §VII — `npm run lint:js`).
- [ ] `npm run validate-packages`: passes (Constitution §VII).
- [ ] `bin/verify-f021-gates.sh`: all 5 gates green (T118b/T118c/T118d/T119/T120).
- [ ] Security review: sanitization, escaping, nonces, capabilities, rate limits, secret storage verified at every boundary.
- [ ] All hooks wired in `Main.php` — none in class constructors.
- [ ] Every column-width invariant preserved (see FR-040).
- [ ] No composer runtime dependencies added.
- [ ] Feature 011's decisions (phantom-guard, SUBCLASS-NO-USE-COLLISION, column widths) untouched.
- [ ] Feature 019's third-party tab filter (`acrossai_mcp_manager_server_tabs`) untouched.
- [ ] `includes/REST/CliController.php` (Application Password CLI flow) untouched.
- [ ] `vendor/wordpress/mcp-adapter/` and `includes/AccessControl/` untouched.
- [ ] Zero references to raw tokens, secrets, or auth codes at rest (grep-verifiable).

### Measurable Outcomes

- **SC-001**: A site admin can generate credentials for a registered connector, copy them into the AI client, complete an OAuth handshake, and issue a first MCP tool call in under 5 minutes end-to-end (measured on a stock local WordPress install).
- **SC-002**: 100% of `/authorize` requests with `code_challenge_method != 'S256'` (including `plain`) are rejected before an auth code is issued.
- **SC-003**: 100% of DCR requests exceeding 10/IP/60s receive a 429 with `Retry-After: 60`. 100% of `/authorize` and `/token` requests exceeding 60/IP/60s receive the same treatment.
- **SC-004**: An access token that has been revoked (either by refresh rotation or admin action) MUST fail authentication on the very next request — no cache, no grace period.
- **SC-005**: An identical DCR POST body (byte-for-byte) produces the same `client_id` on the second call as it did on the first — verified by fingerprint match — with zero new rows inserted and zero `token_issued` actions fired.
- **SC-006**: An auth code presented to `/token` a second time returns `invalid_grant` and MUST NOT issue a token — verified via a concurrent-POST test.
- **SC-007**: An access token issued for a specific `resource` URL is bound to that URL and stored in the `resource` column, AND presenting the token against a different MCP server URL on the same site returns 401 (RFC 8707 audience enforcement per §Clarifications Q1) — verified via a two-server integration test.
- **SC-008**: The daily `acrossai_mcp_manager_oauth_cleanup` cron runs successfully on a live WP-Cron install, removes expired auth codes and expired-and-revoked tokens, and fires the observability action once per run.
- **SC-009**: When the plugin is uninstalled with the delete-data opt-in flag set, `SHOW TABLES LIKE 'wp_acrossai_mcp_oauth_%'` returns zero rows and `wp_next_scheduled( 'acrossai_mcp_manager_oauth_cleanup' )` returns false.
- **SC-010**: The base plugin ships zero connector profiles — the AI Connectors tab shows the empty state until a companion plugin registers one via the filter.
- **SC-011**: With no companion plugins installed and no OAuth flows exercised, the plugin's activation adds three tables + one cron and adds zero to per-request PHP execution time on non-OAuth pages (measured by `Server-Timing` header or `debug.log` timestamps).

### Phase 10 (Nested Tabs) Success Criteria

- **SC-024-001**: With multiple connector profiles registered (Claude + a stub), the AI Connectors tab renders a Level 2 sub-tab row with both, and switching between them updates the URL (`?connector={slug}`) + rendered content.
- **SC-024-002**: The Generate panel shows the MCP URL with a Copy button using the same clipboard behavior as Phase 9 (`navigator.clipboard.writeText` with `document.execCommand('copy')` fallback).
- **SC-024-003**: The Connections panel lists at least one client after an admin has run Generate on the Advanced fallback, or after a DCR-capable AI client has completed registration and been claimed by `$profile->matches_dcr_client`.
- **SC-024-004**: Flipping Enable from true to false immediately revokes every active token for that connector on that server (verified by `SELECT COUNT(*) FROM wp_acrossai_mcp_oauth_tokens WHERE ... AND revoked=0` returning 0) and fires `token_revoked` per row with reason `'connector_disabled'`.
- **SC-024-005**: Toggling Require admin approval on and then attempting `/authorize` as a non-approved user renders the "pending admin approval" template (with `nocache_headers()`), NOT the consent screen. Admin can then approve via the Settings panel, and the user can complete the flow on next attempt.

---

## Assumptions

- **OAuth 2.1 vs OAuth 2.0**: This feature implements OAuth 2.1 (draft-ietf-oauth-v2-1 as of 2026-07). The observable wire behavior overlaps almost entirely with OAuth 2.0 + the required PKCE / iss / resource extensions. The MCP authorization spec's Anthropic-mandated requirements are the strictest and drive every default.
- **Single scope**: The plugin ships one scope (`mcp`). Multi-scope authorization is out of scope for this release; connector profiles that want to introduce additional scopes will need a follow-up feature to widen the scope registry.
- **No JWT / no signing keys**: Access tokens are opaque bearer strings (32 random bytes → 64 hex chars). No JWT signing. No resource-server key rotation. This is deliberate: JWT requires a signing library or careful native implementation of RS256/ES256; keeping tokens opaque + storing them hashed lets the plugin ship without new dependencies.
- **HTTPS assumed on production**: OAuth is only usable over HTTPS. The plugin does not fail activation on HTTP sites (developers commonly run local `http://localhost` installs), but the existing HTTPS notice at `Admin\Partials\Notices` covers the operator-facing warning.
- **No JWT bearer client authentication**: Only `client_secret_post` and `none` are supported. Adding `client_secret_jwt` or `private_key_jwt` is a follow-up feature.
- **No introspection or revocation REST endpoints**: RFC 7662 (token introspection) and RFC 7009 (token revocation) endpoints are out of scope for v1. Revocation happens implicitly via refresh-token rotation or explicitly via admin regeneration.
- **Fresh install only**: These are three new tables. No data-migration path is required or offered. Every existing WordPress install starts with empty OAuth state.
- **F017 + F020 exposure/curation is authoritative**: The OAuth server just makes the user known to WordPress. Which abilities/tools the user can invoke is still owned by F015 (access control), F017 (ability exposure), and F020 (tool curation) at the mcp-adapter tool-call boundary. Feature 021 does not attempt to short-circuit or override any of these.
- **CliController flow stays separate**: The existing CLI Application Password issuance flow at `includes/REST/CliController.php` is a distinct product surface with a distinct contract. It is not reused, not extended, not consolidated with the new OAuth 2.1 server. If a future feature wants to unify them, that is scoped separately.
- **Connector profiles ship as companion plugins**: The base plugin does not ship any Claude/ChatGPT/Gemini/Copilot profiles. Each is a small companion plugin (e.g. `acrossai-claude-connectors`, `acrossai-chatgpt-connectors`) that adds one callback to `acrossai_mcp_manager_connector_profiles`. This keeps the base plugin's LOC small and third-party contributions welcome.
- **Rewrite rule flush**: The plugin already calls `flush_rewrite_rules()` in `Activator::activate()` (from Feature 007's `FrontendAuth`). The OAuth endpoints piggyback on that flush; no additional flush is needed.
- **Rate-limit backing store**: Transients are used for rate-limit counters. On installs with Redis / Memcached object caching, transients are backed by the object cache and per-instance IP counters may be inconsistent across web workers. For rate-limiting the current single-instance guarantees are acceptable; a distributed counter is a follow-up if abuse becomes measurable.

### Accepted Security Trade-offs (from plan-phase security review)

- **DCR dedup information disclosure (SEC-021-005)**: When a DCR client re-registers with byte-identical metadata, the endpoint returns 200 with the same `client_id` (no new secret). This leaks the fact that a specific `(redirect_uris, grant_types, response_types, token_endpoint_auth_method)` combination is already registered. Accepted trade-off: the disclosed metadata is content the AI client publishes anyway (its `redirect_uris` are usually publicly discoverable, its `client_name` is public branding). The operational simplicity of idempotent registrations outweighs the marginal disclosure. Documented in `docs/security-reviews/2026-07-10-021-oauth-2-1-implementation-plan.md` §SEC-021-005.
- **SHA-256 for server-issued client secrets (SEC-021-009)**: Client secrets are 256-bit random values (`random_bytes(32)`), so SHA-256 provides adequate at-rest protection without the memory-hard cost of argon2id. Argon2id would be required if secrets were user-chosen. This matches Doorkeeper (Ruby), django-oauth-toolkit (Python), and league/oauth2-server (PHP) precedent. A future migration to argon2id is possible without changing the wire contract; deferred until a compelling reason surfaces.
- **CORS policy (SEC-021-007)**: Discovery endpoints (`/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`) explicitly set `Access-Control-Allow-Origin: *` (public metadata). Non-discovery endpoints (`/authorize`, `/token`, `/register`, `/generate-client`) explicitly do NOT set any `Access-Control-Allow-*` headers. This blocks browser JS from posting client credentials or authorization codes cross-origin. Companion plugins wanting to enable browser-hosted DCR MAY register a documented CORS filter in a future release; not shipped in v1.
- **RFC 7009 token revocation endpoint deferred (SEC-021-006)**: v1 does not ship a user-facing `/revoke` endpoint. Users who need to invalidate a compromised token must either wait for the access token to expire (≤3600 s) or contact an admin to run `TokensQuery::revoke_by_hash` (via wp-cli) or click **Regenerate** on the AI Connectors tab (revokes all tokens for that client). Introduce `/revoke` as a follow-up feature. RFC 7662 (introspection) is similarly deferred.
- **Approved-action observability asymmetry (SEC-021-008)**: The plugin fires `oauth_authorization_denied` on Deny but does NOT fire `oauth_authorization_approved` on Approve — approvals are inferrable from the subsequent `oauth_token_issued` fire when `/token` exchanges the auth code. If a client abandons the flow after Approve but before `/token`, the approval is silent. Audit consumers wanting hard approval records should observe `oauth_token_issued` and cross-reference against their own consent-request log. A symmetric `oauth_authorization_approved` action MAY be added in a future release if audit demand justifies the additional event surface; not shipped in v1.
