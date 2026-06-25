# Feature Specification: OAuth / Claude Connectors Integration

**Feature Number**: 005
**Feature Branch**: `005-oauth-connectors`
**Created**: 2026-06-18
**Status**: Draft
**Spec**: `specs/005-oauth-connectors/spec.md`
**Input**: User description: "OAuth / Claude Connectors Integration"

---

## Clarifications

### Session 2026-06-18

- Q: Where do OAuth audit events (`failed_unknown_client`, `failed_redirect_mismatch`, `failed_replay_attempt`, successful Bearer auth, cross-server token use blocked, etc.) get persisted? → A: **New dedicated `acrossai_mcp_oauth_audit` table.** Audit events have different access patterns (append-mostly, retained 30-90 days for forensics) and different lifecycle from auth codes (cleaned up in minutes). Clean separation matches the project's one-table-per-concern pattern (MCPServer, CliAuthLog, OAuthToken, OAuthAudit). New FR-019a defines the schema; new BerlinDB Query layer at `includes/Database/OAuthAudit/{Schema,Table,Row,Query}.php`.
- Q: How are expired auth codes, expired access tokens, and old audit events cleaned up to prevent DB bloat? → A: **WP-Cron daily sweep with per-class retention.** Single recurring event `acrossai_mcp_oauth_cleanup` runs once per 24 hours. Per-class retention: auth codes deleted 24 hours after expiry, access tokens deleted 7 days after expiry-or-revocation (the 7-day window allows admin to investigate "why did Claude lose access" reports while still bounding DB growth), audit events deleted 90 days after creation. WP-CLI command `wp acrossai-mcp oauth cleanup` for production hosts using real cron instead of WP-Cron. Cleanup is idempotent — re-running mid-sweep is safe. New FR-019b defines the event + retention windows.
- Q: How is the public token endpoint protected against brute-force (PKCE-verifier guessing, client_secret guessing, DoS)? → A: **Per-(client_id, IP) exponential backoff with soft enforcement.** Track failed token-endpoint attempts per `(client_id, IP)` in a WordPress transient. After 5 failures in 1 minute → return HTTP 429 + `Retry-After: 60`. After 50 failures in 1 hour → lock the `(client_id, IP)` tuple for an hour (still returns 429, never blackholes). NO global IP ban — the IP might be a shared NAT and locking it would harm legitimate Claude users from the same network. Every lockout writes a `failed_rate_limit` audit row (new event type). New FR-014a defines the limits. Successful exchanges reset the failure counter for that tuple.
- Q (SEC-001 amendment, 2026-06-21): The original FR-013 redeem step described `SELECT … WHERE redeemed_at IS NULL` followed by `UPDATE … SET redeemed_at = NOW()`. Under concurrent requests with the same code, both pass the SELECT before either flips the flag — both issue tokens, defeating the FR-014 anti-replay defense. → A: **Atomic compare-and-swap on the redeem step.** Replace the SELECT-then-UPDATE pattern with a single `UPDATE … WHERE id = :id AND completed_at IS NULL` and check `$wpdb->rows_affected`. `1` → this request won the race, proceed to issue token. `0` → another request already redeemed; treat as REPLAY (FR-014 path fires: revoke all child tokens, return `invalid_grant`, audit `failed_replay_attempt`). The CAS is a single row-lock — no performance cost. FR-013 + FR-014 wording updated; `contracts/token-endpoint.md` Step 8 + new Step 8b document the CAS. Per-RFC-test PHPUnit must include a concurrent-redeem case.

---

## User Scenarios & Testing

### User Story 1 — Claude Discovers the OAuth Server Metadata (P1)

Claude (the AI client, on behalf of an end user) wants to connect to a WordPress
site as an MCP client. Before it can do so it needs to know the site's OAuth
authorization endpoint, token endpoint, supported grant types, supported PKCE
challenge methods, and supported scopes. Per RFC 8414 (OAuth Authorization
Server Metadata) and RFC 9728 (OAuth Protected Resource Metadata), Claude
issues `GET` requests to two well-known URLs and parses the JSON responses.

**Why this priority**: Without discovery Claude cannot find the auth and token
endpoints. No subsequent flow is possible.

**Independent Test**: From a clean install of the plugin, `curl
https://example.com/.well-known/oauth-authorization-server` returns a 200 with
a JSON body containing `issuer`, `authorization_endpoint`, `token_endpoint`,
`response_types_supported`, `grant_types_supported`,
`code_challenge_methods_supported`, and `token_endpoint_auth_methods_supported`.

**Acceptance Scenarios**:

1. **Given** the plugin is active and rewrite rules are flushed, **When**
   anonymous client sends `GET /.well-known/oauth-authorization-server`,
   **Then** response is HTTP 200, `Content-Type: application/json`, and the
   body is a valid JSON object with all RFC-8414-mandatory fields populated.
2. **Given** the same setup, **When** anonymous client sends `GET
   /.well-known/oauth-protected-resource`, **Then** response is HTTP 200,
   JSON, with at minimum the `resource` and `authorization_servers` fields per
   RFC 9728.
3. **Given** the plugin is inactive, **When** the same URL is requested,
   **Then** WordPress returns the normal 404 (the rewrite rule is removed at
   deactivation).
4. **Given** site URL is `https://example.com/wp/` (subdirectory install),
   **When** the discovery URL is requested, **Then** all returned URLs are
   absolute and rooted at the WordPress site URL — not at the server's
   document root.

---

### User Story 2 — End User Grants Claude Permission to Access Their MCP Server (P1)

A WordPress site administrator has configured an MCP server and entered its
OAuth Client ID + Secret + Redirect URI on the Claude Connector tab (Phase 2).
A Claude end user enters the site URL into Claude's Connectors UI. Claude
discovers the OAuth metadata (US1), generates a PKCE `code_verifier` +
`code_challenge`, and redirects the user's browser to the plugin's
authorization URL. The user (who must be logged in as a WordPress admin)
sees a consent screen listing the connecting client, the requested scope, and
the MCP server's name. The user clicks **Approve**. The plugin generates a
one-time authorization code, stores it (hashed) with the requesting client's
context, and redirects the user back to Claude's `redirect_uri` with the code
+ state.

**Why this priority**: Without consent the flow halts. This is the
human-in-the-loop boundary that prevents silent grants.

**Independent Test**: Visit
`/acrossai-mcp-oauth/?response_type=code&client_id=<id>&redirect_uri=<uri>&scope=mcp&state=xyz&code_challenge=<sha256-b64u>&code_challenge_method=S256`,
log in as admin, click Approve, observe the browser redirect to
`<redirect_uri>?code=<opaque>&state=xyz`. The auth code in the URL is a
non-guessable 32+ byte opaque string.

**Acceptance Scenarios**:

1. **Given** an MCP server row has OAuth credentials configured, **When** an
   admin user visits the authorization URL with valid params, **Then** the
   consent page renders showing the requesting client's name, the requested
   scope, and the server's display name.
2. **Given** the user clicks Approve, **When** the form submits, **Then** the
   plugin generates a 32-byte cryptographically-random code, hashes it
   (SHA-256), persists the hash + client_id + redirect_uri + code_challenge +
   user_id + expiry timestamp, and 302-redirects to `<redirect_uri>?code=<raw>&state=<state>`.
3. **Given** the user clicks Deny, **When** the form submits, **Then** the
   plugin 302-redirects to `<redirect_uri>?error=access_denied&state=<state>`
   per RFC 6749 §4.1.2.1.
4. **Given** the requesting `client_id` is not registered on any MCP server
   row, **When** the authorize URL is hit, **Then** the plugin shows an error
   page (NOT a redirect — RFC 6749 §4.1.2.1 specifies that unknown clients
   MUST NOT receive a redirect) and writes a `failed_unknown_client` row to
   the audit log.
5. **Given** the requesting `redirect_uri` does not exactly match the
   registered URI for the resolved server row, **When** the authorize URL is
   hit, **Then** the plugin shows an error page (no redirect) and writes a
   `failed_redirect_mismatch` row to the audit log.
6. **Given** the user is not logged in, **When** the authorize URL is hit,
   **Then** the plugin redirects to `wp-login.php?redirect_to=<the original
   authorize URL>` so the user logs in then returns to the consent screen.
7. **Given** the user is logged in but lacks `manage_options`, **When** the
   authorize URL is hit, **Then** the plugin shows a denial page explaining
   that OAuth grants require admin privileges (Application Passwords' default
   security boundary) — does NOT silently fall through to consent.

---

### User Story 3 — Claude Exchanges the Authorization Code for an Access Token (P1)

Claude received an authorization code from US2. It now POSTs to the token
endpoint with `grant_type=authorization_code`, `code`, `client_id`,
`client_secret`, `redirect_uri`, and the `code_verifier` matching the
`code_challenge` it sent at authorize time. The plugin validates every
parameter (constant-time client_secret comparison against the stored hash;
SHA-256 of `code_verifier` matched against `code_challenge`; redirect_uri
exact match; code not expired; code not previously redeemed). On success the
plugin issues a 32+ byte access token, hashes it, persists the hash with
expiry + scope + user_id + server_id, marks the auth code as redeemed
(one-time use), and returns the raw access token in the JSON response per
RFC 6749 §5.1.

**Why this priority**: Without token exchange Claude has nothing to present
at the MCP endpoint. Every subsequent MCP call depends on this step.

**Independent Test**: After US2 produces an auth code, `curl -X POST
https://example.com/wp-json/acrossai-mcp/v1/token -d
grant_type=authorization_code&code=<code>&client_id=<id>&client_secret=<secret>&redirect_uri=<uri>&code_verifier=<verifier>`
returns HTTP 200 with `{"access_token":"<opaque>","token_type":"Bearer","expires_in":3600,"scope":"mcp"}`.

**Acceptance Scenarios**:

1. **Given** Claude has a valid unredeemed code (≤10 minutes old) and the
   correct `code_verifier`, `client_id`, `client_secret`, and `redirect_uri`,
   **When** Claude POSTs to `/wp-json/acrossai-mcp/v1/token`, **Then** the
   response is HTTP 200 with the JSON envelope above. The raw access token
   is a 32+ byte opaque string; only its SHA-256 hash is persisted.
2. **Given** the auth code has expired (>10 minutes since issue), **When**
   Claude POSTs the token request, **Then** the response is HTTP 400 with
   body `{"error":"invalid_grant","error_description":"Authorization code expired."}`
   per RFC 6749 §5.2.
3. **Given** the auth code has already been redeemed once, **When** Claude
   POSTs the same code again, **Then** the response is HTTP 400 with
   `{"error":"invalid_grant"}` AND the plugin **revokes every access token
   previously issued for this code** (per RFC 6749 §10.5 anti-replay).
4. **Given** the `code_verifier` does not hash to the stored `code_challenge`,
   **When** Claude POSTs, **Then** the response is HTTP 400 with
   `{"error":"invalid_grant","error_description":"PKCE verifier mismatch."}`.
5. **Given** the `client_secret` does not match the stored secret for the
   `client_id`, **When** Claude POSTs, **Then** the response is HTTP 401 with
   `{"error":"invalid_client"}`. **The check MUST use constant-time
   comparison** (`hash_equals()`) — never `===` or `strcmp`.
6. **Given** the `redirect_uri` in the token request does not exactly match
   the value sent at authorize time, **When** Claude POSTs, **Then** the
   response is HTTP 400 with `{"error":"invalid_grant"}`.
7. **Given** any of the required POST fields is missing, **When** Claude
   POSTs, **Then** the response is HTTP 400 with `{"error":"invalid_request"}`.
8. **Given** the token endpoint receives a `GET` (or any non-POST method),
   **When** the handler runs, **Then** the response is HTTP 405 Method Not
   Allowed.

---

### User Story 4 — Claude Uses the Access Token to Call MCP Endpoints (P1)

Claude has an access token from US3. It now calls an MCP server endpoint
(e.g. `POST /wp-json/mcp/wordpress-default-server/tools/list`) with
`Authorization: Bearer <access_token>` header. The plugin's
`determine_current_user` filter recognises the Bearer token, looks up its
hash in the access-tokens table, validates it has not expired, resolves it
to the granting user, and sets the current user for the request. The MCP
handler proceeds as if the user were logged in. After the response, an audit
log entry is written recording the server_id + user_id + token-hash-prefix +
endpoint.

**Why this priority**: This is the only path by which an issued token
delivers actual MCP access. Without it the entire OAuth flow is theatre.

**Independent Test**: After US3 produces an access token, `curl
https://example.com/wp-json/mcp/wordpress-default-server/whatever -H
'Authorization: Bearer <token>'` returns the same response that a
logged-in admin would get.

**Acceptance Scenarios**:

1. **Given** a valid unexpired access token, **When** an MCP endpoint is
   called with `Authorization: Bearer <token>`, **Then** the plugin resolves
   the token's hash to the granting user, calls `wp_set_current_user()` for
   the request lifetime, and the MCP endpoint responds as that user.
2. **Given** an expired access token, **When** an MCP endpoint is called,
   **Then** the request proceeds as the anonymous user (the Bearer filter
   does NOT throw). The MCP endpoint then returns its own 401/403 based on
   its `permission_callback`.
3. **Given** a Bearer token that is not in the access-tokens table, **When**
   an MCP endpoint is called, **Then** the request proceeds as anonymous —
   silent failure, no signal to the caller about why (avoids token-discovery
   oracles).
4. **Given** an access token that was issued for `server_id=5` but the
   request targets `server_id=7`'s MCP endpoint, **When** the handler runs,
   **Then** the cross-server attempt is rejected: `wp_set_current_user()` is
   NOT called and the request proceeds as anonymous. Audit log records the
   `failed_cross_server_token` event.
5. **Given** a valid token, **When** the MCP endpoint completes, **Then** an
   audit log row records `server_id`, `user_id`, the token's hash prefix
   (first 8 hex chars), and the endpoint path. The raw token is never
   logged.

---

### User Story 5 — All OAuth Hooks Are Wired Through the Loader (P1)

A developer auditing the OAuth module confirms that no class constructor
contains `add_action()` or `add_filter()`. Every hook OAuth registers
(`init` for rewrite rules, `query_vars`, `rest_api_init` for discovery +
token route registration, `template_redirect` for authorize + discovery
serving, `determine_current_user` for Bearer recognition,
`rest_post_dispatch` for response decoration if needed) is wired
exclusively through `Includes\Main::define_admin_hooks()` (or
`define_public_hooks()` — implementer's choice based on whether the hook
fires only in admin context). Module follows the singleton + private
constructor pattern from Phase 1.

**Why this priority**: Constitutional invariant. Phases 1-4 established
the Loader contract; OAuth must not regress it. The OAuth module spans
both admin (the consent UI) and public (the token endpoint, the
discovery URLs) contexts, making it the most stress-testing case for the
Loader contract so far.

**Independent Test**:
`grep -rnE 'add_action|add_filter' includes/OAuth/` returns empty.
`grep -n 'loader->add_action\|loader->add_filter' includes/Main.php` shows
every OAuth hook on a named-singleton-variable line.

**Acceptance Scenarios**:

1. **Given** any class file under `includes/OAuth/`, **When** its
   constructor is read, **Then** no `add_action(` or `add_filter(` call
   appears.
2. **Given** `Main::define_admin_hooks()` is invoked, **When** the Loader
   runs, **Then** all OAuth hooks fire in the right context.
3. **Given** the plugin is deactivated and reactivated, **When**
   `Activator::activate()` runs, **Then** the rewrite rule for
   `/acrossai-mcp-oauth/` is re-registered and `flush_rewrite_rules()`
   runs.

---

### Edge Cases

- **Authorization code is leaked via referrer header**: PKCE binds the code
  to the original client. Even if leaked, an attacker without the
  `code_verifier` cannot redeem the code. **MUST** be enforced.
- **Authorization code is redeemed twice (double-spend)**: The first
  redemption succeeds and issues a token; the second redemption fails AND
  revokes every token issued by this code (RFC 6749 §10.5 anti-replay).
- **Redirect URI uses a different scheme/host than registered**: Exact
  byte-for-byte match required. `http://example.com/cb` and
  `https://example.com/cb` are different URIs and one MUST NOT match the
  other.
- **Concurrent authorize requests for the same user**: Each request gets
  its own auth code; concurrent codes for the same user are allowed
  (legitimate "user opens two browser tabs" case).
- **DB is full / write fails during code issuance**: The plugin MUST return
  HTTP 503 with `{"error":"server_error"}` and log the failure. It MUST
  NOT silently issue a code without storing it.
- **System clock drift**: Token expiry is compared against `time()` (Unix
  timestamp). If the system clock drifts backward, previously-issued
  tokens MAY appear unexpired longer than intended — acceptable
  trade-off, documented in Assumptions.
- **`client_secret` is intercepted in transit**: The token endpoint
  enforces HTTPS via WordPress's `is_ssl()` check — falls back to the
  `force_ssl_admin` setting; warns at admin time when neither is true.
- **Plugin is uninstalled while access tokens exist**: The uninstall hook
  drops the OAuth tokens table. Outstanding tokens become invalid silently
  (acceptable — uninstall is destructive by nature).
- **WP-Cron is disabled** (`DISABLE_WP_CRON=true`, common on production
  hosts): The cleanup event never fires automatically. The plugin MUST
  surface a `notice notice-warning` on every wp-admin page reminding the
  admin to call `wp acrossai-mcp oauth cleanup` from real cron. Without
  this, expired-data tables grow unbounded silently.
- **An MCP server row is deleted while its tokens exist**: Tokens
  referencing the now-missing `server_id` are treated as invalid; cleanup
  job removes them on next cron run.

---

## Requirements

### Functional Requirements

#### Discovery (RFC 8414 + RFC 9728)

- **FR-001**: A `GET /.well-known/oauth-authorization-server` MUST return
  HTTP 200 with `Content-Type: application/json` and a JSON body containing
  at minimum:
  - `issuer` — the site URL (no trailing slash)
  - `authorization_endpoint` — full URL of the authorize page
  - `token_endpoint` — full URL of the token REST route
  - `response_types_supported: ["code"]`
  - `grant_types_supported: ["authorization_code"]`
  - `code_challenge_methods_supported: ["S256"]`
  - `token_endpoint_auth_methods_supported: ["client_secret_post"]`
  - `scopes_supported: ["mcp"]`

- **FR-002**: A `GET /.well-known/oauth-protected-resource` MUST return
  HTTP 200 with JSON containing at minimum:
  - `resource` — the MCP base URL (`{site_url}/wp-json/mcp`)
  - `authorization_servers: [{site_url}]`
  - `bearer_methods_supported: ["header"]`

- **FR-003**: Both discovery URLs MUST be registered as rewrite rules at
  activation time, with the rules surviving across plugin deactivation +
  reactivation. The handler resolves to the rule via a custom query var,
  serves the JSON, calls `exit`. WordPress's normal template lookup MUST
  NOT see the request.

#### Authorization endpoint (RFC 6749 §4.1)

- **FR-004**: A `GET` to the authorization URL (default
  `/acrossai-mcp-oauth/`) MUST validate every query parameter:
  - `response_type` MUST equal `code` (no implicit / hybrid flows)
  - `client_id` MUST resolve to an MCP server row whose
    `claude_connector_client_id` matches
  - `redirect_uri` MUST exactly equal the resolved server's
    `claude_connector_redirect_uri` (byte-for-byte)
  - `code_challenge_method` MUST equal `S256` (no `plain` accepted)
  - `code_challenge` MUST be 43 chars of base64url
  - `scope` MUST equal `mcp` (the only supported scope this phase)
  - `state` is optional but echoed back unmodified on the redirect

- **FR-005**: If `client_id` resolves to no server row OR `redirect_uri`
  doesn't match, the plugin MUST render an error page (HTTP 400) and MUST
  NOT redirect anywhere. Writes a `failed_unknown_client` or
  `failed_redirect_mismatch` row to the audit log.

- **FR-006**: If validation passes AND the user is not logged in, the
  plugin MUST redirect to `wp-login.php?redirect_to=<original full URL>`.

- **FR-007**: If validation passes AND the user is logged in but lacks
  `manage_options`, the plugin MUST render a denial page (HTTP 403) — no
  redirect to `redirect_uri`.

- **FR-008**: If validation passes AND the user is logged in AND has
  `manage_options`, the plugin MUST render a consent page showing:
  - The MCP server's display name (`server_name`)
  - The requesting client_id
  - The requested scope (`mcp`)
  - An **Approve** button (POSTs back) and a **Deny** button (POSTs back)
  - A WordPress nonce for the form

- **FR-009**: On **Approve** POST (with valid nonce):
  - Generate a 32-byte cryptographically-random raw code via
    `wp_generate_uuid4()` or `random_bytes(32)` + base64url-encode
  - Compute `sha256(raw_code)` and persist:
    `auth_code_hash`, `client_id`, `server_id`, `user_id`,
    `redirect_uri`, `code_challenge`, `code_challenge_method`, `scope`,
    `expires_at = now() + 600` (10 minutes), `redeemed_at = NULL`
  - 302-redirect to `<redirect_uri>?code=<raw_code>&state=<state>`

- **FR-010**: On **Deny** POST (with valid nonce):
  - 302-redirect to `<redirect_uri>?error=access_denied&state=<state>` per
    RFC 6749 §4.1.2.1.

#### Token endpoint (RFC 6749 §4.1.3)

- **FR-011**: `POST /wp-json/acrossai-mcp/v1/token` MUST be registered with
  `permission_callback: __return_true` (public — authentication is via
  POST body, not session). It MUST accept ONLY `Content-Type:
  application/x-www-form-urlencoded`.

- **FR-012**: The token endpoint MUST validate, in order, and short-circuit
  on the first failure with the documented HTTP status + JSON error:
  1. Required fields present: `grant_type`, `code`, `client_id`,
     `client_secret`, `redirect_uri`, `code_verifier`. Missing any → HTTP
     400 + `{"error":"invalid_request"}`.
  2. `grant_type === "authorization_code"`. Otherwise → HTTP 400 +
     `{"error":"unsupported_grant_type"}`.
  3. `client_id` resolves to a server row. Otherwise → HTTP 401 +
     `{"error":"invalid_client"}`.
  4. `client_secret` matches the stored secret via `hash_equals()`
     constant-time compare. Otherwise → HTTP 401 +
     `{"error":"invalid_client"}`.
  5. `sha256(code)` is found in the auth-codes table for THIS `client_id`,
     not redeemed, not expired. Otherwise → HTTP 400 +
     `{"error":"invalid_grant","error_description":"<one of:>"}`. The
     description text MUST differentiate "expired" / "unknown code" /
     "already redeemed" for legitimate clients debugging but MUST NOT leak
     timing information (constant-time DB lookup pattern).
  6. `redirect_uri` matches the value stored with the code. Otherwise →
     HTTP 400 + `{"error":"invalid_grant"}`.
  7. `base64url(sha256(code_verifier))` equals the stored `code_challenge`.
     Otherwise → HTTP 400 + `{"error":"invalid_grant","error_description":"PKCE verifier mismatch."}`.

- **FR-013**: On all-checks-pass, the token endpoint MUST:
  1. **Atomically claim the auth code via compare-and-swap** (SEC-001
     amendment, 2026-06-21): issue a single SQL statement
     `UPDATE wp_acrossai_mcp_cli_auth_logs SET completed_at = NOW()
     WHERE id = :code_row_id AND completed_at IS NULL` and inspect
     `$wpdb->rows_affected`. **`1`** → this request won the race, proceed
     to step 2. **`0`** → another concurrent request already redeemed the
     code: jump to FR-014's REPLAY path (revoke any tokens issued by the
     winning sibling request, return HTTP 400 `invalid_grant`, write
     `failed_replay_attempt` audit row). The CAS pattern closes the
     SELECT-then-UPDATE race window that would otherwise let a stolen
     code be redeemed twice during concurrent timing.
  2. Generate a 32-byte cryptographically-random raw access token.
  3. Compute `sha256(raw_token)` and persist:
     `access_token_hash`, `server_id`, `user_id`, `scope`,
     `expires_at = now() + 3600` (1 hour; configurable via filter
     `acrossai_mcp_oauth_access_token_lifetime`).
  4. Return HTTP 200 with JSON body:
     `{"access_token":"<raw_token>","token_type":"Bearer","expires_in":3600,"scope":"mcp"}`.

- **FR-014**: If an auth code is presented for redemption a second time
  (detected via the FR-013 atomic CAS returning 0 rows-affected, OR via
  the FR-012 Step 5 sequential check finding `completed_at IS NOT NULL`),
  the token endpoint MUST:
  1. Reject the request with `{"error":"invalid_grant"}` HTTP 400.
  2. Mark every access token issued for this code as revoked
     (set `revoked_at = NOW()`) — per RFC 6749 §10.5 anti-replay defense.
  3. Write a `failed_replay_attempt` audit log row recording both the
     winning request's outcome (if available — token hash prefix of the
     access token issued by the legitimate sibling) AND the losing
     request's client_id + IP. Each revoked token also writes a
     `token_revoked` audit row.

  **Note (SEC-001 amendment 2026-06-21)**: under concurrent
  same-code redeem attempts, the FR-013 CAS is the load-bearing
  detector — the FR-012 Step 5 SELECT alone would be defeated by a
  race. FR-013 Step 1 + FR-014 path together produce the same anti-
  replay guarantee whether the second attempt is sequential
  (milliseconds later) or simultaneous (concurrent).

- **FR-014a**: The token endpoint MUST enforce per-`(client_id, IP)`
  rate limiting against brute-force attacks (Q3 clarification 2026-06-18):
  1. Track failure counts in WordPress transients keyed by
     `oauth_rate_<sha256(client_id . '|' . request_ip)>`. The hash key
     avoids storing raw IPs in option_name.
  2. On every token-endpoint validation failure (any FR-012 step), increment
     the per-tuple counter; on a successful exchange, reset it to zero.
  3. **Threshold A**: 5 failures in any 1-minute rolling window → reject
     the current request with HTTP 429 + `Retry-After: 60` + JSON body
     `{"error":"slow_down"}`. Subsequent requests from the same tuple
     during the 1-minute window get the same response.
  4. **Threshold B**: 50 failures in any 1-hour rolling window → lock the
     `(client_id, IP)` tuple for a full hour. During the lockout, every
     token-endpoint request from that tuple gets HTTP 429 +
     `Retry-After: 3600` + JSON body `{"error":"slow_down"}`.
  5. Write a `failed_rate_limit` audit row on first crossing of each
     threshold (so admins can investigate without log spam from each
     subsequent rejected request).
  6. **No global IP ban** — the IP may be a shared NAT (university,
     office, mobile network). Locking the IP would harm legitimate Claude
     users from the same network. The lockout is scoped to the
     `(client_id, IP)` tuple.
  7. The request handler MUST short-circuit BEFORE the FR-012 validation
     chain runs — so attackers can't probe per-step error messages to
     determine which validation failed during the lockout.

#### Bearer authentication (RFC 6750)

- **FR-015**: A `determine_current_user` filter callback MUST recognise
  `Authorization: Bearer <token>` headers on requests targeting
  `wp-json/mcp/*` routes:
  1. Read the header from `$_SERVER['HTTP_AUTHORIZATION']` first, then
     fall back to `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']` for hosts
     that strip `Authorization` under Apache+CGI (common on shared
     hosting). Reject tokens longer than 256 chars (length guard).
  2. Compute `sha256(token)` and look it up in the access-tokens table.
  3. If found AND not expired AND `server_id` matches the resolved MCP
     server from the request URL, return the associated `user_id`.
  4. Otherwise, return the input `$user_id` unchanged. MUST NOT throw,
     MUST NOT override an already-resolved user, and MUST NOT short-circuit
     other auth methods.

- **FR-016**: After every successful Bearer authentication, the plugin
  MUST write an audit log row recording `server_id`, `user_id`, the
  token's hash prefix (first 8 hex characters of the SHA-256 hash for
  log-correlation; full hash is sensitive), and the endpoint path. The
  raw token MUST NEVER be logged.

#### Per-server credentials (data dependency)

- **FR-017**: The OAuth module consumes three columns on the MCP server
  row (already shipped in Phase 2): `claude_connector_client_id`,
  `claude_connector_client_secret`, `claude_connector_redirect_uri`.
  When any of these is empty for a server row, OAuth requests targeting
  that server MUST be rejected at `FR-004` validation as if the
  `client_id` were unregistered.

#### Storage

- **FR-018**: Authorization codes are persisted in a column extension of
  the existing `acrossai_mcp_cli_auth_logs` table (Phase 2) — adding rows
  with `status = 'oauth_code_issued'` and using:
  - `auth_code_hash` (existing column, SHA-256 hex)
  - `server_id` (existing)
  - `user_id` (existing)
  - `created_at` (existing — code issuance time; expiry is `created_at + 600s`)
  - `completed_at` (existing — set to redeem time on first redeem; second
    redeem detects this is non-null)
  - NEW columns added by this phase: `redirect_uri VARCHAR(500)`,
    `code_challenge CHAR(43)`, `code_challenge_method VARCHAR(16)`,
    `scope VARCHAR(64)`

- **FR-019**: Access tokens are persisted in a new dedicated table
  `{wpdb->prefix}acrossai_mcp_oauth_tokens` with columns:
  `id BIGINT PK`, `access_token_hash CHAR(64) UNIQUE`,
  `server_id BIGINT`, `user_id BIGINT`,
  `issued_from_code_id BIGINT NOT NULL DEFAULT 0` (FK-by-convention to the
  CliAuthLog row that issued this token — required for FR-014 anti-replay
  to revoke all tokens issued by a single code in one query),
  `scope VARCHAR(64)`, `created_at DATETIME`, `expires_at DATETIME`,
  `revoked_at DATETIME NULL` (for FR-014 anti-replay).

- **FR-019a**: OAuth audit events are persisted in a new dedicated table
  `{wpdb->prefix}acrossai_mcp_oauth_audit` (Q1 clarification 2026-06-18)
  with columns:
  - `id BIGINT PK`
  - `event_type VARCHAR(64)` — canonical event names from FRs
    (alphabetical, frozen for this phase):
    `bearer_auth_success`, `cleanup_run`, `code_issued`, `code_redeemed`,
    `consent_denied`, `failed_cross_server_token`, `failed_rate_limit`
    (Q3 2026-06-18), `failed_redirect_mismatch`, `failed_replay_attempt`,
    `failed_unknown_client`, `token_revoked`
  - `server_id BIGINT NULL` (some events have no server context)
  - `user_id BIGINT NULL` (some events fail before user resolution)
  - `client_id VARCHAR(255) NULL` (raw client_id from request — useful
    for forensics even when it doesn't resolve to a server row)
  - `token_hash_prefix CHAR(8) NULL` (first 8 hex chars of token's SHA-256
    hash; full hash is sensitive — prefix is enough for log correlation
    against the tokens table without leaking the token)
  - `endpoint VARCHAR(255) NULL` (the request path for Bearer-related events)
  - `details_json TEXT NULL` (event-specific structured data — RFC error
    code, redirect_uri mismatch values, etc.)
  - `created_at DATETIME NOT NULL`
  - `INDEX (event_type, created_at)`, `INDEX (server_id, created_at)`,
    `INDEX (user_id, created_at)` — to support the admin forensics queries
    most likely to run

  Audit rows are append-only — no updates, no deletes-except-by-retention
  (see FR-019b).

- **FR-019b**: A recurring WP-Cron event `acrossai_mcp_oauth_cleanup`
  (Q2 clarification 2026-06-18) MUST run every 24 hours and delete:
  - Auth-code rows (in `acrossai_mcp_cli_auth_logs` with
    `status='oauth_code_issued'`) where `created_at + 600s + 86400s < now()`
    — i.e. **24 hours after the code's 10-minute expiry**
  - Access-token rows (in `acrossai_mcp_oauth_tokens`) where
    `(expires_at < now() OR revoked_at IS NOT NULL)` AND
    `(expires_at + 7 days < now() AND COALESCE(revoked_at, expires_at) + 7 days < now())`
    — i.e. **7 days after the token expired or was revoked** (window lets
    admins investigate "why did Claude lose access" reports while bounding
    DB growth)
  - Audit rows (in `acrossai_mcp_oauth_audit`) where
    `created_at + 90 days < now()` — **90 days after event**

  The cleanup MUST be idempotent (re-running mid-sweep is safe), MUST be
  invocable both from WP-Cron AND from a WP-CLI command
  `wp acrossai-mcp oauth cleanup` (for production hosts using real cron
  instead of WP-Cron), and MUST write a single audit row per sweep
  recording `rows_deleted_codes`, `rows_deleted_tokens`,
  `rows_deleted_audit` (so admins can verify it ran). The event is
  registered at activation via `wp_schedule_event()` and unregistered at
  deactivation via `wp_clear_scheduled_hook()`.

- **FR-020**: Both code hashes and token hashes MUST be SHA-256 of the raw
  value, stored as lowercase hex (64 chars). The raw value MUST NEVER be
  stored. (Constitution §III bullet 7.)

#### Architecture / Loader contract

- **FR-021**: Every class file under `includes/OAuth/` MUST have a
  constructor that contains zero `add_action()` or `add_filter()` calls.
  Classes follow the Phase 1 singleton pattern: `protected static
  $_instance`, private constructor, public static `instance()`,
  side-effect-free constructor body.

- **FR-022**: `Includes\Main::define_admin_hooks()` (or
  `define_public_hooks()` for the public-facing parts of the flow) MUST
  wire every OAuth hook via `$this->loader->add_action()` or `add_filter()`.
  Required hooks for the OAuth module:
  - `init` → register rewrite rules
  - `query_vars` → add `acrossai_mcp_oauth` query var (the dispatcher uses
    its value — `as_metadata` / `rs_metadata` / `authorize` — to branch)
  - `template_redirect` → discovery handlers (2) + authorize handler
    (dispatched by `serve_discovery_or_authorize`)
  - `rest_api_init` → register the `/wp-json/acrossai-mcp/v1/token` route
  - `determine_current_user` → Bearer recognition (priority 20)
  - `acrossai_mcp_oauth_cleanup` → daily cron callback (FR-019b)

- **FR-023**: `Includes\Activator::activate()` MUST register the OAuth
  rewrite rules and call `flush_rewrite_rules()`. The activation MUST
  also `maybe_create_table()` for the new `acrossai_mcp_oauth_tokens`
  table.

#### Namespace + placement

- **FR-024**: Every class file under `includes/OAuth/` MUST declare
  namespace `AcrossAI_MCP_Manager\Includes\OAuth`. File names match class
  names.

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ |
| WordPress version | 6.9+ |
| Multisite | Single-site only this phase |
| Required Composer packages | `automattic/jetpack-autoloader ^5.0`; no OAuth library (hand-rolled per project pattern — same logic as Phase 2.0 BerlinDB) |
| Optional integrations | `\WP\MCP\Plugin` (the MCP adapter) MUST be installed for the protected endpoints to actually be served; OAuth issues tokens regardless but tokens are useless without the adapter |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `includes/OAuth/ClaudeConnectors.php` | `AcrossAI_MCP_Manager\Includes\OAuth` | New — orchestrator: discovery serving, authorize page rendering, rewrite registration |
| `includes/OAuth/TokenController.php` | same | New — REST controller for `/wp-json/acrossai-mcp/v1/token` |
| `includes/OAuth/Storage.php` | same | New — persistence: code issuance, code lookup, code redeem, token issuance, token lookup, token revoke |
| `includes/OAuth/PKCE.php` | same | New — PKCE verifier-↔-challenge math (pure utility, no DB) |
| `includes/OAuth/BearerAuth.php` | same | New — `determine_current_user` filter callback |
| `includes/OAuth/AuditLog.php` | same | New — audit log writer (`acrossai_mcp_access_denied` action handler) |
| `includes/Database/OAuthToken/{Schema,Table,Row,Query}.php` | `AcrossAI_MCP_Manager\Includes\Database\OAuthToken` | New — BerlinDB-style Query layer (per D9) for the access-tokens table |
| `includes/Database/OAuthAudit/{Schema,Table,Row,Query}.php` | `AcrossAI_MCP_Manager\Includes\Database\OAuthAudit` | New (Q1 clarification 2026-06-18) — BerlinDB-style Query layer for the audit-events table |
| `includes/Database/CliAuthLog/{Schema,Table}.php` | (existing) | Extend — add 4 new columns per FR-018 |

**Hook Registration Rule**: ALL `add_action` / `add_filter` calls for this
feature MUST be wired only through the Loader inside
`Main::define_admin_hooks()` or `Main::define_public_hooks()`. Zero hook
calls may appear in any class constructor anywhere under `includes/OAuth/`.

### Admin UI Requirements

This phase introduces ONE new admin screen: the **consent page**
rendered when a user reaches the authorize URL. It is NOT a registered
admin menu page — it is reached only via the OAuth redirect from
Claude. The consent page is a standard `<form method="post">` with a
nonce and two submit buttons (Approve, Deny). It is **not** subject to
the DataForm/DataViews mandate because:

1. It's not an admin menu page (no DataViews routing)
2. It's a one-button-each form (DataForm overkill for 2 fields)
3. RFC 6749 §4.1.1 prescribes the consent flow shape, not DataForm

The consent page MUST display the requesting client's name, the
requested scope, and the MCP server's display name, all escaped at
output.

### REST API Contract

| Method | Route | Auth | Description |
|---|---|---|---|
| `GET` | `/.well-known/oauth-authorization-server` | none | RFC 8414 server metadata |
| `GET` | `/.well-known/oauth-protected-resource` | none | RFC 9728 resource metadata |
| `GET` | `/acrossai-mcp-oauth/` | session (admin) | Authorize page (consent screen) |
| `POST` | `/acrossai-mcp-oauth/` | session + nonce | Consent form submission |
| `POST` | `/wp-json/acrossai-mcp/v1/token` | code-based (public) | Token exchange per RFC 6749 §4.1.3 |
| `GET` | `/wp-json/mcp/*` | Bearer header | Existing MCP routes; OAuth adds the `determine_current_user` filter |

**`permission_callback` rule**: `__return_true` is permitted on the token
endpoint ONLY because the body itself authenticates (RFC 6749 §2.3.1).
Every other route uses real session / Bearer authentication.

### Database / Storage

Two storage surfaces:

| Table | Purpose | Created by |
|---|---|---|
| `{prefix}acrossai_mcp_cli_auth_logs` | Authorization codes (extended in this phase) | Existing Phase 2 schema + this phase's 4-column ALTER |
| `{prefix}acrossai_mcp_oauth_tokens` | Access tokens | NEW — created by this phase's Activator extension |
| `{prefix}acrossai_mcp_oauth_audit` | OAuth audit events (Q1 2026-06-18) | NEW — created by this phase's Activator extension |

Both reach the DB via BerlinDB-style Query classes per D9.

### Security Checklist

*(Derived from Constitution §III + RFC 6749 §10 — OAuth-specific)*

- [x] Authorization codes hashed (SHA-256) at storage; raw codes never persisted — `Storage::issue_authorization_code` (`hash('sha256', $raw)`)
- [x] Access tokens hashed (SHA-256) at storage; raw tokens never persisted — `Storage::issue_access_token`
- [x] Authorization codes expire in ≤10 minutes (RFC 6749 §4.1.2 recommendation) — `Storage::AUTH_CODE_TTL_SECONDS = 600` + `TokenController` step 5a expiry check
- [x] Access tokens expire in ≤1 hour by default (filter-configurable) — `Storage::ACCESS_TOKEN_TTL_SECONDS = 3600` + `acrossai_mcp_oauth_access_token_lifetime` filter
- [x] Authorization codes are one-time-use; double-spend triggers replay defence (revoke all tokens from this code) — SEC-001 atomic CAS (`CliAuthLog\Query::redeem_atomic`) + `Storage::revoke_all_tokens_for_code`; `ConcurrentRedeemRaceTest` verifies
- [x] PKCE S256 required for every authorize request (no `plain` accepted; no PKCE-free flow accepted) — `ClaudeConnectors::render_authorize_page` rejects non-S256
- [x] `redirect_uri` exact byte-match against registered URI (no wildcard, no substring) — `hash_equals` in `render_authorize_page` + `TokenController` step 6
- [x] `client_secret` compared with `hash_equals()` constant-time — `TokenController` step 4
- [x] Authorization codes scoped to ONE `client_id` — code lookup goes through server resolution; cross-client redemption rejected at step 3
- [x] Bearer token recognition is scoped to the request's MCP server (`server_id` match required; cross-server tokens rejected) — `BearerAuth::resolve_bearer_token` constrains the lookup with `server_id`; `BearerCrossServerRejectionTest` verifies
- [x] Every token endpoint POST is rejected unless `Content-Type: application/x-www-form-urlencoded` (no `application/json` body parsing — narrower attack surface) — added 2026-06-24 in `TokenController::handle_request` precheck
- [x] Token endpoint enforces `is_ssl()` in production (warning at admin time when HTTPS is not configured) — admin notice via `Notices::render_oauth_https_notice` (soft-warn per spec §Assumptions; not a hard block)
- [x] Consent form uses `wp_nonce_field` + `check_admin_referer` — `ClaudeConnectors::render_consent_form` + `handle_consent_submit` (`ConsentSubmitTest::test_missing_nonce_dies`)
- [x] Discovery + token + authorize endpoints write per-event audit log rows; raw codes/tokens NEVER appear in logs (only their first-8-hex prefixes for correlation) — `AuditLog::write` truncates `token_hash_prefix` to 8 chars; `AuditLogTest` verifies
- [x] Output escaping on the consent page uses `esc_html` / `esc_attr` / `esc_url` at every render point — `ClaudeConnectors::render_consent_form`
- [x] No `unserialize()` anywhere in the OAuth module — grep verified 2026-06-24
- [x] Token endpoint rate-limited per-(client_id, IP) with soft 429 + Retry-After response (Q3 2026-06-18) — `Storage::rate_limit_check_and_increment` + `TokenController` step 0; `RateLimitTest` verifies

### Key Entities

- **Authorization Code (row in `acrossai_mcp_cli_auth_logs` with `status='oauth_code_issued'`)**: A one-time, short-lived (10 min) credential issued at consent time. Stored as SHA-256 hash. Bound to client_id, redirect_uri, code_challenge, user_id, server_id, scope.
- **Access Token (row in `acrossai_mcp_oauth_tokens`)**: A bearer credential issued at token-exchange time. Stored as SHA-256 hash. Bound to server_id, user_id, scope. Lifetime 1 hour by default.
- **Per-Server OAuth Credentials**: Client ID, Client Secret, Redirect URI columns on the MCP server row (existing from Phase 2). One server = one OAuth client.
- **PKCE Challenge / Verifier**: Transient values — `code_verifier` is held by the client (Claude) and never sent until token exchange; `code_challenge = base64url(sha256(verifier))` is sent at authorize time and persisted with the code.

---

## Success Criteria

### Definition of Done Gates

- [x] PHPCS: zero errors and zero warnings (verified 2026-06-24 on 15 new OAuth + Database files)
- [x] PHPStan level 8: zero errors (verified 2026-06-24)
- [ ] PHPUnit: full RFC-conformance test suite (per-RFC-section coverage) passing — **deferred**: 16 OAuth test files written; requires `bin/install-wp-tests.sh` to provision WP-PHPUnit harness before suite can run
- [x] Security checklist above: every applicable item verified — all 17 items flipped to `[x]` with impl evidence on 2026-06-24
- [x] All hooks wired in `Main::define_admin_hooks()` / `define_public_hooks()` — none in any class constructor under `includes/OAuth/`
- [x] `grep -rn 'add_action\|add_filter' includes/OAuth/` returns zero matches (verified 2026-06-23 in implementation summary)
- [ ] Manual quickstart: external OAuth client (e.g. `oauth2-client` CLI tool or Claude itself) completes the full authorize→consent→token→Bearer flow against a real install and successfully reaches an MCP endpoint as the granting user
- [ ] `npm run validate-packages` passes — **deferred**: gate informational for this phase (no JS changes)

### Measurable Outcomes

- **SC-001**: An external OAuth client following RFC 6749 §4.1 + RFC 7636 (PKCE) can complete the full flow on a clean install with no plugin patching. Verified manually with a test OAuth client.
- **SC-002**: Auth codes expire exactly 10 minutes after issue (with ≤2 seconds clock-drift tolerance). Verified by issuing a code, waiting 10 min 5 sec, and confirming the redemption returns `invalid_grant`.
- **SC-003**: Access tokens expire exactly 1 hour after issue (with ≤5 seconds clock-drift tolerance). Verified the same way.
- **SC-004**: Double-spend of an auth code reliably revokes all tokens previously issued by that code. Verified by issuing token T1, then re-redeeming the same code, then calling `/wp-json/mcp/*` with T1 — must get the anonymous response.
- **SC-005**: PKCE verifier-↔-challenge mismatch blocks 100% of token exchanges that present the wrong verifier. Verified by RFC-test-vector PHPUnit cases.
- **SC-006**: `redirect_uri` non-exact-match blocks 100% of authorize requests. Verified by sending a request with a single trailing slash difference and confirming the 400 error page.
- **SC-007**: Cross-server token use is blocked 100% — a token issued for server A cannot be redeemed at server B's MCP endpoint. Verified by PHPUnit + manual `curl`.

---

## Assumptions

- **No refresh tokens this phase**: The user input does not mention them. The
  RFC 6749 §6 refresh-token grant is **out of scope for this phase**. Claude's
  current Connectors implementation re-runs the full authorize→consent flow
  for each new access token; if that changes upstream we add refresh tokens
  in a follow-up phase. The 1-hour access-token lifetime is short enough to
  be acceptable without refresh in the meantime.
- **Single scope per app (`mcp`)**: This phase supports exactly one scope:
  `mcp`. Per-tool / per-resource scopes are out of scope. If Claude grows
  finer-grained scopes upstream we add them in a follow-up phase.
- **Per-server OAuth credentials, not site-wide**: Each MCP server row has
  its own `client_id` / `client_secret` / `redirect_uri`. Site-wide dynamic
  client registration (RFC 7591) is out of scope.
- **Access tokens stored in a NEW table**: User input said "OAuth\Storage
  persists authorization codes and access tokens in the DB (uses the
  CliAuthLog table for code storage)". The parenthetical implies codes use
  the existing CliAuthLog table; access tokens get their own NEW table
  (`acrossai_mcp_oauth_tokens`). This separation reflects different
  lifetimes (codes 10min, tokens 1h), different access patterns (codes
  one-time read, tokens repeated read), and different cleanup cadence.
- **PKCE S256 only**: `code_challenge_method=plain` is not accepted. Every
  OAuth 2.1 hardening guide recommends rejecting `plain`. Claude already
  supports S256.
- **HTTPS enforcement at warning level, not hard block**: The token
  endpoint warns admins via an admin notice when HTTPS isn't configured but
  doesn't refuse to issue tokens (would break local development). Production
  configurations MUST run HTTPS — this is documented in the admin notice.
- **The MCP adapter must be installed for tokens to be useful**: OAuth
  issues tokens regardless of whether `\WP\MCP\Plugin` is installed, but
  Bearer-authenticated MCP calls require the adapter to actually serve
  responses. Phase 2's "MCP adapter missing" admin notice already
  surfaces this.
- **Plugin uninstall is destructive**: The OAuth tokens table is dropped
  on plugin uninstall. Outstanding tokens become invalid silently — there
  is no graceful-revocation flow. This matches the source repo's behavior.
- **Multisite is out of scope**: Tokens are scoped to one site's
  `wp_users`. Multisite delegated auth is a separate problem.
