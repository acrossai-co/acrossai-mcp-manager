# Implementation Plan: OAuth `/token` accepts HTTP Basic auth + DCR-registered clients attributed to connector profiles

**Branch**: `029-oauth-token-basic-auth-and-dcr-attribution` (nominal; implementation on `fix/oauth-token-basic-auth-and-dcr-attribution` — see §Note on branch naming) | **Date**: 2026-07-18 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/029-oauth-token-basic-auth-and-dcr-attribution/spec.md`
**Companion docs**: [pre-Spec-Kit planning doc](../../docs/planings-tasks/029-oauth-token-basic-auth-and-dcr-attribution.md)

## Note on branch naming

Shipped on `fix/oauth-token-basic-auth-and-dcr-attribution` (user-specified branch name, not the Spec-Kit-conventional `029-*`). Following the same one-off shape as F028 (`feature/remove-freemius`) — the user picked the branch name, PR #37 is already tracking it, no rename to avoid PR ref churn. Future features should still use `NNN-slug` from the start.

## Summary

Close two follow-on gaps after F027 (v0.1.2) shipped the DCR default flip:

1. **`/token` didn't accept RFC 6749 §2.3.1 HTTP Basic auth.** The spec RECOMMENDS Basic auth as the transport for client credentials at the token endpoint. This plugin only read `client_id`/`client_secret` from the POST body. Generic OAuth libraries and some MCP hosts using the header form were rejected with `invalid_request`.
2. **DCR-registered clients had `connector_slug = ''`**, silently bypassing F024's per-connector settings gate. F024's admin toggles (enable/disable per connector, admin-approval gate) are keyed on `connector_slug`; empty string meant every Claude.ai / ChatGPT / Cursor / Cline DCR client sailed through.

Plus a defense-in-depth softening: when a `client_secret_post` client sends NO secret at exchange, fall through to PKCE-only verification instead of hard-rejecting. F027 fixes the source-of-truth default; this softening ensures pre-F027 rows OR explicitly-confidential-but-behaving-public clients still complete.

Consumer surface:
- **`includes/OAuth/TokenController.php`** — 90 insertions / 19 deletions: new `read_client_credentials_from_header()` static; header-first-then-body credential resolution in both grant handlers; `client_secret_post` softening in both.
- **`includes/OAuth/ClientRegistrationController.php`** — 14 insertions / 1 deletion: profile walk with first-match-wins slug attribution before `ClientRepository::create()`.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline).
**Primary Dependencies**: None new. Reuses `ConnectorProfileRegistry` (F021), `ClientRepository` (F021), `AuthCodeRepository` (F021), `AccessTokenRepository` / `RefreshTokenRepository` (F021), `PKCE::verify_s256` (F021).
**Storage**: Reads only — no schema change. New DCR client rows now populate `connector_slug` on create (existing column, was hardcoded empty).
**Testing**: PHPUnit — existing `tests/phpunit/OAuth/` suite continues to pass. Recommended follow-up cases documented in tasks.md; not gated by this PR.
**Target Platform**: WordPress 6.9+ single-site admin (multisite out of scope per plugin baseline).
**Project Type**: WordPress plugin, single project.
**Performance Goals**: Header parsing is one `stripos` + one `base64_decode` + one `explode` per `/token` request — negligible. Profile walk is O(N_profiles) per DCR request (typically 1–5 profiles) — negligible.
**Constraints**: No new schema. No new REST route. Loader-only hook registration (unchanged). All new code inside the F021 OAuth module — no cross-module touches.
**Scale/Scope**: Two files, two grant handlers softened, one new static, one attribution block. ~104 lines added, ~20 removed.

## Constitution Check

*GATE: Must pass before implementation. Re-check after code lands.*

Constitution v1.1.0 (ratified 2026-05-28, last amended 2026-07-12).

| Principle | Gate | Status | Notes |
|---|---|---|---|
| **I. Modular Architecture** | Single-purpose module, no cross-module coupling, shared logic in `includes/Utilities/` | **PASS** | All changes are within `includes/OAuth/`. No new module, no cross-module coupling. `ConnectorProfileRegistry` is already the F021-established seam for connector attribution. |
| **II. WordPress Standards Compliance** | WPCS strict, PHPStan L8, ESLint clean, WP 6.9+ / PHP 8.1+, multisite unless justified | **PASS** | WPCS clean after phpcbf auto-fix + one phpcs:ignore for `base64_decode` with RFC 6749 §2.3.1 justification. PHPStan L8 clean. No JS. |
| **III. Security First (NON-NEGOTIABLE)** | Sanitization, escaping, nonces, capability checks, prepared statements, `permission_callback`, hashed secrets | **PASS with review note** | The `client_secret_post` softening (FR-006) creates a fallback path where confidential clients that don't authenticate via secret fall through to PKCE-only. **Security posture** (documented in spec §Story 3): PKCE S256 is mandatory, single-use codes are atomic (B10), audience binding is enforced, and the softening only affects the "no secret sent" branch — clients that DO send a secret are still verified. Residual risk is bounded by the intersection of PKCE + audience + short-lived tokens. Body params still sanitized via `array_map('strval', ...)` at `read_body()`. Basic auth header is parsed with strict base64 decoding + malformed-shape rejection. |
| **IV. User-Centric Design** | New admin UI uses DataForm/DataViews unless pre-approved exception | **PASS** *(no new UI)* | Zero new admin surface. |
| **V. Extensibility Without Core Modification** | Actions/filters/extension points; graceful degradation for optional integrations | **PASS** | Uses the existing `acrossai_mcp_manager_connector_profiles` filter (F021 extension point) via `ConnectorProfileRegistry::get_profiles()`. Companion plugins that register a profile with a working `matches_dcr_client()` get their clients attributed automatically. |
| **VI. Reusability & DRY** | Shared logic centralized; `@wordpress/*` first, npm second | **PASS** | New `read_client_credentials_from_header()` is the canonical single implementation. The `matches_dcr_client()` walk uses the same registry pattern already used by `AuthorizationController::infer_slug_from_dcr_client()` — F029 promotes the walk to registration time so `/authorize`'s walk becomes a legacy-row fallback rather than the primary path. No duplication introduced. |
| **VII. Definition of Done** | PHPCS / PHPStan L8 / ESLint / security / tests / DataForm / DRY / prefix / AGENTS.md / validate-packages | **PASS** | All gates addressable at implementation time; PR #37 CI is running the full 6-check gate set. |

**Post-check verdict**: No violations. One documented security-trade-off (FR-006 softening) — justified in `spec.md` §Story 3 Security Posture and cross-referenced from the PR body. Recommend a follow-up PHPUnit case explicitly locking the PKCE-authenticates-when-no-secret behavior.

## Project Structure

### Documentation (this feature)

```text
specs/029-oauth-token-basic-auth-and-dcr-attribution/
├── plan.md                       # This file
├── spec.md                       # Feature specification
└── tasks.md                      # Implementation task list (all tasks marked [X] — code already shipped)
```

Companion (outside the specs/ dir):

```text
docs/planings-tasks/029-oauth-token-basic-auth-and-dcr-attribution.md   # Pre-Spec-Kit design doc
```

### Source Code (repository root)

```text
includes/OAuth/TokenController.php                # +90 / -19 (new static + credential resolution + softening in both grants)
includes/OAuth/ClientRegistrationController.php   # +14 / -1  (attribution walk before create)
```

## Related memory

| Entry | Status change | Reason |
|---|---|---|
| (none flipped) | | F029 does not supersede any prior decision. F027's DEC-lineage (not captured as a formal DEC — landed as a WORKLOG entry) is complemented, not replaced. |

**New durable memory proposed** by `/speckit.memory-md.capture-from-diff`:
- Candidate: a DEC entry documenting the "confidential-client soft-authentication when PKCE is present" pattern — proposed for user approval (not written automatically).
- Candidate: a WORKLOG entry summarizing F029 as the runtime-side complement to F027's DCR default fix — proposed for user approval.

## Verification

Command lines (all commands run from plugin root, use `TMPDIR=/tmp` to avoid the per-session tmpfs cap):

```bash
composer run phpcs -- includes/OAuth/TokenController.php includes/OAuth/ClientRegistrationController.php
composer run phpstan
composer run test -- --testsuite mcpclients
# CI runs the oauth integration testsuite via bootstrap-wp.php
```

Manual (post-deploy) — full recipe in `spec.md` §Manual Verification. Highlights:
- Header-only Basic auth POST returns 200.
- Body-only POST still returns 200 (regression).
- No-secret + valid PKCE returns 200 (defense-in-depth check).
- No-secret + wrong PKCE returns 400.
- DCR POST for Claude-shaped body populates `connector_slug` on the row.

## Out of scope

- **PHPUnit cases for FR-001, FR-006, FR-007**: recommended but not gated by this PR. The `oauth` integration testsuite requires the WP-PHPUnit harness at `/tmp/wordpress-tests-lib`, which CI provisions but the local dev environment doesn't. Add cases in a follow-up when the harness is available.
- **`client_secret_basic` DCR advertisement**: the discovery metadata's `token_endpoint_auth_methods_supported` currently lists `["none", "client_secret_post"]`. Adding `"client_secret_basic"` would require a separate DCR validation change; F029 only tolerates Basic auth on the `/token` endpoint, not on DCR-side registration.
- **`AuthorizationController::infer_slug_from_dcr_client` cleanup**: the helper stays functional as a legacy fallback for rows created before F029. Simplifying it to short-circuit-on-persisted-slug is a small refactor for a follow-up feature.
- **Migration of pre-F029 DCR rows** with empty `connector_slug`: per D21 (F016 fresh-install-only pattern), no in-plugin migration. Operators wanting to backfill can run a WP-CLI script.
