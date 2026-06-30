# Phase CLI Auth — Frontend Auth Page + REST CLI Controller

> These two modules are tightly coupled: `FrontendAuth` calls
> `CliController::approve_auth_code()` (static). Implement both in the same
> session so the dependency is wired immediately.
>
> **Implement REST controller first** (Part B), then Frontend Auth (Part A).

## Source Files to Read First

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/REST/CliController.php          ← 5 endpoints + transient auth + static approve_auth_code()
  src/Frontend/FrontendAuth.php       ← virtual page, action dispatch, calls approve_auth_code()
  src/Database/MCPServerTable.php     ← static helpers consumed by REST controller
  assets/frontend-auth.css            ← styles to move to src/scss/frontend.scss in Phase 9

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new files:
  includes/REST/CliController.php
  public/Partials/FrontendAuth.php

Extend (do NOT replace):
  includes/Main.php            ← wire rest_api_init and public hooks via Loader
  includes/Activator.php       ← register FrontendAuth rewrite rule + flush
```

---

## Part B — REST CLI Controller (implement first)

### What this covers

Migrates `src/REST/CliController.php` → `includes/REST/CliController.php`.

**REST namespace: `acrossai-mcp-manager/v1`** — never shorten to `acrossai-mcp/v1`.

**5-endpoint auth flow:**

```
Step 1: CLI   → POST /auth/start          → auth_code + auth_url
Step 2: Admin approves via FrontendAuth page at auth_url (browser)
Step 3: CLI   → GET  /auth/status?code=   → approved=true + session_token
Step 4: CLI   → GET  /servers             (Bearer: session_token) → server list
Step 5: CLI   → POST /auth/exchange       → app_password + username
```

**Auth is entirely transient-based** — no database table for auth codes:
- `acrossai_cli_auth_{code}` TTL 300 s
- `acrossai_session_{token}` TTL 600 s

### Endpoints

| Method | Path             | Auth                 | Description                                    |
|--------|------------------|----------------------|------------------------------------------------|
| GET    | `/health`        | None                 | Plugin status + version + site_slug            |
| POST   | `/auth/start`    | None                 | Create pending auth session                    |
| GET    | `/auth/status`   | None                 | Poll for admin approval                        |
| GET    | `/servers`       | Bearer session token | List servers accessible to the authed user     |
| POST   | `/auth/exchange` | None                 | Trade approved code for Application Password   |

### Hook migration

Old (in constructor):
```php
add_action( 'rest_api_init', array( $this, 'register_routes' ) );
```

New (in `includes/Main.php::define_admin_hooks()`):
```php
$cli_controller = REST\CliController::instance();
$this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );
```

### Step 1 — `/speckit.specify`

```
/speckit.specify

Feature: REST API — CLI Authentication Controller
Feature number: 006

REST namespace: acrossai-mcp-manager/v1

FIVE endpoints:

1. GET /health — permission_callback: __return_true
   Returns: { plugin_installed, plugin_active, version, site_slug }
   site_slug = sanitize_title( get_bloginfo('name') )

2. POST /auth/start — permission_callback: __return_true
   Required arg: server_id (sanitize_text_field)
   - Generate auth_code via bin2hex( random_bytes(16) )
   - Store transient acrossai_cli_auth_{code} = { server_id, status:'pending',
       user_id:null, session_token:null, created_at:time() }, TTL 300
   - auth_url = FrontendAuth::get_base_url() + ?action=cli_auth&code=...&server=...
   Returns: { auth_code, auth_url, expires_in: 300 }

3. GET /auth/status — permission_callback: __return_true
   Required args: code, server (both sanitize_text_field)
   - Reads transient acrossai_cli_auth_{code}
   - Not found → WP_Error 404
   - Approved → { approved: true, token: session_token }
   - Pending  → { approved: false }

4. GET /servers — permission_callback: verify_session_token()
   - verify_session_token() reads 'Authorization: Bearer {token}' header
   - Reads acrossai_session_{token} transient → $user_id
   - Calls wp_set_current_user($user_id) on success; WP_Error 401 on missing/invalid
   - Returns: { servers: [ { id, name, description, enabled, version, namespace, route, mcp_url } ] }
   - Filter by AccessControlManager::user_has_access($user_id, $ns, $route)
   - mcp_url = rest_url( $namespace . '/' . $route )

5. POST /auth/exchange — permission_callback: __return_true
   Required args: code (sanitize_text_field), server_id (sanitize_title)
   - Validate: transient exists, status='approved', server_id matches stored server_id
   - Guard: class_exists('WP_Application_Passwords') or WP_Error 501
   - Create: WP_Application_Passwords::create_new_application_password(
       $user_id, [ 'name' => 'AcrossAI MCP Manager CLI - {server_slug}' ])
   - Delete BOTH transients on success (single-use codes)
   - Audit: CliAuthLogTable::record_success()
   Returns: { app_password, username, user_id, expires_in: 2592000, server_id }
   Failure codes: invalid_code (400), not_approved (400), invalid_user (400),
     not_supported (501), missing_server (400), server_mismatch (400), invalid_server (403)

Static method (MUST remain static — called by FrontendAuth::handle_approve()):
  static approve_auth_code( string $auth_code, int $user_id ): bool
  - Updates transient to 'approved', generates session_token
  - Creates acrossai_session_{token} transient (TTL 600)
  - Calls CliAuthLogTable::record_approved()
  - Returns false if not found or already approved

Constants (class-level):
  AUTH_TRANSIENT_PREFIX  = 'acrossai_cli_auth_'
  SESSION_TRANSIENT_PREFIX = 'acrossai_session_'
  AUTH_CODE_TTL = 300
  SESSION_TOKEN_TTL = 600

Singleton pattern. No add_action() in constructor.
Namespace: AcrossAI_MCP_Manager\Includes\REST
File: includes/REST/CliController.php
```

### Step 2 — `/speckit.plan`

```
/speckit.plan

File: includes/REST/CliController.php

Singleton:
  protected static $_instance = null;
  public static function instance(): self { ... }
  private function __construct() {
      $this->server_query = Includes\Database\MCPServer\Query::instance();
  }

Methods:
  register_routes(): void                 — all 5 routes
  health(): WP_REST_Response
  auth_start( WP_REST_Request ): WP_REST_Response|WP_Error
  auth_status( WP_REST_Request ): WP_REST_Response|WP_Error
  verify_session_token( WP_REST_Request ): bool|WP_Error
  list_servers(): WP_REST_Response
  auth_exchange( WP_REST_Request ): WP_REST_Response|WP_Error
  static approve_auth_code( string $auth_code, int $user_id ): bool

Private helpers:
  get_accessible_servers_for_user( int $user_id ): array
  get_accessible_server_row_by_id( int $user_id, string $server_id ): ?array
  record_failed_cli_auth( ... ): void

Wiring in Main::define_admin_hooks():
  $cli_controller = REST\CliController::instance();
  $this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );

Route namespace string:
  $ns = 'acrossai-mcp-manager/v1';
```

---

## Part A — Frontend Auth Page

### What this covers

Migrates `src/Frontend/FrontendAuth.php` → `public/Partials/FrontendAuth.php`.

A virtual WordPress page at `/acrossai-mcp-manager/` rendered as a **standalone HTML shell**
(no theme, no sidebar). Any logged-in user can approve CLI auth requests from this page.

**Key invariants (AGENTS.md):**
- `static get_base_url()` is the single source of truth for the auth URL — used by both this class and `CliController::auth_start()`. Returns `home_url('/acrossai-mcp-manager/')`. Never change to `admin_url()`.
- `nocache_headers()` MUST fire before any output.
- Any logged-in user may proceed — no `manage_options` check.
- `acrossai_mcp_npm_login_enabled` defaults to `false`. Page shows a disabled notice when off.

**Constants:**
- `PAGE_SLUG = 'acrossai-mcp-manager'`
- `QUERY_VAR  = 'acrossai_mcp_auth'`

**Action dispatch table:**

| `?action=`         | `npm_login_enabled` | Result                                           |
|--------------------|---------------------|--------------------------------------------------|
| `cli_auth`         | true                | Render approval/consent form                     |
| `cli_auth`         | false               | Render "feature disabled" notice                 |
| `cli_auth_approve` | true                | Verify nonce → call `CliController::approve_auth_code()` → redirect |
| `cli_auth_approve` | false               | `wp_die()` 403                                   |
| `cli_auth_approved`| any                 | Render confirmation page                         |

### Hook migration

Old (in constructor):
```php
add_action( 'init',               array( $this, 'register_rewrite_rule' ) );
add_filter( 'query_vars',         array( $this, 'add_query_var' ) );
add_action( 'template_redirect',  array( $this, 'maybe_render_page' ) );
add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
```

New (in `includes/Main.php::define_public_hooks()`):
```php
$frontend_auth = Public\Partials\FrontendAuth::instance();
$this->loader->add_action( 'init',               $frontend_auth, 'register_rewrite_rule' );
$this->loader->add_filter( 'query_vars',         $frontend_auth, 'add_query_var' );
$this->loader->add_action( 'template_redirect',  $frontend_auth, 'maybe_render_page' );
$this->loader->add_action( 'wp_enqueue_scripts', $frontend_auth, 'enqueue_assets' );
```

### Step 1 — `/speckit.specify`

```
/speckit.specify

Feature: Frontend CLI Authentication Page
Feature number: 007

Virtual page at /acrossai-mcp-manager/ for AI clients to authorize CLI access.

Constants (class-level):
  PAGE_SLUG = 'acrossai-mcp-manager'
  QUERY_VAR  = 'acrossai_mcp_auth'

Rewrite rule: ^acrossai-mcp-manager/?$ → index.php?acrossai_mcp_auth=1
Registered on init. Activation hook also registers + flushes immediately.

Static: get_base_url(): string → home_url('/acrossai-mcp-manager/')
Never change to admin_url(). Used by CliController::auth_start() as well.

template_redirect:
  a. Return if QUERY_VAR absent.
  b. nocache_headers() immediately — before any output.
  c. Redirect unauthenticated to wp_login_url() with return URL.
  d. ANY logged-in user may proceed (no manage_options check).
  e. Dispatch by ?action= (see table above).

Page rendering: standalone HTML shell — no wp_head(), no theme, minimal inline CSS.

cli_auth_approve security:
  - Verify nonce: wp_verify_nonce( $_GET['_wpnonce'], 'cli_auth_approve' )
  - Sanitize all inputs: wp_unslash() + sanitize_text_field()
  - On success: REST\CliController::approve_auth_code( $code, get_current_user_id() )
  - Redirect to ?action=cli_auth_approved

Asset enqueue (wp_enqueue_scripts):
  - Guard: return if QUERY_VAR absent (never enqueue globally)
  - CSS: build/css/frontend.css, version from build/css/frontend.asset.php
  - Handle: acrossai-mcp-frontend

Singleton pattern. No add_action() in constructor.
Namespace: AcrossAI_MCP_Manager\Public\Partials
File: public/Partials/FrontendAuth.php
```

### Step 2 — `/speckit.plan`

```
/speckit.plan

File: public/Partials/FrontendAuth.php

Singleton:
  protected static $_instance = null;
  public static function instance(): self { ... }
  private function __construct() {}

Public methods (called by Loader):
  register_rewrite_rule(): void
  add_query_var( array $vars ): array
  maybe_render_page(): void
  enqueue_assets(): void

Static:
  static get_base_url(): string → home_url( '/' . self::PAGE_SLUG . '/' )

Private:
  handle_cli_auth( string $code, string $server ): void
  handle_approve( string $code, string $server ): void
    ↳ calls REST\CliController::approve_auth_code( $code, get_current_user_id() )
    ↳ redirects to ?action=cli_auth_approved
  handle_approved(): void
  render_disabled_notice(): void
  render_page_shell( string $content ): void  ← wraps HTML, no wp_head()

maybe_render_page() skeleton:
  if ( ! get_query_var( self::QUERY_VAR ) ) { return; }
  nocache_headers();
  if ( ! is_user_logged_in() ) { wp_redirect( wp_login_url( self::get_base_url() ) ); exit; }
  $action  = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
  $enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
  switch ( $action ) { ... }
  exit;

Rewrite rule in Activator::activate():
  add_rewrite_rule( '^' . FrontendAuth::PAGE_SLUG . '/?$',
      'index.php?' . FrontendAuth::QUERY_VAR . '=1', 'top' );
  flush_rewrite_rules();

Wiring in Main::define_public_hooks():
  $frontend_auth = Public\Partials\FrontendAuth::instance();
  $this->loader->add_action( 'init',               $frontend_auth, 'register_rewrite_rule' );
  $this->loader->add_filter( 'query_vars',         $frontend_auth, 'add_query_var' );
  $this->loader->add_action( 'template_redirect',  $frontend_auth, 'maybe_render_page' );
  $this->loader->add_action( 'wp_enqueue_scripts', $frontend_auth, 'enqueue_assets' );
```

---

## Shared Workflow Steps (run once after both plans are approved)

### Step 3 — `/speckit.clarify` _(optional)_

Run if either Part A or Part B spec needs clarification.

```
/speckit.clarify
```

---

### Step 4 — `/speckit.memory-md.index-project`

```
/speckit.memory-md.index-project
```

---


### Step 5 — `/speckit.architecture-guard.governed-plan`

```
/speckit.architecture-guard.governed-plan
```

---

### Step 6 — `/speckit.security-review.full`

```
/speckit.security-review.full
```

---

### Step 7 — `/speckit.tasks`

Run for Part B (REST Controller) first, then Part A (FrontendAuth).

```
/speckit.tasks
```

---

### Step 8 — `/speckit.architecture-guard.governed-tasks`

```
/speckit.architecture-guard.governed-tasks
```

---

### Step 9 — `/speckit.implement`

Implement Part B (REST Controller) first so `static approve_auth_code()` exists
before FrontendAuth is implemented.

```
/speckit.implement
```

---

### Step 10 — `/speckit.analyze`

```
/speckit.analyze
```

---

### Step 11 — `/speckit.architecture-guard.drift-analysis`

```
/speckit.architecture-guard.drift-analysis
```

---

### Step 12 — `/speckit.security-review.full`

```
/speckit.security-review.full
```

---

### Step 13 — `/speckit.memory-md.merge-features`

```
/speckit.memory-md.merge-features
```

---

### Step 14 — Git commit _(automatic)_

Triggered automatically after `/speckit.analyze` completes.

---

## Success Criteria

**REST CLI Controller:**
- [ ] `includes/REST/CliController.php` — singleton, no `add_action()` in constructor
- [ ] Namespace `acrossai-mcp-manager/v1` (not `acrossai-mcp/v1`)
- [ ] All 5 routes: `GET /health`, `POST /auth/start`, `GET /auth/status`, `GET /servers`, `POST /auth/exchange`
- [ ] `GET /servers` permission callback reads `Authorization: Bearer` header (not `__return_true`)
- [ ] `POST /auth/exchange` deletes both transients on success (single-use)
- [ ] `static approve_auth_code()` retained and stays static
- [ ] Transient prefixes: `acrossai_cli_auth_` (300 s) and `acrossai_session_` (600 s)
- [ ] `/health` returns `site_slug` = `sanitize_title( get_bloginfo('name') )`
- [ ] `auth_url` built via `FrontendAuth::get_base_url()` — never `admin_url()`

**Frontend Auth Page:**
- [ ] `public/Partials/FrontendAuth.php` — singleton, no `add_action()` in constructor
- [ ] `PAGE_SLUG = 'acrossai-mcp-manager'` and `QUERY_VAR = 'acrossai_mcp_auth'` as class constants
- [ ] `nocache_headers()` fires before any HTML output
- [ ] Any logged-in user may approve (no `manage_options` check)
- [ ] `?action=cli_auth_approve` verifies nonce before calling `CliController::approve_auth_code()`
- [ ] `static get_base_url()` returns `home_url('/acrossai-mcp-manager/')` — same value used in CliController
- [ ] CSS enqueues only on the frontend auth page (guarded by `QUERY_VAR`)
- [ ] Rewrite rule registered in `Activator::activate()`
- [ ] Pre-ship `detect-rest-endpoints.mjs` exits 0 (all endpoints have `permission_callback`)
