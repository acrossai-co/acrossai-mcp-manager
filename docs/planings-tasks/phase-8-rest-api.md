# Phase 8 — REST API (CLI Controller) Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/REST/CliController.php

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new file:
  includes/REST/CliController.php

Extend (do NOT replace):
  includes/Main.php            ← wire rest_api_init via Loader
```

---

## What this phase covers

Migrates `src/REST/CliController.php` to `includes/REST/CliController.php`.

The CLI Controller exposes REST endpoints for AI clients to exchange temporary
auth codes for Application Passwords (WordPress REST API credentials).

### Old code

`src/REST/CliController.php` — namespace `ACROSSAI_MCP_MANAGER\REST`

### Target

`includes/REST/CliController.php` — namespace `AcrossAI_MCP_Manager\Includes\REST`

### Hook migration

Old (hooks in constructor):
```php
add_action( 'rest_api_init', array( $this, 'register_routes' ) );
```

New (in `includes/Main.php::define_admin_hooks()`):
```php
$cli_controller = new Includes\REST\CliController( new Includes\Database\CliAuthLog\Query() );
$this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );
```

---

## Spec-Kit Steps

### Step 1: `/speckit.specify`

```
/speckit.specify

Feature: REST API — CLI Authentication Controller
Feature number: 008

The CLI Controller provides REST endpoints for AI tool clients to complete
the browser-based CLI auth flow and obtain WordPress Application Password credentials.

Functional requirements:

1. REST namespace: acrossai-mcp/v1 (or the existing namespace used in main branch)

2. Endpoints:
   POST /wp-json/acrossai-mcp/v1/cli-auth/exchange
     - Accepts: { auth_code: string, client_id: string }
     - Validates the auth code against the CliAuthLog table (unexpired, unused)
     - On success: generates a WordPress Application Password for the user
       who created the code, marks the code as used, returns:
       { username: string, app_password: string, expires_at: ISO8601 }
     - On failure: returns WP_Error with appropriate HTTP status (400/401/410)
     - permission_callback: __return_true (public endpoint — auth is code-based)
       BUT the code itself is the secret; it is single-use and time-limited

   GET /wp-json/acrossai-mcp/v1/cli-auth/status
     - Requires authentication (permission_callback: is_user_logged_in)
     - Returns: { logged_in: bool, user_id: int, username: string }
     - Used by clients to verify their credentials are working

3. Input validation (sanitize_callback on all route args):
   - auth_code: sanitize_text_field, max 255 chars
   - client_id: sanitize_text_field, max 255 chars

4. Auth code rules (enforced by CliAuthLog\Query):
   - Code expires 10 minutes after creation
   - Code is single-use (status flipped to 'used' on exchange)
   - Code is bound to a specific client_id

5. Application Password lifecycle:
   - Created with WP_Application_Passwords::create_new_application_password()
   - Name: "AI Client - {client_id} - {date}"
   - Stored hash is managed by WordPress core

6. Security:
   - Never log raw Application Passwords — only hashed
   - Never expose auth codes in response bodies after exchange
   - Rate limiting: 5 failed exchange attempts per IP per 15 minutes
     (can be implemented via transients)
   - All response data sanitized/escaped before output

7. No direct add_action() in constructor.
   Namespace: AcrossAI_MCP_Manager\Includes\REST
   File: includes/REST/CliController.php
```

### Step 2: `/speckit.plan`

```
/speckit.plan

File: includes/REST/CliController.php
Namespace: AcrossAI_MCP_Manager\Includes\REST

Constructor accepts CliAuthLog\Query as dependency.

class CliController {
    private CliAuthLog\Query $auth_log_query;

    public function __construct( CliAuthLog\Query $auth_log_query ) {
        $this->auth_log_query = $auth_log_query;
    }

    public function register_routes(): void {
        register_rest_route( 'acrossai-mcp/v1', '/cli-auth/exchange', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'exchange_code' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'auth_code' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'client_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( 'acrossai-mcp/v1', '/cli-auth/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );
    }

    public function exchange_code( WP_REST_Request $request ): WP_REST_Response|WP_Error { ... }
    public function get_status( WP_REST_Request $request ): WP_REST_Response { ... }
}

Wiring in Main::define_admin_hooks():
  $cli_controller = new Includes\REST\CliController(
      new Includes\Database\CliAuthLog\Query()
  );
  $this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );

Rate limiting implementation:
  Use transients: get_transient('acrossai_mcp_ratelimit_' . md5($ip))
  Increment counter, block at 5. TTL = 900 seconds (15 min).
```

### Step 3 + 4: `/speckit.tasks` then `/speckit.implement`

---

## Success Criteria

- [ ] `includes/REST/CliController.php` exists with updated namespace
- [ ] No `add_action()` in constructor — wired via Loader
- [ ] `POST /wp-json/acrossai-mcp/v1/cli-auth/exchange` returns app password on valid code
- [ ] Expired codes return HTTP 410
- [ ] Already-used codes return HTTP 409
- [ ] `GET /wp-json/acrossai-mcp/v1/cli-auth/status` requires authentication
- [ ] All REST args have `sanitize_callback`
- [ ] Rate limiting blocks after 5 failed attempts per IP
- [ ] Pre-ship script `detect-rest-endpoints.mjs` exits 0 (all endpoints have permission_callback)
