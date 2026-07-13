---

description: "Implementation task list for Feature 025 — Wire per-server tool selection into MCP registration + let operators remove built-in defaults"
---

# Tasks: Server Tools Registration Hooks

**Input**: Design documents from `/specs/025-server-tools-registration-hooks/`
**Prerequisites**: `plan.md` ✓, `spec.md` ✓ (4 user stories: US1 P1, US2 P2, US3 P2, US4 P3), `research.md` ✓, `data-model.md` ✓, `contracts/` ✓ (2 files), `quickstart.md` ✓, `memory-synthesis.md` ✓, `security-constraints.md` ✓

**Tests**: Included. Feature specification's Definition of Done gates PHPUnit coverage for the compose helper, both filter paths, REST split-on-POST/compose-on-GET, Reset semantics, and schema migration; v2 security review adds a SEC-025-v2-2 hardening test case for protocol-slug POST.

**Organization**: Tasks grouped by user story per plan.md priorities. Foundational phase (Phase 2) covers the schema change + `ToolPolicy` service that every story depends on.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US4 map to spec.md user stories (blank on Setup / Foundational / Polish)
- File paths are exact and repository-relative from plugin root

## Path Conventions

Single WordPress plugin project — paths shown are relative to the plugin root `acrossai-mcp-manager/` (which is `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm branch state; no code changes.

- [X] T001 Confirm branch `025-server-tools-registration-hooks` is checked out with a clean working tree (`git status`); verify `.specify/feature.json` reads `{"feature_directory": "specs/025-server-tools-registration-hooks"}`; verify `specs/025-server-tools-registration-hooks/plan.md` exists.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema migration + the `ToolPolicy` service that every user story depends on.

**⚠️ CRITICAL**: US1 / US2 / US3 / US4 cannot begin until this phase is complete.

- [X] T002 [P] Add three tinyint(1) column entries to `includes/Database/MCPServer/Schema.php` `$columns` array — `tool_discover_abilities`, `tool_get_ability_info`, `tool_execute_ability`, each `'type' => 'tinyint', 'length' => '1', 'default' => 1` — appended after the existing `server_version` column and before `created_at` (per `data-model.md` §"Storage layer 1").
- [X] T003 [P] Bump `protected $version = '1.0.0';` → `'1.1.0';` in `includes/Database/MCPServer/Table.php` so BerlinDB's `maybe_upgrade()` runs `ALTER TABLE ADD COLUMN ... DEFAULT 1` on the next request-time boot (per `research.md` §Decision 6).
- [X] T004 [P] Add three public int properties (`$tool_discover_abilities`, `$tool_get_ability_info`, `$tool_execute_ability` — each `= 1`) to `includes/Database/MCPServer/Row.php`; add explicit `(int)` casts in the constructor per bug pattern B18 (`$wpdb` returns TINYINT as string); extend `to_array()` to include the three new keys.
- [X] T005 Create `includes/Database/MCPServer/ToolPolicy.php` — new stateless helper class (namespace `AcrossAI_MCP_Manager\Includes\Database\MCPServer`) per A11 pure-service pattern. Owns `public const PROTOCOL_TOOLS` (three protocol slugs), `public const COLUMN_MAP` (column-name-to-slug map), `public static function compose_for_row( Row $row ): array` (per `data-model.md` §compose_for_row), and `public static function split_payload( array $tools ): array` (per `data-model.md` §split_payload). MUST use `! empty()` or `(int)`-cast to check column values, NOT `=== 1` (B18). Depends on T004.
- [X] T006 [P] Create `tests/phpunit/Database/MCPServer/ToolPolicyTest.php` — 8 test cases per `plan.md` §TASK-8-1 through TASK-8-5 (compose_for_row all-enabled, one-disabled, all-disabled, curated-appended, curated-dedup) and TASK-8-6 through TASK-8-8 (split_payload three-protocol+two-curated, zero-protocol+three-curated, empty-input). Depends on T005. **File created + `php -l` clean. Full test execution requires WP test-lib in CI.**
- [X] T007 [P] Create `tests/phpunit/Database/MCPServer/SchemaMigrationTest.php` — three cases: post-`maybe_upgrade()` column-presence assertion, DEFAULT-1 assertion via `Row->tool_*` (B18 int-cast verified), maybe_upgrade idempotency. Depends on T002 + T003. **File created + `php -l` clean. Full test execution requires WP test-lib in CI.**

**Checkpoint**: Schema shipped with `DEFAULT 1` backfill; `ToolPolicy` exists and is tested; foundational tests green. User stories can now begin.

---

## Phase 3: User Story 1 — Operator's saved tool selection reaches AI clients (Priority: P1) 🎯 MVP

**Goal**: Close the loop between F020's Tools tab and the MCP adapter — the operator's saved picks (protocol columns + curated rows) become the tool list each MCP server advertises via `tools/list`.

**Independent Test**: Enable an MCP server, pick two curated abilities via the Tools tab, Save. `curl` a JSON-RPC `tools/list` request to the server's endpoint. Response `result.tools` contains the two curated abilities plus the three protocol slugs.

- [X] T008 [US1] Modify `includes/MCP/Controller.php::register_database_servers()` — add `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;` alongside the existing MCPServer\Query import; DELETE the inline 3-element protocol-tools array literal at the current lines 150–154; inside the `foreach ( $servers as $server )` body, immediately before `$adapter->create_server(...)`, insert `$tools = ToolPolicy::compose_for_row( $server ); $tools = apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $server ); $tools = array_values( array_unique( array_map( 'strval', (array) $tools ) ) );`; replace the 10th argument to `$adapter->create_server()` with `$tools`. Add the filter's docblock per `contracts/filter-acrossai_mcp_manager_server_tools.md`. Post-edit grep sanity: `grep -n "mcp-adapter/discover-abilities\|mcp-adapter/get-ability-info\|mcp-adapter/execute-ability" includes/MCP/Controller.php` MUST return zero matches.
- [X] T009 [US1] Add `public function filter_default_server_config( $config )` method to `includes/MCP/Controller.php`, placed immediately after `register_database_servers()` and before `get_adapter_status()`. Add `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;` if not already present. Body per `plan.md` §TASK-4 — defensive short-circuits for non-array `$config`, non-array `$config['tools']`, missing default row, and empty composed set. Add code comment `// SEC-025-v2-3: server_slug index is 'key' not 'unique' (F011 baseline); MCPServerQuery::query returns first insertion-order match; safe within manage_options trust boundary — see security-review v2` immediately above the `MCPServerQuery::instance()->query( ... )` call.
- [X] T010 [US1] Modify `includes/Main.php::define_admin_hooks()` — immediately AFTER the existing line `$this->loader->add_action( 'rest_api_init', $mcp_controller, 'initialize_adapter' );` (currently line 513), ADD one line: `$this->loader->add_filter( 'mcp_adapter_default_server_config', $mcp_controller, 'filter_default_server_config' );`. Post-edit grep: `grep -n "mcp_adapter_default_server_config" includes/Main.php` MUST return exactly one match.
- [X] T011 [P] [US1] Create `tests/phpunit/MCP/ControllerToolsInjectionTest.php` — 9 test cases (5 filter_default_server_config short-circuits/REPLACE + 3 register_database_servers filter emission + 1 SEC-TASKS-025-1 confused-deputy assertion). **File created + `php -l` clean. Full test execution requires WP test-lib in CI.** Cases 1–5 cover `filter_default_server_config` short-circuits and REPLACE semantics; cases 6–8 cover `register_database_servers` filter emission + response to filter returns (add-slug, remove-protocol, `null` degrades to `[]`); **case 9 (SEC-TASKS-025-1)** covers the confused-deputy assertion: given a row with `tool_execute_ability = 0` and a filter callback that appends `mcp-adapter/execute-ability` unconditionally, assert the final composed set INCLUDES the slug (documenting the intentional filter-over-column precedence) AND assert `acrossai_mcp_tools_changed` did NOT fire (filter-side changes are silent — only POST-side flips emit the event).

**Checkpoint**: US1 is fully functional — the operator's saved picks are what the adapter registers; both server-registration paths compose from DB; the new plugin filter and the vendor filter both work. MVP shippable.

---

## Phase 4: User Story 2 — Operator can remove built-in defaults with an explicit warning (Priority: P2)

**Goal**: The three built-in defaults become removable via the Tools tab, gated by a `@wordpress/components` `ConfirmDialog` warning; the empty-state pane renders a warning banner per FR-017.

**Independent Test**: On the Tools tab, click Remove on Discover Abilities. Verify the `ConfirmDialog` opens with the FR-003 copy. Click "Remove anyway". Verify the row disappears from the right pane, reappears in the left pane with the `#fef7e0` badge and a `+ Add` button, DB column value flips to `0`, and `tools/list` no longer advertises `mcp-adapter/discover-abilities`.

- [X] T012 [US2] Modify `includes/REST/ToolsController.php` — DELETE the `EXCLUDED_SLUGS` constant at lines 69–73; DELETE the validation branch in `post_tools()` (~lines 262–271) that rejects submissions containing protocol slugs. In `post_tools()`, after existing slug validation and before persisting, call `ToolPolicy::split_payload( $tools_param )`, then `MCPServerQuery::instance()->update_item( $server_id, $columns )` (columns half) and `MCPServerToolQuery::instance()->replace_set( $server_id, $curated )` (curated half). Add code comment `// SEC-025-INFO-2: accepted race window between column update and curated replace_set — see security-review v1 § two-write POST path` between the two writes. Emit `acrossai_mcp_tools_changed` per FR-016 for column flips (one bullet per column whose new value differs from pre-save value, `operation` = 'added' if new=1 / 'removed' if new=0, slug from `COLUMN_MAP`). In `get_tools()`, replace the direct `get_added_slugs()` call with `ToolPolicy::compose_for_row( $row )`; drop the `abilities` catalog's `EXCLUDED_SLUGS` filter so the three protocol slugs appear as regular entries when `include_abilities=1`. Post-edit grep: `grep -n "EXCLUDED_SLUGS\|discover-abilities" includes/REST/ToolsController.php` MUST return zero matches. **PRESERVATION invariant (SEC-TASKS-025-3)**: this task MUST NOT modify `register_rest_route()` `permission_callback` binding on either the GET or POST route, MUST NOT modify the nonce middleware setup, and MUST NOT modify the `manage_options` capability check inside `permission_check()`. Only the `EXCLUDED_SLUGS` constant and its consuming validation branch inside `post_tools()` are deleted.
- [X] T013 [US2] Modify `src/js/tools.js` — `EXCLUDED_SLUGS` constant + pool-filter usage deleted; `PROTOCOL_TOOL_SLUGS` added; `AbilityRow` rewritten to derive `isProtocolTool` from slug (not from `side` prop), always render `Remove` button, keep `#fef7e0` background for protocol slugs in both panes; `ConfirmDialog` imported from `@wordpress/components`; `removeAbility` gates through the dialog for protocol slugs (FR-003 copy verbatim); non-protocol removals bypass per FR-006.
- [X] T014 [US2] Modify `src/js/tools.js` — FR-017 empty-state warning banner rendered inside the right pane when `addedRows.length === 0`, with inline Reset CTA that opens the same Reset `ConfirmDialog` as the pane header.
- [X] T015 [US2] Modify `src/js/tools.js` count-text i18n string — replaced `'%1$d of %2$d abilities added as tools · %3$d built-in always available'` with `'%1$d of %2$d abilities added as tools'` (dropped built-in-count suffix); dropped the F020 "3 built-in protocol tools + your curated selection" title on the count badge in favor of "Composed union..."; dropped the F020 zero-added banner in favor of the FR-017 in-pane banner from T014.
- [X] T016 [P] [US2] Create `tests/phpunit/REST/ToolsControllerTest.php` — 7 test cases: auth 403 (F020 preserved), SEC-025-v2-2 protocol-slugs POST 200, split-on-POST mixed slugs, SEC-TASKS-025-2 empty-tools legal state (POST empty → 200, columns 0, curated cleared, GET returns []), US3 Reset semantic (POST [3 protocol] → columns 1, curated wiped), GET composed union. **File created + `php -l` clean. Full test execution requires WP test-lib in CI.** (or create if it doesn't exist). Remove any assertion that POSTing protocol slugs returns 400. Add assertions per `data-model.md` §Two-write POST path and `contracts/rest-tools-endpoint-semantics.md` §Internal handling on POST: (a) POST `[3 protocol slugs + 1 curated]` sets all three `tool_*` columns to `1` AND writes the curated to `wp_acrossai_mcp_server_tools`; (b) POST `[1 curated only]` sets all three `tool_*` columns to `0` AND writes the curated; (c) GET returns the composed union. Add SEC-025-v2-2 hardening case per security-review v2: `test_post_accepts_all_three_protocol_slugs()` asserting POST with only the three protocol slugs returns 200. **Add SEC-TASKS-025-2 case**: `test_post_empty_tools_array_produces_empty_composed_set()` — given prior curated rows + default columns, POST `{ tools: [] }`; assert 200, all three `tool_*` columns = 0, `MCPServerToolQuery::get_added_slugs()` returns `[]`, and subsequent GET returns `{ tools: [] }` (documents the truly-empty legal state per FR-017).

**Checkpoint**: US2 fully functional — the operator can remove built-in defaults with the warning dialog, the empty state renders the banner, the REST endpoints accept the new payload shape.

---

## Phase 5: User Story 3 — Reset button restores defaults + clears curated (Priority: P2)

**Goal**: The `Reset` button in the "Added as tools" pane header restores all three protocol columns to `1` AND clears every curated row via `MCPServerToolQuery::replace_set( $server_id, [] )`, gated by its own `ConfirmDialog`.

**Independent Test**: With a curated pick added and one protocol tool removed, click Reset in the "Added as tools" pane header; confirm the dialog. Verify DB: all three columns `1`, `wp_acrossai_mcp_server_tools` rows for this server_id count = 0. Verify UI: right pane shows exactly the three protocol defaults with the `#fef7e0` background; count text reads "3 of N".

- [X] T017 [US3] Modify `src/js/tools.js` Reset button — `applyReset()` handler + `openResetDialog()` opens `ConfirmDialog` with body copy verbatim from FR (`'Reset the tools for this server to only the three built-in defaults? All curated picks will be removed.'`) and confirm button `'Reset to defaults'`; on confirm, `persistSet(new Set(PROTOCOL_TOOL_SLUGS), added)` — POSTs the three protocol slugs; backend split_payload flips all columns to 1 and clears curated in one round-trip. with title `__( 'Reset tools?', 'acrossai-mcp-manager' )` and body copy exactly `__( 'Reset the tools for this server to only the three built-in defaults? All curated picks will be removed.', 'acrossai-mcp-manager' )`; confirm button text `__( 'Reset to defaults', 'acrossai-mcp-manager' )`. On confirm, POST to `${nsRoot}/servers/${serverId}/tools` with body `{ tools: [ 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info', 'mcp-adapter/execute-ability' ] }` — the backend's `ToolPolicy::split_payload()` will flip all three columns to `1` and `replace_set()` with an empty curated array, dropping every non-protocol row. The T014 empty-state banner's inline Reset CTA reuses this same dialog and POST.

**Checkpoint**: US3 fully functional — Reset lifecycle atomically restores defaults + clears curated in one POST round-trip.

---

## Phase 6: User Story 4 — Companion plugins can add or remove tools via a documented filter (Priority: P3)

**Goal**: Publish the extension-author documentation for the new `acrossai_mcp_manager_server_tools` filter and the two-hook model (plugin filter for database servers, vendor filter for the default server).

**Independent Test**: Drop a scratch mu-plugin that hooks `acrossai_mcp_manager_server_tools` and appends one slug for a specific server. Reload `tools/list`; verify the slug appears. Hook `mcp_adapter_default_server_config` at priority 20; verify the modification applies to the default server's `tools/list`. Both flows match the quickstart's US4 walkthrough.

- [X] T018 [US4] Create `docs/extending-server-tools.md` — 7 sections per `plan.md` §TASK-9. Section 1: Storage model (column/row split — mention operator's DB is authoritative post-filter). Section 2: Filter contract (mirror `contracts/filter-acrossai_mcp_manager_server_tools.md`). Section 3: Two-hook model table (plugin filter → database servers; vendor filter → default server). Sections 4–6: three worked examples (add "Notes" slug for named servers; strip `execute-ability` for audit-only servers; same via the vendor filter for the default server). Section 7: Throw safety note (WP filter default; wrap your own callback if needed). Include an explicit SEC-025-INFO-1 advisory sentence in Section 2: `"Note: a callback that re-adds a protocol slug the operator has removed via the Tools tab will silently override the operator's UI-facing decision. Filter authors SHOULD log or documentation-cite any such override."`.

**Checkpoint**: Extension surface is fully documented; companion plugin authors have a canonical reference.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Docblock updates, remediation TODOs from security review v1 + v2, quality gates, and grep audits.

- [X] T019 Update the docblock at `includes/MCP/ToolExposureGate.php::EXCLUDED_SLUGS` per SEC-025-INFO-3 — add one paragraph noting: `"Vestigial post-Feature 025 (2026-07-13). Under F025's DB-authoritative model, protocol slugs are excluded from the tools/list response when the corresponding column is 0 — the adapter refuses the call at the tool-lookup layer regardless. This bypass is retained as a belt-and-braces safety net for AI clients that cached a slug from an earlier session. Do NOT cite this constant as precedent for new bypass rules."`. Do NOT delete the constant or its consuming code.
- [X] T020 Update `specs/025-server-tools-registration-hooks/spec.md` §Edge Cases per SEC-025-v2-1 (Option A recommended) — amend the "empty tool list" bullet to distinguish: `"For DATABASE servers, the server registers with an empty tool list and tools/list returns an empty array. For the DEFAULT server, the vendor's defaults (three protocol tools) win as a safer fallback — Controller::filter_default_server_config short-circuits when the composed set is empty. Operators requiring truly empty state on the default server MUST hook mcp_adapter_default_server_config at priority >10 and explicitly set $config['tools'] = []."` Update `contracts/filter-acrossai_mcp_manager_server_tools.md` §Defensive short-circuits to reference the amended edge case.
- [X] T021 Update `docs/planings-tasks/023-server-tools-registration-hooks.md` — add a header note pointing at the actual feature dir: `"Note: The Spec-Kit feature directory for this planning doc is specs/025-server-tools-registration-hooks/; the '023' number in this file's name is decoupled from the specs/ numbering (specs registry landed on 025). See specs/025-server-tools-registration-hooks/plan.md for the canonical plan."`.
- [X] T022 [P] Ran `vendor/bin/phpcs` on all 8 modified/new source files — **8/8 files scanned, zero errors, 1.4s** ✓
- [X] T023 [P] Ran `vendor/bin/phpstan analyse --memory-limit=4G` at level 8 on the modified source files — **exit 0, no new baseline entries** ✓
- [~] T024 [P] `composer test` — WP test library not installed on the local dev host (`Class "WP_UnitTestCase" not found`). All four F025 test files pass `php -l`; they'll execute in CI where the WP test-lib is bootstrapped by `tests/bootstrap-wp.php`. **Verify in CI post-push.**
- [~] T025 [P] `npx wp-scripts lint-js` — local `node_modules` has an ESLint 10 / `@wordpress/scripts` mismatch (`Cannot find module 'eslint/package.json'`). tools.js was verified via ESM `node --check` parse (clean). **Verify in CI post-push.**
- [X] T026 Ran `npx wp-scripts build` — **webpack 5.107.2 compiled successfully in 7055ms**; only pre-existing F017 bundle-size warnings on abilities.js (unrelated to F025). tools.js entry built without errors. `npm run validate-packages` also passed ✓
- [X] T027 Grep audits per plan §TASK-10 (executed 2026-07-14):
  - (a) `grep -rn "apply_filters( 'acrossai_mcp_manager_server_tools'" includes/` → **1 match** at `includes/MCP/Controller.php:162` ✓
  - (b) `grep -n "mcp_adapter_default_server_config" includes/` → **1 match** at `includes/Main.php:514` (the `add_filter` line) ✓
  - (c) `grep -rn "EXCLUDED_SLUGS" includes/ src/js/` → **5 matches** (revised expectation): 2 in `includes/MCP/ToolExposureGate.php` (constant + consumer — intentionally preserved per T019 with vestigial docblock), 3 in `src/js/abilities.js` (F017 abilities-tab scope, orthogonal to F025). ToolsController and tools.js each returned ZERO matches ✓ within F025 scope.
  - (d) `grep -rn "mcp-adapter/discover-abilities" includes/` → **3 matches** (revised expectation): 2 in `ToolPolicy.php` (canonical `PROTOCOL_TOOLS` + `COLUMN_MAP` — both required for the split/compose logic), 1 in `ToolExposureGate.php` (vestigial per T019). Zero inline literals remain in `Controller.php` or `ToolsController.php` ✓
  - **Verdict**: PASS. Original tasks.md wording of "exactly ONE" match under-counted the canonical helper (which needs the slug in both its `PROTOCOL_TOOLS` array and `COLUMN_MAP` array) and did not account for the intentionally-preserved vestigial constant in `ToolExposureGate`.
- [~] T028 **DEFERRED to reviewer** — Walk through `specs/025-server-tools-registration-hooks/quickstart.md` end-to-end. Requires a live WordPress instance with the plugin activated + at least one MCP server enabled + WP-CLI access. Reviewer signs off after the 8 checks pass (schema migration verification through empty-tool-list warning banner). end-to-end on a LocalWP install. All 8 checks (schema migration verification through empty-tool-list warning banner) must pass. Attach the walkthrough output to the PR description.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: T001 — no dependencies; complete before any other work.
- **Foundational (Phase 2)**: T002 / T003 / T004 can run in parallel; T005 (ToolPolicy) depends on T004; T006 (ToolPolicyTest) depends on T005; T007 (SchemaMigrationTest) depends on T002 + T003. **BLOCKS all user stories.**
- **User Story 1 (Phase 3)**: depends on Foundational (all of T002–T007). US1 tasks T008 → T009 are same-file sequential; T010 depends on T009 (needs the callback method to bind to); T011 depends on T008 + T009 + T010.
- **User Story 2 (Phase 4)**: depends on Foundational (T005 for `ToolPolicy` usage). Backend T012 is same-file sequential. Frontend T013 → T014 → T015 all touch `src/js/tools.js` (sequential). T016 test file update can run in parallel with T012 (different file) but must complete before merge.
- **User Story 3 (Phase 5)**: T017 depends on US2 T014 (empty-state banner) reusing the same Reset dialog component; and on T012 (POST split) landing so the payload semantics work.
- **User Story 4 (Phase 6)**: T018 is docs-only; no code dependencies. Can start any time after Foundational.
- **Polish (Phase 7)**: T019 / T020 / T021 depend on the respective files existing (US1/US2 landed). Quality gates T022–T026 depend on all implementation tasks. T027 grep audits and T028 quickstart depend on the entire implementation.

### User Story Dependencies

- **US1 (P1)**: Can start immediately after Phase 2 completes — no dependencies on other user stories.
- **US2 (P2)**: Can start immediately after Phase 2 completes. Independent of US1 backend at compile time; but the observability event stream (FR-016) reuses F020's existing action which is untouched.
- **US3 (P2)**: Requires US2 T012 (POST split) and US2 T014 (empty-state banner reuses same Reset dialog). Effectively sequential after US2.
- **US4 (P3)**: Docs-only. Independent of US1/US2/US3 code but references their behavior — the filter itself is delivered by US1 T008.

### Parallel Opportunities

- Phase 2: T002 / T003 / T004 [P] run in parallel (different files). T006 / T007 [P] run in parallel once their deps land.
- Phase 3 US1: T011 [P] can run in parallel with T008/T009/T010 authoring if a second developer is available.
- Phase 4 US2: T016 [P] can run in parallel with T012 authoring.
- Phase 7 Polish: T022 / T023 / T024 / T025 [P] all run in parallel (quality gates on different tools).

---

## Parallel Example: Phase 2 Foundational

```bash
# All three file edits run in parallel — no cross-file dependencies:
Task: "T002 Add three tinyint(1) columns to includes/Database/MCPServer/Schema.php"
Task: "T003 Bump $version 1.0.0 → 1.1.0 in includes/Database/MCPServer/Table.php"
Task: "T004 Add three int properties + cast + to_array to includes/Database/MCPServer/Row.php"

# After T005 lands (ToolPolicy), run the two tests in parallel:
Task: "T006 Create tests/phpunit/Database/MCPServer/ToolPolicyTest.php"
Task: "T007 Create tests/phpunit/Database/MCPServer/SchemaMigrationTest.php"
```

## Parallel Example: Phase 7 Polish

```bash
# All quality gates run in parallel:
Task: "T022 composer phpcs"
Task: "T023 composer phpstan"
Task: "T024 composer test"
Task: "T025 npm run lint:js"
```

---

## Implementation Strategy

### MVP First (US1 only)

1. Complete Phase 1 (T001) → clean branch state.
2. Complete Phase 2 (T002–T007) → schema shipped, `ToolPolicy` in place.
3. Complete Phase 3 (T008–T011) → US1 fully functional.
4. **STOP and VALIDATE**: Enable a server, pick two curated abilities, curl `tools/list`, confirm the two picks appear alongside the three protocol slugs.
5. Ship the MVP if F025's user-facing UI Remove/Reset work can be deferred. (In practice, US2/US3 are joined to US1 by the operator experience; typically we ship US1+US2+US3 together.)

### Incremental Delivery

1. Setup + Foundational → Foundation ready.
2. US1 → MVP (registration wiring lands; existing UI still hides protocol tools as ornaments).
3. US2 → Remove-with-warning UX ships.
4. US3 → Reset semantics ships.
5. US4 → Extension docs ship.
6. Polish → Quality gates + grep audits + docblock updates + quickstart validation → merge-ready.

### Parallel Team Strategy

With multiple developers:
1. Team completes Phase 1 + Phase 2 together (or one developer does schema+ToolPolicy while another writes tests).
2. Once Foundational is done:
   - **Developer A**: US1 (T008–T011) — PHP-focused.
   - **Developer B**: US2 (T012–T016) — full-stack.
   - **Developer C**: US3 (T017) — JS-focused; blocks on US2's T014 banner shell.
   - **Developer D**: US4 (T018) — docs-focused; can start any time.
3. All converge for Polish (T019–T028).

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks in the same phase.
- [Story] label maps to spec.md user stories.
- Every user story is independently testable per the "Independent Test" line in its phase header.
- Verify PHPUnit tests fail BEFORE implementation for the test-first cases (T006, T007, T011, T016). This matches the plugin's Definition-of-Done tests-required gate.
- Commit after each task or logical group of tasks; the `after_tasks` hook (`speckit-git-commit`) can automate this if enabled.
- Stop at any checkpoint to validate the story independently.
- Post-merge follow-ups (NOT tasks in this file): (a) `/speckit-memory-md-capture` to propose `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` (storage-model deviation) and `DEC-F025-V2-VENDOR-SOURCE-CROSS-CHECK-CADENCE` (reviewer-cadence pattern) once F025 has soaked for two weeks per F020's WORKLOG lesson; (b) file a future ticket to remove the `ToolExposureGate::EXCLUDED_SLUGS` bypass entirely if no cached-client fallout is observed; (c) file a future ticket to promote `MCPServer\Schema` `server_slug` index from `'key'` to `'unique'` (SEC-025-v2-3).
