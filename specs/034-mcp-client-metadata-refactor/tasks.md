---

description: "Task list for F034 — MCP Client Metadata + Filter-Aware Enumeration Refactor"
---

# Tasks: F034 MCP Client Metadata + Filter-Aware Enumeration Refactor

**Input**: Design documents from `specs/034-mcp-client-metadata-refactor/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/AbstractMCPClient.contract.md](./contracts/AbstractMCPClient.contract.md), [security-constraints.md](./security-constraints.md), [memory-synthesis.md](./memory-synthesis.md)

**Tests**: REQUIRED per AGENTS.md ("Feature is NOT complete without: PHPCS validation, Security review, Unit tests") + Constitution §VII Definition of Done. All test tasks below are mandatory, not optional.

**Organization**: Tasks are grouped by user story from `spec.md` (US1 P1, US2 P1, US3 P2). Each phase is an independently testable increment. Security review's TASK-SEC-034-001 folded in as T018 under US2 (the phase that owns the Renderer rewire that the invariant protects).

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: `[US1]` / `[US2]` / `[US3]` — required for user-story phase tasks; setup/foundational/polish get no label

## Path Conventions

- Source: `includes/MCPClients/` (abstract + 8 concrete client classes), `public/Renderers/MCPClientsBlock.php`
- Tests: `tests/phpunit/MCPClients/`, `tests/phpunit/Public/Renderers/`
- Memory: `docs/memory/{DECISIONS.md, INDEX.md}`, `README.txt`

---

## Phase 1: Setup (Pre-Flight Baseline)

**Purpose**: Capture the pre-refactor state so post-refactor grep gates have a comparison baseline.

- [x] T001 Capture pre-flight grep baseline into a session note. Run `grep -rEn 'CLIENT_META|get_all_clients\(\)|acrossai_mcp_client_classes' --include='*.php' includes/ admin/ public/ tests/` from the plugin root; save the output to a local reference (e.g. `/tmp/f034-preflight.txt`) so post-refactor greps (T020, SC-002..004) can be verified against the baseline. No file changes.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: This is a refactor of an existing subsystem — no new shared infrastructure to build. Phase 2 is intentionally empty; user-story phases can begin immediately after T001.

**Checkpoint**: Foundational phase completes trivially. Proceed to Phase 3.

---

## Phase 3: User Story 1 — Third-party developer adds self-describing MCP client (Priority: P1) 🎯 MVP

**Goal**: A companion-plugin developer can add a new `AbstractMCPClient` subclass via the existing `acrossai_mcp_client_classes` filter and declare all display metadata (icon, description, config file, top-level key, instructions, priority) via method overrides on the abstract base class — same shape as `AbstractConnectorProfile`.

**Independent Test**: With ONLY Phase 3 tasks complete (Phase 4/5 not yet started), a companion plugin registering a valid `AbstractMCPClient` subclass via the filter can call `AbstractMCPClient::get_all_registered_clients()` and see its instance in the returned array with metadata read back from its own overrides. `MCPClientsBlock` render is NOT yet rewired at this checkpoint — this US1 slice is verifiable via unit test only, without visiting the admin UI.

### Tests for User Story 1 (write FIRST, ensure they FAIL against pre-refactor code)

- [x] T002 [P] [US1] Add default-return assertions in `tests/phpunit/MCPClients/AbstractMCPClientTest.php` — verify a bare test-only subclass implementing only the three original abstract methods returns `''` from `get_icon`, `get_description`, `get_config_file`, `get_top_level_key`, `get_instructions` and `100` from `get_priority()`. Assert method signatures via reflection.
- [x] T003 [P] [US1] Write `tests/phpunit/MCPClients/GetAllRegisteredClientsTest.php` — cover: (a) default state returns 8 built-in slugs in priority order (`claude-desktop, claude-code, vscode, github-copilot, codex, cursor, gemini, custom`); (b) `acrossai_mcp_client_classes` filter can append a valid subclass FQN and it appears in the returned list; (c) invalid contributions silently skipped (non-string, missing class, wrong parent); (d) bad slug (empty, uppercase, underscore, >64 chars) triggers `_doing_it_wrong` under `WP_DEBUG` and is skipped; (e) duplicate slug triggers `_doing_it_wrong` under `WP_DEBUG` and later-wins; (f) explicit `get_priority()` override moves a third-party contribution to the declared slot with slug ascending as tiebreaker.

### Implementation for User Story 1

- [x] T004 [US1] In `includes/MCPClients/AbstractMCPClient.php` add six new concrete public methods after the existing `get_config_snippet()` abstract declaration: `get_icon(): string` (default `''`), `get_description(): string` (default `''`), `get_config_file(): string` (default `''`), `get_top_level_key(): string` (default `''`), `get_instructions(): string` (default `''`), `get_priority(): int` (default `100`). Match docblock style of existing methods. Do NOT touch existing abstract methods, protected helpers, or constants.
- [x] T005 [US1] In `includes/MCPClients/AbstractMCPClient.php` add `public const DEFAULT_CLIENT_CLASSES = array( ClaudeDesktopClient::class, ClaudeCodeClient::class, VSCodeClient::class, GitHubCopilotClient::class, CodexClient::class, CursorClient::class, GeminiClient::class, CustomClient::class );` and add `public static function get_all_registered_clients(): array` implementing the procedure in `data-model.md` §"Canonical enumeration method": fire `acrossai_mcp_client_classes` filter → validate FQN (`is_string` + `class_exists` + `is_subclass_of` → silent skip) → instantiate → validate slug (regex `/\A[a-z0-9-]{1,64}\z/` → `_doing_it_wrong( 'AcrossAI_MCP_Manager\\Includes\\MCPClients\\AbstractMCPClient::get_all_registered_clients', __( '...', 'acrossai-mcp-manager' ), '0.1.7' )` under `WP_DEBUG` and skip) → dedup by slug with `_doing_it_wrong` on duplicates → `usort` by `(priority ASC, slug ASC)` → `array_values`. Requires imports for the 8 concrete client class FQNs. Do NOT delete `get_all_clients()` yet (Phase 5, T019).

**Checkpoint**: US1 tests (T002, T003) go from RED → GREEN. `get_all_registered_clients()` is now callable and returns the 8 built-in slugs in priority order. Third-party subclasses can register via filter and appear in the returned array. `MCPClientsBlock` still uses its old enumeration path (unchanged). Byte-identical render preserved trivially since Renderer isn't touched yet.

---

## Phase 4: User Story 2 — Site administrator sees byte-identical rendering (Priority: P1)

**Goal**: All eight built-in clients render byte-identical HTML on the server-edit → Clients tab before and after the Renderer rewires to consume the canonical enumeration + metadata from client instances. `CLIENT_META` const is deleted; no metadata lookups remain in the Renderer.

**Independent Test**: Load the server-edit → Clients tab manually pre- and post-refactor; DOM output for each of the 8 built-in clients is identical. Automated regression: `MCPClientsBlockRenderTest` asserts key DOM markers for the `claude-desktop` client (representative).

### Tests for User Story 2

- [x] T006 [P] [US2] Write `tests/phpunit/MCPClients/ConcreteClientMetadataTest.php` — data-provider parameterized over all 8 built-in client classes. For each, assert `get_icon()`, `get_description()`, `get_config_file()`, `get_top_level_key()`, `get_instructions()`, and `get_priority()` return the migrated values from the current `MCPClientsBlock::CLIENT_META[$slug]` (+ the priority table in `data-model.md` §"8 Concrete Client Classes"). Priority values: `claude-desktop=10, claude-code=20, vscode=30, github-copilot=40, codex=50, cursor=60, gemini=70, custom=80`.
- [x] T007 [P] [US2] Write `tests/phpunit/Public/Renderers/MCPClientsBlockRenderTest.php` — render `MCPClientsBlock` for a seeded test MCP server row with `sub_client = 'claude-desktop'` in context. Assert the rendered DOM contains: the `🍰` emoji character in the sub-nav, the exact string `~/Library/Application Support/Claude/claude_desktop_config.json`, the string `mcpServers`, and the first phrase of the Claude Desktop instructions (`'Generate a password'`). Also (per SEC-034-001 hardening suggestion) register a fake `AbstractMCPClient` subclass returning `'<script>alert(1)</script>'` from `get_description()` and assert the rendered DOM contains `&lt;script&gt;alert(1)&lt;/script&gt;` (escaped), NOT the raw tag.

### Implementation for User Story 2

- [x] T00U- [ ] T008 [P] [US2]E In `includes/MCPClients/ClaudeDesktopClient.php` add 6 method overrides: `get_icon(): string { return '🍰'; }`, `get_description(): string { return __( 'Anthropic Claude Desktop App', 'acrossai-mcp-manager' ); }`, `get_config_file(): string { return '~/Library/Application Support/Claude/claude_desktop_config.json'; }`, `get_top_level_key(): string { return 'mcpServers'; }`, `get_instructions(): string { return __( 'Generate a password → copy the JSON → open the config file path above → paste under the top-level key → restart Claude Desktop.', 'acrossai-mcp-manager' ); }`, `get_priority(): int { return 10; }`. Do NOT touch `get_client_slug`, `get_client_name`, or `get_config_snippet`.
- [x] T00U- [ ] T009 [P] [US2]E In `includes/MCPClients/ClaudeCodeClient.php` add 6 method overrides — icon `'📄'`, description (translated) `'Anthropic Claude Code CLI'`, config_file `'~/.claude.json'`, top_level_key `'mcpServers'`, instructions (translated) migrated verbatim from `CLIENT_META['claude-code']['instructions']`, priority `20`.
- [x] T010 [P] [US2] In `includes/MCPClients/VSCodeClient.php` add 6 method overrides — icon `'▤'`, description (translated) `'Visual Studio Code'`, config_file `'~/.vscode/mcp.json'`, top_level_key `'servers'`, instructions (translated) migrated verbatim from `CLIENT_META['vscode']['instructions']`, priority `30`.
- [x] T011 [P] [US2] In `includes/MCPClients/GitHubCopilotClient.php` add 6 method overrides — icon `'🐱'`, description (translated) `'GitHub Copilot in VS Code (user-level MCP config)'`, config_file `'~/.vscode/mcp.json'`, top_level_key `'servers'`, instructions (translated) migrated verbatim from `CLIENT_META['github-copilot']['instructions']`, priority `40`.
- [x] T012 [P] [US2] In `includes/MCPClients/CodexClient.php` add 6 method overrides — icon `'🐙'`, description (translated) `'OpenAI Codex CLI'`, config_file `'~/.codex/config.toml'`, top_level_key `'mcp_servers'`, instructions (translated) migrated verbatim from `CLIENT_META['codex']['instructions']`, priority `50`.
- [x] T013 [P] [US2] In `includes/MCPClients/CursorClient.php` add 6 method overrides — icon `'⚡'`, description (translated) `'Cursor AI Code Editor'`, config_file `'~/.cursor/mcp.json'`, top_level_key `'mcpServers'`, instructions (translated) migrated verbatim from `CLIENT_META['cursor']['instructions']`, priority `60`.
- [x] T014 [P] [US2] In `includes/MCPClients/GeminiClient.php` add 6 method overrides — icon `'💎'`, description (translated) `'Google Gemini CLI'`, config_file `'~/.gemini/settings.json'`, top_level_key `'mcpServers'`, instructions (translated) migrated verbatim from `CLIENT_META['gemini']['instructions']`, priority `70`.
- [x] T015 [P] [US2] In `includes/MCPClients/CustomClient.php` add 6 method overrides — icon `'⚙'`, description (translated) `'Custom MCP Client Implementation'`, config_file `'depends on your client'`, top_level_key `'depends on your client'`, instructions (translated) migrated verbatim from `CLIENT_META['custom']['instructions']`, priority `80`.
- [x] T016 [US2] In `public/Renderers/MCPClientsBlock.php` rewrite `render_body()`: replace the inline default-classes array + filter loop (current lines ~167-198) with a single `$clients = AbstractMCPClient::get_all_registered_clients();` call. Add `use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;` to the top-of-file imports if not present; remove the eight per-client `use` statements that are no longer needed once the inline default array is gone. Preserve the empty-client-list guard, the `sub_client_slug` selection logic, and every render helper below `render_body()` verbatim. Depends on T004, T005, T008–T015 all completing.
- [x] T017 [US2] In `public/Renderers/MCPClientsBlock.php` throughout every render helper called from `render_body()`, replace each `self::CLIENT_META[$slug]['<key>']` lookup with the corresponding client-instance method call: `['emoji']` → `$client->get_icon()`, `['description']` → `$client->get_description()`, `['config_file']` → `$client->get_config_file()`, `['top_level_key']` → `$client->get_top_level_key()`, `['instructions']` → `$client->get_instructions()`. After every lookup is migrated, DELETE the entire `CLIENT_META` const declaration (current lines ~55-112). PHPStan will fail if any lookup was missed. Depends on T016.
- [x] T018 [US2] **[SEC-034-001 preservation invariant]** Verify no `esc_*` call in `public/Renderers/MCPClientsBlock.php` render helpers was removed or relaxed by T016/T017. Run: `git diff main -- public/Renderers/MCPClientsBlock.php | grep -E '^-\s*esc_(html|attr|url|js|textarea|kses)'`. MUST return zero lines. If any escape call was removed, restore it before proceeding — this is the security-review preservation invariant per SEC-034-001. Depends on T016, T017.

**Checkpoint**: US2 tests (T006, T007) go from RED → GREEN. Rendered DOM for all 8 built-in clients is byte-identical to pre-refactor state (verified by T007 + manual DOM-diff in Phase 6). `MCPClientsBlock::CLIENT_META` is deleted from the source tree.

---

## Phase 5: User Story 3 — Maintainer reads one enumeration path, not three (Priority: P2)

**Goal**: Delete the pre-refactor `AbstractMCPClient::get_all_clients()` glob-based enumeration and verify via grep audit that no stale references to the deleted symbols remain anywhere in the codebase. The client subsystem now has ONE canonical enumeration path (`get_all_registered_clients()`).

**Independent Test**: Grep gates from `spec.md` SC-002, SC-003, SC-004 all pass.

### Implementation for User Story 3

- [x] T019 [US3] In `includes/MCPClients/AbstractMCPClient.php` DELETE the entire `get_all_clients(): array` static method (current lines ~105-128) plus its docblock. Depends on Phase 3 + Phase 4 completing (no callers of `get_all_clients()` may remain).
- [x] T020 [US3] Run the grep audit from `spec.md` §Success Criteria:
  - `grep -rEn 'CLIENT_META' --include='*.php' includes/ admin/ public/ tests/` MUST return 0 hits outside test fixtures. (SC-002)
  - `grep -rEn '\bget_all_clients\(\)' --include='*.php' includes/ admin/ public/ tests/` MUST return 0 hits. (SC-003)
  - `grep -rEn 'acrossai_mcp_client_classes' --include='*.php' includes/ admin/ public/` returns hits ONLY inside `AbstractMCPClient::get_all_registered_clients()`. Test fixtures + docs may also cite it. (SC-004)
  - `grep -rEn 'get_all_registered_clients\(\)' --include='*.php' includes/ public/` returns at least one hit inside `MCPClientsBlock::render_body()` and one inside `AbstractMCPClient` itself.

**Checkpoint**: All three grep-gate SCs pass. Codebase has one canonical enumeration entry point.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Memory hygiene, changelog, quality gates, and manual verification.

- [x] T021 [P] Memory hygiene: (a) Append a new decision entry to `docs/memory/DECISIONS.md` — next `D` slot (per INDEX.md tail count; today likely `D35`) with title `Self-contained subsystem contract — abstract base owns metadata + enumeration; Renderers are consumers only`, body per `plan.md` §"Memory hygiene" wording. Wrap under a `### YYYY-MM-DD - D35 — Self-contained subsystem contract` heading. (b) Add a companion row in `docs/memory/INDEX.md` under `## Active Decisions` table. (c) Search for any existing entries mentioning `CLIENT_META` or `AbstractMCPClient::get_all_clients` — if found, mark Superseded (Feature 034) per PATTERN-MEMORY-SUPERSESSION-VS-ANNOTATION, keeping the original body intact.
- [x] T022 [P] Changelog: In `README.txt` under `== Changelog ==` add a new `= Unreleased =` section (create if missing) with the F034 bullet: `* **Feature 034 — MCP client subsystem refactor: metadata methods + canonical filter-aware enumeration.** Each concrete MCP client class (ClaudeDesktopClient, ClaudeCodeClient, VSCodeClient, GitHubCopilotClient, CodexClient, CursorClient, GeminiClient, CustomClient) now declares its own display metadata via six new methods on AbstractMCPClient (get_icon, get_description, get_config_file, get_top_level_key, get_instructions, get_priority) — replacing the private CLIENT_META const in MCPClientsBlock. Enumeration collapses to a single canonical entry point AbstractMCPClient::get_all_registered_clients() that fires the existing acrossai_mcp_client_classes filter, validates FQNs + slugs (regex [a-z0-9-]{1,64}), dedups + sorts by (priority ASC, slug ASC) — mirroring ConnectorProfileRegistry::get_profiles(). The glob-based AbstractMCPClient::get_all_clients() is removed. Third-party client subclasses contributed via the filter now have a symmetric way to declare their own icon / description / config-file / top-level-key / instructions / sub-nav slot instead of being stranded in the Renderer's private const. Rendered output for the eight built-in clients on the server-edit → Clients tab is byte-identical. No breaking changes for existing third-party subclasses — the six new methods default to empty strings (or 100 for priority).`
- [x] T023 [P] Run `vendor/bin/phpcs includes/MCPClients/ public/Renderers/MCPClientsBlock.php tests/phpunit/MCPClients/ tests/phpunit/Public/Renderers/` — MUST report zero errors and zero warnings.
- [x] T024 [P] Run `vendor/bin/phpstan analyse includes/MCPClients/ public/Renderers/MCPClientsBlock.php --memory-limit=4G --no-progress` — MUST report zero errors at level 8.
- [x] T025 Run `vendor/bin/phpunit --testsuite=mcpclients` AND `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite=renderers` — both suites MUST be green. Also verify no regression in `--testsuite=abilities` and other pre-existing suites. Depends on T023, T024.
- [x] T026 Manual verification checklist from `spec.md` §"Render-parity check": (a) Load a local install's server-edit → Clients tab pre-refactor (save DOM snapshot for each of Claude Desktop, Codex, Custom Client — the three snippet-shape codepaths). (b) Load post-refactor; DOM-diff each panel; rendered output MUST be identical. (c) Register an mu-plugin adding a fake `AbstractMCPClient` subclass via `acrossai_mcp_client_classes` with distinct metadata; verify it appears in the sub-nav with its declared icon + description. (d) Remove mu-plugin; verify fake client disappears with no residual state. Depends on T025.

**Checkpoint**: All spec Success Criteria met. Ready for `/speckit-git-commit` + PR.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: No dependencies — T001 first.
- **Foundational (Phase 2)**: Empty; auto-satisfied.
- **US1 (Phase 3)**: Depends on Phase 1. All Phase 3 tasks (T002–T005) can run after T001.
- **US2 (Phase 4)**: Depends on Phase 3 completion (T016 needs T004+T005; T008-T015 don't strictly depend on Phase 3, but per tests-first convention should sequence after tests). US2 tests (T006, T007) can run in parallel with Phase 3 tasks.
- **US3 (Phase 5)**: Depends on Phase 3 + Phase 4 completing (must have all callers of `get_all_clients()` migrated before deletion).
- **Polish (Phase 6)**: Depends on Phase 5 completing.

### User story dependencies

- **US1 (P1)**: Delivers the third-party developer contract. Independent MVP slice — can be demoed by exercising `get_all_registered_clients()` via a unit test / mu-plugin without visiting the admin UI.
- **US2 (P1)**: Delivers byte-identical render. Depends on US1's new abstract methods existing. Must sequence AFTER US1.
- **US3 (P2)**: Delivers the "one canonical path" cleanup. Depends on US1 + US2 completing.

### Within-story ordering

- Tests written FIRST, ensure FAIL against pre-refactor code (per Constitution §II and AGENTS.md "Tests Rules").
- Abstract-class additions (T004, T005) before concrete-client migrations (T008-T015).
- Concrete-client migrations (T008-T015) before Renderer rewire (T016, T017).
- Renderer rewire (T016, T017) before escape-preservation gate (T018).
- Renderer rewire complete before glob-method deletion (T019).

### Parallel opportunities

- **T002 [P] + T003 [P]**: Different test files; run in parallel.
- **T006 [P] + T007 [P]**: Different test files; run in parallel.
- **T008-T015 (eight [P] tasks)**: All eight concrete client migrations touch DIFFERENT files with NO cross-dependencies. All eight can run in parallel by different developers or a single developer moving through them.
- **T021 [P] + T022 [P] + T023 [P] + T024 [P]**: Memory hygiene, changelog, PHPCS, PHPStan — all different files or different quality gates. Run in parallel.
- **T005 depends on T004**: Both touch `AbstractMCPClient.php`; sequential.
- **T017 depends on T016**: Both touch `MCPClientsBlock.php`; sequential.

---

## Parallel Example: User Story 2 concrete client migrations

```bash
# T008-T015 all touch different files; safe to fan out.
# In a single-developer flow: just do them one after another (fast — verbatim string moves).
# In a multi-developer flow: assign one task per developer.

Task: T008 [US2] Migrate CLIENT_META into ClaudeDesktopClient.php
Task: T009 [US2] Migrate CLIENT_META into ClaudeCodeClient.php
Task: T010 [US2] Migrate CLIENT_META into VSCodeClient.php
Task: T011 [US2] Migrate CLIENT_META into GitHubCopilotClient.php
Task: T012 [US2] Migrate CLIENT_META into CodexClient.php
Task: T013 [US2] Migrate CLIENT_META into CursorClient.php
Task: T014 [US2] Migrate CLIENT_META into GeminiClient.php
Task: T015 [US2] Migrate CLIENT_META into CustomClient.php
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete Phase 1: T001 pre-flight grep baseline.
2. Complete Phase 3 (US1): T002-T005.
3. **STOP and VALIDATE**: `vendor/bin/phpunit --testsuite=mcpclients` green. Register a test mu-plugin adding a fake subclass; verify it appears in `AbstractMCPClient::get_all_registered_clients()` output.
4. This is a shippable MVP if the Renderer rewire is deferred — third-party subclasses become self-describing at the API level, and the Renderer still uses the pre-refactor path (byte-identical rendering, both paths coexist temporarily). NOT recommended as a shipped state (creates two enumeration paths again — the thing F034 exists to eliminate) but a valid intermediate for review.

### Recommended: Ship all three stories together

Because US2 and US3 delete the OLD path that US1 replaces, shipping only US1 would leave the codebase in a "two paths exist" state — the exact drift condition F034 fixes. Recommend: complete Phase 3 + Phase 4 + Phase 5 + Phase 6 as one PR.

### Parallel team strategy

With multiple developers post-Phase-3:
- Developer A: T006 + T007 + T008-T015 (tests + concrete migrations)
- Developer B: (waits for A to finish T008-T015 before starting T016) + T016 + T017 + T018
- Developer C: T021 + T022 (memory + changelog) in parallel with A and B

---

## Notes

- [P] tasks = different files, no dependencies. Safe to fan out.
- [US1] / [US2] / [US3] label maps task to spec.md user story for traceability.
- Every task cites an exact file path. No vague tasks.
- Tests written BEFORE implementation — new tests MUST fail against pre-refactor code and pass after their corresponding implementation task completes.
- Commit after each task or per-phase logical group.
- T018 (SEC-034-001) is a security-review preservation invariant — DO NOT skip; per DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX pattern.
- Feature-number discrepancy noted at the top of `spec.md`: engineering brief filename says `035-...md`, spec dir + branch use `034-...`. No functional impact; resolve at merge if desired.
