---

description: "Task list for Phase 4 — MCP Client Classes (Pure Service Layer)"
---

# Tasks: MCP Client Classes — Pure Service Layer

**Feature**: `specs/004-mcp-clients/` | **Branch**: `004-mcp-clients`
**Input**: `plan.md`, `spec.md`, `research.md`, `quickstart.md`,
`security-review-plan.md`, `security-constraints.md`, `governance-summary.md`,
`memory-synthesis.md`

**Tests**: Spec DoD requires "PHPUnit tests written and passing for
`AbstractMCPClient`'s helpers and for each of the 7 concrete clients
(golden-fixture snippet assertions)" → tests **ARE included** in this
task list. Pattern: implementation file + matching test file + 2
golden-fixture files per concrete client.

**Organization**: Tasks are grouped by user story so each story is
independently completable and testable. Mapping: **US1**=admin
end-to-end · **US2**=per-client correctness · **US3**=new-client
extensibility · **US4**=architectural purity.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Maps task to user story (US1…US4); Setup/Foundational/Polish have no story label
- File paths are repo-root-relative; every code task names the exact file

## Path Conventions

- WordPress plugin layout (constitution Architecture & UI Standards):
  - `includes/MCPClients/*.php` — new module
  - `tests/phpunit/MCPClients/*Test.php` — test classes
  - `tests/phpunit/MCPClients/fixtures/*` — golden snippet fixtures
- Source repo to consult for canonical AI-tool envelope shapes (V2=redesign
  framing per governance-summary.md): `../acrossai-mcp-manager/src/MCPClients/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Pre-flight checks before any code change.

- [ ] T001 Verify the feature directory is intact — `specs/004-mcp-clients/{spec,plan,research,quickstart,security-review-plan,security-constraints,governance-summary,memory-synthesis}.md` and `checklists/requirements.md` all present
- [ ] T002 [P] Confirm `phpcs.xml.dist` Phase 1 baseline exclusions still apply (D5 — filename casing, `$_instance` prefix, file docblocks). MCPClient files will hit `$_instance`-related rules only if they violate FR-009; no new exclusions should be needed.
- [ ] T003 [P] Confirm `composer.json` PSR-4 mapping (`"AcrossAI_MCP_Manager\\Includes\\": "includes/"`) is intact. The new `includes/MCPClients/*.php` files will autoload through that mapping without composer.json edits.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Hard gates and scaffolding that every user story depends on.

**⚠️ CRITICAL**: No user story work may begin until **T004** passes.

- [ ] T004 **P0 GATE — STOP if it fails**: Verify the PHPUnit harness exists. Required: `tests/phpunit/` directory, `phpunit.xml.dist` config, `vendor/bin/phpunit` binary, and a bootstrap that PSR-4-loads `AcrossAI_MCP_Manager\Includes\*` **without** loading WordPress (SC-003). If absent, escalate as the Phase 2 RT-4 follow-up dependency from the architecture review — do NOT bundle harness setup into this phase.
- [ ] T005 Create directory `includes/MCPClients/` (no files yet)
- [ ] T006 [P] Create directory `tests/phpunit/MCPClients/` and `tests/phpunit/MCPClients/fixtures/`
- [ ] T007 [P] After T005, run `composer dump-autoload` to refresh the classmap so the empty `includes/MCPClients/` directory is autoloader-discoverable (or confirm PSR-4 reflection handles it automatically — `automattic/jetpack-autoloader` does)

**Checkpoint**: Foundation ready — directories exist, harness verified, no code written yet. User stories can begin.

---

## Phase 3: User Story 2 — AbstractMCPClient base + helpers (P1)

**Goal**: Ship the abstract base class with all 4 protected helpers
(`build_server_url`, `derive_server_key`, `safe_token`, `redact_token`)
and the public static factory (`get_all_clients()`) and its complete
test coverage. This is a prerequisite for every concrete client port.

**Independent Test**: Run
`vendor/bin/phpunit tests/phpunit/MCPClients/AbstractMCPClientTest.php`
— all helper assertions pass; `get_all_clients()` returns an empty array
when no concrete classes exist yet (verified separately in Phase 4).

### Implementation for User Story 2 (base class)

- [ ] T008 [US2] Implement `includes/MCPClients/AbstractMCPClient.php` per spec FR-001/FR-002/FR-003 + plan §Method signatures + research.md R2/R3/R4:
  1. Namespace `AcrossAI_MCP_Manager\Includes\MCPClients`
  2. `defined( 'ABSPATH' ) || exit;` guard (kept so the file is safe inside WP; tests bypass via composer autoload)
  3. Three abstract methods: `get_client_slug(): string`, `get_client_name(): string`, `get_config_snippet(string $server_url, string $auth_token): string|array`
  4. Static factory `public static function get_all_clients(): array` per R4 (glob + class_exists + is_subclass_of, skip `AbstractMCPClient.php` itself)
  5. Protected helper `build_server_url(string $base_rest_url, string $route_namespace, string $route): string` — pure string concat with trailing-slash hygiene
  6. Protected helper `derive_server_key(string $server_url): string` per R2 (strtok → rtrim → explode → last segment → fallback `'wordpress-mcp'`)
  7. Protected helper `safe_token(string $token): string` per R3 — returns `'(paste generated password here)'` when empty, else returns `$token` verbatim
  8. Protected helper `redact_token(string $token): string` per spec FR-002 — `substr($token, 0, 4) . '…' . substr($token, -2)`, or `'(empty)'` for empty input

### Tests for User Story 2 (base class)

- [ ] T009 [US2] Implement `tests/phpunit/MCPClients/AbstractMCPClientTest.php` covering all helpers (one test file = sequential edits within the file, so NOT marked [P]):
  - `testDeriveServerKeyMatrix` — assert all 7 input/output pairs from research.md R2 table
  - `testSafeTokenReturnsPlaceholderOnEmpty` — `assertSame('(paste generated password here)', $sut->safe_token(''))`
  - `testSafeTokenReturnsRawOnNonEmpty` — `assertSame('xyz', $sut->safe_token('xyz'))`
  - `testRedactTokenFirstFourLastTwo` — assert `'abcd…78' === $sut->redact_token('abcdef5678')`
  - `testRedactTokenEmptyReturnsEmptyMarker` — assert `'(empty)' === $sut->redact_token('')`
  - `testBuildServerUrlConcatenatesPathsCorrectly` — assert URLs with/without trailing slashes compose cleanly
  - `testGetAllClientsReturnsEmptyWhenNoConcreteClasses` — before Phase 4, expected to be empty (will flip to "returns exactly 7" after Phase 4 lands; gate task T017 re-asserts the post-Phase-4 count)
  - `testGetAllClientsExcludesAbstractClass` — even after Phase 4 ships, `AbstractMCPClient` itself MUST NOT appear in the returned array
  - Use a Reflection trampoline (`getProtectedMethod()`) to invoke protected helpers from test scope; OR define a small test-only `TestableAbstractClient` subclass in the test file's namespace

**Checkpoint**: Abstract base + helpers + tests complete. Phase 4's 7
concrete clients depend on this.

---

## Phase 4: User Story 2 — 7 concrete client classes (P1)

**Goal**: Implement all 7 concrete client classes with their test
classes and golden fixtures. Each task is self-contained (one
implementation file + one test file + 2 fixture files) so all 7 can
run in parallel by different developers/agents.

**Independent Test**: For each client, run
`vendor/bin/phpunit tests/phpunit/MCPClients/<ClientName>Test.php` —
all golden-fixture assertions pass.

**Source-repo guidance** (V2=redesign per governance-summary.md): read
`../acrossai-mcp-manager/src/MCPClients/<ClientName>Client.php` for
the AI tool's `config_file` path and `top_level_key` to mirror in the
new 3-method interface. Do NOT copy the 6-method source interface
verbatim.

### Implementation for User Story 2 (concrete clients)

For each task below, the deliverable is **3-4 new files**:
1. `includes/MCPClients/<Name>Client.php` — concrete subclass with `get_client_slug()`, `get_client_name()`, `get_config_snippet()`
2. `tests/phpunit/MCPClients/<Name>ClientTest.php` — slug + name + snippet-with-token + snippet-empty-token assertions
3. `tests/phpunit/MCPClients/fixtures/<slug>-with-token.{json,txt}` — golden fixture for non-empty token
4. `tests/phpunit/MCPClients/fixtures/<slug>-empty-token.{json,txt}` — golden fixture for empty token (verifies safe_token() placeholder substitution)

The fixture file extension follows the return type: `.json` for array-returning clients (most), `.txt` for string-returning clients (Claude Code only).

- [ ] T010 [P] [US2] **ClaudeCodeClient** (string return — CLI install command) — implement `includes/MCPClients/ClaudeCodeClient.php` (slug `claude-code`, name `"Claude Code"`); snippet shape: `claude mcp add <derive_server_key($url)> -- npx -y @automattic/mcp-wordpress-remote@latest` with shell-escaped env vars for `WP_API_URL` and `WP_API_PASSWORD`. Add `tests/phpunit/MCPClients/ClaudeCodeClientTest.php` + `fixtures/claude-code-with-token.txt` + `fixtures/claude-code-empty-token.txt`.
- [ ] T011 [P] [US2] **ClaudeDesktopClient** (array, `mcpServers` envelope) — implement `includes/MCPClients/ClaudeDesktopClient.php` (slug `claude-desktop`, name `"Claude Desktop"`); snippet shape per research.md R1 with top-level key `mcpServers`. Add `ClaudeDesktopClientTest.php` + `fixtures/claude-desktop-with-token.json` + `fixtures/claude-desktop-empty-token.json`.
- [ ] T012 [P] [US2] **CodexClient** (array, `mcpServers` envelope) — implement `includes/MCPClients/CodexClient.php` (slug `codex`, name `"Codex"`); target `~/.codex/config.json`. Add `CodexClientTest.php` + 2 fixtures.
- [ ] T013 [P] [US2] **CursorClient** (array, `mcpServers` envelope) — implement `includes/MCPClients/CursorClient.php` (slug `cursor`, name `"Cursor"`); target `~/.cursor/mcp.json`. Add `CursorClientTest.php` + 2 fixtures.
- [ ] T014 [P] [US2] **CustomClient** (array, `mcpServers` envelope, generic template) — implement `includes/MCPClients/CustomClient.php` (slug `custom`, name `"Custom Client"`); snippet includes a comment explaining the user should adapt the envelope to their MCP-compatible tool. Add `CustomClientTest.php` + 2 fixtures.
- [ ] T015 [P] [US2] **GitHubCopilotClient** (array, `mcp.servers` namespaced envelope) — implement `includes/MCPClients/GitHubCopilotClient.php` (slug `github-copilot`, name `"GitHub Copilot"`); snippet shape: `[ 'mcp' => [ 'servers' => [ $key => $inner ] ] ]` per Copilot preview spec. Add `GitHubCopilotClientTest.php` + 2 fixtures.
- [ ] T016 [P] [US2] **VSCodeClient** (array, `mcp.servers` envelope) — implement `includes/MCPClients/VSCodeClient.php` (slug `vscode`, name `"VS Code"`); target `.vscode/mcp.json`. Add `VSCodeClientTest.php` + 2 fixtures.

**Checkpoint**: All 7 concrete clients implemented with tests and
fixtures. Phase 3's `testGetAllClientsReturnsEmptyWhenNoConcreteClasses`
in `AbstractMCPClientTest` must be updated to
`testGetAllClientsReturnsExactlySevenClients` and re-run.

---

## Phase 5: User Story 1 — End-to-end smoke (P1)

**Goal**: Prove the implementation works as a user-facing flow:
generate a snippet without any WordPress bootstrap, dump it, confirm
the envelope shape matches the AI tool's expectations.

**Independent Test**: A single `php -r '...'` one-liner that requires
`vendor/autoload.php`, instantiates `ClaudeDesktopClient`, calls
`get_config_snippet()`, and asserts the output structure.

- [ ] T017 [US1] Update `AbstractMCPClientTest::testGetAllClientsReturnsEmptyWhenNoConcreteClasses` from Phase 3 to `testGetAllClientsReturnsExactlySevenClients`; add `testGetAllClientsReturnsSortedSlugs` (asserts alphabetical order by slug). Re-run the full test suite.
- [ ] T018 [US1] Run quickstart.md §6 smoke (without WP bootstrap):
   ```bash
   php -r 'require_once "vendor/autoload.php"; $c = new \AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeDesktopClient(); var_dump($c->get_config_snippet("https://example.com/wp-json/mcp/test-server", "secret123"));'
   ```
   Assert: top-level key `mcpServers`, inner key `test-server`, `env.WP_API_URL === "https://example.com/wp-json/mcp/test-server"`, `env.WP_API_PASSWORD === "secret123"`. Verifies SC-003.

**Checkpoint**: End-to-end smoke passes. Snippets are syntactically
correct and ready for the Phase 2 RT-3 amendment to render them in
the Tokens tab.

---

## Phase 6: User Story 3 — New-client extensibility (P2)

**Goal**: Prove that adding a new client requires exactly one new file
(SC-002). Verifies the `get_all_clients()` file-scan mechanism works
without code changes elsewhere.

**Independent Test**: Drop a stub `WindsurfClient.php` into
`includes/MCPClients/`, re-run `get_all_clients()`, assert count is 8;
remove the stub, assert count is 7.

- [ ] T019 [US3] Create a temporary stub file `includes/MCPClients/_TestExtensibilityStubClient.php` (note the underscore prefix to distinguish from real clients — implementer may use any name); make it extend `AbstractMCPClient` and implement the 3 required methods with trivial returns. Run `php -r 'require_once "vendor/autoload.php"; var_dump( count( \AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient::get_all_clients() ) );'` — expect 8. Delete the stub file. Re-run — expect 7. **Result MUST be auto-discovery worked without any other file edit.** Document the result in a one-line comment in `tests/phpunit/MCPClients/fixtures/_extensibility-check.md` (or equivalent).

**Checkpoint**: SC-002 verified. No spec change needed; behavior
matches FR-010.

---

## Phase 7: User Story 4 — Architectural purity (P2)

**Goal**: Verify the FR-008 / FR-009 invariants by grep. Pure service
classes — no hooks, no DB, no HTTP, no cookies, no raw output, no
singleton.

**Independent Test**: Three greps. All MUST return empty.

- [ ] T020 [US4] FR-008 grep gate: `grep -rnE 'add_action|add_filter|\$wpdb|wp_remote_(get|post)|setcookie|^[^*/]*\b(echo|print)\b' includes/MCPClients/` MUST return empty. If any match found, the implementation regressed — fix before merging.
- [ ] T021 [US4] FR-009 grep gate: `grep -rn 'public static function instance' includes/MCPClients/` MUST return empty (no singleton ceremony). Then `grep -rn 'public static function get_all_clients' includes/MCPClients/` MUST return exactly 1 match (the AbstractMCPClient static factory).
- [ ] T022 [US4] SC-003 verification: `vendor/bin/phpunit tests/phpunit/MCPClients/` MUST run to completion WITHOUT `wp-config.php` or `wp-load.php` being in the include path or having been required. Inspect the test bootstrap to confirm.

**Checkpoint**: All three purity invariants verified by grep + test
run. Architectural contract holds.

---

## Phase 8: Polish & Cross-Cutting (DoD + final verification)

**Purpose**: Final gate checks before merge. Most tasks are
parallelizable static-analysis or doc updates.

### Required verification gates

- [ ] T023 [P] FR-007 slug uniqueness check: run the inline PHP one-liner from quickstart.md §5 — `array_unique()` of all `get_client_slug()` returns MUST equal the original array length (7).
- [ ] T024 [P] Run `vendor/bin/phpcs includes/MCPClients/` — expected **0 errors, 0 warnings**. Phase 1 baseline exclusions in `phpcs.xml.dist` remain authoritative.
- [ ] T025 [P] Run `vendor/bin/phpstan analyse includes/MCPClients/ --level=8` — expected **0 errors**.
- [ ] T026 [P] Run `vendor/bin/phpunit tests/phpunit/MCPClients/` — expected **all green** (8 test classes: AbstractMCPClientTest + 7 concrete).
- [ ] T027 [P] Run `npm run validate-packages` — expected **pass** (Constitution §VI DoD gate; no new npm packages introduced by this phase).
- [ ] T028 Execute the full quickstart.md walk (§1–§7) end-to-end and confirm each step's expected output.
- [ ] T029 Mark spec.md §Success Criteria → Definition of Done Gates checkboxes complete; mark plan.md Status as "Ready for review" — Phase 4 ships.
- [ ] T030 Hand off to Phase 2 RT-3 amendment: the consumer (`Admin\Partials\ApplicationPasswords::render_for_server`) can now call `AbstractMCPClient::get_all_clients()` and iterate. Add a follow-up note in `data-model.md` (Phase 2's, not this phase's) pointing at this phase's `security-review-plan.md` SEC-INFO-002 for the consumer-escape grep gate.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies; can start immediately
- **Foundational (Phase 2)**: Depends on Setup; **T004 is a hard P0 gate** — no story may begin if it fails
- **US2 base (Phase 3)**: Depends on Foundational (T005, T006). Required by every concrete client.
- **US2 clients (Phase 4)**: Depends on Phase 3 (AbstractMCPClient must exist before subclasses can extend it). Once Phase 3 done, all 7 clients run in parallel.
- **US1 (Phase 5)**: Depends on Phase 4 complete (the smoke test instantiates ClaudeDesktopClient)
- **US3 (Phase 6)**: Depends on Phase 4 complete (test relies on the file-scan mechanism finding all 7 clients first)
- **US4 (Phase 7)**: Depends on Phase 4 complete (grep gates only meaningful after the 7 client files exist)
- **Polish (Phase 8)**: Depends on US1+US3+US4 all complete

### User Story Dependencies (intra-phase)

- **US2 base** (Phase 3): T008 → T009 (sequential — both edit same files but in different repos; T008 writes `AbstractMCPClient.php`, T009 writes `AbstractMCPClientTest.php`)
- **US2 clients** (Phase 4): T010 ⫽ T011 ⫽ T012 ⫽ T013 ⫽ T014 ⫽ T015 ⫽ T016 — 7 fully-parallel tasks, each touching its own 3-4 files
- **US1** (Phase 5): T017 → T018 (T017 updates a test that T018 indirectly relies on for the count assertion's correctness)
- **US3** (Phase 6): T019 standalone
- **US4** (Phase 7): T020 ⫽ T021 ⫽ T022 (all 3 parallel — different greps / different runs)
- **Polish** (Phase 8): T023 ⫽ T024 ⫽ T025 ⫽ T026 ⫽ T027 parallel; T028 → T029 → T030 sequential at the end

### Parallel Opportunities

- All Setup [P] tasks (T002, T003) run in parallel
- All Foundational [P] tasks (T006, T007) run in parallel after T005
- **Phase 4 is the biggest parallel win** — 7 concrete clients can be shipped by 7 different developers/agents simultaneously
- US4 grep gates (T020, T021, T022) all parallel
- Polish gates (T023–T027) all parallel; only T028–T030 sequential

---

## Parallel Example: Phase 4 kick-off

```bash
# Once T009 (Abstract + tests) is done, seven concrete-client tasks
# can start simultaneously — each touches a distinct file set:
Task: "Port ClaudeCodeClient.php + test + 2 fixtures"        # T010
Task: "Port ClaudeDesktopClient.php + test + 2 fixtures"     # T011
Task: "Port CodexClient.php + test + 2 fixtures"             # T012
Task: "Port CursorClient.php + test + 2 fixtures"            # T013
Task: "Port CustomClient.php + test + 2 fixtures"            # T014
Task: "Port GitHubCopilotClient.php + test + 2 fixtures"     # T015
Task: "Port VSCodeClient.php + test + 2 fixtures"            # T016
# All 7 touch separate files → no conflicts.
```

---

## Implementation Strategy

### MVP First (US2 base + US2 clients + US1)

1. Phase 1 Setup (T001–T003)
2. Phase 2 Foundational — **T004 is the hard P0 gate** (T005–T007)
3. Phase 3 — AbstractMCPClient + helpers + tests (T008–T009)
4. Phase 4 — 7 concrete clients in parallel (T010–T016)
5. Phase 5 — End-to-end smoke (T017–T018)
6. **STOP and VALIDATE**: Run the full quickstart walk. Already a
   shippable module; Phase 2 RT-3 amendment can consume
   `AbstractMCPClient::get_all_clients()`.

### Incremental Delivery

7. Phase 6 — US3 extensibility check (T019)
8. Phase 7 — US4 purity gates (T020–T022)
9. Phase 8 — Polish (T023–T030)

### Parallel Team Strategy

With seven developers/agents after T009 closes:
- **Dev A–G** each take one client task from T010–T016
- All seven converge on Phase 5 (T017–T018) once the last client lands
- One dev runs Polish in parallel as gates become unblocked

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks
- [Story] label maps task to user story for traceability
- Each user story is independently completable and testable
- Verify tests pass after implementation (this is a regression-style port, not strict TDD — Phase 4 tasks each ship implementation + test + fixtures together)
- Commit after each task or logical group of [P] tasks
- Stop at any **Checkpoint** to validate the story independently
- Avoid: cross-story file conflicts; touching files outside the story's scope; adding hooks/DB/HTTP/cookies to MCPClients/ (the FR-008 grep gate at T020 will catch it)
- **T004 P0 dependency**: if the PHPUnit harness doesn't yet exist, escalate to the Phase 2 RT-4 follow-up — do NOT set it up inside this phase
- **Consumer handoff (T030)**: after this phase ships, the Phase 2 amendment task list (RT-3) inherits SEC-INFO-002 from `security-review-plan.md` — the consumer MUST wrap snippet output with `esc_html()` + `wp_json_encode()` at the render boundary

---

## Task Count Summary

| Phase | Task IDs | Count |
|---|---|---|
| 1 — Setup | T001–T003 | 3 |
| 2 — Foundational | T004–T007 | 4 |
| 3 — US2 base (AbstractMCPClient) | T008–T009 | 2 |
| 4 — US2 7 concrete clients | T010–T016 | 7 |
| 5 — US1 end-to-end | T017–T018 | 2 |
| 6 — US3 extensibility | T019 | 1 |
| 7 — US4 purity | T020–T022 | 3 |
| 8 — Polish | T023–T030 | 8 |
| **Total** | | **30** |

Implementation tasks: 9 (T008, T010–T016, T017 partial).
Test tasks: 9 (T009 + 7×test-class-bundled-with-impl in T010–T016 + T017).
Gate / verification / doc tasks: 12.
