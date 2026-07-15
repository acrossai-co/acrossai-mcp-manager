---

description: "Implementation task list for Feature 026 — Include F017-effective abilities in the composed tool list at server-registration time"
---

# Tasks: Abilities Into Tool Registration

**Input**: Design documents from `/specs/026-abilities-into-tool-registration/`
**Prerequisites**: `plan.md` ✓, `spec.md` ✓ (3 user stories: US1 P1 registration wiring, US2 P2 filter compatibility, US3 P2 Tools tab UX preservation), `research.md` ✓, `data-model.md` ✓, `contracts/` ✓ (1 file), `quickstart.md` ✓, `memory-synthesis.md` ✓, `security-constraints.md` ✓

**Tests**: Included. Feature specification's Definition of Done gates PHPUnit coverage (4 new `test_compose_effective_*` cases + 1 new `test_register_database_servers_produces_f017_widened_*` case).

**Organization**: Tasks grouped by user story per plan.md priorities. Foundational phase (Phase 2) covers the one new `ToolPolicy` method that both US1 and US2 depend on.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 map to spec.md user stories (blank on Setup / Foundational / Polish)
- File paths are exact and repository-relative from plugin root

## Path Conventions

Single WordPress plugin project — paths shown are relative to the plugin root `acrossai-mcp-manager/` (absolute: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager/`).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm branch state; no code changes.

- [X] T001 Confirm branch `026-abilities-into-tool-registration` is checked out with a clean working tree (`git status`); verify `.specify/feature.json` reads `{"feature_directory": "specs/026-abilities-into-tool-registration"}`; verify `specs/026-abilities-into-tool-registration/plan.md` exists.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: New `ToolPolicy` method that every user story depends on.

**⚠️ CRITICAL**: US1 / US2 / US3 cannot begin until this phase is complete.

- [X] T002 Add `public static function compose_effective_tools_for_row( Row $row ): array` to `includes/Database/MCPServer/ToolPolicy.php`. Placement: immediately after `compose_for_row()` (line ~135), before `split_payload()` (line ~159). Add `use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;` alongside the existing `MCPServerTool\Query` import. Body: seed `$tools = self::compose_for_row( $row );`, then add a one-line code comment `// SC-006: fail-open path verified by quickstart (§"Fail-open") — not unit-tested because PHPUnit cannot shadow function_exists() without runkit.` (per SEC-TASKS-026-1) followed by `if ( ! function_exists( 'wp_get_abilities' ) ) return $tools;` (fail-open per FR-003), then iterate `\wp_get_abilities()` and append `$slug = (string) $ability->get_name()` (skipping empty strings) when `ExposureResolver::resolve( (int) $row->id, $slug, is_array( $meta ) ? $meta : array() )` returns true. Return `array_values( array_unique( array_map( 'strval', $tools ) ) )`. Docblock per `data-model.md` §"Method delta". Do NOT touch `compose_for_row()`, `split_payload()`, `PROTOCOL_TOOLS`, `COLUMN_MAP`, or `PROTOCOL_TOOL_METADATA`.

**Checkpoint**: `ToolPolicy::compose_effective_tools_for_row()` exists and can be consumed by US1/US2 tasks.

---

## Phase 3: User Story 1 — Operator's Abilities-tab choices reach AI clients (Priority: P1) 🎯 MVP

**Goal**: The two F025 server-registration call sites in `Controller` swap from `compose_for_row()` to the new `compose_effective_tools_for_row()`, so every enabled MCP server's `tools/list` includes the operator's F017-effective abilities (public abilities + explicit per-server enablement, minus per-server disablement).

**Independent Test**: Enable an MCP server; register a WP ability with `meta.mcp.public = true` via a scratch mu-plugin. Call `tools/list` on the server's endpoint. The ability appears alongside the three protocol tools. Toggle the ability OFF via the Abilities tab; re-issue `tools/list`; the ability is gone.

- [X] T003 [US1] Modify `includes/MCP/Controller.php` line 142 — change `$tools = ToolPolicy::compose_for_row( $server );` to `$tools = ToolPolicy::compose_effective_tools_for_row( $server );`. Do NOT change any other line in `register_database_servers()`; do NOT change the surrounding filter emission or defensive normalize at line 163.
- [X] T004 [US1] Modify `includes/MCP/Controller.php` line 247 — change `$tools = ToolPolicy::compose_for_row( $rows[0] );` to `$tools = ToolPolicy::compose_effective_tools_for_row( $rows[0] );`. Do NOT change any other line in `filter_default_server_config()`; do NOT change the surrounding defensive short-circuits at lines 230–245 or the empty-set fallback at lines 248–250.
- [X] T005 [P] [US1] Extend `tests/phpunit/Database/MCPServer/ToolPolicyTest.php` — add 4 new `test_compose_effective_*` cases per `data-model.md` §"Test data model" and `docs/planings-tasks/026-abilities-into-tool-registration.md` TASK-5: (a) `test_compose_effective_includes_public_ability_with_no_override`, (b) `test_compose_effective_excludes_public_ability_with_disabled_override`, (c) `test_compose_effective_includes_non_public_ability_with_enabled_override`, (d) `test_compose_effective_excludes_non_public_ability_with_no_override`. Each case: register a scratch ability via `wp_register_ability()` (skip with `markTestSkipped()` if `! function_exists( 'wp_register_ability' )`), optionally `MCPServerAbility\Query::instance()->upsert( $server_id, $slug, $is_exposed )`, call `ToolPolicy::compose_effective_tools_for_row( $row )`, assert `assertContains` / `assertNotContains` on the slug. Add `ExposureResolver::_reset_cache_for_tests();` at the start of `setUp()` alongside the existing table truncations; add `TRUNCATE wp_acrossai_mcp_server_abilities` to `truncate_tables()`.
- [X] T006 [P] [US1] Extend `tests/phpunit/MCP/ControllerToolsInjectionTest.php` — add 1 new case at the end: `test_register_database_servers_produces_f017_widened_composed_set()`. Seed a public ability + a server row; call `apply_filters( 'acrossai_mcp_manager_server_tools', ... )` directly (matching the existing F025 harness pattern in the existing "filter fires once per server" case); assert the pre-filter `$tools` array contains the public ability slug.

**Checkpoint**: US1 is fully functional — both server-registration paths produce the F017-widened composed set. MVP shippable.

---

## Phase 4: User Story 2 — Companion plugins keep working via the existing F025 filter (Priority: P2)

**Goal**: Update the filter's docblock and extension-author documentation to declare the widened pre-filter input. Companion plugins that hooked F025's filter continue to work without callback changes.

**Independent Test**: In a scratch mu-plugin, hook `acrossai_mcp_manager_server_tools` and log the received `$tools` array. After F026, the log includes F017-effective ability slugs alongside the F025 protocol + curated slugs. The mu-plugin's `array_diff`/`array_merge` return continues to work as before.

- [X] T007 [US2] Update the filter docblock in `includes/MCP/Controller.php` at line ~144-161 (the block immediately above `apply_filters( 'acrossai_mcp_manager_server_tools', ... )` at line 162). Change the wording from "the union of the row's enabled tool_* columns (protocol slugs) and the ability slugs saved in wp_acrossai_mcp_server_tools" to "the union of THREE sources: (1) The row's enabled tool_* columns (F025 — protocol slugs). (2) Ability slugs saved in wp_acrossai_mcp_server_tools (F020 — curated). (3) F017-effective abilities — every ability where ExposureResolver::resolve( $server_id, $slug, $meta ) === true. Row-in-wp_acrossai_mcp_server_abilities beats meta.mcp.public per DEC-ABILITY-OVERRIDE-RESOLUTION." Preserve the `@since 0.1.0 (Feature 025)` line; add a companion `@since 0.1.0 (Feature 026 — widened composed set)` line. Preserve the `NOT fired for the default server` paragraph and the `@param` docblock lines verbatim.
- [X] T008 [US2] Update `docs/extending-server-tools.md` per `contracts/filter-acrossai_mcp_manager_server_tools-widened.md` and the folded security-review findings:
  - **§Filter contract** — extend the "pre-filter composed list" bullet list to include the third F017-effective source with the row-in-table > `mcp.public` precedence.
  - **§Arguments → `$tools`** — note the widened composition and the composition order (protocol → curated → F017-effective) as stable but non-contractual.
  - **NEW §"Interaction with the Abilities tab"** (add between §"Composability" and §"Examples") — paragraph explaining that toggling an ability off in the Abilities tab (writes `is_exposed = 0`) removes it from `tools/list` on the next request even if `mcp.public = true`. Add SEC-026-INFO-1 cross-reference: **"Note: a companion filter callback can still add back an ability the operator hid via the Abilities tab. This mirrors SEC-025-INFO-1's confused-deputy note — the F017 call-time gate at `mcp_adapter_pre_tool_call` priority 20 will still block execution, so the operator's hidden decision remains effective at `tools/call` time even if it's overridden at advertisement time."**. Add SEC-026-INFO-3 opt-in note: **"Adding a third-party plugin that registers abilities with `meta.mcp.public = true` will immediately expose those abilities in `tools/list` on every enabled MCP server. To opt out per server, use the Abilities tab to toggle each ability OFF (writes `is_exposed = 0` in `wp_acrossai_mcp_server_abilities`)."**. Add SEC-026-v2-2 timing note (as a new NEW §"Ability registration timing" or folded into the same §"Interaction" section): **"For F026 to see your ability at server-registration time, it MUST be registered via `add_action( 'wp_abilities_api_init', ... )` (which fires during WordPress `init`). Abilities late-registered on `rest_api_init` or later hooks will be invisible to `tools/list` on the same request-cycle."**

**Checkpoint**: US2 fully documented; companion-plugin authors have a canonical reference for the widened contract.

---

## Phase 5: User Story 3 — Tools tab UI is unchanged (Priority: P2)

**Goal**: Confirm via grep that `ToolsController::get_tools()` continues to call `compose_for_row()` (not the new `compose_effective_tools_for_row()`), preserving the F025 REST GET contract byte-for-byte.

**Independent Test**: `curl GET /wp-json/acrossai-mcp-manager/v1/servers/{id}/tools` returns only protocol + curated slugs in the `tools` array — no F017-effective abilities. Tools tab count text and add/remove UX unchanged.

- [X] T009 [US3] `includes/REST/ToolsController.php` — NO CODE CHANGE. Verify via `grep -n "ToolPolicy::compose_" includes/REST/ToolsController.php` that: (a) exactly one match for `ToolPolicy::compose_for_row` at line ~201 (inside `get_tools()`), (b) ZERO matches for `ToolPolicy::compose_effective_tools_for_row` in this file. Any deviation means an accidental swap; fix by reverting to `compose_for_row`.

**Checkpoint**: US3 verified — REST GET semantic preserved verbatim.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Spec amendment (SEC-026-v2-1), quality gates, grep audits, quickstart walkthrough.

- [X] T010 Update `specs/026-abilities-into-tool-registration/spec.md` §Edge Cases per SEC-026-v2-1 (security-review v2). Amend the "What if a curated ability has `mcp.public = false`..." bullet OR add a new bullet: **"What if the default server has all three protocol columns `0` AND no curated picks? — pre-F026 this fell back to vendor defaults per SEC-025-v2-1. Under F026 the fallback fires only when the composed set is TRULY empty (no protocol, no curated, AND no F017-effective abilities). On installs with any `mcp.public = true` third-party ability, the fallback path is typically unreachable — F026 advertises the F017-effective abilities instead. Operators who need an empty default-server tool list must additionally toggle every ability OFF via the Abilities tab OR hook `mcp_adapter_default_server_config` at priority `> 10` and explicitly set `$config['tools'] = []`."**. **Also amend `data-model.md` §"Order-of-operations at server registration time" §"Default server" step 3 (per SEC-TASKS-026-2)** with a parenthetical after "replaces `$config['tools']` with the result": *"(if empty, short-circuits and returns `$config` untouched — see `spec.md §Edge Cases` for the shifted fallback semantic under F026)."*
- [X] T011 [P] Run `composer phpcs` on the modified files (`includes/Database/MCPServer/ToolPolicy.php`, `includes/MCP/Controller.php`, `tests/phpunit/Database/MCPServer/ToolPolicyTest.php`, `tests/phpunit/MCP/ControllerToolsInjectionTest.php`) — zero errors and zero warnings.
- [X] T012 [P] Run `composer phpstan` — level 8, zero errors on the modified files, no new baseline entries.
- [~] T013 [P] Run `composer test` — full PHPUnit suite green, including the 5 new F026 cases.
- [X] T014 Grep audits per plan §"Contract §Grep audits (post-F026)": (a) `grep -rn "compose_effective_tools_for_row" includes/` → exactly **3 matches** (1 definition in `ToolPolicy.php`, 2 call sites in `Controller.php`). (b) `grep -rn "compose_for_row" includes/` → **4 matches** — **corrected during implementation** (1 definition in `ToolPolicy.php`, 1 REST GET at `ToolsController.php:201`, 1 POST response at `ToolsController.php:388` — the plan under-counted this because F025's `post_tools()` response also returns `compose_for_row()` output as the "what did I just save" reflection, which correctly stays on `compose_for_row()` under F026, 1 internal seed call inside `compose_effective_tools_for_row()`). (c) `grep -n "apply_filters( 'acrossai_mcp_manager_server_tools'" includes/` → exactly **1 match** (unchanged from F025). Any deviation is a defect and blocks merge.
- [~] T015 Walk through `specs/026-abilities-into-tool-registration/quickstart.md` end-to-end on a LocalWP install (or comparable). All 12 checks (US1 §1–4, US2 §1–3, US3 §1–3, fail-open, F017 gate regression) must pass. Attach the walkthrough output to the PR description. **DEFERRED to reviewer** — requires live WordPress + WP-CLI + browser.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: T001 — no dependencies; complete before any other work.
- **Foundational (Phase 2)**: T002 — depends on Setup; **BLOCKS all user stories**.
- **User Story 1 (Phase 3)**: depends on Foundational. T003 and T004 are same-file sequential (both edit `Controller.php`). T005 and T006 test files can run in parallel with T003/T004 authoring if a second developer is available.
- **User Story 2 (Phase 4)**: depends on Foundational (T007 references the filter docblock at line ~155, which surrounds T003's edit at line 142; T007 must run AFTER T003 to avoid line-number drift). T008 (docs) is independent of code; can run any time after T002.
- **User Story 3 (Phase 5)**: depends on Foundational (T009 is grep-verification only, but the grep is meaningful only after T002 exists and T003/T004 have or have not swapped `ToolsController.php`).
- **Polish (Phase 6)**: T010 (spec amendment) depends on T002 landing (references the F026 method's semantic). T011-T013 (quality gates) depend on all implementation tasks. T014 (grep audits) depends on all implementation. T015 (quickstart) depends on the entire implementation.

### User Story Dependencies

- **US1 (P1)**: Can start immediately after Phase 2 completes.
- **US2 (P2)**: Can start immediately after Phase 2 completes. T007's line-number reference depends on T003's edit landing first (T003 modifies line 142, T007 modifies docblock at line ~155 — after T003 landing the line numbers may shift by ±1 for the docblock reference).
- **US3 (P2)**: Grep-only. Verifiable any time after T002 exists.

### Parallel Opportunities

- Phase 3 US1: T005 (ToolPolicyTest) and T006 (ControllerToolsInjectionTest) run in parallel — different test files.
- Phase 4 US2: T007 (Controller docblock) and T008 (docs) can be authored in parallel if T003 has already landed.
- Phase 6 Polish: T011 / T012 / T013 (quality gates) all run in parallel.

---

## Parallel Example: Phase 3 User Story 1

```bash
# Author the code changes sequentially (same file):
Task: "T003 Swap Controller.php:142 compose_for_row → compose_effective_tools_for_row"
Task: "T004 Swap Controller.php:247 compose_for_row → compose_effective_tools_for_row"

# Author the tests in parallel:
Task: "T005 [P] Add 4 test_compose_effective_* cases to ToolPolicyTest.php"
Task: "T006 [P] Add test_register_database_servers_produces_f017_widened_composed_set to ControllerToolsInjectionTest.php"
```

## Parallel Example: Phase 6 Polish

```bash
Task: "T011 composer phpcs"
Task: "T012 composer phpstan"
Task: "T013 composer test"
```

---

## Implementation Strategy

### MVP First (US1 only)

1. Complete Phase 1 (T001) → clean branch state.
2. Complete Phase 2 (T002) → new method exists in `ToolPolicy`.
3. Complete Phase 3 (T003–T006) → US1 fully functional; tests pass.
4. **STOP and VALIDATE**: enable a server, register a public ability via a scratch mu-plugin, curl `tools/list`, confirm the ability appears; toggle OFF via Abilities tab, confirm it disappears.
5. Ship the MVP if F026's US2 (filter docs) and US3 (Tools tab UI verification) can be deferred. In practice all three ship together because docs/verification are cheap.

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. US1 → MVP (both server-registration paths advertise F017-effective abilities).
3. US2 → Companion-plugin authors get updated docs.
4. US3 → REST GET verified unchanged.
5. Polish → Spec amendment, quality gates, grep audits, quickstart validation → merge-ready.

### Parallel Team Strategy

With multiple developers:
1. Team completes Phase 1 + Phase 2 together (one developer).
2. Once Foundational is done:
   - **Developer A**: US1 T003–T006 — PHP-focused.
   - **Developer B**: US2 T008 (docs) — docs-focused; can start immediately.
   - **Developer C**: US2 T007 (Controller docblock) after Developer A lands T003.
   - **Developer D**: US3 T009 (grep verification) after Foundational.
3. All converge for Polish (T010–T015).

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks in the same phase.
- [Story] label maps to spec.md user stories.
- Every user story is independently testable per the "Independent Test" line in its phase header.
- Verify PHPUnit tests fail BEFORE implementation for the test-first cases (T005, T006). Matches the plugin's Definition-of-Done tests-required gate.
- Commit after each task or logical group of tasks; the `after_tasks` hook (`speckit-git-commit`) can automate this if enabled.
- Stop at any checkpoint to validate the story independently.
- Post-merge follow-ups (NOT tasks in this file):
  - Consider updating `DEC-F025-HYBRID-TOOL-STORAGE-PROTOCOL-VS-CURATED` in `docs/memory/DECISIONS.md` with an "F026 is the second use of this pattern" annotation.
  - Consider a future F017 maintenance PR to rename `ExposureResolver::_reset_cache_for_tests()` → `clear_request_cache()` per B23 guidance (SEC-026-INFO-2).

## Implementation status (2026-07-14)

- Completed [X]: T001–T012, T014 (branch state, foundational method, US1 code + tests, US2 docblock + docs, US3 grep verify, polish spec + data-model amendments, PHPCS clean on F026 files, PHPStan L8 clean, grep audits 3/4/1 all match).
- Deferred [~]:
  - **T013** `composer test` — full PHPUnit suite fatal-errors locally with `Class "WP_UnitTestCase" not found` because the WordPress test library isn't bootstrapped in this LocalWP checkout. Same posture as F025 pre-merge — CI runs the integration suite. The 5 new F026 test cases (T005 × 4 + T006 × 1) parse-check clean (`php -l`) and follow the exact fixture pattern of the passing F025 cases.
  - **T015** quickstart walkthrough — DEFERRED to reviewer per its own body ("requires live WordPress + WP-CLI + browser").

---

## Phase 7: F026 v2 scope expansion (added 2026-07-14, folded during governed-implement)

**Purpose**: Type-filter bug fix on tools composer + resources + prompts wiring, per user's fold-in decision. All F017-effective, no curated storage, no new tables, no new UI.

- [X] T016 Create `includes/Database/MCPServer/AbilityDiscovery.php` (new stateless class). Public API: `public static function for_server( int $server_id, string $type ): array`. Class constants: `TYPE_TOOL`, `TYPE_RESOURCE`, `TYPE_PROMPT`. Iterates `wp_get_abilities()`, filters by `mcp.type` (default 'tool' when unset — vendor semantic), gates via `ExposureResolver::resolve()`. Fail-open: returns `[]` when Abilities API unavailable. Result deduped + string-normalized via `array_values( array_unique( array_map( 'strval', ... ) ) )`. Stateless per A11 exemption.
- [X] T017 Refactor `ToolPolicy::compose_effective_tools_for_row()` to delegate the F017 iteration to `AbilityDiscovery::for_server( ..., TYPE_TOOL )`. Union with `compose_for_row()` output as before. Drop the inline `wp_get_abilities()` loop + the `use ExposureResolver;` import (moved to `AbilityDiscovery`). This is the type-filter bug fix — resource/prompt-typed public abilities no longer leak into tools list.
- [X] T018 `includes/MCP/Controller.php` — extend `register_database_servers()`: after `compose_effective_tools_for_row($server)`, add `$resources = AbilityDiscovery::for_server( (int) $server->id, TYPE_RESOURCE );` and `$prompts = AbilityDiscovery::for_server( ..., TYPE_PROMPT );`. Fire two new filters: `apply_filters( 'acrossai_mcp_manager_server_resources', $resources, $server )` and `apply_filters( 'acrossai_mcp_manager_server_prompts', $prompts, $server )`. Apply defensive re-normalization to each. Pass all three to `$adapter->create_server()` (replaces the `array(), array()` 11th/12th args).
- [X] T019 `includes/MCP/Controller.php` — extend `filter_default_server_config()`: after locating the default row, REPLACE `$config['resources']` and `$config['prompts']` unconditionally (when the respective keys are arrays) with `AbilityDiscovery::for_server( $server_id, TYPE_RESOURCE|TYPE_PROMPT )`. Rationale: the vendor's `DefaultServerFactory::discover_abilities_by_type()` sets these to the `mcp.public = true` set with no F017 overlay — we MUST REPLACE (even with empty array) so operator's Abilities-tab `is_exposed = 0` clicks remove abilities the vendor auto-discovered. Update the method's docblock accordingly. `tools` key keeps the F025 empty-set fallback (unchanged).
- [X] T020 [P] Add `tests/phpunit/Database/MCPServer/AbilityDiscoveryTest.php` — 7 cases: (1) tool type filter, (2) resource type filter, (3) prompt type filter, (4) resource `is_exposed=0` override, (5) prompt `is_exposed=1` override for non-public, (6) missing `mcp.type` defaults to 'tool', (7) result normalization (deduped, string, zero-indexed).
- [X] T021 [P] Extend `tests/phpunit/Database/MCPServer/ToolPolicyTest.php` — update `register_scratch_ability()` helper to accept an optional `$type` param (default 'tool'). Add one new case `test_compose_effective_excludes_public_resource_typed_ability_from_tool_list` guarding the F026 v2 type-filter bug fix.
- [X] T022 [P] Extend `tests/phpunit/MCP/ControllerToolsInjectionTest.php` — 3 new cases: `test_acrossai_mcp_manager_server_resources_filter_receives_f017_effective_resource_set`, `test_acrossai_mcp_manager_server_prompts_filter_receives_f017_effective_prompt_set`, `test_filter_default_server_config_replaces_resources_and_prompts_when_default_row_exists` (asserts REPLACE semantic — vendor-auto-discovered items get removed from the config). Add `wp_acrossai_mcp_server_abilities` to `truncate_tables()`. Update the existing v1 F026 case's scratch ability registration to include `'type' => 'tool'`.
- [X] T023 Update `docs/extending-server-tools.md`: (a) amend §2 `$tools` description to note the type filter — F017-effective, tool-typed only. (b) Add `acrossai_mcp_manager_server_resources` and `acrossai_mcp_manager_server_prompts` filter contracts in §2. (c) Update §3 two-hook table to name all three plugin filters + note vendor filter now REPLACES three keys on the default server config.
- [X] T024 Quality gates: `php -l` on all six touched files (pass); `./vendor/bin/phpcs` on all six (pass); `composer phpstan` L8 (exit 0).
- [X] T025 Grep audits: `AbilityDiscovery::for_server` = 5 call sites (1 ToolPolicy + 2 register_database_servers + 2 filter_default_server_config); new filter names = 1 apply_filters each; `compose_effective_tools_for_row` = 3 (1 def + 2 Controller call sites, unchanged from v1).

**Checkpoint**: F026 v2 complete. All three MCP ability types (tool/resource/prompt) advertise correctly on both server-registration paths. Type-filter bug fixed. Two new extension seams for companion plugins.

---

## Security-review remediation folded into tasks

- **SEC-026-INFO-1** (v1) → T008 (docs cross-reference to SEC-025-INFO-1's confused-deputy note)
- **SEC-026-INFO-2** (v1) → No F026 task; noted for future F017 maintenance PR.
- **SEC-026-INFO-3** (v1) → T008 (`mcp.public = true` opt-in note in `docs/extending-server-tools.md`)
- **SEC-026-v2-1** (v2) → T010 (spec §Edge Cases amendment)
- **SEC-026-v2-2** (v2) → T008 (ability registration timing note in `docs/extending-server-tools.md`)

---

## Phase 8: F026 v3 — refactor arc + scope reversal + bug fixes (added 2026-07-15)

**Purpose**: Reshape the ability-exposure mechanism after operator feedback on F026 v2's tools-widening behavior. Move enforcement from advertisement-time (registration composition) to call-time (plugin-owned meta tools). Fix two pre-existing bugs surfaced by the new call-time path.

**Commits in this phase** (chronological):

- [X] T026 `4ca9db4` **feat(vendor-override)** — Ship an intercept-only `VendorAbilityInterceptor` module at `includes/VendorOverrides/`. Hooks vendor's `mcp_adapter_pre_tool_call` (priority 40) and `mcp_adapter_tool_call_result` (priority 40) to block/rewrite the three built-in meta tools per-server. Includes 12 PHPUnit cases + a plugin-owned filter `acrossai_mcp_manager_vendor_override_effective_slugs`. **Sunset marker**: `ACROSSAI_MCP_MANAGER_VENDOR_OVERRIDE_243`.

  **Now superseded** — see T027. Left the commit in history so the design trade-off (intercept vs. callback-swap) is documented.

- [X] T027 `070ffe2` **refactor(abilities)** — Replace the intercept module with a plugin-owned callback-swap via WP core's `wp_register_ability_args` filter. Creates `includes/Abilities/` folder with 6 classes:
  - `CurrentServerHolder` — request-scoped singleton; captures server on `rest_pre_dispatch` priority 5, clears on `rest_post_dispatch` + `shutdown` priority 999.
  - `AbilityHelpers` — trait with `mcp_type()`, `is_meta_public()`, `apply_exposure_filter()`.
  - `Discover`, `GetAbilityInfo`, `Execute` — plugin-owned callbacks for the three vendor slugs.
  - `CallbackReplacer` — hooks `wp_register_ability_args`, swaps `execute_callback` + `permission_callback` on the three vendor slugs; passes through all others.

  Wires 4 new hooks in `Main.php`. Deletes `includes/VendorOverrides/*`, `tests/phpunit/VendorOverrides/*`, `docs/planings-tasks/026-vendor-abilities-override.md`, and the 2 old `add_filter` lines. Adds 6 PHPUnit test classes (30+ cases) covering callback swap, holder lifecycle, per-context filter emission, filter widen/narrow, ability-type filter, exposure vs authorization boundary, and reflection-based callback binding.

  New filter: `acrossai_mcp_is_ability_exposed( bool $is_exposed, WP_Ability $ability, ?int $server_id, string $context )` — shape mirrors upstream mcp-adapter#244 for future migration.

- [X] T028 `0e122e2` **revert(026)** — Stop widening `tools/list` with F017-effective abilities at server-registration time. `ToolPolicy::compose_effective_tools_for_row()` is now a straight passthrough to `compose_for_row()` (F025 protocol columns + F020 curated only). AI clients reach abilities exclusively through the three built-in meta tools whose callbacks respect the Abilities tab (via T027's callback swap).

  Rationale: F026 v1's widening caused a mismatch — the Tools tab UI showed 3 "Built-in" tools but `tools/list` returned 3 + N public abilities, confusing the "what's a tool vs. what's an ability" mental model. Reverting keeps `tools/list` scoped to operator's Tools-tab picks.

  Resources and prompts widening in `Controller::register_database_servers()` and `Controller::filter_default_server_config()` is UNCHANGED (F026 v2 kept — no equivalent meta tools for those types).

  Test inversions: 2 `test_compose_effective_includes_*` cases in `ToolPolicyTest` → renamed to `_excludes_*_post_revert` and inverted. The `test_register_database_servers_produces_f017_widened_composed_set` case in `ControllerToolsInjectionTest` → renamed to `_does_not_widen_*` and inverted.

- [X] T029 `69e689c` **fix(020)** — Pre-existing gap in F020 `ToolExposureGate::EXCLUDED_SLUGS` bypass. The constant listed the raw ability form (with `/`), but at gate time `$tool_name` is the vendor-sanitized form (with `-` — vendor's `McpNameSanitizer::sanitize_name` swaps `/` → `-` when registering an ability as an MCP tool). Bypass never matched; F020 denied all three built-in meta tools with `acrossai_mcp_tool_not_added` ("This tool is not enabled on this MCP server.").

  Gap survived because pre-T027 nobody actually invoked the meta tools via `tools/call` — they were vendor-internal plumbing. T027 made them first-class execution paths, immediately surfacing the F020 bug.

  Fix: expanded `EXCLUDED_SLUGS` to 6 entries — both raw and sanitized forms. Added new test class `tests/phpunit/MCP/ToolExposureGateTest.php` with 6 cases (bypass matching for both forms, non-protocol slug still denied, deny-precedence).

- [X] T030 `e0189b0` **fix(abilities)** — Gap in T027's `AbilityHelpers::apply_exposure_filter()`. The filter's default was `meta.mcp.public` only — never consulted `ExposureResolver::resolve()`. So the Abilities-tab per-server toggles (F017 `is_exposed = 1` rows) were silently ignored. Only globally-public abilities passed through `Discover::execute()`; operators saw 1-of-N abilities returned.

  Fix: `apply_exposure_filter()` now calls `ExposureResolver::resolve( $server_id, $slug, $meta )` when `CurrentServerHolder` returns a non-null server_id. That resolver is F017's canonical function — honors row-in-table override AND `meta.mcp.public` fallback per `DEC-ABILITY-OVERRIDE-RESOLUTION`. Filter contract unchanged; only the DEFAULT value changes from `meta.mcp.public` to the F017-resolved output.

  Added 2 regression cases to `DiscoverTest`: `test_execute_includes_non_public_ability_with_f017_override_when_holder_set` and `test_execute_excludes_public_ability_with_f017_override_disabled_when_holder_set`.

**Obsoletion notes** on earlier tasks:

- **T003, T004** (Phase 3 US1) — `Controller.php` line-swap tasks. Post-T028, both lines still call `compose_effective_tools_for_row()` but the method is now a passthrough. Behavior described in T003/T004 ("advertise F017-effective abilities in `tools/list`") is no longer active for tools; still active for resources/prompts via T018/T019.
- **T005, T006** — Test cases inverted in T028. See T028 for the inversion details.
- **T009** — REST GET `/tools` verification still valid (unchanged behavior).

**Checkpoint**: F026 v3 complete. Advertisement path scoped to Tools-tab picks. Call path through the three meta tools honors Abilities-tab visibility. Two pre-existing bugs (F020 sanitizer bypass, F017 resolver gap) fixed. Total commits: 4ca9db4, 070ffe2, 0e122e2, 69e689c, e0189b0.
