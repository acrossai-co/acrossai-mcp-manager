# Phase 0 Research — Asset Build Pipeline

**Date**: 2026-07-01
**Status**: Complete — both design questions resolved

Two nontrivial planning-time questions arose from the plan's Phase 0 outline. Both are resolved here so Phase 5 (`public/Main.php` refactor) has a stable consumer contract.

---

## R1 — OAuth consent-page predicate

### Decision

Publish a public static predicate `\AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors::is_authorize_page(): bool` that returns `true` when the current request is on the OAuth consent surface. Implementation:

```php
public static function is_authorize_page(): bool {
    return 'authorize' === (string) get_query_var( 'acrossai_mcp_oauth' );
}
```

`public/Main.php` consumes this predicate via `use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;` — no direct query var reads on the consumer side.

### Rationale

- `ClaudeConnectors::serve_discovery_or_authorize()` (line 82) reads `$mode = (string) get_query_var('acrossai_mcp_oauth');` and dispatches to `render_authorize_page()` when `$mode === 'authorize'`. The consent form and its OAuth-styled HTML shell are emitted from that branch.
- **Option (a) — duplicate the query var read in `public/Main.php`**: creates two magic-string bindings to `'authorize'` / `'acrossai_mcp_oauth'`. If Phase 5 ever renames the query var (unlikely but possible), the consumer silently breaks.
- **Option (b) — publish the predicate as a public static (chosen)**: single source of truth. Zero behavior change to Phase 5. Matches the **A11** pure-stateless-helper family exemption (no instance state, no hook registration, no side effects). Analogous to Phase 6's `CliController::peek_pending_server()` (added 2026-06-30 for Phase 7's SEC-001 fix).
- **Option (c) — URL pattern match on `$_SERVER['REQUEST_URI']`**: fragile; couples the consumer to Phase 5's routing decisions.

### Applicability across GET / POST

`serve_discovery_or_authorize()` also dispatches to `handle_consent_submit()` on POST when `$mode === 'authorize'` — that path returns via redirect (no HTML render). Because CSS enqueue is only meaningful when HTML is rendered, we could narrow the predicate to `GET` only:

```php
return 'authorize' === (string) get_query_var( 'acrossai_mcp_oauth' )
    && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
```

**Decision — do NOT narrow.** The POST path never reaches `wp_head()` (redirect + exit); `wp_enqueue_scripts` never fires; the predicate returning `true` on POST is a harmless no-op. Narrowing adds complexity without value.

### Citation

- `includes/OAuth/ClaudeConnectors.php` line 61 (rewrite rule target: `index.php?acrossai_mcp_oauth=authorize`)
- `includes/OAuth/ClaudeConnectors.php` line 82–100 (dispatch switch on `$mode`)
- Phase 6 `CliController::peek_pending_server()` (line 528 of `includes/REST/CliController.php`) as the precedent for A11-compliant pure static predicates in this codebase

---

## R2 — Build-artifact commit policy

### Decision

**YES** — commit `build/css/frontend-oauth.{css,asset.php,-rtl.css}` to the repo after successful `npm run build`. Matches existing convention.

### Rationale

- `git ls-files build/` currently returns **12 tracked files**: `backend.{css,asset.php,-rtl.css}`, `frontend.{css,asset.php,-rtl.css}`, `backend.{js,asset.php}`, `frontend.{js,asset.php}`, `media/{bookshelf,purple-sunset}.webp`.
- Every prior phase (2 / 5 / 6 / 7) committed its build outputs. Phase 8 follows suit.
- Deploy-time trust of committed artifacts is bounded by the SEC-008-001 CI gate (tasks.md T028): `npm ci && npm run build && git diff --exit-code build/`. The gate transforms "committed build/ trust" into "committed build/ is CI-verified against a clean rebuild".

### Alternative rejected

Adding `build/` to `.gitignore` would force every deployer to run `npm ci && npm run build` before packaging the plugin distribution. WordPress plugin authors distribute ready-to-run ZIPs; end-users don't run npm. Diverging from this convention would break the distribution pipeline.

### Citation

- `git ls-files build/` verified 2026-07-01 (12 files tracked, matches all 4 prior phases' commit pattern)

---

## Open items

None. Phase 1 (design docs + contracts) proceeds with stable answers to R1 and R2.

## Summary table

| Question | Decision | Rationale |
|---|---|---|
| R1 — OAuth consent-page predicate | Publish `ClaudeConnectors::is_authorize_page(): bool` as public static; consumer calls via `use` import | Single source of truth; A11 exemption applies; matches Phase 6 `peek_pending_server` precedent |
| R2 — Build-artifact commit policy | YES — commit `build/css/frontend-oauth.*` after successful build | Matches Phases 2/5/6/7 convention; SEC-008-001 CI gate enforces integrity |
