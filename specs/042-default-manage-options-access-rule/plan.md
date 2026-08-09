# Implementation Plan: Runtime-Filter Default of `manage_options` for MCP Servers

**Branch**: `feat/042-default-manage-options-access-rule` | **Date**: 2026-08-09 | **Spec**: [spec.md](./spec.md)

## Summary

Register a single filter callback on the vendor `mcp_adapter_default_transport_permission_user_capability` filter that returns `'manage_options'` when the current server has no wpb-ac rule configured, otherwise returns the vendor default (`'read'`) so the existing F015 wrapper's `gate_mcp_tool_call` (on `mcp_adapter_pre_tool_call`) handles real per-rule enforcement.

**Rejected alternative (Q1)**: seeding a `wp_capability = manage_options` row into `wp_mcp_access_control` at server-creation time. The vendor UI's admin state model treats seeded rows differently than we expected — the "Who can access" dropdown still displayed "No user access added by admin" after our write, producing misleading operator UX and coupling our defaults to the operator-editable table. The runtime-filter approach has zero DB writes and applies uniformly to all servers.

## Technical Context

**Language/Version**: PHP 7.4+ (plugin min), tested on PHP 8.1+/8.4.
**Primary Dependencies**: `wordpress/mcp-adapter` (owns the filter this feature hooks) + `wpboilerplate/wpb-access-control` v2/v3 (rule read via `RuleQuery::get_rule()`). Both already loaded via composer.
**Storage**: Reads only. One `RuleQuery::get_rule()` call per unique route per MCP HTTP request (transient-cached inside the vendor).
**Testing**: Manual verification via the recipe in `spec.md` SC-001..SC-008. No new PHPUnit files strictly required — the callback is a ~30-line early-return chain; the vendor's `RuleQuery` + MCPServerQuery methods are covered by their own upstream tests.
**Target Platform**: WordPress admin + REST, any host.
**Project Type**: WordPress plugin — 1 new file (`TransportPermissionDefault.php`) + 2 edited (`Main.php`, `AccessControlTab.php`).
**Performance Goals**: One `MCPServerQuery::query()` + one `RuleQuery::get_rule()` per unique route per MCP HTTP request. Memoized per-request in a private array. Vendor `get_rule()` uses a transient cache under the hood.
**Constraints**:

- MUST hook via the plugin Loader per A1 — no direct `add_filter()`.
- MUST use the 5-arg Loader signature `($hook, $component, $callback_string, $priority, $accepted_args)` to avoid B43 (wrapping callback in array — silent latent fatal at fire time).
- MUST match the namespace literal `'acrossai-mcp-manager'` used by `AcrossAI_MCP_Access_Control` lines 243/304 — no new constant introduced.
- MUST NOT write to `wp_mcp_access_control` from any plugin code path — the prior DB-seeder approach is fully removed.
- MUST fail-open when the wpb-ac vendor is absent — mirrors F015's `is_available()` contract.
- MUST NOT modify `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` — the tool-call gate is the correct enforcement layer for operator-configured rules and stays unchanged.

**Scale/Scope**: 1 new file (~140 lines w/ docblocks), 2 edited files (~5 lines in `Main.php` for filter wiring, ~20 lines in `AccessControlTab.php` for banner method + call). Net ~165 LoC added.

## Constitution Check

*Evaluated 2026-08-09 against project constitution v1.1.0.*

| Principle | Applicability | Verdict |
|---|---|---|
| I — Modular Architecture (feature classes in `includes/`, admin in `admin/Partials/`, hooks in `Main.php` only) | Yes — new class in `includes/AccessControl/`; UI banner in `admin/Partials/ServerTabs/`; filter wired ONLY in `Main::define_public_hooks()` | PASS |
| II — Singleton + private `__construct()` | Yes — `TransportPermissionDefault::instance()` follows the plugin-wide pattern | PASS |
| III — Security (nonces, capability, sanitization, escaping) | Yes — read-only filter callback; no user input reaches it; UI banner uses `esc_html__()` and hardcodes `<code>` markers | PASS |
| IV — DataForm / DataViews UI contract | N/A — no form/table added; a static banner div is not a form | PASS |
| V — Extensibility via filters | Yes — the whole feature IS a filter callback; consumers who want a different default hook the same vendor filter at priority > 10 | PASS |
| VI — WordPress packages first | N/A — no JS/CSS changes | PASS |
| VII — Package hierarchy | N/A — no JS package added | PASS |

**Constitution invariants checked against memory:**
- **A1** (hooks in `Main.php` only) — filter wiring is in `define_public_hooks()`. ✓
- **A6** (imports required, no bare relative namespace) — `use HttpRequestContext`, `use MCPServerQuery`, `use RuleQuery` at the top of the new file. ✓
- **B43** (Loader signature) — using 5-arg form `add_filter( 'hook', $instance, 'method', 10, 2 )`, not `add_filter( 'hook', array( $instance, 'method' ), 10, 2 )`. ✓
- **D19** (fail-open observability) — behavior fails open on unknown route / missing vendor / missing server; no `do_action` observability fire because this is a plugin-wide default rather than a per-request access decision. ✓

**No constitution deviations. No memory-hub decision-level entry warranted** — this feature applies a known-good runtime-filter pattern; the durable lesson is the "vendor-UI-owns-the-table → don't write to that table" note captured briefly in the planings-tasks nav.

## Implementation Notes

### File 1: `includes/AccessControl/TransportPermissionDefault.php` (NEW)

Singleton. Constants:
- `NAMESPACE_SLUG = 'acrossai-mcp-manager'` (private const).
- `ADMIN_ONLY_CAPABILITY = 'manage_options'` (private const).

Per-request memoization: `private array $memo = []` keyed by `"$ns/$route"`.

One public method: `filter_default_capability( string $default, HttpRequestContext $context ): string`. Early returns:
1. Route is empty → `$default`.
2. Route doesn't split into 2 non-empty parts on the first `/` → `$default`.
3. No MCPServer row matches (`namespace, route`) → `$default` (route belongs to some other consumer of the vendor filter).
4. `server_slug` is empty → `$default` (defensive).
5. Vendor `RuleQuery` class missing → `$default` (fail-open).
6. Existing rule (`get_rule()['key'] !== ''`) → `$default` (defer to `gate_mcp_tool_call`).
7. Otherwise → `'manage_options'`.

Every branch writes to `$this->memo[$memo]` before returning.

### File 2: `includes/Main.php` (EDITED, ~5 lines added)

Inside `define_public_hooks()`, immediately after the existing `mcp_adapter_pre_tool_call` filter registration (line 453), add:

```php
$transport_default = \AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault::instance();
$this->loader->add_filter( 'mcp_adapter_default_transport_permission_user_capability', $transport_default, 'filter_default_capability', 10, 2 );
```

The two access-control filters now sit together — mental grouping for future maintainers.

### File 3: `admin/Partials/ServerTabs/AccessControlTab.php` (EDITED, ~20 lines added)

1. Prepend `$this->render_default_policy_notice();` at the top of `render_body()`.
2. Add a new `private function render_default_policy_notice(): void` between `render_body()` and `render_pom_header()` that emits the info-banner div with the "Default policy: administrators only." headline and body text explaining the "No user access added by admin" ↔ admin-only mapping.

## Files removed vs pre-042 shape

None. This branch was reset to `main` before applying the filter approach, so no reverts are needed — the DB-seeder code from the prior PR #71 attempt never lands on main.

## Quality Gates

- `php -l` on the 3 files → No syntax errors.
- `vendor/bin/phpcs --standard=phpcs.xml.dist` on `TransportPermissionDefault.php` + `AccessControlTab.php` → 0 errors (Main.php has pre-existing baseline exceptions on unrelated lines, per PHPCS baseline).
- `vendor/bin/phpstan analyse` (L8) on all 3 files → exit 0, no errors.
- Manual recipe in `spec.md` SC-001..SC-006 on local site → operator to run post-merge.

## PR mechanics

The prior PR #71 shipped the DB-seeder approach. This plan replaces those commits via a `git reset --hard origin/main` + rebuild + `git push --force-with-lease`. The PR body is updated via `gh pr edit` to describe the runtime-filter approach. No new PR number is issued; PR #71 stays the same URL.

## References

- **Vendor filter**: `vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:119` (`apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', $context )`).
- **Vendor HttpRequestContext shape**: `vendor/wordpress/mcp-adapter/includes/Transport/Infrastructure/HttpRequestContext.php:15` (public `$request` WP_REST_Request + method + session_id + body + protocol_version + accept_header).
- **Vendor RuleQuery**: `vendor/wpboilerplate/wpb-access-control/src/Database/Rule/RuleQuery.php:163` (`get_rule` returns `['key' => '', 'value' => []]` on empty; transient-cached).
- **F015 wrapper**: `includes/AccessControl/AcrossAI_MCP_Access_Control.php` (namespace hardcoding at lines 243/304; `TABLE_SLUG` at line 65; unchanged tool-call gate at line 260).
- **Access Control tab URL**: `/wp-admin/admin.php?page=acrossai_mcp_manager&action=edit&server=<id>&tab=access-control`.
