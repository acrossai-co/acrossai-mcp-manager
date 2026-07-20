---
description: "Task list for Feature 030 — Per-Server Ability Permission-Callback Override"
---

# Tasks: Per-Server Ability Permission-Callback Override

**Input**: Design documents from `specs/030-per-server-permission-override/`
**Prerequisites**:
- `plan.md` ✅
- `spec.md` ✅ (4 user stories, 20 FRs, 4 clarifications recorded)
- `memory-synthesis.md` ✅ (1 soft conflict with D24 — DEC-F030-* capture planned)
- `../../docs/planings-tasks/030-per-server-permission-override.md` ✅ (engineering brief with concrete code snippets)
- `../../docs/security-reviews/2026-07-20-030-per-server-permission-override-plan.md` ✅ (5 SEC-030-* findings — 1 M, 2 L, 2 I)

**Tests**: **REQUIRED** — spec §Definition of Done gate mandates PHPUnit coverage for all new logic. Test tasks are interleaved per user story, marked with the same [US*] label.

**Organization**: Tasks grouped by user story for independent implementation + testing. US3 (safe upgrade) is bundled into Phase 2 Foundational because its acceptance scenarios ARE the D28 column-add procedure.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1/US2/US3/US4 for user-story tasks; no label for Setup/Foundational/Polish
- Every task includes an exact file path

## Path Conventions

WordPress plugin layout (per plan.md §Project Structure):
- Runtime PHP: `includes/`, `admin/`, `public/`
- Tests: `tests/phpunit/`
- Specs + memory: `specs/030-per-server-permission-override/`, `docs/memory/`, `docs/planings-tasks/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Sanity checks before touching code. This plugin is already initialized — no scaffolding needed.

- [ ] T001 Verify branch `030-per-server-permission-override` is checked out and clean; verify `.specify/feature.json` points to `specs/030-per-server-permission-override`; verify BerlinDB Kern base classes are autoloadable (`composer dump-autoload -o` if unsure)

---

## Phase 2: Foundational (Blocking Prerequisites — covers US3 Safe Upgrade)

**Purpose**: Add the `override_abilities_permission tinyint(1) NOT NULL DEFAULT 0` column to `wp_acrossai_mcp_servers` via the D28 3-part contract. Every user story downstream reads/writes this column, so this phase MUST land first.

**⚠️ CRITICAL**: No US1/US2/US4 work begins until this phase is complete and the column exists in the DB.

- [ ] T002 [P] Append the new column entry to `$columns` in `includes/Database/MCPServer/Schema.php` (matching the shape of the existing `tool_execute_ability` entry: `'name' => 'override_abilities_permission', 'type' => 'tinyint', 'length' => '1', 'default' => 0`)
- [ ] T003 [P] Bump `$version` from `'1.1.1'` to `'1.1.2'` in `includes/Database/MCPServer/Table.php`; register the `$upgrades` callback entry `'1.1.2' => 'upgrade_to_1_1_2'`; implement `upgrade_to_1_1_2()` method mirroring the existing `upgrade_to_1_1_1()` at lines 136–171 verbatim (INFORMATION_SCHEMA.COLUMNS existence check → return true if present → otherwise `$wpdb->query()` the ALTER ADD COLUMN with DDL `ALTER TABLE `{$table}` ADD COLUMN `override_abilities_permission` tinyint(1) NOT NULL DEFAULT 0`)
- [ ] T004 [P] Add `public $override_abilities_permission = 0;` property to `includes/Database/MCPServer/Row.php` alongside the existing `tool_*` properties (lines 22–34); add `(int)` cast in the constructor next to the existing `tool_*` casts (B18 mitigation — tinyint returns as string)
- [ ] T005 [P] PHPUnit test for the D28 upgrade path in `tests/phpunit/Database/MCPServer/PermissionOverrideColumnUpgradeTest.php` — mirror `PhantomVersionGuardTest` shape: drop `override_abilities_permission` column while `wpdb_acrossai_mcp_servers_version = 1.1.2`, force `maybe_upgrade()` re-entry (or set the option back to `1.1.1` and re-invoke), assert the column is recreated exactly once (`SHOW WARNINGS` empty, second invocation is a no-op)

**Checkpoint**: On any admin page load `wp_options.wpdb_acrossai_mcp_servers_version = '1.1.2'`; `DESCRIBE wp_acrossai_mcp_servers` lists `override_abilities_permission tinyint(1) NOT NULL DEFAULT 0`; every pre-existing row has `override_abilities_permission = 0` (US3 acceptance scenarios 1–3 pass).

---

## Phase 3: User Story 1 — Operator enables permission override (Priority: P1) 🎯 MVP

**Goal**: An operator with `manage_options` can toggle a per-server override that, when ON, causes exposed abilities to bypass their `permission_callback` for in-flight MCP requests to that server. This is the core feature.

**Independent Test**: Ship US1 alone — visit the Access Control tab, tick the checkbox, save, reload (checkbox persists); as a low-privilege authenticated user invoke any exposed ability via the server's MCP route and observe success (US1 acceptance scenarios 1–3).

### Tests for User Story 1 (write FIRST, ensure they FAIL before implementing)

- [ ] T006 [P] [US1] PHPUnit test for `Settings::handle_save_permission_override()` nonce rejection in `tests/phpunit/Admin/Partials/SettingsPermissionOverrideSaveTest.php` — POST with stale/missing `acrossai_mcp_manager_permission_override_nonce` MUST NOT write to DB; use `wp_die` filter throw pattern from B13
- [ ] T007 [P] [US1] PHPUnit test for `handle_save_permission_override()` capability rejection in the same file — user without `manage_options` MUST NOT write to DB
- [ ] T008 [P] [US1] PHPUnit test in `tests/phpunit/Abilities/PermissionOverrideProcessorTest.php` for the null-server-context fall-through — `CurrentServerHolder::instance()->get_server_id()` returns null → closure returns the original callback's result unchanged (verified with a stub original that returns `true` on one call, `false` on another)
- [ ] T009 [P] [US1] PHPUnit test in same file for the override-off fall-through — server row has `override_abilities_permission = 0` → closure returns original callback's result
- [ ] T010 [P] [US1] PHPUnit test in same file for the not-exposed fall-through — server row has `override_abilities_permission = 1` BUT `ExposureResolver::resolve( $server_id, $slug, [] )` returns false → closure returns original callback's result
- [ ] T011 [P] [US1] PHPUnit test in same file for the override-on + exposed happy path — server row has `override_abilities_permission = 1` AND `ExposureResolver::resolve()` returns true → closure returns exactly `bool true` regardless of original callback

### Implementation for User Story 1

- [ ] T012 [US1] Create `includes/Abilities/PermissionOverrideProcessor.php` (NEW) — namespace `AcrossAI_MCP_Manager\Includes\Abilities`, plugin singleton (`protected static $_instance = null;` + `public static function instance(): self` + `private __construct()`), static `boot()` method registering `add_filter( 'wp_register_ability_args', array( __CLASS__, 'inject_override' ), 999999, 2 )`, static `inject_override( array $args, string $slug ): array` implementing the four-branch closure from plan §Phase 1 (null server, override off, not exposed, override on + exposed) with per-request static cache `private static ?array $server_row_cache = null` keyed by `int $server_id` (mirrors F017 `ExposureResolver::resolve()` pattern), plus `private static call_original( $original )` helper returning `false` when non-callable (matches WP Abilities API deny-by-default)
- [ ] T013 [US1] Wire `PermissionOverrideProcessor::boot()` in `includes/Main.php` near the existing `CallbackReplacer` wiring (line ~536) — default to A1-strict Loader shape: `$permission_override = \AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor::instance(); $this->loader->add_filter( 'wp_register_ability_args', $permission_override, 'inject_override', 999999, 2 );`. Also wire a companion `rest_post_dispatch` @ P999 hook to clear `$server_row_cache` symmetric with `CurrentServerHolder::clear()` (A17 symmetry)
- [ ] T014 [US1] Extend `admin/Partials/ServerTabs/AccessControlTab.php::render_body()` — AFTER the existing `AccessControlBlock::instance()->render(...)` call, render an `<hr>`, then the permission-override form section: `<h2>` "Ability Permission Override", `<p class="description">` describing scope + P999999 precedence, `<form method="post">` with `wp_nonce_field( 'acrossai_mcp_manager_permission_override_' . (int) $server['id'], 'acrossai_mcp_manager_permission_override_nonce' )` + hidden `acrossai_mcp_manager_action=save_permission_override` + hidden `server={id}` + labelled checkbox `override_abilities_permission` (state via `checked( (int) $server['override_abilities_permission'], 1, false )`) + `submit_button()`. When `(int) $server['override_abilities_permission'] === 1`, render a `<div class="notice notice-warning inline">` above the checkbox naming the server (FR-016). Emit an inline `<script>` block that adds a submit handler firing `confirm()` when the checkbox is checked at submit-time (FR-017); **CRITICAL — SEC-030-001 remediation**: interpolate the server name via `wp_json_encode( $server['server_name'] )` — NEVER `esc_html()` or `esc_attr()` for JS-string context
- [ ] T015 [US1] Add `Settings::handle_save_permission_override()` method to `admin/Partials/Settings.php` (private method) with the exact signature and body from plan §Task-2 code snippet: `check_admin_referer`, `absint` server_id, `manage_options` cap check, `MCPServerQuery::instance()->update_item( $server_id, [ 'override_abilities_permission' => $value ] )`, `wp_safe_redirect( add_query_arg( ..., 'acrossai_mcp_manager_permission_saved=1', admin_url('admin.php') ) ); exit;`. Wire into the existing `admin_init` server-edit POST router by adding a `'save_permission_override' => 'handle_save_permission_override'` branch — see how `handle_update_server()` is dispatched today and mirror exactly
- [ ] T016 [US1] Add `do_action( 'acrossai_mcp_permission_override_toggled', (int) $server_id, (int) $value, get_current_user_id(), time() );` at the end of `handle_save_permission_override()` before the `wp_safe_redirect` — **SEC-030-002 remediation** (D19 fail-open observability pattern). Fire-and-forget, no return value used
- [ ] T017 [US1] Add admin-notices rendering for `acrossai_mcp_manager_permission_saved=1` GET param — reuse whichever success-notice helper already renders for the Update Server tab; render a `<div class="notice notice-success is-dismissible"><p>…</p></div>`. Do NOT echo any GET-param text — the flag only gates a static translated string

**Checkpoint**: All 6 US1 tests green (T006–T011). Toggling the checkbox and saving persists to DB and re-renders with state; MCP calls to server X with override ON bypass permission_callback; hostile `server_name` (`'; alert(1); //`) does NOT execute in confirm() prompt (SEC-030-001 verified).

---

## Phase 4: User Story 2 — Isolation invariant (Priority: P1)

**Goal**: The override applies ONLY to in-flight MCP requests routed to the specific server that has it enabled. Other servers, non-MCP REST paths, WP-CLI, wp-admin — all see the ability's original permission_callback unchanged.

**Independent Test**: With server A override ON + server B override OFF, invoke ability `foo/bar` (exposed to both) via A's route (succeeds), then B's route (denied per original), then a non-MCP REST endpoint (denied per original) — US2 acceptance scenarios 1–3.

**Note**: US2 shares implementation with US1 (same `PermissionOverrideProcessor` class). No net-new implementation tasks; verification is via additional integration tests that assert the isolation invariants.

### Tests for User Story 2

- [ ] T018 [P] [US2] PHPUnit integration test in `tests/phpunit/Abilities/PermissionOverrideIsolationTest.php` — seed two server rows (A override=1, B override=0), both expose ability `foo/bar` via `wp_acrossai_mcp_server_abilities`; simulate MCP request to server A (populate `CurrentServerHolder` accordingly) → assert closure returns `true`; simulate request to server B → assert closure returns original callback's result
- [ ] T019 [P] [US2] PHPUnit test in same file — with `CurrentServerHolder` NOT populated (simulates non-MCP REST or WP-CLI context), assert closure ALWAYS returns original callback's result regardless of override flag on any server (US2 acceptance scenario 2)
- [ ] T020 [P] [US2] PHPUnit test in same file — register a fake `wp_register_ability_args` filter at priority `100000` (mimicking sibling `acrossai-abilities-manager`) that returns a `permission_callback` denying access; register `PermissionOverrideProcessor` at `999999`; server A override ON; assert closure returns `true` (P999999 wins over P100000 — US2 acceptance scenario 3)

**Checkpoint**: All 3 US2 tests green. Isolation invariants provably enforced.

---

## Phase 5: User Story 4 — Promo card + code fallback (Priority: P2)

**Goal**: An operator seeing the Access Control tab discovers the recommended fine-grained tooling (sibling `acrossai-abilities-manager` plugin). They can install & activate it inline (no page nav) via the promo card, OR jump straight to its admin screen when already active, OR expand a `<details>` block to see the WP filter they can hook programmatically without installing anything.

**Independent Test**: On a fresh site without the sibling plugin, open the Access Control tab and click "Install & Activate" — plugin installs and activates without leaving the tab (US4 acceptance scenario 1). Reload → button becomes "Edit Abilities" linking to `admin.php?page=acrossai-abilities-manager` (scenario 2). Expand `<details>` → filter name = `wp_register_ability_args`, priority = `999999` (scenario 3).

### Tests for User Story 4

- [ ] T021 [P] [US4] PHPUnit test in `tests/phpunit/Admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCardTest.php` — with sibling plugin file missing from `WP_PLUGIN_DIR`, rendered card contains `data-action="install"` and the label "Install & Activate"
- [ ] T022 [P] [US4] PHPUnit test in same file — with sibling plugin file present AND `is_plugin_active()` returning true, rendered card contains a link to `admin.php?page=acrossai-abilities-manager` and the label "Edit Abilities" (no install action)
- [ ] T023 [P] [US4] PHPUnit test in same file — rendered `<details>` block contains BOTH the literal string `wp_register_ability_args` AND the literal string `999999` (SEC-030-003 documentation invariant)

### Implementation for User Story 4

- [ ] T024 [P] [US4] Extend `admin/Partials/AddonsFilter.php` — add a filter callback (sibling method or new singleton) that hooks `acrossai_addons` and APPENDS the abilities-manager entry when not already present. Entry MUST use `source: 'github'`, `install_folder: 'acrossai-abilities-manager'`, and **SEC-030-005 remediation**: `download_url` explicitly starts with `https://` (pin the latest tagged release ZIP URL from `https://github.com/acrossai-co/acrossai-abilities-manager/releases/latest`). Idempotency check: scan the incoming `$addons` array for `slug === 'acrossai-abilities-manager'` before appending; skip if present (FR-019 do-not-double-register clause)
- [ ] T025 [US4] Create `admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCard.php` (NEW subdir + file) — namespace `AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials`, plugin singleton, public method `render( array $server ): void`. Fetches the resolved addon entry via `apply_filters( 'acrossai_addons', [] )` and filters for `slug === 'acrossai-abilities-manager'`. Calls `\AcrossAI_Addon\AddonsPageRenderer::button_state_for( $addon )` (or the equivalent public helper — the Explore report confirmed this method is public in `main-menu`) to get `{action, label, css_class}`. When `action === 'install'` or `'activate'`: render the card with the button; the existing `AddonsAjaxHandlers::install`/`activate` handlers accept the click via the shared `acrossai_addons` nonce (do NOT re-implement the AJAX flow). When plugin is active: render an "Edit Abilities" `<a class="button">` linking to `admin_url( 'admin.php?page=acrossai-abilities-manager' )`
- [ ] T026 [US4] Extend `admin/Partials/ServerTabs/AccessControlTab.php::render_body()` — AFTER the T014 form section, render another `<hr>` and call `AbilitiesManagerPromoCard::instance()->render( $server )` (US4 promo card slot)
- [ ] T027 [US4] Extend `AccessControlTab::render_body()` further — AFTER the promo card, render a `<details>` block titled "Prefer to use code?" containing a `<summary>` and a `<pre><code>` snippet showing: filter name `wp_register_ability_args`, priority `999999` explicitly documented as HIGHER than any known competing filter (P100000 for sibling, P10 for CallbackReplacer), plus a short PHP snippet showing how a developer would hook the same filter at priority `1000000` to override or refine F030's behaviour

**Checkpoint**: All 3 US4 tests green. On a fresh site without the sibling plugin: promo card shows "Install & Activate"; click succeeds (main-menu's existing AJAX handlers do the work); reload shows "Edit Abilities" link. `<details>` block accurately documents F030's own filter registration.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Documentation, marketing assets, memory hygiene, and follow-up gates.

- [ ] T028 [P] Add a trust-boundary paragraph to `specs/030-per-server-permission-override/spec.md` §Security Checklist (SEC-030-004 remediation) — one paragraph explicitly stating that `CurrentServerHolder::get_server_id()` is populated only server-side by `rest_pre_dispatch` P5 route-matching and is never sourced from URL/POST/header params; any bug in `capture_from_request()` would defeat F030's per-server scoping so A17 wiring must not regress
- [ ] T029 [P] Add an "Unreleased" changelog bullet to `README.txt` describing Feature 030 in operator-facing terms (from plan §Task-5 draft)
- [ ] T030 [P] Stage the 14 WordPress.org marketing assets in `.wordpress-org/` (2 banners, 1 icon.svg, 11 screenshots) via `git add .wordpress-org/`; include an "Also includes" bullet in the PR description explaining why non-code assets are in the diff
- [ ] T031 Run the full quality gate on the branch: `composer run phpcs`, `composer run phpstan` (level 8), `composer run test` (all PHPUnit incl. new F030 tests), `npm run validate-packages`. All MUST be green (constitution §VII DoD gate). Additional grep gates (added per governed-tasks review, 2026-07-20):
  - `grep -rEn '\badmin_url\s*\(' includes/ admin/` on files touched by F030 MUST show every hit wrapped by `esc_url()` (S5 belt-and-suspenders)
  - `grep -rEn '@dataProvider|@test|@depends|@group' tests/phpunit/Abilities/PermissionOverrideProcessorTest.php tests/phpunit/Admin/Partials/SettingsPermissionOverrideSaveTest.php tests/phpunit/Admin/Partials/ServerTabs/Partials/AbilitiesManagerPromoCardTest.php tests/phpunit/Database/MCPServer/PermissionOverrideColumnUpgradeTest.php tests/phpunit/Abilities/PermissionOverrideIsolationTest.php` MUST return zero (B9 — use PHP 8 attributes like `#[DataProvider]`, `#[Test]`, `#[Depends]`, `#[Group]`)
- [ ] T032 Run `/speckit-security-review-staged` to confirm the SEC-030-001 remediation (`wp_json_encode()` in the inline `<script>`) is present in the diff — this is the ship-blocker security gate from the plan-review report
- [ ] T033 Run `/speckit-memory-md-capture` to formalize the durable memory entries proposed during planning + security review: (a) new decision `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS` — scoped carve-out to D24 corollary, six-layer defensive gating; (b) new bug pattern `B35 — Filter priority footrace for permission-callback swap chains` — priority slot map (P10 CallbackReplacer, P100000 sibling, P999999 F030); (c) new bug pattern `B36 — Inline <script> string-interpolation requires wp_json_encode(), not esc_html()/esc_attr()` (generalizable JS-context escaping rule surfaced by SEC-030-001); (d) WORKLOG entry for F030 covering the promo-card + code-fallback paired UX pattern as generalizable
- [ ] T034 Update `docs/memory/INDEX.md` — add rows for the new DECISION + 2 new BUG patterns; add row for the security review at `docs/security-reviews/2026-07-20-030-per-server-permission-override-plan.md` (paste the row from the review report's "Memory Hub INDEX.md Row" section); add WORKLOG row for F030
- [ ] T035 [P] Append a row for `030-per-server-permission-override.md` to `docs/planings-tasks/README.md` (feature index)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup (T001)**: No dependencies — can start immediately
- **Phase 2 Foundational (T002–T005)**: T001 must be done; T002/T003/T004/T005 can all run in parallel (different files); **BLOCKS every user-story phase** — no closure/save/UI code can run against a non-existent column
- **Phase 3 US1 (T006–T017)**: Depends on Phase 2 complete
- **Phase 4 US2 (T018–T020)**: Depends on Phase 3 US1 complete (uses the same `PermissionOverrideProcessor` class)
- **Phase 5 US4 (T021–T027)**: Depends on Phase 2 complete; can run in PARALLEL with Phases 3/4 by a different developer (different files: promo card is a separate class, addon filter entry is a separate registration, `AccessControlTab::render_body()` edits are sequential-only if same developer)
- **Phase 6 Polish (T028–T035)**: T028–T030 depend on nothing structural; T031 depends on all implementation phases; T032/T033/T034/T035 depend on T031 green

### User Story Dependencies

- **US3 (Safe upgrade, P2)**: Bundled into Phase 2 Foundational — acceptance scenarios are exactly the D28 column-add procedure
- **US1 (Operator enables toggle, P1)**: Depends on Foundational (Phase 2) — is the MVP slice
- **US2 (Isolation invariant, P1)**: Depends on US1 implementation (shares `PermissionOverrideProcessor`); its tests are additive
- **US4 (Promo card + code fallback, P2)**: Depends on Foundational only; independent of US1/US2 — could ship separately if desired

### Within each user story

- Tests written FIRST and confirmed FAILING before implementation (per constitution §VII DoD + spec §Definition of Done)
- Models/schema before services (Phase 2 completes before any US* work)
- Services before UI (`PermissionOverrideProcessor` before `AccessControlTab` edits)
- Same-file edits are always sequential (`AccessControlTab.php` in T014 → T026 → T027)

### Parallel Opportunities

- **T002/T003/T004/T005** — all parallel (schema, table, row, test file are 4 different files)
- **T006/T007/T008/T009/T010/T011** — all parallel (different test files or different test classes)
- **T018/T019/T020** — all parallel
- **T021/T022/T023** — all parallel
- **US1 + US4 dev streams** — a two-developer team can run Phase 3 (US1/US2) and Phase 5 (US4) in parallel after Phase 2 completes, meeting only at shared-file edits to `AccessControlTab.php`
- **T028/T029/T030/T035** — all parallel documentation/asset tasks

---

## Parallel Example: Phase 2 Foundational

```bash
# All four can run concurrently — different files, no cross-dependencies:
Task: "Append override_abilities_permission entry to includes/Database/MCPServer/Schema.php"
Task: "Bump $version + register upgrade_to_1_1_2 in includes/Database/MCPServer/Table.php"
Task: "Add public property + int cast in includes/Database/MCPServer/Row.php"
Task: "Create D28 upgrade regression test in tests/phpunit/Database/MCPServer/PermissionOverrideColumnUpgradeTest.php"
```

## Parallel Example: US1 Tests

```bash
# All six US1 tests parallelize — separate assertion targets, no fixture coupling:
Task: "Save-handler nonce rejection test in tests/phpunit/Admin/Partials/SettingsPermissionOverrideSaveTest.php"
Task: "Save-handler capability rejection test in tests/phpunit/Admin/Partials/SettingsPermissionOverrideSaveTest.php"
Task: "Closure null-server fall-through in tests/phpunit/Abilities/PermissionOverrideProcessorTest.php"
Task: "Closure override-off fall-through in tests/phpunit/Abilities/PermissionOverrideProcessorTest.php"
Task: "Closure not-exposed fall-through in tests/phpunit/Abilities/PermissionOverrideProcessorTest.php"
Task: "Closure override-on + exposed returns true in tests/phpunit/Abilities/PermissionOverrideProcessorTest.php"
```

---

## Implementation Strategy

### MVP First (US1 + Foundational + US3-bundled)

1. Phase 1 Setup — T001
2. Phase 2 Foundational — T002–T005 (parallelizable)
3. Phase 3 US1 — T006–T017 (tests first, then impl)
4. **STOP AND VALIDATE**: All 6 US1 tests green, admin can toggle + save, MCP calls behave as expected
5. Ship as MVP if desired — US2 tests (Phase 4) validate the invariants, US4 (promo card) is P2

### Incremental Delivery

1. Setup + Foundational → Foundation ready (column exists, upgrader idempotent)
2. Add US1 → Test independently → Ship MVP
3. Add US2 tests → Prove isolation invariants → Ship confidence
4. Add US4 (promo card) → Test independently → Ship the discoverability improvement
5. Phase 6 Polish → Docs, changelog, marketing assets, memory capture → Ship the PR

### Parallel Team Strategy

- Developer A: Phase 3 US1 (`PermissionOverrideProcessor` + `AccessControlTab` form section + save handler)
- Developer B: Phase 5 US4 (`AddonsFilter` extension + `AbilitiesManagerPromoCard`)
- Sync at `AccessControlTab::render_body()` edits (T014 vs T026/T027) — same file, sequential order
- Developer C: Phase 4 US2 tests + Phase 6 Polish

---

## Security Task Traceability (SEC-030-* remediation)

Per the plan-phase security review at `docs/security-reviews/2026-07-20-030-per-server-permission-override-plan.md`:

| SEC ID | Severity | Remediating Task | Notes |
|---|---|---|---|
| SEC-030-001 | MEDIUM | T014 (inline `<script>` MUST use `wp_json_encode()`) | Ship-blocker — verified by T032 staged review |
| SEC-030-002 | LOW | T016 (`do_action('acrossai_mcp_permission_override_toggled', …)`) | Fires operator-visible observability signal |
| SEC-030-003 | LOW | T027 (`<details>` documents priority slot map) + T033 (`B35` memory capture) | Filter-priority footrace documented; boot-time diagnostic deferred to follow-up feature |
| SEC-030-004 | INFO | T028 (trust-boundary paragraph in spec §Security Checklist) | Documentation-only |
| SEC-030-005 | INFO | T024 (`download_url` explicit `https://` in addon entry) | HTTPS pinning |

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks
- [Story] label maps task to US1/US2/US4 for traceability
- US3 is bundled into Phase 2 Foundational (its "acceptance scenarios" = the D28 upgrade procedure)
- Every user story is independently testable and independently deliverable — Phase 5 US4 could ship first if the team prefers to lead with the promo card discoverability play, then follow with US1/US2 in a second PR
- Commit after each task or logical group; do NOT bundle unrelated edits
- Verify PHPUnit tests FAIL before implementing the corresponding impl task (constitution §VII TDD gate)
- Avoid: vague tasks (all tasks name exact files); cross-story dependencies that break independence; skipping the `wp_json_encode()` in T014 (would reintroduce SEC-030-001)
