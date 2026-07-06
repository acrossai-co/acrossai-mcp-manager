---

description: "Task list — Feature 016 Remove Claude Connectors"
---

# Tasks: Remove Claude Connectors

**Input**: Design documents from `/specs/016-remove-claude-connectors/`
**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/retired-artifacts.md`, `quickstart.md`, `security-constraints.md`

**Tests**: Test tasks are INCLUDED for Feature 016 because (a) the plan retires 22 PHPUnit files under `tests/phpunit/OAuth/` — those deletions ARE test tasks; and (b) 3 remaining PHPUnit files require pruning to keep the suite green. No net-new tests are added.

**Organization**: Tasks are grouped by user story. Feature 016 is a subtractive-edit retirement, so most tasks are file deletions and surgical edits. All 4 user stories are P1 — implementation happens in a single PR, not incremental releases.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel with other [P] tasks in the same phase (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1 admin UI leaner / US2 operator retires prior schema / US3 fresh install lean / US4 CLI auth regression protection)
- Paths are absolute per `plan.md` §Project Structure

## Path Conventions

- Plugin root: `/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/plugins/acrossai-mcp-manager`
- All paths below are project-relative to that root

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Capture the pre-flight grep baseline before any deletions land — this is the reference against which the FR-015 final audit compares.

- [ ] T001 Capture pre-flight grep baseline. Run from plugin root:
  ```
  grep -rEn '(claude[_-]connector|ClaudeConnector|OAuth\\\\(Storage|AuditLog|TokenController|BearerAuth|PKCE|CliCommand)|OAuthToken|OAuthAudit|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_oauth_cleanup|frontend-oauth)' --include='*.php' --include='*.js' --include='*.scss' --include='*.css' --include='*.json' includes/ admin/ public/ src/ tests/ webpack.config.js uninstall.php acrossai-mcp-manager.php > specs/016-remove-claude-connectors/pre-flight-callers.txt
  ```
  Commit `pre-flight-callers.txt` as a reference artifact.
- [ ] T002 [P] Verify B15 grep-hygiene guard works. Create scratch file `/tmp/scratch-b15.php` with `<?php use \AcrossAI_MCP_Manager\Includes\OAuth\Storage;` and `new \AcrossAI_MCP_Manager\Includes\OAuth\Storage();`. Copy into `includes/` temporarily. Run the T001 grep. Confirm 2 hits appear (proves ERE `\\?` alternation matches both bare-`use` and leading-`\` FQN forms). Delete the scratch file. If the grep misses one form, the FR-015 audit at T031 will produce false PASS on that form and must be fixed before proceeding.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: None. Feature 016 is subtractive-only; no shared infrastructure needs to land before the deletions can begin. All user stories can execute in parallel once T001–T002 complete.

**Checkpoint**: Setup ready — all user-story phases below can proceed in parallel.

---

## Phase 3: User Story 1 — Admin UI is leaner (Priority: P1) 🎯 MVP

**Goal**: Site admin no longer sees Claude Connectors surface — no per-server tab, no Settings → MCP section, no shortcode registration.

**Independent Test**: On an install with the previous plugin version, apply this phase's edits, reactivate, confirm server-edit page renders 10 tabs (was 11) and Settings → MCP has no "Claude Connectors" section. Insert `[acrossai_mcp_claude_connector_block server=1]` on a test page — renders as literal shortcode text.

- [ ] T003 [P] [US1] Delete `admin/Partials/ServerTabs/ClaudeConnectorTab.php` (entire file).
- [ ] T004 [P] [US1] Delete `admin/Partials/ConnectorAuditLogListTable.php` (entire file).
- [ ] T005 [P] [US1] Delete `public/Renderers/ClaudeConnectorBlock.php` (entire file).
- [ ] T006 [US1] Modify `admin/Partials/ServerTabs/Registry.php` — remove the `new ClaudeConnectorTab()` entry from `all_tabs()` (should leave exactly 10 tab instances) and remove the `use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\ClaudeConnectorTab;` import at the top.
- [ ] T007 [US1] Modify `admin/Partials/Settings.php` — (a) remove `'save_claude_connector'` from the allowed-actions whitelist in `handle_actions()`; (b) delete the entire `if ( 'save_claude_connector' === $action ) { ... }` handler branch; (c) delete the `handle_save_claude_connector()` private method in its entirety.
- [ ] T008 [US1] Modify `admin/Partials/SettingsMenu.php` — delete (a) the `register_setting( 'acrossai-settings', 'acrossai_mcp_claude_connectors_enabled', ... )` call; (b) the `add_settings_section()` + `add_settings_field()` calls for the "Claude Connectors" section; (c) the `render_claude_connectors_section_description()` method.
- [ ] T009 [US1] Modify `includes/REST/ClientRendererController.php` — delete (a) the `add_shortcode( 'acrossai_mcp_claude_connector_block', ... )` registration inside `register_shortcodes_and_actions()`; (b) the `'claude-connector' => ClaudeConnectorBlock::class` entry in the dispatch map inside `dispatch_render_action()`; (c) the `use AcrossAI_MCP_Manager\Public\Renderers\ClaudeConnectorBlock;` import at the top.
- [ ] T010 [P] [US1] Modify `tests/phpunit/Admin/ServerTabs/RegistryTest.php` — remove `'claude-connector'` from the expected-tabs slug list; update tab-count assertion from 11 to 10.
- [ ] T011 [P] [US1] Modify `tests/phpunit/Admin/SettingsMenuTest.php` — remove any assertion about `acrossai_mcp_claude_connectors_enabled` registration and any "Claude Connectors" section presence check.
- [ ] T012 [P] [US1] Handle `tests/phpunit/Public/Renderers/PublicApiTest.php`. Read the file first. If EVERY test method targets `ClaudeConnectorBlock`, delete the entire file. Otherwise, remove only the connector-specific test methods + fixtures; keep the file with the residual assertions.

**Checkpoint**: US1 complete. Admin surface has no Claude Connectors references. Manual smoke test per Independent Test above.

---

## Phase 4: User Story 2 — Operator retires prior schema manually (Priority: P1)

**Goal**: Plugin ships with zero references to the retired OAuth infrastructure, tables, columns, or cron event. Operator handles physical data retirement via the manual SQL + WP-CLI recipe in `spec.md` §User Story 2.

**Independent Test**: On an install with pre-Feature-016 data (13-column MCPServer, 2 OAuth tables, scheduled cron), run the operator's manual recipe (`spec.md` §User Story 2), reactivate the plugin, verify reactivation completes without fatal, `SHOW WARNINGS` empty, `wp cron event list` shows no `acrossai_mcp_oauth_cleanup`.

- [ ] T013 [P] [US2] Delete `includes/OAuth/` directory in its entirety — 7 files: `ClaudeConnectors.php`, `Storage.php`, `AuditLog.php`, `TokenController.php`, `BearerAuth.php`, `PKCE.php`, `CliCommand.php`. Directory should not exist after this task.
- [ ] T014 [P] [US2] Delete `includes/Database/OAuthToken/` directory in its entirety — 4 files: `Table.php`, `Schema.php`, `Query.php`, `Row.php`. Directory should not exist after this task.
- [ ] T015 [P] [US2] Delete `includes/Database/OAuthAudit/` directory in its entirety — 4 files: `Table.php`, `Schema.php`, `Query.php`, `Row.php`. Directory should not exist after this task.
- [ ] T016 [US2] Modify `includes/Main.php` — delete (a) the `$claude_connectors = ClaudeConnectors::instance();` block and its four hook registrations (`init` → `register_rewrite_rules`, `query_vars` filter → `add_query_var`, `template_redirect` priority 9 → `serve_discovery_or_authorize`, `acrossai_mcp_oauth_cleanup` action → `handle_cleanup_event`) in `define_public_hooks()`; (b) the `$token_controller = TokenController::instance(); ... rest_api_init` block; (c) the `$bearer_auth = BearerAuth::instance(); ... determine_current_user` block; (d) the `wp_enqueue_scripts` hook registrations for `Public\Main::enqueue_styles` and `Public\Main::enqueue_scripts`; (e) the two `Table::instance()` calls for `OAuthToken\Table` + `OAuthAudit\Table` in `bootstrap_database_tables()`; (f) all associated `use` imports at the top of the file. Preserve `MCPServer\Table::instance()` and `CliAuthLog\Table::instance()` boot calls verbatim.
- [ ] T017 [US2] Modify `includes/Activator.php` — delete (a) the `class_exists( ClaudeConnectors::class ) { ClaudeConnectors::instance()->register_rewrite_rules(); }` block; (b) the `if ( ! wp_next_scheduled( 'acrossai_mcp_oauth_cleanup' ) ) { wp_schedule_event(...) }` block; (c) the two `Table::instance()->maybe_upgrade()` calls for `OAuthToken\Table` + `OAuthAudit\Table` and their `use` imports. Preserve the existing final `flush_rewrite_rules()` call, `MCPServerTable::instance()->maybe_upgrade()`, `CliAuthLogTable::instance()->maybe_upgrade()`, and `DefaultServerSeeder::seed()` calls verbatim. Do NOT add any new destructive SQL or `delete_option` cleanup — operator handles that manually per spec §User Story 2.
- [ ] T018 [US2] Modify `includes/Deactivator.php` — delete the `wp_clear_scheduled_hook( 'acrossai_mcp_oauth_cleanup' )` line entirely. String is retired by FR-015; operator unschedules manually via `wp cron event unschedule acrossai_mcp_oauth_cleanup`.
- [ ] T019 [P] [US2] Modify `includes/Database/MCPServer/Schema.php` — delete the three column definitions: `claude_connector_client_id` (varchar 255 default ''), `claude_connector_client_secret` (varchar 255 default ''), `claude_connector_redirect_uri` (varchar 500 default ''). Preserve the other 10 columns' type/length/default/sortable/searchable metadata byte-for-byte. Do NOT bump `MCPServer/Table.php::$version` (fresh-install-only stance).
- [ ] T020 [P] [US2] Modify `includes/Database/MCPServer/Row.php` — delete the three `public $claude_connector_client_id`, `public $claude_connector_client_secret`, `public $claude_connector_redirect_uri` property declarations AND their three matching entries in the `to_array()` method's return array. Preserve all other properties and to_array entries verbatim.
- [ ] T021 [P] [US2] Modify `includes/Database/MCPServer/DefaultServerSeeder.php` — delete the three `'claude_connector_*' => ''` entries from the insert `$data` array AND the three matching `%s` format specifiers from the format array. Confirm the resulting seed insert compiles cleanly and matches the 10-column shape.
- [ ] T022 [P] [US2] Delete `tests/phpunit/OAuth/` directory in its entirety — 22 files including `fixtures/` subdirectory. Directory should not exist after this task.
- [ ] T023 [P] [US2] Delete `tests/phpunit/Public/MainEnqueueTest.php` — targets the deleted `Public\Main::enqueue_styles/scripts` methods; no reason to keep.

**Checkpoint**: US2 complete. All OAuth-code deletions landed. Plugin has no runtime references to retired OAuth infrastructure. Manual smoke: apply operator recipe on a pre-016 install, reactivate, verify no fatal + `SHOW WARNINGS` empty.

---

## Phase 5: User Story 3 — Fresh install ships lean (Priority: P1)

**Goal**: Fresh WordPress install activating the updated plugin creates only 2 MCP-owned tables, 10-column MCPServer, no OAuth options, no OAuth cron, no `acrossai-mcp-frontend-oauth` stylesheet.

**Independent Test**: On a fresh WP install with no prior AcrossAI plugin, activate the plugin. Verify `SHOW TABLES LIKE 'wp_acrossai_mcp_%'` returns 2 rows, `DESCRIBE wp_acrossai_mcp_servers` returns 10 columns, `wp cron event list` shows no OAuth cron, no `acrossai-mcp-frontend-oauth` enqueue.

- [ ] T024 [P] [US3] Modify `public/Main.php` — delete (a) the `enqueue_styles()` method in its entirety; (b) the `enqueue_scripts()` method in its entirety; (c) the `OAUTH_STYLE_HANDLE` constant declaration; (d) the `use AcrossAI_MCP_Manager\Includes\OAuth\ClaudeConnectors;` import at the top of the file. Confirm no remaining method body references `ClaudeConnectors::is_authorize_page()`.
- [ ] T025 [P] [US3] Delete `src/scss/frontend-oauth.scss` (entire file). Source SCSS is gone; the build step in T028 regenerates `build/` naturally.
- [ ] T026 [P] [US3] Modify `webpack.config.js` — remove the `'css/frontend-oauth': path.resolve( process.cwd(), 'src/scss', 'frontend-oauth.scss' )` entry from the `entry` object (currently lines 97–101). Preserve every other entry (`css/frontend`, `css/backend`, `js/frontend`, `js/backend`, `js/access-control`, block entries, etc.) verbatim.
- [ ] T027 [P] [US3] Delete `build/css/frontend-oauth.css`, `build/css/frontend-oauth-rtl.css`, and `build/css/frontend-oauth.asset.php` if they exist. (T028's `npm run build` regenerates the build dir cleanly, so this step is optional but keeps the intermediate state clean.)
- [ ] T028 [US3] Run `npm run build` from plugin root. Verify: (a) exit code 0; (b) no error/warning output referencing frontend-oauth; (c) no `build/css/frontend-oauth*` files exist in the output.

**Checkpoint**: US3 complete. Fresh install path is lean. Manual smoke on a disposable WP install: activate, run `wp db query "SHOW TABLES LIKE 'wp_acrossai_mcp_%'"` (expect 2 rows) + `wp db query "DESCRIBE wp_acrossai_mcp_servers"` (expect 10 rows).

---

## Phase 6: User Story 4 — CLI auth flow regression protection (Priority: P1)

**Goal**: The CLI auth stack (`FrontendAuth`, `CliController`, `wp_acrossai_mcp_cli_auth_logs`, App Passwords, `acrossai-mcp-frontend` stylesheet) continues to function unchanged. Bearer-header requests no longer elevate users.

**Independent Test**: On any install after Phases 3–5 land, initiate a WP-CLI-driven auth handshake, complete the browser approval, verify App Password is issued in `wp_usermeta` + row in `wp_acrossai_mcp_cli_auth_logs`, and confirm `Authorization: Bearer` headers no longer elevate users.

- [ ] T029 [P] [US4] Verify CLI auth stack files are untouched by the retirement. Run: `git diff --name-only main -- 'public/Partials/FrontendAuth.php' 'includes/REST/CliController.php' 'includes/Database/CliAuthLog/**' 'src/scss/frontend.scss' 'build/css/frontend*.css'`. Expected output: EMPTY. Any file listed here indicates accidental over-deletion — investigate and revert before proceeding.
- [ ] T030 [US4] Manual smoke on the Local site: (a) initiate the CLI auth flow (invoke whichever WP-CLI-driven client the plugin's canonical flow uses — see `docs/planings-tasks/phase-cli-auth.md`); (b) confirm the browser approval page loads with `acrossai-mcp-frontend` stylesheet enqueued (View Source); (c) approve the request in-browser; (d) run `wp db query "SELECT status, server_id, user_id FROM wp_acrossai_mcp_cli_auth_logs ORDER BY id DESC LIMIT 1"` — expect status='approved'; (e) run `curl -H "Authorization: Bearer 0123456789abcdef" https://LOCAL/wp-json/wp/v2/users/me` — expect 401 `rest_not_logged_in`.

**Checkpoint**: US4 complete. CLI auth still works. Bearer-header trust path retired.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Quality gates, grep audit, documentation, and memory-hygiene follow-ups.

- [ ] T031 Run the final FR-015 grep audit from plugin root:
  ```
  grep -rEn '(claude[_-]connector|ClaudeConnector|acrossai_mcp_claude_connectors_enabled|acrossai_mcp_oauth_cleanup|frontend-oauth|OAuthToken|OAuthAudit|OAuth\\\\(Storage|AuditLog|TokenController|BearerAuth|PKCE|CliCommand))' --include='*.php' --include='*.js' --include='*.scss' --include='*.css' --include='*.json' includes/ admin/ public/ src/ tests/ webpack.config.js uninstall.php acrossai-mcp-manager.php
  ```
  **Expected**: zero matches. If any hit surfaces, fix the missing deletion in the referenced file before proceeding. Diff against T001's baseline capture — expect every baseline hit is gone.
- [ ] T032 [P] Run `composer run phpcs` from plugin root — zero errors, zero warnings across all remaining files.
- [ ] T033 [P] Run `composer run phpstan` — zero errors at level 8 across all remaining files.
- [ ] T034 [P] Run `composer test` (PHPUnit) — all remaining tests pass. Suite is smaller (T022 removed 22 files, T023 removed 1, T010–T012 pruned 3) but should be green end-to-end.
- [ ] T035 [P] Run `npm run validate-packages` — zero warnings.
- [ ] T036 Update `README.txt` — add an Unreleased changelog block. Content:
  - Feature 016: Removed the Claude Connectors integration (OAuth 2.1 authorization server, admin tab, per-server audit log, settings toggle, three `claude_connector_*` MCPServer columns, two OAuth tables). The feature never worked with claude.ai's hosted Connectors UI on local installs and has been retired.
  - **Operator action required for pre-016 installs**: run the manual retirement recipe from `docs/planings-tasks/016-remove-claude-connectors.md` (DROP TABLE + ALTER TABLE + DELETE FROM wp_options + `wp cron event unschedule`) before reactivating the plugin. Include the pre-DROP secure-discard step from SEC-001: `UPDATE wp_acrossai_mcp_servers SET claude_connector_client_secret = '', claude_connector_redirect_uri = '';` BEFORE the `ALTER TABLE ... DROP COLUMN`.
  - **Behavior change (SEC-003)**: The plugin no longer accepts `Authorization: Bearer <token>` headers for user resolution. Integrators depending on that path should migrate to WordPress Application Passwords via the CLI auth flow (`public/Partials/FrontendAuth`).
  - **Operator advisory (SEC-002)**: If any active claude.ai connector tokens exist on your install, revoke them from claude.ai's Connectors UI BEFORE running the manual retirement SQL. The retirement drops the audit log with no recovery.
- [ ] T037 Queue post-implementation memory-hygiene follow-ups. This task is a REMINDER, not a code edit. After the PR merges, run `/speckit-memory-md-capture-from-diff` to propose these annotations:
  - `docs/memory/PROJECT_CONTEXT.md` — annotate `S7` ("OAuth token endpoint `__return_true` exception") with "no consumers post-F016; token endpoint retired".
  - `docs/memory/DECISIONS.md` — annotate `DEC-CLIENT-RENDERER-PUBLIC-API` (F013) with "post-F016: dispatch map shrinks to 2 entries (`npm`, `clients`); shortcode surface shrinks to 2 (`acrossai_mcp_npm_block`, `acrossai_mcp_clients_block`)".
  - `docs/memory/ARCHITECTURE.md` — annotate `A13` ("RFC-prescribed forms exempted from A4 DataForm") with "no active consumers post-F016; still valid for future RFC-prescribed forms".
  - `.specify/memory/constitution.md` — annotate Principle I Rationale (remove `OAuth / Claude Connectors` from the 5-active-area list) and Architecture Directory Layout (remove `includes/OAuth/` line).

**Checkpoint**: All quality gates green, grep audit clean, README updated, memory-hygiene queued.

---

## Dependencies

**Story completion order**: US1, US2, US3, US4 are LARGELY INDEPENDENT within Phase 3–6 but share Phase 1 setup and Phase 7 polish.

- **Phase 1 (T001–T002)** MUST complete before ANY US phase — baseline capture and B15 grep-hygiene verification are prerequisites.
- **US1 (T003–T012)** ⇔ **US2 (T013–T023)**: nearly independent. T016 (Main.php) touches hooks referenced by US1 [T009 ClientRendererController] but from different code regions — safe to interleave. T017–T018 (Activator/Deactivator) do not overlap with US1 files.
- **US3 (T024–T028)**: depends on US2's T016 (removal of Main.php's `wp_enqueue_scripts` hook registrations) so that removing `Public\Main::enqueue_styles/scripts` methods in T024 doesn't leave dangling hook targets. Sequence: complete T016 before T024.
- **US4 (T029–T030)**: verification-only; runs AFTER US1 + US2 + US3 complete because it validates the negative-space (nothing important got broken).
- **Phase 7 (T031–T037)**: runs AFTER all US phases complete. T031 grep depends on ALL deletions being in place. Quality gates T032–T035 also require all deletions.

**Serialization requirement**: Within US2, T016 (Main.php) must complete before T013–T015 (deletion of the classes Main.php references) OR the class-file deletions must happen inside the same commit as T016 to avoid a broken intermediate state where Main.php references non-existent classes. Recommend committing T013+T016 together (and T014, T015 same commit as T016) to keep any bisect landing on a workable state.

**Parallelism inside US1**: T003, T004, T005 are independent file deletions (parallelizable). T010, T011, T012 are independent test-file edits (parallelizable). T006, T007, T008, T009 each edit distinct source files (parallelizable if committed together; the plugin is not runnable mid-way through).

**Parallelism inside US2**: T013, T014, T015 are independent directory deletions. T019, T020, T021 edit distinct files in the same module (parallelizable but conceptually paired). T022, T023 are independent test deletions.

**Parallelism inside US3**: T024, T025, T026, T027 are independent file edits/deletions. T028 (`npm run build`) is a barrier — must run after T025–T027 land.

**Parallelism inside Phase 7**: T032, T033, T034, T035 are independent tool invocations (parallelizable). T036 is a manual documentation edit (independent). T037 is a REMINDER task (no execution needed until post-merge).

---

## Parallel Execution Examples

### US1 (all edits share the same commit boundary)

```bash
# Batch 1 — parallel file deletions (no cross-dependencies)
rm admin/Partials/ServerTabs/ClaudeConnectorTab.php
rm admin/Partials/ConnectorAuditLogListTable.php
rm public/Renderers/ClaudeConnectorBlock.php

# Batch 2 — parallel source-file edits (each touches a distinct file)
# T006 admin/Partials/ServerTabs/Registry.php
# T007 admin/Partials/Settings.php
# T008 admin/Partials/SettingsMenu.php
# T009 includes/REST/ClientRendererController.php

# Batch 3 — parallel test-file edits (independent)
# T010 tests/phpunit/Admin/ServerTabs/RegistryTest.php
# T011 tests/phpunit/Admin/SettingsMenuTest.php
# T012 tests/phpunit/Public/Renderers/PublicApiTest.php
```

### US2 (directory + file deletions in parallel; Main.php edit is critical-path)

```bash
# Batch 1 — parallel directory + test deletions (safe before Main.php edit? NO — must land same commit as T016)
rm -rf includes/OAuth/
rm -rf includes/Database/OAuthToken/
rm -rf includes/Database/OAuthAudit/
rm -rf tests/phpunit/OAuth/
rm tests/phpunit/Public/MainEnqueueTest.php

# Batch 2 — parallel MCPServer/ edits (Schema/Row/DefaultServerSeeder)
# T019 Schema.php delete 3 column defs
# T020 Row.php delete 3 properties + 3 to_array entries
# T021 DefaultServerSeeder.php delete 3 seed entries + %s specifiers

# Batch 3 — serialized (must follow Batches 1–2 above)
# T016 includes/Main.php surgical edit
# T017 includes/Activator.php surgical edit
# T018 includes/Deactivator.php surgical edit
```

### US3 (build barrier)

```bash
# Batch 1 — parallel source + config edits
rm src/scss/frontend-oauth.scss                                   # T025
# T026 webpack.config.js edit
# T024 public/Main.php edit

# Optional cleanup
rm build/css/frontend-oauth.css build/css/frontend-oauth-rtl.css build/css/frontend-oauth.asset.php  # T027

# Barrier
npm run build                                                     # T028
```

### Phase 7 (quality gates in parallel, README + memory-hygiene serial)

```bash
# Batch 1 — parallel tool invocations
composer run phpcs           # T032
composer run phpstan         # T033
composer test                # T034
npm run validate-packages    # T035

# Serial follow-ups
# T031 FR-015 grep audit (MUST be green before merge)
# T036 README.txt Unreleased edit
# T037 (post-merge reminder)
```

---

## Implementation Strategy

### MVP scope

All 4 user stories are P1. The MVP IS the whole retirement. Splitting into smaller PRs would leave the plugin in a half-migrated state where some connector references linger — worse than the pre-016 state because the code becomes internally inconsistent (e.g., admin tab removed but backing class still exists). Ship as one PR.

### Suggested commit boundaries within the single PR

To keep any git bisect operation landing on a workable intermediate state, group commits so each preserves plugin loadability:

1. **Commit 1 — Pre-flight** (T001, T002): capture baseline, verify grep hygiene.
2. **Commit 2 — Admin UI leaner** (T003–T012): US1 edits. Plugin still activates cleanly; admin surface is smaller.
3. **Commit 3 — OAuth infrastructure removal** (T013–T023): US2 edits. This is the risky commit — landing all deletions + Main.php/Activator/Deactivator edits together avoids the "references non-existent class" intermediate state. Verify plugin activates without fatal on the Local install BEFORE committing.
4. **Commit 4 — Fresh-install lean** (T024–T028): US3 edits + build regeneration.
5. **Commit 5 — Regression verification + polish** (T029–T036): US4 verification + quality gates + README.
6. **Post-merge**: T037 memory-hygiene capture as a separate follow-up PR.

### Rollback

If any commit produces a plugin that won't activate, `git reset --hard HEAD~1` reverts the offending commit. The Local install can be reset to a snapshot at any point — it is disposable per project convention.

---

## Format Validation

Every task above follows the required checklist format:

- ✅ Starts with `- [ ]`
- ✅ Sequential T### ID
- ✅ `[P]` marker where parallelizable
- ✅ `[Story]` label on Phase 3–6 tasks (US1/US2/US3/US4); NO label on Phase 1/2/7
- ✅ Concrete file paths in every task description

**Total tasks**: 37 (T001–T037)
- Phase 1 Setup: 2 (T001–T002)
- Phase 2 Foundational: 0
- Phase 3 US1: 10 (T003–T012)
- Phase 4 US2: 11 (T013–T023)
- Phase 5 US3: 5 (T024–T028)
- Phase 6 US4: 2 (T029–T030)
- Phase 7 Polish: 7 (T031–T037)
