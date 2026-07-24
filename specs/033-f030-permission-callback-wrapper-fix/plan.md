# Implementation Plan: F030 Permission-Callback Wrapper Fix (Feature 033)

**Feature Branch**: `fix/f030-permission-callback-bypass`
**PR**: [#45](https://github.com/acrossai-co/acrossai-mcp-manager/pull/45)
**Status**: Shipped
**Related brief**: `docs/planings-tasks/033-f030-permission-callback-wrapper-fix.md`
**Related spec**: `specs/033-f030-permission-callback-wrapper-fix/spec.md`

## Summary

Two-line signature change + one-method rewrite in `includes/Abilities/PermissionOverrideProcessor.php` closes a plugin-wide `permission_callback` bypass. Three regression tests (one parameterised over five WP roles) added to `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php`. No source changes outside these two files.

## Technical Context

- **Vulnerable filter**: `PermissionOverrideProcessor::inject_override`, hooked on `wp_register_ability_args` at priority 999999.
- **Two bugs, one file**:
  - **Bug A** — `includes/Abilities/PermissionOverrideProcessor.php:109` (pre-fix). Closure declared `static function () use ( $slug, $original )`. Zero parameters silently discards WP core's args to the wrapped `permission_callback`.
  - **Bug B** — `includes/Abilities/PermissionOverrideProcessor.php:186-191` (pre-fix). `call_original` did `return (bool) call_user_func( $original );`. `(bool) $wp_error_object === true` in PHP → silent deny→allow conversion at the vendor's `if ( true !== $permission )` boundary.
- **Both bugs required for the bypass**. Fixing one alone does not close the hole:
  - Fix only Bug A → `Execute::check_permission` now sees the real `$input`, correctly runs its exposure filter, returns `WP_Error(acrossai_mcp_ability_not_exposed_for_server)`. `call_original` still coerces it to `true`. Bypass persists.
  - Fix only Bug B → wrapper preserves `WP_Error`, but `Execute::check_permission` still sees empty input and returns `WP_Error(missing_ability_name)`. The vendor now correctly denies — but the error message is misleading, and callbacks that rely on their input (any future callback beyond `Execute`) still misbehave.
- **Post-fix shape**:
  - `static function ( ...$callback_args ) use ( $slug, $original )` — variadic accepts any signature the caller uses.
  - `call_original( $original, array $args = array() ): bool|WP_Error` — uses `call_user_func_array`, guards with `is_wp_error()` before scalar cast.
- **Untouched**: the six-layer defensive gating (server_id null → fall through, override off → fall through, not exposed → fall through, all pass → return `true`). No changes to the allow-path.

## Constitution Check

- **§I Coding standards** — all changes follow existing WPCS conventions; no new prefixes/hooks/tables. Pass.
- **§II Tests-first** — regression tests added alongside the fix; each test asserts a specific bug's absence. Pass.
- **§III Security boundary** — this is the boundary being repaired. Fix scope is narrow (fall-through path only) and closes a live, reproduced privilege-escalation vector. Pass.
- **§V Integration resilience** — no vendor-package assumptions changed; fix is entirely inside the plugin's F030 layer. Pass.

## Project Structure

### Documentation (this feature)

- `docs/planings-tasks/033-f030-permission-callback-wrapper-fix.md` — engineering brief.
- `specs/033-f030-permission-callback-wrapper-fix/spec.md` — feature spec.
- `specs/033-f030-permission-callback-wrapper-fix/plan.md` — this file.
- `specs/033-f030-permission-callback-wrapper-fix/tasks.md` — task list matching the shipped commit.

### Source Code (repository root)

- `includes/Abilities/PermissionOverrideProcessor.php` — the fix.
- `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php` — the regression tests.

No changes elsewhere. Existing dependent files (`Execute::check_permission`, `Discover::check_permission`, `GetAbilityInfo::check_permission`, `AbilityHelpers::apply_exposure_filter`) continue to behave identically now that they receive their expected inputs.

## Complexity Tracking

Two logical changes, one file. No new abstractions, no new caches, no new hooks. Minimal blast radius by design.

## Follow-up work (NOT this feature)

- **F034 candidate** — filter-time eligibility gate in `inject_override` so the wrap is only installed for abilities that could ever satisfy layers 1 + 4. Removes the entire wrapper code path — and its bug class — for the vast majority of abilities. Tracked separately on GitHub.
- **Strict allowlist** — separate proposal to remove the `meta.mcp.public` fallback from `ExposureResolver::resolve`. Unrelated to the wrapper fix but discovered during the same investigation.
