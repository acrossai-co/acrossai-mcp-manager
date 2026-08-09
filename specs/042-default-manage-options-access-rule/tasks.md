---
description: "Task list for Feature 042 — runtime-filter default of manage_options for MCP servers + Access Control tab explainer notice"
---

# Tasks: Runtime-Filter Default of `manage_options` for MCP Servers

**Input**: [spec.md](./spec.md), [plan.md](./plan.md)

**Tests**: 2 PHPUnit test files under `tests/phpunit/Includes/AccessControl/` (picked up by the existing `admin` suite — no phpunit.xml.dist change):

- `TransportPermissionDefaultTest.php` — 14 unit tests covering every branch of `filter_default_capability()` in isolation. Real `MCPServerQuery` + real vendor `RuleQuery`, no mocks. Transactional DB rollback per test.
- `TransportPermissionRoleMatrixTest.php` — 12 composed integration tests exercising BOTH filters end-to-end via a `user_can_reach()` helper that mirrors `HttpTransport::check_permission` (layer 1) + `gate_mcp_tool_call` (layer 2). Covers 6 user roles × 4 rule shapes × ≥4 servers per test, including a 5×4 truth-table matrix and a multi-server user-ID rule test.

Manual verification recipe in `spec.md` SC-001..SC-006 remains as an operator smoke test.

**Organization**: One user story (US1). Shipped on branch `feat/042-default-manage-options-access-rule` (force-push replacing the earlier DB-seeder attempt).

## Format: `[ID] [Story] Description`

## Path Conventions

- **Plugin root**: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager`

---

## Phase 1: Research

- [X] T001 [US1] Confirm the vendor filter `mcp_adapter_default_transport_permission_user_capability` exists at `vendor/wordpress/mcp-adapter/includes/Transport/HttpTransport.php:119`, defaults to `'read'`, and fires only when no `transport_permission_callback` is set on the server.
- [X] T002 [US1] Confirm this plugin's `MCP\Controller::create_server()` call at line 216 does NOT pass a `$transport_permission_callback` → the vendor filter fires for every plugin-owned server.
- [X] T003 [US1] Confirm `HttpRequestContext` public fields at `vendor/wordpress/mcp-adapter/includes/Transport/Infrastructure/HttpRequestContext.php:15` — `$request` is the sole hook back to the WP REST layer.
- [X] T004 [US1] Confirm MCPServer schema has `server_route_namespace` (varchar 100, default 'mcp') and `server_route` (varchar 255, default '') columns at `includes/Database/MCPServer/Schema.php:85, 93`; both are queryable via BerlinDB `Query::query()`.
- [X] T005 [US1] Confirm vendor `RuleQuery::get_rule( $ns, $key ): array{key: string, value: string[]}` returns `['key'=>'', 'value'=>[]]` on no rows — matches the "No user access added by admin" UI state.

## Phase 2: User Decision Gate

- [X] T006 [US1] Q1 approach: DB-row seeding OR runtime filter? → **A: Runtime filter.** Prior DB-seeder attempt (PR #71 initial version) surfaced a vendor UI conflict.
- [X] T007 [US1] Q2 semantics: static `manage_options` always OR rule-aware defer? → **A: Rule-aware.** Preserves the admin UI flow (add rule for editor → editor gets access).

## Phase 3: Branch Reset

- [X] T008 [US1] Reset feature branch `feat/042-default-manage-options-access-rule` to `origin/main` to drop the earlier DB-seeder commits. Working tree clean at `main` tip.

## Phase 4: Implementation

- [X] T009 [US1] Create `includes/AccessControl/TransportPermissionDefault.php` — singleton with private constants (`NAMESPACE_SLUG`, `ADMIN_ONLY_CAPABILITY`), per-request `$memo` array, and one public method `filter_default_capability( string $default, HttpRequestContext $context ): string` implementing the 7-branch early-return chain from `plan.md`.
- [X] T010 [US1] Edit `includes/Main.php::define_public_hooks()` — after the existing `mcp_adapter_pre_tool_call` filter registration at line 453, add the new filter wiring using the 5-arg Loader shape (avoids B43).
- [X] T011 [US1] Edit `admin/Partials/ServerTabs/AccessControlTab.php::render_body()` — prepend `$this->render_default_policy_notice();` call; add new private method `render_default_policy_notice()` that emits the info banner with "Default policy: administrators only." headline + body copy referencing the "No user access added by admin" dropdown state.

## Phase 5: Quality Gates

- [X] T012 [US1] `php -l` on all 3 files → No syntax errors detected.
- [X] T013 [US1] `vendor/bin/phpcs --standard=phpcs.xml.dist` on `TransportPermissionDefault.php` + `AccessControlTab.php` + both new test files → 0 errors. Main.php has pre-existing baseline exceptions unrelated to this change.
- [X] T014 [US1] `vendor/bin/phpstan analyse` (L8) on all 3 code files → exit 0, no errors.

## Phase 5b: Automated Tests

- [X] T014a [US1] Create `tests/phpunit/Includes/AccessControl/TransportPermissionDefaultTest.php` — 14 unit tests covering every branch of `filter_default_capability()` (singleton contract, 4 route-parsing early returns, unknown server, server-with-no-rule → `manage_options`, server-with-rule → deferral, seeded default server, default-passthrough on both branches, per-request memoization ×2, filter-wiring regression guard). Real `MCPServerQuery` + real vendor `RuleQuery`, no mocks.
- [X] T014b [US1] Create `tests/phpunit/Includes/AccessControl/TransportPermissionRoleMatrixTest.php` — 12 composed integration tests exercising BOTH filters end-to-end via `user_can_reach()` helper. Covers 6 user roles × 4 rule shapes (none / wp_role / wp_user / wp_capability / `authenticated` / `everyone`) × ≥4 servers per test, including a 5×4 truth-table matrix that directly proves per-server independence (Group G) and a multi-server user-ID rule test (Group H).
- [X] T014c [US1] Both test files land in the existing PHPUnit `admin` suite via the pre-existing `<directory>tests/phpunit/Includes/AccessControl</directory>` entry in `phpunit.xml.dist` — no `phpunit.xml.dist` edit needed. CI's `PHPUnit (integration)` job runs them automatically.

## Phase 6: Post-Merge Verification (manual)

- [ ] T015 [US1] Delete every row from `wp_mcp_access_control` (`DELETE FROM wp_mcp_access_control;`). Confirm every server's Access Control tab dropdown reads "No user access added by admin".
- [ ] T016 [US1] `curl` the default server endpoint as anonymous → 401. As subscriber → 401. As admin → 200.
- [ ] T017 [US1] Set the dropdown to `WordPress role → Editor`, save. Confirm dropdown now reads "WordPress role". `curl` as editor → 200; as subscriber → 403 with `WP_Error acrossai_mcp_access_denied`.
- [ ] T018 [US1] Create a NEW server via **Add New Server**. Repeat SC-001..SC-003 on the new server endpoint — confirm the filter fires for user-created servers, not just the seeded default.
- [ ] T019 [US1] `git grep 'RuleQuery.*set_rule\|access_control.*INSERT' includes/ admin/` → 0 hits (no DB writes to the vendor table from plugin code).

## Phase 7: PR + Memory

- [X] T020 [US1] Force-push replace `feat/042-default-manage-options-access-rule` with the filter-based commits + spec-kit docs; update PR #71 title/body via `gh pr edit`.
- [ ] T021 [US1] After merge, optionally add a `docs/memory/WORKLOG.md` entry ONLY if the "vendor-UI-owns-the-table → prefer runtime defaults over DB writes" pattern proves durable (i.e. reused for a second feature). Single-use → skip.

---

## Ledger

- **Files created (code)**: `includes/AccessControl/TransportPermissionDefault.php`.
- **Files created (tests)**: `tests/phpunit/Includes/AccessControl/TransportPermissionDefaultTest.php` (14 tests, 408 lines), `tests/phpunit/Includes/AccessControl/TransportPermissionRoleMatrixTest.php` (12 tests, 529 lines).
- **Files created (docs)**: `specs/042-default-manage-options-access-rule/{spec,plan,tasks}.md`, `docs/planings-tasks/042-default-manage-options-access-rule.md`.
- **Files modified**: `includes/Main.php` (+6 lines for filter wiring, adjacent to the existing `mcp_adapter_pre_tool_call` registration so the pair is visually obvious), `admin/Partials/ServerTabs/AccessControlTab.php` (+31 lines: 1-line call + new `render_default_policy_notice()` method).
- **Files deleted vs pre-042**: none (branch was reset to main; prior PR #71 DB-seeder commits never merged to main).
- **Total LOC**: ~195 code + ~937 tests + ~500 docs.
- **Test count**: 26 PHPUnit tests + 24 data-provider rows = 50+ assertions across the two-filter, per-server permission stack.
