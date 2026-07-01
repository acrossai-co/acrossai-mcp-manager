# Security Review — Plan-Level Constraints

**Reviewed plan**: `specs/008-asset-build-pipeline/plan.md`
**Reviewed spec**: `specs/008-asset-build-pipeline/spec.md`
**Constitution**: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE)
**Date**: 2026-07-01
**Reviewer**: governed-plan orchestrator

## Scope

Phase 8 is a migration-finalization phase: build-pipeline configuration + enqueue-method guards. It has **no forms, no REST routes, no DB, no transient, no user input at all**. Constitution §III's normal surfaces (sanitize/escape/nonce/capability/hashed-creds/permission_callback) are structurally absent.

The security-relevant work is entirely about **preventing over-enqueueing** — a hygiene concern, not an integrity concern.

## Trust Boundaries

| Boundary | Direction | Threat Surface | Defense |
|---|---|---|---|
| Anonymous internet → front-end pages | inbound | Every visitor loads plugin CSS/JS even when not on a plugin page (current bug in `public/Main.php`) | Guard `enqueue_styles/scripts` on OAuth-consent predicate; no enqueue on non-plugin pages (FR-016, FR-018) |
| Authenticated admin → admin screens | inbound | Every admin page loads plugin admin CSS/JS if not screen-guarded | Existing `admin/Main.php::is_plugin_admin_screen()` guard (Phase 2, unchanged) — verified as compliant |
| Filesystem → `build/*.asset.php` `require` | inbound | Corrupted / missing / partial manifest could emit PHP warnings or return unexpected shape | Defensive triple-check on `require` return value (B11 generalized): `is_array + isset('version','dependencies') + is_string(version)`; silent fallback to plugin version constant on failure |
| Attacker fingerprinting via asset URLs | inbound | Presence of admin asset handles on non-admin URLs could leak "this site runs the plugin" info to unauthenticated visitors | Enqueue guards eliminate the fingerprint surface entirely |

## Authorization Assumptions

Phase 8 introduces zero new capability checks, permission callbacks, or nonces. It preserves:

- **Admin enqueue** — Phase 2's `AdminPageSlugs::plugin_screen_ids()` whitelist is the sole gate; not modified.
- **Public (CLI consent)** — Phase 7's `FrontendAuth::enqueue_assets()` guards on `get_query_var('acrossai_mcp_auth')`; not modified.
- **Public (OAuth consent)** — NEW guard predicate consumed from Phase 5. The predicate itself is not defined by Phase 8; the source of truth remains Phase 5's rendering class.

**No §III capability check applies** — asset enqueueing is not "an admin action" per the Constitution §III / §III Consent-surface exception distinction. It is an idempotent rendering hygiene operation.

## Data Isolation & Validation

| Surface | Risk | Mitigation |
|---|---|---|
| `$_GET`, `$_POST`, `$_COOKIE` | not consumed by Phase 8 code | N/A |
| `get_current_screen()` (admin path) | attacker cannot influence directly | consumed via `AdminPageSlugs::plugin_screen_ids()` whitelist (Phase 2, verified) |
| `get_query_var('acrossai_mcp_auth')` (CLI path) | Phase 7's authoritative predicate | Phase 8 consumes verbatim; no re-interpretation (S9-adjacent invariant — the predicate is the authoritative store) |
| Phase 5 OAuth predicate | Phase 5's authoritative predicate | Phase 8 consumes verbatim per R1 research; no re-interpretation |
| `require build/*.asset.php` | filesystem write required to poison; requires attacker file-write access to the plugin dir | (a) `file_exists()` guard, (b) B11 defensive triple-check on return value, (c) silent fallback to plugin version constant — no PHP warning, no `error_log`, no crash |
| Displayed content in enqueue methods | none rendered — `wp_enqueue_style/script` emit registered HTML tags via WP core | escaping is WP core's responsibility; Phase 8 passes only trusted values (handle string, plugin-URL-derived src, `.asset.php` version + deps) |

## Async / Race Conditions

| Race | Risk | Mitigation |
|---|---|---|
| Two concurrent requests during `npm run build` | build tool overwrites `build/*.asset.php` mid-request; the PHP process reading it sees a partial file | Build runs offline before deploy (not on live request path). `file_exists` + B11 defensive read handles the edge case where a runtime race somehow occurs. Not a runtime-triggered race. |
| Enqueue method called twice for the same request | double `<link>` tag | `wp_enqueue_style` is idempotent per WP core — second call with same handle no-ops. Test in `MainEnqueueTest.php`. |

## Compliance / Privacy

| Constraint | Status |
|---|---|
| User-identifying data rendered / stored | None — Phase 8 renders no user data |
| Cookies / sessions | None new |
| GDPR / right-to-erasure | Not triggered |
| Logging / audit trail | None new |
| Third-party asset URLs | None — all assets are plugin-local via `plugins_url()` |

## High-Risk Issues

**None.** Phase 8 has no meaningful attack surface expansion. The one runtime concern is manifest corruption, addressed by B11 defensive read.

Two **non-blocking hygiene advisories**:

### Advisory 1 — Explicit `wp_style_add_data($handle, 'rtl', 'replace')` for the new OAuth handle

The RTL variant is emitted by `@wordpress/scripts` (rtlcss) but WordPress will not auto-substitute it unless `wp_style_add_data(..., 'rtl', 'replace')` is called immediately after `wp_enqueue_style`. FR-021 mandates this; the plan's contract MUST specify it verbatim. RTL locales (Arabic, Hebrew, etc.) would render mis-aligned CSS otherwise. Non-blocking because FR-021 already covers it, but call out at implementation review.

### Advisory 2 — Deprecation of the historical `acrossai-mcp-manager` handle (current plugin-name-as-handle)

`public/Main.php` currently uses `$this->plugin_name` (`acrossai-mcp-manager`) as the enqueue handle for its unguarded frontend CSS. After Phase 8's guard is in place, this handle will fire on the OAuth consent surface only. Two future-cleanup implications:

1. The handle name `acrossai-mcp-manager` doesn't communicate "OAuth-specific"; consider renaming to `acrossai-mcp-frontend-oauth` in the plan's `data-model.md` for clarity. Not a security concern; naming hygiene.
2. If any consumer (theme child stylesheet, another plugin) has a hardcoded dependency on the `acrossai-mcp-manager` handle firing globally, the guard will break it. Very unlikely for a plugin-internal handle, but if the OAuth consent CSS is expected to leak into the theme's main site style cascade, that's already broken by design.

Both advisories are documentation/naming concerns, not security-blockers.

## Final Verdict

✅ **Plan PASSES security review.**

- Zero new attack surface. Phase 8 REDUCES surface (closes the global asset leak).
- Constitution §III's normal surfaces (sanitize/escape/nonce/capability/permission_callback) are structurally absent — nothing to check.
- Load-bearing defense is the guard chain (FR-016 / FR-018) and the defensive `.asset.php` read (B11 generalized).
- The FR-020 memory-informed reconciliation to option (b) resolves the only cross-phase coordination question without expanding the security perimeter.
- The consent-surface authorization exception (§III amendment, 2026-06-30) is preserved as a Phase 7/5 concern — Phase 8 does not touch it.

**No follow-up needed** beyond the two hygiene advisories above (rename consideration + RTL data verify), which are documentation- or DoD-level concerns.

Proceed to architecture review.
