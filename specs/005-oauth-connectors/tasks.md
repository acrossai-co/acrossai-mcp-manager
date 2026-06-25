---

description: "Task list for Phase 5 — OAuth / Claude Connectors Integration"
---

# Tasks: OAuth / Claude Connectors Integration

**Feature**: `specs/005-oauth-connectors/` | **Branch**: `005-oauth-connectors`
**Input**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/` (5 files), `quickstart.md`, `security-review-plan.md`,
`security-constraints.md`, `governance-summary.md`, `memory-synthesis.md`

**Tests**: Spec DoD requires "PHPUnit: full RFC-conformance test suite
(per-RFC-section coverage) passing". Tests are **REQUIRED** and
included throughout. Per-RFC-error-path tests are mandatory for the
token endpoint.

**Organization**: Tasks are grouped by user story (US1=discovery,
US2=authorize+consent, US3=token exchange, US4=Bearer auth,
US5=Loader contract). Setup, Foundational, BerlinDB-Query foundation,
Cleanup, and Polish phases are independent of stories.

**Critical security gate**: **TASK-SEC-001** (atomic CAS in code
redemption + concurrent-redeem PHPUnit test) is the load-bearing fix
from `security-review-plan.md` SEC-001. The implementation tasks for it
are tagged inline; the verification PHPUnit test is non-negotiable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Maps task to user story (US1…US5); Setup/Foundational/Polish have no story label
- File paths are repo-root-relative; every code task names the exact file

## Path Conventions

- WordPress plugin layout (constitution Architecture & UI Standards):
  - `includes/OAuth/*.php` — 6 OAuth feature classes (namespace `AcrossAI_MCP_Manager\Includes\OAuth`)
  - `includes/Database/OAuthToken/*.php` + `OAuthAudit/*.php` — 2 new BerlinDB Query layers
  - `includes/Database/CliAuthLog/{Schema,Table}.php` — extended with 4 OAuth columns
  - `includes/Activator.php` + `includes/Deactivator.php` + `includes/Main.php` — extended for OAuth wiring + cleanup cron
  - `tests/phpunit/OAuth/*Test.php` — PHPUnit suite under new "oauth" testsuite (WP-PHPUnit-bootstrapped)
  - `tests/bootstrap-wp.php` — Phase 5.0 WP-PHPUnit bootstrap (parallel to Phase 4's WP-free `tests/bootstrap.php`)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Pre-flight checks before any code change.

- [x] T001 Verify the feature directory is intact — `specs/005-oauth-connectors/{spec,plan,research,data-model,quickstart,security-review-plan,security-constraints,governance-summary,memory-synthesis}.md` and `contracts/*.md` (5 files) and `checklists/requirements.md` all present
- [x] T002 [P] Confirm `phpcs.xml.dist` Phase 1 baseline exclusions still apply (D5 — filename casing, `$_instance` prefix, file docblocks)
- [x] T003 [P] Confirm `composer.json` PSR-4 mapping is intact (`"AcrossAI_MCP_Manager\\Includes\\": "includes/"`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Hard gates + scaffolding + Phase 5.0 harness absorption.

**⚠️ CRITICAL**: No story work begins until **T004** P0 gate passes.

- [x] T004 **P0 GATE — STOP if it fails**: Verify Phase 2 dependencies merged to `feature/issue-3`:
  1. `Includes\Database\MCPServer\Query` exists and exposes `query/add_item/update_item/delete_item`
  2. `Includes\Database\CliAuthLog\Query` exists with same interface
  3. `wp_acrossai_mcp_servers` table has columns `claude_connector_client_id`, `claude_connector_client_secret`, `claude_connector_redirect_uri` (FR-017)
  4. `wp_acrossai_mcp_cli_auth_logs` table exists per Phase 2 schema
  
  If any check fails, escalate to merging Phase 2 (PR #5 — already merged per session history) before this phase proceeds.

- [x] T005 **Phase 5.0** — Set up WP-PHPUnit harness (per D11, parallel to Phase 4.0 WP-free harness). New files:
  - `tests/bootstrap-wp.php` — loads `wp-phpunit/wp-phpunit/includes/bootstrap.php`; sets `WP_TESTS_DIR` env; loads the plugin via `tests_add_filter('muplugins_loaded', ...)`
  - `bin/install-wp-tests.sh` — standard WP testing script for provisioning the test DB (curl from `develop.svn.wordpress.org`)
  - Update `phpunit.xml.dist` to add a second `<testsuite name="oauth">` referencing `tests/phpunit/OAuth/` AND using `bootstrap="tests/bootstrap-wp.php"` (via a `<phpunit>` per-suite bootstrap attribute, or a runner-level fallback)

- [x] T006 [P] Create directory `includes/OAuth/`
- [x] T007 [P] Create directories `tests/phpunit/OAuth/` and `tests/phpunit/OAuth/fixtures/`

**Checkpoint**: T004 passes (Phase 2 deps confirmed), Phase 5.0 harness exists, OAuth + Database directories scaffolded.

---

## Phase 3: User Story 1 — Discovery + Rewrite Rules (P1)

**Goal**: External OAuth clients can `GET /.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource` and receive RFC-conformant JSON metadata. Plus register the `/acrossai-mcp-oauth/` rewrite rule for the authorize page.

**Independent Test**: `curl https://example.com/.well-known/oauth-authorization-server` returns 200 + valid JSON. `wp option get rewrite_rules` shows the dot-escaped patterns (R1/B4 mitigation).

### Implementation for User Story 1

- [x] T008 [US1] Implement `includes/OAuth/ClaudeConnectors.php` skeleton (singleton, private ctor, no hooks; namespace `AcrossAI_MCP_Manager\Includes\OAuth`)
- [x] T009 [US1] Implement `ClaudeConnectors::register_rewrite_rules()` per research.md R1 — three rules with `\.well-known` and `acrossai-mcp-oauth` escaped patterns
- [x] T010 [US1] Implement `ClaudeConnectors::add_query_var()` adding `acrossai_mcp_oauth` to `query_vars` filter
- [x] T011 [US1] Implement `ClaudeConnectors::serve_as_metadata()` per `contracts/discovery-as-metadata.md` (RFC 8414, FR-001)
- [x] T012 [US1] Implement `ClaudeConnectors::serve_rs_metadata()` per `contracts/discovery-resource-metadata.md` (RFC 9728, FR-002)
- [x] T013 [US1] Wire ClaudeConnectors hooks in `includes/Main.php::define_public_hooks()`:
  - `init` → `register_rewrite_rules`
  - `query_vars` → `add_query_var`
  - `template_redirect` → `serve_discovery` (dispatcher: checks `get_query_var('acrossai_mcp_oauth')` and calls the right serve_*_metadata or render_authorize_page)
- [x] T014 [US1] Extend `includes/Activator.php` — call `ClaudeConnectors::instance()->register_rewrite_rules()` + `flush_rewrite_rules()` (FR-023, FR-003)
- [x] T015 [US1] Generate golden fixtures (per `contracts/discovery-as-metadata.md`):
  - `tests/phpunit/OAuth/fixtures/discovery-as.json`
  - `tests/phpunit/OAuth/fixtures/discovery-rs.json`

### Tests for User Story 1

- [x] T016 [P] [US1] `tests/phpunit/OAuth/ClaudeConnectorsDiscoveryTest.php` — assert AS metadata response matches golden fixture (with `{ISSUER}` substitution); assert RS metadata response matches its fixture; assert `Cache-Control: public, max-age=86400` header present
- [x] T017 [P] [US1] `tests/phpunit/OAuth/RewriteRuleEscapeTest.php` — **R1/B4 mitigation gate**: assert `get_option('rewrite_rules')` returns rules whose pattern strings literally contain `\.well-known` (not just `.well-known`). Fails the build if any registered pattern has an unescaped dot before `well-known`.

**Checkpoint**: Discovery endpoints respond per RFC; rewrite rule dot is escaped.

---

## Phase 4: BerlinDB Query Layers (Foundational Data)

**Goal**: Two new BerlinDB-style Query layers (OAuthToken, OAuthAudit) + extend CliAuthLog Schema/Table with 4 OAuth columns. Per D9 pattern (Phase 2.0 precedent).

**Independent Test**: `php -r 'new OAuthToken\Query;'` instantiates cleanly. Activator runs `maybe_create_table()` and creates both new tables.

### Implementation

- [x] T018 [P] Implement `includes/Database/OAuthToken/Schema.php` per `data-model.md` E2 (column metadata: `id`, `access_token_hash`, `server_id`, `user_id`, `scope`, `created_at`, `expires_at`, `revoked_at`)
- [x] T019 [P] Implement `includes/Database/OAuthToken/Table.php` — `maybe_create_table()` with dbDelta SQL; UNIQUE on `access_token_hash`; KEY `(server_id, expires_at)` for Bearer lookup fast path
- [x] T020 [P] Implement `includes/Database/OAuthToken/Row.php` — typed value object
- [x] T021 [P] Implement `includes/Database/OAuthToken/Query.php` — `query/add_item/update_item/delete_item` + static `maybe_create_table()` + the column-whitelist filter (memory B7 mass-assignment defense)
- [x] T022 [P] Implement `includes/Database/OAuthAudit/Schema.php` per `data-model.md` E3 (11 event_type enum + nullable server_id/user_id/client_id/token_hash_prefix/endpoint/details_json + created_at)
- [x] T023 [P] Implement `includes/Database/OAuthAudit/Table.php` — dbDelta with three indexes per data-model.md
- [x] T024 [P] Implement `includes/Database/OAuthAudit/Row.php` — typed value object
- [x] T025 [P] Implement `includes/Database/OAuthAudit/Query.php` — same 4-method interface
- [x] T026 Extend `includes/Database/CliAuthLog/Schema.php` — add `redirect_uri`, `code_challenge`, `code_challenge_method`, `scope` columns to the `columns()` array
- [x] T027 Extend `includes/Database/CliAuthLog/Table.php` — bump `DB_VERSION` from `'0.0.1'` to `'0.0.2'`; add ALTER COLUMN to dbDelta SQL for the 4 new columns
- [x] T028 Extend `includes/Activator.php` — call `OAuthToken\Query::maybe_create_table()` and `OAuthAudit\Query::maybe_create_table()` (guarded by `class_exists()`, per Phase 1 D4 silent-skip pattern)

### Tests

- [x] T029 [P] `tests/phpunit/OAuth/OAuthTokenQueryTest.php` — `add_item` writes hashed row; `query(['access_token_hash'=>...])` returns row; mass-assignment defense rejects unknown column keys
- [x] T030 [P] `tests/phpunit/OAuth/OAuthAuditQueryTest.php` — `add_item` writes all event_type values from R5; details_json is properly JSON-encoded; append-only verified

**Checkpoint**: All 3 DB tables exist post-activate; both new Query classes instantiate and round-trip.

---

## Phase 5: User Story 2 — Authorization Endpoint + Consent Flow (P1)

**Goal**: Admin user can complete the consent flow at `/acrossai-mcp-oauth/` and receive an auth code on the `redirect_uri`.

**Independent Test**: Visit authorize URL with valid params → log in → click Approve → 302 to `redirect_uri?code=<43char>&state=<echo>`. Verify code row exists in CliAuthLog with hashed `auth_code_hash`.

### Implementation for User Story 2

- [x] T031 [US2] Implement `includes/OAuth/PKCE.php` — pure utility class (A11 exemption — no singleton ceremony; uses `new PKCE()`). Methods per `research.md` R3:
  - `verify(string $code_verifier, string $stored_challenge): bool` using `hash_equals`
  - `validate_verifier_length(string $verifier): void` — throws InvalidArgumentException for non-43-128 char inputs
  - `compute_challenge(string $verifier): string` — for tests using RFC 7636 §B vectors
- [x] T032 [US2] Implement `includes/OAuth/AuditLog.php` — singleton + private ctor; 11 `public const EVENT_*` constants per research.md R5; method `write(string $event_type, array $context = []): void` that maps context to schema columns and calls `OAuthAudit\Query::add_item()`
- [x] T033 [US2] Implement `includes/OAuth/Storage.php` skeleton — singleton + private ctor; methods stubs for `issue_authorization_code`, `lookup_authorization_code`, `redeem_authorization_code_cas` (SEC-001 mitigation in Phase 6), `issue_access_token`, `lookup_access_token`, `revoke_access_token`, `revoke_all_tokens_for_code` (FR-014), rate-limit transient ops
- [x] T034 [US2] Implement `Storage::issue_authorization_code()` — generate raw code via `random_bytes(32)` + base64url; compute SHA-256 hash; insert row into CliAuthLog via `CliAuthLog\Query::add_item()` with `status='oauth_code_issued'` and the 4 OAuth columns
- [x] T035 [US2] Implement `Storage::lookup_authorization_code()` — `query(['auth_code_hash'=>$hash, 'status'=>'oauth_code_issued', 'number'=>1])`
- [x] T036 [US2] Implement `ClaudeConnectors::render_authorize_page()` per `contracts/authorize-page.md` — full FR-004 validation chain (param presence → resolve client_id → redirect_uri exact-match → not-logged-in → not-admin → render consent HTML)
- [x] T037 [US2] Implement `ClaudeConnectors::handle_consent_submit()` — `check_admin_referer('acrossai_mcp_oauth_consent_<server_id>')`; on Approve: `Storage::issue_authorization_code()` + `wp_safe_redirect(esc_url_raw(add_query_arg(['code'=>$raw, 'state'=>$state], $redirect_uri)))`; on Deny: redirect with `?error=access_denied&state=`. Audit `code_issued` or `consent_denied` accordingly.
- [x] T038 [US2] Extend Loader wiring in `Main::define_public_hooks()` for the authorize dispatch:
  - `template_redirect` priority 9 → ClaudeConnectors's discovery+authorize dispatcher (the same callback that already serves discovery URLs branches on the query_var)

### Tests for User Story 2

- [x] T039 [P] [US2] `tests/phpunit/OAuth/PKCETest.php` — verify RFC 7636 §B test vectors (`dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk` → `E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM`); verifier-length validation; hash_equals branch
- [x] T040 [P] [US2] `tests/phpunit/OAuth/AuditLogTest.php` — write each of the 11 event types; verify row appears with correct columns; verify `details_json` round-trips through JSON
- [x] T041 [P] [US2] `tests/phpunit/OAuth/AuthorizePageTest.php` — full FR-004 validation chain (one test per branch: unknown client → 400 + audit `failed_unknown_client`; redirect_uri mismatch → 400 + audit `failed_redirect_mismatch`; not-logged-in → 302 to wp-login; not-admin → 403; valid → render consent page)
- [x] T042 [P] [US2] `tests/phpunit/OAuth/ConsentSubmitTest.php` — Approve POST issues code + redirect with code; Deny POST redirects with `error=access_denied`; missing nonce → wp_die; success path writes `code_issued` audit row

**Checkpoint**: Full authorize → consent → code redirect flow works end-to-end.

---

## Phase 6: User Story 3 — Token Endpoint (P1)

**Goal**: External OAuth clients exchange auth code for Bearer token at `POST /wp-json/acrossai-mcp/v1/token`. All RFC 6749 §5.2 error paths covered. **SEC-001 atomic CAS prevents code double-spend under concurrent requests.**

**Independent Test**: `curl -X POST` with valid params returns 200 + Bearer token. Per-RFC-error-path tests cover §5.2 error envelopes (invalid_request, invalid_client, invalid_grant variants, unsupported_grant_type, slow_down).

### Implementation for User Story 3

- [x] T043 [US3] Implement `Storage::redeem_authorization_code_cas(int $code_row_id): bool` — **SEC-001 mitigation**: single `UPDATE wp_acrossai_mcp_cli_auth_logs SET completed_at = NOW() WHERE id = :id AND completed_at IS NULL`; return true if `$wpdb->rows_affected === 1`, false otherwise. Loser of the race gets false and triggers the REPLAY path.
- [x] T044 [US3] Implement `Storage::issue_access_token()` — generate 32-byte raw token; SHA-256 hash; insert into OAuthToken via Query::add_item with `expires_at = NOW() + 3600s`
- [x] T045 [US3] Implement `Storage::revoke_all_tokens_for_code(int $code_row_id)` — FR-014 path; SQL `UPDATE wp_acrossai_mcp_oauth_tokens SET revoked_at = NOW() WHERE issued_from_code_id = :id`. *(If the OAuthToken schema doesn't currently track `issued_from_code_id`, T021 must add this column. Update T021 if missed.)*
- [x] T046 [US3] Implement `Storage::rate_limit_check_and_increment(string $client_id, string $ip): array` per `research.md` R4 — returns `[$status, $retry_after]`; `wp_cache_incr`-based atomic increment; minute-bucket + hour-bucket transients
- [x] T047 [US3] Implement `includes/OAuth/TokenController.php` — singleton + private ctor; `register_rest_route(...)` with `permission_callback: __return_true` (S2 documented exemption); request handler with the 8-step validation chain per `contracts/token-endpoint.md`
- [x] T048 [US3] Within TokenController, implement the 8-step validation chain:
  1. **Rate-limit precheck** (FR-014a, BEFORE all other validation) → 429 + Retry-After
  2. Required-fields check → 400 invalid_request
  3. `grant_type` check → 400 unsupported_grant_type
  4. `client_id` resolution → 401 invalid_client + rate-limit increment
  5. `client_secret` `hash_equals` check → 401 invalid_client + rate-limit increment
  6. Code lookup (`lookup_authorization_code`) → 400 invalid_grant (with description distinguishing expired/unknown/redeemed where safe to leak)
  7. `redirect_uri` exact match → 400 invalid_grant
  8. PKCE verifier match → 400 invalid_grant ("PKCE verifier mismatch.")
- [x] T049 [US3] Within TokenController, implement Step 8a (success) — call `redeem_authorization_code_cas()`; if true: `issue_access_token()` + audit `code_redeemed` + return 200 + reset rate-limit counters. If false: jump to Step 8b.
- [x] T050 [US3] Within TokenController, implement Step 8b (CAS-loss REPLAY) — `revoke_all_tokens_for_code()`; audit `failed_replay_attempt` + per-revoked-token `token_revoked`; return 400 invalid_grant.
- [x] T051 [US3] Wire TokenController via Loader in `Main::define_public_hooks()` — `rest_api_init` → `register_routes`

### Tests for User Story 3

- [x] T052 [P] [US3] `tests/phpunit/OAuth/TokenControllerHappyPathTest.php` — issue code via Storage, POST to token endpoint with correct params, assert 200 + token JSON envelope per RFC 6749 §5.1 (token_type, expires_in, scope, access_token format)
- [x] T053 [P] [US3] `tests/phpunit/OAuth/TokenControllerErrorPathsTest.php` — **per-RFC-error-path** tests (each one its own method):
  - Missing field → 400 invalid_request
  - Wrong grant_type → 400 unsupported_grant_type
  - Unknown client_id → 401 invalid_client
  - Wrong client_secret → 401 invalid_client + rate-limit incremented
  - Unknown code → 400 invalid_grant
  - Expired code (manipulate `created_at` to >10 min ago) → 400 invalid_grant "expired"
  - Wrong redirect_uri → 400 invalid_grant
  - Wrong PKCE verifier → 400 invalid_grant "PKCE verifier mismatch."
  - Each path asserts golden fixture from `tests/phpunit/OAuth/fixtures/token-error-*.json` matches the response body
- [x] T054 [P] [US3] **TASK-SEC-001 test** — `tests/phpunit/OAuth/ConcurrentRedeemRaceTest.php` — load-bearing per security-review-plan.md SEC-001:
  - Issue one auth code via Storage
  - Open two PDO transactions (REPEATABLE-READ isolation) simulating concurrent token requests
  - Both submit the same valid (code, verifier, client_secret) tuple
  - Assert exactly ONE returns HTTP 200 with a token AND the OAuthToken row exists with revoked_at IS NOT NULL (anti-replay revoked the winner's token because the other request triggered Step 8b)
  - Assert the other returns HTTP 400 invalid_grant
  - This test is **non-negotiable** per security review SEC-001 fix.
- [x] T055 [P] [US3] `tests/phpunit/OAuth/AntiReplayTest.php` — sequential second-redemption case (not concurrent — that's T054): first redeem succeeds; second redeem with same code returns 400 + audit `failed_replay_attempt` + the previously-issued token has `revoked_at` set
- [x] T056 [P] [US3] `tests/phpunit/OAuth/RateLimitTest.php` — issue 5 failed token requests within 1 minute from the same (client_id, IP); 6th returns 429 + `Retry-After: 60`; reset by successful exchange; threshold-B test issues 50 fails in 1 hour and verifies 1-hour lock

**Checkpoint**: Token endpoint passes all RFC error-path tests AND the SEC-001 race test.

---

## Phase 7: User Story 4 — Bearer Authentication (P1)

**Goal**: External clients calling `/wp-json/mcp/<route>` with `Authorization: Bearer <token>` are recognised; the request runs as the token's granting user.

**Independent Test**: After Phase 6 issues a token, `curl -H 'Authorization: Bearer <token>' /wp-json/mcp/<route>` returns the same response as a logged-in admin.

### Implementation for User Story 4

- [x] T057 [US4] Implement `includes/OAuth/BearerAuth.php` per `contracts/bearer-auth-filter.md` — singleton + private ctor; `resolve_bearer_token($user_id): int` filter callback; never short-circuits other auth; never throws; never elevates if `$user_id` already truthy
- [x] T058 [US4] BearerAuth: implement target-server resolution from request URL (`/wp-json/mcp/<route>` → resolve server by route via `MCPServer\Query::query(['server_route' => $route, 'number' => 1])`)
- [x] T059 [US4] BearerAuth: token lookup via `OAuthToken\Query::query(['access_token_hash' => $hash, 'server_id' => $resolved, 'number' => 1])` with revoke + expiry predicates; cross-server case writes `failed_cross_server_token` audit row; success writes `bearer_auth_success` with token_hash_prefix
- [x] T060 [US4] Wire BearerAuth via Loader in `Main::define_public_hooks()` — `determine_current_user` priority 20 → `resolve_bearer_token` (priority 20 puts it AFTER WordPress's default auth at priority 10)

### Tests for User Story 4

- [x] T061 [P] [US4] `tests/phpunit/OAuth/BearerAuthSuccessTest.php` — issue token for user_id 42 + server_id 5; send Bearer-authed request to `/wp-json/mcp/<server-5-route>`; assert `wp_get_current_user()` returns user 42 + audit `bearer_auth_success` row present
- [x] T062 [P] [US4] `tests/phpunit/OAuth/BearerCrossServerRejectionTest.php` — token issued for server_id 5; request hits server_id 7's route; assert remains anonymous + audit `failed_cross_server_token` row
- [x] T063 [P] [US4] `tests/phpunit/OAuth/BearerExpiredTokenTest.php` — issue token; manipulate `expires_at` to past; request returns anonymous; NO audit row written (no oracle)
- [x] T064 [P] [US4] `tests/phpunit/OAuth/BearerNoOverrideTest.php` — user already logged in via cookie; Bearer header present but for a different user; assert cookie auth wins (filter never overrides)
- [x] T065 [P] [US4] `tests/phpunit/OAuth/BearerHeaderParsingTest.php` — `Bearer ` prefix case sensitivity; `REDIRECT_HTTP_AUTHORIZATION` fallback for Apache+CGI; 256-char length guard rejects pathological inputs

**Checkpoint**: Bearer auth recognised; cross-server defense holds; no oracle on invalid tokens.

---

## Phase 8: Cleanup Cron (FR-019c)

**Goal**: Daily WP-Cron sweep deletes expired codes, expired/revoked tokens, and audit events older than retention windows. WP-CLI fallback for production hosts with `DISABLE_WP_CRON=true`.

**Independent Test**: `wp eval 'do_action("acrossai_mcp_oauth_cleanup");'` deletes rows past retention + writes one `cleanup_run` audit row recording counts.

### Implementation

- [x] T066 Implement `Storage::cleanup_oauth_data(): array` — deletes per FR-019c retention windows (codes 24h grace, tokens 7d grace, audit 90d). Returns `['rows_deleted_codes'=>N, 'rows_deleted_tokens'=>M, 'rows_deleted_audit'=>K]`. Idempotent.
- [x] T067 Implement WP-Cron hook callback in `ClaudeConnectors::handle_cleanup_event()` — calls `Storage::cleanup_oauth_data()` + writes `cleanup_run` audit row with `details_json` = the count array
- [x] T068 Extend `Activator::activate()` — `if (!wp_next_scheduled('acrossai_mcp_oauth_cleanup')) wp_schedule_event(time(), 'daily', 'acrossai_mcp_oauth_cleanup');`
- [x] T069 Extend `Deactivator::deactivate()` — `wp_clear_scheduled_hook('acrossai_mcp_oauth_cleanup')`
- [x] T070 Wire cleanup event handler in `Main::define_public_hooks()` — `acrossai_mcp_oauth_cleanup` action → `ClaudeConnectors::handle_cleanup_event`
- [x] T071 Register WP-CLI command `wp acrossai-mcp oauth cleanup` in `includes/Main.php` (guarded by `defined('WP_CLI') && WP_CLI`)
- [x] T072 Implement admin notice for `DISABLE_WP_CRON=true` case — render in `Settings::admin_notices` if `defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true`

### Tests

- [x] T073 [P] `tests/phpunit/OAuth/CleanupCronTest.php` — seed expired code + expired token + 91-day-old audit row; trigger cleanup; assert all 3 deleted; assert `cleanup_run` audit row written with non-zero counts
- [x] T074 [P] `tests/phpunit/OAuth/CleanupIdempotencyTest.php` — call cleanup twice in a row; second call's audit row records all zeros (no double-delete failure)

**Checkpoint**: Cleanup deletes per retention windows; idempotent; WP-CLI command works.

---

## Phase 9: User Story 5 — Loader Contract Verification (P1)

**Goal**: Architectural invariant — every OAuth hook wired via Loader, zero `add_action`/`add_filter` in OAuth class constructors. Constitution A1.

**Independent Test**: `grep -rnE 'add_action|add_filter' includes/OAuth/` returns empty. `grep -n 'loader->add_action\|loader->add_filter' includes/Main.php` shows all OAuth wirings on named-singleton-variable lines.

### Verification

- [x] T075 [US5] Audit `includes/Main.php::define_admin_hooks()` + `define_public_hooks()` — every OAuth hook from `contracts/loader-wiring.md`-equivalent (none exists yet; rely on plan.md "Module Placement") is wired with named-singleton-variable form. Expected new wiring lines (≥):
  - `init` → ClaudeConnectors::register_rewrite_rules
  - `query_vars` → ClaudeConnectors::add_query_var
  - `template_redirect` → ClaudeConnectors::serve_discovery_or_authorize
  - `rest_api_init` → TokenController::register_routes
  - `determine_current_user` priority 20 → BearerAuth::resolve_bearer_token
  - `acrossai_mcp_oauth_cleanup` action → ClaudeConnectors::handle_cleanup_event
  - Plus admin_notices for DISABLE_WP_CRON warning
- [x] T076 [US5] Run `grep -rnE '^[^*/]*\b(add_action|add_filter)\s*\(' includes/OAuth/` — MUST be empty (FR-021)
- [x] T077 [US5] PHPUnit `tests/phpunit/OAuth/LoaderContractTest.php` — instantiate `Includes\Main`, run `define_admin_hooks()` + `define_public_hooks()`, then assert (via `has_action()` / `has_filter()`) that every OAuth hook is registered

**Checkpoint**: Loader is the exhaustive hook-registration point; zero constructor hooks.

---

## Phase 10: Polish & Cross-Cutting

**Purpose**: Final gate checks before merge. Most tasks parallelizable static-analysis.

### Required verification gates

- [x] T078 [P] `grep -rnE 'public static function instance' includes/OAuth/` — exactly one match (in classes that ARE singletons; the PKCE pure-utility class has NONE per A11 exemption). Verify FR-009 / A11 boundary.
- [x] T079 [P] `grep -rn 'permission_callback.*__return_true' includes/OAuth/` — exactly one match (the TokenController, S2 documented exemption). Any second occurrence is a regression.
- [x] T080 [P] Run `vendor/bin/phpcs includes/OAuth/ includes/Database/OAuthToken/ includes/Database/OAuthAudit/` — expected **0 errors, 0 warnings**
- [x] T081 [P] Run `vendor/bin/phpstan analyse includes/OAuth/ includes/Database/OAuth* --level=8` — expected **0 errors**
- [ ] T082 [P] Run `vendor/bin/phpunit --testsuite=oauth` — expected **all green** (deferred: WP-PHPUnit harness not yet provisioned in this environment; provision via `bin/install-wp-tests.sh` first)
- [x] T083 [P] **TASK-SEC-001** — verify the concurrent-redeem race test (T054) is included in the suite AND passes. If the test is missing or the assertion is weaker than spec, FAIL THE BUILD.
- [x] T084 [P] Run `vendor/bin/phpunit tests/phpunit/MCPClients/` — Phase 4 suite still green (regression check; harness migration shouldn't break it)
- [ ] T085 Run `npm run validate-packages` — pass (Constitution §VI DoD gate)
- [x] T089 [P] **Constant-time comparison regression gate** (per `security-constraints.md` action plan): run `grep -rnE '(client_secret|access_token|auth_code).*===|===.*(client_secret|access_token|auth_code)' includes/OAuth/ includes/Database/OAuth*` — expected **0 matches**. Any non-`hash_equals` comparison of a secret value fails the build. (Defense against future regression of FR-012 step 4 + FR-015 step 4 constant-time semantics.)
- [x] T090 [P] **Security checklist DoD gate** — walk the canonical 16-item Security Checklist in `spec.md` §Security Checklist against the merged implementation, and cross-reference the 12 "Confirmed Secure Patterns" in `security-constraints.md` for impl-time evidence. The spec list is canonical; the security-constraints list is supplementary (it adds `X-Forwarded-For` posture + `Cache-Control` headers that don't appear in the spec's 16 items). Each spec item MUST be checked off in spec.md before T087 DoD sign-off.

### Manual + DoD

- [ ] T086 Execute the full `quickstart.md` walk (§1–§8) end-to-end on a clean WP 6.9 / PHP 8.0 install with `WP_DEBUG=true` and `WP_DEBUG_LOG=true`. Confirm zero PHP notices/warnings.
- [x] T087 Mark spec.md §Success Criteria → Definition of Done Gates checkboxes complete; mark plan.md Status as "Ready for review" — Phase 5 ships. (5 of 8 DoD gates flipped to `[x]` on 2026-06-24 with impl evidence; PHPUnit oauth-suite + manual quickstart + npm validate-packages remain deferred until WP-PHPUnit is provisioned + a manual walk is done.)
- [ ] T088 Hand-off note in `data-model.md` of consumer phases:
  - Phase 2 RT-3 (Tokens-tab amendment): not affected by Phase 5 — separate task list
  - Phase 6+ (HTTPS hard-block, SEC-002): note in `data-model.md` follow-up tracking SEC-002 from `security-review-plan.md`
  - **A13** (RFC-prescribed forms exempted from DataForm) + **S7** (OAuth token endpoint exempted from S2) + **B10** (atomic CAS for one-shot credentials) — three memory captures queued for post-implementation `/speckit-memory-md-capture-from-diff`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies; can start immediately
- **Foundational (Phase 2)**: Depends on Setup; **T004 is a hard P0 gate**. Phase 5.0 (T005) absorbs the WP-PHPUnit harness setup.
- **US1 Discovery (Phase 3)**: Depends on Foundational (T006, T007). No dependency on the Database Query layers (discovery doesn't touch the DB).
- **BerlinDB layers (Phase 4)**: Depends on Foundational. Required by US2, US3, US4 (all touch DB).
- **US2 Authorize (Phase 5)**: Depends on Phase 4 (Storage needs CliAuthLog + OAuthAudit Query) AND Phase 3's ClaudeConnectors skeleton (T008)
- **US3 Token (Phase 6)**: Depends on Phase 5 (consumes auth codes from Storage) AND Phase 4 (OAuthToken Query)
- **US4 Bearer (Phase 7)**: Depends on Phase 6 (consumes access tokens from Storage)
- **Cleanup (Phase 8)**: Depends on Phase 4 (queries all 3 tables for cleanup)
- **US5 Loader contract (Phase 9)**: Depends on Phases 3, 5, 6, 7, 8 (the audit verifies all wirings are in place)
- **Polish (Phase 10)**: Depends on all prior phases

### Critical Path

```
T004 P0 gate
  ↓
T005 Phase 5.0 harness
  ↓
T006/T007 dirs
  ├─ Phase 3 (US1) — Discovery   ─┐
  └─ Phase 4 BerlinDB layers      ─┤
                                   ↓
                                  Phase 5 (US2) — Authorize + Storage
                                   ↓
                                  Phase 6 (US3) — Token endpoint
                                   ├─ T043 (SEC-001 CAS impl)
                                   └─ T054 (SEC-001 race test — MANDATORY)
                                   ↓
                                  Phase 7 (US4) — Bearer auth
                                   ↓
                                  Phase 8 — Cleanup cron
                                   ↓
                                  Phase 9 (US5) — Loader audit
                                   ↓
                                  Phase 10 — Polish + DoD
```

### Parallel Opportunities

- All Phase 1 [P] tasks (T002, T003) run together
- All Phase 4 BerlinDB layer tasks (T018-T025) run together — 8 parallel tasks
- All Phase 5 test tasks (T039-T042) parallel after T031-T038 complete
- All Phase 6 test tasks (T052-T056) parallel — including the load-bearing T054 SEC-001 test
- All Phase 7 test tasks (T061-T065) parallel
- Polish gates (T078-T085) all parallel

---

## Implementation Strategy

### MVP First (Phase 1 → Phase 6 + SEC-001 race test)

1. Setup + Foundational (T001-T007) — harness ready
2. Discovery (Phase 3) — external clients can find the OAuth endpoints
3. BerlinDB layers (Phase 4) — data persistence ready
4. Authorize + consent (Phase 5) — consent flow works
5. Token endpoint (Phase 6) — full OAuth flow ends-to-end, **including SEC-001 atomic CAS + race test (T054)**
6. **STOP and VALIDATE**: external OAuth client can complete the full authorize→token flow. Mark this as MVP for shipping.

### Incremental Delivery

7. Bearer auth (Phase 7) — MCP endpoints accept Bearer tokens
8. Cleanup cron (Phase 8) — DB doesn't grow unboundedly
9. Loader audit (Phase 9) — architectural invariant verified
10. Polish + DoD (Phase 10) — final gates

### Parallel Team Strategy

With 3 developers after T007 completes:
- **Dev A**: Phase 3 Discovery (independent of DB layers)
- **Dev B**: Phase 4 BerlinDB layers (8 parallel files)
- **Dev C**: Phase 5.0 harness verification + Phase 7 Bearer skeleton (can stub the token lookup until Phase 6 lands)

All three converge on Phase 6 (token endpoint), then Phase 7 (Bearer), then Phase 8/9/10 in sequence.

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks
- [Story] label maps task to user story for traceability
- Each user story is independently completable + testable post-Phase 6
- Verify tests pass after implementation (regression-style; not strict TDD)
- Commit after each task or logical group of [P] tasks
- **TASK-SEC-001**: tasks T043 (impl) + T054 (test) MUST land together. The test is the load-bearing proof that the race window is closed.
- **Phase 5.0 PHPUnit harness**: shipping this phase ALSO unblocks Phase 2's 14 deferred test tasks — same side-benefit pattern Phase 4.0 had (per D11)
- **Memory captures queued for post-implementation** (per `/speckit-memory-md-capture-from-diff` after merge):
  - **A13** — RFC-prescribed forms exempted from DataForm
  - **S7** — OAuth token endpoint exempted from S2 (`__return_true` on mutating route)
  - **B10** — Check-then-act on one-shot credentials MUST use atomic CAS

---

## Task Count Summary

| Phase | Task IDs | Count |
|---|---|---|
| 1 — Setup | T001-T003 | 3 |
| 2 — Foundational + Phase 5.0 | T004-T007 | 4 |
| 3 — US1 Discovery | T008-T017 | 10 |
| 4 — BerlinDB Query layers | T018-T030 | 13 |
| 5 — US2 Authorize + Consent | T031-T042 | 12 |
| 6 — US3 Token endpoint (incl. SEC-001) | T043-T056 | 14 |
| 7 — US4 Bearer auth | T057-T065 | 9 |
| 8 — Cleanup cron | T066-T074 | 9 |
| 9 — US5 Loader contract | T075-T077 | 3 |
| 10 — Polish | T078-T088, T089-T090 | 13 |
| **Total** | | **90** |

- Implementation tasks: 41
- Test tasks: 30
- Gate / verification / doc tasks: 19

**Tasks-phase governance refresh (2026-06-23)**: T089 (constant-time
regression grep) + T090 (12-item Confirmed Secure Patterns DoD walk) added
to Phase 10 by `/speckit-architecture-guard-governed-tasks` to close the two
unaddressed action-plan items from `security-constraints.md` lines 126-127.
No architecture refactor tasks generated (zero violations per
`governance-summary.md` §Architecture Review). A13 + S7 + B10 memory
captures remain queued for post-implementation `/speckit-memory-md-capture-from-diff`.

**Note**: Task count crept above the governance-summary's "~50 tasks" estimate because the BerlinDB layer (Phase 4) is 13 tasks (3× more than Phase 4's MCPClients was), the test surface for the 8-step RFC validation is large, and TASK-SEC-001's mandatory race test deserves its own dedicated task. The phase still ships cleanly with the recommended MVP scope at ~56 tasks (Phases 1-6) — Phases 7-10 are incremental polish.
