# Data Model — Phase 6: REST CLI Auth Controller + Phase 6.0 FrontendAuth

**Date**: 2026-06-25 | **Branch**: `006-rest-cli-auth`

This phase introduces **zero new database tables**. All state is held in WordPress transients (object cache + `wp_options` fallback). Audit writes go through Phase 2's existing `acrossai_mcp_cli_auth_logs` table via the static `CliAuthLog\Query::record_approved` + `record_success` methods.

---

## E1 — Auth Code (transient)

**Storage key**: `acrossai_cli_auth_<32-hex-code>` (derived from `CliController::AUTH_TRANSIENT_PREFIX` + raw code)
**TTL**: 300 seconds (`CliController::AUTH_CODE_TTL`)
**Owned by**: `Includes\REST\CliController` (write on `/auth/start`, read on `/auth/status` + `/auth/exchange`, mutate on static `approve_auth_code()`, delete on `/auth/exchange` success)

### Shape

```php
array(
    'server_id'     => string,      // server_slug from POST body
    'status'        => string,      // 'pending' | 'approved'
    'user_id'       => int|null,    // null while pending; admin user_id after approval
    'session_token' => string|null, // null while pending; 32-hex string after approval
    'created_at'    => int,         // Unix timestamp at issue time
);
```

### State transitions

```text
issued (status='pending', user_id=null, session_token=null, created_at=now)
  ├─ approve → approved (status='approved', user_id=$admin_id, session_token=$bin2hex16)
  │              via CliController::approve_auth_code( $code, $user_id )
  │              (also creates the sibling E2 session-token transient)
  ├─ expire  → expired-implicit (TTL elapsed; transient absent on next read)
  └─ consume → deleted (status was 'approved' → /auth/exchange success deletes both this AND E2)
```

### Validation rules

- **Issuance** (`/auth/start`): `server_id` MUST be present and non-empty post-`sanitize_text_field`. Empty → REST 400 `rest_missing_callback_param`.
- **Approval** (`approve_auth_code()`): the transient MUST be `status === 'pending'`. Any other state (including absent) returns `false` from the static method.
- **Polling** (`/auth/status`): the `server` query parameter MUST `hash_equals` the stored `server_id`. Mismatch → `{"approved": false}` (NO oracle: the same response as "not approved yet").
- **Exchange** (`/auth/exchange`): all 7 FR-006 validation steps run in order; each step has its own failure envelope (see contracts/auth-exchange.md).

### Cleanup

No application cleanup needed — WordPress's transient API self-evicts after TTL. On `/auth/exchange` success, both transients are deleted explicitly (single-use enforcement).

---

## E2 — Session Token (transient)

**Storage key**: `acrossai_session_<32-hex-token>` (derived from `CliController::SESSION_TRANSIENT_PREFIX` + raw token)
**TTL**: 600 seconds (`CliController::SESSION_TOKEN_TTL`)
**Owned by**: `Includes\REST\CliController` (write on static `approve_auth_code()`, read on `verify_session_token()` permission callback, delete on `/auth/exchange` success)

### Shape

The transient value is a 2-element associative array (per **Q4 Clarification, 2026-06-25** — was previously a bare `int $user_id`; reversed to bind the session token to the consented `server_id`):

```php
set_transient(
    CliController::SESSION_TRANSIENT_PREFIX . $session_token,
    array(
        'user_id'   => (int) $user_id,
        'server_id' => (string) $consented_server_id,
    ),
    CliController::SESSION_TOKEN_TTL
);
```

| Key | Type | Source |
|---|---|---|
| `user_id` | `int` | the approving admin's user ID (read in `verify_session_token` to call `wp_set_current_user`) |
| `server_id` | `string` | the `server_id` (slug) the admin consented to in `/auth/start` — read from the E1 transient at approval time |

### State transitions

```text
issued (user_id stored, TTL=600s) — created by approve_auth_code() alongside E1 approval
  ├─ resolve → wp_set_current_user($user_id) on /servers request (read-only; transient persists)
  ├─ expire  → expired-implicit (TTL elapsed; transient absent → /servers returns 401)
  └─ consume → deleted on /auth/exchange success (handled in CliController, NOT in FrontendAuth)
```

### Validation rules

- **Permission callback** (`verify_session_token`): the `Authorization` header MUST be `Bearer <token>`. Header parsing follows Phase 5 `BearerAuth::get_bearer_token_from_request` (R2): try `HTTP_AUTHORIZATION`, fall back to `REDIRECT_HTTP_AUTHORIZATION`, 64-char length guard, case-insensitive `Bearer` prefix.
- **Lookup**: `get_transient( SESSION_TRANSIENT_PREFIX . $token )` MUST return an array with both `user_id` (numeric) AND `server_id` (string) keys (per Q4). Missing transient, non-array value, or missing keys → 401.
- **Set current user**: `wp_set_current_user( (int) $payload['user_id'] )` BEFORE returning `true` from the callback. Endpoint body relies on the current-user context for AccessControl filtering.
- **Server-binding**: the callback stashes `server_id` onto the request via `$request->set_param( '_bound_server_id', $payload['server_id'] )` so the `/servers` endpoint body can read it without re-fetching the transient.

### Cleanup

No application cleanup — transient auto-evicts. On `/auth/exchange` success, the session-token transient is deleted explicitly even if it would expire in the same minute (single-use semantics; defensive cleanup).

---

## E3 — Audit Row (existing Phase 2 + Phase 5 table)

**Table**: `{wpdb->prefix}acrossai_mcp_cli_auth_logs` (existing — extended in Phase 5 with 4 OAuth columns this phase DOES NOT touch)
**Owned by**: `Includes\Database\CliAuthLog\Query` (Phase 2)
**Written by this phase**: ONLY via the static helpers `record_approved()` and `record_success()` (best-effort, never blocks the user-visible response)

### Columns this phase uses (read or write)

| Column | This phase's use |
|---|---|
| `server_id` | WRITE — populated from the resolved server row's primary key on `record_approved` and `record_success`. |
| `server_slug` | NOT written by this phase (Phase 2 admin paths populate it). |
| `user_id` | WRITE — populated from the approving / exchanging user. |
| `status` | WRITE — `'approved'` on `record_approved`; `'success'` on `record_success`. NOT `'oauth_code_issued'` (that's Phase 5's namespace). |
| `auth_code_hash` | WRITE — `hash('sha256', $auth_code)` — raw code NEVER persisted. |
| `app_password_uuid` | WRITE — populated from the WP-Apps `record['uuid']` returned in R4. NULL on `record_approved` (no App Password issued yet). |
| `approved_at` | WRITE — `current_time('mysql', 1)` on `record_approved`. |
| `completed_at` | WRITE — `current_time('mysql', 1)` on `record_success`. |
| `created_at` | Auto-populated by MySQL (`DEFAULT CURRENT_TIMESTAMP`). |
| `redirect_uri`, `code_challenge`, `code_challenge_method`, `scope` (Phase 5 OAuth columns) | NOT touched. Empty string default already-applied per Phase 5 schema. |

### Audit row semantics

Each row records ONE state transition:
- `record_approved` row: `status='approved'`, `auth_code_hash` set, `user_id` set, `server_id` set, `approved_at` set, `app_password_uuid` empty.
- `record_success` row: `status='success'`, `auth_code_hash` set, `user_id` set, `server_id` set, `completed_at` set, `app_password_uuid` set (the UUID from `WP_Application_Passwords::create_new_application_password`).

**Two rows are written per successful flow** (one approved, one success). This is intentional — admins reading the audit table see the timeline: "code X was approved at T0, exchanged at T1, latency = T1-T0".

### Cleanup

This phase does NOT introduce its own cleanup. The Phase 2 admin UI exposes a "purge old audit rows" admin action (out of scope for this phase). The Phase 5 OAuth cleanup cron (`acrossai_mcp_oauth_cleanup`) does NOT touch `status='approved'` or `status='success'` rows — only `status='oauth_code_issued'`.

---

## E4 — MCP Server Row (existing Phase 2 table — read-only consume)

**Table**: `{wpdb->prefix}acrossai_mcp_servers` (Phase 2)
**Owned by**: `Includes\Database\MCPServer\Query` (Phase 2)
**This phase reads via**: `( new MCPServerQuery() )->query( [...] )`

### Read sites

1. **`/servers` endpoint** — `query( ['is_enabled' => 1] )` returns all enabled rows. For each row, the endpoint composes:
   ```php
   array(
       'id'          => (int) $row->id,
       'name'        => (string) $row->server_name,
       'description' => (string) $row->description,
       'enabled'     => (bool) $row->is_enabled,
       'version'     => (string) $row->server_version,
       'namespace'   => (string) $row->server_route_namespace,
       'route'       => (string) $row->server_route,
       'mcp_url'     => rest_url( $row->server_route_namespace . '/' . $row->server_route ),
   );
   ```
2. **`/auth/exchange` server-validation step** — `query( ['server_slug' => $server_id, 'is_enabled' => 1, 'number' => 1] )` to resolve the user-supplied `server_id` (which is actually the `server_slug` per spec Assumption). If the result is empty → HTTP 403 `{"error":"invalid_server"}`. The resolved row's `server_slug` is used to compose the Application Password name: `"AcrossAI MCP Manager CLI - <server_slug> - <code_prefix>"` (per **Q3 Clarification** — `<code_prefix>` is `substr( $auth_code, 0, 8 )` for uniqueness when the same admin authorizes the same server multiple times).

### No writes

This phase NEVER writes to `acrossai_mcp_servers`. The Phase 2 admin UI is the sole owner of server-row mutations.

---

## E5 — Feature Flag (existing WordPress option)

**Option key**: `acrossai_mcp_npm_login_enabled` (boolean)
**Default**: `false` (kill-switch — disabled by default)
**Owned by**: `Public\Partials\FrontendAuth` (read on every `maybe_render_page` call)

### Read sites

```php
$enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
if ( ! $enabled ) {
    $this->render_disabled_notice();
    exit;
}
```

If false, the frontend approval page renders a "feature disabled" notice (`render_disabled_notice()`) and exits. The REST endpoints (CliController) are NOT gated by this flag — the kill-switch is purely on the browser-facing surface. Operators who disable the frontend page MAY still receive CLI tool requests at `/auth/start`, but the resulting `auth_url` will land on the disabled page, blocking the human-approval step. This is intentional: it lets admins quickly take the CLI flow offline without unregistering REST routes (which would require a deploy).

### Flag toggle path

This phase introduces NO admin UI for the flag. Admins toggle via WP-CLI: `wp option update acrossai_mcp_npm_login_enabled 1`. A future Phase 2 RT-4 amendment could add an admin checkbox; out of scope here.

---

## State Diagram (full flow)

```text
┌──────────────────────────────────────────────────────────────────────────┐
│  CLI tool                       Browser                  Plugin / DB     │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  POST /auth/start ─────────────────────────────────────► Issue auth_code │
│                                                          (E1 pending)    │
│  ◄────────── { auth_code, auth_url, expires_in: 300 } ──────────         │
│                                                                          │
│  print(auth_url)                                                         │
│                          ◄─── user opens auth_url                        │
│                              FrontendAuth checks login + feature flag    │
│                              + ?action=cli_auth → renders Approve form   │
│                          ─── user clicks Approve ───►                    │
│                              FrontendAuth::handle_approve()              │
│                              → CliController::approve_auth_code()        │
│                                  - E1.status = 'approved'                │
│                                  - E2 session_token issued (600s TTL)    │
│                                  - record_approved() audit row          │
│                          ◄── 302 to ?action=cli_auth_approved            │
│                              "Approved! Return to your CLI."             │
│                                                                          │
│  GET /auth/status?code=... ────────────────────────────► Read E1         │
│  ◄────────── { approved: true, token: <session_token> } ────────         │
│                                                                          │
│  GET /servers (Bearer <session_token>) ─────────────────► Read E2 + E4   │
│  ◄────────── { servers: [...filtered by AccessControl...] } ────         │
│                                                                          │
│  POST /auth/exchange ─────────────────────────────────► Validate E1+E4   │
│  { code, server_id }                                     Create App Pwd  │
│                                                          Delete E1 + E2  │
│                                                          record_success  │
│  ◄────── { app_password, username, user_id, expires_in: 2592000 } ──    │
│                                                                          │
│  ── now uses app_password against /wp-json/mcp/* indefinitely (30 days) │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Privacy / Sensitive-Data Boundaries

| Data | Where lives | Sensitivity | Retention |
|---|---|---|---|
| Raw `auth_code` (32 hex) | Transit only (CLI ↔ plugin); transient KEY suffix | Sensitive (proves identity for ≤ 5 min) | 5 min (TTL) |
| Raw `session_token` (32 hex) | Transit only (CLI ↔ plugin); transient KEY suffix | Sensitive (proves identity for ≤ 10 min) | 10 min (TTL) |
| `auth_code_hash` (SHA-256) | Audit table `auth_code_hash` column | Forensic identifier (cannot be reversed) | Indefinite (admin policy) |
| Raw `app_password` (24 chars, spaces removed) | Transit only (1× in `/auth/exchange` response); WP-core stores SHA-256 hash | Sensitive (long-lived — 30 days) | 30 days (WP-core) |
| `user_id` | E1 transient body + E2 transient value + audit row | Internal identifier | TTL-bound (transients) + indefinite (audit) |
| `server_id` (slug) | E1 transient body + audit row | Non-secret (admin-visible config) | TTL-bound + indefinite |
| `app_password_uuid` | E3 audit row | Forensic identifier (not the password itself) | Indefinite |

**NEVER persisted**: raw `auth_code`, raw `session_token`, raw `app_password`.
**Logged via `error_log()`**: error messages from `WP_Application_Passwords::create_new_application_password` and `CliAuthLog\Query` audit failures. These are operator-facing; they MUST NOT contain raw secrets.

---

## Hand-Off Note for Consumer Phases (T108)

This phase ships the CliController + FrontendAuth + Recorder triad. The following items are explicitly OUT of scope and queued for follow-up phases:

- **Phase 7 admin UI for the feature flag** (`acrossai_mcp_npm_login_enabled`) — currently toggled via `wp option update`. A future Phase 7 amendment can add an admin checkbox under MCP Manager → Settings. Not affected by Phase 6's contracts.
- **Phase 7+ audit observability** (`do_action('acrossai_mcp_cli_audit_failed', $context)`) — security-constraints.md F3 / SEC-103. The `try/catch` blocks in `Recorder::record_*` currently emit `error_log()` only. A `do_action` hook would let downstream monitoring (Slack notifier, APM trace) observe silent failures without modifying Recorder.
- **B10 atomic-CAS hardening on `/auth/exchange`** — planning-time accepted deferral per `plan.md` §Complexity Tracking row 3. Q4 server-binding already narrowed the race-loss impact. Future hardening can apply atomic CAS via `wp_options` direct UPDATE on a `consumed_at` field if WP-Apps grows revocation-on-create semantics.
- **HTTPS hard-block on `/auth/exchange`** — matches Phase 5's "warning-not-block" posture. A future phase could apply `is_ssl()` rejection if compliance requires.

**Memory captures queued for post-implementation** (per `/speckit-memory-md-capture-from-diff` after merge):

- **S8** — Body-authenticated mutating REST routes are exempt from S2 when (a) Content-Type allow-list rejects missing/unknown headers BEFORE field validation AND (b) downstream credential is bound to the consented resource scope. Broader than OAuth-specific S7. Validated by Q2 + Q4 patterns landing successfully in Phase 6.
- **A15** — Database-namespace audit-recorder static helpers follow the A11/A14 stateless-helper family. Validated by `CliAuthLog\Recorder` landing successfully.
