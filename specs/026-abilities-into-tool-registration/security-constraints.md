# Security Constraints — Feature 026

**Source reviews**:
- [Plan v1 (2026-07-14)](../../docs/security-reviews/2026-07-14-026-abilities-into-tool-registration-plan.md) — 3 INFO findings.
- [Plan v2 (2026-07-14)](../../docs/security-reviews/2026-07-14-026-abilities-into-tool-registration-plan-v2.md) — 2 INFO findings.
- [Tasks (2026-07-14)](../../docs/security-reviews/2026-07-14-026-abilities-into-tool-registration-tasks.md) — 2 INFO task-hygiene findings, both applied inline to `tasks.md`.

**Aggregate risk**: LOW.

## Boundaries preserved

- **REST auth boundary**: `permission_callback = manage_options` on Tools tab GET/POST (F025 baseline) and Abilities tab GET/POST (F017 baseline). No new routes; no boundary changes.
- **DB write boundary**: F026 introduces ZERO writes. All F017/F020/F025 storage layers are read-only from F026's perspective.
- **Vendor boundary**: `vendor/wordpress/mcp-adapter/` untouched. F025's existing consumption of `mcp_adapter_default_server_config` continues; the callback body internally calls the widened composer.
- **F017 gate at `mcp_adapter_pre_tool_call` priority 20**: untouched. Deny precedence preserved — companion filter that adds an F017-hidden slug at advertising time still gets 403 at call time.
- **F020 gate at `mcp_adapter_pre_tool_call` priority 30**: untouched.
- **Fail-open contract**: `function_exists( 'wp_get_abilities' )` guard in the new composer. Matches F017 `AbilityExposureGate` + F025 `filter_default_server_config` defensive patterns.

## Implementation constraints derived from findings

Every finding is INFORMATIONAL or LOW; none block implementation. All are folded into concrete task IDs in `tasks.md`:

### Plan-review v1 (3 INFO)

| Finding | Task ID | Constraint |
|---|---|---|
| SEC-026-INFO-1 (F017 confused-deputy surface carries into F026's widened filter input) | T008 | Ensure `docs/extending-server-tools.md` §"Interaction with the Abilities tab" cross-references SEC-025-INFO-1 and states that companion filters can override the operator's Abilities tab decision at ADVERTISING time but cannot bypass F017's call-time gate at priority 20. |
| SEC-026-INFO-2 (B23 test-suffix method usage on `_reset_cache_for_tests`) | (deferred) | No F026 task — deferred to future F017 maintenance PR (rename → `clear_request_cache()`). F026 correctly consumes the existing method in test `setUp()` only. No production code path. |
| SEC-026-INFO-3 (`mcp.public = true` is implicit opt-in) | T008 | Add explicit paragraph in `docs/extending-server-tools.md` §"Interaction with the Abilities tab": adding a third-party plugin with `meta.mcp.public = true` immediately exposes its abilities on every server; operators opt out per server via the Abilities tab. |

### Plan-review v2 (2 INFO)

| Finding | Task ID | Constraint |
|---|---|---|
| SEC-026-v2-1 (empty-set fallback semantic shift on default server) | T010 | Amend `spec.md §Edge Cases` to note that F026's fallback fires only when protocol + curated + F017-effective are all empty; on installs with any `mcp.public = true` ability, the fallback path is typically unreachable. Also amend `data-model.md` §"Order-of-operations" per SEC-TASKS-026-2. |
| SEC-026-v2-2 (implicit "third-party abilities must register on `wp_abilities_api_init`" constraint) | T008 | Add one-sentence timing note to `docs/extending-server-tools.md`: extension authors MUST hook `wp_abilities_api_init` (which fires during `init`); late-registered abilities on `rest_api_init` or later hooks are invisible to F026. |

### Tasks-review (2 INFO) — inline task edits applied 2026-07-14

| Finding | Task ID | Constraint | Status |
|---|---|---|---|
| SEC-TASKS-026-1 (fail-open branch has no PHPUnit test) | T002 | Add one-line code-comment marker at the fail-open branch citing SC-006 quickstart coverage. Prevents future maintainer confusion about test-strategy gap. | **Applied inline** to T002 body. |
| SEC-TASKS-026-2 (SEC-026-v2-1 spec amendment should mirror to `data-model.md`) | T010 | Extend T010 to also update `data-model.md` §"Order-of-operations" §"Default server" step 3 with a parenthetical pointing at `spec.md §Edge Cases`. | **Applied inline** to T010 body. |

## Constitutional §III mapping

| §III item | F026 stance |
|---|---|
| Input sanitized at boundary | No new user input. Ability slugs already `strval`'d before append; `is_array( $meta ) ? $meta : array()` guard on `ExposureResolver::resolve()` input. |
| Output escaped at render | No new HTML output. Composed list is a JSON payload in vendor-controlled MCP JSON-RPC responses. |
| Nonces on forms/AJAX | Inherited from F017/F025 (WP core REST middleware). No new forms. |
| Capability checks on admin/mutating | `manage_options` on all touched REST endpoints; unchanged. |
| Prepared SQL | BerlinDB Kern via `MCPServerAbility\Query::query()`; prepares under the hood. Zero raw `$wpdb` calls added. |
| REST `permission_callback` explicit | Preserved on all routes. No `__return_true` added. |
| Hashed secrets | N/A — F026 has no secret surface. |
| File uploads | N/A. |

## Not blocking implementation

Proceed directly to `/speckit-architecture-guard-violation-detection` (Step 5 of the parent governed-plan workflow). The three INFO findings are inline TODOs for TASK-4 (docs update), not gates.

---

## Post-2026-07-15 addendum — no security invariants added or relaxed

The F026 v3 refactor arc (commits `4ca9db4` → `e0189b0`) changed the mechanism but not the security posture:

- Advertisement-time enforcement moved to call-time enforcement (via 070ffe2's plugin-owned meta-tool callbacks). Same F017 semantic honored, just at a different point in the request flow.
- F017 `AbilityExposureGate` at `mcp_adapter_pre_tool_call` priority 20 UNCHANGED — direct calls to an ability's own slug still gated per-server.
- Two pre-existing bugs fixed:
  - `69e689c` — F020 `EXCLUDED_SLUGS` didn't match vendor-sanitized names. This was denying legitimate meta-tool calls (availability). Fixed by expanding the constant to include both raw and sanitized forms.
  - `e0189b0` — `AbilityHelpers::apply_exposure_filter` defaulted to `meta.mcp.public` only, silently ignoring Abilities-tab per-server overrides on the meta-tool discovery path (integrity of operator intent). Fixed by defaulting to `ExposureResolver::resolve()`.
- **Critical new invariant** added by 070ffe2 (documented in `spec.md §FR-023`): **exposure ≠ authorization**. A companion filter widening exposure via `acrossai_mcp_is_ability_exposed` MUST NOT bypass the target ability's own `permission_callback`. Enforced by ordering in `Execute::check_permission` (auth → exposure filter → target permission_callback). Regression-guarded by `ExecuteTest::test_filter_widening_does_not_bypass_target_permission_callback`.
- No new REST routes, no new writes, no new capabilities, no new secrets, no vendor code changes. Constitutional §III (Security First) still holds.

The security-review artifacts under `docs/security-reviews/2026-07-14-026-*.md` remain valid for the F026 v1 design they analyzed. A fresh review has NOT been commissioned for the v3 shape because the change is a mechanism swap within an already-reviewed threat model, not a new capability surface. If a follow-up security review is desired, invoke `/speckit-security-review-branch` against the current HEAD.
