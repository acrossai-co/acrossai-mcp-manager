# Tasks: F030 Permission-Callback Wrapper Fix (Feature 033)

**Feature Branch**: `fix/f030-permission-callback-bypass`
**PR**: [#45](https://github.com/acrossai-co/acrossai-mcp-manager/pull/45)
**Status**: All tasks completed
**Related brief**: `docs/planings-tasks/033-f030-permission-callback-wrapper-fix.md`
**Related spec**: `specs/033-f030-permission-callback-wrapper-fix/spec.md`
**Related plan**: `specs/033-f030-permission-callback-wrapper-fix/plan.md`

## Format: `[ID] [Story] Description`

Tasks match the single commit that shipped in PR #45. No `[P?]` parallelism markers — everything landed in one small commit against `main`.

## Path Conventions

- Source: `includes/Abilities/PermissionOverrideProcessor.php`
- Tests: `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php`

## Phase 1: Reproduction & Root Cause (Investigation, pre-commit)

- [x] **T001 [Story 1]** Reproduce the bypass live against `mcp-adapter-default-server` with `acrossai-abilities-manager/site-title-get` (`is_exposed=0`) as a subscriber-level user. Confirmed: 200 response with site title returned.
- [x] **T002 [Story 1]** Add temporary `error_log('[MCP-DEBUG] …')` instrumentation to `CallbackReplacer::replace_callbacks`, `Execute::check_permission`, `Execute::execute`, `AbilityHelpers::apply_exposure_filter`, `ExposureResolver::resolve`. Reproduce and read `wp-content/debug.log` to identify the bypass path.
- [x] **T003 [Story 1]** Trace the log evidence to `PermissionOverrideProcessor::inject_override` closure signature (Bug A) and `call_original` bool-cast of `WP_Error` (Bug B). Discard all debug instrumentation.

## Phase 2: Tests (tests-first per §II)

- [x] **T010 [Story 2]** Write `test_closure_forwards_args_to_original_callback` in `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php`. Captures original's input via `use ( &$captured )` closure. Fails on pre-fix code (captured stays `null`); passes post-fix.
- [x] **T011 [Story 3]** Write `test_wp_error_from_original_is_preserved_not_coerced_to_true`. Fails on pre-fix (`assertInstanceOf( WP_Error::class, $result )` fails because result is `bool true`); passes post-fix.
- [x] **T012 [Story 1]** Write `test_wrapper_preserves_role_gated_denials` with a `@dataProvider` yielding `subscriber / contributor / author / editor / administrator`. Original callback mirrors `Execute::check_permission`'s shape: returns `WP_Error(missing_ability_name)` on empty input, else `current_user_can( 'manage_options' )`. On pre-fix code every role is granted (WP_Error → true); post-fix only administrator receives `true`.
- [x] **T013 [Story 1]** Add matching `provide_wp_roles(): array` data provider.
- [x] **T014** Import `use WP_Error;` at the test file's top-of-file namespace block.

## Phase 3: Implementation

- [x] **T020 [Story 2]** In `PermissionOverrideProcessor::inject_override`, change the closure signature from `static function () use ( $slug, $original )` to `static function ( ...$callback_args ) use ( $slug, $original )`.
- [x] **T021 [Story 2]** In the same closure body, change every `self::call_original( $original )` call site to `self::call_original( $original, $callback_args )`. Three occurrences (null-server, override-off, not-exposed fall-through paths).
- [x] **T022 [Story 3]** Rewrite `call_original` signature to `call_original( $original, array $args = array() ): bool|WP_Error`. Body switches to `call_user_func_array( $original, $args )` and guards with `is_wp_error( $result )` before the scalar-to-bool cast.
- [x] **T023 [Story 2/3]** Add SEC-annotated comment blocks above both changes explaining the invariants (arg-forwarding + WP_Error preservation) so a future reader understands the shape is load-bearing.

## Phase 4: Validation

- [x] **T030** Run `vendor/bin/phpcs includes/Abilities/PermissionOverrideProcessor.php tests/phpunit/Abilities/PermissionOverrideProcessorTest.php`. Clean.
- [x] **T031** Run `vendor/bin/phpstan analyse includes/Abilities/PermissionOverrideProcessor.php --memory-limit=4G --no-progress`. Exit 0, no errors.
- [x] **T032** `php -l` on both changed files. No syntax errors.
- [ ] **T033** CI phpunit run on the abilities suite (via `tests/bootstrap-wp.php`) — validated by GitHub Actions `.github/workflows/phpunit.yml` on the PR. Local WP test harness not installed; deferred to CI.

## Phase 5: Ship

- [x] **T040** Commit with the `fix(security):` prefix, body describing both bugs, both fixes, and the regression tests.
- [x] **T041** Push branch `fix/f030-permission-callback-bypass` to `origin`.
- [x] **T042** Open PR against `main` with a summary that (a) explains both bugs, (b) links the shipped fix, (c) lists the regression test scenarios, (d) notes local validation status and CI coverage.
- [x] **T043** Backfill spec-kit documentation (`docs/planings-tasks/033-…md`, `specs/033-…/spec.md`, `plan.md`, `tasks.md`) on the same PR branch — this commit.

## Dependencies & Execution Order

- Investigation (T001–T003) is a prerequisite for tests (T010–T014) and implementation (T020–T023). Tests were written first to encode the reproduction as regressions.
- Validation (T030–T033) blocks Ship (T040+).
- Everything is on one branch, one commit for the fix, one follow-up commit for the docs.

## Not in this feature (tracked separately)

- Filter-time eligibility gate refactor — GitHub issue linked from PR #45.
- Strict allowlist for `ExposureResolver::resolve` — separate proposal.
