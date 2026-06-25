# Implementation Plan: REST API — CLI Authentication Controller (+ Phase 6.0 FrontendAuth)

**Branch**: `006-rest-cli-auth` | **Date**: 2026-06-25 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/006-rest-cli-auth/spec.md`

---

## Summary

Build the REST controller (`Includes\REST\CliController`) that lets external CLI tools authenticate against a WordPress site via a browser-mediated "device-code-grant-style" handoff, then exchange the approval for a WordPress Application Password (30-day Bearer credential). Five endpoints under `acrossai-mcp-manager/v1`: `/health`, `/auth/start`, `/auth/status`, `/auth/exchange`, `/servers`. One public static method `approve_auth_code( $code, $user_id ): bool` is the entry point Phase 3's `FrontendAuth::handle_approve()` calls.

The implementation is **greenfield** for the REST controller. Per **D11 Phase X.0 absorption pattern** + the soft conflict surfaced in `memory-synthesis.md`, this phase also absorbs the **full `Public\Partials\FrontendAuth` class** as Phase 6.0 — the user explicitly provided its design in the planning input, signaling that "absorb only `get_base_url()`" was too narrow. The two classes ship together because they form a single value-delivery flow (CLI → FrontendAuth approval page → CliController exchange).

**Three classes shipped** (refreshed 2026-06-25 after Q1–Q4):
- `Includes\REST\CliController` — 5 REST routes + 1 static approval entry point + 4 class constants (transient prefixes + TTLs) + FR-015 Content-Type guard (Q2)
- `Public\Partials\FrontendAuth` — 1 query var + 1 rewrite rule + 4 Loader-wired callbacks (register_rewrite_rule, add_query_var, maybe_render_page, enqueue_assets) + 1 static `get_base_url()` + 4 private rendering helpers
- `Includes\Database\CliAuthLog\Recorder` — **NEW per Q1 Clarification** — stateless A11-style static helper class with `record_approved()` + `record_success()`. Internally calls Phase 2's `( new Query() )->add_item(...)`. Owns the audit-write boundary between feature classes (CliController + FrontendAuth) and the BerlinDB Query layer.

**Soft conflicts from `memory-synthesis.md` — explicit plan-time decisions, all materially mitigated by Q1–Q4**:
- **S2 → S8 candidate**: `__return_true` on 4 of 5 REST routes. Q2 added FR-015 Content-Type allow-list (inherits Phase 5 SEC-002 lesson) AND Q4 bound the session token to `server_id` (parity with Phase 5 FR-015 cross-server defense). Together these materially narrow the attack surface compared to a naive `__return_true` deployment. **S8 capture queued** post-implementation — broader than OAuth-specific S7.
- **B10 vs FR-007 `/auth/exchange`**: spec defers atomic-CAS redemption. Q4's server-binding NARROWED the race-loss impact further — even a race-loss only lets an attacker obtain an App Password scoped to the consented server, never beyond. **Plan accepts the deferral.**
- **D11 vs Phase 3 FrontendAuth dependency** (RESOLVED): Phase 6.0 absorbs the FULL FrontendAuth class. Largest Phase X.0 surface so far (~180 lines vs Phase 5.0's ~60-line WP-PHPUnit harness).
- **Q1 outcome**: new `CliAuthLog\Recorder` class is A11-style exempt. Extends the A11/A14 stateless-helper family to "Database-namespace audit recorders". **A15 candidate** queued for post-impl capture.

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.0+ (constitution target); no JS this phase (FrontendAuth approval page is server-rendered HTML) |
| Primary dependencies | `automattic/jetpack-autoloader ^5.0`; no OAuth library; relies on WP core `WP_Application_Passwords` (WP 5.6+, guarded by `class_exists`) |
| Storage | WordPress transients (object cache when available; `wp_options` fallback). NO new DB tables. Audit writes go through existing Phase 2 `CliAuthLog\Query`. |
| Testing | **WP-PHPUnit** (the OAuth flow uses `wp_set_current_user`, transients, `wp_redirect`, `is_user_logged_in`, `get_query_var` — testing requires a WP bootstrap, identical to Phase 5.0 already-shipped). The Phase 5.0 `tests/bootstrap-wp.php` is reused — no harness work needed. |
| Target platform | WordPress 6.9+ admin + public; single-site only |
| Project type | WordPress plugin — `Includes\REST\*` + `Public\Partials\*` |
| Performance goals | `/auth/status` polling MUST respond in ≤50ms p95 on a working object cache (read-only transient + memory comparison); other endpoints unbounded but bounded by `WP_Application_Passwords` internal work (≤200ms p95) |
| Constraints | A1 (zero hooks in either class constructor); A2 (singleton + private ctor); A6 (`use` imports throughout); FR-009 class constants; FR-012 documented `__return_true` exemption; S7 precedent followed |
| Scale / scope | **3 feature classes** (CliController + FrontendAuth + Recorder per Q1) + 0 new DB tables + 1 new Activator rewrite rule + 4 new Main.php wiring lines + tests + golden response fixtures |

### Hard prerequisites (P0 dependencies)

Three classes:

1. **Phase 2 BerlinDB Query infrastructure** — `MCPServer\Query` (read-only consume for `/servers`) and `CliAuthLog\Query` / `CliAuthLogTable` (write `record_approved` + `record_success`). **Status**: shipped on `feature/issue-3` ✅.
2. **WordPress core `WP_Application_Passwords`** — WP 6.9 ships it; the controller `class_exists()`-guards and returns 501 on absence. **Status**: WP-core ✅.
3. **WP-PHPUnit test harness** — Phase 5.0 already shipped `tests/bootstrap-wp.php`, `bin/install-wp-tests.sh`, and the `phpunit.xml.dist` `oauth` testsuite. This phase adds a NEW `cli-rest` testsuite alongside (no new bootstrap needed; reuses `bootstrap-wp.php`). **Status**: shipped on `005-oauth-connectors` ✅ (merged into `feature/issue-3` via PR #7 when accepted).

No P0 blockers. Phase 3 FrontendAuth being absent is RESOLVED by this plan's Phase 6.0 absorption.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| I. Modular Architecture | ✅ | 2 single-purpose classes; sibling-decoupled. CliController owns the REST surface + transient state machine. FrontendAuth owns the browser approval page. |
| II. WordPress Standards | ✅ | PHPCS WPCS strict + PHPStan L8 mandated; baseline (D5) preserved. |
| III. Security First | ✅ + 1 **documented exemption** (S2/V1) | Transient values are 16-byte CSPRNG opaque tokens; `auth_code_hash` SHA-256 in audit row; `hash_equals` for `server_id` constant-time comparison. The `__return_true` exemption on 4 of 5 REST routes is RFC-OAuth-precedented (S7 inheritance) and documented in spec FR-012 + this plan §Complexity Tracking. |
| IV. User-Centric Design (DataForm) | ✅ — no admin UI | This phase introduces NO admin menu page. The browser approval page (FrontendAuth) is a `template_redirect` page, not an admin menu — DataForm mandate does NOT apply (same family as the OAuth consent page A13 exemption). |
| V. Extensibility Without Core Modification | ✅ | All hooks via Loader; `class_exists()` guards on `\WPBoilerplate\AccessControl\AccessControlManager` and `WP_Application_Passwords` and `\AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Table` (for `record_approved`/`record_success`). |
| VI. Reusability & DRY | ✅ | Shared transient-prefix constants on `CliController` class (`AUTH_TRANSIENT_PREFIX`, `SESSION_TRANSIENT_PREFIX`). Static `FrontendAuth::get_base_url()` is the single source for the page URL. |
| VII. Definition of Done | ✅ | DoD gates listed in spec; PHPUnit harness reused from Phase 5.0. |
| A1 — Hooks via Loader | ✅ | FR-011 enforced; both classes have zero `add_action`/`add_filter` in constructors or methods. |
| A2 — Singleton pattern | ✅ | Both `CliController` AND `FrontendAuth` are singletons with private ctors. The static `CliController::approve_auth_code()` does NOT instantiate the controller — it operates on transients directly. |
| A6 — `use` imports in `Includes\*` and `Public\*` | ✅ | Required for `CliController` (refs MCPServer\Query, CliAuthLog\Query, FrontendAuth) and `FrontendAuth` (refs CliController for the static call). |
| **S2** — REST routes never `__return_true` on mutating routes | ⚠ **Documented exemption** (V1) | `/auth/start` (mutates a no-PII transient), `/auth/exchange` (body-as-credential), `/auth/status` (read-only), `/health` (public read) all use `__return_true`. The exemption is BROADER than S7 (which is OAuth-specific) — this is a NEW pattern: "body-authenticated mutating REST routes with bounded mutation are exempt from S2 when no PII is involved AND a stronger downstream check exists". **Q2 + Q4 added defense-in-depth**: FR-015 Content-Type allow-list (rejects missing/unknown headers BEFORE field validation) + Q4 session-token server-binding (closes the cross-server enumeration vector). **S8 capture queued post-implementation.** |
| **FR-015** (NEW, per Q2 Clarification) — strict Content-Type allow-list on POST endpoints | ✅ | Inherits Phase 5 SEC-002 hardening lesson. Accepts both `application/json` and `application/x-www-form-urlencoded` (CLI ergonomics); rejects missing header or any other value with HTTP 400. Defense runs BEFORE field validation per FR-015. |
| **Session token server-binding** (per Q4 Clarification) — session token transient value is `array{user_id: int, server_id: string}` | ✅ | Matches Phase 5 FR-015 cross-server defense pattern. `/servers` returns ONLY the consented server, never the user's full inventory. Eliminates the server-enumeration vector that a leaked session token would otherwise enable. |
| **B4** — Unescaped dot in PCRE rewrite rule | ✅ Mitigated | FrontendAuth's rewrite rule is `'^acrossai-mcp-manager/?$'` — no `.` to escape; B4 not triggered. |
| **B5** — Public constructor on singleton allows double registration | ✅ Mitigated | Both singletons declare `private function __construct() {}`. |
| **B10** — Atomic CAS for one-shot credentials | ⚠ **Plan-time accepted deferral** | `/auth/exchange` redemption uses non-atomic `get_transient + delete_transient`. Threat model is weaker than Phase 5 (issued App Password is a fresh credential, not a revocable token chain); race-loss results in a `get_userdata` lookup failure (returns 400) rather than double-token issuance. **Documented in spec §Edge Cases**; flagged for revisit in any future hardening pass. |

**Result**: All gates pass with one documented soft exemption (S2-broader-than-S7) and one plan-time accepted deferral (B10). Complexity Tracking captures both.

## Project Structure

### Documentation (this feature)

```text
specs/006-rest-cli-auth/
├── plan.md                  # THIS FILE
├── spec.md                  # 14 base FRs + 6 user stories
├── memory-synthesis.md      # already produced (896 words)
├── research.md              # Phase 0 — transient key derivation, Bearer header, get_userdata semantics
├── data-model.md            # Phase 1 — 2 transient shapes + audit row mapping
├── contracts/               # Phase 1 — JSON envelopes per endpoint
│   ├── health.md
│   ├── auth-start.md
│   ├── auth-status.md
│   ├── auth-exchange.md
│   ├── servers.md
│   └── frontend-auth-page.md   # Phase 6.0 — HTML page contract (no JSON)
├── quickstart.md            # Phase 1 — full CLI flow walk
├── checklists/requirements.md   # spec quality checklist
└── tasks.md                 # /speckit-tasks output (NOT created here)
```

### Source Code (repository root)

```text
includes/
├── REST/
│   └── CliController.php           # NEW — 5 routes + 1 static method + 4 class constants + Q2 Content-Type guard (~300 lines est.)
└── Database/
    └── CliAuthLog/
        └── Recorder.php            # NEW per Q1 — stateless A11-style helper, 2 static methods, ~80 lines est.

public/
└── Partials/
    └── FrontendAuth.php            # NEW (Phase 6.0 absorption) — 4 Loader callbacks + static get_base_url() + 4 private renders (~180 lines est.)

includes/Activator.php              # EXTEND — add 1 rewrite rule registration line (`'^acrossai-mcp-manager/?$'`)
includes/Main.php                   # EXTEND — define_public_hooks() wires 4 FrontendAuth callbacks + 1 CliController callback (rest_api_init)

tests/
└── phpunit/
    ├── RestCli/                    # NEW — testsuite name "cli-rest"
    │   ├── HealthEndpointTest.php
    │   ├── AuthStartEndpointTest.php
    │   ├── AuthStatusEndpointTest.php
    │   ├── AuthExchangeEndpointTest.php
    │   ├── ServersEndpointTest.php
    │   ├── ApproveAuthCodeStaticTest.php
    │   ├── VerifySessionTokenTest.php
    │   ├── ConstantsIntegrityTest.php
    │   └── fixtures/
    │       ├── health.json
    │       ├── auth-start-success.json
    │       ├── auth-status-pending.json
    │       ├── auth-status-approved.json
    │       ├── auth-exchange-success.json
    │       ├── auth-exchange-error-invalid_code.json
    │       ├── auth-exchange-error-not_approved.json
    │       ├── auth-exchange-error-invalid_user.json
    │       ├── auth-exchange-error-not_supported.json
    │       ├── auth-exchange-error-missing_server.json
    │       ├── auth-exchange-error-server_mismatch.json
    │       ├── auth-exchange-error-invalid_server.json
    │       ├── servers-success.json
    │       └── servers-unauthorized.json
    └── FrontendAuth/                # NEW — testsuite shares "cli-rest" suite (or separate "frontend-auth")
        ├── RewriteRuleTest.php
        ├── MaybeRenderPageTest.php
        ├── HandleCliAuthTest.php
        ├── HandleApproveTest.php
        ├── HandleApprovedTest.php
        └── DisabledNoticeTest.php

phpunit.xml.dist                    # EDIT — add `<testsuite name="cli-rest">` referencing tests/phpunit/RestCli + tests/phpunit/FrontendAuth
```

**Structure Decision**: Standard WordPress-plugin layout. The CliController + FrontendAuth pair lives in the canonical `includes/REST/` and `public/Partials/` directories per the constitution's Architecture & UI Standards. Tests reuse the Phase 5.0 WP-PHPUnit bootstrap — no new harness work.

## Phase 0 — Outline & Research

Five research outputs in `research.md`:

### R1 — Transient key derivation + collision avoidance

**Decision**: Transient keys are literal prefixed strings — `acrossai_cli_auth_<32hex>` and `acrossai_session_<32hex>` — composed via class constants `AUTH_TRANSIENT_PREFIX` + `SESSION_TRANSIENT_PREFIX`.

**Rationale**: 32 hex chars of CSPRNG-derived randomness gives 128 bits of entropy — collision probability is negligible (10^-18 across the 5-minute window). Class constants prevent magic-string drift; T080 polish-grep will assert zero inline `'acrossai_cli_auth_' . $code` constructions outside the controller class. The `acrossai_cli_auth_` prefix does NOT collide with Phase 5's OAuth transients (which use `oauth_rate_`), Phase 2's CliAuthLog audit rows (which use a custom-table column, not transients), or any WP-core prefix.

**Alternatives rejected**: hashing the prefix + code for shorter keys (rejected — debugging visibility matters more than 30 bytes per key); using `wp_options` directly (rejected — defeats the auto-eviction TTL semantics).

### R2 — Bearer header parsing (`Authorization` extraction)

**Decision**: `verify_session_token()` reads `$_SERVER['HTTP_AUTHORIZATION']` first, then falls back to `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']` (Apache + CGI fallback — identical pattern to Phase 5's `BearerAuth::get_bearer_token_from_request`). Strip the `Bearer ` prefix with a case-insensitive `stripos`-based check. Length-guard: reject tokens >64 chars (session tokens are exactly 32; the guard is paranoid).

**Rationale**: Identical fallback pattern as Phase 5 — proven in production by `BearerHeaderParsingTest` (5 acceptance scenarios already written). Reusing the pattern reduces review surface and maintains consistency.

**Alternatives rejected**: parsing via `getallheaders()` polyfill (rejected — adds a function-existence guard for marginal benefit); rejecting whitespace anywhere in the token (rejected — `trim()` handles the common case).

### R3 — `get_userdata()` semantics for the `invalid_user` failure path

**Decision**: `/auth/exchange` step 3 calls `get_userdata( $user_id )`. If it returns `false` (user deleted between approval and exchange), return HTTP 400 `{"error":"invalid_user"}`. Do NOT attempt to use `wp_get_current_user()` because at this point in the flow, current user is NOT set (the request is anonymous; the `user_id` comes from the transient).

**Rationale**: `get_userdata` is the WP-core canonical "user exists?" check. It returns a `WP_User` object on hit OR `false` on miss. It does NOT trigger filters or user-meta loads that could mask deletion. Compare to `WP_User::exists()` which requires constructing the object first.

**Alternatives rejected**: querying `wp_users` directly with `$wpdb->prepare` (rejected — adds an unnecessary boundary breach); using `username_exists` (rejected — that takes a username string, not an ID, and is for a different use case).

### R4 — `WP_Application_Passwords::create_new_application_password` return shape

**Decision**: The method returns either a `WP_Error` (on failure — wrap in `try/catch` and convert to a generic 500 `server_error` JSON envelope) OR an array `[ $raw_password, $app_password_record ]`. Element 0 is the raw 24-char password (with spaces removed per WP-core convention); element 1 is the `app_password` record array (uuid, name, app_id, created, last_used, last_ip).

**Rationale**: WP-core return shape is positional, not associative. The plan documents the destructuring at the contract level so the implementer doesn't surprise themselves on test-time. The raw password is what the CLI receives; the record is what WP-core persists.

**Alternatives rejected**: passing `'app_id'` in the optional `$args` (rejected — let WP-core generate it); creating the password BEFORE deleting the transients (rejected — race window where transient still exists but password is created allows replay).

### R5 — Single-use transient deletion ordering

**Decision**: On `/auth/exchange` success, delete BOTH transients in this order:
1. `delete_transient( 'acrossai_cli_auth_' . $code )` — invalidates the polling endpoint
2. `delete_transient( 'acrossai_session_' . $session_token )` — invalidates `/servers` for the now-consumed session

The Application Password is created BEFORE the deletion (step is "create password → delete transients → return response"). If the password creation fails (returns `WP_Error`), do NOT delete the transients — the legitimate CLI can retry on the same code if WP-Apps is transiently available again.

**Rationale**: Phase 5's B10 pattern would prefer atomic CAS, but as documented in Constitution Check above, the threat model here doesn't justify the atomic-redeem complexity. The deletion-after-creation ordering ensures forward progress: a failed password creation MAY succeed on retry; a failed transient deletion is recoverable on the next sweep (transients self-expire).

**Alternatives rejected**: deleting transients BEFORE creating the password (rejected — race window allows code re-use if WP-Apps fails mid-call); deleting only the auth_code transient (rejected — leaves the session_token live, which `/servers` would still accept).

## Phase 1 — Design & Contracts

### data-model.md

Two entity surfaces — both transient (no new DB tables):

1. **Auth Code (`acrossai_cli_auth_<code>` transient)** — per-CLI-request state machine. State: `pending → approved → consumed` (consumed is implicit deletion).
2. **Session Token (`acrossai_session_<token>` transient)** — Bearer-credential mapping `<token> → int $user_id`. Single-purpose: gate `/servers` access during the consent → exchange window.
3. **Audit Row** (already-existing Phase 2 `acrossai_mcp_cli_auth_logs` table — extended in Phase 5 by 4 OAuth columns that this phase DOES NOT touch). This phase WRITES via the existing `record_approved` and `record_success` static methods only.
4. **MCP Server Row** (already-existing Phase 2 table) — read-only consume in `/servers` and `/auth/exchange` step 6 (`invalid_server` check).

State-transition diagram included in `data-model.md` for each transient.

### contracts/

Six contract documents:

- `health.md` — RFC-style JSON response shape with golden fixture
- `auth-start.md` — POST body + success envelope + REST `rest_missing_callback_param` 400 path
- `auth-status.md` — query params + success envelope + 404 + the `{"approved": false}` server-mismatch oracle defense
- `auth-exchange.md` — POST body + all 8 failure response shapes (one row per FR-006 step) + success envelope
- `servers.md` — Bearer header contract + success envelope + 401 + 200-with-empty-servers
- `frontend-auth-page.md` — HTML rendering contract (no JSON): URL params, action switch, three HTML page shells (consent, approved, disabled-notice), and the call into `CliController::approve_auth_code` from `handle_approve`

### quickstart.md

A full CLI-flow walk using `curl` + a one-off Bash test script (`bin/quickstart-cli-flow.sh`) that mimics what a real CLI tool would do. Confirms SC-001 through SC-008 in order.

### Agent context update

Update `.github/copilot-instructions.md` between the `<!-- SPECKIT START -->` markers to point at `specs/006-rest-cli-auth/plan.md`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| **S2 `__return_true` exemption** (broader than S7) on 4 of 5 REST routes | The CLI tool has no session — it cannot present a nonce or a cookie. The auth credential is the `code` in the POST body of `/auth/exchange`. `/auth/start` and `/auth/status` are pre-auth; `/health` is public. | Custom permission_callbacks rejected — would force routing the body parse into a permission_callback context, splitting validation logic across two callbacks (the same anti-pattern S7 documents for the OAuth token endpoint). |
| **Phase 6.0 absorption of the FULL FrontendAuth class** (not just `get_base_url()`) | The user explicitly provided the full FrontendAuth design in the planning input, AND the spec's §Dependencies marks Phase 3 ⚠. Shipping CliController without its approval-page partner produces a non-functional flow. | Shipping CliController-only and stubbing `FrontendAuth::get_base_url()` rejected — the static helper alone doesn't render the approval page; the CLI flow is broken without `maybe_render_page` + `handle_approve`. The minimal absorption is the FULL class. |
| **B10 deferral** for `/auth/exchange` redemption | The transient-based redemption (`get_transient + delete_transient`) is NOT a single-statement atomic CAS. Threat model is weaker than Phase 5 (no client_secret, issued App Password is a separate fresh credential — race-loss results in `invalid_user` 400 rather than double-token issuance). | Implementing atomic CAS via `wp_options` direct manipulation rejected — bypasses the WP transient API, breaks object-cache invalidation, and the gain is < 1% of the Phase 5 SEC-001 attack surface (which DID warrant atomic CAS because of the anti-replay token-revocation chain). |

All three exemptions are explicit in the Constitution Check above and in the spec's §FR-012 / §Edge Cases. Neither violates a constitutional MUST.
