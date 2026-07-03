# Security Review — Plan-Level Constraints

**Reviewed plan**: `specs/012-mcp-settings-tab/plan.md`
**Reviewed spec**: `specs/012-mcp-settings-tab/spec.md`
**Constitution**: `.specify/memory/constitution.md` §III (Security First — NON-NEGOTIABLE)
**Date**: 2026-07-03
**Reviewer**: governed-plan orchestrator (inline `speckit-security-review-plan` fallback)

---

## Scope

Feature 012 is an admin-surface feature: (1) adds an MCP tab on the shared `?page=acrossai-settings` page with three operator toggles, (2) rewrites `uninstall.php` to gate destructive teardown behind an opt-in flag, (3) removes the standalone CLI Auth Log admin submenu. The security-relevant surfaces are:

1. **`manage_options` capability gate** on the shared settings page (owned upstream by the vendor's `PageRenderer`).
2. **Nonce handling** on the Save round-trip (owned upstream by the vendor's `settings_fields( 'acrossai-settings' )` call inside `PageRenderer::render()`).
3. **Sanitize contracts** for the three new option values (`rest_sanitize_boolean` × 2 + custom `sanitize_uninstall_flag` × 1).
4. **`$wpdb->prepare()` on every DB path** in the rewritten `uninstall.php`.
5. **Destructive-teardown gate**: `uninstall.php` MUST refuse to touch tables/options unless the opt-in flag is `1`.
6. **Preserved runtime dependencies**: OAuth flow (SEC-001 atomic-CAS via `redeem_atomic`) continues to consume the `includes/Database/CliAuthLog/**` layer that this feature explicitly preserves.

Because the plugin ships in the same codebase as Feature 011's OAuth invariants (atomic-CAS, SHA-256 hashed columns), this review's primary job is to confirm that Feature 012's edits DO NOT REGRESS those invariants.

---

## Trust Boundaries

| Boundary | Direction | Threat Surface | Defense |
|---|---|---|---|
| Admin browser → shared settings page save form | inbound at HTTP POST | Standard WP admin form: user could omit fields, submit tampered values, or replay expired nonces. If the sanitize callbacks were wrong or the option group misconfigured, tampered inputs could persist to `wp_options`. | Vendor's `PageRenderer::render()` emits `settings_fields('acrossai-settings')` → wpnonce + `_wp_http_referer`. `options.php` handoff validates via `option_group` whitelist. `rest_sanitize_boolean` coerces boolean toggles to `bool` (rejects arbitrary strings). Custom `sanitize_uninstall_flag` coerces int-0/1 (`empty($value) ? 0 : 1` rejects anything truthy except literal `1`). |
| Uninstall runtime → destructive DB operations | at uninstall time | If the opt-in gate is bypassed or misread, `uninstall.php` silently deletes all four `wp_acrossai_mcp_*` tables + all `acrossai_mcp_*` options. Irreversible data loss for the operator. | FR-019 mandates the early-return gate at the TOP of `uninstall.php`, immediately after `WP_UNINSTALL_PLUGIN`. FR-020 orders operations INSIDE the gate. Every destructive op MUST be after the gate check per CONSTRAINTS block "Do not skip the gate" + "Do not invert the default". `(int)` cast on `get_option( ..., 0 )` guards against non-int values (default `0` → early return). |
| Uninstall runtime → `wp_options` LIKE-sweep | at uninstall time | `SELECT option_name FROM wp_options WHERE option_name LIKE %s` — if the pattern were user-controlled or contained SQL wildcards, arbitrary options could be deleted. | FR-022 hardcodes the pattern to `'acrossai_mcp_%'` (a fixed string literal in PHP source, not from user input). `$wpdb->prepare()` handles the `%s` binding correctly — MySQL LIKE metacharacters (`%`, `_`) in the pattern are LITERAL within `$wpdb->prepare( "... LIKE %s", 'acrossai_mcp_%' )` because `$wpdb->prepare()` treats the argument as a string literal, not a pattern. Only `wp_options` rows whose `option_name` starts with `acrossai_mcp_` are matched. |
| Uninstall runtime → `DROP TABLE IF EXISTS` loop | at uninstall time | Table name is interpolated into the SQL (`{$table}`), not passed as a `%i` placeholder — a phpcs:ignore comment is needed. If the table name were user-derived, this would be SQL injection. | FR-021 enumerates the 4 tables as a hardcoded PHP array. Each `$table = $wpdb->prefix . 'acrossai_mcp_<stem>'` — hardcoded stem + `$wpdb->prefix` (which is a WordPress-owned string). No user input reaches the SQL. `phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared` is scoped to this loop only. `IF EXISTS` handles the missing-table case idempotently. |
| CliAuthLog DB layer (preserved) → OAuth flow | at OAuth request time | If Feature 012 accidentally deleted files under `includes/Database/CliAuthLog/**`, OAuth token exchange would break: `redeem_atomic` becomes an undefined method → fatal → user cannot complete auth flow. This would also silently disable the SEC-001 atomic-CAS invariant from Feature 011. | Spec FR-028 mandates verbatim preservation of the 5 files. Companion pre-flight grep in the spec verifies the hit count for `CliAuthLog\Query\|Row\|Table\|Recorder` is UNCHANGED before and after TASK-6. Any drop = accidental deletion; MUST be reverted before merge. |
| Vendor Save routing → wrong option group | at save time (design-time risk) | If `register_setting()` used the per-tab page slug (`acrossai-settings-mcp`) instead of the shared option group (`acrossai-settings`), the vendor's `settings_fields('acrossai-settings')` call would produce a nonce that doesn't cover the mcp options, and `options.php` would reject the save silently — user thinks they saved but nothing persists. | Vendor README section 168-180 documents this exact contract. Spec FR-004 hardcodes the option group to `'acrossai-settings'`. PHPUnit test `test_register_settings_registers_expected_option_keys` (per spec TASK-4 SC-007) asserts the option key lives under this group in `$wp_registered_settings` — locks the invariant as a runtime check. |

---

## Authorization Assumptions

Feature 012 introduces zero new capability checks, permission callbacks, or nonces. It preserves:

- **`manage_options` capability gate** — enforced upstream by the vendor `PageRenderer` when rendering the shared page. `SettingsMenu`'s render methods do NOT need to re-check the capability because they are called from within the vendor's `do_settings_sections()` loop, which itself is called from within a `PageRenderer::render()` method that has already verified `manage_options`.
- **Nonce validation** — enforced upstream by the vendor's `settings_fields('acrossai-settings')` emit + `options.php` handoff. No plugin-side nonce work.
- **Consent-surface exception (Feature 007 §III amendment)** — NOT applicable. The MCP tab is admin-only, not a browser-mediated user-on-own-behalf consent surface. The consent-surface exception's five conditions do not apply here.
- **Feature 009 `class_exists('\WP\MCP\Plugin')` guard** — not touched by this feature. `MCP/Controller.php` remains at Feature 011 state.
- **Feature 010 `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` guards** — the Access Control admin submenu (position 4 in `Menu.php`) survives the position-3 CLI Auth Log deletion per plan §Constitution Check A8. FR-025 explicitly requires preserving positions 2 and 4 unchanged.

---

## Data Isolation & Validation Risks

- **`acrossai_mcp_uninstall_delete_data` value drift**: if a rogue caller (or a future feature) wrote a string `'true'` or `'yes'` to this option, `(int) 'true' === 0` → the gate would (correctly) short-circuit. Preserve-by-default holds even under garbage input. Safe.
- **`acrossai_mcp_npm_login_enabled` legacy values**: if a site already has this option set to `1` (int) or `'1'` (string) from an OLDER build (e.g., the sibling `wordpress-ai` copy which uses the same option key), `(bool) get_option( ..., false )` in the render method coerces to `true` correctly. `rest_sanitize_boolean` on save also handles both forms. No corruption.
- **Uninstall race with active OAuth flow**: theoretically, if an OAuth CLI redemption were in flight (holding a row read on `wp_acrossai_mcp_cli_auth_logs`) at the exact moment of uninstall, the destructive teardown could interleave with the atomic-CAS. In practice, `uninstall.php` runs synchronously during the WP admin plugins-screen "Delete" action; the admin has already deactivated the plugin (which disables the REST route handlers), so no concurrent CLI redemption can be in flight. Not a real threat surface.
- **Multi-plugin option-name collision**: `acrossai_mcp_%` LIKE-sweep only matches options with that prefix. Sibling `acrossai-abilities-manager` uses `acrossai_abilities_%` prefix — no collision. Vendor `acrossai-co/main-menu` options (if any) would use `acrossai_main_menu_%` or similar — no collision. Safe.

---

## Async / Concurrency Security Context

- **Save round-trip**: WP `options.php` is a synchronous POST → `wp_die` or redirect. No async concurrency risk.
- **Uninstall runtime**: synchronous with the WP admin plugins-screen action. No async concurrency risk.
- **PageRenderer + settings_fields**: standard WP Settings API — no plugin-defined concurrency behavior.

---

## Missing Gates / Recommendations

- **RECOMMEND — PHPUnit assertion on preserve-by-default behavior**: the plan's TASK-4 lists three test methods focused on `register_tab` + `register_settings`. Consider adding a fourth test method that exercises the uninstall.php gate (via `include` of `uninstall.php` with `WP_UNINSTALL_PLUGIN` defined + option value 0) and asserts no `$wpdb->query` calls fired. This is technically outside the SettingsMenu test file's scope, but locks the safety invariant. Optional — the acceptance scenarios in US2 already cover this via manual smoke test.
- **RECOMMEND — Reviewer callout for the DROP TABLE loop's `phpcs:ignore` scope**: at code-review time, verify the `phpcs:ignore` comment is scoped to the loop only (not applied to the outer file scope). Broadening the ignore would silently suppress other SQL-injection warnings elsewhere in `uninstall.php`.
- **RECOMMEND — Companion DB-layer grep as a merge-gate**: spec FR-028's companion pre-flight grep MUST run before merge. If the hit count drops, TASK-6 accidentally damaged the DB layer and OAuth token exchange is at risk. Automate as a CI check (or verify manually before pressing "Merge").

---

## Status

**PASS** — no HARD security-architecture conflicts identified. Three RECOMMEND items surface as advisory gates for `/speckit-tasks` to fold into T4 / T5 / T6 DoD lines.

The plan's overall security posture matches or slightly strengthens the pre-feature baseline:
- New: opt-in gate on `uninstall.php` — safer than pre-feature unconditional teardown for the two OAuth tables.
- New: sanitize contracts on three options — prevents option-value corruption at save time.
- Preserved: SEC-001 atomic-CAS (via CliAuthLog DB layer preservation).
- Preserved: SHA-256 hashed columns (untouched — no schema changes).
- Preserved: `manage_options` gate on the shared page.
- Preserved: nonce validation via vendor `settings_fields()`.
