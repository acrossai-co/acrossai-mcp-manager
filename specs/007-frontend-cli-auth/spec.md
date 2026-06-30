# Feature Specification: Frontend CLI Authentication Page

**Feature Number**: 007
**Feature Branch**: `007-frontend-cli-auth`
**Created**: 2026-06-25
**Status**: Draft
**Spec**: `specs/007-frontend-cli-auth/spec.md`
**Input**: User description: "Frontend CLI Authentication Page — virtual page at `/acrossai-mcp-manager/` for AI clients to authorize CLI access"

---

## Clarifications

### Session 2026-06-25

- Q: Should user-facing strings be wrapped for translation with the `acrossai-mcp-manager` text domain? → A: **Required.** Every user-facing string MUST be wrapped via `esc_html__()` / `esc_attr__()` / `_x()` with text domain `acrossai-mcp-manager`. Placeholders use `sprintf( esc_html__('…%1$s…', 'acrossai-mcp-manager'), $value )`. Matches existing codebase, satisfies PHPCS WPCS strict (`WordPress.WP.I18n`), and is the WP.org plugin-directory convention.
- Q: Should the asset enqueue tell WordPress about the RTL CSS variant? → A: **Required.** After enqueueing `acrossai-mcp-frontend`, call `wp_style_add_data( 'acrossai-mcp-frontend', 'rtl', 'replace' )` so WordPress auto-substitutes `frontend-rtl.css` on RTL locales. The RTL build artifact already ships in `build/css/frontend-rtl.css`. Consistent with FR-016 i18n requirement: translated strings on Arabic/Hebrew sites need RTL layout to remain legible.

---

## Context

This phase ships the **browser-mediated approval surface** for the Phase 6 CLI authentication flow. Phase 6 (`specs/006-rest-cli-auth/`) ships the REST endpoints; this phase ships the human-in-the-loop consent page that turns a `pending` auth code into an `approved` one by calling `CliController::approve_auth_code()` after a logged-in user clicks **Approve**.

This is a re-specification of the existing `public/Partials/FrontendAuth.php` module (which was absorbed into Phase 6 as the "Phase 6.0" subset). The re-spec encodes four intentional behaviour changes from the existing implementation:

1. **`QUERY_VAR` renamed** from `acrossai_mcp_frontend_auth` → `acrossai_mcp_auth` (shorter, parallels the namespace pattern).
2. **Authorization broadened** from `manage_options` → ANY logged-in user. The site administrator's role in the consent flow is the WordPress login itself; granular role gating is deferred to a future "scoped CLI access" feature.
3. **CSS extracted** from inline `<style>` to a build-pipeline asset (`build/css/frontend.css`) with a versioned cache-busting hash from `build/css/frontend.asset.php`.
4. **Nonce scope simplified** from per-code (`cli_auth_approve_<code>`) to action-only (`cli_auth_approve`). Per-code binding is preserved by the form's nonce-action choice; the code itself remains part of the verified GET payload.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Logged-in User Lands on the Approval Page and Sees Consent UI (Priority: P1)

A developer running a CLI tool (e.g. an MCP-aware Claude Code shim) initiates the auth flow via `POST /auth/start` and is given an `auth_url` of the form `https://example.com/acrossai-mcp-manager/?action=cli_auth&code=<code>&server=<server_slug>`. They open this URL in the browser where they are already logged in to WordPress as an admin (or any role). They see a minimal, theme-free approval page that names the requesting server and offers a single **Approve** action.

**Why this priority**: Without this rendering, the entire Phase 6 CLI flow has no way to obtain consent. The whole `pending → approved` state transition depends on this page existing and being visible to the logged-in user.

**Independent Test**: After hitting `https://example.com/acrossai-mcp-manager/?action=cli_auth&code=abc123&server=wordpress-default-server` while logged in, the browser displays a standalone HTML page (no theme header/footer) containing the server slug, a brief explanation, and an **Approve** button. The button's `href` includes `action=cli_auth_approve`, `code=abc123`, `server=wordpress-default-server`, and a valid `_wpnonce`.

**Acceptance Scenarios**:

1. **Given** a user logged in with any role + an `auth_url` with `?action=cli_auth&code=<code>&server=<server>`, **When** they visit it, **Then** the response is HTTP 200 with a standalone HTML shell (no `wp_head()`, no theme), the page title is plugin-branded, the server slug is rendered (escaped), and an **Approve** button is present.
2. **Given** the URL is missing the `code` or `server` query parameter, **When** the logged-in user visits it, **Then** the page renders a "Missing Authentication Parameters" message instead of the Approve button.
3. **Given** the page is rendered, **When** the HTTP response is inspected, **Then** the response carries `nocache_headers()` (the page MUST NOT be cached by intermediaries because it embeds a single-use nonce).

---

### User Story 2 — Unauthenticated Visitor Is Redirected to wp-login (Priority: P1)

A user clicks the CLI's `auth_url` in a browser session that has no active WordPress login cookie. They are redirected to the standard WordPress login screen with the original approval URL preserved in `redirect_to`. After successful login, WordPress returns them to the approval page.

**Why this priority**: This is the only path to consent — without login, the approval page MUST NOT render, and the user MUST be funnelled through `wp_login_url()` rather than a custom auth form. Reusing `wp-login.php` keeps the threat surface minimal.

**Independent Test**: With no login cookies present, hit `https://example.com/acrossai-mcp-manager/?action=cli_auth&code=abc123&server=foo`. The response is HTTP 302 with a `Location` header pointing at `wp-login.php?redirect_to=<urlencoded-approval-url>`.

**Acceptance Scenarios**:

1. **Given** no active WordPress session, **When** the user visits any URL under `/acrossai-mcp-manager/`, **Then** the response is HTTP 302 redirecting to `wp_login_url()` with the original URL preserved as the `redirect_to` parameter.
2. **Given** the user completes login on `wp-login.php`, **When** WordPress redirects back, **Then** the user lands on the original approval URL with all query parameters intact.

---

### User Story 3 — User Approves the Request and Returns the CLI to a Working State (Priority: P1)

The user, now seeing the consent UI, clicks **Approve**. The page validates the nonce, calls `CliController::approve_auth_code($code, get_current_user_id())`, and redirects to a `?action=cli_auth_approved` success page. Meanwhile, the CLI (which has been polling `/auth/status`) detects the approval and proceeds to `/auth/exchange`.

**Why this priority**: This is the value-delivery step for the whole feature. Without it, the consent click is meaningless.

**Independent Test**: With a valid pending auth code in the transient layer, click **Approve** on the rendered page. The browser is redirected to `?action=cli_auth_approved` and displays a "You can close this tab" success message. Server-side, the transient for that code now has `status: 'approved'` and a fresh `acrossai_session_<token>` transient exists.

**Acceptance Scenarios**:

1. **Given** a valid pending auth code + a logged-in user + a valid nonce, **When** the user clicks **Approve**, **Then** the request flows through `cli_auth_approve` → `CliController::approve_auth_code()` → HTTP 302 to `?action=cli_auth_approved` (no error, no dead-end).
2. **Given** the success page is rendered (`?action=cli_auth_approved`), **When** the user sees it, **Then** the page shows a "You can close this tab" success message in the same standalone HTML shell.
3. **Given** the auth code is invalid, expired, or already approved, **When** the user clicks **Approve**, **Then** the page renders a user-facing error explaining that the link is no longer valid (no stack trace, no internal IDs).

---

### User Story 4 — Nonce Verification Blocks Forged Approve Clicks (Priority: P1)

An attacker observes a logged-in admin in the browser and tries to forge an approval URL with a guessed or tampered nonce. WordPress rejects the request with the standard 403 nonce-failure response before any controller method is called.

**Why this priority**: Without nonce protection, an attacker who knows (or guesses) a pending auth code could trick a logged-in admin into approving it via a crafted link. CSRF protection is non-negotiable on any state-mutating GET endpoint.

**Independent Test**: Submit `?action=cli_auth_approve&code=abc&server=foo&_wpnonce=invalid` to the page. WordPress's `wp_die()` 403 nonce-failure screen is shown; `CliController::approve_auth_code()` is never reached.

**Acceptance Scenarios**:

1. **Given** a request with `?action=cli_auth_approve` and a missing `_wpnonce` query parameter, **When** processed, **Then** the request short-circuits with HTTP 403 and `CliController::approve_auth_code()` is NOT called.
2. **Given** a request with `?action=cli_auth_approve` and an `_wpnonce` value that fails `wp_verify_nonce(..., 'cli_auth_approve')`, **When** processed, **Then** the request short-circuits with HTTP 403 and `CliController::approve_auth_code()` is NOT called.

---

### User Story 5 — Activation Hook Establishes the Pretty URL Without a Manual Permalinks Flush (Priority: P2)

A site administrator activates the plugin on a fresh WordPress install. Without taking any extra action (no visit to Settings → Permalinks), the URL `https://example.com/acrossai-mcp-manager/` resolves to the consent page rather than a 404.

**Why this priority**: Forcing administrators to manually re-save permalinks after activation is a well-known WP UX papercut. Registering the rewrite rule on `init` AND flushing during activation eliminates it. P2 because the feature would still function for users who do save permalinks, but the friction would degrade installation experience.

**Independent Test**: On a fresh activation, `curl -I https://example.com/acrossai-mcp-manager/` returns HTTP 302 (redirect to login) — NOT HTTP 404.

**Acceptance Scenarios**:

1. **Given** a fresh plugin activation, **When** the activation hook completes, **Then** the rewrite rule for `^acrossai-mcp-manager/?$` is registered AND flushed immediately (no manual permalink-save needed).
2. **Given** a subsequent normal page load (post-activation), **When** `init` fires, **Then** the same rewrite rule is re-registered idempotently so it survives any external rule cache invalidation.

---

### User Story 6 — Frontend CSS Loads Only on the Approval Page (Priority: P2)

The plugin's `build/css/frontend.css` asset enqueues only when the visitor is on the `/acrossai-mcp-manager/` virtual page. Every other page on the site (admin or front-end) does NOT load this asset.

**Why this priority**: Globally enqueuing a CSS file the rest of the site never uses is a measurable performance regression. P2 because the page would still work without this guard — it's a hygiene requirement, not a correctness one.

**Independent Test**: Inspect the HTTP response of `/wp-admin/`, the home page `/`, and any post — none of them contain a `<link>` tag for `acrossai-mcp-frontend`. Inspect `/acrossai-mcp-manager/?action=cli_auth&code=x&server=y` — it does contain the `<link>` tag.

**Acceptance Scenarios**:

1. **Given** a request to any URL where `get_query_var('acrossai_mcp_auth')` is empty, **When** `wp_enqueue_scripts` fires, **Then** the `acrossai-mcp-frontend` handle is NOT enqueued.
2. **Given** a request to the consent page (`get_query_var('acrossai_mcp_auth')` is truthy), **When** `wp_enqueue_scripts` fires, **Then** the `acrossai-mcp-frontend` handle IS enqueued, sourced from `build/css/frontend.css` with the version pinned to the value in `build/css/frontend.asset.php`.

---

### Edge Cases

- **Build assets missing** (e.g. the `npm run build` step was not run before deploy): the `build/css/frontend.asset.php` file is absent. The enqueue MUST fall back gracefully — the page still renders with an unstyled minimal HTML body (the CSS is cosmetic; the consent flow is not blocked by missing CSS). An admin-side notice or `error_log()` warning is acceptable but not required by this spec.
- **`build/css/frontend.css` exists but `frontend.asset.php` is absent**: enqueue with the plugin version constant as fallback. Mark this as a known-degraded state.
- **User logged in but session expired between page load and click**: the **Approve** click flows through nonce verification first. WordPress's nonce check incorporates the logged-in user; a stale nonce returns 403 and the user is asked to re-authenticate.
- **Nonce reuse across approval clicks**: WordPress nonces have a 12–24 hour lifetime by default. The same nonce CAN be reused within that window. The single-use guarantee on the approval flow comes from `CliController::approve_auth_code()` returning `false` on a second call for the same auth code (per Phase 6 FR-008.1) — not from nonce single-use semantics.
- **Auth code captured + replayed by an attacker who is ALSO logged in**: the attacker can render the consent page but they would be approving the code under their own user identity. The CLI receives an Application Password for the attacker's user, not the legitimate target's. This is by design — the consent flow binds approval to whichever user clicks Approve, and the threat boundary is "the user who clicks Approve is who they say they are".
- **The legitimate target visits the consent page TWICE in two tabs and clicks Approve in both**: the first click succeeds (transient → `approved`, session token generated). The second click is rejected by `CliController::approve_auth_code()` returning `false` because the transient status is no longer `pending`. The second tab renders the "no longer valid" error.
- **The user closes the success tab before the CLI has detected approval**: the auth code is still `approved` in the transient layer; the CLI's next poll succeeds. No re-render of the success page is required because the user has done their part.
- **`?action=` is absent or unknown**: the page renders the `cli_auth` default — the consent form (with the "Missing Authentication Parameters" message if `code` or `server` are also missing).
- **Browser caches a previous approval page response**: `nocache_headers()` is emitted BEFORE any output so intermediaries (browser, proxies, CDNs) cannot serve a stale page that contains an already-used nonce.
- **Plugin is deactivated AFTER the rewrite rule was flushed**: WordPress evicts the rule on the next flush. There is no harm: requests to `/acrossai-mcp-manager/` will simply 404 once the plugin no longer responds to `init`. No uninstall-time flush is required by this spec.
- **Multisite**: rewrite rules are per-subsite under WP-native semantics. This phase does NOT add `switch_to_blog()` logic; activation should run per-subsite via WP's standard multisite activation walk if multisite support is later requested.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Constants and class shape

- **FR-001**: The class MUST declare these `const` values at class level (NOT instance properties, NOT inline magic strings):
  ```php
  const PAGE_SLUG = 'acrossai-mcp-manager';
  const QUERY_VAR = 'acrossai_mcp_auth';
  ```
  All references to the page slug and query var inside the class MUST use these constants.

- **FR-002**: The class MUST follow the singleton pattern: `protected static $_instance = null;` + `public static function instance(): self` + `private function __construct() {}`. The constructor MUST contain ZERO `add_action()` / `add_filter()` calls. All hooks MUST be wired by `Includes\Main::define_public_hooks()` via the Loader.

#### Rewrite rule + activation

- **FR-003**: A `register_rewrite_rule()` instance method MUST register exactly one rewrite rule via `add_rewrite_rule()`:
  - Pattern: `'^' . self::PAGE_SLUG . '/?$'`
  - Target: `'index.php?' . self::QUERY_VAR . '=1'`
  - Position: `'top'`

  This method MUST be wired to `init` (priority 10) via the Loader.

- **FR-004**: An `add_query_var( array $vars ): array` instance method MUST append `self::QUERY_VAR` to the public query vars array. Wired to `query_vars` via the Loader.

- **FR-005**: The plugin's activation hook (in `Includes\Activator` or equivalent) MUST call the same rewrite-rule registration AND follow with `flush_rewrite_rules()` so that the pretty URL works immediately on activation without a manual permalinks save.

#### Static helper

- **FR-006**: A `public static function get_base_url(): string` MUST return `home_url( '/' . self::PAGE_SLUG . '/' )`. This helper is consumed externally by `REST\CliController::auth_start()` and the Activator. It MUST NOT be changed to `admin_url(...)` under any refactoring — the URL must resolve on the front-end where the user is logged in, not the admin area.

#### `template_redirect` dispatch

- **FR-007**: A `maybe_render_page()` instance method MUST be wired to `template_redirect` via the Loader. Its control flow:
  1. **Return immediately** if `get_query_var( self::QUERY_VAR )` is empty/falsy. (This is the global guard — execution short-circuits on any non-consent-page request.)
  2. Call `nocache_headers()` IMMEDIATELY, before any output, so the response carries `Cache-Control: no-cache, must-revalidate, max-age=0` and friends.
  3. If `is_user_logged_in()` is `false`, `wp_safe_redirect( wp_login_url( self::get_base_url() ) )` and `exit` (base URL only — no query preservation). *(Planning-time decision per research.md §R3 — sidesteps URL-encoding round-trip. `wp_safe_redirect` is the chosen verb (2026-06-30 architecture-review reconciliation): for a `home_url()`-derived target the `wp_validate_redirect` check always passes, and `wp_safe_redirect` is the defense-in-depth choice that survives any future refactor introducing attacker-influenced URL fragments. UX trade-off: after login the user lands on `/acrossai-mcp-manager/` with no `?action=` and re-opens the CLI's auth_url to retry.)*
  4. **No `current_user_can( 'manage_options' )` check** — any logged-in user MAY proceed to the consent dispatch. (Intentional change from prior implementation; see Context section above.) **Authorized via the Constitution §III "Consent-surface exception"** (added 2026-06-30 — `.specify/memory/constitution.md`). The class docblock at `public/Partials/FrontendAuth.php` cites the exception with this FR identifier. All five conditions of the exception are satisfied by the implementation: (a) `is_user_logged_in()` at maybe_render_page step 3; (b) credential bound to `get_current_user_id()` via `approve_auth_code`; (c) operator-gated via `acrossai_mcp_npm_login_enabled` default-OFF; (d) docblock citation; (e) displayed slug from transient via S9 / SEC-001 fix.
  5. Read `$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) )`.
  6. Read `$enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false )`. If `false`, invoke `render_disabled_notice()` and `exit` (HTTP 503). *(Planning-time decision: operator escape hatch retained; default `false` is the safe stance. See plan.md §Spec ↔ Plan realignment.)*
  7. Dispatch via a `switch` statement keyed on `$action`. After dispatch, `exit` so WordPress does not also render its own template chain.

- **FR-008** *(amended 2026-06-30 per SEC-001 — CWE-451/CWE-441)*: The `?action=` dispatch table is exactly:

  | `?action=` value | Handler | Mutating? | Nonce required? |
  |---|---|---|---|
  | `cli_auth` (or absent / unknown) | `handle_cli_auth( $code )` — renders consent form; sources displayed server slug from `CliController::peek_pending_server( $code )`, NOT from `$_GET['server']` | no | no |
  | `cli_auth_approve` | `handle_approve()` — calls `CliController::approve_auth_code()` | YES | YES |
  | `cli_auth_approved` | `handle_approved()` — renders success page | no | no |

  Unknown `?action=` values MUST fall through to the `cli_auth` default rather than erroring.

  **`?server=` GET param**: preserved on the URL for backward compatibility with existing CLI clients, but the handler IGNORES it. The displayed server name is the authoritative `server_id` stored in the transient by `CliController::auth_start()`. Rendering URL-supplied context would be a confused-deputy attack (S9 in `docs/memory/PROJECT_CONTEXT.md`).

#### Approval handler security

- **FR-009** *(amended 2026-06-30 per SEC-002 — CWE-352)*: The `cli_auth_approve` branch MUST use a PER-CODE nonce action `'cli_auth_approve_' . $code` so a nonce minted for code `A` cannot be replayed against code `B`. Read order: `$code` is read+sanitized BEFORE nonce verification (reading `$_GET` is not "state mutation" — the nonce-before-mutation invariant still holds). Empty `$code` → `wp_die( 'Missing authorization code.', 400 )` BEFORE the nonce check (avoids verifying against a trailing-underscore action string):
  ```php
  $code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
  if ( '' === $code ) {
      wp_die( esc_html__( 'Missing authorization code.', 'acrossai-mcp-manager' ), 400 );
  }
  $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
  if ( ! wp_verify_nonce( $nonce, 'cli_auth_approve_' . $code ) ) {
      wp_die( esc_html__( 'Security check failed.', 'acrossai-mcp-manager' ), 403 );
  }
  ```
  All other `$_GET` reads on this branch MUST also pass through `wp_unslash()` + `sanitize_text_field()` (in that order).

- **FR-010**: On a successful nonce verification, the handler MUST:
  1. Read `$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) )`.
  2. If `$code === ''`, render an error page (HTTP 400) and `exit`.
  3. Call `\AcrossAI_MCP_Manager\Includes\REST\CliController::approve_auth_code( $code, get_current_user_id() )`.
  4. If the call returns `false`, render an error page explaining "this link is no longer valid" (HTTP 400) and `exit`.
  5. On `true`, `wp_safe_redirect( add_query_arg( 'action', 'cli_auth_approved', self::get_base_url() ) )` and `exit`.

#### Page rendering

- **FR-011**: All rendered pages MUST use a standalone HTML shell — no `wp_head()`, no `get_header()`, no `wp_footer()`, no theme template chain. The shell MUST include:
  - `<!DOCTYPE html>`
  - `<html lang="<bloginfo-language>">`
  - A `<title>` with the plugin's branding string
  - `<meta name="viewport" content="width=device-width, initial-scale=1">`
  - The enqueued external CSS (printed via `wp_print_styles( 'acrossai-mcp-frontend' )` inside the `<head>`).
  - A single inline minimal `<style>` block is permitted ONLY for layout safety nets (max-width, body padding) so the page is legible if the external CSS fails to load.

- **FR-012** *(amended 2026-06-30 per SEC-001)*: All user-visible output (server slug, error messages) MUST be escaped at the point of rendering with the most-specific escaping function (`esc_html()` for text, `esc_attr()` for HTML attributes, `esc_url()` for hrefs). String-interpolated translations MUST use `sprintf()` with `esc_html()` wrapping the format result. **The displayed server slug rendered in the consent body MUST come from the transient's bound `server_id` (via `CliController::peek_pending_server()`), NOT from the `?server=` GET parameter.** Escape at output via `esc_html()` as defense-in-depth, never as the sole defense. See S9 in `docs/memory/PROJECT_CONTEXT.md`.

- **FR-016** *(per 2026-06-25 clarification)*: Every user-facing string emitted by this class MUST be wrapped for translation using the `acrossai-mcp-manager` text domain. Use the combined escape-and-translate helpers: `esc_html__( 'text', 'acrossai-mcp-manager' )` for body text, `esc_attr__( 'text', 'acrossai-mcp-manager' )` for HTML attributes, and `_x( 'text', 'context', 'acrossai-mcp-manager' )` only when disambiguating context is required. Placeholders MUST use `sprintf( esc_html__( '…%1$s…', 'acrossai-mcp-manager' ), $value )` so the translator sees a single format string and the variable substitution remains escaped. Bare English literals in `echo` statements are PROHIBITED — PHPCS WPCS strict (`WordPress.WP.I18n.MissingTranslatorsComment`, `WordPress.WP.I18n.MissingArgDomain`) MUST report zero violations on this file.

#### Asset enqueue

- **FR-013**: An `enqueue_assets()` instance method MUST be wired to `wp_enqueue_scripts` via the Loader. The method MUST:
  1. Return immediately if `get_query_var( self::QUERY_VAR )` is empty/falsy — the asset MUST NEVER enqueue globally.
  2. Build the asset URL from the plugin's base path: `<plugin_url>/build/css/frontend.css`.
  3. Read the version from `<plugin_path>/build/css/frontend.asset.php` (the @wordpress/scripts-emitted manifest, shape `[ 'dependencies' => [], 'version' => '<hash>' ]`). If the manifest is missing or unreadable, fall back to the plugin's declared version constant.
  4. Register the handle `acrossai-mcp-frontend` with `wp_enqueue_style()`. Dependencies array: `[]` (no theme stylesheet, no WP core handle).
  5. *(per 2026-06-25 clarification)* Immediately after `wp_enqueue_style()`, call `wp_style_add_data( 'acrossai-mcp-frontend', 'rtl', 'replace' )`. WordPress will then auto-substitute `build/css/frontend-rtl.css` (the same versioning manifest applies) when the locale's `is_rtl()` returns `true`. The RTL artifact MUST exist in `build/css/` — verify in the deploy-time gate (PR review or CI check).

#### Hook registration

- **FR-014**: All `add_action` / `add_filter` calls for this feature MUST be registered through `Includes\Main::define_public_hooks()` via the Loader. The mapping is exactly:

  | Hook | Priority | Method |
  |---|---|---|
  | `init` | 10 | `register_rewrite_rule` |
  | `query_vars` | 10 | `add_query_var` |
  | `template_redirect` | 10 | `maybe_render_page` |
  | `wp_enqueue_scripts` | 10 | `enqueue_assets` |

  The `FrontendAuth` constructor MUST remain free of all `add_action`/`add_filter` calls. PHPCS / static-analysis verification: `grep -rn 'add_action\|add_filter' public/Partials/FrontendAuth.php` returns zero matches (the `add_rewrite_rule` call inside `register_rewrite_rule()` is a separate function, not `add_action`/`add_filter`).

#### Namespace and placement

- **FR-015**: The class MUST live at `public/Partials/FrontendAuth.php` with namespace `AcrossAI_MCP_Manager\Public\Partials`. File name matches class name; PSR-4 autoloading via `automattic/jetpack-autoloader`.

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ |
| WordPress version | 6.9+ |
| Multisite | Single-site only this phase |
| Required Composer packages | None new — relies on existing `automattic/jetpack-autoloader` |
| Required npm packages | `@wordpress/scripts` for the `build/css/frontend.*` build pipeline (assumed already present in `package.json` from prior phases) |
| Required existing classes | `Includes\REST\CliController::approve_auth_code()` (Phase 6 FR-008) ✅ shipped; `Includes\Main::define_public_hooks()` ✅ shipped; `Includes\Loader` ✅ shipped; `Includes\Activator` ✅ shipped |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `public/Partials/FrontendAuth.php` | `AcrossAI_MCP_Manager\Public\Partials` | **Replace** — full re-spec of existing Phase 6.0 module per FR-001…FR-015 |
| `includes/Main.php` | (existing) | **Extend** — `define_public_hooks()` wires `init`, `query_vars`, `template_redirect`, `wp_enqueue_scripts` to `FrontendAuth` instance methods |
| `includes/Activator.php` | (existing) | **Extend** — activation calls the same rewrite-rule registration + `flush_rewrite_rules()` once |
| `build/css/frontend.css` | (build artifact) | **New** — sourced from `src/frontend.css` (or equivalent); emitted by `@wordpress/scripts` |
| `build/css/frontend.asset.php` | (build artifact) | **New** — emitted by `@wordpress/scripts` alongside the CSS |

**Hook Registration Rule**: ALL `add_action`/`add_filter` calls for this feature MUST be wired ONLY through the Loader inside `Main::define_public_hooks()`. Zero hook calls may appear in the `FrontendAuth` constructor or any of its methods.

### Admin UI Requirements

This phase introduces NO admin UI. All rendering is on the front-end virtual page `/acrossai-mcp-manager/`. The DataForm / DataViews requirements from Constitution §IV do not apply because this is not an admin screen.

### REST API Contract

This phase introduces NO new REST routes. It consumes `Includes\REST\CliController::approve_auth_code()` as a static PHP call — not as an HTTP call.

### Database / Storage

This phase introduces NO new database tables and NO new options. State is read/written through:

| Storage | Direction | Owned by |
|---|---|---|
| WP rewrite rules | write (via `add_rewrite_rule` + `flush_rewrite_rules`) | This module + Activator |
| WP query vars | write (via `query_vars` filter) | This module |
| `acrossai_cli_auth_<code>` transient | indirect write (via `CliController::approve_auth_code`) | Phase 6 — this module only triggers the call |
| `acrossai_session_<token>` transient | indirect write (via `CliController::approve_auth_code`) | Phase 6 — this module only triggers the call |

### Security Checklist

*(Derived from Constitution §III — verify all that apply to this feature)*

- [ ] `cli_auth_approve` branch verifies nonce via `wp_verify_nonce( $_GET['_wpnonce'], 'cli_auth_approve' )` BEFORE any state mutation (FR-009)
- [ ] **No** `current_user_can( 'manage_options' )` check — ANY logged-in user may approve. The threat model is: "the user who is currently logged in is consenting, on their own behalf, to issue an Application Password under their own user identity" (FR-007.4 — intentional broadening from prior implementation)
- [ ] Unauthenticated visitors are redirected to `wp_login_url()` with `redirect_to` preserved (FR-007.3) — no custom auth surface, no 403 on the public URL
- [ ] All user input from `$_GET` sanitized at system boundary: `wp_unslash()` THEN `sanitize_text_field()` in that order (FR-009, FR-010)
- [ ] All output escaped at point of rendering with most-specific function (`esc_html()`, `esc_attr()`, `esc_url()`) (FR-012)
- [ ] `nocache_headers()` emitted BEFORE any output so embedded nonces are never served from cache (FR-007.2)
- [ ] Asset enqueue is scoped via `get_query_var( self::QUERY_VAR )` guard — never enqueued globally (FR-013)
- [ ] No `wp_head()` / theme chain — eliminates third-party plugin and theme injection into the consent surface (FR-011)
- [ ] No JS in this phase — eliminates an entire class of XSS/CSRF gadget chains (FR-013)
- [ ] Singleton + private constructor + zero `add_action` in constructor — A1/A2 compliance (FR-002, FR-014)
- [ ] `home_url()` (not `admin_url()`) for `get_base_url()` — the page MUST resolve on the front-end (FR-006)
- [ ] All hooks wired through `Main::define_public_hooks()` via Loader; zero `add_action`/`add_filter` calls inside `public/Partials/FrontendAuth.php` (FR-014)

### Key Entities

- **Virtual page** (`/acrossai-mcp-manager/`): not a real WP post, page, or CPT. A rewrite-rule + query-var pair routes the URL to a `template_redirect` handler that emits a standalone HTML response. The page has no `wp_options` row, no permalink row, and no Site Editor representation.
- **Auth code GET parameter** (`?code=<32-hex>`): non-secret in transit to this page (the user is consenting on behalf of the code holder). The code is the join key between the consent click and `CliController::approve_auth_code()`.
- **Server slug GET parameter** (`?server=<slug>`): the MCP server the CLI is requesting access to. Rendered (escaped) in the consent UI so the user can disambiguate. The slug is also validated downstream by `CliController` against the transient's stored `server_id`.
- **Nonce** (`?_wpnonce=<value>`): WP nonce bound to action `cli_auth_approve` and the current logged-in user. Lifetime: 12–24h per WP default. Single-use semantics for THIS flow are enforced by `approve_auth_code()`'s `status === 'pending'` check, not by nonce expiry.
- **Session token** (transient `acrossai_session_<token>`): written by `CliController::approve_auth_code()`. NOT visible to this module — it is created as a side effect of the static call.

---

## Success Criteria *(mandatory)*

### Definition of Done Gates

All of the following MUST pass before this feature is considered complete:

- [ ] PHPCS validation: zero errors and zero warnings on `public/Partials/FrontendAuth.php` (`vendor/bin/phpcs`)
- [ ] PHPStan level 8: zero errors on `public/Partials/FrontendAuth.php` (`vendor/bin/phpstan`)
- [ ] PHPUnit tests written and passing for all branches of `maybe_render_page()` — covered scenarios: query-var absent, unauthenticated redirect, logged-in user + `cli_auth`, logged-in user + `cli_auth_approve` (good nonce), logged-in user + `cli_auth_approve` (bad nonce), logged-in user + `cli_auth_approved`, logged-in user + unknown action
- [ ] Security checklist above: every applicable item verified
- [ ] All hooks wired in `Main::define_public_hooks()` — zero `add_action`/`add_filter` calls inside `public/Partials/FrontendAuth.php` (verifiable with `grep -rn 'add_action\|add_filter' public/Partials/FrontendAuth.php`)
- [ ] `npm run build` emits `build/css/frontend.css` AND `build/css/frontend.asset.php`; the latter has shape `[ 'dependencies' => array, 'version' => string ]`
- [ ] Manual quickstart: on a fresh WP 6.9 install with the plugin newly activated, visiting `https://example.com/acrossai-mcp-manager/?action=cli_auth&code=test&server=test` (a) prompts login if logged out, (b) renders the consent UI after login regardless of role
- [ ] `npm run validate-packages` passes

### Measurable Outcomes

- **SC-001**: After fresh activation, an HTTP request to `https://example.com/acrossai-mcp-manager/` returns HTTP 302 (redirect to login) — NOT HTTP 404 — without the administrator first visiting Settings → Permalinks. Verified by PHPUnit + a manual install walkthrough.
- **SC-002**: A user with `subscriber` role (the lowest standard WP role) can render the consent page and click Approve, and `CliController::approve_auth_code()` is called with their `user_id`. Verified by PHPUnit using a fixture subscriber user.
- **SC-003**: A request to `?action=cli_auth_approve` with a missing or invalid `_wpnonce` short-circuits with HTTP 403 and `CliController::approve_auth_code()` is NOT called. Verified by PHPUnit.
- **SC-004**: `wp_enqueue_scripts` enqueues `acrossai-mcp-frontend` ONLY when `get_query_var('acrossai_mcp_auth')` is truthy. On `/wp-admin/`, the home page, and any post, the handle is absent. Verified by PHPUnit using `wp_styles()->registered` introspection.
- **SC-005**: The `Cache-Control` header on the consent page response begins with `no-cache, must-revalidate, max-age=0` (or equivalent emitted by `nocache_headers()`). Verified by an HTTP-level assertion.
- **SC-006**: `grep -rn 'add_action\|add_filter' public/Partials/FrontendAuth.php` returns zero matches. Verified in CI as a Definition of Done gate.
- **SC-007**: A `curl` against the consent page returns ZERO bytes of theme markup, ZERO `<script>` tags emitted by `wp_head()`, and ZERO references to `wp-emoji-release.min.js`. Verified by string-grep on the response body.

---

## Assumptions

- **No `manage_options` gate** — any logged-in user may complete the consent flow. The threat model is "the user clicking Approve is consenting on their own behalf to issue an Application Password tied to their own user identity". An attacker who tricks a subscriber-level user into approving still only obtains an Application Password scoped to that subscriber's capabilities — limited blast radius. Granular role gating is deferred to a future scoped-access feature.
- **Kill switch retained** *(planning-time realignment — updated 2026-06-25)* — the `acrossai_mcp_npm_login_enabled` option (default `false`) gates the consent surface. Default-off is the safe stance: operators MUST explicitly enable via `wp option update acrossai_mcp_npm_login_enabled 1`. The check runs AFTER login redirect but BEFORE action dispatch. When disabled, all consent-page requests render a 503 "CLI Login Not Enabled" page via `render_disabled_notice()`. No admin UI for the toggle in this phase (out of scope).
- **Phase 6 CliController is shipped** — `Includes\REST\CliController::approve_auth_code( string $code, int $user_id ): bool` exists and follows the contract in `specs/006-rest-cli-auth/spec.md` FR-008. The static call site is a hard dependency, not an optional integration; `class_exists()` guards are NOT required.
- **`@wordpress/scripts` build pipeline is configured** — `package.json` has `"build"` and `"start"` scripts that emit `build/css/*.css` AND `build/css/*.asset.php`. If absent, this becomes a prerequisite task in the plan phase.
- **Build artifacts ship with releases** — `build/` is committed (or built in CI before the release archive is produced). The runtime MUST NOT trigger a build at install time.
- **Single-site only** — multisite activation walks are out of scope. Each subsite would need its own `flush_rewrite_rules()` if activated under network mode; that work is deferred.
- **Nonce action chosen as `cli_auth_approve` (not `cli_auth_approve_<code>`)** — the per-code binding is preserved by the GET payload (the `code` parameter is part of the dispatch input) and by `approve_auth_code()` rejecting the second call. A single-string action is sufficient for CSRF defense and simpler to audit.
- **`nocache_headers()` is sufficient cache defense** — WP core sets `Cache-Control: no-cache, must-revalidate, max-age=0`, `Expires` in the past, and `Pragma: no-cache`. No additional vendor-specific headers (Surrogate-Control, CDN-Cache-Control) are emitted by this module; reverse proxies must be configured separately if non-standard caches are in play.
- **Inline CSS as a safety net is acceptable** — a minimal `<style>` block (max-width, body padding) MAY remain inline so the page is legible if `build/css/frontend.css` fails to load. The bulk of the styling is in the external file.
- **Existing FrontendAuth (Phase 6.0) is being REPLACED, not extended** — this spec supersedes the prior implementation. The four behaviour changes documented in the Context section are intentional. Once this phase is implemented, the prior code path is deleted (not deprecated, not feature-flagged).

### Resolved 2026-06-30 — Plan-level security review fixes baked into-spec

The 2026-06-30 plan-level security re-review (see `docs/security-reviews/2026-06-30-007-frontend-cli-auth-plan.md`) surfaced 3 findings that have been resolved in-plan via spec amendments and corresponding implementation tasks:

- **SEC-001 (MEDIUM, CWE-451 / CWE-441) — Server slug spoofing**: FR-008 + FR-012 amended; displayed slug now sourced from `CliController::peek_pending_server()` (new helper, see `contracts/cli-controller-peek-pending-server.md`); `?server=` GET param is informational only. Reusable pattern captured as **S9** in `docs/memory/PROJECT_CONTEXT.md`.
- **SEC-002 (LOW, CWE-352) — Cross-code nonce replay**: FR-009 amended to use per-code action `'cli_auth_approve_' . $code`; nonce minted for code `A` cannot be replayed against code `B`.
- **SEC-005 (INFO, CWE-1004) — 503 hardening**: `contracts/page-disabled-notice.md` amended to require `Retry-After: 3600` header and `<meta name="robots" content="noindex,nofollow">` directive.

Remaining residual findings deferred to follow-up: **SEC-003** (broadened authz — LOW, Phase 6 hardening epic), **SEC-004** (GET-as-mutation — INFO, documented as acceptable given `nocache_headers()` + WP nonce). Tracked via tasks.md T041.

---

## Dependencies

| Phase | Dependency | Status |
|---|---|---|
| Phase 1 (core boot flow) | Loader + Activator + Main are wired; constants defined | ✅ shipped |
| Phase 6 (REST CLI auth) | `Includes\REST\CliController::approve_auth_code( string, int ): bool` is the static call target | ✅ shipped (PR #8 merged 2026-06-25) |
| Build pipeline | `@wordpress/scripts` emits `build/css/frontend.css` + `build/css/frontend.asset.php` from a `src/frontend.css` entry point | ⚠ Build entry MAY need to be added to webpack/wp-scripts config — confirm at plan phase |

**Cross-phase note**: this Phase 7 module's `get_base_url()` static helper is the ONE shared call site with Phase 6. Phase 6's `CliController::auth_start()` consumes it to compose the `auth_url` returned to the CLI. If `get_base_url()` were ever changed to `admin_url(...)`, Phase 6's `auth_url` would point at `/wp-admin/`, where this page is not registered — the CLI flow breaks. The "never change to admin_url" constraint in FR-006 is load-bearing across two phases.
