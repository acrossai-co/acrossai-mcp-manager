# Tasks — F072 Quick Setup Entry Points

**Feature**: [spec.md](./spec.md) · [plan.md](./plan.md)
**Branch**: `072-quick-setup-entry-points`
**Total**: 9 tasks (5 code + 1 optional cleanup + 3 verification/commit)

## Phase 1 — Code changes (parallel-safe across files; T002 and T003 share `Menu.php` so serialize those two)

- [ ] **T001** [P] Edit `admin/Partials/QuickSetup/ActivationRedirect.php` line 106 — replace `first_run=1` with `server=1`. Final URL: `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1`. See plan.md → *Per-file change map → `ActivationRedirect.php`* for the exact diff.

- [ ] **T002** Edit `admin/Partials/Menu.php::plugin_action_links()` — build a second `$quick_setup_link` (pointing at `admin.php?page=<parent>&quick-setup=1&step=1&server=1`) and `array_unshift` it before the existing Settings link so the final order becomes `Settings | Quick Setup | Deactivate | Download`. See plan.md → *Per-file change map → `Menu.php::plugin_action_links()`*.

- [ ] **T003** Edit `admin/Partials/Menu.php::register_submenu()` — append a second `add_submenu_page()` call. Menu-slug is a URL literal (`admin.php?page=<parent>&quick-setup=1&step=1`), render callback is `''`, position is `3` (right after `MCP` @ 2). See plan.md → *Per-file change map → `Menu.php::register_submenu()`*. **Must land in the same file-lock window as T002** — same file.

- [ ] **T004** [P] Edit `admin/Partials/Settings.php::render_servers_table()` — build a second `$quick_setup_url` via `add_query_arg()` (page + `quick-setup=1` + `step=1`, no `server`) and add a second `page-title-action` `<a>` in the existing `printf()` for the header. See plan.md → *Per-file change map → `Settings.php::render_servers_table()`*.

- [ ] **T005** [P] Edit `admin/Partials/MCPServerListTable.php::column_actions()` — after the existing `$quick_links` foreach loop, append one more `<a class="acrossai-quicklink">` with `dashicons-admin-tools` and target `admin.php?page=<parent>&quick-setup=1&step=1&server=<row-id>`. Emit **outside** the loop so the loop stays tab-only (Option A in plan). See plan.md → *Per-file change map → `MCPServerListTable.php::column_actions()`*.

- [ ] **T006** [P, optional — FR-010] Edit `src/js/quick-setup/hooks/useWizardRouter.js` line 78 comment — replace the word `first_run` with `server` so the doc-comment matches the actually-preserved query params. No functional impact.

## Phase 2 — Verification (sequential, after Phase 1)

- [ ] **T007** Run PHPCS on the four touched PHP files. Must exit 0.
  ```
  ./vendor/bin/phpcs admin/Partials/QuickSetup/ActivationRedirect.php \
                     admin/Partials/Menu.php \
                     admin/Partials/Settings.php \
                     admin/Partials/MCPServerListTable.php
  ```

- [ ] **T008** Manual smoke check covering SC-001 → SC-005 from `spec.md`:
  1. **SC-001**: Deactivate + reactivate the plugin from `/wp-admin/plugins.php`. Browser must land on `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1&server=1` with the default server preselected on Step 1. Navigating to Step 2+ must preserve `&server=1`.
  2. **SC-002**: Reload `/wp-admin/plugins.php`. AcrossAI MCP Manager row must read `Settings | Quick Setup | Deactivate | Download`. Clicking **Quick Setup** must open the same URL as SC-001.
  3. **SC-003**: Open the AcrossAI top-level admin menu. `Quick Setup` must appear directly below `MCP`. Clicking it must open `admin.php?page=acrossai_mcp_manager&quick-setup=1&step=1` (no `server` param) — Step 1 opens on the generic server picker.
  4. **SC-004**: Open the MCP Servers list page. Header must read `MCP Servers   [Add New]   [Quick Setup]`. Clicking **Quick Setup** must open the same URL as SC-003.
  5. **SC-005**: On the MCP Servers list, every row's Actions cell must show a fifth `Quick Setup` quicklink pill after `MCP Clients`. Clicking it must open `…&quick-setup=1&step=1&server=<that-row's-id>` with that server preselected in Step 1.
  6. **SC-007 sanity**: Bulk-activate two plugins (this + any other) → confirm the F072 redirect is skipped (bulk-activation guard intact). If a multisite is available, network-activate → confirm the redirect is skipped (network-activation guard intact).

- [ ] **T009** `git commit` the four artifact files (already staged in this planning pass) alongside the Phase 1 code changes.
  Suggested message:
  ```
  feat(quick-setup): add 4 admin entry points + repoint activation redirect (F072)
  ```
  **Do NOT open a PR.** The user asked to hold off on the PR — the branch will sit locally awaiting their signal.

## Dependency Diagram

```
T001 ─┐
T002 → T003 ─┐              (Menu.php serialize)
T004 ─┤       ├→ T007 (PHPCS) → T008 (smoke) → T009 (commit)
T005 ─┤
T006 ─┘ (optional)
```

Every task in Phase 1 targets exactly one file (except T002+T003, which share `Menu.php`). T007 gates Phase 2 and must pass before T008 begins.
