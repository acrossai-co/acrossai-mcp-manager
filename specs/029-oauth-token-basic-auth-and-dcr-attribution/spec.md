# Feature Specification: OAuth `/token` accepts HTTP Basic auth + DCR-registered clients attributed to connector profiles

**Feature Branch**: `029-oauth-token-basic-auth-and-dcr-attribution` (implementation on `fix/oauth-token-basic-auth-and-dcr-attribution` — see plan.md §Note on branch naming)
**Created**: 2026-07-18
**Status**: Implemented (reverse-engineered from PR #37)
**Input**: User description: "Two follow-on OAuth gaps surfaced after v0.1.2 (F027 DCR default fix): (a) /token endpoint didn't accept RFC 6749 §2.3.1 HTTP Basic auth, only body-side client_id/client_secret; (b) DCR-registered clients got connector_slug='' which silently bypassed F024's per-connector settings gating. Also: soften client_secret_post enforcement so pre-F027 confidential-registered clients that behave as public+PKCE at exchange can still complete."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - MCP host authenticates at `/token` via HTTP Basic auth (Priority: P1) 🎯 MVP

Any OAuth-standard client (including generic OAuth libraries and some MCP hosts) presents client credentials at the token endpoint using the Authorization header — `Authorization: Basic base64(client_id:client_secret)` — as RECOMMENDED by RFC 6749 §2.3.1. Before F029, this plugin's `/token` endpoint read `client_id`/`client_secret` from the POST body only. A Basic-auth-only client hit `invalid_request` for the "missing" body-side `client_id` field even though the credentials were correctly presented.

**Why this priority**: This is the spec-conformance gap. RFC 6749 explicitly RECOMMENDS Basic auth; not accepting it makes the endpoint non-interoperable with generic OAuth libraries and MCP hosts that follow the spec literally.

**Independent Test**: `curl -X POST -H 'Authorization: Basic <base64>' --data 'grant_type=authorization_code&code=…&code_verifier=…&redirect_uri=…' <site>/token` with NO `client_id`/`client_secret` in the body → response is 200 with token payload (not 400 `invalid_request`).

**Acceptance Scenarios**:

1. **Given** a client registered with a `client_secret` and a valid auth code, **When** the client POSTs to `/token` with `Authorization: Basic base64(client_id:client_secret)` and body containing only `grant_type + code + code_verifier + redirect_uri`, **Then** the response is 200 with an access + refresh token pair.
2. **Given** a client running behind a CGI-style FastCGI wrapper that surfaces the Authorization header via `REDIRECT_HTTP_AUTHORIZATION`, **When** the client POSTs with Basic auth, **Then** `read_client_credentials_from_header()` reads the CGI fallback header and the flow succeeds.
3. **Given** a client that sends BOTH a Basic auth header AND `client_id`/`client_secret` body params, **When** the client POSTs, **Then** the header credentials take precedence (RFC 6749 §2.3.1 conformance) and the body values are ignored for authentication purposes.
4. **Given** a malformed Authorization header (missing `Basic ` prefix, or base64 decode fails, or decoded string has no colon), **When** the client POSTs, **Then** `read_client_credentials_from_header()` returns empty strings and the endpoint falls back to body params (or rejects if body is also empty).
5. **Given** the same fix applied to the refresh_token grant, **When** a refresh happens with Basic auth, **Then** the response is 200 with a rotated pair.

---

### User Story 2 - DCR-registered client is attributed to its connector profile at registration time (Priority: P1)

The F024 admin surface exposes per-connector enable/disable toggles and an admin-approval gate. Both consult `connector_slug` on the client row to resolve settings. Admin-generated clients (via `/oauth/generate-client`) get the slug from the REST param, but DCR-registered clients (via `/oauth/register`, opaque 32-hex `client_id`) previously received a hardcoded `connector_slug = ''`, causing F024 gate resolution to silently fall open. Every Claude.ai / ChatGPT / Cursor / Cline connection bypassed the admin gate.

**Why this priority**: Silent failure of an admin-facing security control. Operators believe they've disabled the Claude connector on their site; DCR clients still authenticate through it.

**Independent Test**: POST a DCR request with `client_name: "Claude"` and `redirect_uris: ["https://claude.ai/api/mcp/auth_callback"]` → response is 201; the new row in `wp_acrossai_mcp_oauth_clients` has `connector_slug` populated with the matching profile's slug (e.g., `claude-ai`), not empty string. Then flip the connector's `enabled` toggle off on F024 → subsequent `/authorize` requests for that `client_id` should redirect with `access_denied`.

**Acceptance Scenarios**:

1. **Given** a `ConnectorProfileRegistry` with at least one profile whose `matches_dcr_client()` returns true for a DCR body's `(client_name, redirect_uris)`, **When** the client POSTs to `/oauth/register`, **Then** the persisted `connector_slug` equals that profile's `get_slug()`.
2. **Given** a DCR body whose `(client_name, redirect_uris)` matches NO registered profile, **When** the client POSTs to `/oauth/register`, **Then** `connector_slug` is persisted as empty string (previous behavior — unknown clients still register cleanly).
3. **Given** multiple profiles whose `matches_dcr_client()` would return true, **When** the client POSTs to `/oauth/register`, **Then** the FIRST matching profile's slug is used (first-match-wins, deterministic per registry iteration order).
4. **Given** the F024 admin toggles the matched connector to disabled, **When** the DCR-registered client subsequently hits `/authorize`, **Then** the request is rejected via `access_denied` per F024's gate — as it would be for an admin-generated client with the same slug.

---

### User Story 3 - Confidential client that fails to send secret at exchange still completes via PKCE (Priority: P2)

Modern MCP hosts (Claude.ai and friends) sometimes register as `client_secret_post` — either via pre-F027 DCR that defaulted to that value, or via an admin generator — but then behave as public+PKCE clients at the exchange step, never carrying the secret. Before F029, the token endpoint hard-rejected these with `invalid_client` for the missing secret; the auth code was already atomically consumed by that point, so the client couldn't retry. F029 softens the enforcement: when a `client_secret_post` client sends NO secret (header AND body both empty), fall through to PKCE-only verification.

**Why this priority**: Defense-in-depth on top of F027's source-of-truth default fix. F027 ensures NEW DCR clients register as `none`; F029 ensures pre-existing OR explicitly-confidential-but-behaving-public clients also complete the flow.

**Independent Test**: On an install with a pre-F027 DCR client row (`token_endpoint_auth_method = 'client_secret_post'`, non-null `client_secret_hash`), have the client POST `/token` with `code + code_verifier + client_id + redirect_uri` and NO secret (header or body). Response is 200 with token pair. Then repeat with a WRONG `code_verifier` — response is 400 `invalid_grant PKCE verification failed`, confirming PKCE still authenticates the exchange.

**Acceptance Scenarios**:

1. **Given** a client registered as `client_secret_post` and a valid auth code, **When** the client POSTs to `/token` with no secret in header or body but a correct PKCE verifier, **Then** the response is 200 (PKCE authenticates).
2. **Given** the same client registration, **When** the client POSTs a wrong PKCE verifier and no secret, **Then** the response is 400 `invalid_grant PKCE verification failed`.
3. **Given** the same client registration, **When** the client DOES send a secret (correct or incorrect), **Then** the existing constant-time verify path runs (correct secret → 200; incorrect → 401 `invalid_client`). No regression on clients that DO authenticate the confidential way.
4. **Given** the same softening applied to the `refresh_token` grant, **When** a `client_secret_post` client refreshes without sending a secret, **Then** the request succeeds provided the refresh token itself is valid, unrevoked, and bound to the correct `client_id`.

---

### Edge Cases

- **What if the Authorization header contains a scheme other than Basic (e.g., Bearer)?** `read_client_credentials_from_header()` short-circuits (stripos returns non-zero) and returns empty strings. The endpoint then falls back to body params, or rejects if the body is also empty. Bearer tokens are used elsewhere (MCP endpoint via `TokenValidator`); they don't belong on `/token`.
- **What if the base64-decoded value has NO colon?** Invalid Basic auth format per RFC. `read_client_credentials_from_header()` returns empty strings; fall back to body.
- **What if the base64-decoded value has MULTIPLE colons (e.g., a secret contains a colon)?** `explode( ':', $decoded, 2 )` splits on the FIRST colon only — the rest becomes part of `$client_secret`. Matches RFC 6749 §2.3.1 (which references RFC 2617 §2 that treats the last colon in the userid position as the separator).
- **What if a `ConnectorProfile::matches_dcr_client()` implementation throws?** F029 does NOT wrap the walk in try/catch — a profile throwing would abort the DCR request with a 500. This is intentional: a broken profile is a bug that should be surfaced loudly. If we later need graceful degradation, add per-profile try/catch in a follow-up.
- **What if a client registers via DCR and its slug is later disabled on F024?** Existing tokens continue to work (F024 gates `/authorize` + `/token`; bearer usage against the MCP endpoint is governed by `TokenValidator` which does NOT consult F024 settings). This is by design: revoking bearer usage requires an explicit token-revocation via cron or admin action. Not F029's scope.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `TokenController::read_client_credentials_from_header()` MUST parse `$_SERVER['HTTP_AUTHORIZATION']` (with `REDIRECT_HTTP_AUTHORIZATION` CGI fallback) as `Basic base64(client_id:client_secret)` and return the decoded pair, or `['', '']` when the header is absent / has wrong scheme / decodes malformed.
- **FR-002**: Both `handle_authorization_code()` and `handle_refresh_token()` MUST resolve `$client_id` / `$client_secret` from the header first, falling back to body params on empty header values. Header MUST take precedence (RFC 6749 §2.3.1).
- **FR-003**: `handle_authorization_code()` MUST remove `client_id` from its `$required` body-field list (moved to a post-resolution guard so header-only presentation is accepted).
- **FR-004**: Every downstream reference in both grants (`hash_equals` against `$row->client_id`, `ClientRepository::find_by_id`, `AccessTokenRepository::issue`, `RefreshTokenRepository::issue`, the `acrossai_mcp_manager_oauth_token_issued` action's `client_id` arg) MUST use the header-first-resolved local `$client_id`, not `$body['client_id']`.
- **FR-005**: When a client has `token_endpoint_auth_method === 'client_secret_post'` AND submits a non-empty secret (via header OR body), the endpoint MUST verify it via `ClientRepository::verify_secret` and reject with `invalid_client` HTTP 401 on mismatch (unchanged behavior).
- **FR-006**: When a client has `token_endpoint_auth_method === 'client_secret_post'` AND submits NO secret (header AND body both empty), the endpoint MUST fall through to PKCE-only verification for `authorization_code` (or refresh-token-bound-to-client verification for `refresh_token`) instead of rejecting.
- **FR-007**: `ClientRegistrationController::handle_register()` MUST walk `ConnectorProfileRegistry::instance()->get_profiles()` and call `matches_dcr_client( $client_name, $redirect_uris )` on each profile before calling `ClientRepository::create()`.
- **FR-008**: The first profile whose `matches_dcr_client()` returns true MUST have its `get_slug()` value persisted into the new row's `connector_slug` column.
- **FR-009**: When no profile matches, `connector_slug` MUST be persisted as empty string (previous behavior preserved for unknown clients).
- **FR-010**: `handle_admin_generate()` MUST NOT be modified — admin-generated clients continue to receive their slug from the REST param.

### Success Criteria

- **SC-001**: `composer run phpcs` on both modified files returns clean (base64_decode warning acceptable behind a phpcs:ignore with RFC 6749 §2.3.1 justification).
- **SC-002**: `composer run phpstan` (level 8) returns clean.
- **SC-003**: The pre-existing `oauth` PHPUnit testsuite (`tests/phpunit/OAuth/`) continues to pass on CI. New PHPUnit cases for FR-001, FR-002, FR-006, FR-007 are recommended follow-ups but not gated by F029's PR (see Out-of-Scope).
- **SC-004**: A `curl` smoke test with header-only Basic auth against `/token` returns 200 (not 400 `invalid_request`).
- **SC-005**: A DCR POST for a Claude-shaped body results in a `wp_acrossai_mcp_oauth_clients` row with `connector_slug` populated by the matching profile, not empty string.
- **SC-006**: PKCE verifier mismatch still returns 400 `invalid_grant PKCE verification failed` (no security regression on the defense-in-depth softening).

---

## Assumptions

- The `ConnectorProfileRegistry` returns profiles in a deterministic order; first-match-wins semantics of FR-008 depend on this. Currently `get_profiles()` iterates in registration order (companion plugins' `add_filter` order on `acrossai_mcp_manager_connector_profiles`).
- Existing DCR rows with `connector_slug = ''` are NOT backfilled by F029. The `AuthorizationController::infer_slug_from_dcr_client()` helper continues to serve as a legacy-row fallback and stays functional.
- `AbstractConnectorProfile::matches_dcr_client()` implementations are trusted — F029 does not wrap the walk in a try/catch. A throwing profile is treated as a bug to be surfaced.
- PKCE S256 is mandatory on every authorization flow (enforced by `AuthorizationController` per F021). F029's defense-in-depth softening in FR-006 relies on this invariant — without PKCE, the softened path would be unauthenticated.
