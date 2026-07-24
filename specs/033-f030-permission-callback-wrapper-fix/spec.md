# Feature Specification: F030 Permission-Callback Wrapper Fix

**Feature Branch**: `fix/f030-permission-callback-bypass`
**Created**: 2026-07-24
**Status**: Shipped (PR [#45](https://github.com/acrossai-co/acrossai-mcp-manager/pull/45))
**Input**: Live reproduction: a subscriber-level MCP client successfully invoked `acrossai-abilities-manager/site-title-get` on the default server, even though the ability was `mcp.public=false` at registration and the operator had explicitly disabled it (`is_exposed=0`) in the Abilities tab. Root cause: two bugs in F030's `permission_callback` wrapper — the closure signature dropped its args, and `call_original` cast the resulting `WP_Error` to boolean `true`. See `docs/planings-tasks/033-f030-permission-callback-wrapper-fix.md` for the full engineering brief.

## Clarifications

### Session 2026-07-24

- Q: Ship as a scoped bug fix or bundle with the architectural refactor that would eliminate the wrapper for un-eligible abilities entirely? → A: Scoped fix. PR #45 is minimal, reviewable, and correct; the refactor (filter-time eligibility gate) is architecturally superior but a bigger diff — track it separately so this security fix ships immediately.
- Q: Strict allowlist (`no is_exposed=1 row → deny`) — bundle into this fix? → A: No. Behavioural change (every globally-public ability stops working via MCP until an admin opts each one in per server). Separate concern from the wrapper-bugs fix.
- Q: Does the fix change any of the six defensive layers in `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS`? → A: No. All six layers are preserved. The fix only changes (a) how args flow into the fall-through path and (b) how `WP_Error` returns are represented, both without touching the allow-path semantics.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Subscriber cannot invoke a disabled ability (Priority: P1 — security)

A site admin unchecks `acrossai-abilities-manager/site-title-get` in the Abilities tab for the default MCP server (persists `is_exposed=0`). A subscriber-level authenticated user connects via MCP and invokes `mcp-adapter/execute-ability` with `ability_name = acrossai-abilities-manager/site-title-get`. The response is a permission-denied error and the ability is NOT executed.

**Why this priority**: this is the security regression the feature exists to close. Without it, the Abilities-tab control is a placebo for any authenticated user.

**Independent Test**: Seed the state above. As a subscriber (`wp_set_current_user($subscriber_id)`), invoke the wrapped `permission_callback` on `mcp-adapter/execute-ability` with `$input = ['ability_name' => 'acrossai-abilities-manager/site-title-get', 'parameters' => []]`. Assert the return is `WP_Error` (or `false`), never `true`. Assert the site title is not returned in a live end-to-end test.

**Acceptance Scenarios**:

1. **Given** an MCP server with `override_abilities_permission = 0` and an ability with `is_exposed = 0` on that server, **When** a subscriber invokes the ability via `mcp-adapter/execute-ability`, **Then** the response carries the permission-denied error surfaced by `Execute::check_permission` (`acrossai_mcp_ability_not_exposed_for_server`) AND the ability's `execute_callback` is NOT invoked.
2. **Given** the same server config, **When** an administrator (with `manage_options`) invokes an ability the operator has NOT enabled (`is_exposed = 0`), **Then** the response is the same permission-denied error — role does not override the exposure gate.

### User Story 2 — Args reach the wrapped callback intact (Priority: P1 — correctness)

Any code that wraps another callback and forwards to it MUST preserve the caller's arguments. F030's closure had the wrong shape and dropped them.

**Independent Test**: Wrap a closure that captures its `$input` argument via a reference; call the wrapped closure with a known input; assert the captured value equals the input passed in.

### User Story 3 — WP_Error results propagate through the wrapper (Priority: P1 — correctness)

Downstream permission callbacks routinely return `WP_Error` to communicate the specific reason a permission was denied. The wrapper MUST NOT cast an object return to boolean, because `(bool) $any_object === true` in PHP silently converts denies into allows at the vendor's `if ( true !== $permission )` check.

**Independent Test**: Original callback returns `new WP_Error('test_deny', ...)`. Assert the wrapper's return is `instanceof WP_Error` with the expected error code.

### Edge Cases

- Original callback is `null` (ability registered without `permission_callback`) — wrapper returns `false` (WP Abilities API deny-by-default).
- Original callback is non-callable — same as above.
- MCP request in flight, server has override ON, ability is exposed → wrapper returns `true` without invoking the original (the F030 bypass allow-path). Behaviour unchanged.
- Non-MCP context (WP-CLI, cron, direct `wp_get_ability()->execute()`) → `CurrentServerHolder::get_server_id()` returns `null` → wrapper forwards args to the original unchanged.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-033-001**: `PermissionOverrideProcessor::inject_override` MUST forward all args received by the wrapped `permission_callback` to the original callback, unmodified.
- **FR-033-002**: `PermissionOverrideProcessor::call_original` MUST return `WP_Error` unchanged when the original callback returns `WP_Error`. Only scalar returns may be coerced to bool.
- **FR-033-003**: The six defensive layers of `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS` MUST remain enforceable — the fix is scoped to the fall-through path only.
- **FR-033-004**: Existing F030 regression tests in `PermissionOverrideProcessorTest.php` MUST continue to pass.

### Security Checklist

- [x] Reproduces on pre-fix code (subscriber can invoke disabled ability).
- [x] Fails on post-fix code with permission-denied error.
- [x] Fix covers both bug tiers (args + WP_Error) — a fix for one alone leaves a bypass path open (WP_Error cast still returns `true` even with correct args; args being dropped still triggers WP_Error from the callback).
- [x] Role-parameterised test verifies denial across subscriber / contributor / author / editor; administrator allowed only via the ability's own `manage_options` cap check, not via a wrapper coercion.
- [x] No changes to the six-layer allow-path — no new attack surface introduced by the fix itself.

### Key Entities

- `PermissionOverrideProcessor` — F030's `wp_register_ability_args` filter callback registered at priority 999999. The wrap it installs is the fix target.
- Vendor `ToolsHandler::call_tool` — the check-permission boundary at `vendor/wordpress/mcp-adapter/includes/Handlers/Tools/ToolsHandler.php:148`.
- `Execute::check_permission` — the replacement callback for `mcp-adapter/execute-ability`. First victim of the args-drop; returned the `WP_Error` that got coerced.

## Success Criteria *(mandatory)*

### Definition of Done Gates

- [x] Two-line signature fix + one-method rewrite in `PermissionOverrideProcessor.php`.
- [x] Three regression tests in `PermissionOverrideProcessorTest.php`, one of them parameterised over five WP roles.
- [x] PHPCS clean on changed files.
- [x] PHPStan level 8 clean on changed files.
- [x] PR opened against `main` with a body describing the two bugs and the fix.

### Measurable Outcomes

- All three new regression tests fail against `main` @ 49108d6 (pre-fix) and pass on the fix branch.
- No existing tests regress (variadic closures accept zero args, so pre-existing `call_user_func( $wrapped )` call sites still work).
- Live reproduction against the fixed branch returns the expected permission-denied error.
