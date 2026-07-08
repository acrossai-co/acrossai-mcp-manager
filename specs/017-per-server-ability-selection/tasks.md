---
description: "Task list for Feature 017 — Per-server Ability Selection"
---

# Tasks: Per-server Ability Selection (Feature 017)

**Input**: Design documents from `/specs/017-per-server-ability-selection/`
**Prerequisites**: `plan.md` ✅ · `spec.md` ✅ · `research.md` ✅ · `data-model.md` ✅ · `contracts/rest-api.md` + `contracts/js-hooks.md` ✅ · `quickstart.md` ✅ · `memory-synthesis.md` ✅ · `docs/security-reviews/2026-07-07-017-per-server-ability-selection-plan.md` ✅
**Planning doc**: `docs/planings-tasks/017-per-server-ability-selection.md` (TASK-1..10)

**Tests**: Requested per `spec.md` §Definition of Done — PHPUnit for resolver + Query + REST controller; Jest for `safeApplyFilters` + additive-merge reducers.

**Organization**: Tasks are grouped by user story so each story can be implemented and validated independently. US1 is the MVP — it delivers the operator-visible toggle + enforcement. US2/US3/US5 tests validate cross-cutting invariants that ship as side-effects of the US1 implementation. US4 is a verification-only story (grep gates). US6 (extensibility) ships as a follow-on increment on top of US1.

## Format: `- [x] TID [P?] [Story?] Description with file path`

- **[P]**: Different file, no dep on incomplete tasks → parallelizable.
- **[USn]**: Story tag (US1..US6). Setup / Foundational / Polish tasks omit the tag.
- Every task lists an exact file path.

## Path Conventions

Single WordPress-plugin repo. Paths are relative to the repo root at `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Declare new npm dependencies and wire the new webpack entry so subsequent phases have working build output.

- [x] T001 Add `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/hooks` to `"dependencies"` in `package.json` (skip any already present).
- [x] T002 [P] Add the `'js/abilities': path.resolve( process.cwd(), 'src/js', 'abilities.js' )` entry to `webpack.config.js` under the existing `entry:` map, immediately after the F015 `'js/access-control'` line.
- [x] T003 [P] Create empty scaffold `src/js/abilities.js` with a top-level IIFE + mount guard so `npm run build` succeeds before Phase 3 lands the real UI. Mount div check returns silently when absent.

**Checkpoint**: `npm run build` succeeds; `build/js/abilities.js` + `build/js/abilities.asset.php` exist; no runtime effect yet.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The `MCPServerAbility` BerlinDB module + install lifecycle + uninstall path. Every user story below requires this table to exist.

**⚠️ CRITICAL**: No user story work can begin until Phase 2 is complete.

- [x] T004 [P] Create `includes/Database/MCPServerAbility/Schema.php` extending `\BerlinDB\Database\Kern\Schema` with the six columns (`id`, `server_id`, `ability_slug varchar(191)`, `is_exposed`, `created_at`, `updated_at`) and three indexes (`PRIMARY`, `UNIQUE server_ability`, `KEY server_id`) per `data-model.md` §Columns / §Indexes.
- [x] T005 [P] Create `includes/Database/MCPServerAbility/Row.php` extending `\BerlinDB\Database\Kern\Row` with typed public properties per column and a `to_array()` matching `includes/Database/MCPServer/Row.php` shape.
- [x] T006 Create `includes/Database/MCPServerAbility/Table.php` extending `\BerlinDB\Database\Kern\Table` with `$name = 'acrossai_mcp_server_abilities'`, `$version = '1.0.0'`, `$db_version_key = 'acrossai_mcp_server_abilities_db_version'`, `$schema = Schema::class`, `$global = false`. Override `maybe_upgrade()` verbatim from `includes/Database/MCPServer/Table.php:89-94` (silent phantom-version guard). Use leading-`\` FQN when extending — per DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION.
- [x] T007 Create `includes/Database/MCPServerAbility/Query.php` extending `\BerlinDB\Database\Kern\Query` with singleton `instance()` + private ctor, `$table_alias = 'mcpsa'`, and the bespoke `upsert( int $server_id, string $ability_slug, bool $is_exposed ): bool` helper per `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-1.
- [x] T008 In `includes/Activator.php`, add `use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as MCPServerAbilityTable;` and one line — `MCPServerAbilityTable::instance()->maybe_upgrade();` — immediately after `CliAuthLogTable::instance()->maybe_upgrade();`. No seeder call.
- [x] T009 In `includes/Main.php::bootstrap_database_tables()`, add `\AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table::instance();` — the DEC-BERLINDB-TABLE-REQUEST-BOOT requirement.
- [x] T010 In `uninstall.php`, add `wp_acrossai_mcp_server_abilities` to the existing DROP TABLE list AFTER the `acrossai_mcp_uninstall_delete_data` opt-in short-circuit (DEC-UNINSTALL-OPT-IN-GATE). Also purge the version option `acrossai_mcp_server_abilities_db_version` inside the same gate.
- [x] T011 [P] Create PHPUnit test `tests/phpunit/Database/MCPServerAbility/SchemaTest.php` asserting DDL parity — parse `SHOW CREATE TABLE wp_acrossai_mcp_server_abilities` after `Table::instance()->maybe_upgrade()` and assert the three expected indexes exist verbatim (names + column sets).
- [x] T012 [P] Create PHPUnit test `tests/phpunit/Database/MCPServerAbility/PhantomVersionGuardTest.php` reproducing the F011 test shape — stamp the version option, DROP the physical table, invoke `Table::instance()->maybe_upgrade()`, assert the table returns AND no `error_log`/admin notice fires.

**Checkpoint**: BerlinDB module in place; `SHOW TABLES LIKE 'wp_acrossai_mcp_server_abilities'` returns one row after activation; PHPUnit foundational suite green.

---

## Phase 3: User Story 1 — Choose which abilities a specific MCP server exposes (Priority: P1) 🎯 MVP

**Goal**: The Abilities tab renders an interactive `DataViews` table; admins toggle exposure per row, persist via REST, and connected AI clients that call a hidden ability receive 403 at the MCP boundary.

**Independent Test**: With server_id=1 enabled and at least one MCP-public ability registered, load `?page=acrossai_mcp_manager&action=edit&server=1&tab=abilities`; toggle a row's exposure off; reload — state persists; call the ability from a connected client (or `curl` the MCP endpoint) — receive a 403 with `acrossai_mcp_ability_not_exposed`. See `quickstart.md` §Manual UI walkthrough + §Extensibility smoke test.

### Tests for User Story 1 (write FIRST, ensure they FAIL before impl)

- [x] T013 [P] [US1] Create `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php` asserting: (a) row with `is_exposed=1` overrides `meta[mcp][public]=false`, (b) row with `is_exposed=0` overrides `meta[mcp][public]=true`, (c) no row + `meta[mcp][public]=true` returns true, (d) no row + falsy meta returns false, (e) per-request static cache returns the same instance on second call without a second DB query.
- [x] T014 [P] [US1] Create `tests/phpunit/REST/AbilitiesControllerGetTest.php` for the GET route — assert 200 payload shape (`has_abilities_api` + `abilities[]` with the seven built-in keys); 404 on unknown `server_id`; 403 for unauth; `has_abilities_api: false` when `wp_get_abilities` absent (mock the function).
- [x] T015 [P] [US1] Create `tests/phpunit/REST/AbilitiesControllerPostTest.php` for the POST route — assert successful batch upsert returns refreshed merged list; 400 with `acrossai_mcp_invalid_payload` on unknown slug (verify **zero** DB rows written); 400 on missing/malformed `abilities` array; 404 on unknown `server_id`.
- [x] T016 [P] [US1] Create `tests/phpunit/Database/MCPServerAbility/QueryUpsertTest.php` — insert path (no row → INSERT), update path (row exists → UPDATE), returns `true` on success.
- [x] T017 [P] [US1] Create `tests/phpunit/MCP/AbilityExposureGateTest.php` for the call-time enforcement callback — (a) returns input `$result` unchanged when `is_exposed=true`, (b) returns `WP_Error( 'acrossai_mcp_ability_not_exposed', ..., 403 )` when `is_exposed=false`, (c) propagates an existing `WP_Error` `$result` unchanged (never overrides F015 deny with allow), (d) fail-open when server id unresolvable.
- [x] T018 [P] [US1] Create `tests/jest/abilities/App.test.js` — render `<App />` with a mocked `apiFetch`, assert (a) loading Spinner while fetch in-flight, (b) error Notice on rejection, (c) DataViews rendered with the six built-in fields, (d) per-row `ToggleControl` change triggers a POST with the correct `{ slug, is_exposed }` payload, (e) bulk actions Expose/Hide dispatch a single POST per action.

### Implementation for User Story 1

- [x] T019 [P] [US1] Create `includes/Database/MCPServerAbility/ExposureResolver.php` — `final class` with `private static array $cache = []` and `public static function resolve( int $server_id, string $ability_slug, array $meta ): bool` per `research.md` §D5 and `data-model.md` §Row Shape. A11 pure-service exception — no singleton, no ctor.
- [x] T020 [US1] Create `includes/REST/AbilitiesController.php` — singleton (private ctor, `instance()`), private const `NAMESPACE = 'acrossai-mcp-manager/v1'`, `register_routes()` registering GET + POST at `/servers/(?P<server_id>\d+)/abilities`, `permission_check()` gating on `current_user_can('manage_options')` (S2). Implement `get_abilities()` and `post_abilities()` per `contracts/rest-api.md`. NO `__return_true` anywhere. (Note: initial draft included an `acrossai_mcp_ability_row` PHP filter — retired Session 2026-07-08 per FR-027 RETIRED; server-side row-filter reintroduction tracked in a separate issue.)
- [x] T021 [US1] Fire the `acrossai_mcp_ability_exposure_changed` action inside `post_abilities()` per FR-024 — read `ExposureResolver::resolve()` BEFORE and AFTER `Query::upsert()`; fire only when the effective value changed. Include the SEC-004 caveat in the docblock verbatim from `contracts/rest-api.md` §Side effects.
- [x] T022 [US1] Create `includes/MCP/AbilityExposureGate.php` (or fold into the REST controller — final placement decision made here). Singleton or A11 pure-service. Implement `gate_tool_call_by_exposure( $result, array $args, string $tool_name, $mcp_tool )` per `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-10 — early-`return $result` if `is_wp_error()`, resolve `$server_id` from `$mcp_tool` (matching F015 accessor), fail-open on unresolvable id, call `ExposureResolver::resolve()`, return `WP_Error( 'acrossai_mcp_ability_not_exposed', ..., array( 'status' => 403 ) )` when false.
- [x] T023 [US1] In `includes/Main.php::define_admin_hooks()`, register the REST controller on `rest_api_init` (matching the F015 pattern immediately after the `MCP\Controller` block at line 405-406) AND register the exposure gate on `mcp_adapter_pre_tool_call` at **priority 20** (F015 runs at 10 — F017 must run later per FR-030). Both wiring calls go through `$this->loader->add_action` / `$this->loader->add_filter`. Zero add_action calls inside class ctors (A1).
- [x] T024 [US1] Rewrite `admin/Partials/ServerTabs/AbilitiesTab.php::render_body()` per `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-5 — keep the two guard branches (server disabled / API absent) with unchanged wording; replace the rest with the mount `<div id="acrossai-mcp-abilities-root" data-server-id data-server-slug>`. Delete `partition_abilities()`, `render_public_table()`, `render_private_table()` in the same commit.
- [x] T025 [US1] Implement the full React app in `src/js/abilities.js` per `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-6 — imports from `@wordpress/{element,api-fetch,i18n,dataviews,components,hooks}`, `apiFetch.createNonceMiddleware`, `<App />` state (items/loading/error/view), `useEffect` GET, `useMemo` fields with the six built-in ids, `useMemo` actions with `expose`/`hide` bulk callbacks, `saveOne`/`saveMany` helpers, live "N of M exposed" header with `_n()` pluralization, error `<Notice>` boundary. Column render uses `createElement` (never `dangerouslySetInnerHTML`).
- [x] T026 [US1] In `admin/Main.php`, add private method `maybe_enqueue_abilities_app()` mirroring the F015 `maybe_enqueue_access_control_app()` shape at lines 136-199 verbatim: guard `?action=edit` + `?tab=abilities` via `sanitize_key( wp_unslash( $_GET[…] ) )` with phpcs pragma comments; `read_asset_manifest( 'build/js/abilities.asset.php' )` with silent bail on null; enqueue `js/abilities.js` + optional matching `.css` on handle `acrossai-mcp-manager-abilities`; `wp_localize_script(…, 'acrossaiMcpAbilities', [ serverId, serverSlug, restApiRoot: untrailingslashit(rest_url()) (B17 defense), nonce: wp_create_nonce('wp_rest'), namespace: 'acrossai-mcp-manager/v1' ])`. Server slug lookup via `MCPServer\Query::instance()`.
- [x] T027 [US1] In `admin/Main.php::enqueue_scripts()`, call `$this->maybe_enqueue_abilities_app();` immediately after the existing `$this->maybe_enqueue_access_control_app();` at line 127.
- [ ] T028 [US1] Run the full US1 verification path from `quickstart.md`: fresh activate, table exists, phantom-version guard passes, `curl` GET/POST/403/404/400 return expected shapes, tab renders, per-row toggle persists, bulk actions work, live counter updates. Zero `wp-content/debug.log` entries during interaction.

**Checkpoint**: User Story 1 is fully functional. Admin can toggle exposure per server via UI + REST; connected AI clients are blocked at the tool-call boundary for hidden abilities.

---

## Phase 4: User Story 2 — Fall back to `meta[mcp][public]` when no row exists (Priority: P1)

**Goal**: An unmigrated install shows and enforces the SAME exposure as before F017 landed — the resolver's absence-of-row fallback preserves the pre-feature UX end-to-end.

**Independent Test**: With `wp db query "TRUNCATE TABLE wp_acrossai_mcp_server_abilities"`, load the Abilities tab and confirm every ability whose `meta[mcp][public]` is truthy shows `is_exposed=true`; every ability with falsy meta shows `is_exposed=false`; all rows show `has_override=false`; `curl` the MCP endpoint and confirm a connected client sees exactly the same exposure as it did pre-F017.

- [x] T029 [P] [US2] Extend `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php` (from T013) with a `test_falls_back_to_meta_when_no_row_exists()` case — insert zero rows for the target `(server_id, slug)`, seed `meta['mcp']['public'] = true`, assert `resolve()` returns true; flip meta to false, assert returns false. Explicitly asserts FR-007 invariant.
- [x] T030 [P] [US2] Create `tests/phpunit/REST/AbilitiesControllerFallbackTest.php` — GET response for a server with zero rows in the new table MUST list every registered ability with `has_override=false` and `is_exposed` matching the ability's own `meta[mcp][public]`.
- [ ] T031 [US2] Manual regression test — before merging, on a staging site with existing `mcp.public=true` abilities toggled ON via the sibling `acrossai-abilities-manager` plugin, deactivate + reactivate this plugin to trigger the F017 activator, then verify AI clients still see the same exposure they saw before. Documents SC-009.

**Checkpoint**: US2 invariant (zero visible change on upgrade) provably holds via automated + manual test.

---

## Phase 5: User Story 3 — REST endpoints power the UI and are safely gated (Priority: P1)

**Goal**: Both REST routes are inaccessible to non-admins, return the right error codes for all defined edge cases, and refuse to write unregistered slugs.

**Independent Test**: `curl -H 'X-WP-Nonce: <bad>' /wp-json/acrossai-mcp-manager/v1/servers/1/abilities` → 403. Same with no nonce → 403. Same with an editor-role user → 403. POST with a garbage slug → 400 + zero rows written. GET on `/servers/9999/abilities` → 404.

- [x] T032 [P] [US3] Extend `tests/phpunit/REST/AbilitiesControllerGetTest.php` (from T014) with role-based auth cases — `subscriber`, `contributor`, `author`, `editor` roles all receive 403; only `administrator` sees 200. Explicit S2 assertion — NO test may pass through `__return_true`.
- [x] T033 [P] [US3] Extend `tests/phpunit/REST/AbilitiesControllerPostTest.php` (from T015) — nonce-missing request returns 403 (wp_rest_missing_nonce). Same for a malformed nonce.
- [x] T034 [P] [US3] Add a `tests/phpunit/REST/AbilitiesControllerPayloadValidationTest.php` — assert POST rejects `{"abilities":[]}` (empty), `{"abilities":"not-an-array"}`, `{"abilities":[{"slug":123,"is_exposed":true}]}` (non-string slug), `{"abilities":[{"slug":"core/get-user-info"}]}` (missing is_exposed).
- [ ] T035 [US3] Manual security sweep — from an incognito browser, hit both routes without auth cookie; confirm 403 pages. From an authenticated editor account, confirm 403. From an admin, confirm 200/400 as appropriate.
- [x] T036 [US3] `grep -rEn 'permission_callback.*__return_true' includes/REST/AbilitiesController.php` must return **zero matches**.

**Checkpoint**: US3 auth surface hardened; every FR-011/FR-012/FR-013 error path exercised.

---

## Phase 6: User Story 4 — Bundle stays lean with `@wordpress/*` packages only (Priority: P2)

**Goal**: The abilities-tab JS bundle contains zero bytes from generic React libraries.

**Independent Test**: `grep -rEn 'react-query|@tanstack|redux|mobx|react-table|@mui/|styled-components' src/js/` returns **zero matches**; `npm run build` succeeds and the produced `build/js/abilities.js` bundle size is comparable to the F015 access-control bundle within one order of magnitude.

- [x] T037 [P] [US4] Add a repo-root check script or CI grep step (whichever the plugin's existing infra uses — inspect `package.json` `scripts` for precedent) that runs the SC-008 grep gate on every build. If no precedent exists, add a `scripts/audit-js-packages.sh` invoked from `npm run validate-packages`.
- [x] T038 [P] [US4] Audit `package.json` — confirm the six new `@wordpress/*` deps declared under `"dependencies"` (T001); confirm **no** `react-query`, `@tanstack/*`, `redux`, `mobx`, `react-table`, `@mui/*`, or `styled-components` present anywhere in `dependencies` or `devDependencies`.
- [ ] T039 [US4] Compare `build/js/abilities.js` size vs `build/js/access-control.js` size after `npm run build` — record both in `specs/017-per-server-ability-selection/bundle-size.txt` (created inline). Fail if `abilities.js` is >5× the F015 baseline.

**Checkpoint**: WP-packages-only invariant automated + measurable.

---

## Phase 7: User Story 5 — Feature is scoped to the Abilities tab only (Priority: P2)

**Goal**: The abilities-tab script never loads on unrelated screens.

**Independent Test**: Visit the plugin list page, Overview tab, Access Control tab, and MCP Tracker tab — DevTools Network shows **zero** `build/js/abilities.js` requests. Visit the Abilities tab — exactly one request appears.

- [ ] T040 [P] [US5] Create `tests/phpunit/Admin/EnqueueScopeTest.php` — mock `$_GET['action']` + `$_GET['tab']` combinations, call `Admin\Main::instance()->enqueue_scripts()`, assert `wp_script_is( 'acrossai-mcp-manager-abilities' )` returns true ONLY when both `action=edit` and `tab=abilities` are present.
- [ ] T041 [P] [US5] Manual DevTools sweep per `quickstart.md` §Manual UI walkthrough (User Story 5 — enqueue scope). Capture screenshots or a Network HAR into `specs/017-per-server-ability-selection/artifacts/us5-enqueue-scope.har` (create the dir).
- [x] T042 [US5] `grep -n 'maybe_enqueue_abilities_app' admin/Main.php` must return exactly TWO lines — the method definition and the single call site inside `enqueue_scripts()`.

**Checkpoint**: US5 enqueue-scope invariant provably holds.

---

## Phase 8: User Story 6 — Third-party plugin adds columns and per-row actions (Priority: P2)

**Goal**: Companion plugins can add columns + row actions via `@wordpress/hooks` + one PHP filter, without touching this plugin's source. Built-in fields cannot be overwritten; failing extensions never white-screen.

**Independent Test**: Ship the `hello-abilities-ext` helper from `quickstart.md` §Extensibility smoke test — confirm "Action" column with "Edit" button appears; clicking Edit shows the alert including the PHP-added `hello_extra` value. Swap in the throwing filter — tab still renders built-ins, console shows one `[acrossai-mcp-manager] filter "..." threw:` line, no white-screen.

### Tests for User Story 6

- [x] T043 [P] [US6] Create `tests/jest/abilities/safeApplyFilters.test.js` — assert (a) callback throws → returns input, `console.error` called exactly once; (b) callback returns non-array for a `.fields` name → returns input; (c) callback returns non-object for `.row` → returns input; (d) callback returns valid array/object → returns callback's return.
- [x] T044 [P] [US6] Create `tests/jest/abilities/additiveMerge.test.js` — register a filter that removes `is_exposed` from the fields array; assert the built-in `is_exposed` field is still present in the final render. Same for actions (`expose`/`hide`). Same for row keys (built-in keys survive a JS row filter that returns `{ slug: 'x' }`).
- [~] T045 [P] [US6] ~~Create `tests/phpunit/REST/AbilitiesRowFilterTest.php`~~ — **RETIRED Session 2026-07-08.** The `acrossai_mcp_ability_row` PHP filter it tested was removed during the `@wordpress/abilities` refactor (FR-027 RETIRED). Server-side row-filter reintroduction is tracked in a separate issue.
- [~] T046 [P] [US6] ~~Add `tests/phpunit/REST/AbilitiesRowFilterArgumentOrderTest.php`~~ — **RETIRED Session 2026-07-08** with T045. The `array_merge` invariant it protected no longer exists (`build_row()` was removed from `AbilitiesController`). Deferred to the separate issue that reintroduces the filter.

### Implementation for User Story 6

- [x] T047 [US6] Update `src/js/abilities.js` per `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-6 additions — import `applyFilters` from `@wordpress/hooks`; add `safeApplyFilters( name, value )` helper with try/catch + type-guard; add `decorateRow( item )` + `useMemo` `decoratedItems`; add `useMemo` `finalFields` + `finalActions` with the additive-only reducer (`builtinIds` Set + `.filter( f => ! builtinIds.has( f.id ) )`); pass `decoratedItems` / `finalFields` / `finalActions` to `<DataViews>`.
- [~] T048 [US6] ~~In `includes/REST/AbilitiesController.php::get_abilities()`, add the `apply_filters( 'acrossai_mcp_ability_row', ... )` call~~ — **RETIRED Session 2026-07-08.** The filter and its call site were removed during the `@wordpress/abilities` refactor (FR-027 RETIRED). Server-side row-filter reintroduction is tracked in a separate issue.
- [x] T049 [P] [US6] Add stability-marker docblocks (`@since 0.0.10 @experimental May change without notice before 1.0.0` — DEC-CLIENT-RENDERER-PUBLIC-API precedent) on: the `acrossai_mcp_ability_exposure_changed` action's `do_action()` call site (SEC-004 concurrency caveat included) and each of the three JS filter call sites in `src/js/abilities.js`. (The `acrossai_mcp_ability_row` filter's docblock is deferred with T048 to a separate issue.)
- [x] T050 [P] [US6] Create `docs/extending-abilities-tab.md` — companion-plugin author guide per `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-9. Sections: "What you get out of the box", "Client filters" with the worked "Edit button" example matching `quickstart.md` §Extensibility smoke test, "Invariants" listing built-in field/action/row keys that extensions cannot overwrite, "Cross-plugin coordination" linking to `[[DEC-CLIENT-RENDERER-PUBLIC-API]]`, plus explicit trust contract per SEC-002 ("**Extensions MUST escape their own values before use**; prefer `createElement( 'span', {}, value )` over `dangerouslySetInnerHTML`; use `wp_kses_post()` for HTML on the PHP side"), performance contract per SEC-005 ("callbacks fire once per ability on every GET — MUST be O(1) with respect to network/disk/DB work"), and error-message contract per SEC-006 ("do NOT include secrets in errors your callbacks throw; `safeApplyFilters` surfaces raw error messages to the browser console"). (Note: the "Server filter" section was deferred with FR-027 to a separate issue.)
- [x] T051 [P] [US6] Fold the two failing-filter smoke tests from `quickstart.md` §Extensibility smoke test into the docs as a "Verify your integration" section at the bottom of `docs/extending-abilities-tab.md`.
- [x] T052 [US6] Grep-gate verification — `grep -rEn 'acrossaiMcpManager\.abilities\.(fields|actions|row)' src/js/` returns exactly THREE matches (one per filter name). (The `acrossai_mcp_ability_row` PHP-filter grep was retired Session 2026-07-08 with FR-027 — reintroduction tracked separately.)
- [ ] T053 [US6] Ship the `hello-abilities-ext` smoke plugin from `quickstart.md` as a copy-paste block inside `docs/extending-abilities-tab.md`; verify it runs on a fresh local install without modification.

**Checkpoint**: US6 extensibility surface is functional, documented, and defensively bounded.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Release-ready changelog, memory updates, and final full-repo audits.

- [x] T054 [P] Add the F017 Unreleased changelog bullet to `README.txt` per `docs/planings-tasks/017-per-server-ability-selection.md` §TASK-8.
- [x] T055 [P] Add `DEC-ABILITY-OVERRIDE-RESOLUTION` and `DEC-WP-DATAVIEWS-OVER-REACT` entries to `docs/memory/DECISIONS.md` with full body per the planning doc; mark both `Active (Feature 017)`.
- [x] T056 [P] Add the two DEC-* rows to `docs/memory/INDEX.md` §Active Decisions.
- [x] T057 [P] Append a Feature 017 milestone entry to `docs/memory/WORKLOG.md` (Why durable / Future mistake prevented / Evidence / Where to look) — highlight the durable lesson: "per-resource overrides that fall back to a global default belong in a single shared resolver, not duplicated across every consumer" + "call-time enforcement of stored per-server overrides rides the same `mcp_adapter_pre_tool_call` shape as F015 access control (D18); wire at priority 20 so a hidden verdict supersedes any earlier allow."
- [x] T058 [P] Add the WORKLOG row to `docs/memory/INDEX.md` §Worklog Entries.
- [x] T059 [P] Append a row for `017-per-server-ability-selection.md` to the Feature Specs table in `docs/planings-tasks/README.md`.
- [x] T060 [P] Append the F017 plan-review row to `docs/memory/INDEX.md` §Security Reviews — `| docs/security-reviews/2026-07-07-017-per-server-ability-selection-plan.md | plan | 2026-07-07 | HIGH | C:0 H:1 M:1 L:2 | A01,A03,A05,A09 |`.
- [x] T061 Run the full-repo audit greps from `docs/planings-tasks/017-per-server-ability-selection.md` §Final full-repo audit — `react-query|@tanstack|redux|mobx|react-table|styled-components|@mui/` under `src/js/` returns ZERO; `partition_abilities|render_public_table|render_private_table` under PHP returns ZERO; `mcp_adapter_pre_tool_call` under `includes/` returns TWO (F015 wiring + F017 wiring). (The `acrossai_mcp_ability_row` grep was retired Session 2026-07-08 with FR-027 — reintroduction tracked separately.)
- [ ] T062 Run the quality gate suite: `composer run phpcs`, `composer run phpstan`, `npm run lint:js`, `vendor/bin/phpunit tests/phpunit/`, `npm test -- --testPathPattern abilities`, `npm run validate-packages`, `npm run build`. ALL must return zero errors.
- [x] T063 Post-implement, run `/speckit-analyze` to catch drift between the shipping code and the spec (per WORKLOG 2026-07-04 F015 lesson — three Session-2026-07-07 clarifications + one mid-plan scope-add for TASK-10 require a post-implement audit). **Ran 2026-07-07 (Delta Run 1) and 2026-07-08 (Delta Run 2). Spec.md updated Session 2026-07-08 to codify the shipped shape: FR-009/014 (split-source REST envelope), FR-016 (widened package list), FR-027 (RETIRED — JS-only extensibility), FR-031 (Enable/Disable All), FR-032 (pagination + cross-page selection), FR-033 (exposure filter), FR-034 (EXCLUDED_SLUGS), FR-035 (client-store fallback). New SC-013, SC-014. PHP version fixed to 8.1+.**

---

## Phase 10: Post-Implement Follow-Up Tasks (added Session 2026-07-08)

**Purpose**: Cover the FRs added during the post-implement /speckit-analyze so nothing ships without at least one verification touch point.

- [ ] T064 [US1] SC-004 performance benchmark — seed a WP-Env instance with 100 registered abilities, `curl` the READ endpoint (with `?include_abilities=1` — worst case) 10 times, record p95 latency in `specs/017-per-server-ability-selection/perf.txt`. Fail if p95 exceeds 1000 ms.
- [ ] T065 [US1] SC-013 EXCLUDED_SLUGS invariant — `grep -n "mcp-adapter/discover-abilities\|mcp-adapter/get-ability-info\|mcp-adapter/execute-ability" src/js/abilities.js` MUST return three lines inside the `EXCLUDED_SLUGS` Set literal. Manual sweep: load the tab and confirm none of the three slugs render in the table.
- [ ] T066 [US1] SC-014 client-store fallback smoke test — temporarily disable the `@wordpress/abilities` script handle (e.g. `wp_deregister_script('wp-abilities')` on a WP-Env), reload the tab, confirm the client falls back to `?include_abilities=1` and the table populates from the REST envelope.
- [ ] T067 [US1] Manual smoke test for FR-031 — click Enable All → confirm all `is_exposed=true` after the write. Click Disable All → confirm all `is_exposed=false`. Verify counter reflects the change.
- [ ] T068 [US1] Manual smoke test for FR-032 — select rows on page 1, navigate to page 2 via `›`, select more rows there, click Expose selected. Confirm the REST POST payload contains slugs from BOTH pages.
- [ ] T069 [US1] Manual smoke test for FR-033 — pick "Only exposed" → confirm the table shows only enabled rows and the header counter (`N of M exposed`) stays at the pre-filter values.
- [ ] T070 [US1] Manual smoke test for FR-034 — with all filters cleared, confirm the three `mcp-adapter/*` slugs never appear in the rendered table.
- [ ] T071 Update `docs/planings-tasks/017-per-server-ability-selection.md` and `contracts/rest-api.md` to reflect the retirement of FR-027 and the new split-source REST envelope so the design docs match spec + shipping code.

**Checkpoint**: All gates green; feature ready for `/speckit-git-commit` + PR to `feature/issue-3`.

---

## Dependencies & Execution Order

### Phase dependencies

| Phase | Depends on | Blocks |
|---|---|---|
| Phase 1: Setup | — | Phase 3+ (needs `js/abilities` entry to build) |
| Phase 2: Foundational | Phase 1 | All user stories (needs the physical table + Query API) |
| Phase 3: US1 (MVP) | Phase 2 | Phases 4, 5, 7 (validate US1 outputs); Phase 8 lands on top of US1's React app |
| Phase 4: US2 | Phase 3 | — |
| Phase 5: US3 | Phase 3 | — |
| Phase 6: US4 | Phase 1 + package.json final state | — |
| Phase 7: US5 | Phase 3 (enqueue guard lands in US1) | — |
| Phase 8: US6 | Phase 3 | Phase 9 |
| Phase 9: Polish | All prior | — |

### User story dependencies

- **US1** stands alone once Phase 2 is complete — delivers the MVP toggle + enforcement.
- **US2/US3/US5** are invariant-verification stories that ride on US1's implementation — their tests are the "receipts" that US1 shipped correctly.
- **US4** is a build-pipeline verification story, independent of US1's runtime.
- **US6** is a strict superset — the extensibility filters wire into the US1 React app + REST controller but don't change their behavior when no filter is registered.

### Within each phase

- Tests (T013–T018 for US1, etc.) MUST be written FIRST and observed to fail before the implementation tasks land — TDD invariant per the template.
- BerlinDB module: `Schema` + `Row` (T004, T005 parallel) → `Table` (T006) → `Query` (T007) — Table + Query depend on Schema being final.
- REST controller: `ExposureResolver` (T019) MUST land before the REST controller (T020) that consumes it.
- React app: `abilities.js` scaffold from T003 → full app in T025 → extensibility filters in T047. Each step is idempotent-safe against the prior.

### Parallel opportunities

**Phase 1**: T002 + T003 in parallel after T001 lands.

**Phase 2**: T004 + T005 in parallel; T011 + T012 in parallel once T004–T010 complete.

**Phase 3 tests**: T013, T014, T015, T016, T017, T018 all in parallel (six different test files).

**Phase 3 impl**: T019 (Resolver) blocks T020 (Controller). T022 (Gate) blocks T023 (wiring). T024, T025, T026 in parallel (Tab body, React app, Enqueue guard — three different files).

**Phase 8 tests**: T043, T044, T045, T046 in parallel.

**Phase 9**: T054–T060 all in parallel (independent doc + memory files).

---

## Parallel Example: User Story 1

```bash
# Launch all Phase 3 tests together (six different files):
Task: "T013 Create tests/phpunit/Database/MCPServerAbility/ExposureResolverTest.php"
Task: "T014 Create tests/phpunit/REST/AbilitiesControllerGetTest.php"
Task: "T015 Create tests/phpunit/REST/AbilitiesControllerPostTest.php"
Task: "T016 Create tests/phpunit/Database/MCPServerAbility/QueryUpsertTest.php"
Task: "T017 Create tests/phpunit/MCP/AbilityExposureGateTest.php"
Task: "T018 Create tests/jest/abilities/App.test.js"

# Then the parallel impl fan-out after Resolver + Controller land:
Task: "T024 Rewrite AbilitiesTab.php::render_body()"
Task: "T025 Implement src/js/abilities.js React app"
Task: "T026 Add maybe_enqueue_abilities_app() to admin/Main.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Phase 1 (Setup) → Phase 2 (Foundational) → Phase 3 (US1).
2. **Stop and validate**: run `quickstart.md` §Manual UI walkthrough (User Story 1) + §REST API smoke test.
3. If green, US1 alone is a shippable increment — operators can toggle exposure per server AND the tool boundary enforces it. US2/US3/US5 tests are the correctness receipts; US4/US6 land in the same PR but aren't blockers for MVP behavior.

### Incremental delivery

1. Phase 1 + Phase 2 → foundation ready (no operator-visible change).
2. Add Phase 3 (US1) → **MVP shipped**.
3. Add Phase 4 (US2) → fallback tests documented.
4. Add Phase 5 (US3) → auth surface hardened + documented.
5. Add Phase 6 (US4) → WP-packages-only gate wired into `npm run validate-packages`.
6. Add Phase 7 (US5) → enqueue scope test + Network HAR captured.
7. Add Phase 8 (US6) → extensibility filters + docs — companion plugins can now integrate.
8. Add Phase 9 (Polish) → README + memory + final gates.

### Parallel team strategy

- Dev A owns the BerlinDB module (Phase 2) → US1 backend (T019–T023).
- Dev B owns the React app + tab body (T024–T027) once T019 lands.
- Dev C owns the extensibility surface (Phase 8) — starts after T025 exposes the React app boot.
- Dev D handles Polish (Phase 9) once Phases 3+ are complete.

---

## Notes

- Every test task (T011–T018, T029, T030, T032–T034, T040, T043–T046) MUST be written to FAIL before its paired implementation task lands. Verify with a failing test run before proceeding.
- The four full-repo audit greps in T061 are the merge gate — a single non-zero match on any of them blocks the PR.
- SEC-001 (call-time enforcement) is closed by T017 + T022 + T023 — the highest-priority security review finding is resolved in the MVP phase, not deferred.
- SEC-002/SEC-005/SEC-006 (extension trust/perf/error contracts) land in T050 (docs) — no PR-blocking code change required.
- SEC-003 (array_merge arg order) is captured as a dedicated regression test (T046) plus the invariant comment inside T020 — both must be present.
- SEC-004 (concurrency observability caveat) is captured as a docblock addition in T021 — no code change beyond the comment.
- Commit after each logical group (e.g., Phase 2 complete → single commit; Phase 3 tests → commit; Phase 3 impl → commit) so `/speckit-git-commit` produces meaningful history.
- Post-implement, invoke `/speckit-analyze` before opening the PR (T063) — memory F015 lesson.
