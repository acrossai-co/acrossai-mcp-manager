---

description: "Task list for Phase 6 — REST CLI Authentication Controller + Phase 6.0 FrontendAuth"
---

# Tasks: REST CLI Authentication Controller (+ Phase 6.0 FrontendAuth)

**Feature**: `specs/006-rest-cli-auth/` | **Branch**: `006-rest-cli-auth`
**Input**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/` (6 files), `quickstart.md`, `security-constraints.md`,
`memory-synthesis.md`

**Tests**: Spec DoD requires "PHPUnit tests written and passing for
all new logic — full per-endpoint coverage on the new `cli-rest`
testsuite". Tests are **REQUIRED** and included throughout. Q1–Q4
clarifications add specific regression gates (Recorder integration,
Content-Type allow-list, App Password naming, session-token binding).

**Organization**: Tasks are grouped by user story (US1=health,
US2=auth/start, US3=auth/status, US4=servers, US5=auth/exchange,
US6=approve_auth_code + FrontendAuth approval). Setup, Foundational,
Phase 6.0 (FrontendAuth class), Recorder, CliController skeleton, and
Polish phases are independent of stories.

**Critical security gates**:
- **TASK-SEC-201** — S8 candidate documentation in PR description
- **TASK-SEC-202** — B10 deferral documentation in PR description
- **TASK-Q2** — strict Content-Type allow-list gate (per FR-015)
- **TASK-Q3** — App Password naming uniqueness gate
- **TASK-Q4** — session-token server-binding gate (single-server `/servers` response)

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Maps task to user story (US1…US6); Setup/Foundational/Polish have no story label
- File paths are repo-root-relative; every code task names the exact file

## Path Conventions

- WordPress plugin layout (constitution Architecture & UI Standards):
  - `includes/REST/CliController.php` — 5 REST routes + static method + class constants (namespace `AcrossAI_MCP_Manager\Includes\REST`)
  - `includes/Database/CliAuthLog/Recorder.php` — NEW per Q1 — A11-style stateless static helper (namespace `AcrossAI_MCP_Manager\Includes\Database\CliAuthLog`)
  - `public/Partials/FrontendAuth.php` — Phase 6.0 absorption — 4 Loader callbacks + static get_base_url + 4 private renderers (namespace `AcrossAI_MCP_Manager\Public\Partials`)
  - `includes/Activator.php` + `includes/Main.php` — extended for FrontendAuth wiring + rewrite rule
  - `tests/phpunit/RestCli/*Test.php` + `tests/phpunit/FrontendAuth/*Test.php` — PHPUnit suite under new "cli-rest" testsuite (reuses Phase 5.0 WP-PHPUnit bootstrap)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Pre-flight checks before any code change.

- [x] T001 Verify the feature directory is intact — `specs/006-rest-cli-auth/{spec,plan,research,data-model,quickstart,security-constraints,memory-synthesis}.md` and `contracts/*.md` (6 files: health, auth-start, auth-status, auth-exchange, servers, frontend-auth-page) and `checklists/requirements.md` all present
- [x] T002 [P] Confirm `phpcs.xml.dist` baseline exclusions still apply (D5 — filename casing, `$_instance` prefix, file docblocks)
- [x] T003 [P] Confirm `composer.json` PSR-4 mapping is intact (`AcrossAI_MCP_Manager\\Includes\\` → `includes/`, `AcrossAI_MCP_Manager\\Public\\` → `public/`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Hard gates + Phase 5.0 harness reuse + directory scaffolding.

**⚠️ CRITICAL**: No story work begins until **T004** P0 gate passes.

- [x] T004 **P0 GATE — STOP if it fails**: Verify Phase 2 + Phase 5 dependencies merged to `feature/issue-3`:
  1. `Includes\Database\MCPServer\Query` exists with `query/add_item/update_item/delete_item` AND supports `server_slug` filter
  2. `Includes\Database\CliAuthLog\Query` exists with same interface AND its Phase 5 OAuth column extension (`redirect_uri`, `code_challenge`, `code_challenge_method`, `scope`) has been applied
  3. `wp_acrossai_mcp_cli_auth_logs` table exists at DB_VERSION 0.0.2 (Phase 5 schema)
  4. Phase 5.0 WP-PHPUnit harness present: `tests/bootstrap-wp.php` + `bin/install-wp-tests.sh` + `phpunit.xml.dist` with `oauth` testsuite already shipped
  5. WP core `WP_Application_Passwords` class available on the target WP version (6.9+ default; 5.6+ minimum)

  If any check fails, escalate to merging the prerequisite PR before this phase proceeds.

- [x] T005 Update `phpunit.xml.dist` to add a third `<testsuite name="cli-rest">` referencing `tests/phpunit/RestCli/` AND `tests/phpunit/FrontendAuth/`. Same bootstrap as the existing `oauth` testsuite (`tests/bootstrap-wp.php`).

- [x] T006 [P] Create directory `includes/REST/` (if absent)
- [x] T007 [P] Create directory `includes/Database/CliAuthLog/` (already exists from Phase 2; verify; no creation needed)
- [x] T008 [P] Create directories `tests/phpunit/RestCli/`, `tests/phpunit/RestCli/fixtures/`, `tests/phpunit/FrontendAuth/`
- [x] T009 [P] Confirm `public/Partials/` directory exists (should — Phase 1 boilerplate)

**Checkpoint**: T004 passes, `cli-rest` testsuite registered, source + test directories scaffolded.

---

## Phase 3: Phase 6.0 — FrontendAuth Class (Absorbed Phase 3 module)

**Goal**: The browser approval page at `/acrossai-mcp-manager/` works end-to-end so US2's `auth_url` lands on a valid page AND US5's `/auth/exchange` has been preceded by a working consent flow. Per D11 Phase X.0 absorption pattern — this phase MUST ship FrontendAuth alongside CliController.

**Independent Test**: Visit `/acrossai-mcp-manager/?action=cli_auth&code=<code>&server=<server>` as a logged-in admin → see the consent page render with Approve button. Click Approve → 302 redirect to `?action=cli_auth_approved` → see success page.

### Implementation for FrontendAuth (Phase 6.0)

- [x] T010 Implement `public/Partials/FrontendAuth.php` skeleton (singleton, private ctor, no hooks; namespace `AcrossAI_MCP_Manager\Public\Partials`). Class constants per `contracts/frontend-auth-page.md`:
  - `const PAGE_SLUG = 'acrossai-mcp-manager';`
  - `const QUERY_VAR = 'acrossai_mcp_frontend_auth';`
- [x] T011 Implement `FrontendAuth::register_rewrite_rule()` per R6 — single rule `'^acrossai-mcp-manager/?$'` → `'index.php?acrossai_mcp_frontend_auth=1'`. (No `.` in pattern → B4 not triggered.)
- [x] T012 Implement `FrontendAuth::add_query_var( array $vars ): array` per `contracts/frontend-auth-page.md` — append `self::QUERY_VAR`
- [x] T013 Implement `FrontendAuth::get_base_url(): string` (STATIC) per `contracts/frontend-auth-page.md` — returns `home_url( '/' . self::PAGE_SLUG . '/' )`. Used by `CliController::handle_auth_start` (US2) AND by `FrontendAuth::handle_approve` itself.
- [x] T014 Implement `FrontendAuth::maybe_render_page()` dispatcher per R7 + `contracts/frontend-auth-page.md` — short-circuit on query var; `nocache_headers`; login redirect; admin capability check; feature-flag check; action switch.
- [x] T015 Implement private `FrontendAuth::handle_cli_auth( string $code, string $server ): void` per `contracts/frontend-auth-page.md` — render consent form with Approve button + `wp_create_nonce( 'cli_auth_approve_' . $code )` action URL.
- [x] T016 Implement private `FrontendAuth::handle_approve( string $code, string $server ): void` per R8 — `check_admin_referer` + `manage_options` recheck + static call to `CliController::approve_auth_code( $code, get_current_user_id() )` + 302 to `?action=cli_auth_approved` on success, `wp_die(400)` on false return.
- [x] T017 Implement private `FrontendAuth::handle_approved(): void` per `contracts/frontend-auth-page.md` — success page ("CLI Authorization Approved — return to your CLI").
- [x] T018 Implement private `FrontendAuth::render_disabled_notice(): void` per `contracts/frontend-auth-page.md` — `status_header(503)` + "CLI Login Not Enabled" page.
- [x] T019 Implement private `FrontendAuth::render_page_shell( string $content ): void` per `contracts/frontend-auth-page.md` — minimal HTML shell; **NO `wp_head()`** call; inline CSS; pre-escaped `$content`.
- [x] T020 Implement public `FrontendAuth::enqueue_assets(): void` — empty (no JS this phase; CSS is inline in `render_page_shell`).
- [x] T021 Extend `includes/Activator.php` — call `FrontendAuth::instance()->register_rewrite_rule()` + ensure `flush_rewrite_rules()` is called once after the registration block.
- [x] T022 Wire FrontendAuth hooks in `includes/Main.php::define_public_hooks()`:
  - `init` → `register_rewrite_rule`
  - `query_vars` → `add_query_var`
  - `template_redirect` → `maybe_render_page` (priority 10)
  - `wp_enqueue_scripts` → `enqueue_assets`

### Tests for FrontendAuth

- [x] T023 [P] `tests/phpunit/FrontendAuth/MaybeRenderPageTest.php` per `contracts/frontend-auth-page.md` Test Invariants — 5 acceptance branches: query-var-absent short-circuit, not-logged-in 302, non-admin 403, feature-flag-off 503, unknown-action falls to `handle_cli_auth('','')` "missing code" page. Plus `test_page_shell_omits_wp_head` — assert output buffer does NOT contain `<link rel='stylesheet'` or `<script src=`.
- [x] T024 [P] `tests/phpunit/FrontendAuth/HandleApproveTest.php` — 3 branches: missing-nonce wp_die, invalid-code wp_die(400), valid-code 302 to `cli_auth_approved` AND `CliController::approve_auth_code` is called with `get_current_user_id()`. Use a test double / closure capture to verify the static call.

**Checkpoint**: FrontendAuth page renders consent + approval flow. The static `approve_auth_code` is still a stub — implemented in Phase 8 below.

---

## Phase 4: Recorder Helper Class (per Q1 Clarification)

**Goal**: A11-style stateless static helper class for audit writes. Required by FrontendAuth (T016 already calls it indirectly via CliController) and CliController (US5). Per Q1: stateless, static methods only, calls into Phase 2's `( new Query() )->add_item(...)`.

**Independent Test**: `\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Recorder::record_approved( 1, 'srv-1', 'sha256-hash' )` writes a row with `status='approved'`, `user_id=1`, `server_id=1` (resolved), `auth_code_hash='sha256-hash'`, `approved_at=NOW()`.

### Implementation

- [x] T025 Implement `includes/Database/CliAuthLog/Recorder.php` per Q1 Clarification — class shape:
  ```php
  namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;
  final class Recorder {
      public static function record_approved( int $user_id, string $server_slug, string $auth_code_hash ): void { ... }
      public static function record_success( int $user_id, string $server_slug, string $auth_code_hash, string $app_password_uuid ): void { ... }
      // No public/private __construct; no instance state; no hooks; not a singleton (A11-style).
  }
  ```
  Both methods MUST `try/catch (\Throwable)` around `( new Query() )->add_item(...)`; on failure → `error_log()` only; never throw or return failure.
- [x] T026 [P] Inside `Recorder::record_approved`, resolve `server_slug` to numeric `server_id` via `MCPServer\Query::query(['server_slug' => $server_slug, 'number' => 1])` for the audit-row `server_id` column. If resolution fails, write the row with `server_id = 0` (graceful degradation; the slug is still in the audit chain via other rows).
- [x] T027 [P] Inside `Recorder::record_success`, same server resolution pattern; also populate `app_password_uuid` and `completed_at`.

### Tests

- [x] T028 [P] `tests/phpunit/RestCli/RecorderTest.php` — verify both static methods write rows with the correct column values; `auth_code_hash` is the SHA-256 hex passed in (not double-hashed); audit failures are silent (mock `Query::add_item` to throw → assert `record_*` returns normally and no exception propagates).
- [x] T029 [P] `tests/phpunit/RestCli/RecorderMissingClassTest.php` — Q1 / A11-style hardening: when `CliAuthLog\Query` class doesn't exist (simulate via runkit or by routing the call through a wrapper), `record_*` MUST log via `error_log` and return without throwing.

**Checkpoint**: Recorder writes audit rows for both `status='approved'` and `status='success'` paths; failures are silent.

---

## Phase 5: CliController Skeleton + Class Constants + Permission Callback

**Goal**: The class skeleton with 4 class constants, the singleton ceremony, and the `verify_session_token` permission callback that reads the Q4-bound transient shape. No endpoint logic yet — those land in Phases 6-10.

**Independent Test**: `\AcrossAI_MCP_Manager\Includes\REST\CliController::instance()` instantiates cleanly. `register_routes()` registers all 5 REST routes (each as a stub returning HTTP 501). `verify_session_token()` permission callback returns `true` for a valid Bearer token (transient written manually in the test), `WP_Error 401` otherwise.

### Implementation

- [x] T030 Implement `includes/REST/CliController.php` skeleton (singleton + private ctor; namespace `AcrossAI_MCP_Manager\Includes\REST`). Class constants per FR-009:
  ```php
  const REST_NAMESPACE         = 'acrossai-mcp-manager/v1';
  const AUTH_TRANSIENT_PREFIX  = 'acrossai_cli_auth_';
  const SESSION_TRANSIENT_PREFIX = 'acrossai_session_';
  const AUTH_CODE_TTL          = 300;
  const SESSION_TOKEN_TTL      = 600;
  const APP_PASSWORD_TTL_INFO  = 2592000;  // 30 days — for the response envelope only
  ```
- [x] T031 Implement `register_routes()` per `contracts/*.md` — register 5 routes under `self::REST_NAMESPACE`:
  - `GET /health` → `handle_health` (permission_callback: `__return_true`)
  - `POST /auth/start` → `handle_auth_start` (permission_callback: `__return_true`)
  - `GET /auth/status` → `handle_auth_status` (permission_callback: `__return_true`)
  - `GET /servers` → `handle_servers` (permission_callback: `[ $this, 'verify_session_token' ]`)
  - `POST /auth/exchange` → `handle_auth_exchange` (permission_callback: `__return_true`)
  All callbacks return HTTP 501 stub responses initially; replaced in Phases 6-10.
- [x] T032 Implement `verify_session_token( WP_REST_Request $request )` per R2 + R8 + Q4 — read `Authorization` header (with `REDIRECT_HTTP_AUTHORIZATION` fallback per Phase 5 R2); 64-char length guard; case-insensitive `Bearer` prefix; read transient as `array{user_id, server_id}` per Q4; on success `wp_set_current_user( (int) $payload['user_id'] )` AND `$request->set_param( '_bound_server_id', (string) $payload['server_id'] )`; return `true` or `WP_Error('rest_unauthorized', '...', ['status' => 401])`.
- [x] T033 Implement private `check_content_type( WP_REST_Request $request ): ?WP_REST_Response` helper per R9 + FR-015 (Q2) — returns `null` if Content-Type is in the allow-list (`application/json` or `application/x-www-form-urlencoded`, each with optional `;charset=...`); returns HTTP 400 `{"error":"invalid_request"}` envelope otherwise. Called as Step 0 of `handle_auth_start` and `handle_auth_exchange`.
- [x] T034 Wire `CliController` via Loader in `includes/Main.php::define_public_hooks()`:
  ```php
  $cli_controller = \AcrossAI_MCP_Manager\Includes\REST\CliController::instance();
  $this->loader->add_action( 'rest_api_init', $cli_controller, 'register_routes' );
  ```
  Confirm zero `add_action`/`add_filter` calls inside `CliController` class itself (T076-style grep gate fires in Polish).

**Checkpoint**: 5 REST routes registered (each returning 501 stubs); `verify_session_token` works on a hand-seeded session transient; `check_content_type` helper available.

---

## Phase 6: User Story 1 — Health Endpoint (P1)

**Goal**: `GET /health` returns plugin status + site_slug. Simplest endpoint; lands first to establish the contract for downstream endpoints.

**Independent Test**: `curl https://example.com/wp-json/acrossai-mcp-manager/v1/health` returns 200 + `{plugin_installed, plugin_active, version, site_slug}` per `contracts/health.md`.

### Implementation for User Story 1

- [x] T035 [US1] Implement `CliController::handle_health( WP_REST_Request $request ): WP_REST_Response` per `contracts/health.md` — returns array with `plugin_installed: true`, `plugin_active: true`, `version: ACROSSAI_MCP_MANAGER_VERSION`, `site_slug: sanitize_title( get_bloginfo('name') )`.
- [x] T036 [US1] Generate `tests/phpunit/RestCli/fixtures/health.json` golden fixture per `contracts/health.md` — with `{VERSION}` and `{SITE_SLUG}` placeholders.

### Tests for User Story 1

- [x] T037 [P] [US1] `tests/phpunit/RestCli/HealthEndpointTest.php` — call the endpoint via `WP_REST_Request`; assert HTTP 200; assert response shape matches the golden fixture (with placeholder substitution).
- [x] T038 [P] [US1] `tests/phpunit/RestCli/HealthEndpointTest::test_site_slug_uses_sanitize_title` — set `blogname` option to `"Example Site — Production"`, hit `/health`, assert `site_slug === 'example-site-production'`.

**Checkpoint**: `/health` returns 200 + correct envelope; `site_slug` correctly derived.

---

## Phase 7: User Story 2 — Auth Start Endpoint (P1)

**Goal**: `POST /auth/start` issues a 32-hex `auth_code`, writes the E1 transient, returns `{auth_code, auth_url, expires_in: 300}`. The `auth_url` points at FrontendAuth (which Phase 3 already shipped).

**Independent Test**: `curl -X POST -H 'Content-Type: application/json' -d '{"server_id":"x"}' /wp-json/acrossai-mcp-manager/v1/auth/start` returns 200 + the success envelope. The transient `acrossai_cli_auth_<auth_code>` exists with TTL 300.

### Implementation for User Story 2

- [x] T039 [US2] Implement `CliController::handle_auth_start( WP_REST_Request $request ): WP_REST_Response` per `contracts/auth-start.md`:
  1. Call `$this->check_content_type( $request )` (T033) FIRST — return `invalid_request` 400 if rejected.
  2. Read `server_id` via `sanitize_text_field( (string) $request->get_param('server_id') )`; empty → REST 400 `rest_missing_callback_param` (WP-native default).
  3. Generate `$auth_code` via `bin2hex( random_bytes( 16 ) )` inside `try/catch (\Throwable)` — on entropy failure return HTTP 500 `server_error`.
  4. Write transient `self::AUTH_TRANSIENT_PREFIX . $auth_code` with array shape per E1 data-model + TTL `self::AUTH_CODE_TTL`. If `set_transient` returns false → HTTP 500.
  5. Compose `$auth_url` via `\AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url() . '?' . http_build_query([ 'action' => 'cli_auth', 'code' => $auth_code, 'server' => $server_id ])`.
  6. Return 200 + `{auth_code, auth_url, expires_in: self::AUTH_CODE_TTL}`.
- [x] T040 [US2] Generate `tests/phpunit/RestCli/fixtures/auth-start-success.json` golden fixture per `contracts/auth-start.md` with `{AUTH_CODE}` + `{AUTH_URL}` placeholders.

### Tests for User Story 2

- [x] T041 [P] [US2] `tests/phpunit/RestCli/AuthStartEndpointTest::test_happy_path` — POST with valid `server_id` → 200; response matches golden fixture shape; `$response['auth_code']` matches `/^[a-f0-9]{32}$/`; transient exists with TTL ≤ 300 + 1s.
- [x] T042 [P] [US2] `AuthStartEndpointTest::test_missing_server_id` — POST without `server_id` → 400 `rest_missing_callback_param`.
- [x] T043 [P] [US2] `AuthStartEndpointTest::test_two_calls_produce_different_codes` — call twice; assert codes differ.
- [x] T044 [P] [US2] `AuthStartEndpointTest::test_auth_url_starts_with_base_url` — assert `auth_url` begins with `FrontendAuth::get_base_url()` + has `action=cli_auth&code=...&server=...`.
- [x] T045 [P] [US2] **TASK-Q2** — `AuthStartEndpointTest::test_content_type_rejected` — POST with `Content-Type: text/plain` → 400 `invalid_request` BEFORE field validation (assert `error: invalid_request`, NOT `rest_missing_callback_param`).
- [x] T046 [P] [US2] **TASK-Q2** — `AuthStartEndpointTest::test_missing_content_type_rejected` — POST with NO `Content-Type` header → 400 `invalid_request` (per Phase 5 SEC-002 inheritance).
- [x] T047 [P] [US2] `AuthStartEndpointTest::test_transient_shape` — POST → read the transient back → assert it's an array with all 5 keys (`server_id`, `status: 'pending'`, `user_id: null`, `session_token: null`, `created_at: <recent unix>`).

**Checkpoint**: `/auth/start` issues codes correctly; Q2 Content-Type gate enforced; transient shape matches E1.

---

## Phase 8: User Story 3 — Auth Status Endpoint (P1)

**Goal**: `GET /auth/status?code=<code>&server=<server>` returns `{"approved": false}` while pending; `{"approved": true, "token": <session_token>}` after admin approves. Q4 oracle defense: server mismatch returns `{"approved": false}`, NOT 404.

**Independent Test**: Issue a code via US2. Poll `/auth/status` — `{"approved": false}`. Manually flip the transient to `approved` + add `session_token`. Poll again — `{"approved": true, "token": ...}`.

### Implementation for User Story 3

- [x] T048 [US3] Implement `CliController::handle_auth_status( WP_REST_Request $request )` per `contracts/auth-status.md`:
  1. Sanitize `code` + `server` query params.
  2. Read transient `self::AUTH_TRANSIENT_PREFIX . $code`. Absent → `WP_Error( 'auth_code_not_found', '...', ['status' => 404] )`.
  3. If `status === 'approved'` AND `hash_equals( (string) $payload['server_id'], $server )` → return `{approved: true, token: (string) $payload['session_token']}`.
  4. Otherwise → return `{approved: false}`. (Includes the server-mismatch case per Q4.)
- [x] T049 [US3] Generate fixtures `tests/phpunit/RestCli/fixtures/auth-status-pending.json` + `auth-status-approved.json` per `contracts/auth-status.md`.

### Tests for User Story 3

- [x] T050 [P] [US3] `tests/phpunit/RestCli/AuthStatusEndpointTest::test_pending` — issue code via Storage write, poll → `{approved: false}` HTTP 200.
- [x] T051 [P] [US3] `AuthStatusEndpointTest::test_approved` — issue + flip transient + add session_token, poll → `{approved: true, token: ...}` HTTP 200.
- [x] T052 [P] [US3] `AuthStatusEndpointTest::test_unknown_code_404` — poll with random code → HTTP 404 + `auth_code_not_found`.
- [x] T053 [P] [US3] **TASK-Q4** — `AuthStatusEndpointTest::test_server_mismatch_returns_pending_no_oracle` — issue + approve, then poll with WRONG server slug → response is `{approved: false}` HTTP 200, NOT 404. Asserts the Q4 oracle-defense pattern.

**Checkpoint**: `/auth/status` polling works for both states; Q4 oracle defense verified.

---

## Phase 9: User Story 6 — Approve Auth Code Static Method (P1)

**Goal**: `CliController::approve_auth_code( $auth_code, $user_id ): bool` — the static method FrontendAuth calls. Updates the E1 transient to `approved`, generates session_token, writes the E2 transient with Q4-bound shape `array{user_id, server_id}`, calls `Recorder::record_approved`.

**Note**: This phase is US6 because the spec lists it as User Story 6 even though it's needed before US5 (auth/exchange). FrontendAuth (Phase 3) already calls it but with a stub return; this phase implements the real logic.

**Independent Test**: `CliController::approve_auth_code( $code, 1 )` for a pending transient → returns `true`; E1 transient updated; E2 transient exists with `array{user_id: 1, server_id: <orig>}`; audit row written.

### Implementation for User Story 6

- [x] T054 [US6] Implement `public static function approve_auth_code( string $auth_code, int $user_id ): bool` on `CliController` per FR-008 (refreshed per Q4):
  1. Read E1 transient `self::AUTH_TRANSIENT_PREFIX . $auth_code`. If absent OR `status !== 'pending'` → return `false`.
  2. Generate `$session_token` via `bin2hex( random_bytes( 16 ) )` in `try/catch (\Throwable)`.
  3. Update E1 transient: `status: 'approved'`, `user_id: $user_id`, `session_token: $session_token`. Re-write with `self::AUTH_CODE_TTL`.
  4. Write E2 transient `self::SESSION_TRANSIENT_PREFIX . $session_token` with **Q4-bound shape** `array( 'user_id' => (int) $user_id, 'server_id' => (string) $payload['server_id'] )` + TTL `self::SESSION_TOKEN_TTL`.
  5. Call `\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Recorder::record_approved( $user_id, $payload['server_id'], hash( 'sha256', $auth_code ) )` — best-effort.
  6. Return `true`.

### Tests for User Story 6

- [x] T055 [P] [US6] `tests/phpunit/RestCli/ApproveAuthCodeStaticTest::test_pending_transient_approved` — issue code, call `approve_auth_code` → returns `true`; E1 transient now `status='approved'`; `session_token` is 32 hex chars.
- [x] T056 [P] [US6] `ApproveAuthCodeStaticTest::test_unknown_code_returns_false` — call with random code → returns `false`; no E2 transient created.
- [x] T057 [P] [US6] `ApproveAuthCodeStaticTest::test_already_approved_returns_false` — call twice → first returns `true`, second returns `false`. Single-approval-per-code.
- [x] T058 [P] [US6] **TASK-Q4** — `ApproveAuthCodeStaticTest::test_session_transient_shape_binds_server_id` — after approve, read E2 transient back → assert it is `array` with `user_id` AND `server_id` keys; `server_id` matches the E1 server_id. Asserts the Q4 binding.
- [x] T059 [P] [US6] `ApproveAuthCodeStaticTest::test_audit_row_written` — after approve, query `acrossai_mcp_cli_auth_logs` for `auth_code_hash = sha256($code)` → exactly one row with `status='approved'`, `user_id=<arg>`, `approved_at` set.

**Checkpoint**: FrontendAuth's Approve button now produces a real session token; static method is fully wired.

---

## Phase 10: User Story 4 — Servers Endpoint (P1, Bearer-auth)

**Goal**: `GET /servers` with `Authorization: Bearer <session_token>` returns the SINGLE consented server (Q4 binding). AccessControl-filtered when the vendor package is present; full server return when absent.

**Independent Test**: After Phase 9 issues a session token, `curl -H 'Authorization: Bearer <token>' /servers` returns `{servers: [ <single-server> ]}`. Missing header → 401.

### Implementation for User Story 4

- [x] T060 [US4] Implement `CliController::handle_servers( WP_REST_Request $request ): WP_REST_Response` per `contracts/servers.md` (Q4-refreshed body):
  1. Read `$bound_server_id` via `(string) $request->get_param( '_bound_server_id' )` (set by `verify_session_token`).
  2. Query single server via `MCPServer\Query::query( ['server_slug' => $bound_server_id, 'is_enabled' => 1, 'number' => 1] )`.
  3. If empty → return `{servers: []}` HTTP 200.
  4. If `class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' )` AND `AccessControlManager::instance()->user_has_access( get_current_user_id(), $ns, $route )` returns false → return `{servers: []}` HTTP 200.
  5. Otherwise compose the single entry per the contract: `{id, name, description, enabled, version, namespace, route, mcp_url}`; return `{servers: [ <entry> ]}` HTTP 200.

### Tests for User Story 4

- [x] T061 [P] [US4] `tests/phpunit/RestCli/ServersEndpointTest::test_happy_path_returns_single_server` — issue session token bound to server-A, call `/servers` with Bearer → response has EXACTLY ONE entry, with `id` matching server-A.
- [x] T062 [P] [US4] **TASK-Q4** — `ServersEndpointTest::test_only_bound_server_returned` — set up TWO enabled servers (A and B); issue session token bound to A; assert `/servers` returns ONLY server-A, never server-B. Even if the granting user has access to both.
- [x] T063 [P] [US4] `ServersEndpointTest::test_missing_authorization_header_401` — call `/servers` with no `Authorization` → 401 `rest_unauthorized`.
- [x] T064 [P] [US4] `ServersEndpointTest::test_unknown_token_401` — call with `Bearer aaa...` (random) → 401.
- [x] T065 [P] [US4] `ServersEndpointTest::test_bound_server_disabled_returns_empty` — issue token bound to A, then `is_enabled = 0` on A, then call → `{servers: []}` HTTP 200.
- [x] T066 [P] [US4] `ServersEndpointTest::test_access_control_filters_bound_server` — install a stub ACM that returns false; call → `{servers: []}` HTTP 200.
- [x] T067 [P] [US4] `ServersEndpointTest::test_access_control_absent_returns_full` — no ACM class; call → single entry with the bound server (graceful degrade per Constitution §V).

**Checkpoint**: `/servers` enforces Bearer + returns only the bound server; Q4 binding verified end-to-end.

---

## Phase 11: User Story 5 — Auth Exchange Endpoint (P1, App Password creation)

**Goal**: `POST /auth/exchange` validates 7 steps + Step 0 Content-Type guard, creates a WP Application Password with Q3 unique name, deletes BOTH transients, writes `record_success`. The most complex endpoint with 9 distinct error envelopes.

**Independent Test**: After US6 approves a code, `curl -X POST -d '{"code":"...","server_id":"..."}' /auth/exchange` returns `{app_password, username, user_id, expires_in: 2592000, server_id}`. Second call with same code → `{"error":"invalid_code"}` HTTP 400.

### Implementation for User Story 5

- [x] T068 [US5] Implement `CliController::handle_auth_exchange( WP_REST_Request $request ): WP_REST_Response` Step 0 — call `$this->check_content_type( $request )` per FR-015 / Q2 / TASK-Q2; return `invalid_request` 400 BEFORE field validation.
- [x] T069 [US5] Implement Step 1 — read transient via sanitized `code`; absent → 400 `invalid_code`.
- [x] T070 [US5] Implement Step 2 — `status !== 'approved'` → 400 `not_approved`.
- [x] T071 [US5] Implement Step 3 — `get_userdata( $stored_user_id ) === false` → 400 `invalid_user`.
- [x] T072 [US5] Implement Step 4 — `class_exists( 'WP_Application_Passwords' ) === false` → 501 `not_supported` (audit row NOT written).
- [x] T073 [US5] Implement Step 5 — missing/empty `server_id` field → 400 `missing_server`.
- [x] T074 [US5] Implement Step 6 — `! hash_equals( $stored_server_id, $request_server_id )` → 400 `server_mismatch`. Transients NOT deleted.
- [x] T075 [US5] Implement Step 7 — `MCPServer\Query::query( ['server_slug' => $server_id, 'is_enabled' => 1, 'number' => 1] )` empty → 403 `invalid_server`.
- [x] T076 [US5] Implement Step 8 (success path) per R5:
  1. **TASK-Q3** — compose `$app_pwd_name = 'AcrossAI MCP Manager CLI - ' . $resolved_server_slug . ' - ' . substr( $code, 0, 8 )`.
  2. Call `WP_Application_Passwords::create_new_application_password( $stored_user_id, [ 'name' => $app_pwd_name ] )` inside `try/catch (\Throwable)`.
  3. If `is_wp_error( $result )` → log + 500 `server_error`. Transients NOT deleted (legitimate retry).
  4. Destructure `list( $raw_password, $record ) = $result`.
  5. `delete_transient( self::AUTH_TRANSIENT_PREFIX . $code )` — single-use enforcement.
  6. `delete_transient( self::SESSION_TRANSIENT_PREFIX . $stored_session_token )` — also single-use.
  7. Call `\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Recorder::record_success( $stored_user_id, $server_id, hash( 'sha256', $code ), (string) $record['uuid'] )` — best-effort.
  8. Return 200 + `{app_password: $raw_password, username: $user->user_login, user_id: (int) $stored_user_id, expires_in: 2592000, server_id: $server_id}` + headers `Cache-Control: no-store`, `Pragma: no-cache`.
- [x] T077 [US5] Generate fixtures for all 9 response shapes per `contracts/auth-exchange.md`: success + 8 errors (`invalid_request`, `invalid_code`, `not_approved`, `invalid_user`, `not_supported`, `missing_server`, `server_mismatch`, `invalid_server`).

### Tests for User Story 5 — per-RFC-error-path coverage

- [x] T078 [P] [US5] `tests/phpunit/RestCli/AuthExchangeEndpointTest::test_happy_path` — full flow: issue code, approve, exchange. Assert 200; assert response matches success fixture shape; assert App Password created in WP-core; assert BOTH transients deleted; assert audit row with `status='success'` written.
- [x] T079 [P] [US5] **TASK-Q3** — `AuthExchangeEndpointTest::test_app_password_name_has_code_prefix` — exchange with known `$auth_code = 'a1b2c3d4...'`; query WP-core's user app passwords; assert one row with `name === 'AcrossAI MCP Manager CLI - ' . $server_slug . ' - a1b2c3d4'`.
- [x] T080 [P] [US5] **TASK-Q3** — `AuthExchangeEndpointTest::test_two_authorizations_produce_unique_names` — issue+approve+exchange TWICE for the same admin + server, with different auth codes. Assert the TWO App Passwords have DIFFERENT names (because the code-prefix suffix differs).
- [x] T081 [P] [US5] **TASK-Q2** — `AuthExchangeEndpointTest::test_missing_content_type_rejected` — POST with no `Content-Type` → 400 `invalid_request` BEFORE field validation runs (assert no `invalid_code` envelope even if `code` is missing).
- [x] T082 [P] [US5] **TASK-Q2** — `AuthExchangeEndpointTest::test_bogus_content_type_rejected` — POST with `Content-Type: text/plain` → 400 `invalid_request`.
- [x] T083 [P] [US5] `AuthExchangeEndpointTest::test_invalid_code` — POST with nonexistent code → 400 `invalid_code`.
- [x] T084 [P] [US5] `AuthExchangeEndpointTest::test_not_approved` — issue but DON'T approve, POST → 400 `not_approved`.
- [x] T085 [P] [US5] `AuthExchangeEndpointTest::test_invalid_user` — issue + approve, then DELETE the user; POST → 400 `invalid_user`.
- [x] T086 [P] [US5] `AuthExchangeEndpointTest::test_not_supported` — install `add_filter( 'wp_is_application_passwords_available', '__return_false' )`; POST → 501 `not_supported`; assert NO audit row written.
- [x] T087 [P] [US5] `AuthExchangeEndpointTest::test_missing_server` — POST without `server_id` → 400 `missing_server`.
- [x] T088 [P] [US5] `AuthExchangeEndpointTest::test_server_mismatch_preserves_transients` — issue with server-A, approve, POST with server-B → 400 `server_mismatch`; assert BOTH transients are STILL present (legitimate retry path).
- [x] T089 [P] [US5] `AuthExchangeEndpointTest::test_invalid_server` — issue with server-X-which-was-just-disabled, approve, then `is_enabled=0`; POST → 403 `invalid_server`.
- [x] T090 [P] [US5] `AuthExchangeEndpointTest::test_single_use_after_success` — successful exchange; second POST with same code → 400 `invalid_code` (transient was deleted in step 8).
- [x] T091 [P] [US5] `AuthExchangeEndpointTest::test_wp_apps_failure_preserves_transients` — mock `WP_Application_Passwords::create_new_application_password` to return `WP_Error`; POST → 500 `server_error`; assert BOTH transients are STILL present (legitimate retry path).

**Checkpoint**: All 9 response shapes per `contracts/auth-exchange.md` exercised by per-RFC-style tests; Q2 + Q3 specifically verified.

---

## Phase 12: Polish & Cross-Cutting Concerns

**Purpose**: Final gate checks before merge. Most tasks parallelizable static-analysis or DoD verification.

### Required verification gates

- [x] T092 [P] **A1 / FR-021 Loader-contract grep gate** — `grep -rnE '^[^*/]*\b(add_action|add_filter)\s*\(' includes/REST/ public/Partials/ includes/Database/CliAuthLog/Recorder.php` MUST return empty. (Q1 adds Recorder.php to the gate scope.)
- [x] T093 [P] **A2 singleton gate** — `grep -rn 'public static function instance' includes/REST/ public/Partials/` MUST return exactly 2 matches (CliController + FrontendAuth). Recorder MUST have ZERO (A11-style stateless per Q1).
- [x] T094 [P] **S2 `__return_true` exemption gate** — `grep -rn 'permission_callback.*__return_true' includes/REST/` MUST return exactly 4 matches (health, auth/start, auth/status, auth/exchange). Any 5th occurrence (e.g. accidentally on `/servers`) is a regression.
- [x] T095 [P] **TASK-Q1 verification** — `grep -rn 'CliAuthLog\\\\Recorder::record_' includes/REST/ public/Partials/` MUST show usage at all 3 call sites: `CliController::approve_auth_code` (record_approved), `CliController::handle_auth_exchange` step 8.7 (record_success). FrontendAuth MUST NOT call Recorder directly (it calls `CliController::approve_auth_code` which internally calls Recorder).
- [x] T096 [P] Run `vendor/bin/phpcs --standard=phpcs.xml.dist includes/REST/ public/Partials/ includes/Database/CliAuthLog/Recorder.php` — expected **0 errors, 0 warnings**.
- [x] T097 [P] Run `vendor/bin/phpstan analyse includes/REST public/Partials includes/Database/CliAuthLog --level=8 --no-progress` — expected **0 errors**.
- [ ] T098 [P] Run `vendor/bin/phpunit --testsuite=cli-rest` — expected **all green**. **Deferred per D12 honest task-status discipline**: 8 test files written (RestCli/* + FrontendAuth/*); execution requires WP-PHPUnit DB provisioned via `bin/install-wp-tests.sh` (same env-blocker as Phase 5's T082).
- [ ] T099 [P] Run `vendor/bin/phpunit --testsuite=oauth` — Phase 5 OAuth suite still green (regression check; the Phase 5 audit-table schema is shared). **Deferred per D12**: same WP-PHPUnit DB requirement; Phase 5 PR #7 has the same outstanding gate.
- [x] T100 [P] Run `vendor/bin/phpunit --testsuite=mcpclients` — Phase 4 MCPClients suite still green.
- [x] T101 [P] **Constant-time comparison regression gate** (per `security-constraints.md` action plan + Phase 5 T089 pattern): run `grep -rnE '(client_secret|access_token|auth_code|session_token).*===|===.*(client_secret|access_token|auth_code|session_token)' includes/REST/ public/Partials/ includes/Database/CliAuthLog/Recorder.php` — expected **0 matches**. Any non-`hash_equals` comparison of a secret value fails the build.
- [x] T102 [P] **Confirmed Secure Patterns DoD walk** (per `security-constraints.md`): walked all 15 items against the merged implementation (2026-06-25). Verified: (1) 128-bit CSPRNG opaque credentials, (2) `hash_equals` on server_id in `/auth/status`, (3) Bearer header fallback `HTTP_AUTHORIZATION` → `REDIRECT_HTTP_AUTHORIZATION`, (4) Q2 Content-Type allow-list via `check_content_type`, (5) Q4 `array{user_id, server_id}` session shape, (6) Single-use enforcement deletes both transients, (7) WP-Apps absent → 501 + no audit, (8) AccessControl optional with `class_exists` guard, (9) Zero `unserialize`, (10) DB writes via Query layer, (11) `render_page_shell` omits `wp_head`, (12) CSRF via `check_admin_referer`, (13) App Password created BEFORE transient deletion (R5 ordering), (14) Raw secrets never persisted, (15) `auth_code_hash` is SHA-256.
- [ ] T103 [P] Run `npm run validate-packages` — pass (Constitution §VI DoD gate; no JS changes expected this phase). **Deferred — informational gate.**

### Manual + DoD

- [ ] T104 Execute the full `quickstart.md` walk (steps 1–8 + 3 manual SC verifications) end-to-end on a clean WP 6.9 / PHP 8.0 install with `WP_DEBUG=true` and `WP_DEBUG_LOG=true`. Confirm zero PHP notices/warnings. Confirm SC-001 through SC-008.
- [ ] T105 **TASK-SEC-201** — Add explicit S8 capture queue note in PR description: "Memory candidate **S8** — Body-authenticated mutating REST routes broader than S7 (covers CLI device-code-grant style). Capture validated by Q2 Content-Type allow-list + Q4 session-token server-binding patterns landing successfully."
- [ ] T106 **TASK-SEC-202** — Add explicit B10 deferral note in PR description: "`/auth/exchange` redemption uses non-atomic `get_transient + delete_transient`. Threat model is weaker than Phase 5 (Q4 server-binding narrows race-loss impact to single consented server). Future hardening may apply atomic CAS via `wp_options` direct UPDATE on a `consumed_at` field."
- [x] T107 Mark spec.md §Success Criteria → Definition of Done Gates checkboxes complete; mark plan.md Status as "Ready for review" — Phase 6 ships. (5 of 8 DoD gates flipped to `[x]` with evidence; T098 PHPUnit cli-rest run + T103 npm + T104 manual quickstart remain deferred until WP-PHPUnit harness is provisioned + a manual walk is performed, per D12 honest task-status discipline.)
- [x] T108 Hand-off note in `data-model.md` of consumer phases:
  - Phase 7 (frontend admin UI for feature flag toggle): not affected by Phase 6 — separate task list
  - Phase 7+ (audit observability — `do_action('acrossai_mcp_cli_audit_failed', ...)`): captured as known follow-up per SEC-103
  - **S8** + **A15** — two memory captures queued for post-implementation `/speckit-memory-md-capture-from-diff`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies; can start immediately.
- **Foundational (Phase 2)**: Depends on Setup; **T004 is a hard P0 gate**. Reuses Phase 5.0 WP-PHPUnit harness — no new bootstrap work.
- **Phase 6.0 FrontendAuth (Phase 3)**: Depends on Foundational. MUST land before US2 (which builds the `auth_url` referencing `FrontendAuth::get_base_url()`) AND before US5 (which exists as the value-delivery step after FrontendAuth's approve flow). T010-T022.
- **Recorder (Phase 4)**: Depends on Foundational. Required by US6 + US5. T025-T029.
- **CliController skeleton (Phase 5)**: Depends on Recorder (because `verify_session_token` permission callback uses A14-style helpers patterns proven by Recorder). T030-T034.
- **US1 Health (Phase 6)**: Depends on CliController skeleton. Independent of all other US phases.
- **US2 Auth Start (Phase 7)**: Depends on CliController skeleton AND FrontendAuth (`get_base_url`). Independent of US3-US6.
- **US3 Auth Status (Phase 8)**: Depends on CliController skeleton. Independent of US2 (can be tested with hand-seeded transients).
- **US6 approve_auth_code (Phase 9)**: Depends on CliController skeleton AND Recorder. Independent of US2-US5 (can be tested standalone via direct static call).
- **US4 Servers (Phase 10)**: Depends on CliController skeleton + US6 (needs `verify_session_token` to work end-to-end). Q4 binding test requires US6 session-token issuance.
- **US5 Auth Exchange (Phase 11)**: Depends on US2 (code issuance) + US6 (approval) + Recorder. The most-downstream phase.
- **Polish (Phase 12)**: Depends on all prior phases.

### Critical Path

```
T004 P0 gate
  ↓
T005 cli-rest testsuite
  ↓
T010-T024 FrontendAuth (Phase 6.0) ─────────┐
T025-T029 Recorder ─────────────────────────┤
                                            ↓
T030-T034 CliController skeleton + verify_session_token
  ├─ T035-T038 US1 Health
  ├─ T039-T047 US2 Auth Start (uses FrontendAuth::get_base_url)
  ├─ T048-T053 US3 Auth Status (Q4 oracle test)
  ├─ T054-T059 US6 approve_auth_code (Q4 binding test)
  │              ↓
  ├─ T060-T067 US4 Servers (Q4 single-server test)
  │              ↓
  └─ T068-T091 US5 Auth Exchange (Q2 Content-Type + Q3 naming + 9 error envelopes)
                  ↓
               T092-T108 Polish + DoD
```

### Parallel Opportunities

- All Phase 1 [P] tasks (T002, T003) run together
- FrontendAuth + Recorder phases (T010-T029) — many [P] tasks within
- CliController skeleton (T030-T034) is mostly sequential
- All Phase 6-11 endpoint-test tasks are [P] within their phase
- Polish gates (T092-T103) all parallel

---

## Implementation Strategy

### MVP First (Phase 1 → Phase 11 minus US4 + US5 polish)

1. Setup + Foundational (T001-T009) — harness ready
2. Phase 6.0 FrontendAuth (T010-T024) — browser approval page works
3. Recorder (T025-T029) — audit boundary ready
4. CliController skeleton (T030-T034) — 5 routes registered as stubs
5. US1 Health (T035-T038) — first endpoint live
6. US2 Auth Start (T039-T047) — CLI can request codes
7. US3 Auth Status (T048-T053) — CLI can poll
8. US6 approve_auth_code (T054-T059) — Approve button works end-to-end
9. **STOP and VALIDATE**: a CLI tool can complete /health → /auth/start → admin approves in browser → /auth/status polling sees approved + token. **Mark this as MVP for shipping.**

### Incremental Delivery

10. US4 Servers (T060-T067) — Bearer auth + Q4 single-server lookup
11. US5 Auth Exchange (T068-T091) — App Password issuance + Q2/Q3 hardening
12. Polish + DoD (T092-T108) — final gates

### Parallel Team Strategy

With 2 developers after T034 completes:
- **Dev A**: US1 Health → US2 Auth Start → US3 Auth Status (sequential to keep context warm)
- **Dev B**: US6 approve_auth_code → US4 Servers (uses session token Dev A's US3 tests don't fully need)

Both converge on US5 Auth Exchange + Polish.

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks
- [Story] label maps task to user story for traceability
- Each user story (except US4 and US5) is independently testable via hand-seeded transients
- Verify tests pass after implementation (regression-style; not strict TDD)
- Commit after each task or logical group of [P] tasks
- **TASK-Q2 (Content-Type allow-list)**: T045 + T046 + T081 + T082 are the load-bearing assertions
- **TASK-Q4 (Session-token server-binding)**: T053 + T058 + T062 are the load-bearing assertions
- **TASK-SEC-201 / SEC-202**: PR description notes — do NOT skip
- **Reuse from Phase 5**: WP-PHPUnit bootstrap, BearerHeaderParsingTest pattern (R2), per-error-path assertion shape (Phase 5's T053 family is the template for T078-T091)
- **Memory captures queued for post-implementation** (per `/speckit-memory-md-capture-from-diff` after merge):
  - **S8** — Body-authenticated mutating REST routes broader than S7
  - **A15** — Database-namespace audit-recorder static helpers follow A11/A14 family

---

## Task Count Summary

| Phase | Task IDs | Count |
|---|---|---|
| 1 — Setup | T001-T003 | 3 |
| 2 — Foundational | T004-T009 | 6 |
| 3 — Phase 6.0 FrontendAuth | T010-T024 | 15 |
| 4 — Recorder (per Q1) | T025-T029 | 5 |
| 5 — CliController skeleton + permission callbacks | T030-T034 | 5 |
| 6 — US1 Health | T035-T038 | 4 |
| 7 — US2 Auth Start (+ Q2 gates) | T039-T047 | 9 |
| 8 — US3 Auth Status (+ Q4 oracle gate) | T048-T053 | 6 |
| 9 — US6 approve_auth_code (+ Q4 binding gate) | T054-T059 | 6 |
| 10 — US4 Servers (+ Q4 single-server gate) | T060-T067 | 8 |
| 11 — US5 Auth Exchange (+ Q2/Q3 gates + 9 error envelopes) | T068-T091 | 24 |
| 12 — Polish | T092-T108 | 17 |
| **Total** | | **108** |

- Implementation tasks: 47
- Test tasks: 42
- Gate / verification / doc tasks: 19

**Task count reflects** the broader scope (3 classes vs Phase 5's 6 classes, but 6 user stories vs Phase 5's 5, and 4 explicit Q1-Q4 regression gates that didn't exist in Phase 5). MVP scope (Phases 1-9, ~62 tasks) ships a working CLI → browser-approve → poll flow. Phases 10-12 add the value-delivery (App Password) + polish.
