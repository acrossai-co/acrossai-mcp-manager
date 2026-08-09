# Implementation Plan: CLI Auth URL Cache-Exclusion Notice

**Branch**: `feat/cli-auth-cache-exclusion-notice` | **Date**: 2026-08-09 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/041-cli-auth-cache-exclusion-notice/spec.md`

## Summary

Append 15 lines to `admin/Partials/Notices.php::register_shared_notices()` that, gated on `get_option( 'acrossai_mcp_npm_login_enabled', false )`, push a warning card into the shared `acrossai_notices` collection describing the CLI auth URL and the cache-exclusion requirement. Reuses the existing `acrossai_notices` filter wiring (already registered in `Main.php`) and the vendor rendering layer in `acrossai-co/main-menu` 0.0.30+ — no new hook registration, no new asset, no new REST endpoint.

## Technical Context

**Language/Version**: PHP 7.4+ (per `AGENTS.md` `php_min_version`).
**Primary Dependencies**: `acrossai-co/main-menu` 0.0.30+ (owns the `acrossai_notices` filter contract + Notices submenu render).
**Storage**: None. Reads existing option `acrossai_mcp_npm_login_enabled`.
**Testing**: PHPUnit suite `notices` (existing) — no new test files strictly required for a conditional-append, but a shipped smoke test asserting presence/absence of the notice on option-toggle is a nice-to-have.
**Target Platform**: WordPress admin, any host.
**Project Type**: WordPress plugin — additive edit to a single Partials file.
**Performance Goals**: N/A — one `get_option()` call per admin page load already fetched by other consumers (autoloaded on WP core boot).
**Constraints**:

- MUST reuse `acrossai_notices` (NOT `admin_notices`) so the vendor Notices submenu + dismissible summary handle rendering + per-user dismissal.
- MUST resolve the URL via `FrontendAuth::get_base_url()` so any future change to the CLI landing route (via `FrontendAuth::PAGE_SLUG`) propagates automatically.
- MUST escape the URL via `esc_url()` before interpolation (the vendor renderer passes `message` through `wp_kses_post`, which does NOT sanitize attribute-context URLs).
- MUST NOT modify the existing inline banner in `SettingsMenu::render_npm_section_description()` — both surfaces intentionally co-exist.
- MUST leave the `Main.php` hook wiring untouched (`add_filter( 'acrossai_notices', $notices, 'register_shared_notices' )` already registered at `includes/Main.php:426`).

**Scale/Scope**: 1 file edit, 15 additional lines. Zero new files. Zero new hooks. Zero new tests required for merge (existing suite covers the register_shared_notices contract's dedup behavior).

## Constitution Check

*Evaluated 2026-08-09 against project constitution.*

| Principle | Applicability | Verdict |
|---|---|---|
| I — Modular Architecture (Notices in `admin/Partials/`, hooks in `Main.php` only) | Yes — edit stays in `admin/Partials/Notices.php`; no new hook wiring | PASS |
| II — Singleton + private `__construct()` | Yes — `Notices::instance()` unchanged | PASS |
| III — Security (nonces, capability, sanitization, escaping) | Yes — `esc_url()` on interpolated URL; no user-input path; capability enforced by vendor render layer | PASS |
| IV — DataForm / DataViews UI contract | N/A — no form / table added | N/A |
| V — Extensibility via filters | Yes — the notice IS pushed through a filter (`acrossai_notices`); no new filter added | PASS |
| VI — WordPress packages first | N/A — no JS/CSS | N/A |

**No constitution deviations. No memory-hub durable-decision entry warranted beyond WORKLOG.** Captured as a single WORKLOG entry (2026-08-09) documenting the "gate-tied cache-exclusion warnings belong in `acrossai_notices`" pattern.

## Implementation Notes

The edit slots between the two existing conditional appends inside `register_shared_notices()`:

1. `\WP\MCP\Plugin` class-existence check → error notice.
2. `\WPBoilerplate\AccessControl\AccessControlManager` class-existence check → warning notice.
3. **[NEW]** `acrossai_mcp_npm_login_enabled` option check → warning notice. ← this feature

Order does not matter — the vendor render layer sorts by `type` (error → warning → info → success) and dedups by `id` (first-wins).

## Post-Merge Verification

Recipe (already ran locally):
1. Enable **Settings → MCP → Allow CLI connections via npm / npx**.
2. Navigate to **AcrossAI → Notices** — confirm the "Exclude CLI auth URL from page cache" card appears with the site's `https://<host>/acrossai-mcp-manager/` URL wrapped in a `<code>` tag.
3. Disable the setting → confirm the card disappears on next admin page load.
4. Re-enable → dismiss the card → confirm dismissal persists per-user.

## References

- **PR**: https://github.com/acrossai-co/acrossai-mcp-manager/pull/70
- **Companion pattern** (site-wide `WP_CACHE` version): `acrossai-ai-connectors/admin/Partials/Notices.php` id `acrossai_ai_connectors_page_cache_exclusions_required`.
- **Filter contract docs**: `admin/Partials/Notices.php:96-111` (inline documentation of the `acrossai_notices` shape).
- **URL resolver invariant**: `tests/phpunit/FrontendAuth/GetBaseUrlTest.php`.
- **Durable memory**: `docs/memory/WORKLOG.md` 2026-08-09 entry, `docs/memory/INDEX.md` Worklog Entries row (2026-08-09 / Notices).
