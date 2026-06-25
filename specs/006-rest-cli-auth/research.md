# Research — Phase 6: REST API CLI Authentication Controller + Phase 6.0 FrontendAuth

**Date**: 2026-06-25 | **Branch**: `006-rest-cli-auth`

---

## R1 — Transient key derivation + collision avoidance

**Decision**: Transient keys are literal prefixed strings — `acrossai_cli_auth_<32hex>` and `acrossai_session_<32hex>` — composed via class constants `AUTH_TRANSIENT_PREFIX` + `SESSION_TRANSIENT_PREFIX` on `CliController`.

```php
final class CliController {
    const AUTH_TRANSIENT_PREFIX    = 'acrossai_cli_auth_';
    const SESSION_TRANSIENT_PREFIX = 'acrossai_session_';
    const AUTH_CODE_TTL            = 300;   // seconds
    const SESSION_TOKEN_TTL        = 600;   // seconds
}

// Use sites:
$key = self::AUTH_TRANSIENT_PREFIX . $auth_code;
set_transient( $key, $payload, self::AUTH_CODE_TTL );
```

**Rationale**: 32 hex chars of CSPRNG-derived randomness gives 128 bits of entropy — collision probability across the 5-minute issuance window is negligible (~10^-18 for any realistic concurrent client count). Class constants prevent magic-string drift. T080 polish-grep asserts zero inline `'acrossai_cli_auth_' . $code` constructions outside the controller class.

Prefix-collision audit:
- `acrossai_cli_auth_` does NOT collide with Phase 5's OAuth transients (which use `oauth_rate_<sha256>`).
- Does NOT collide with Phase 2's CliAuthLog audit rows (custom DB table, not transients).
- Does NOT collide with WP-core prefixes (`_transient_`, `_transient_timeout_`, `_site_transient_`, etc.).
- The `acrossai_session_` prefix is similarly clean.

**Alternatives rejected**:
- Hashing the prefix + code for shorter keys — rejected, debugging visibility matters more than 30 bytes per key.
- Using `wp_options` directly — rejected, defeats the auto-eviction TTL semantics and forces manual cleanup cron.

---

## R2 — Bearer header parsing (`Authorization` extraction)

**Decision**: `CliController::verify_session_token()` reads `$_SERVER['HTTP_AUTHORIZATION']` first, then falls back to `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`. Strip the `Bearer ` prefix via `stripos`-based case-insensitive check. Length-guard: reject tokens >64 chars (session tokens are exactly 32; the guard is paranoid).

```php
private function verify_session_token( WP_REST_Request $request ) {
    $header = '';
    if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
        $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
        // Apache + CGI strips Authorization by default; this is the fallback.
        $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ( 0 !== stripos( $header, 'Bearer ' ) ) {
        return new \WP_Error( 'rest_unauthorized', 'Missing Bearer token.', array( 'status' => 401 ) );
    }
    $token = trim( substr( $header, 7 ) );
    if ( '' === $token || strlen( $token ) > 64 ) {
        return new \WP_Error( 'rest_unauthorized', 'Malformed Bearer token.', array( 'status' => 401 ) );
    }
    $payload = get_transient( self::SESSION_TRANSIENT_PREFIX . $token );
    // Per Q4 Clarification: session payload is array{user_id: int, server_id: string}
    if ( ! is_array( $payload )
         || ! isset( $payload['user_id'], $payload['server_id'] )
         || ! is_numeric( $payload['user_id'] )
    ) {
        return new \WP_Error( 'rest_unauthorized', 'Invalid or expired session token.', array( 'status' => 401 ) );
    }
    wp_set_current_user( (int) $payload['user_id'] );
    // Stash the bound server_id on the request so handle_servers() can read it
    // without re-fetching the transient.
    $request->set_param( '_bound_server_id', (string) $payload['server_id'] );
    return true;
}
```

**Rationale**: Identical fallback pattern as Phase 5's `BearerAuth::get_bearer_token_from_request` — proven by `BearerHeaderParsingTest` (5 acceptance scenarios already shipped). Reusing the pattern reduces review surface and maintains consistency. The PHPUnit test mirrors `tests/phpunit/OAuth/BearerHeaderParsingTest.php` to assert the REDIRECT_HTTP_AUTHORIZATION fallback works on this controller too.

**Q4 refresh (2026-06-25)**: the transient now stores `array{user_id, server_id}` instead of a bare `int $user_id`. The permission callback reads BOTH fields, sets the current user from `user_id`, and stashes `server_id` on the request via `set_param('_bound_server_id', ...)` so the endpoint body has access to the bound slug without re-fetching the transient. The `_bound_server_id` parameter name has a leading underscore to signal "internal-use; not part of the REST args schema".

**Alternatives rejected**:
- Parsing via `getallheaders()` polyfill — rejected, adds a function-existence guard for marginal benefit (PHP-FPM exposes `HTTP_*` reliably).
- Rejecting any whitespace anywhere in the token — rejected, `trim()` handles the common case (trailing `\r\n` from curl).

---

## R3 — `get_userdata()` semantics for the `invalid_user` failure path

**Decision**: `/auth/exchange` step 3 calls `get_userdata( $user_id )`. If it returns `false` (user deleted between approval and exchange), return HTTP 400 `{"error":"invalid_user"}`. Do NOT call `wp_get_current_user()` because at this point in the flow, current user is NOT set — the request is anonymous; the `user_id` comes from the transient state.

```php
$user = get_userdata( $stored_user_id );
if ( false === $user || ! ( $user instanceof \WP_User ) ) {
    return new \WP_REST_Response( array( 'error' => 'invalid_user' ), 400 );
}
```

**Rationale**: `get_userdata` is WP-core's canonical "user exists?" check. It returns a `WP_User` object on hit OR `false` on miss. It does NOT trigger filters or user-meta loads that could mask deletion. Comparing to `WP_User::exists()` (which requires constructing the object first) makes the failure path one round-trip shorter.

**Alternatives rejected**:
- Querying `wp_users` directly with `$wpdb->prepare` — rejected, breaches the boundary between feature classes and the data layer for no functional gain.
- Using `username_exists` — rejected, takes a username string not an ID; wrong primitive for this use case.

---

## R4 — `WP_Application_Passwords::create_new_application_password` return shape

**Decision**: The method returns either a `WP_Error` (on failure — wrap in `try/catch` and convert to a generic 500 `server_error` JSON envelope) OR a positional array `[ $raw_password, $app_password_record ]`. Element 0 is the raw 24-char password (spaces removed per WP-core convention); element 1 is the persisted record array with keys `uuid`, `name`, `app_id`, `created`, `last_used`, `last_ip`.

```php
// Reference signature (WP core):
public static function create_new_application_password( $user_id, $args = array() ) {
    // returns array{0: string, 1: array{uuid: string, name: string, ...}} on success,
    // returns WP_Error on failure
}

// Use site in CliController:
$result = \WP_Application_Passwords::create_new_application_password(
    $user_id,
    array( 'name' => 'AcrossAI MCP Manager CLI - ' . $server_slug )
);
if ( is_wp_error( $result ) ) {
    error_log( '[acrossai-mcp] App Password creation failed: ' . $result->get_error_message() );
    return new \WP_REST_Response( array( 'error' => 'server_error' ), 500 );
}
list( $raw_password, $record ) = $result;
```

**Rationale**: WP-core return shape is positional, not associative. The plan documents the destructuring at the contract level so the implementer doesn't surprise themselves at test-time. The raw password is what the CLI receives in the response body (exactly once); the record is what WP-core persists. The `name` field uses the server SLUG (not the server name) so the value is URL-safe and stable.

**Alternatives rejected**:
- Passing `'app_id'` in the optional `$args` — rejected, let WP-core generate it for uniqueness guarantees.
- Creating the password BEFORE deleting the transients — already the chosen order (see R5).

---

## R5 — Single-use transient deletion ordering

**Decision**: On `/auth/exchange` success, the order is:
1. Validate all 7 FR-006 steps (short-circuit on first failure)
2. Resolve `server_slug` from `MCPServer\Query::query( ['server_slug' => $server_id, 'is_enabled' => 1, 'number' => 1] )`
3. Call `WP_Application_Passwords::create_new_application_password()` — if it returns `WP_Error`, abort with 500 (transients are NOT deleted)
4. Delete `acrossai_cli_auth_<code>` transient (invalidates polling)
5. Delete `acrossai_session_<session_token>` transient (invalidates `/servers`)
6. Call `CliAuthLogTable::record_success()` — best-effort (audit failure does NOT roll back)
7. Return 200 with the response envelope

```php
// Order matters — see B10 deferral rationale in plan.md
$result = \WP_Application_Passwords::create_new_application_password( $user_id, $args );
if ( is_wp_error( $result ) ) {
    error_log( '[acrossai-mcp] App Password create failed: ' . $result->get_error_message() );
    return new \WP_REST_Response( array( 'error' => 'server_error' ), 500 );
}
list( $raw_password, $record ) = $result;

// Now consume the credentials — single-use enforcement.
delete_transient( self::AUTH_TRANSIENT_PREFIX . $code );
if ( ! empty( $transient['session_token'] ) ) {
    delete_transient( self::SESSION_TRANSIENT_PREFIX . $transient['session_token'] );
}

// Best-effort audit — MUST NOT block the response on failure.
try {
    \AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query::record_success(
        $user_id, $server_id, hash( 'sha256', $code )
    );
} catch ( \Throwable $e ) {
    error_log( '[acrossai-mcp] audit record_success failed: ' . $e->getMessage() );
}

return new \WP_REST_Response( array(
    'app_password' => $raw_password,
    'username'     => (string) $user->user_login,
    'user_id'      => (int) $user_id,
    'expires_in'   => 2592000,
    'server_id'    => $server_id,
), 200 );
```

**Rationale**: Phase 5's B10 pattern would prefer atomic CAS, but as documented in `plan.md` Constitution Check, the threat model here doesn't justify the atomic-redeem complexity. The deletion-after-creation ordering ensures forward progress:
- A failed password creation MAY succeed on retry (transient WP-Apps unavailability) — keeping the transients live allows the legitimate CLI to retry on the same code.
- A failed transient deletion is recoverable on the next sweep (transients self-expire at 300s / 600s).
- Race-loss (attacker calls `/auth/exchange` between transient read and delete) results in `get_userdata` succeeding for both — both calls would create App Passwords. **This is the explicit B10 deferral**. Mitigation: short TTLs (5 min / 10 min) bound the race window; a future hardening can apply atomic CAS via a `wp_options` direct UPDATE on a `consumed_at` field.

**Alternatives rejected**:
- Deleting transients BEFORE creating the password — rejected, race window allows code re-use if WP-Apps fails mid-call.
- Deleting only the auth_code transient — rejected, leaves the session_token live, which `/servers` would still accept.
- Atomic CAS via `wp_options` direct UPDATE on a `consumed_at` field — rejected at planning time (see plan.md §Complexity Tracking row 3); revisit in future hardening.

---

## R6 — FrontendAuth rewrite rule + query var (Phase 6.0)

**Decision**: FrontendAuth defines two class constants — `PAGE_SLUG = 'acrossai-mcp-manager'` and `QUERY_VAR = 'acrossai_mcp_frontend_auth'` — and registers the rewrite rule on `init`:

```php
final class FrontendAuth {
    const PAGE_SLUG  = 'acrossai-mcp-manager';
    const QUERY_VAR  = 'acrossai_mcp_frontend_auth';

    public function register_rewrite_rule(): void {
        add_rewrite_rule(
            '^' . self::PAGE_SLUG . '/?$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function get_base_url(): string {
        return home_url( '/' . self::PAGE_SLUG . '/' );
    }
}
```

The pattern `'^acrossai-mcp-manager/?$'` has NO `.` to escape — B4 (Phase 1 bug) does NOT apply. The optional trailing slash matches both `/acrossai-mcp-manager` and `/acrossai-mcp-manager/`.

**Rationale**: Slug + query var as class constants prevents magic-string drift across `FrontendAuth`, `CliController` (which references the URL via `FrontendAuth::get_base_url()`), and the Activator (which calls `register_rewrite_rule` on first activation). The Activator extension is a 1-line `FrontendAuth::instance()->register_rewrite_rule()` followed by `flush_rewrite_rules()` (same pattern as Phase 5).

**Alternatives rejected**:
- Hard-coding `'acrossai-mcp-manager'` at every use site — rejected, drift risk.
- Using a hash-based slug — rejected, breaks human-readable URLs that the user must type into a browser.

---

## R7 — `maybe_render_page` action switch + login redirect

**Decision**: The `template_redirect` callback follows this exact skeleton:

```php
public function maybe_render_page(): void {
    if ( ! get_query_var( self::QUERY_VAR ) ) {
        return;
    }
    nocache_headers();

    if ( ! is_user_logged_in() ) {
        wp_redirect( wp_login_url( self::get_base_url() ) );
        exit;
    }

    $action  = isset( $_GET['action'] )
        ? sanitize_text_field( wp_unslash( $_GET['action'] ) )
        : '';
    $enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );

    if ( ! $enabled ) {
        $this->render_disabled_notice();
        exit;
    }

    switch ( $action ) {
        case 'cli_auth':
            $code   = isset( $_GET['code'] )   ? sanitize_text_field( wp_unslash( $_GET['code'] ) )   : '';
            $server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : '';
            $this->handle_cli_auth( $code, $server );
            break;
        case 'cli_auth_approve':
            $code   = isset( $_GET['code'] )   ? sanitize_text_field( wp_unslash( $_GET['code'] ) )   : '';
            $server = isset( $_GET['server'] ) ? sanitize_text_field( wp_unslash( $_GET['server'] ) ) : '';
            check_admin_referer( 'cli_auth_approve_' . $code );
            $this->handle_approve( $code, $server );
            break;
        case 'cli_auth_approved':
            $this->handle_approved();
            break;
        default:
            $this->handle_cli_auth( '', '' );  // empty params → "missing code" error page
    }
    exit;
}
```

**Rationale**: Mirror of Phase 5's `ClaudeConnectors::serve_discovery_or_authorize` dispatcher pattern. The `nocache_headers()` early call prevents browser/proxy caching of the consent page. The `is_user_logged_in()` short-circuit redirects unauthenticated visitors through `wp-login.php` and back. The feature-flag `get_option('acrossai_mcp_npm_login_enabled', false)` is a kill-switch — disabled by default; site admins enable it via an admin setting (out of scope for this phase; user enables manually with `wp option update acrossai_mcp_npm_login_enabled 1`).

**Alternatives rejected**:
- No feature flag — rejected, deployment risk (Phase 3-5 admins shouldn't suddenly see a new frontend route).
- POST-only for approval — rejected, nonced GET is easier for browser users + nonce + `manage_options` recheck provides equivalent CSRF defense.

---

## R8 — Static `approve_auth_code` contract (CliController → FrontendAuth callback)

**Decision**: The static method signature is `public static function approve_auth_code( string $auth_code, int $user_id ): bool`. It is called from `FrontendAuth::handle_approve()` like this:

```php
private function handle_approve( string $code, string $server ): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to approve CLI authentication.', 'acrossai-mcp-manager' ), 403 );
    }

    $approved = \AcrossAI_MCP_Manager\Includes\REST\CliController::approve_auth_code(
        $code,
        get_current_user_id()
    );

    if ( ! $approved ) {
        wp_die( esc_html__( 'This authorization code is no longer valid.', 'acrossai-mcp-manager' ), 400 );
    }

    wp_redirect( add_query_arg( 'action', 'cli_auth_approved', self::get_base_url() ) );
    exit;
}
```

The static method:
1. Reads `acrossai_cli_auth_<code>` transient. If absent OR `status !== 'pending'` → return `false`.
2. Generates `session_token` via `bin2hex( random_bytes( 16 ) )`.
3. UPDATES the transient: `status: 'approved'`, `user_id: $user_id`, `session_token: $token`. Re-write with `AUTH_CODE_TTL` to preserve consistency.
4. WRITES new transient `acrossai_session_<token>` with value `$user_id` and TTL `SESSION_TOKEN_TTL` (600 s).
5. Calls `CliAuthLog\Query::record_approved( $user_id, $stored_server_id, hash('sha256', $code) )` — best-effort, `try/catch` wraps it.
6. Returns `true`.

**Rationale**: The method MUST be static because `FrontendAuth::handle_approve()` is itself a private method on a singleton — calling `CliController::instance()->approve_auth_code()` would either work (via the singleton) or introduce an unnecessary coupling. The static signature decouples the two classes cleanly: `FrontendAuth` only knows the static method name, not the controller's state machine.

**Q4 refresh (2026-06-25)**: step 4 now writes the session-token transient as `array{user_id: int, server_id: string}` (was previously a bare `int $user_id`). The `server_id` is read from the stored transient at step 1 (`$payload['server_id']`) — the same value the admin consented to. The result is that any subsequent `/servers` call against this token is constrained to that one server. The body of the static method then becomes:

```php
$session_token = bin2hex( random_bytes( 16 ) );
set_transient(
    self::SESSION_TRANSIENT_PREFIX . $session_token,
    array(
        'user_id'   => (int) $user_id,
        'server_id' => (string) $payload['server_id'],
    ),
    self::SESSION_TOKEN_TTL
);
```

**Alternatives rejected**:
- Instance method via `CliController::instance()->approve_auth_code()` — rejected, forces FrontendAuth to know the controller's singleton constructor; the static signature is the documented contract.
- Returning the `session_token` from the static method — rejected, FrontendAuth doesn't need it (the user's browser is sent to `?action=cli_auth_approved` which renders the success page; the CLI later polls `/auth/status` to read the token).
- Storing only `user_id` in the transient (pre-Q4 design) — rejected by Q4 as it allows server-enumeration via leaked session token.

---

## R9 — Content-Type allow-list policy (Q2 Clarification, FR-015)

**Decision**: `POST /auth/start` and `POST /auth/exchange` MUST validate `Content-Type` BEFORE any field-level validation. The allow-list is exactly two values (each with optional `; charset=…` parameter):

- `application/json`
- `application/x-www-form-urlencoded`

Any other value, including a MISSING header, returns HTTP 400 `{"error":"invalid_request"}`. The check runs as the first executable statement of `handle_auth_start` and `handle_auth_exchange`.

```php
private function check_content_type( WP_REST_Request $request ): ?WP_REST_Response {
    $ct_info = $request->get_content_type();
    $value   = strtolower( (string) ( $ct_info['value'] ?? '' ) );
    if ( 'application/json' !== $value
         && 'application/x-www-form-urlencoded' !== $value
    ) {
        $resp = new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
        $resp->header( 'Cache-Control', 'no-store' );
        return $resp;
    }
    return null;  // caller continues
}

// Use site (start of handle_auth_start / handle_auth_exchange):
$err = $this->check_content_type( $request );
if ( null !== $err ) {
    return $err;
}
```

**Rationale**: Inherits Phase 5's SEC-002 hardening lesson (rejection of missing Content-Type was a real fix in PR #7) while RELAXING the form-urlencoded-only restriction for this phase's first-party CLI tooling. JSON is the ergonomic default for modern CLI ecosystems (curl `-d '{"code":..."}'`); form-urlencoded remains accepted for shell-friendly callers. The threat model that motivated Phase 5's strict form-urlencoded-only choice (third-party OAuth clients with arbitrary Content-Type confusion attacks) does NOT apply here — this phase's callers are user-controlled scripts, not adversarial OAuth clients. The defense remains the NULL-safe missing-header rejection, which IS the load-bearing part of SEC-002.

`GET /health`, `GET /auth/status`, `GET /servers` are NOT subject to this check. GET requests carry no semantically significant body in the contracts; testing with a wrong Content-Type on a GET would surface unrelated REST-API behavior, not the OAuth-style attack surface FR-015 protects against.

**Alternatives rejected**:
- Strict form-urlencoded-only (Phase 5 exact pattern) — rejected, user-hostile for CLI tools that default to JSON.
- Strict JSON-only — rejected, breaks the most common Bash + curl pattern (`-d 'k=v&k=v'`).
- No Content-Type check at all (status quo before Q2) — rejected, leaves attackers free to probe REST API edge cases with mixed encodings.
- Allowing `text/plain` as a third option — rejected, no legitimate use case + WP REST will parse it as raw string, which the controller cannot meaningfully consume.
