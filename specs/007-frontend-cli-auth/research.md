# Phase 0 Research — Frontend CLI Authentication Page

**Date**: 2026-06-25
**Status**: Complete — all design questions resolved

This phase has **no `NEEDS CLARIFICATION` markers in the spec**. The research below resolves three nontrivial planning-time design questions surfaced by the spec→plan realignment (see plan.md §Spec ↔ Plan realignment).

---

## R1. Nonce scope: action-only `cli_auth_approve` vs per-code `cli_auth_approve_<code>`

### Decision

Use the action-only string `'cli_auth_approve'` for `wp_create_nonce()` AND `wp_verify_nonce()`. The auth code itself is part of the verified GET payload and is checked downstream by `CliController::approve_auth_code()` against the transient's `status === 'pending'` invariant.

### Rationale

- A WordPress nonce binds to (action-string, user-id, current-time-window). Adding the auth code to the action string would bind the nonce to (action+code, user-id, time-window) — strictly narrower, but the additional binding only matters if an attacker can craft a `_wpnonce` value for a DIFFERENT auth code AND then use it on the target code. Both branches go through `approve_auth_code()`, which rejects any code whose transient is not `pending` — so the per-code binding does not add CSRF defense; it adds a redundancy.
- The action-only form is simpler to audit and matches WordPress core conventions (e.g. `wp_create_nonce( 'delete-comment' )` is action-only, not per-comment-id).
- Single-use semantics in this flow are enforced by `approve_auth_code()` returning `false` on a second call (the transient transitions from `pending → approved`, so the second call sees `status !== 'pending'` and short-circuits). The nonce's 12–24h window is wider than the auth code's 5-minute TTL — the per-code action would not change that.

### Alternatives considered

- **Per-code action (`cli_auth_approve_<code>`)** — what the existing implementation uses. Rejected because the additional binding adds no real CSRF defense given the downstream pending-check, and complicates audit-reading without a security benefit.
- **Add a CSRF token as a hidden form field** — rejected because the **Approve** UI is a single `<a>` link with the approval URL pre-baked; no form is rendered. Switching to a POST form for one button is YAGNI.

### Citation

- WordPress docs: `wp_create_nonce()` description — "nonces protect URLs and forms from misuse". The action is meant to scope the nonce to one operation, not one operation-instance.
- Spec FR-009 prescribes `wp_verify_nonce( $_GET['_wpnonce'], 'cli_auth_approve' )` — this research confirms it.

---

## R2. Asset enqueue fallback when `build/css/frontend.asset.php` is unreadable

### Decision

1. Build the asset.php path as `dirname( ACROSSAI_MCP_MANAGER_PLUGIN_FILE ) . '/build/css/frontend.asset.php'`.
2. Wrap the `require` in `is_readable()`. If readable, capture the `version` key from the returned array.
3. If unreadable OR the returned value is not an array OR the `version` key is missing, fall back to the plugin's runtime version (read once from the plugin file headers via `get_file_data()` or a single `acrossai_mcp_get_plugin_version()` utility — depending on what exists in the codebase; if neither, use the string `'0.0.0'` as a deterministic fallback that signals "build manifest missing").
4. Do NOT emit `error_log()` on the unreadable case. The page still renders; the only consequence is that the CSS may be browser-cached past the intended bust point. A silent fallback is preferable to log noise on every page load.

### Rationale

- `@wordpress/scripts` emits the asset.php with shape `['dependencies' => array, 'version' => string]`. The current build (verified via `git show HEAD:build/css/frontend.asset.php`) is `['dependencies' => array(), 'version' => 'fbb750fd312778403036']`.
- The fallback only triggers in degraded environments (deploys where `npm run build` was skipped, or stripped distributions). The page is still legible because the spec mandates an inline minimal `<style>` safety net.
- `error_log()` on every page load to the consent surface would spam server logs without operator action. The proper signal is "missing build artifact" → flagged at deploy time by `npm run build` not having been executed, not by runtime logging.

### Alternatives considered

- **Skip enqueue entirely if asset.php missing** — rejected because `frontend.css` still exists in `build/css/` and serving it unversioned (or with the fallback plugin version) is still better than serving nothing. The page is more legible WITH the external CSS even with a non-cache-busting version string.
- **Emit `error_log()` warning** — rejected per the rationale above; if operators want to detect deploy-time misconfiguration, the right signal is a CI check that asserts `build/css/frontend.asset.php` exists before packaging the release ZIP.
- **Filemtime-based version fallback** — rejected because `filemtime()` is cache-defeating: every deploy rotates the version even when the CSS is byte-identical. The hash from the asset manifest is the right signal.

### Citation

- `@wordpress/scripts` docs: `dependency-extraction-webpack-plugin` documents the asset.php emission contract.
- The frontend.asset.php manifest currently committed at `build/css/frontend.asset.php` confirms the array shape.

---

## R3. Login redirect URL preservation

### Decision

`wp_redirect( wp_login_url( self::get_base_url() ) ); exit;` — pass ONLY the base URL (`https://example.com/acrossai-mcp-manager/`), not the full request URI with `action`, `code`, `server`, and `_wpnonce`.

### Rationale

- The planning input explicitly prescribes this form.
- The CLI's auth flow is resilient to a one-step re-trigger: after login the user lands on `/acrossai-mcp-manager/` (no `?action=`), sees the "Missing Authentication Parameters" message, returns to the terminal, copies the CLI's `auth_url` again, and re-opens it. The CLI is still polling — no work is lost.
- `wp_validate_redirect()` (called by `wp_safe_redirect()`, NOT `wp_redirect()`, so this is informational here) requires the host to be on the allowed-hosts list. Since `self::get_base_url()` derives from `home_url()`, it's always on the allowed list. Passing the full request URI with attacker-influenced `?code=` and `?server=` values is not a redirect risk for `wp_redirect()` but COULD become one if a future refactor switches to `wp_safe_redirect()` and an attacker injects a `code=` value that, after URL-decoding, contains a `//` sequence interpreted as a scheme-relative redirect. The base-URL form sidesteps that vector permanently.
- Preserving the full URI would require URL-encoding the `_wpnonce` value, and any encoding mismatch on the round-trip could fail `wp_verify_nonce()` after login — a more brittle path than just having the user re-open the auth URL.

### Alternatives considered

- **Preserve full request URI** (what the spec FR-007.3 originally prescribed) — rejected per the rationale above (brittle round-trip + injection edge cases).
- **Preserve only `action`+`code`+`server`, drop `_wpnonce`** — rejected because the `cli_auth_approve` branch needs a fresh nonce anyway (the rendered `cli_auth` page mints the nonce inline on the Approve button), so dropping it doesn't help. And the round-trip is still a brittle URL-encoding dance for the two non-secret params.

### Citation

- WordPress core `wp_login_url( $redirect_to )` source — the redirect_to is round-tripped via the `redirect_to` GET parameter, which goes through `urldecode()` on retrieval. Attacker-supplied opaque tokens with `+`, `&`, `=` characters can break the round-trip.
- The spec will be updated at implementation time to align FR-007.3 with this decision (track via the spec realignment note in plan.md).

### R3 amendment 2026-07-15 — preserve `?action=cli_auth&code=X` with hex-format validation

**Revised decision**: preserve `?action=cli_auth&code=<32-char-hex>` in the redirect target passed to `wp_login_url()`. Fall through to the base-URL-only form when either the action isn't `cli_auth` OR the code doesn't match `/^[a-f0-9]{32}$/`.

**Trigger**: operator feedback that the R3 UX ("return to terminal, copy auth URL again, re-open it") is a friction point. The CLI's polling window and the user's cognitive load both suffer for what turned out to be an easily-mitigable safety concern.

**Mitigations for the original R3 concerns**:

1. **`_wpnonce` round-trip brittleness** — NOT APPLICABLE. The URL preserved through login has NO `_wpnonce`. The initial `?action=cli_auth` URL from the CLI never contained `_wpnonce`; that nonce is generated inline server-side by `handle_cli_auth` after login on the return trip.
2. **`//` scheme-relative injection via `code`** — MITIGATED by the `/^[a-f0-9]{32}$/` regex check applied BEFORE the value is passed to `add_query_arg`. Hex characters cannot contain `/`. CLI codes are always 32-char hex (MD5-style hashes generated server-side per `CliController::handle_auth_start`); any non-conforming value indicates either tampering or an unrelated URL and correctly falls back to base-URL-only.
3. **Attacker-influenced `server` in URL** — NOT PRESERVED. `server` is fetched from the transient by `peek_pending_server( $code )` per SEC-001; keeping it out of the redirect URL is strictly safer AND simpler than R3 v1's blanket exclusion.

**Load-bearing invariant**: the `[a-f0-9]{32}` regex is the sole line of defence against R3's original `//` injection concern. See `security-constraints.md` for the constraint statement. If a future refactor loosens the pattern (e.g., allows `-` for UUID-style codes), the security-constraints entry MUST be updated first with an equivalent injection-safety argument.

**What the user sees now** (post-amendment): after login, browser lands directly on the Authorize card. Zero extra terminal round-trips. When the URL is malformed or the code is unrecognized, the branded "Missing Authentication Parameters" card still guides the user back to their terminal.

**Cross-references**:
- Implementation: `public/Partials/FrontendAuth.php::maybe_render_page` (the `! is_user_logged_in()` branch).
- Constraint: `security-constraints.md §"Hex-format load-bearing invariant"`.
- Test guards: `tests/phpunit/FrontendAuth/MaybeRenderPageTest.php` (3 new cases pinning the regex-based branch).

---

## Open items (none)

All Phase 0 questions resolved. Phase 1 can proceed.

---

## Summary table

| Question | Decision | Rationale |
|---|---|---|
| Nonce scope | action-only `'cli_auth_approve'` | Downstream `pending`-check enforces single-use; action-only is simpler to audit |
| Asset version fallback | plugin runtime version (or `'0.0.0'`); no `error_log()` | Page still legible; deploy-time check is the right signal |
| Login redirect URL | base URL only via `wp_redirect( wp_login_url( get_base_url() ) )` | Sidesteps URL-encoding round-trip + future `wp_safe_redirect()` injection risk |
