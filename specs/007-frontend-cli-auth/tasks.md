---
description: "Task list for 007-frontend-cli-auth — Frontend CLI Authentication Page (security-hardened — SEC-001/002/005 baked in)"
---

# Tasks: Frontend CLI Authentication Page

**Input**: Design documents from `/specs/007-frontend-cli-auth/`
**Prerequisites**: `plan.md` ✅, `spec.md` ✅, `research.md` ✅, `data-model.md` ✅, `contracts/` ✅, `quickstart.md` ✅, `security-constraints.md` ✅ (2026-06-30 amendment)

**Tests**: Tests ARE included. Spec §Definition of Done + §Success Criteria require PHPUnit coverage for every branch of `maybe_render_page()`. Five `tests/phpunit/FrontendAuth/<Name>Test.php` files plus one new test in `tests/phpunit/RestCli/` for the cross-cutting helper.

**Organization**: Tasks are grouped by phase. Phase 0 (NEW) amends spec, data-model, and contracts to incorporate the 2026-06-30 security review findings IN-PLAN before implementation begins. Phase 2 widens to include a cross-cutting `includes/REST/CliController.php` change that powers the SEC-001 fix.

## What's Different vs. the 2026-06-30-17:39 baseline

- **Phase 0 (NEW, 9 tasks)** — spec / data-model / contract amendments encoding SEC-001 (transient-sourced slug), SEC-002 (per-code nonce), SEC-005 (Retry-After + noindex on 503).
- **Phase 2 widened** — adds `CliController::peek_pending_server()` cross-cutting helper + its unit test.
- **T023 (handle_cli_auth)** — sources displayed slug from the transient, not from `?server=`.
- **T024 (render_disabled_notice)** — emits `Retry-After: 3600` + `<meta name="robots" content="noindex,nofollow">`.
- **T028 (handle_approve) + T021/T027 (tests)** — per-code nonce action `'cli_auth_approve_' . $code`.
- **T042 (security follow-up)** — scope narrowed: only SEC-003 (broadened authz, Phase 6 scope) and SEC-004 (GET-as-mutation, INFO, documented) remain deferred.

## Format: `[ID] [P?] [Story?] Description with file path`

- **[P]**: Different file, no dependencies on incomplete tasks
- **[Story]**: Which user story (US1–US6); required in user-story phases only

## Path Conventions

- **Implementation (Phase 7 owner)**: `public/Partials/FrontendAuth.php` (1 file, replaced wholesale)
- **Implementation (cross-cutting Phase 6 read helper)**: `includes/REST/CliController.php` (1 new public static method added)
- **Tests**: `tests/phpunit/FrontendAuth/<Name>Test.php` (5 files) + `tests/phpunit/RestCli/PeekPendingServerTest.php` (1 file, cross-cutting)
- **Spec / design artifacts (amended)**: `specs/007-frontend-cli-auth/spec.md`, `data-model.md`, `contracts/*.md`

---

## Phase 0: Plan-Level Amendments (Security Review Bake-In)

**Purpose**: Encode the 2026-06-30 security review's open findings (SEC-001 MEDIUM, SEC-002 LOW, SEC-005 INFO) into the spec / data-model / contracts BEFORE implementation begins. Without these amendments, implementation tasks would conflict with the legacy plan. All Phase 0 tasks edit distinct files OR distinct sections — most are parallelizable.

- [x] T001 [P] Amend `specs/007-frontend-cli-auth/spec.md` FR-008 dispatch table: change the `cli_auth` row's handler from `handle_cli_auth( $code, $server )` to `handle_cli_auth( $code )` — `$server` is no longer a parameter because the displayed slug is now sourced from the transient via `CliController::peek_pending_server( $code )`. Add a clarifying note: "The `?server=` GET param is preserved on the URL for backward compatibility with existing CLI clients but is IGNORED by the handler — display is sourced from the transient's authoritative `server_id`." Cross-reference SEC-001 (CWE-451/CWE-441).
- [x] T002 [P] Amend `specs/007-frontend-cli-auth/spec.md` FR-009: change nonce action from `'cli_auth_approve'` to `'cli_auth_approve_' . $code`. Update the inline code block to read `$code` and sanitize BEFORE nonce verification (reading `$_GET` is not "state mutation"; the nonce-before-mutation invariant still holds). Empty `$code` → `wp_die( 'Missing authorization code.', 400 );` BEFORE nonce check. Cross-reference SEC-002 (CWE-352).
- [x] T003 [P] Amend `specs/007-frontend-cli-auth/spec.md` FR-012: add a sentence — "The displayed server slug rendered in the consent body MUST come from the transient's bound `server_id` (via `CliController::peek_pending_server()`), NOT from the `?server=` GET parameter. Escape at output via `esc_html()` as defense-in-depth, never as the sole defense." Cross-reference S9 in `docs/memory/PROJECT_CONTEXT.md`.
- [x] T004 [P] Amend `specs/007-frontend-cli-auth/spec.md` §Assumptions: append a "Resolved 2026-06-30" sub-section noting that SEC-001 is fixed in-plan via T001+T003+T018; SEC-002 is fixed in-plan via T002; SEC-005 is fixed in-plan via T008+T024.
- [x] T005 [P] Amend `specs/007-frontend-cli-auth/data-model.md` §4: change the `server` row in the GET parameters table to "informational only — ignored at dispatch; displayed value sourced from transient via `peek_pending_server`". Validation column: "still sanitized as defense-in-depth, but never rendered or passed downstream."
- [x] T006 [P] Amend `specs/007-frontend-cli-auth/data-model.md` §7 (Cross-phase coupling diagram): bump coupling-point count from 2 to 3. Add a third bullet — "**`CliController::peek_pending_server( string $code ): ?string` consumed by `FrontendAuth::handle_cli_auth()`** — read-only helper that returns the transient's bound `server_id` for pending codes, used to source the displayed slug in the consent UI. Returns `null` for unknown/expired/non-pending codes. The contract is one-way: `handle_cli_auth()` reads, `peek_pending_server()` returns." Update the ASCII coupling diagram accordingly.
- [x] T007 [P] Amend `specs/007-frontend-cli-auth/contracts/page-cli-auth.md`: change the consent-body example HTML to clarify that the rendered server name comes from the transient. Update the "missing parameters" path: now triggered by EITHER `$code === ''` OR `peek_pending_server( $code ) === null` (unknown/expired/non-pending code). Update the Approve button href section to reflect per-code nonce: `'_wpnonce' => wp_create_nonce( 'cli_auth_approve_' . $code )`.
- [x] T008 [P] Amend `specs/007-frontend-cli-auth/contracts/page-disabled-notice.md`: add `Retry-After: 3600` to the response headers and `<meta name="robots" content="noindex,nofollow">` inside `<head>`. Update the assertions table to include both. Cross-reference SEC-005 (CWE-1004).
- [x] T009 Create new contract `specs/007-frontend-cli-auth/contracts/cli-controller-peek-pending-server.md` documenting the cross-cutting Phase 6 read helper: signature `public static function peek_pending_server( string $auth_code ): ?string`; returns the transient's `server_id` ONLY when transient exists AND `is_array($payload)` AND `isset($payload['status'], $payload['server_id'])` AND `'pending' === $payload['status']` AND `is_string($payload['server_id'])` (B11 defensive triple-check, generalized). Returns `null` otherwise. Read-only — no transient mutation, no state writes, no logging at INFO level. Idempotent and side-effect-free. Cross-references: Phase 6 FR-008 (the transient owner), SEC-001 (the consuming finding), S9 (the captured pattern), B11 (the defensive-read pattern).

**Checkpoint**: Phase 0 complete — spec, data-model, and contracts reflect the security-hardened design. The plan is now implementable without ambiguity. Phase 1 begins.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Verify P0 prerequisites and archive the prior Phase 6.0 absorbed module.

- [x] T010 Verify P0 prerequisites on the working branch: (a) `Includes\REST\CliController::approve_auth_code( string, int ): bool` exists in `includes/REST/CliController.php` per Phase 6 FR-008; (b) `Includes\Main::define_public_hooks()` already wires `init`/`query_vars`/`template_redirect`/`wp_enqueue_scripts` against `FrontendAuth::instance()` in `includes/Main.php`; (c) `Includes\Activator::activate()` calls `FrontendAuth::instance()->register_rewrite_rule()` + `flush_rewrite_rules()` in `includes/Activator.php`; (d) `build/css/frontend.css`, `build/css/frontend.asset.php`, AND `build/css/frontend-rtl.css` are committed (`git ls-files build/css`); (e) NEW: confirm `CliController` does NOT already have a `peek_pending_server` method — if it does, T018 becomes an update task instead of an add task. Block remaining work if any prerequisite is missing.
- [x] T011 [P] Archive (git-rm) the existing Phase 6.0 absorbed `public/Partials/FrontendAuth.php` AND the existing test files under `tests/phpunit/FrontendAuth/` so the re-spec'd version replaces them cleanly (plan §Summary: "REPLACE — full re-spec of existing Phase 6.0 module"). Capture the deleted-file shas in the commit message for forensic reference.

---

## Phase 2: Foundational (Blocking Prerequisites — INCLUDES cross-cutting Phase 6 helper)

**Purpose**: Class skeleton + non-render helpers + the cross-cutting `CliController::peek_pending_server()` read helper that US1 depends on. ⚠️ All user-story phases depend on Phase 2 completion. Note: this phase TOUCHES Phase 6's `CliController` to add the read helper required by SEC-001's fix. Architecture-Guard sign-off is built into T043.

- [x] T012 Create class skeleton at `public/Partials/FrontendAuth.php` with `namespace AcrossAI_MCP_Manager\Public\Partials;`, `use AcrossAI_MCP_Manager\Includes\REST\CliController;` at the top, `final class FrontendAuth` body containing `const PAGE_SLUG = 'acrossai-mcp-manager';`, `const QUERY_VAR = 'acrossai_mcp_auth';`, `protected static ?FrontendAuth $_instance = null;`, `public static function instance(): self { ... }`, `private function __construct() {}` (empty body — zero `add_action`/`add_filter`). FR-001, FR-002, FR-014, FR-015. Verifies S6/B5 (private ctor) and A2 (singleton).
- [x] T013 Add `public static function get_base_url(): string` to `public/Partials/FrontendAuth.php` returning `home_url( '/' . self::PAGE_SLUG . '/' )`. Constraint: never `admin_url(...)` — Phase 6 `CliController::auth_start()` consumes this and breaks if it points at wp-admin. FR-006.
- [x] T014 Add `public function add_query_var( array $vars ): array` to `public/Partials/FrontendAuth.php` appending `self::QUERY_VAR` and returning `$vars`. FR-004.
- [x] T015 Add `public function register_rewrite_rule(): void` to `public/Partials/FrontendAuth.php` calling `add_rewrite_rule( '^' . self::PAGE_SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' )`. FR-003. B4 NOT triggered — the literal pattern contains no `.`.
- [x] T016 [P] Verify `Includes\Main::define_public_hooks()` in `includes/Main.php` already wires the 4 hooks against `FrontendAuth::instance()` in the order `init/query_vars/template_redirect/wp_enqueue_scripts` at priority 10. If absent or pointing at the prior class shape, update it. Confirm zero `add_action`/`add_filter` calls reference `FrontendAuth` from anywhere outside `Main.php`. FR-014, A1.
- [x] T017 [P] Verify `Includes\Activator::activate()` in `includes/Activator.php` calls `FrontendAuth::instance()->register_rewrite_rule()` immediately followed by `flush_rewrite_rules()` exactly once. FR-005.
- [x] T018 Add `public static function peek_pending_server( string $auth_code ): ?string` to `includes/REST/CliController.php` per the new contract `specs/007-frontend-cli-auth/contracts/cli-controller-peek-pending-server.md`. Read `acrossai_cli_auth_<auth_code>` transient via `get_transient()`. Apply B11 defensive triple-check: `if ( ! is_array( $payload ) || ! isset( $payload['status'], $payload['server_id'] ) || 'pending' !== $payload['status'] || ! is_string( $payload['server_id'] ) || '' === $payload['server_id'] ) { return null; }`. Otherwise `return $payload['server_id'];`. Empty `$auth_code` → `return null;` (early). Class docblock cites SEC-001 + S9. Pure read; no `set_transient`, no `delete_transient`, no `error_log`. PHPDoc: `@param string $auth_code`, `@return string|null`. **Cross-cutting**: this method lives in Phase 6's `CliController` but is consumed by Phase 7's `FrontendAuth::handle_cli_auth()`.
- [x] T019 [P] Create `tests/phpunit/RestCli/PeekPendingServerTest.php` covering: (a) unknown code → null; (b) empty `$auth_code` → null; (c) malformed transient (non-array, missing keys, wrong types) → null per B11; (d) `status !== 'pending'` (approved, used) → null; (e) valid pending transient → returns the `server_id` string verbatim; (f) idempotency: two consecutive calls return identical values, no transient state changed; (g) no transient writes verified via a `wp_transient_*` action spy. Maps to the new contract.

**Checkpoint**: Foundation complete. `instance()`, `get_base_url()`, `add_query_var()`, `register_rewrite_rule()` exist; Loader + Activator wiring confirmed; `CliController::peek_pending_server()` shipped + tested. User-story phases may now proceed.

---

## Phase 3: User Story 1 — Logged-in User Sees Consent UI (Priority: P1) 🎯 MVP

**Goal**: A logged-in user (any role) visiting `/acrossai-mcp-manager/?action=cli_auth&code=…` (the `?server=` param is preserved by URL but ignored by the handler) sees a standalone HTML consent page rendering the **transient-bound** server slug (escaped) with an Approve button containing a **per-code** nonce.

**Independent Test**: `curl -b <login-cookies> 'https://example.com/acrossai-mcp-manager/?action=cli_auth&code=<valid-pending>&server=DOES-NOT-MATTER'` returns HTTP 200 with the consent form HTML showing the transient's bound server name (NOT the URL's `?server=` value), `Cache-Control: no-cache, must-revalidate, max-age=0`, and ZERO `wp_head()` output.

### Tests for User Story 1 ⚠️ Write FIRST, ensure they FAIL before implementation

- [x] T020 [P] [US1] Create `tests/phpunit/FrontendAuth/GetBaseUrlTest.php` asserting `FrontendAuth::get_base_url()` returns `home_url('/acrossai-mcp-manager/')` and is byte-equal across multiple calls. Asserts the FR-006 invariant.
- [x] T021 [P] [US1] Create `tests/phpunit/FrontendAuth/HandleCliAuthTest.php` covering: (a) happy-path (valid pending transient with `server_id='real-server'`) → response body contains `Authorize CLI Access` AND the escaped string `real-server`; (b) **anti-spoof regression for SEC-001**: stub the transient with `server_id='real-server'` BUT pass `?server=spoofed-server` on the URL → rendered body MUST contain `real-server` and MUST NOT contain `spoofed-server`; (c) `peek_pending_server` returns `null` (unknown/expired code) → "Missing Authentication Parameters" path; (d) escaped server slug — stub the transient with `server_id='<script>alert(1)</script>'` → output contains `&lt;script&gt;` (XSS regression); (e) Approve button `href` contains `action=cli_auth_approve` AND `code=<input>` AND `_wpnonce=` AND starts with `self::get_base_url()`; (f) **per-code nonce regression for SEC-002**: assert the nonce in the href was created via `wp_create_nonce( 'cli_auth_approve_' . $code )` (verify by minting one with the same action + user + window and comparing); (g) `Cache-Control` header begins with `no-cache, must-revalidate, max-age=0`; (h) response body contains zero `wp-emoji-release.min.js` / theme markup. Maps to amended page-cli-auth.md and SC-005, SC-007.

### Implementation for User Story 1

- [x] T022 [US1] Add `private function render_page_shell( string $title, string $body_html ): void` to `public/Partials/FrontendAuth.php` emitting `<!DOCTYPE html>`, `<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">`, `<head>` with `<meta charset="utf-8">`, escaped `<title>`, `<meta name="viewport" content="width=device-width, initial-scale=1">`, `wp_print_styles( 'acrossai-mcp-frontend' );`, a minimal inline `<style>` safety-net block (max-width + body padding only), and `<body>` containing the caller-supplied `$body_html` (caller is responsible for escaping). FR-011, FR-012.
- [x] T023 [US1] Add `private function handle_cli_auth( string $code ): void` to `public/Partials/FrontendAuth.php`. **CRITICAL — SEC-001 fix**: source the displayed slug from `CliController::peek_pending_server( $code )` — do NOT use `$_GET['server']`. Logic: if `$code === ''` OR `null === ($bound_server = CliController::peek_pending_server( $code ))`, build the body as escaped + translated "Missing Authentication Parameters" message via `esc_html__( 'Missing Authentication Parameters', 'acrossai-mcp-manager' )` and explanatory `<p>`. Otherwise build the body containing `<h1>` "Authorize CLI Access" (translated), an explanatory `<p>` using `sprintf( esc_html__( 'A CLI tool is requesting access to your MCP server "%1$s".', 'acrossai-mcp-manager' ), esc_html( $bound_server ) )`, and an Approve `<a class="button button-primary" href="…">` whose href is composed via `esc_url( add_query_arg( [ 'action' => 'cli_auth_approve', 'code' => $code, '_wpnonce' => wp_create_nonce( 'cli_auth_approve_' . $code ) ], self::get_base_url() ) )` — note: **`server` is NOT added to the approve URL** since it's no longer used downstream, and per-code nonce action per T002. Render via `render_page_shell()` and `exit;`. FR-008 (amended), FR-009 (amended), FR-012 (amended), FR-016. Contract: contracts/page-cli-auth.md (amended).
- [x] T024 [US1] Add `private function render_disabled_notice(): void` to `public/Partials/FrontendAuth.php`: call `status_header( 503 )` BEFORE any output, then call `header( 'Retry-After: 3600' );` (SEC-005 fix), then `render_page_shell( esc_html__( 'CLI Login Not Enabled', 'acrossai-mcp-manager' ), '<meta name="robots" content="noindex,nofollow"><h1>' . esc_html__( 'CLI Login Not Enabled', 'acrossai-mcp-manager' ) . '</h1><p>' . esc_html__( 'The CLI login flow is currently disabled on this site. Contact your administrator.', 'acrossai-mcp-manager' ) . '</p>' );` and `exit;`. **NOTE**: the `<meta>` is in body for simplicity; if `render_page_shell()` takes a head-fragment parameter, prefer placing it in `<head>`. Contract: contracts/page-disabled-notice.md (amended).
- [x] T025 [US1] Add `public function maybe_render_page(): void` to `public/Partials/FrontendAuth.php` implementing FR-007 steps 1–7: (1) `if ( ! get_query_var( self::QUERY_VAR ) ) { return; }`; (2) `nocache_headers();`; (3) `if ( ! is_user_logged_in() ) { wp_redirect( wp_login_url( self::get_base_url() ) ); exit; }`; (4) skip — no `current_user_can()` check; (5) `$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );` AND `$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );` — note: `$server` is NOT parsed (per T001/T005 amendments — informational only); (6) `if ( ! (bool) get_option( 'acrossai_mcp_npm_login_enabled', false ) ) { $this->render_disabled_notice(); exit; }`; (7) `switch ( $action )` with `cli_auth_approved` and `cli_auth_approve` cases as placeholders (filled by US3+US4) and `default` / `cli_auth` calling `$this->handle_cli_auth( $code );`. End each case with `exit;`. FR-007 (amended).

**Checkpoint**: Logged-in user visiting the consent URL now sees the transient-bound server slug (NOT the URL-supplied one). SEC-001 fix is operational. US1 is independently demonstrable.

---

## Phase 4: User Story 2 — Unauthenticated Visitor → wp-login Redirect (Priority: P1)

**Goal**: An unauthenticated visitor to any `/acrossai-mcp-manager/…` URL is 302-redirected to `wp_login_url( get_base_url() )` — base URL only, NOT the full request URI (research §R3).

**Independent Test**: `curl -I 'https://example.com/acrossai-mcp-manager/?action=cli_auth&code=x&server=y'` (no cookies) returns HTTP 302 with `Location: https://example.com/wp-login.php?redirect_to=https%3A%2F%2Fexample.com%2Facrossai-mcp-manager%2F`.

### Tests for User Story 2

- [x] T026 [P] [US2] Create `tests/phpunit/FrontendAuth/MaybeRenderPageTest.php` covering: (a) query var absent → method returns with no output, `wp_redirect()`/`exit` not called (use spy); (b) query var set + not logged in → `wp_redirect()` called with `wp_login_url( FrontendAuth::get_base_url() )`; assert `redirect_to` carries BASE URL only, NOT `action=`/`code=`/`server=`/`_wpnonce=`; (c) query var set + logged in + option disabled → `render_disabled_notice()` invoked, status 503, `Retry-After: 3600` header emitted, response body contains `<meta name="robots" content="noindex,nofollow">`, dispatch switch never reached (spy `handle_cli_auth`/`handle_approve`/`handle_approved`); (d) query var set + logged in + option enabled + unknown `?action=` → falls through to `handle_cli_auth` (default). FR-007 (amended), research §R3, contracts/page-cli-auth.md "Unauthenticated response" + contracts/page-disabled-notice.md (amended).

(US2's implementation is folded into T025 — no new code in this phase.)

**Checkpoint**: Logged-out flow + kill-switch flow verified. US1 + US2 pass.

---

## Phase 5: User Story 3 + User Story 4 — Approve Click + Nonce Verification (Priority: P1)

**Goal**: Approve button submits to `?action=cli_auth_approve&code=…&_wpnonce=…` with **per-code** nonce; handler verifies nonce BEFORE state mutation, calls `CliController::approve_auth_code()`, redirects to `?action=cli_auth_approved` on success.

**Independent Test**: With a logged-in user + valid pending auth code: hit the Approve href → HTTP 302. With missing `_wpnonce` → HTTP 403, spy reports zero `approve_auth_code` calls. With per-code nonce minted against code `A` but used against URL with `code=B` → HTTP 403 (anti-replay regression for SEC-002).

### Tests for User Story 3 + User Story 4

- [x] T027 [P] [US3] [US4] Create `tests/phpunit/FrontendAuth/HandleApproveTest.php` covering: (a) missing `_wpnonce` → HTTP 403 with "Security check failed." body; `CliController::approve_auth_code` spy = zero calls (US4); (b) tampered `_wpnonce` → same; (c) valid nonce but empty `code` → HTTP 400 with "Missing authorization code." body (early-reject; spy = zero calls); (d) valid nonce + non-empty code + spy `approve_auth_code` returns `false` → HTTP 400 with "no longer valid" body; (e) valid nonce + spy returns `true` → `wp_safe_redirect()` called with URL containing `action=cli_auth_approved` AND no `code`/`_wpnonce` leakage; (f) **anti-replay regression for SEC-002**: mint a nonce via `wp_create_nonce( 'cli_auth_approve_A' )`, then submit URL with `code=B` and that nonce → HTTP 403 (the per-code binding rejects); (g) regression for SEC-007: when spy returns `false`, `wp_safe_redirect` NOT called. Maps to contracts/page-cli-auth-approve.md (amended) and SC-003.

### Implementation for User Story 3 + User Story 4

- [x] T028 [US3] [US4] Add `private function handle_approve(): void` to `public/Partials/FrontendAuth.php`. **Ordering matters for per-code nonce (T002 amendment)**: (1) read `$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );` — reading `$_GET` is NOT state mutation, so this comes before nonce check; (2) `if ( '' === $code ) { wp_die( esc_html__( 'Missing authorization code.', 'acrossai-mcp-manager' ), 400 ); }`; (3) read `$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';`; (4) `if ( ! wp_verify_nonce( $nonce, 'cli_auth_approve_' . $code ) ) { wp_die( esc_html__( 'Security check failed.', 'acrossai-mcp-manager' ), 403 ); }` — **per-code action per T002 amendment**; (5) call `$ok = CliController::approve_auth_code( $code, get_current_user_id() );`; (6) `if ( ! $ok ) { wp_die( esc_html__( 'This authorization code is no longer valid. It may have expired or been used already.', 'acrossai-mcp-manager' ), 400 ); }`; (7) `wp_safe_redirect( add_query_arg( 'action', 'cli_auth_approved', self::get_base_url() ) ); exit;`. FR-009 (amended), FR-010, FR-012, FR-016. Contract: contracts/page-cli-auth-approve.md (amended).
- [x] T029 [US3] Add `private function handle_approved(): void` to `public/Partials/FrontendAuth.php` rendering success body (`<h1>` "CLI Authorization Approved", `<p>` "You can now return to your CLI tool — it will detect the approval shortly.", `<p>` "This page can be closed.") via `render_page_shell()` with all strings wrapped via `esc_html__('…', 'acrossai-mcp-manager')`. `exit;` after rendering. Contract: contracts/page-cli-auth-approved.md.
- [x] T030 [US3] [US4] Wire the `cli_auth_approve` and `cli_auth_approved` cases into the `switch ( $action )` block inside `maybe_render_page()` in `public/Partials/FrontendAuth.php`: `case 'cli_auth_approve': $this->handle_approve(); exit;` and `case 'cli_auth_approved': $this->handle_approved(); exit;`. FR-008 dispatch table (amended).

**Checkpoint**: Full approve flow wired with per-code nonce defense. All P1 stories pass. End-to-end CLI consent flow runnable per `quickstart.md` (with one expected delta — approve URL no longer carries `?server=`).

---

## Phase 6: User Story 5 — Activation Establishes Pretty URL (Priority: P2)

**Goal**: Fresh plugin activation makes `/acrossai-mcp-manager/` resolve immediately (not 404).

**Independent Test**: `curl -I https://example.com/acrossai-mcp-manager/` after fresh activate → HTTP 302.

- [ ] T031 [US5] Add an activation acceptance assertion. Either: (a) extend an existing `tests/phpunit/Includes/ActivatorTest.php` (if it exists) with a case that triggers `Activator::activate()` then asserts the rewrite rule + query var resolve via a parse-request walk; OR (b) document the explicit step in `specs/007-frontend-cli-auth/quickstart.md` §2 (already present — confirm wording). FR-005, SC-001. Manual gate acceptable for P2.

**Checkpoint**: Activation flow asserted.

---

## Phase 7: User Story 6 — Frontend CSS Loads Only on the Approval Page (Priority: P2)

**Goal**: `build/css/frontend.css` enqueued ONLY when `get_query_var('acrossai_mcp_auth')` is truthy.

**Independent Test**: `curl /wp-admin/ | grep -c 'acrossai-mcp-frontend-css'` → `0`; same for `/` and `/post-X/`. Consent page → `1`.

### Tests for User Story 6

- [x] T032 [P] [US6] Create `tests/phpunit/FrontendAuth/EnqueueAssetsTest.php` covering: (a) query var empty → handle NOT registered after `wp_enqueue_scripts`; (b) query var truthy → handle registered with src = `<plugin_url>/build/css/frontend.css`, version = manifest hash, deps = `[]`; (c) manifest missing (stub `is_readable` false) → handle still registered with fallback version, no errors emitted; (d) RTL: `wp_styles()->registered['acrossai-mcp-frontend']->extra['rtl']` === `'replace'`. FR-013, SC-004; research §R2.

### Implementation for User Story 6

- [x] T033 [US6] Add `public function enqueue_assets(): void` to `public/Partials/FrontendAuth.php`: `if ( ! get_query_var( self::QUERY_VAR ) ) { return; }`; `$path = dirname( ACROSSAI_MCP_MANAGER_PLUGIN_FILE ) . '/build/css/frontend.asset.php';`; `$version = ACROSSAI_MCP_MANAGER_VERSION;` (fallback); `if ( is_readable( $path ) ) { $asset = require $path; if ( is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) ) { $version = $asset['version']; } }` — NO `error_log()` per research §R2; `wp_enqueue_style( 'acrossai-mcp-frontend', plugins_url( 'build/css/frontend.css', ACROSSAI_MCP_MANAGER_PLUGIN_FILE ), [], $version );`; `wp_style_add_data( 'acrossai-mcp-frontend', 'rtl', 'replace' );`. FR-013.

**Checkpoint**: Asset scoping enforced. All six US tests pass.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: DoD gates, CI gates, deploy-time hygiene, residual security follow-up, full quickstart walk, architecture re-verify.

- [ ] T034 Run `vendor/bin/phpcs public/Partials/FrontendAuth.php includes/REST/CliController.php` and fix any WPCS strict violations. Required gates: zero errors, zero warnings, zero `WordPress.WP.I18n.MissingTranslatorsComment`, zero `WordPress.WP.I18n.MissingArgDomain`. FR-016, Spec §Definition of Done.
- [ ] T035 [P] Run `vendor/bin/phpstan analyse public/Partials/FrontendAuth.php includes/REST/CliController.php --level=8` and fix any errors. Spec §Definition of Done.
- [ ] T036 [P] Add CI grep gate under `.github/workflows/` to fail if `grep -rn 'add_action\|add_filter' public/Partials/FrontendAuth.php` returns any match. SC-006, FR-014.
- [ ] T037 [P] Add CI deploy-time gate (in `package.json` `scripts.validate-packages` or new `npm run validate-build-artifacts`) asserting `build/css/frontend.css`, `build/css/frontend.asset.php`, AND `build/css/frontend-rtl.css` exist AND `frontend.asset.php` returns an array of shape `['dependencies' => array, 'version' => string]`. Addresses SEC-006.
- [ ] T038 [P] Run `vendor/bin/phpunit tests/phpunit/FrontendAuth/ tests/phpunit/RestCli/PeekPendingServerTest.php` and assert every test added in T019, T020, T021, T026, T027, T032 passes. Spec §Definition of Done.
- [ ] T039 [P] Execute the AMENDED `specs/007-frontend-cli-auth/quickstart.md` end-to-end on a fresh WP 6.9 / PHP 8.0 install. All numbered steps + the 6 hardening grep assertions in §10 must pass. **NEW spoof regression**: after step 3, manually rewrite the `auth_url` to substitute `&server=spoofed` and verify the consent page renders the ORIGINAL server slug from the transient (NOT `spoofed`). Spec §Definition of Done, SC-001…SC-007.
- [ ] T040 [P] Run `npm run build` then `npm run validate-packages`. Confirm the build emits `build/css/frontend.css`, `build/css/frontend.asset.php`, and `build/css/frontend-rtl.css` with the expected shape. Spec §Definition of Done.
- [ ] T041 [P] Run `/speckit-security-review-followup` for the REMAINING residual findings: SEC-003 (broadened authz — LOW, tracked under Phase 6 hardening epic) and SEC-004 (GET-as-mutation — INFO, documented as acceptable per `nocache_headers` + WP nonce). SEC-001/002/005/006/007 are now in-plan; do NOT re-create tasks for them. Also schedule a Phase 5 OAuth-consent S9 audit task (separate epic, `/speckit-security-review-branch` against `005-oauth-connectors`).
- [ ] T042 Update `specs/007-frontend-cli-auth/spec.md` §"Resolved 2026-06-30" sub-section (added in T004) with the FINAL list of in-plan-fixed findings and verify cross-references resolve. Close the plan §"Spec ↔ Plan realignment" loop.
- [ ] T044 [P] **Deferred refactor (V2 from 2026-06-30 architecture review)**: Extract `PAGE_SLUG`, `QUERY_VAR`, and `get_base_url()` from `public/Partials/FrontendAuth.php` to a new `includes/Utilities/CliAuthRoutes.php` final class to resolve the bidirectional Phase 6 ↔ Phase 7 coupling. After extraction: (a) `CliController::auth_start()` consumes `CliAuthRoutes::get_base_url()` (no longer needs `use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;`); (b) `FrontendAuth` keeps thin deprecation wrappers (`FrontendAuth::PAGE_SLUG` etc. point at `CliAuthRoutes::PAGE_SLUG`) for back-compat, OR deletes them outright after grep-confirming zero external readers; (c) Loader wiring in `Main.php` and `Activator.php` references unchanged. **Acceptance criteria**: `grep -rn "FrontendAuth" includes/REST/CliController.php` returns zero matches. **Out-of-scope for this feature's merge** — register as a follow-up hardening epic; tracked as **DEV3** (Accepted bidirectional Phase 6 ↔ Phase 7 coupling pending A9 promotion) in `docs/memory/INDEX.md` until shipped.
- [ ] T043 Run `/speckit-architecture-guard-architecture-verify` (or its successor) to confirm the cross-cutting Phase 6 change (`peek_pending_server`) does not introduce architecture violations. Specifically: (a) confirm the new method satisfies A11 (pure stateless helper) — no instance state, no hook registration, just a read; (b) confirm the new cross-phase coupling point is reflected in `data-model.md` §7 (per T006); (c) confirm S9 capture is reflected in `docs/memory/INDEX.md` (already done this turn); (d) confirm Constitution §I–§VII + A1/A2/A6/A9 remain green. Verdict required before merge.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 0 (Amendments)**: No dependencies — start immediately. Many tasks parallel. BLOCKS Phase 1+ because subsequent tasks reference the amended FRs / contracts.
- **Phase 1 (Setup)**: Depends on Phase 0 completion.
- **Phase 2 (Foundational)**: Depends on Phase 1. ⚠️ **BLOCKS all user-story phases**. Note T018 is cross-cutting to Phase 6.
- **Phase 3 (US1 — MVP)**: Depends on Phase 2 (especially T018+T019 — the new `peek_pending_server` helper).
- **Phase 4 (US2)**: Depends on Phase 2 + T025 (login redirect in `maybe_render_page`). Tests-only.
- **Phase 5 (US3+US4)**: Depends on Phase 2 + T025 (switch dispatch). Adds approve flow.
- **Phase 6 (US5)**: Depends on Phase 2 only.
- **Phase 7 (US6)**: Depends on Phase 2 + T010 (build artifact verification).
- **Phase 8 (Polish)**: Depends on Phases 3–7. T043 (arch verify) gates merge.

### Within Each User Story

- Tests written FIRST and verified failing (TDD per spec §Definition of Done).
- Single-file implementation: render_page_shell → render_disabled_notice → handle_cli_auth → maybe_render_page → handle_approve → handle_approved → enqueue_assets. Sequential ordering preserves dependency chain.
- Cross-cutting: T018 (`peek_pending_server`) ships BEFORE T023 (handle_cli_auth) reads it.

### Parallel Opportunities

- **Phase 0**: T001, T002, T003, T004, T005, T006, T007, T008 ALL parallelizable (distinct file sections / distinct files); T009 sequential (new file creation).
- **Phase 1**: T011 parallel with T010 once T010 surfaces findings.
- **Phase 2**: T013, T014, T015 sequential (same file); T016 + T017 parallel; T018 sequential to T012 but parallel with T013–T017 (different file); T019 parallel with T012–T018.
- **Phase 3–7 tests**: T019, T020, T021, T026, T027, T032 ALL parallelizable (distinct test files).
- **Phase 8**: T035–T041 parallel; T034 + T042 + T043 sequential (T034 modifies impl, T042 modifies spec, T043 is final gate).

---

## Parallel Example: Phase 0 amendments

```bash
# All 8 spec/contract amendment tasks fire concurrently (distinct file sections):
Task: T001 — Amend FR-008
Task: T002 — Amend FR-009
Task: T003 — Amend FR-012
Task: T004 — Append §Resolved 2026-06-30
Task: T005 — Amend data-model §4
Task: T006 — Amend data-model §7
Task: T007 — Amend page-cli-auth.md
Task: T008 — Amend page-disabled-notice.md
# T009 follows once T001/T002/T006 land
```

## Parallel Example: All test files in parallel (post-Phase 2)

```bash
Task: T019 — PeekPendingServerTest.php           (CliController/)
Task: T020 — GetBaseUrlTest.php                  (FrontendAuth/)
Task: T021 — HandleCliAuthTest.php               (FrontendAuth/)
Task: T026 — MaybeRenderPageTest.php             (FrontendAuth/)
Task: T027 — HandleApproveTest.php               (FrontendAuth/)
Task: T032 — EnqueueAssetsTest.php               (FrontendAuth/)
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete **Phase 0: Amendments** (T001–T009). Spec / data-model / contracts now reflect security-hardened design.
2. Complete **Phase 1: Setup** (T010, T011).
3. Complete **Phase 2: Foundational** (T012–T019). ⚠️ Includes cross-phase Phase 6 helper.
4. Complete **Phase 3: US1** (T020–T025). Now the consent form renders with transient-bound slug; SEC-001 fix operational; per-code nonce minted.
5. **STOP AND VALIDATE**: Manually walk steps 1–5 of `quickstart.md` PLUS the new spoof regression in T039. The Approve button is rendered correctly but click-handling not wired yet — confirm everything up to the click.

### Incremental Delivery

1. **Amendments + Setup + Foundational + US1**: MVP — secure consent surface renders. Demo with spoof test.
2. **+US2**: assert login-redirect + amended kill-switch (Retry-After + noindex) behavior is regression-proof.
3. **+US3+US4**: wire approve flow with per-code nonce. End-to-end CLI consent flow with SEC-001/002 hardening.
4. **+US5+US6**: operator hygiene checked.
5. **Polish**: DoD gates + residual security follow-up + architecture verify → ready to ship.

### Single-Developer Strategy

This feature is sized for a single developer working primarily on one file plus one cross-cutting helper. Estimated wall-clock: ~1.5 dev-days for amendments + implementation, ~1 dev-day for tests + polish + quickstart walk + arch verify.

---

## Task Count Summary

- **Total tasks**: 43
- **Phase 0 Amendments**: 9 (T001–T009)
- **Phase 1 Setup**: 2 (T010–T011)
- **Phase 2 Foundational**: 8 (T012–T019) — includes cross-cutting CliController helper + its test
- **Phase 3 US1**: 6 (T020–T025) — 2 tests, 4 implementation
- **Phase 4 US2**: 1 (T026) — tests-only
- **Phase 5 US3+US4**: 4 (T027–T030) — 1 test, 3 implementation
- **Phase 6 US5**: 1 (T031)
- **Phase 7 US6**: 2 (T032–T033)
- **Phase 8 Polish**: 10 (T034–T043)

**Parallel opportunities**:
- Phase 0: 8 parallel amendment tasks
- Phase 2: 4 parallel verifies/impl
- Test files post-Phase 2: 6 parallel
- Phase 8: 7 parallel polish tasks
- **Total**: up to ~25 parallelizable across the feature lifetime.

**Independent test criteria** (unchanged from prior version except for spoof regression):
- US1: `curl -b <cookies>` against `?action=cli_auth&code=<valid>&server=DOES-NOT-MATTER` → 200 with consent form showing the transient's server name (NOT `DOES-NOT-MATTER`)
- US2: `curl -I` without cookies → 302 to `wp_login_url` with base-URL `redirect_to`; kill-switch path → 503 with `Retry-After` + `noindex`
- US3+US4: `curl -b` with valid per-code nonce → 302 to `cli_auth_approved`; with nonce minted against code `A` but URL `code=B` → 403
- US5: `curl -I /acrossai-mcp-manager/` after fresh activate → 302
- US6: `curl /wp-admin/`, `/`, `/post-X/` → zero `acrossai-mcp-frontend-css` matches

**Suggested MVP scope**: Phases 0 + 1 + 2 + 3 (T001–T025) — 25 tasks — delivers the secure consent form render with SEC-001/002/005 fixes operational. Approval click-through requires Phase 5.

## Notes

- [P] = different files (or different sections of the same multi-section file), no dependencies on incomplete tasks.
- [Story] labels: required in Phases 3–7 (US1–US6); omitted in Phases 0, 1, 2, 8.
- Tests verify failure FIRST per WP-PHPUnit harness convention.
- Commit after each task or logical group. `/speckit-git-commit` hook between phases.
- Stop at the MVP checkpoint (end of Phase 3) to validate before continuing.
- **Cross-phase change (T018) requires Phase 6 sign-off**: the `CliController::peek_pending_server()` method widens Phase 6's surface. Architecture-Guard re-verify (T043) is the gate. If the Phase 6 owner objects, fall back to deferring SEC-001 via `/speckit-security-review-followup` and revert T001+T003+T005+T006+T007 + T018+T019+T021(b)+T023.
- **Residual security findings (not addressed in-plan)**: SEC-003 (broadened authz — LOW, Phase 6 epic), SEC-004 (GET-as-mutation — INFO, documented). T041 spawns followup.
- **Phase 5 OAuth audit (S9 spillover)**: NOT a Phase 7 task. Schedule separately via `/speckit-security-review-branch` against `005-oauth-connectors`. Mentioned in T041 for tracking.
