# Implementation Plan: Frontend CLI Authentication Page

**Branch**: `007-frontend-cli-auth` | **Date**: 2026-06-25 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/007-frontend-cli-auth/spec.md`

---

## Summary

Replace the existing `public/Partials/FrontendAuth.php` (shipped as Phase 6.0 absorbed into PR #8) with a re-spec'd implementation that encodes the four intentional changes documented in spec §Context:

1. `QUERY_VAR` renamed `acrossai_mcp_frontend_auth` → `acrossai_mcp_auth`.
2. Authorization broadened from `manage_options` → ANY logged-in user.
3. Inline `<style>` replaced with externally-enqueued `build/css/frontend.css` (versioned via `build/css/frontend.asset.php`).
4. Nonce action simplified from `cli_auth_approve_<code>` → `cli_auth_approve`.

Implementation surface: ONE PHP file (`public/Partials/FrontendAuth.php`), ONE optional CSS edit (`src/scss/frontend.scss` if the existing source has nothing useful for this page), and the activation hook is already wired (`Includes\Activator::activate()` already calls `FrontendAuth::instance()->register_rewrite_rule(); flush_rewrite_rules();` per existing code). `Main::define_public_hooks()` already wires all four Loader hooks. No new files outside the singleton class itself.

### Spec ↔ Plan realignment (planning-time decisions)

Two deviations between the spec (007 spec.md) and the planning input the user provided were detected at plan time. Both are accepted as planning-time decisions; the spec will be updated at implementation time to match:

- **Kill switch retained.** The spec's Assumptions said "no feature-flag kill switch" but the planning input includes `$enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );` AND a `render_disabled_notice()` private method. **Plan reinstates the option-gated kill switch.** It runs AFTER login + action parse and BEFORE the dispatch switch. If disabled, `render_disabled_notice()` emits a `503 Service Unavailable` page using the standard `render_page_shell()`. This is the same kill-switch the existing implementation has and is a known-good operator escape hatch.
- **Login redirect uses base URL only.** The spec FR-007.3 prescribed preserving `action`/`code`/`server`/`_wpnonce` in the `redirect_to` parameter. The planning input shows `wp_redirect( wp_login_url( self::get_base_url() ) )` — base URL only. **Plan adopts the simpler form** (less code, no edge cases around URL-encoding of CLI-supplied opaque values that could fail `wp_validate_redirect()`). UX trade-off: after login the user lands on `/acrossai-mcp-manager/` with no `?action=`, so they see the "Missing Authentication Parameters" message and must reopen the CLI's `auth_url` to retry. Acceptable because the CLI is still polling and the operator can copy/paste once.

Both deviations are minor surface changes; FR-001 through FR-015 hold otherwise.

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.0+ (constitution target) |
| Primary dependencies | `automattic/jetpack-autoloader ^5.0` (existing); `Includes\REST\CliController` (Phase 6, shipped); WP core (`wp_verify_nonce`, `nocache_headers`, `wp_safe_redirect`, `home_url`, `wp_enqueue_style`) |
| Storage | None new. Reads from existing `acrossai_mcp_npm_login_enabled` option. Triggers transient writes inside `CliController::approve_auth_code()`. |
| Testing | WP-PHPUnit (existing harness from Phase 5.0 / 6 — `tests/phpunit/bootstrap-wp.php`). Tests live in `tests/phpunit/FrontendAuth/` (the existing test files for the Phase 6.0 absorption can be replaced 1-for-1 against the new spec). |
| Target platform | WordPress 6.9+; single-site only |
| Project type | WordPress plugin — `Public\Partials\*` |
| Performance goals | Render p95 ≤ 50ms (no DB queries except the one `get_option('acrossai_mcp_npm_login_enabled')` autoload-cached read + the `CliController::approve_auth_code()` transient round-trip on the approve branch only) |
| Constraints | A1 (zero `add_action`/`add_filter` in class); A2 (singleton + private ctor); A6 (`use` imports); FR-001 class constants; FR-007 strict `template_redirect` control flow; FR-009 nonce-before-mutation |
| Scale / scope | **1 PHP file replaced** (~250 lines) + tests updated. Zero new DB tables, zero new options, zero new REST routes. |

### Hard prerequisites (P0 dependencies)

All four are shipped on `feature/issue-3`:

1. `Includes\REST\CliController::approve_auth_code( string, int ): bool` — Phase 6 FR-008 ✅
2. `Includes\Main::define_public_hooks()` — already wires the four Loader hooks against `FrontendAuth::instance()` ✅
3. `Includes\Activator::activate()` — already calls `FrontendAuth::instance()->register_rewrite_rule()` + `flush_rewrite_rules()` ✅
4. `build/css/frontend.css` + `build/css/frontend.asset.php` — already committed (verified via `git ls-files build/css`); asset.php shape: `['dependencies' => array(), 'version' => 'fbb750fd312778403036']` ✅

No P0 blockers. This is a single-file replacement with regression-test coverage.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| I. Modular Architecture | ✅ | Single-file class with one purpose (browser-mediated CLI consent). Decoupled from REST sibling via the one static-method call site `CliController::approve_auth_code()`. |
| II. WordPress Standards | ✅ | PHPCS WPCS strict + PHPStan L8 mandated; existing baseline preserved. |
| III. Security First | ✅ | Nonce verified BEFORE state mutation (FR-009); `wp_unslash` + `sanitize_text_field` on every `$_GET` read (FR-009, FR-010); `esc_*` at every output point (FR-012); `nocache_headers()` BEFORE output (FR-007.2). One **documented deviation**: no `manage_options` check — see "Complexity Tracking" + spec §Assumptions (intentional broadening; threat model is "user clicks Approve on their own behalf"). |
| IV. User-Centric Design (DataForm) | ✅ — N/A | No admin UI. Browser approval page is a `template_redirect` virtual page, not an admin menu. Same family as the Phase 5 OAuth consent page (A13 exemption). |
| V. Extensibility Without Core Modification | ✅ | All hooks via Loader; the one external call (`CliController::approve_auth_code`) is a hard dep on a shipped sibling, not an optional integration. |
| VI. Reusability & DRY | ✅ | Two class constants (`PAGE_SLUG`, `QUERY_VAR`) deduplicate the slug + query var strings. `get_base_url()` is the single URL source consumed by `CliController::auth_start()` and `Activator`. |
| VII. Definition of Done | ✅ | DoD gates listed in spec §Success Criteria; PHPUnit harness reused from Phase 6. |
| A1 — Hooks via Loader | ✅ | Class constructor remains empty (private + `{}`); all four hooks registered by `Main::define_public_hooks()` (already in place). |
| A2 — Singleton pattern | ✅ | `protected static $_instance = null;` + `public static function instance(): self` + `private function __construct() {}`. |
| A6 — `use` imports in `Public\*` | ✅ | `use AcrossAI_MCP_Manager\Includes\REST\CliController;` at top of file. |
| **`manage_options` broadened** | ⚠ **Documented deviation** | Any logged-in user may proceed. Threat-model rationale in spec §Assumptions: "user is consenting on their own behalf". Mitigated by (a) downstream `CliController::approve_auth_code` writing the consenting user's `user_id`, NOT the auth-code-issuer's; (b) the Application Password issued in `/auth/exchange` is scoped to that user's capabilities. Reverse: a subscriber-level user approving still only obtains a subscriber-scoped App Password — bounded blast radius. **No new memory capture needed** — this is feature-specific, not a reusable pattern. |
| **B4** — Unescaped dot in PCRE rewrite rule | ✅ Mitigated | Rewrite is `'^acrossai-mcp-manager/?$'` — no `.` to escape. |
| **B5** — Public ctor on singleton | ✅ Mitigated | `private function __construct() {}`. |
| **Kill switch retained** (planning-time realignment) | ✅ | `acrossai_mcp_npm_login_enabled` option default `false`. Operators MUST explicitly enable the page; absent the option, all visits to `/acrossai-mcp-manager/?action=*` receive the 503 disabled notice after login. Default-off is the safe stance. |

**Result**: All gates pass with one documented deviation (manage_options broadening) which is feature-local and does not warrant a memory capture.

## Project Structure

### Documentation (this feature)

```text
specs/007-frontend-cli-auth/
├── plan.md                       # THIS FILE
├── spec.md                       # 6 user stories + 15 FRs + 7 SCs (already written)
├── research.md                   # Phase 0 — decisions about nonce scope, asset enqueue fallback, redirect URL preservation
├── data-model.md                 # Phase 1 — query-var + GET-param shape + the `acrossai_mcp_npm_login_enabled` option
├── contracts/                    # Phase 1
│   ├── page-cli-auth.md          # `?action=cli_auth` HTML response
│   ├── page-cli-auth-approve.md  # `?action=cli_auth_approve` redirect contract
│   ├── page-cli-auth-approved.md # `?action=cli_auth_approved` HTML response
│   └── page-disabled-notice.md   # 503 kill-switch HTML response
├── quickstart.md                 # Phase 1 — end-to-end manual walk
├── checklists/requirements.md    # spec quality checklist (already written; all green)
└── tasks.md                      # /speckit-tasks output (NOT created here)
```

### Source Code (repository root)

```text
public/
└── Partials/
    └── FrontendAuth.php          # REPLACE — full re-spec of existing Phase 6.0 module

includes/
├── Main.php                       # NO CHANGES (Loader wiring already in place from PR #8)
└── Activator.php                  # NO CHANGES (already calls register_rewrite_rule + flush_rewrite_rules)

build/css/
├── frontend.css                   # NO CHANGES (already shipped)
└── frontend.asset.php             # NO CHANGES (already shipped)

src/scss/
└── frontend.scss                  # OPTIONAL EDIT — add minimal styles for the consent page if desired

tests/phpunit/FrontendAuth/   # REPLACE tests 1-for-1 against the new spec
├── MaybeRenderPageTest.php
├── HandleApproveTest.php
├── HandleCliAuthTest.php
├── EnqueueAssetsTest.php
└── GetBaseUrlTest.php
```

**Structure Decision**: This is a **single-file class replacement** within an established WP-plugin layout. The boot-flow, activation, and asset-build pipelines are already configured by prior phases — no scaffolding work, no new namespaces, no new test harness. The plan focuses entirely on the `FrontendAuth` class's internal method graph and on the assertion table for tests.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Broadened `manage_options` check to "any logged-in user" | Spec §Assumptions: the consent flow's threat boundary is "the logged-in user is consenting on their own behalf to issue an App Password scoped to themselves". Requiring `manage_options` would block the common case where a developer-role user wants to authorize a CLI tool against their own account. | Keeping `manage_options` blocks legitimate use cases. A capability-mapping middle-ground (`edit_posts`, custom `acrossai_mcp_cli_authorize` cap) would require an admin UI to manage role grants — out of scope this phase. Future "scoped CLI access" feature can re-introduce gating. |
| `acrossai_mcp_npm_login_enabled` option-gated kill switch (default `false`) | Operator escape hatch: lets site admins disable the entire consent surface without deactivating the plugin (which would also remove the rewrite rule and 404 the URL). Default-OFF is the safe stance — operators MUST explicitly enable. | No kill switch means the only way to disable the surface is plugin deactivation, which has broader blast radius (rewrite rules, REST routes, cron all disappear). |

## Phase 0 Output Plan (Research)

Phase 0 produces `research.md` resolving the three nontrivial design questions surfaced by the spec→plan realignment:

1. **Nonce scope: action-only vs per-code** — confirm `cli_auth_approve` (spec FR-009) is sufficient given that `approve_auth_code()` enforces single-use semantics downstream.
2. **Asset enqueue fallback when `frontend.asset.php` is unreadable** — what version string to fall back to and whether to emit an `error_log()` warning.
3. **Login redirect URL preservation** — confirm the planning-time decision to pass `get_base_url()` (base only) rather than the full request URI.

No NEEDS CLARIFICATION markers remain in the spec; Phase 0 is reconciliation, not unblocking.

## Phase 1 Output Plan (Design & Contracts)

Phase 1 produces:

- **`data-model.md`** — the query-var/option/GET-param shape this module reads and writes, plus the cross-class flow into `CliController` transients.
- **`contracts/page-cli-auth.md`** — HTTP response shape for `?action=cli_auth` (HTML page, no JSON).
- **`contracts/page-cli-auth-approve.md`** — redirect-or-error contract for the state-mutating branch.
- **`contracts/page-cli-auth-approved.md`** — success page response shape.
- **`contracts/page-disabled-notice.md`** — 503 disabled-notice response shape.
- **`quickstart.md`** — operator walkthrough: install → enable option → trigger CLI flow → verify approval → verify App Password received.

Agent context (`CLAUDE.md`) gets a single-line plan-link update inside the `<!-- SPECKIT START -->...<!-- SPECKIT END -->` markers.

## Phase 1 Re-Check of Constitution Gates

After Phase 1 design, no new violations are introduced. The three new artifacts (data-model.md, contracts/, quickstart.md) are documentation only and add no code surface. The "manage_options broadened" + "kill switch retained" deviations carry forward unchanged.

**Result**: gates remain green; ready for `/speckit-tasks`.
