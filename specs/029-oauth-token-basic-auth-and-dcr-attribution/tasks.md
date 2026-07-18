---
description: "Implementation task list for Feature 029 — OAuth /token accepts HTTP Basic auth + DCR-registered clients attributed to connector profiles"
---

# Tasks: OAuth `/token` Basic auth + DCR attribution

**Input**: Design documents from `/specs/029-oauth-token-basic-auth-and-dcr-attribution/`
**Prerequisites**: `plan.md` ✓, `spec.md` ✓ (3 user stories: US1 P1 Basic auth, US2 P1 DCR attribution, US3 P2 defense-in-depth softening)

**Tests**: Deferred — see Polish phase note. F029's PR does not gate PHPUnit additions; existing `oauth` testsuite continues to pass on CI.

**Note**: This tasks.md is reverse-engineered from PR #37 which shipped before the Spec Kit ceremony. All tasks are marked [X] — the code is in `1a83d62`'s follow-up commit. Task IDs and file paths match what was actually shipped.

**Organization**: Tasks grouped by user story per plan.md priorities. Foundational phase (Phase 2) covers the shared `read_client_credentials_from_header()` static that US1 and US3 both depend on.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 map to spec.md user stories (blank on Setup / Foundational / Polish)
- File paths are exact and repository-relative from plugin root

## Path Conventions

Single WordPress plugin project — paths shown are relative to the plugin root `acrossai-mcp-manager/` (absolute: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm branch state; no code changes.

- [X] T001 Confirm feature branch is checked out with a clean working tree (`git status`); nominal branch name is `029-oauth-token-basic-auth-and-dcr-attribution` but shipping happened on `fix/oauth-token-basic-auth-and-dcr-attribution` — see `plan.md` §Note on branch naming. Verify `docs/planings-tasks/029-oauth-token-basic-auth-and-dcr-attribution.md` exists (pre-Spec-Kit design doc).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared header-parsing helper that US1 and US3 both depend on.

**⚠️ CRITICAL**: US1 and US3 cannot begin until this phase is complete.

- [X] T002 Add `private static function read_client_credentials_from_header(): array` to `includes/OAuth/TokenController.php`. Placement: after `handle_refresh_token()` closing brace and before `read_body()` (around line 283). Body: read `$_SERVER['HTTP_AUTHORIZATION']` first, fall back to `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`; short-circuit to `['', '']` when the header doesn't start with `Basic ` (case-insensitive `stripos`); `base64_decode( substr( $auth_header, 6 ), true )` with strict mode; short-circuit to `['', '']` on decode failure OR when the decoded string has no colon; return `explode( ':', $decoded, 2 )` cast to `(string)`. Docblock cites RFC 6749 §2.3.1 + notes the CGI fallback. Add `phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 6749 §2.3.1 Basic auth requires base64 decoding of the header value.` above the `base64_decode` call.

**Checkpoint**: `read_client_credentials_from_header()` exists and returns a `[string, string]` tuple; can be consumed by US1/US3 tasks in both grant handlers.

---

## Phase 3: User Story 1 — MCP host authenticates at `/token` via HTTP Basic auth (Priority: P1) 🎯 MVP

**Goal**: The token endpoint accepts client credentials via `Authorization: Basic base64(client_id:client_secret)` on both the `authorization_code` and `refresh_token` grants. Header takes precedence over body (RFC 6749 §2.3.1).

**Independent Test**: `curl` with Basic auth header + body containing only `grant_type + code + code_verifier + redirect_uri` → 200 with token pair.

- [X] T003 [US1] Modify `TokenController::handle_authorization_code()` at `includes/OAuth/TokenController.php` (starting ~line 82): at the top of the method (before `$required` validation), destructure `[ $header_client_id, $header_client_secret ] = self::read_client_credentials_from_header();`; read `$body_client_id` / `$body_client_secret` from `$body` with empty-string fallbacks; assign `$client_id = '' !== $header_client_id ? $header_client_id : $body_client_id;` and `$client_secret = '' !== $header_client_secret ? $header_client_secret : $body_client_secret;` (header takes precedence). Remove `client_id` from the `$required` array (leave `code, code_verifier, redirect_uri`); add a separate `if ( '' === $client_id )` guard that responds with `invalid_request` after the required-field loop. Migrate every subsequent `$body['client_id']` reference in the method body to the local `$client_id` variable: `hash_equals` against `$row->client_id` (line ~95), `ClientRepository::find_by_id` (line ~102), `AccessTokenRepository::issue` `client_id` arg (line ~140), `RefreshTokenRepository::issue` `client_id` arg (line ~149), and the `do_action( 'acrossai_mcp_manager_oauth_token_issued', ..., $client_id, ... )` call (line ~161).
- [X] T004 [US1] Apply the identical credential-resolution block at the top of `TokenController::handle_refresh_token()` at `includes/OAuth/TokenController.php` (~line 168): same `[ $header_client_id, $header_client_secret ]` destructure, same body fallback, same `$client_id` / `$client_secret` locals. Update the guard at line 189 from `if ( ! isset( $body['refresh_token'], $body['client_id'] ) || '' === $body['refresh_token'] || '' === $body['client_id'] )` to `if ( ! isset( $body['refresh_token'] ) || '' === $body['refresh_token'] || '' === $client_id )`. Migrate every subsequent `$body['client_id']` reference in the method body to the local `$client_id`: `hash_equals` at line ~213, `ClientRepository::find_by_id` at ~222, both `Repository::issue` calls (lines ~254 and ~263), and the `do_action` at line ~271.

**Checkpoint**: US1 is fully functional — both grant handlers accept Basic auth on the header, body-only usage still works (regression-safe), and downstream calls receive the correctly-resolved `$client_id`.

---

## Phase 4: User Story 2 — DCR-registered client is attributed to its connector profile at registration time (Priority: P1)

**Goal**: `handle_register()` walks `ConnectorProfileRegistry` and stores the first matching profile's slug into `connector_slug` on the new client row, replacing the previous hardcoded empty string. F024 per-connector settings gate now applies to DCR clients.

**Independent Test**: POST a Claude-shaped DCR body → new row has `connector_slug = <profile-slug>`, not empty. Then toggle the connector disabled on F024 → subsequent `/authorize` for that `client_id` rejects with `access_denied`.

- [X] T005 [US2] Modify `ClientRegistrationController::handle_register()` at `includes/OAuth/ClientRegistrationController.php` (~line 355): after the fingerprint dedup lookup (`$existing = ClientRepository::find_by_fingerprint( $fingerprint )`) short-circuits on cache hit, and after the `$new_client_id` / `$new_client_secret` generation (~line 358) + the `server-` prefix guard (~line 361), and BEFORE `ClientRepository::create()` is called (~line 365), insert the attribution walk: initialize `$attributed_slug = '';` then `foreach ( ConnectorProfileRegistry::instance()->get_profiles() as $profile ) { if ( $profile->matches_dcr_client( $client_name, $redirect_uris ) ) { $attributed_slug = $profile->get_slug(); break; } }`. Then change the `connector_slug` argument in the `ClientRepository::create()` call from the hardcoded `''` to `$attributed_slug`. Add a docblock comment above the walk citing F024 attribution + noting first-match-wins + no-match-preserves-empty semantics.

**Checkpoint**: US2 is fully functional — new DCR registrations populate `connector_slug` correctly; F024 gate resolves the persisted slug instead of relying on `AuthorizationController::infer_slug_from_dcr_client()` at every `/authorize`.

---

## Phase 5: User Story 3 — Confidential client without secret completes via PKCE (Priority: P2)

**Goal**: When a `client_secret_post` client sends NO secret at exchange (header AND body both empty), fall through to PKCE-only verification instead of hard-rejecting.

**Independent Test**: A pre-F027 client row (`token_endpoint_auth_method = 'client_secret_post'`) can exchange a code with valid PKCE and no secret → 200. Wrong PKCE still → 400.

- [X] T006 [US3] Modify the client_secret_post enforcement block in `TokenController::handle_authorization_code()` (~lines 108–113 pre-change). Change from `if ( 'client_secret_post' === $client->token_endpoint_auth_method ) { if ( '' === $submitted_secret || ! ClientRepository::verify_secret( ... ) ) { respond_error( 'invalid_client', ... ); } }` to `if ( 'client_secret_post' === $client->token_endpoint_auth_method && '' !== $client_secret ) { if ( ! ClientRepository::verify_secret( $client, $client_secret ) ) { respond_error( 'invalid_client', 'client_secret verification failed', 401 ); } }`. Add a code comment above the `if` explaining the interop rationale (modern MCP hosts registered as client_secret_post but behaving as public+PKCE; PKCE still authenticates the exchange).
- [X] T007 [US3] Apply the identical softening in `TokenController::handle_refresh_token()` (~lines 208–213 pre-change). Same `&& '' !== $client_secret` guard + same inner verify + same code comment (adjust for refresh-token context: refresh_token bound to client_id via row->client_id authenticates the exchange when secret is absent).

**Checkpoint**: US3 is fully functional — the defense-in-depth softening applies to both grants. Clients that DO send a secret are still verified (no regression); clients that don't fall through to PKCE.

---

## Phase 6: Polish & Verification

**Purpose**: Run the gates enumerated in `plan.md` §Verification and confirm CI green.

- [X] T008 Run `composer run phpcs -- includes/OAuth/TokenController.php includes/OAuth/ClientRegistrationController.php` — 8 alignment warnings auto-fixed via `vendor/bin/phpcbf`; 1 `base64_decode` warning covered by phpcs:ignore comment with RFC 6749 §2.3.1 justification. Final PHPCS run returns zero warnings.
- [X] T009 Run `composer run phpstan` — level 8 clean.
- [X] T010 Run `composer run test -- --testsuite mcpclients` locally (WP-free suite) to confirm no cross-suite regression. The `oauth` integration testsuite requires the WP-PHPUnit harness at `/tmp/wordpress-tests-lib` and is deferred to CI.
- [X] T011 Confirm CI green on PR #37: 8 checks — F021 grep gates, JavaScript Lint, PHP 8.1+ Compatibility, PHPStan Static Analysis, PHPUnit (integration) — PHP 8.4 / WP latest, PHPUnit (pure) — PHP 8.4, WordPress Coding Standards, WordPress Package Hierarchy (Constitution §VI/§VII).
- [ ] T012 (Deferred to follow-up) Add PHPUnit cases to `tests/phpunit/OAuth/`:
  - `TokenControllerBasicAuthTest::test_authorization_code_header_only_basic_auth_returns_200`
  - `TokenControllerBasicAuthTest::test_authorization_code_body_only_regression_returns_200`
  - `TokenControllerBasicAuthTest::test_header_takes_precedence_over_body`
  - `TokenControllerBasicAuthTest::test_malformed_basic_header_falls_back_to_body`
  - `TokenControllerPkceOnlyTest::test_client_secret_post_no_secret_valid_pkce_returns_200`
  - `TokenControllerPkceOnlyTest::test_client_secret_post_no_secret_wrong_pkce_returns_400`
  - `TokenControllerPkceOnlyTest::test_client_secret_post_with_secret_wrong_secret_returns_401` (regression)
  - `DCRAttributionTest::test_dcr_populates_connector_slug_from_matching_profile`
  - `DCRAttributionTest::test_dcr_preserves_empty_slug_when_no_profile_matches`
  - `DCRAttributionTest::test_dcr_first_matching_profile_wins`
  - All extend `WP_UnitTestCase`, belong to the `oauth` testsuite.

**Checkpoint**: All gates pass; PR #37 ready for merge. Follow-up test cases tracked as T012.

---

## Task summary

- Total tasks: 12 (11 shipped + 1 deferred)
- Setup: 1 (T001)
- Foundational: 1 (T002)
- US1 (P1): 2 (T003, T004)
- US2 (P1): 1 (T005)
- US3 (P2): 2 (T006, T007)
- Polish: 5 (T008–T012; T012 deferred)

Parallel-safe tasks marked [P]: none within a single file — all `TokenController` tasks touch the same file so they must be sequential; T005 is on a different file but depends on nothing in TokenController.

Coverage matrix (Requirement → Task):

| Req | Tasks | Notes |
|---|---|---|
| FR-001 (`read_client_credentials_from_header`) | T002 | |
| FR-002 (header-first-then-body precedence) | T003, T004 | |
| FR-003 (drop client_id from required, guard separately) | T003 | |
| FR-004 (downstream refs use local $client_id) | T003, T004 | |
| FR-005 (verify secret when submitted — regression) | T006, T007 | Preserved behavior; enforcement condition unchanged for the "secret present" branch. |
| FR-006 (fall through to PKCE when no secret) | T006, T007 | |
| FR-007 (DCR profile walk) | T005 | |
| FR-008 (first-match-wins slug attribution) | T005 | |
| FR-009 (no match → empty preserved) | T005 | |
| FR-010 (admin generator untouched) | (none — explicit non-goal) | Verified by absence of handle_admin_generate changes in diff. |
| SC-001 (PHPCS clean) | T008 | |
| SC-002 (PHPStan L8) | T009 | |
| SC-003 (oauth suite passes) | T011 | CI. |
| SC-004 (Basic auth curl smoke) | (post-deploy) | Manual verification per plan.md. |
| SC-005 (DCR populates connector_slug) | (post-deploy) | Manual verification per plan.md. |
| SC-006 (PKCE mismatch still rejected) | T006 | Behavioral invariant preserved; T012 will explicitly lock it in PHPUnit. |
