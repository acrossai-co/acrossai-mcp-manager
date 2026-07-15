# Feature Specification: REST API — CLI Authentication Controller

**Feature Number**: 006
**Feature Branch**: `006-rest-cli-auth`
**Created**: 2026-06-25
**Status**: Draft
**Spec**: `specs/006-rest-cli-auth/spec.md`
**Input**: User description: "REST API — CLI Authentication Controller (5 endpoints + static approval method)"

---

## Clarifications

### Session 2026-06-25

- Q: Where do the `record_approved` / `record_success` audit helpers live? → A: New `Includes\Database\CliAuthLog\Recorder` helper class (static methods only, A11-style stateless helper). Internally calls `( new Query() )->add_item(...)`. Respects Phase 2's BerlinDB Query boundary.
- Q: Strict Content-Type policy on `/auth/start` and `/auth/exchange`? → A: Allow EITHER `application/json` OR `application/x-www-form-urlencoded`. Reject missing or any other Content-Type with HTTP 400 `{"error":"invalid_request"}`. Narrower attack surface than Phase 5's form-urlencoded-only token endpoint (because this phase's caller base is first-party CLI tooling where JSON is more ergonomic), but the missing-header rejection inherits Phase 5 SEC-002's defense.
- Q: Application Password naming when the same admin authorizes the same server twice? → A: Append the auth code's first 8 hex chars as a uniqueness suffix — `"AcrossAI MCP Manager CLI - <server_slug> - <code_prefix>"`. Always unique, forensically correlatable to the `auth_code_hash` audit row (the prefix is non-secret because the full code is deleted by the time the App Password name is visible). Eliminates the "which one is current?" admin UX friction.
- Q: Session token scope — bound to the consented `server_id` or not? → A: **BIND** the session token to the consented `server_id`. `/servers` returns ONLY that one server's metadata when called with the bound session token (or `[]` if AccessControlManager filters it out). Matches Phase 5 FR-015 cross-server defense pattern. Eliminates the server-enumeration vector that the original "not bound" assumption introduced.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — CLI Tool Discovers a Site's MCP Capability (Priority: P1)

A developer runs a command-line tool (e.g. an MCP-aware Claude Code shim, a custom CLI, or a one-off `curl` invocation) against a WordPress site. Before initiating any authentication exchange, the tool needs to know that the AcrossAI MCP Manager plugin is installed, active, and responding — and it needs the site's canonical slug to use as an identifier in subsequent calls and logs.

**Why this priority**: Discovery is the first network call any CLI integration makes. Without a reliable health endpoint, every downstream failure looks ambiguous — "is the plugin missing? Is the site down? Is my URL wrong?"

**Independent Test**: `curl https://example.com/wp-json/acrossai-mcp-manager/v1/health` returns HTTP 200 with a JSON body containing `plugin_installed`, `plugin_active`, `version`, and `site_slug`. The slug is `sanitize_title(get_bloginfo('name'))`.

**Acceptance Scenarios**:

1. **Given** the plugin is active, **When** anonymous client `GET`s `/health`, **Then** response is HTTP 200 with `{"plugin_installed": true, "plugin_active": true, "version": "0.0.1", "site_slug": "example-site"}`.
2. **Given** the plugin is deactivated, **When** the same URL is requested, **Then** WordPress returns the normal 404 (the REST routes aren't registered when the plugin doesn't boot).
3. **Given** the site name is `"Example Site — Production"`, **When** `/health` is requested, **Then** `site_slug` is `"example-site-production"` (per `sanitize_title()` semantics).

---

### User Story 2 — CLI Tool Initiates a Browser-Mediated Authentication Flow (Priority: P1)

The CLI tool needs to authenticate against the site as a specific admin user — but the tool itself runs in a terminal with no browser session. The standard pattern is "device-code-grant-style" handoff: the CLI requests an `auth_code`, prints an authorization URL to the user, and asks the user to visit that URL in a browser where they are (or will become) logged in as an admin. The user clicks **Approve** in the browser; the CLI then polls for approval.

**Why this priority**: Without this initiation step, the CLI has no handle to track the consent state. Every subsequent call needs an `auth_code` that the user can correlate to "the request I just approved".

**Independent Test**: `POST /wp-json/acrossai-mcp-manager/v1/auth/start` with body `server_id=some-server` returns HTTP 200 with `{"auth_code": "<32-hex-chars>", "auth_url": "https://example.com/acrossai-mcp-manager/?action=cli_auth&code=<code>&server=<server_id>", "expires_in": 300}`. A corresponding pending-state transient exists in WP's options/transient table.

**Acceptance Scenarios**:

1. **Given** the plugin is active, **When** anonymous client `POST`s `/auth/start` with `server_id=wordpress-default-server`, **Then** response is HTTP 200 with a cryptographically-random `auth_code` (32 hex chars / 16 bytes), an `auth_url` that opens the FrontendAuth approval page, and `expires_in: 300`.
2. **Given** the same call, **When** the implementation inspects WP transients, **Then** a transient `acrossai_cli_auth_<code>` exists with `{server_id, status: 'pending', user_id: null, session_token: null, created_at: <unix>}` and TTL 300 seconds.
3. **Given** `server_id` is missing or empty in the POST body, **When** the call is made, **Then** the response is the standard WP REST 400 `rest_missing_callback_param`.
4. **Given** the request body contains additional unrecognized fields, **When** the call is made, **Then** those fields are silently ignored (REST default).

---

### User Story 3 — CLI Tool Polls Authentication Status (Priority: P1)

After printing the `auth_url`, the CLI sits in a polling loop checking whether the user has clicked **Approve** in the browser yet. Each poll passes the original `code` and `server` so the endpoint can look up the per-attempt state.

**Why this priority**: Polling is the bridge between the asynchronous human approval step and the CLI's synchronous "have we been approved yet?" question. Without it, the CLI cannot detect approval and proceed.

**Independent Test**: After User Story 2 issues a code, `GET /wp-json/acrossai-mcp-manager/v1/auth/status?code=<code>&server=<server_id>` returns `{"approved": false}` while the user has not yet clicked Approve. After the admin clicks Approve (User Story 6 / the static `approve_auth_code()` path), the same poll returns `{"approved": true, "token": "<32-hex-chars>"}`.

**Acceptance Scenarios**:

1. **Given** an issued-but-not-yet-approved auth code, **When** the CLI polls `/auth/status`, **Then** response is HTTP 200 with `{"approved": false}`.
2. **Given** the user has clicked Approve and `approve_auth_code()` has been called, **When** the CLI polls, **Then** response is HTTP 200 with `{"approved": true, "token": "<session_token>"}`.
3. **Given** the auth code is unknown (never issued, expired, or already exchanged), **When** the CLI polls, **Then** response is `WP_Error` 404.
4. **Given** the auth code exists but `server` query parameter doesn't match the stored `server_id`, **When** the CLI polls, **Then** response remains `{"approved": false}` (the polling endpoint MUST NOT leak whether the code is valid for a different server — that would be an enumeration oracle).

---

### User Story 4 — CLI Tool Lists MCP Servers Available to the Granting User (Priority: P1)

Once approved, the CLI receives a `session_token` (short-lived, 10 minutes). It uses this token as a `Bearer` credential against `GET /servers` to discover which MCP servers it is allowed to call — filtered by the AccessControlManager so the CLI sees exactly the same server inventory the granting user would see in the admin UI.

**Why this priority**: The session token's only purpose is to mediate the short window between consent and Application-Password issuance. Surfacing the visible server set during that window lets the CLI present a richer "you can now use the following endpoints" message AND lets the user disambiguate which server they meant if multiple are visible.

**Independent Test**: With a valid session token from User Story 3, `curl -H 'Authorization: Bearer <token>' https://example.com/wp-json/acrossai-mcp-manager/v1/servers` returns HTTP 200 with `{"servers": [ { id, name, description, enabled, version, namespace, route, mcp_url } ]}`. Each `mcp_url` is `rest_url($namespace . '/' . $route)`. Servers the granting user does not have access to are excluded.

**Acceptance Scenarios**:

1. **Given** a valid `Bearer <session_token>` header, **When** the CLI calls `/servers`, **Then** response is HTTP 200 with a `servers` array of all enabled servers the granting user has access to per `AccessControlManager::user_has_access`.
2. **Given** the `Authorization` header is missing, **When** the CLI calls `/servers`, **Then** response is `WP_Error` 401 (`rest_unauthorized`).
3. **Given** the `Authorization` header presents an unknown or expired session token, **When** the CLI calls, **Then** response is `WP_Error` 401.
4. **Given** the `Authorization` header is valid AND the granting user has no access to ANY enabled server, **When** the CLI calls, **Then** response is HTTP 200 with `{"servers": []}` (empty list, not an error).
5. **Given** the `AccessControlManager` class is NOT installed (the access-control plugin is absent), **When** the CLI calls with a valid token, **Then** response is HTTP 200 with the FULL list of enabled servers (the integration degrades gracefully per Constitution §V).

---

### User Story 5 — CLI Tool Exchanges an Approved Code for a WordPress Application Password (Priority: P1)

The session token from User Story 4 is too short-lived for ongoing automation. The CLI's final step is to exchange its approved auth code for a long-lived WordPress Application Password (30-day default). The exchange is **single-use**: the auth code and the session token are both invalidated on success, so a leaked code cannot be re-exchanged.

**Why this priority**: This is the value-delivery step. Without it, the CLI never gets credentials it can actually use across sessions, and the whole flow is theatre.

**Independent Test**: After User Stories 2–4 produce an approved code, `POST /wp-json/acrossai-mcp-manager/v1/auth/exchange` with body `{code: <auth_code>, server_id: <server>}` returns HTTP 200 with `{"app_password": "<wp-app-password>", "username": "<wp-login>", "user_id": <int>, "expires_in": 2592000, "server_id": <server>}`. Calling the same endpoint again with the same code returns 400 `invalid_code`.

**Acceptance Scenarios**:

1. **Given** an approved auth code + matching server_id, **When** the CLI `POST`s `/auth/exchange`, **Then** the plugin creates a new WordPress Application Password named `"AcrossAI MCP Manager CLI - <server_slug> - <code_prefix>"` for the granting user (where `<code_prefix>` is `substr($auth_code, 0, 8)` per Q3 Clarification — uniqueness suffix), deletes BOTH the `acrossai_cli_auth_<code>` and `acrossai_session_<token>` transients, writes an audit row via `CliAuthLog\Recorder::record_success()`, and returns HTTP 200 with `{app_password, username, user_id, expires_in: 2592000, server_id}`.
2. **Given** the auth code is unknown / expired, **When** the CLI `POST`s `/auth/exchange`, **Then** response is HTTP 400 `{"error":"invalid_code"}`.
3. **Given** the auth code exists but `status !== 'approved'` (still pending or in an unknown state), **When** the CLI `POST`s `/auth/exchange`, **Then** response is HTTP 400 `{"error":"not_approved"}`.
4. **Given** the auth code is approved but `user_id` in the transient is null or no longer maps to a real user, **When** the CLI `POST`s `/auth/exchange`, **Then** response is HTTP 400 `{"error":"invalid_user"}`.
5. **Given** the WP installation does NOT support `WP_Application_Passwords` (`class_exists` returns false), **When** the CLI `POST`s `/auth/exchange`, **Then** response is HTTP 501 `{"error":"not_supported"}` and no audit row is written.
6. **Given** the `server_id` field is missing from the POST body, **When** the CLI `POST`s, **Then** response is HTTP 400 `{"error":"missing_server"}`.
7. **Given** the `server_id` in the POST body does not match the `server_id` stored in the transient (a cross-server replay), **When** the CLI `POST`s, **Then** response is HTTP 400 `{"error":"server_mismatch"}`. The transient is NOT deleted (the legitimate client may still complete the flow with the right server_id).
8. **Given** the `server_id` resolves to no enabled MCP server row, **When** the CLI `POST`s, **Then** response is HTTP 403 `{"error":"invalid_server"}`.

---

### User Story 6 — Admin Approves a CLI Authentication Request from the Browser (Priority: P1)

The user, after visiting the `auth_url` printed by the CLI, sees a FrontendAuth approval page (owned by a sibling module — `Public\Partials\FrontendAuth`). They click **Approve**. The FrontendAuth class calls `CliController::approve_auth_code( $auth_code, $user_id )` STATICALLY. This static method flips the transient state to `approved`, generates a new short-lived session token, and writes a corresponding `acrossai_session_<token>` transient that User Story 3 / 4 read.

**Why this priority**: This is the human-in-the-loop boundary. Without it, no `auth_code` ever transitions from `pending` to `approved` and the entire flow is stuck.

**Independent Test**: `\AcrossAI_MCP_Manager\Includes\REST\CliController::approve_auth_code( 'some-code', $admin_user_id )` returns `true` and persists a `acrossai_session_<token>` transient with `$user_id` as its value. Calling the same static method twice for the same auth code returns `false` on the second call.

**Acceptance Scenarios**:

1. **Given** a pending `acrossai_cli_auth_<code>` transient and a valid logged-in admin user_id, **When** FrontendAuth calls `CliController::approve_auth_code( $code, $user_id )`, **Then** the transient's `status` is updated to `approved`, `user_id` is set, a new 32-hex-char `session_token` is generated, a `acrossai_session_<token>` transient with the user_id as value and 600s TTL is written, `CliAuthLog\Recorder::record_approved()` writes an audit row, and the static method returns `true`.
2. **Given** the auth code does NOT exist (never issued, or expired), **When** FrontendAuth calls the static method, **Then** the call returns `false` and no transient is created.
3. **Given** the auth code exists but its status is already `approved`, **When** FrontendAuth calls the static method, **Then** the call returns `false` (single-approval-per-code semantics) and no new session token is generated.

---

### Edge Cases

- **WP_Application_Passwords class is absent** (`define( 'WP_APPLICATION_PASSWORDS_DISABLED', true );` or older WP versions): the `/auth/exchange` endpoint MUST return HTTP 501 `{"error":"not_supported"}` and MUST NOT write an audit row. The CLI tool surfaces this to the user as "this site has Application Passwords disabled — contact the site administrator".
- **AccessControlManager class is absent**: the `/servers` endpoint MUST return the full enabled-server list (graceful degradation per Constitution §V) rather than failing or returning an empty list — the CLI gets the broadest possible view, and admins who haven't installed the access-control plugin get a working CLI flow.
- **CliAuthLog\Recorder is absent or write fails**: audit writes are best-effort — they MUST NOT block the user-visible flow. `approve_auth_code()` and `/auth/exchange` MUST still complete successfully even if the audit log row fails to persist. A warning is logged via `error_log()` for operator diagnostics.
- **Auth code is captured by an attacker BEFORE approval**: the polling endpoint reveals nothing about the code's existence to unauthorized parties (`{"approved": false}` is indistinguishable from the user not having approved yet). Attacker still cannot redeem the code without the matching `server_id` AND a valid `session_token` (for `/servers`) — but they CAN block legitimate redemption by polling. Mitigation: rate-limit `/auth/status` polling at the reverse-proxy or WAF layer; not in scope for this phase.
- **Auth code is captured by an attacker AFTER approval but BEFORE exchange**: the attacker can call `/auth/exchange` and obtain the Application Password before the legitimate CLI does. This is the same race condition that Phase 5 SEC-001 fixed for OAuth codes. **For this phase the mitigation is the 10-minute session-token TTL + the requirement that legitimate CLIs poll on a short interval (≤2 s) so they win the race in the common case.** A future hardening could apply the SEC-001 atomic CAS pattern (B10 memory) to the `/auth/exchange` redemption step — captured as a known follow-up.
- **Two CLI clients call `/auth/start` with the same `server_id` for the same admin user in parallel**: each call generates an independent `auth_code` and an independent transient. Both auth URLs work; whichever the admin approves first wins. The other transient expires after 5 minutes silently.
- **The admin approves an auth code, then walks away without the CLI ever polling**: the session token transient expires after 10 minutes; the `acrossai_cli_auth_<code>` transient expires 5 minutes after issue. The next `/auth/exchange` call with that code returns `invalid_code`. The Application Password is never created. No cleanup needed — WP's transient API handles eviction.
- **WordPress is configured to use the database fallback for transients** (no persistent object cache): transient reads / writes still work but with a small `wp_options` table footprint per active code. With a 5-minute TTL and short-lived nature, this is bounded growth.
- **The admin user is deleted between approval and exchange**: `/auth/exchange` returns 400 `invalid_user`. The session-token transient still exists but is unusable; it expires naturally.
- **Multisite**: each subsite has its own transient namespace (WP-native); the controller is single-site-scoped by design. Multisite is out of scope for this phase.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Discovery + bootstrap

- **FR-001**: `GET /wp-json/acrossai-mcp-manager/v1/health` MUST be registered with `permission_callback: __return_true`. It MUST return HTTP 200 with JSON body containing exactly:
  - `plugin_installed: bool` (always `true` once the controller is loaded)
  - `plugin_active: bool` (always `true` when the route responds)
  - `version: string` (the plugin's version constant)
  - `site_slug: string` — `sanitize_title( get_bloginfo('name') )`

#### Auth flow initiation

- **FR-002**: `POST /wp-json/acrossai-mcp-manager/v1/auth/start` MUST be registered with `permission_callback: __return_true`. Required field: `server_id` (sanitized via `sanitize_text_field`).
  1. Generate `auth_code` via `bin2hex( random_bytes( 16 ) )` — 32 hex chars (16 bytes of CSPRNG entropy).
  2. Write transient `acrossai_cli_auth_<auth_code>` with value:
     ```php
     [
         'server_id'     => $server_id,
         'status'        => 'pending',
         'user_id'       => null,
         'session_token' => null,
         'created_at'    => time(),
     ]
     ```
     and TTL 300 seconds.
  3. Compose `auth_url`: `FrontendAuth::get_base_url() . '?action=cli_auth&code=' . $auth_code . '&server=' . urlencode( $server_id )`.
  4. Return HTTP 200 with `{auth_code, auth_url, expires_in: 300}`.

#### Auth status polling

- **FR-003**: `GET /wp-json/acrossai-mcp-manager/v1/auth/status` MUST be registered with `permission_callback: __return_true`. Required query parameters: `code` and `server` (both sanitized via `sanitize_text_field`).
  1. Read transient `acrossai_cli_auth_<code>`.
  2. If absent → `WP_Error( 'auth_code_not_found', '...', 404 )`.
  3. If present and `status === 'approved'` AND stored `server_id` matches the `server` query parameter → return `{approved: true, token: <session_token>}`.
  4. Otherwise return `{approved: false}`.

  The server-id mismatch case MUST return `{approved: false}` (not an error) so the endpoint does not function as a code-existence oracle for attackers polling with the wrong server.

#### Server inventory

- **FR-004**: `GET /wp-json/acrossai-mcp-manager/v1/servers` MUST be registered with `permission_callback: verify_session_token()` — a private method on `CliController` that:
  1. Reads the `Authorization` request header. If missing or not `Bearer <token>` → `WP_Error( 'rest_unauthorized', '...', 401 )`.
  2. Reads transient `acrossai_session_<token>` → expected to be an array `[ 'user_id' => int, 'server_id' => string ]` (per Q4 Clarification — session token is bound to the consented `server_id`). If absent → `WP_Error( 'rest_unauthorized', '...', 401 )`.
  3. Calls `wp_set_current_user( $user_id )` so downstream filters see the granting user.
  4. Returns `true` (the permission_callback contract).

  The endpoint body:
  1. Reads the bound `$session_server_id` from the session-token transient set in step 2 of the permission callback.
  2. Queries the SINGLE enabled MCP server row matching that slug: `MCPServer\Query::query( ['server_slug' => $session_server_id, 'is_enabled' => 1, 'number' => 1] )`.
  3. If the result is empty (server was disabled or deleted between approval and `/servers` call) → return HTTP 200 with `{"servers": []}`.
  4. Otherwise, if `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )`, call `AccessControlManager::user_has_access( $user_id, $namespace, $route )`. If false → return HTTP 200 with `{"servers": []}`.
  5. Compose the single entry: `{id, name, description, enabled, version, namespace, route, mcp_url}` where `mcp_url = rest_url( $namespace . '/' . $route )`.
  6. Return HTTP 200 with `{"servers": [ <single-entry> ]}`.

  **Cross-server defense (per Q4 Clarification)**: even with a leaked session token, the `/servers` endpoint reveals ONLY the server the admin explicitly consented to. There is no enumeration vector for other servers in the user's inventory. This matches the Phase 5 FR-015 cross-server defense pattern.

#### Application Password exchange

- **FR-005**: `POST /wp-json/acrossai-mcp-manager/v1/auth/exchange` MUST be registered with `permission_callback: __return_true`. Required fields: `code` (sanitized via `sanitize_text_field`) and `server_id` (sanitized via `sanitize_title`).

- **FR-006**: The `/auth/exchange` handler MUST validate in this order and short-circuit on the first failure:
  1. Read transient `acrossai_cli_auth_<code>`. If absent → HTTP 400 `{"error":"invalid_code"}`.
  2. If `status !== 'approved'` → HTTP 400 `{"error":"not_approved"}`.
  3. If `user_id` is null OR `get_userdata( $user_id ) === false` → HTTP 400 `{"error":"invalid_user"}`.
  4. If `class_exists( 'WP_Application_Passwords' ) === false` → HTTP 501 `{"error":"not_supported"}`. Audit row is NOT written.
  5. If `server_id` field missing/empty → HTTP 400 `{"error":"missing_server"}`.
  6. If the submitted `server_id` does NOT match the transient's stored `server_id` → HTTP 400 `{"error":"server_mismatch"}`. Transients are NOT deleted.
  7. If the submitted `server_id` resolves to no enabled MCP server row → HTTP 403 `{"error":"invalid_server"}`.

- **FR-007**: On all-checks-pass, the `/auth/exchange` handler MUST:
  1. Call `WP_Application_Passwords::create_new_application_password( $user_id, ['name' => 'AcrossAI MCP Manager CLI - ' . $server_slug . ' - ' . substr( $auth_code, 0, 8 )] )` (per Q3 Clarification — 8-hex-char uniqueness suffix). The `$server_slug` is the resolved server row's `server_slug`; the `$auth_code` is the raw value submitted in the request body (still in scope at this step before transient deletion).
  2. Delete BOTH transients on success: `acrossai_cli_auth_<code>` AND `acrossai_session_<session_token>` (the latter read from the transient before deletion). Single-use enforcement.
  3. Call `CliAuthLog\Recorder::record_success( $user_id, $server_id, $code_hash )`. Audit failure MUST NOT block the response.
  4. Return HTTP 200 with `{app_password: <raw>, username: <user_login>, user_id: <int>, expires_in: 2592000, server_id: <server>}`.

#### Static approval entry point

- **FR-008**: `CliController::approve_auth_code( string $auth_code, int $user_id ): bool` MUST be a public static method on the controller (NOT an instance method) because `Public\Partials\FrontendAuth::handle_approve()` calls it without holding a singleton reference. The method MUST:
  1. Read transient `acrossai_cli_auth_<auth_code>`. If absent OR `status !== 'pending'` → return `false`.
  2. Generate `session_token` via `bin2hex( random_bytes( 16 ) )` — 32 hex chars.
  3. Update the transient with `status: 'approved'`, `user_id: $user_id`, `session_token: <token>`, preserving the original TTL window (re-write with `AUTH_CODE_TTL` to maintain consistency).
  4. Write transient `acrossai_session_<token>` with value **`[ 'user_id' => $user_id, 'server_id' => $stored_server_id ]`** (per Q4 Clarification — array shape, not bare int, so the session token is bound to the consented `server_id`) and TTL 600 seconds (`SESSION_TOKEN_TTL`).
  5. Call `CliAuthLog\Recorder::record_approved( $user_id, $stored_server_id, $code_hash )` — best-effort, MUST NOT throw or return false on audit failure.
  6. Return `true`.

#### Class constants

- **FR-009**: The controller class MUST declare these `const` values at class level (NOT instance properties, NOT inline magic strings):
  ```php
  const AUTH_TRANSIENT_PREFIX    = 'acrossai_cli_auth_';
  const SESSION_TRANSIENT_PREFIX = 'acrossai_session_';
  const AUTH_CODE_TTL            = 300;   // seconds
  const SESSION_TOKEN_TTL        = 600;   // seconds
  ```
  All transient-key construction inside the class MUST use these constants (no inline `'acrossai_cli_auth_' . $code`).

#### Architecture / Loader contract

- **FR-010**: The controller MUST follow the singleton pattern: `protected static $_instance = null;` + `public static function instance(): self` + `private function __construct() {}`. The constructor MUST contain ZERO `add_action()` / `add_filter()` calls.

- **FR-011**: All REST routes MUST be wired through `Includes\Main::define_public_hooks()` via the Loader on the `rest_api_init` action — never from inside the class constructor or its own hook registrations. Per memory A1.

- **FR-012**: `__return_true` is acceptable as the permission_callback for `/health`, `/auth/start`, `/auth/status`, and `/auth/exchange` because:
  - `/health` is a read-only public discovery endpoint
  - `/auth/start` mutates ONLY a short-lived transient with no user-identifying data
  - `/auth/status` is read-only
  - `/auth/exchange` authenticates via the body's `code` field, which IS the authentication credential (analogous to Phase 5's S7 token-endpoint exemption; document the same RFC-style rationale)

  This is the documented sister-exemption to S2 / S7. Capture as a memory candidate for post-implementation review.

#### Namespace + placement

- **FR-013**: The controller MUST live at `includes/REST/CliController.php` with namespace `AcrossAI_MCP_Manager\Includes\REST`. File name matches class name.

#### Audit integration

- **FR-014**: All audit log writes MUST go through static `CliAuthLog\Recorder` methods — `record_approved()` on approval, `record_success()` on Application-Password issuance. Audit failures (e.g., `CliAuthLog\Recorder` class absent, DB write fails) MUST be logged via `error_log()` but MUST NOT propagate as user-visible errors.

#### Content-Type policy (per Q2 Clarification)

- **FR-015**: Both `POST /auth/start` and `POST /auth/exchange` MUST validate the request `Content-Type` header before executing any business logic. The valid set is exactly:
  - `application/x-www-form-urlencoded` (with or without a `; charset=…` parameter)
  - `application/json` (with or without a `; charset=…` parameter)

  Any other value, including a MISSING header, MUST short-circuit with HTTP 400 `{"error":"invalid_request"}`. The check MUST run BEFORE any field-level validation so attackers cannot probe per-step error envelopes by sending malformed bodies under bogus Content-Types. (Inherits the Phase 5 SEC-002 hardening lesson, with the form-urlencoded restriction relaxed for first-party CLI tooling ergonomics.)

  `GET /health`, `GET /auth/status`, and `GET /servers` are NOT subject to this check (GET requests carry no semantically significant body).

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ |
| WordPress version | 6.9+ |
| Multisite | Single-site only this phase |
| Required Composer packages | None new — relies on existing `automattic/jetpack-autoloader` |
| Optional integrations | `WP_Application_Passwords` (WP core 5.6+, can be disabled via filter); `\WPBoilerplate\AccessControl\AccessControlManager` (the access-control vendor plugin); `Public\Partials\FrontendAuth` (Phase 3 module — provides the browser approval page) |
| Required existing classes | `Includes\Database\MCPServer\Query` (Phase 2), `Includes\Database\CliAuthLog\Query` (Phase 2 — used internally by the new `Recorder` helper), `Public\Partials\FrontendAuth` (Phase 3 — absorbed into Phase 6.0) |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `includes/REST/CliController.php` | `AcrossAI_MCP_Manager\Includes\REST` | New — 5 REST routes + static approval method |
| `includes/Database/CliAuthLog/Recorder.php` | `AcrossAI_MCP_Manager\Includes\Database\CliAuthLog` | New — stateless A11-style helper class with static `record_approved()` + `record_success()` (per Q1 Clarification) |
| `includes/Main.php` | (existing) | Extend — `define_public_hooks()` wires `rest_api_init` → `CliController::register_routes` |

**Hook Registration Rule**: ALL `add_action` / `add_filter` calls for this feature MUST be wired only through the Loader inside `Main::define_public_hooks()`. Zero hook calls may appear in the `CliController` constructor or any of its methods.

### Admin UI Requirements

This phase introduces NO admin UI. The browser-mediated approval page is owned by the sibling `Public\Partials\FrontendAuth` module (Phase 3 — separate spec). This phase ONLY introduces REST endpoints + the static approval entry point that FrontendAuth calls.

### REST API Contract

| Method | Route | Auth | Description |
|---|---|---|---|
| `GET` | `/wp-json/acrossai-mcp-manager/v1/health` | none | Plugin discovery + site_slug |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/auth/start` | none (body-only) | Issue auth_code + auth_url, write pending transient |
| `GET` | `/wp-json/acrossai-mcp-manager/v1/auth/status` | none (body-only) | Poll auth_code state |
| `GET` | `/wp-json/acrossai-mcp-manager/v1/servers` | Bearer session_token | List enabled servers visible to granting user |
| `POST` | `/wp-json/acrossai-mcp-manager/v1/auth/exchange` | none (body-only) | Exchange approved code for Application Password |

### Database / Storage

This phase introduces NO new database tables.

| Storage | Purpose | Owned by |
|---|---|---|
| WordPress transient `acrossai_cli_auth_<code>` | Per-auth-code state machine | This controller (FR-002, FR-003, FR-007, FR-008) |
| WordPress transient `acrossai_session_<token>` | Bearer-token → user_id mapping | This controller (FR-004, FR-008) |
| `{prefix}acrossai_mcp_cli_auth_logs` (existing) | Audit log | Phase 2's `CliAuthLog\Query` owns the row writes; this phase's NEW `CliAuthLog\Recorder` static helper (per Q1 Clarification) calls `( new Query() )->add_item(...)`. CliController + FrontendAuth only call `Recorder::record_approved()` + `record_success()` — never the Query layer directly. |

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] All REST routes have explicit `permission_callback` — `__return_true` only on read or body-authenticated routes; documented exemption matches the same rationale as Phase 5 S7 token endpoint
- [ ] `POST /auth/start` and `POST /auth/exchange` enforce strict Content-Type allow-list (`application/json` OR `application/x-www-form-urlencoded`) per FR-015; missing or unknown Content-Type returns HTTP 400 `invalid_request` BEFORE any field validation runs
- [ ] Session token is bound to the consented `server_id` per Q4 Clarification — `/servers` returns ONLY that one server. The transient value shape is `[ 'user_id' => int, 'server_id' => string ]`, NOT a bare `int $user_id`. Eliminates the server-enumeration vector for leaked session tokens (parity with Phase 5 FR-015 cross-server defense)
- [ ] All user input sanitized at system boundary with most-specific function (`sanitize_text_field()`, `sanitize_title()`)
- [ ] All output that goes to JSON is type-coerced before encoding (e.g., `(int) $user_id`, `(string) $row->server_name`)
- [ ] `auth_code` and `session_token` are 32-hex-char strings derived from `random_bytes(16)` — 128 bits of entropy each
- [ ] `Authorization: Bearer` header parsing uses case-insensitive prefix match and a length guard (reject tokens longer than 64 chars)
- [ ] Transients are deleted on single-use exchange (both `acrossai_cli_auth_<code>` AND `acrossai_session_<token>`)
- [ ] `server_id` comparison in `/auth/status` uses `hash_equals` constant-time (no oracle via timing)
- [ ] Optional integration calls (`AccessControlManager`, `CliAuthLog\Recorder`) are `class_exists()`-guarded; absent integrations degrade gracefully without leaking class-existence as an error oracle
- [ ] `WP_Application_Passwords::create_new_application_password` is called inside a `try/catch` — failures return 500 generic and log to `error_log()`, never leak `Throwable::getMessage()` into the JSON response
- [ ] `auth_code_hash` written to the `CliAuthLog\Recorder` is `hash('sha256', $auth_code)` — raw code never persisted
- [ ] Raw `app_password` is returned in the response body exactly once at issuance — never logged, never persisted by the plugin

### Key Entities

- **Auth Code (transient `acrossai_cli_auth_<code>`)**: A short-lived (300 s) per-CLI-request token. State machine: `pending → approved → consumed` (where `consumed` is implicit — the transient is deleted on `/auth/exchange` success).
- **Session Token (transient `acrossai_session_<token>`)**: A short-lived (600 s) Bearer credential issued at approval time. Maps to a single `user_id`. Used only on `/servers`; deleted on `/auth/exchange` success.
- **Application Password (WordPress core)**: A 30-day Bearer credential created by `WP_Application_Passwords::create_new_application_password`. Returned to the CLI exactly once on `/auth/exchange` success. Stored hashed by WP core.
- **Audit Row (existing `acrossai_mcp_cli_auth_logs` table)**: Per-attempt forensic record. Written on approval (`record_approved`) and on Application-Password issuance (`record_success`).

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [x] PHPCS validation: zero errors and zero warnings (`vendor/bin/phpcs`) — verified 2026-06-25 on `includes/REST/`, `public/Partials/`, `includes/Database/CliAuthLog/Recorder.php`
- [x] PHPStan level 8: zero errors (`vendor/bin/phpstan`) — verified 2026-06-25
- [ ] PHPUnit tests written and passing for all new logic — full per-endpoint coverage on a new `cli-rest` testsuite — **deferred**: 8 test files written (HealthEndpoint, AuthStartEndpoint, AuthStatusEndpoint, ApproveAuthCodeStatic, ServersEndpoint, AuthExchangeEndpoint, RecorderTest, MaybeRenderPage, HandleApprove); execution requires WP-PHPUnit DB provisioned via `bin/install-wp-tests.sh`
- [x] Security checklist above: every applicable item verified — 15 Confirmed Secure Patterns walk completed 2026-06-25 per T102
- [x] All hooks wired in `Main.php::define_public_hooks()` — zero `add_action`/`add_filter` calls inside `includes/REST/CliController.php` — verified 2026-06-25 (T092)
- [x] `grep -rn 'add_action\|add_filter' includes/REST/` returns zero matches — verified 2026-06-25 (also covers `public/Partials/` and `includes/Database/CliAuthLog/Recorder.php` per Q1 scope extension)
- [ ] Manual quickstart: an external CLI client completes the full flow (`/health` → `/auth/start` → admin approves in browser → `/auth/status` → `/servers` → `/auth/exchange`) on a clean install and successfully obtains an Application Password it can use against `/wp-json/mcp/*` endpoints — **deferred**: requires clean WP 6.9 install + admin user + manual browser walk
- [ ] `npm run validate-packages` passes (no JS changes expected; informational gate) — **deferred**: informational only

### Measurable Outcomes

- **SC-001**: An external CLI client following the documented flow completes the full sequence (`/health` → `/auth/start` → manual browser Approve → `/auth/status` poll → `/servers` → `/auth/exchange`) in under 60 seconds on a clean WP 6.9 / PHP 8.0 install. Verified manually with a one-off `curl`-based test client.
- **SC-002**: Auth codes expire exactly 300 seconds after issue (with ≤ 2 seconds clock-drift tolerance). Verified by issuing a code, waiting 305 seconds, and confirming `/auth/exchange` returns `invalid_code`.
- **SC-003**: Session tokens expire exactly 600 seconds after issue (with ≤ 2 seconds clock-drift tolerance). Verified by issuing a token, waiting 605 seconds, and confirming `/servers` returns 401.
- **SC-004**: Application passwords issued have `expires_in: 2592000` (30 days) in the response envelope. Verified by an HTTP-level assertion on the success response.
- **SC-005**: The single-use guarantee holds: after a successful `/auth/exchange`, a second `/auth/exchange` call with the same `code` returns 400 `invalid_code` 100% of the time. Verified by PHPUnit.
- **SC-006**: `/servers` filters correctly per `AccessControlManager::user_has_access` for users with restricted access. With `AccessControlManager` absent, ALL enabled servers are returned. Verified by PHPUnit using both a stub access-control implementation and a `class_exists`-false scenario.
- **SC-007**: `/auth/status` does NOT leak code existence to attackers polling with the wrong `server` parameter — `{"approved": false}` is returned, NOT 404. Verified by PHPUnit.
- **SC-008**: When `WP_Application_Passwords` is disabled (`add_filter( 'wp_is_application_passwords_available', '__return_false' )`), `/auth/exchange` returns 501 `not_supported` and NO audit row is written. Verified by PHPUnit.

---

## Assumptions

- **WP_Application_Passwords is available by default** — WP 6.9 ships it; some hardened sites disable it via filter. The `not_supported` 501 response handles the disabled case explicitly.
- **AccessControlManager is optional** — when `\WPBoilerplate\AccessControl\AccessControlManager` is absent, `/servers` returns the full enabled list. The graceful-degradation behavior matches Constitution §V and the precedent set in Phase 2 (admin UI handles AccessControl absence the same way).
- **FrontendAuth (Phase 3) is the approval-page owner** — this phase consumes `FrontendAuth::get_base_url()` (a static helper) and exposes the static `approve_auth_code()` method for FrontendAuth to call. If Phase 3 hasn't shipped FrontendAuth yet when this phase begins, the P0 gate (T004-equivalent) MUST flag it and absorption via D11 "Phase X.0" pattern is the next move.
- **Single-site only** — multisite is out of scope. Transients are per-subsite under WP-native semantics; the controller does not perform any `switch_to_blog()`.
- **No new database tables** — the existing `acrossai_mcp_cli_auth_logs` table (Phase 2) is the audit destination; this phase only WRITES via the existing static methods.
- **Code + session-token entropy: 128 bits each** — `random_bytes(16)` → `bin2hex` → 32 hex chars. Sufficient for short-lived (5–10 min) opaque credentials per OWASP recommendations. Not increased to 32 bytes because these are NOT persistent credentials (the Application Password is).
- **Polling frequency**: the spec does NOT mandate a minimum polling interval on `/auth/status`. CLI tools SHOULD poll on a ≥ 1-second interval to avoid thundering-herd on the transient layer. Operators behind a reverse proxy MAY rate-limit at that layer if abusive polling is observed.
- **No HTTPS hard-block** on `/auth/exchange` despite the Application Password being returned plaintext — matches Phase 5's "warning-not-block" posture per the `Notices::render_oauth_https_notice` precedent. Production deployments MUST run HTTPS.
- **The `server_id` parameter shape** is the existing `MCPServer\Row::$server_slug` (URL-safe slug), not the numeric primary key. This matches how Phase 2 admin UI references servers and lets CLI tools use human-readable identifiers.
- **`hash_equals` comparison on `server_id` in `/auth/status`** is for code-existence-oracle defense, not secret-comparison (the `server_id` is not secret). Using `hash_equals` here is defense-in-depth — pattern parallel to Phase 5 F1.
- **Session token IS bound to the consented `server_id`** (per Q4 Clarification, 2026-06-25). `/servers` returns ONLY that one server's metadata, never the user's full inventory. Matches Phase 5 FR-015 cross-server defense pattern. CLI tools that genuinely need a multi-server view must initiate `/auth/start` once per target server. (Previously this assumption was inverted; Q4 reversed it after recognizing the server-enumeration vector a leaked session token would otherwise enable.)

---

## Dependencies

| Phase | Dependency | Status |
|---|---|---|
| Phase 1 (core boot flow) | Loader + Activator + Main are wired; constants defined | ✅ shipped |
| Phase 2 (admin UI) | `MCPServer\Query`, `CliAuthLog\Query` / `CliAuthLog\Recorder` exist with the documented interface; existing `acrossai_mcp_cli_auth_logs` table is present | ✅ shipped |
| Phase 3 (frontend auth) | `Public\Partials\FrontendAuth` exists with static `get_base_url()` AND a `handle_approve()` method that calls `CliController::approve_auth_code()` | ⚠ Not yet shipped — P0 gate at T004 (potential D11 Phase X.0 absorption candidate) |
| Phase 5 (OAuth) | Shipped on `005-oauth-connectors` branch (PR open) — provides the `acrossai-mcp/v1/token` precedent for `__return_true` REST routes (memory S7) | ✅ shipped (under review) |

**Cross-phase note**: this Phase 6 REST namespace `acrossai-mcp-manager/v1` is INTENTIONALLY DIFFERENT from Phase 5's `acrossai-mcp/v1`. Phase 5 (`acrossai-mcp/v1/token`) is the OAuth 2.0 endpoint for Claude / external OAuth clients. Phase 6 (`acrossai-mcp-manager/v1/*`) is the CLI-tooling namespace for first-party admin-mediated CLI flows. The two flows are deliberately separate — different threat models, different credential lifecycles, different user-facing UX. Keeping the namespaces distinct prevents future confusion and keeps the per-namespace permission policy clean.

---

## F006 amendment — `/servers` response `id` field carries SLUG (2026-07-15)

**Trigger**: operator ran `npx -y @acrossai/mcp-manager --siteurl=https://acrossai.co --server=mcp-adapter-default-server` and hit:

```
❌ Server 'mcp-adapter-default-server' not in your available servers

Available servers:
  • 1 (Default MCP Server)
```

Auth flow succeeded, server existed and was correctly bound to the session, but the CLI's slug-based match failed because the `/servers` response returned `id: 1` (integer PK) while the CLI's `serverValidator.js:24` compares `s.id === serverId` where `serverId` is the SLUG.

### FR-016 (new) — `/servers` response identifier semantics

The `/servers` endpoint's per-server object MUST use the SLUG STRING as the value of the `id` field — NOT the integer database primary key. Rationale:

- The `@acrossai/mcp-manager` CLI (npm package, cached at `~/.npm/_npx/*/node_modules/@acrossai/mcp-manager/src/serverValidator.js:24`) matches via `servers.find( s => s.id === serverId )` where `serverId` is the CLI's `--server=<slug>` argument.
- The CLI's `configWriter.js` / `configDisplay.js:15` builds config keys as `${siteSlug}-${serverId}` — that concatenation MUST produce a legible slug-based identifier, not `<siteSlug>-1`.
- Per the historical F006 spec §Assumptions bullet: "The `server_id` parameter shape is the existing `MCPServer\Row::$server_slug` (URL-safe slug), not the numeric primary key. This matches how Phase 2 admin UI references servers and lets CLI tools use human-readable identifiers." — the `/servers` RESPONSE contract was inconsistent with this stated ASSUMPTION; the amendment fixes the contract to match the assumption.

Response object shape (MUST):

```php
array(
    'id'          => (string) $row->server_slug, // WAS (int) $row->id — amended.
    'slug'        => (string) $row->server_slug, // Redundant / forward-compat alias equal to `id`.
    'name'        => (string) $row->server_name,
    'description' => (string) $row->description,
    'enabled'     => (bool) $row->is_enabled,
    'version'     => (string) $row->server_version,
    'namespace'   => $ns,
    'route'       => $route,
    'mcp_url'     => rest_url( $ns . '/' . $route ),
)
```

The integer PK is intentionally NOT exposed. No documented consumer needs it; hiding it keeps the identifier surface single-string (slug-only). Prior consumers that treated `id` as `int` MUST update — but no such consumer exists in this codebase or in the published CLI.

Regression-guarded by new PHPUnit case `ServersEndpointTest::test_response_id_and_slug_both_carry_slug_string_for_cli_matching` — seeds a server with slug `mcp-adapter-default-server`, asserts `$data['servers'][0]['id'] === 'mcp-adapter-default-server'` and `slug === id`.

### Commit history

| Commit | Semantic |
|---|---|
| `42e82c1` | Initial fix — added `slug` field alongside `id`. Later found insufficient (CLI reads `id`, not `slug`), superseded by `6c4778b`. Kept in git history for the discovery arc. |
| `6c4778b` | Amended — `id` field itself now carries the slug string. `slug` kept as forward-compat alias. |

### Cross-references

- `includes/REST/CliController.php` — the `handle_servers` response body.
- `tests/phpunit/RestCli/ServersEndpointTest.php` — regression guard.
- Related PRs: #30 (F007 v2), #32 (F004 site-slug prefix). All three PRs together unblock the CLI end-to-end flow.
- Combined-fixes branch for coordinated rsync deploy: `combined-fixes-for-rsync`.
