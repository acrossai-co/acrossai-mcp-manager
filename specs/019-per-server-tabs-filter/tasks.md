---

description: "Task list — Feature 019 Third-party filter for per-server tabs"
---

# Tasks: Third-party filter for per-server tabs

**Input**: Design documents from `/specs/019-per-server-tabs-filter/`
**Prerequisites**: `plan.md`, `spec.md`, `quickstart.md`

**Tests**: New PHPUnit tests are added — see Phase 4. Tests use PHPUnit `#[DataProvider]` PHP attributes per BUGS.md B9.

**Organization**: All 4 user stories are P1 and land together in one atomic patch — a companion plugin author needs the filter, the throw safety, and the built-in-preservation guarantee all present to develop against.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel with other [P] tasks in the same phase (different files, no dependencies)
- **[Story]**: US1 add-a-tab / US2 remove-a-tab / US3 throw-isolation / US4 no-regression-for-built-ins
- Paths are absolute per `plan.md` §Project Structure

## Path Conventions

- Plugin root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager`
- All paths below are project-relative

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish the memory-hygiene follow-up + the pre-patch grep baseline that the FR-016 audit at Phase 4 will compare against.

- [X] T001 Note the `DEC-SERVER-TAB-CLASS-HIERARCHY` memory decision (in `docs/memory/DECISIONS.md` if present, or `[[project_feature_013_server_tabs]]` synthesis file otherwise) as SUPPLEMENTED by Feature 019 — the class hierarchy remains authoritative for built-ins, and Feature 019 adds a NEW third-party extension surface without displacing it. The annotation lands via `/speckit-memory-md-capture-from-diff` post-implementation; no code artifact for T001.
- [X] T002 [P] Capture pre-patch grep baseline. Run from plugin root: `grep -rn "acrossai_mcp_manager_server_tabs" admin/ includes/ tests/ > /tmp/019-pre-baseline.txt`. Expected: 0 matches (proving the filter name is a new symbol). If any pre-existing match surfaces, investigate before writing new code — a stale hook of the same name would break the FR-016 audit.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Extend `AbstractServerTab` with the `priority()` method and slot the ten built-ins BEFORE the Registry starts consuming `->priority()` in its sort loop — otherwise `Registry::for_server()` returns unsorted results in the intermediate commit range.

- [X] T003 [US4] Extend `admin/Partials/ServerTabs/AbstractServerTab.php`:
  - Add a non-abstract `public function priority(): int { return 100; }` after the existing `visible_for()` method (around line 68). Docblock notes that the effective tab list is sorted by ascending priority and that third-party entries default to 100, matching the last built-in slot. Cross-reference Feature 019 and the memory decision `DEC-SERVER-TAB-PRIORITY-SLOTS`.
- [X] T004 [P] [US4] Add `public function priority(): int { return 10; }` to `admin/Partials/ServerTabs/OverviewTab.php`.
- [X] T005 [P] [US4] Add `public function priority(): int { return 20; }` to `admin/Partials/ServerTabs/NpmTab.php`.
- [X] T006 [P] [US4] Add `public function priority(): int { return 30; }` to `admin/Partials/ServerTabs/ClientsTab.php`.
- [X] T007 [P] [US4] Add `public function priority(): int { return 40; }` to `admin/Partials/ServerTabs/WpCliTab.php`.
- [X] T008 [P] [US4] Add `public function priority(): int { return 50; }` to `admin/Partials/ServerTabs/ToolsTab.php`.
- [X] T009 [P] [US4] Add `public function priority(): int { return 60; }` to `admin/Partials/ServerTabs/AbilitiesTab.php`.
- [X] T010 [P] [US4] Add `public function priority(): int { return 70; }` to `admin/Partials/ServerTabs/AccessControlTab.php`.
- [X] T011 [P] [US4] Add `public function priority(): int { return 80; }` to `admin/Partials/ServerTabs/McpTrackerTab.php`.
- [X] T012 [P] [US4] Add `public function priority(): int { return 90; }` to `admin/Partials/ServerTabs/UpdateServerTab.php`.
- [X] T013 [P] [US4] Add `public function priority(): int { return 100; }` to `admin/Partials/ServerTabs/DangerZoneTab.php`.

**Checkpoint**: Every built-in has a slotted priority; the abstract has a safe default. `AbstractServerTab->priority()` is callable everywhere it will be consumed.

---

## Phase 3: User Story 1 + 2 + 3 — Filter, adapter, dispatch (Priority: P1) 🎯 MVP

**Goal**: The filter fires; a third-party can add a tab (US1), remove a built-in (US2), and a broken third-party render_callback catches at the tab boundary (US3).

**Independent Test**: Follow `quickstart.md` §Add a tab, §Remove a tab, §Break a tab — all three exhibit the expected behaviour on a local install.

- [X] T014 [US1] [US2] [US3] Create `admin/Partials/ServerTabs/FilteredServerTab.php`. `final class FilteredServerTab extends AbstractServerTab` with a constructor accepting the normalized entry array and setting protected `$entry`. Implements `slug()`, `label()`, `priority()` from `$entry`. `visible_for( array $server )` returns `false` when `current_user_can( (string) $entry['capability'] )` is false, else invokes `$entry['visible_callback']( $server )` if callable and returns `(bool)` of the result (defaults to `true` when no callback); the callback invocation itself is inside `try/catch \Throwable` that `error_log()`s and returns `false`. `render_body( array $server )` invokes `$entry['render_callback']( $server )` inside `try/catch \Throwable`; on catch, `error_log()` a line including the tab slug + exception class + message + file + line, and echo an inline `<div class="notice notice-error inline"><p>...</p></div>` explaining the failure to the operator without exposing the stack trace. Reuse `AbstractServerTab::open_form()` / `nonce_field()` / `close_form()` helpers by keeping them protected in the abstract (already the case; no change needed).
- [X] T015 [US1] [US2] [US4] Refactor `admin/Partials/ServerTabs/Registry.php`:
  - Keep `all_tabs()` verbatim (returns the ten built-in instances in canonical order — FR-011).
  - Add `private function builtin_entries(): array` — walks `$this->all_tabs()` and returns each as a normalized entry array with `_builtin => true`. This is what the filter's initial argument is.
  - Add `public function for_server( array $server ): array` — fires `apply_filters( 'acrossai_mcp_manager_server_tabs', $this->builtin_entries(), $server )`, passes result to `normalize_entries()`, then `hydrate()`, then sorts by `->priority()`. Returns `AbstractServerTab[]`. This is the sole filter-fire site (FR-016).
  - Add `private function normalize_entries( array $raw ): array` — mirrors vendor `TabbedPageRenderer::resolve_tabs()`. For each entry: sanitize_key on slug (drop if empty), require string label + callable render_callback (drop + `_doing_it_wrong` under `WP_DEBUG` if missing), coerce priority to int (default 100), sanitize_key capability (default `manage_options`, empty → default), validate visible_callback is callable or null, first-registration-wins dedup by slug.
  - Add `private function hydrate( array $entries ): array` — walks entries, for each: if `_builtin === true`, look up the class instance via a slug→instance map built from `all_tabs()`; else `new FilteredServerTab( $entry )`.
  - Refactor `visible_tabs( array $server ): array` to call `for_server( $server )` first, then `array_filter` by `visible_for( $server )` — signature and return type unchanged.
  - Refactor `render( string $tab_slug, array $server ): void` to use `for_server( $server )` internally — dispatches third-party tabs identically to built-ins, fallback to first tab unchanged.
- [X] T016 [P] [US1] [US2] Verify no other call site of `Registry::all_tabs()` exists that would bypass the filter. Grep: `grep -rn "Registry::instance().*all_tabs\|Registry\s*::\s*instance()->all_tabs" admin/ includes/`. Any caller that displays tabs to end users MUST be migrated to `visible_tabs()` or `for_server()`. Callers using `all_tabs()` for administrative introspection (e.g. tests) can remain.

**Checkpoint**: Filter fires; third-party tabs display + dispatch; throws are caught; built-ins are still returned by `all_tabs()`.

---

## Phase 4: Verification (Definition-of-Done gates)

- [ ] T017 [P] [US1] [US2] [US3] Extend `tests/phpunit/Admin/ServerTabs/RegistryTest.php` with seven new methods (each using `#[DataProvider]` where applicable per BUGS.md B9):
  1. `test_for_server_fires_filter_with_server_context` — asserts filter fires with the passed `$server` as arg 2.
  2. `test_for_server_returns_builtins_when_no_callback` — 10 tabs, canonical order.
  3. `test_filter_can_add_a_tab` — appends a third-party entry, asserts it appears via `for_server()` and dispatches via `render()`.
  4. `test_filter_can_remove_a_builtin` — filter unsets `mcp-tracker`; assert `for_server()` returns 9 (database source), `render('mcp-tracker', ...)` falls back to first tab.
  5. `test_filter_can_reorder_via_priority` — third-party entry with priority 5 appears first.
  6. `test_malformed_entry_dropped_with_doing_it_wrong_when_debug` — missing `render_callback` → dropped + `_doing_it_wrong` fires.
  7. `test_duplicate_slug_first_registration_wins` — third-party entry with `slug = overview` is dropped; built-in preserved.
- [ ] T018 [P] [US3] Create `tests/phpunit/Admin/ServerTabs/FilteredServerTabTest.php` with:
  1. `test_capability_short_circuits_visible_for` — user without `manage_options` → `visible_for` returns false; callback not invoked.
  2. `test_visible_callback_composed_with_capability` — capability OK but visible_callback returns false → false.
  3. `test_render_body_catches_throwable` — render_callback throws → `error_log` called, inline notice echoed, no propagation.
  4. `test_visible_callback_throwable_treated_as_hidden` — visible_callback throws → returns false + logs.
- [ ] T019 [US1] [US2] [US3] [US4] Run `composer test` — expected: existing tests PASS + 11 new methods PASS (7 in RegistryTest + 4 in FilteredServerTabTest).
- [ ] T020 [P] [US4] Run `composer phpcs` — expected: zero errors on the modified/new files (`Registry.php`, `AbstractServerTab.php`, `FilteredServerTab.php`, the ten built-in tab class files, both test files).
- [ ] T021 [P] [US4] Run `composer phpstan` — expected: level 8, zero errors, no new baseline entries. If a new error surfaces, fix without adding to `phpstan-baseline.neon`.
- [ ] T022 [P] [US1] FR-016 grep audit — `grep -rn "apply_filters( 'acrossai_mcp_manager_server_tabs'" admin/` returns EXACTLY one match (inside `Registry::for_server()`). A second surviving fire is a defect.
- [ ] T023 [US1] [US2] [US3] Manual quickstart. Follow `quickstart.md` §Add a tab and §Remove a tab and §Break a tab end-to-end on the developer's local install. Expected: all three green.
- [ ] T024 [US4] Manual regression. Deactivate any scratch companion plugin from T023, reload the Edit MCP Server page for both a database-source and a plugin-source server. Verify the tab nav bar renders 10 tabs (DB) and 8 tabs (plugin) in canonical order — no visible regression from pre-Feature-019.

**Checkpoint**: All FRs proven. All SCs achievable.

---

## Phase 5: Docs

- [ ] T025 [US1] [US2] [US3] Author `docs/extending-per-server-tabs.md`. Structure: (a) TL;DR — one filter, one entry array, one worked example; (b) Filter contract table — each key, type, default, requirement; (c) Worked example: add a "Notes" tab (with a `render_callback` echoing a WP_List_Table + a form); (d) Worked example: remove a built-in via `unset()`; (e) Worked example: gate a tab by user role via `visible_callback`; (f) Throw-safety guarantee — "your callback can throw and the page won't white-screen"; (g) Cross-reference the vendor `acrossai_settings_tabs` filter model for authors already familiar with it.

---

## Phase 6: Land on `main`

- [ ] T026 Stage the modified/new PHP files, the two new test files, both spec/tasks/plan/quickstart under `specs/019-per-server-tabs-filter/`, `docs/planings-tasks/019-per-server-tabs-filter.md`, and `docs/extending-per-server-tabs.md`. Do NOT stage `.claude/settings.local.json`, `.phpunit.cache/`, or `build/js/abilities.*` artifacts.
- [ ] T027 Commit with `[Spec Kit] Feature 019 — Third-party filter for per-server tabs` heading, following the multi-section commit body shape of features 016/017/018.
- [ ] T028 Push `019-per-server-tabs-filter` to `origin`, then `gh pr create --base main` with the summary + test-plan sections mirroring PR #22's shape.

**Checkpoint**: PR open against `main`.

---

## Dependencies & Ordering

- Phase 1 (T001–T002) → Phase 2 (T003–T013) → Phase 3 (T014–T016) → Phase 4 (T017–T024) → Phase 5 (T025) → Phase 6 (T026–T028).
- T003 blocks T004–T013 (they add `priority()` overrides that must not exist without the base method — otherwise a PHPCS "override method not found in parent" would fire).
- T014 + T015 are sequentially ordered — T015 hydrates entries into `FilteredServerTab` instances, so the class must exist first.
- T017 + T018 (test files) can run in parallel with T020 + T021 + T022 (static gates) — all are read-only against the same source tree.
- T023 + T024 are sequential (share the same browser session).
