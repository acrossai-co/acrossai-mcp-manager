# Security Review — Plan-Level Constraints

**Reviewed plan**: `specs/007-frontend-cli-auth/plan.md`
**Reviewed spec**: `specs/007-frontend-cli-auth/spec.md`
**Constitution**: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE)
**Date**: 2026-06-26 (initial); amended 2026-06-30 (re-review)
**Reviewer**: governed-plan orchestrator (2026-06-26 baseline) + standalone re-review (2026-06-30)
**Companion document**: `docs/security-reviews/2026-06-30-007-frontend-cli-auth-plan.md` (full comprehensive review)

---

## 2026-06-30 Re-Review Amendment

The 2026-06-26 baseline below remains accurate but did not surface several findings that the 2026-06-30 standalone re-review identified. The plan artifacts have not changed in the intervening period — these findings reflect deeper review, not new design. Treat the table below as the canonical current security-finding list for this plan.

| ID | Severity | Title | Status | Reference |
|---|---|---|---|---|
| SEC-001 | MEDIUM | Server slug rendered from `?server=` GET param, not from transient's authoritative `server_id` — confused-deputy / UI-misrepresentation | OPEN — decision required before implementation begins | `docs/security-reviews/2026-06-30-…-plan.md` §SEC-001 |
| SEC-002 | LOW | Action-only nonce `cli_auth_approve` permits cross-code replay if rendered HTML leaks | OPEN — one-line fix recommended (`cli_auth_approve_<code>`) | §SEC-002 |
| SEC-003 | LOW | Broadened "any logged-in user" auth turns auth code into bearer-for-anyone-logged-in | DOCUMENTED — Phase 6 scope, tracked in spec §Assumptions | §SEC-003 |
| SEC-004 | INFO | GET-as-state-mutation verb (acceptable with `nocache_headers()`) | DOCUMENTED — acceptable as-is | §SEC-004 |
| SEC-005 | INFO | 503 kill-switch response lacks `Retry-After` and `noindex` directives | OPEN — one-line additions in `render_disabled_notice()` | §SEC-005 |
| SEC-006 | INFO | Asset-version fallback `'0.0.0'` silently masks build-pipeline misconfig | ADDRESSED by tasks.md T026 (deploy-time CI gate) | §SEC-006 |
| SEC-007 | INFO | Nonce 12–24h window much wider than 5-minute auth-code TTL (operational invariant) | ADDRESSED by tasks.md T016 case (f) (regression test) | §SEC-007 |

### Memory captured

- **S9** [2026-06-30] — Consent-surface displayed-state MUST be sourced from server-side authoritative store, not URL params. Captured to `docs/memory/PROJECT_CONTEXT.md` + `docs/memory/INDEX.md`. Generalizes SEC-001's fix recommendation to OAuth consent (Phase 5) and any future device-grant surface.

### Updated final verdict (2026-06-30)

⚠ **Plan PASSES with one MEDIUM finding requiring decision before implementation.** The original 2026-06-26 verdict (Constitution §III addressed, broadened-`manage_options` deviation approved, defense-in-depth chain intact) stands. SEC-001 is a confused-deputy / UI-misrepresentation finding the prior reviewer missed; it requires either (a) plan amendment to add `CliController::peek_pending_server()` and source the displayed slug from the transient, or (b) explicit acceptance of MEDIUM finding via `/speckit-security-review-followup`. SEC-002 + SEC-005 are zero-cost fixes that should be folded into implementation tasks.

### Phase 5 audit gap

S9 implies an **immediate audit task for `specs/005-oauth-connectors/`**: if the OAuth consent UI renders any URL-supplied `client_id` / `scope` / `redirect_uri` without sourcing from the OAuth state store, it is a pre-existing S9 violation. This is OUT OF SCOPE for Feature 007's security boundary but worth flagging for the next governance cycle.

---

## Original 2026-06-26 Baseline Review (preserved verbatim)

The original baseline review is preserved below for historical reference. The 2026-06-30 amendment above supersedes the "Final Verdict" section at the end of this baseline.

## Trust Boundaries

| Boundary | Direction | Threat Surface | Defense |
|---|---|---|---|
| Anonymous internet → `/acrossai-mcp-manager/` | inbound | Anyone can request the URL | `template_redirect` short-circuits unauthenticated requests via `wp_login_url()` (FR-007.3) — no anonymous handler is reached |
| Authenticated user → consent page render | inbound | Any role (subscriber+) can render the consent UI | Acceptable: render emits no secrets; server slug is the only attacker-controlled rendered string and is `esc_html()`-escaped (FR-012) |
| Authenticated user → `?action=cli_auth_approve` | inbound, state-mutating | CSRF attack via crafted link in another tab | WP nonce verified BEFORE state mutation (FR-009); `wp_die(403)` on failure |
| `FrontendAuth` → `CliController::approve_auth_code()` | outbound, intra-plugin | Code injection via $code parameter | `$code` is sanitized via `wp_unslash + sanitize_text_field`; downstream `CliController` re-validates against transient state machine |
| Plugin → user browser | outbound | Cache poisoning, embedded-nonce replay via cached page | `nocache_headers()` emitted BEFORE any output (FR-007.2); contracts/page-cli-auth.md asserts the headers |

## Authorization Assumptions

| FR | Authorization Decision | Risk Assessment |
|---|---|---|
| FR-007.4 | NO `manage_options` check — any logged-in user may proceed | **Documented deviation from Constitution §III** ("admin actions MUST enforce capability"). Plan §Complexity Tracking justifies: consent surface is "user-on-own-behalf", not "admin action". App Password is scoped to consenting user's capabilities. Bounded blast radius for subscriber-level approval. **Approved as feature-local deviation.** |
| FR-007.6 | `acrossai_mcp_npm_login_enabled` option default `false` — operator must opt-in | **Stronger than constitutional minimum.** Default-OFF prevents accidental exposure. Operators set via WP-CLI `wp option update`. No admin UI in this phase. |
| FR-009 | Nonce on `cli_auth_approve` branch, action-only string `cli_auth_approve` | **Approved per research.md §R1.** Single-use enforced downstream by `approve_auth_code()`'s `pending`-check; nonce-action need not bind to the code. |

## Data Isolation & Validation

| Surface | Risk | Mitigation |
|---|---|---|
| `$_GET['action']` | Tampered action string drives unexpected dispatch | Sanitized via `wp_unslash + sanitize_text_field`; switch falls through to `cli_auth` default for unknown values (FR-008) |
| `$_GET['code']` | Injected code passed to downstream API | Sanitized; `CliController::approve_auth_code` validates against transient state machine (returns `false` for unknown codes) |
| `$_GET['server']` | Stored XSS via rendered consent UI | Sanitized + `esc_html()` at output (FR-012). Server slug is non-secret (the CLI requesting access controls it; rendering it accurately is the consent UX requirement) |
| `$_GET['_wpnonce']` | Forged nonce | `wp_verify_nonce` constant-time compare; mismatch → `wp_die(403)` |
| `$server` server slug | Open redirect via injected slash sequences | NOT used in any redirect — only rendered (escaped) in HTML and passed to downstream API as opaque string |
| Login redirect URL | Open redirect via `redirect_to` round-trip | **Mitigated per research.md §R3**: redirect uses `get_base_url()` (base only), not the full request URI. Sidesteps URL-encoding round-trip + future `wp_safe_redirect` injection risk. |
| Approval response | Information disclosure on failure | Generic error messages ("link no longer valid"). No stack traces, no transient internals exposed (FR-008 dispatch table; contracts/page-cli-auth-approve.md) |

## Async / Race Conditions

| Race | Risk | Mitigation |
|---|---|---|
| Two browser tabs click Approve simultaneously | Both calls reach `approve_auth_code()`; one succeeds, other gets `false` (transient already approved) | **In scope of Phase 6 FR-008.1**: status check on transient enforces single-use. Phase 7 just receives the `false` and renders the "no longer valid" error. No additional defense needed at this layer. |
| User closes browser between login redirect + return | Original `?action=...` query lost | **Documented UX trade-off** (research.md §R3). User reopens CLI's `auth_url` to retry. No security impact. |
| Asset enqueue version manifest race | Asset.php read while `npm run build` is mid-write | Build pipeline runs OFFLINE before deploy; `require` is atomic. NOT a runtime race. |

## Compliance / Privacy

| Constraint | Status |
|---|---|
| User-identifying data rendered in HTML | Username (`get_current_user_id()` is server-side only; not rendered). Server slug rendered (non-PII; attacker-controlled, escaped) |
| Logging / audit trail | Approval audit via `CliAuthLog\Recorder::record_approved()` — handled by Phase 6; this phase does not write to audit log directly |
| Cookie / session usage | WP login cookies only; no new cookies set |
| GDPR / right-to-erasure | NOT triggered — this module persists no user data |

## High-Risk Issues

None blocking. Two **non-blocking advisories**:

### Advisory 1 — i18n + RTL added at clarify time; verify build artifact ships

The 2 clarifications (FR-016 text-domain mandate; FR-013 step 5 RTL `wp_style_add_data`) require the runtime to find `build/css/frontend-rtl.css`. Verified via `git ls-files build/css` that both files are committed. **No action needed**, but the implementation task list should include an assertion that the RTL file exists post-deploy.

### Advisory 2 — kill switch default `false` requires operator runbook

`acrossai_mcp_npm_login_enabled` defaults to `false`. The plan and quickstart.md document the `wp option update` step, but a fresh-install admin who attempts the CLI flow without enabling the option will see a 503 and may not connect the dots. **Recommendation**: add a one-line plugin-level admin notice on the plugin's main settings page when the option is `false` AND the user has `manage_options`, pointing at the enable command. **Out of scope for this phase**; capture as a future enhancement.

## Final Verdict

✅ **Plan PASSES security review.** All Constitution §III items are addressed; the one documented deviation (broadened `manage_options`) is justified at plan time and bounded by App-Password user-scoping. The kill-switch + nonce + sanitize/escape + nocache_headers chain forms a defense-in-depth posture appropriate for a single-user consent surface.

No security-architecture conflicts surfaced. Proceed to architecture review.
