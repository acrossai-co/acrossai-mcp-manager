---
description: "Task list for F038 — User-Accessible MCP Servers Shortcode + Reusable Base Class"
---

# Tasks: User-Accessible MCP Servers Shortcode + Reusable Base Class

**Input**: Design documents from `specs/037-user-accessible-mcp-servers-shortcode/`
**Prerequisites**: `plan.md` (required), `spec.md` (required for user stories), `research.md`, `data-model.md`, `contracts/` (both files)

**Tests**: Test tasks are included — spec.md FR-026 through FR-028 explicitly require a new `user-servers` PHPUnit suite.

**Organization**: Tasks grouped by user story (US1 = P1, US2 = P1, US3 = P2) to enable independent verification and MVP delivery.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1 / US2 / US3)
- Include exact file paths in descriptions

## Path Conventions

WordPress plugin single-project layout at repo root: `includes/`, `public/`, `admin/`, `tests/`, `docs/`, `.github/`. Namespaces derived from directory path per constitution §Architecture & UI Standards.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Register the `user-servers` PHPUnit suite + CI step so tests can be written before the source classes.

- [x] T001 Verify prerequisites — run from repo root: `composer install && composer dump-autoload && grep -E "(user_has_server_access|get_all_registered_transports|is_enabled_for_server)" -rEn --include='*.php' includes/ public/ | wc -l` — expect ≥ 3 hits confirming F015 + F037 upstream contracts are present on `main` before F038 development begins
- [x] T002 Create the empty test directory `tests/phpunit/Public/Renderers/UserServers/` (mkdir -p) so subsequent T00X tasks can add test files without directory scaffolding overhead
- [x] T003 [P] Register the `user-servers` PHPUnit suite in `phpunit.xml.dist` — add `<testsuite name="user-servers"><directory>./tests/phpunit/Public/Renderers/UserServers/</directory></testsuite>` alongside existing `discovery` and `embeds` suites. Reference precedent: **F036** in the brief-numbering scheme = spec dir **`specs/035-connection-method-discovery-api/`** (the F037 brief established a one-off offset between `docs/planings-tasks/NNN-slug.md` numbering and `specs/NNN-slug/` numbering — F038 inherits it; brief number is always one ahead of spec-dir number).
- [x] T004 [P] Add matching CI step to `.github/workflows/phpunit.yml` — new job running `vendor/bin/phpunit --testsuite=user-servers` via `tests/bootstrap-wp.php`. Match the F036 `discovery` step's env matrix (PHP 8.1 + 8.2). Verify locally with `act -j phpunit` if available, otherwise validate YAML with `yamllint`

**Checkpoint**: Empty `user-servers` suite runs green (zero tests, exit 0). Ready for US1 test-and-implement.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL — Not applicable to F038**: Feature introduces zero new DB schema, zero REST endpoints, zero admin scaffolding, and zero shared utility classes. F015 + F035 + F037 provide every foundational primitive F038 consumes; those already shipped in 0.1.8. No foundational tasks required — proceed directly to Phase 3.

---

## Phase 3: User Story 1 - Logged-in user sees only the MCP servers they can reach (Priority: P1) 🎯 MVP

**Goal**: End-user visits any page containing `[acrossai_mcp_servers]`. If logged in, they see every MCP server the operator has granted them access to (F015 Access Control tab) whose F037 Embeds tab has the master toggle ON and at least one enabled connection method — with the enabled DTOs rendered per server. If logged out, or with no accessible servers, the correct fallback state renders (empty string / empty-state wrapper). Zero information leaks to the wrong user.

**Independent Test**: Set up two servers (one accessible + Embeds ON with one DTO; one inaccessible). Log in as admin — both scenarios visible correctly. Log in as subscriber — only the accessible one. Log out — nothing renders. See `quickstart.md` Tests 1–5 for the full end-to-end recipe.

### Implementation for US1

- [x] T005 [US1] Create the abstract base class file `public/Renderers/UserServers/AbstractUserServersRenderer.php` with the exact shape specified in `contracts/AbstractUserServersRenderer.contract.md` §Class shape — namespace `AcrossAI_MCP_Manager\Public\Renderers\UserServers`, `abstract class`, `@experimental May change without notice before 1.0.0` class docblock, `use` imports for `MCPServerQuery`, `AcrossAI_MCP_Access_Control`, `AbstractEmbedTransport`
- [x] T006 [US1] Implement `AbstractUserServersRenderer::get_accessible_servers( ?int $user_id = null ): array` following the 7-step algorithm in `contracts/AbstractUserServersRenderer.contract.md` §Algorithm — steps: user_id resolution + anonymous short-circuit → `MCPServerQuery::instance()->query([ 'is_enabled' => 1, 'number' => -1 ])` → transport enumeration cached locally → per-server F015 gate → per-transport per-DTO F037 gate → server-level empty-transports skip → `usort` by `strnatcasecmp` on `server_name` → fire `acrossai_mcp_user_accessible_servers` filter → return
- [x] T007 [US1] Copy the following disclosures from `contracts/AbstractUserServersRenderer.contract.md` verbatim into the `AbstractUserServersRenderer.php` docblocks (per SEC-T-001):
  - **SEC-001** — Caller-authority responsibility subsection → into the class-level `/**` docblock (above the class declaration).
  - **SEC-004** — Non-goals bullet ("filter is mutation seam, not gate-bypass; consumers appending entries MUST replay the gate cascade themselves") → into an inline docblock immediately above the `apply_filters( 'acrossai_mcp_user_accessible_servers', ... )` call inside `get_accessible_servers()`.

  Rationale: IDE tooltips (Intelephense, PHPStorm) show only the class docblock at consumption time. Companion-plugin authors reading `contracts/*.contract.md` outside the source tree is not enforced; in-source docblocks are.
- [x] T008 [US1] Create the concrete block file `public/Renderers/UserServers/UserServersBlock.php` per `contracts/UserServersBlock.contract.md` §Class shape — namespace as above, `final class extends AbstractUserServersRenderer`, private constructor, singleton `instance()` per A2, static `$_instance` + `$style_emitted` properties, `@experimental` class docblock. **Additionally per SEC-T-001**: copy the SEC-002 filter-boundary trust disclosure verbatim from `contracts/UserServersBlock.contract.md` §Filter contract → into the class-level `/**` docblock, AND copy the SEC-005 static-CSS-only invariant verbatim from §CSS scope → into an inline docblock immediately above the `INLINE_STYLE` constant declaration (task T012).
- [x] T009 [US1] Implement `UserServersBlock::register_shortcode()` — single-purpose method calling `add_shortcode( 'acrossai_mcp_servers', [ $this, 'render_shortcode' ] )`. Nothing else in this method
- [x] T010 [US1] Implement `UserServersBlock::render_shortcode( $atts_raw ): string` per `contracts/UserServersBlock.contract.md` §Algorithm — 7 steps: `shortcode_atts()` normalize with 3 defaults → anonymous short-circuit (`return ''`) → `$data = $this->get_accessible_servers()` + defensive `is_array` coercion → emit inline `<style>` once (private static flag) → build empty-state OR default HTML per §DOM shape → fire `acrossai_mcp_servers_shortcode_html` filter → return with `(string)` cast
- [x] T011 [US1] Implement the private static helper `UserServersBlock::icon_is_url( string $icon ): bool` per contract §Icon URL detection — returns true iff string starts with `http://` or `https://`. Use `strpos( … ) === 0` (PHP 7.4-compatible per AGENTS.md min PHP)
- [x] T012 [US1] Add the static CSS content constant `UserServersBlock::INLINE_STYLE` (or equivalent private constant) with the scoped rules from `contracts/UserServersBlock.contract.md` §CSS scope — every selector prefixed with `.acrossai-mcp-servers`, uses `currentColor` for borders, no theme opinions. Wrapped in `<style type="text/css">…</style>` at emit time
- [x] T013 [US1] Wire the shortcode registration in `includes/Main.php::define_public_hooks()` — insert `$user_servers_block = \AcrossAI_MCP_Manager\Public\Renderers\UserServers\UserServersBlock::instance(); $this->loader->add_action( 'init', $user_servers_block, 'register_shortcode' );` alongside the existing `$embed_renderer` block near line 758. Zero other edits to `Main.php`
- [x] T014 [P] [US1] Create `tests/phpunit/Public/Renderers/UserServers/AbstractUserServersRendererTest.php` covering the 11 test cases enumerated in `contracts/AbstractUserServersRenderer.contract.md` §Test contract — `test_anonymous_returns_empty`, `test_no_servers_returns_empty`, `test_master_toggle_off_drops_server`, `test_zero_dtos_drops_server`, `test_one_dto_enabled_includes_server`, `test_f015_deny_drops_server`, `test_f015_fail_open_when_package_absent`, `test_filter_round_trip`, `test_sort_by_server_name_case_insensitive`, `test_transport_priority_order_preserved`, `test_dto_with_missing_slug_dropped`. Use `#[DataProvider]` PHP attribute (NOT `@dataProvider` docblock — per B9)
- [x] T015 [P] [US1] Create `tests/phpunit/Public/Renderers/UserServers/UserServersBlockTest.php` covering the 12 test cases enumerated in `contracts/UserServersBlock.contract.md` §Test contract — `test_anonymous_returns_empty_string`, `test_empty_state_renders_wrapper_and_message`, `test_custom_empty_message_attribute`, `test_default_render_shape`, `test_style_emitted_exactly_once`, `test_icon_url_becomes_img`, `test_icon_non_url_becomes_text`, `test_show_description_false_omits_desc`, `test_heading_attribute_renders_h2`, `test_filter_round_trip_html`, `test_escape_at_boundary`, `test_singleton_private_ctor`
- [x] T016 [US1] Run `vendor/bin/phpunit --testsuite=user-servers` and verify all US1 tests green. Fix any assertion failures before proceeding

**Checkpoint**: The `[acrossai_mcp_servers]` shortcode ships end-to-end. quickstart.md Tests 1–5 pass manually against a local install. **This is the MVP** — every subsequent phase adds independence testing + hardening, not new user-visible surface.

---

## Phase 4: User Story 2 - Companion plugin reuses the enumeration primitive without touching HTML (Priority: P1)

**Goal**: A BuddyBoss / WooCommerce / WPUM / MemberPress add-on subclasses `AbstractUserServersRenderer` and calls `get_accessible_servers( $user_id )` to power its own custom-rendered profile widget. F038 does NOT need any code change to support this — the base class IS the extension surface. Also proves the composition is filter-driven end-to-end: a companion plugin registering a fourth transport via `acrossai_mcp_embed_transports` surfaces in F038's payload with zero F038 changes.

**Independent Test**: In an mu-plugin, subclass `AbstractUserServersRenderer` inline; call `get_accessible_servers()`; assert the return shape matches FR-013. Register a fake fourth transport via `acrossai_mcp_embed_transports`; assert its DTOs surface. See `quickstart.md` Test 9.

**Depends on**: US1 T005–T007 (the abstract base must exist).

### Implementation for US2

- [x] T017 [P] [US2] Create `tests/phpunit/Public/Renderers/UserServers/ThirdPartyExtensibilityTest.php` — Test 1: subclass `AbstractUserServersRenderer` inline (via anonymous class or nested test-fixture class), call `get_accessible_servers()`, assert the returned array shape matches FR-013 field-by-field (server_id, server_slug, server_name, description, transports[] each with key/label/priority/dtos[]). No shortcode involvement in this test
- [x] T018 [US2] Add Test 2 to the same file: register a **fake fourth transport class** via `acrossai_mcp_embed_transports` filter — subclass `AbstractEmbedTransport` with `get_transport_key() = 'test-fourth-transport'`, `get_checkbox_label() = 'Test Fourth'`, `get_priority() = 40`, `get_dtos() = [ [ 'slug' => 'test-dto', 'name' => 'Test DTO', 'icon' => '🧪', 'description' => '', 'meta' => [] ] ]`. Set the master toggle + register the DTO as enabled for a test server. Call `get_accessible_servers()`. Assert the returned payload includes a `transports[]` entry with `key = 'test-fourth-transport'` containing the `test-dto` — zero changes to F038 code required (proves SC-005)
- [x] T019 [US2] Run `vendor/bin/phpunit --testsuite=user-servers` and verify US2 tests green

**Checkpoint**: quickstart.md Test 9 passes. Companion-plugin extension pattern verified end-to-end.

---

## Phase 5: User Story 3 - Filter-based customization without subclassing (Priority: P2)

**Goal**: Site admins customize the shortcode's data payload or output HTML via WordPress filters, without writing a subclass. Proves both filters are wired correctly and reachable from mu-plugin scope.

**Independent Test**: Register `acrossai_mcp_user_accessible_servers` filter to remove entries; register `acrossai_mcp_servers_shortcode_html` filter to wrap output. Verify both apply. See `quickstart.md` Tests 5 and 6.

**Depends on**: US1 T005–T016 (both filters are exercised end-to-end).

### Implementation for US3

- [x] T020 [P] [US3] Extend `AbstractUserServersRendererTest.php` (created in T014) with a **payload-filter mutation** test — hook `acrossai_mcp_user_accessible_servers` to unset every entry whose `server_slug` starts with `'excluded-'`. Assert the returned array excludes those entries and preserves the rest. Also verify the filter fires **exactly once** per `get_accessible_servers()` call (use `did_action`-style counter). This test doubles as SEC-004 documentation: a comment in the test body should note the filter is a mutation seam, not a gate-bypass seam (link to contract §Non-goals)
- [x] T021 [P] [US3] Extend `UserServersBlockTest.php` (created in T015) with an **HTML-filter wrapping** test — hook `acrossai_mcp_servers_shortcode_html` to prepend `<div class="my-brand">` and append `</div>`. Assert the wrap appears in the returned string, verify the inner `acrossai-mcp-servers` div still renders, and verify the filter fires exactly once. Comment references SEC-002 (contract says un-sanitized return; test documents the trust boundary)
- [x] T022 [US3] Run `vendor/bin/phpunit --testsuite=user-servers` and verify US3 tests green

**Checkpoint**: quickstart.md Tests 5 and 6 pass. Filter contracts verified.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Enforce the plan-level grep-gates, quality gates, and memory/documentation hygiene before merge.

- [x] T023 [P] Run every grep-gate from `plan.md` §Constitution Check + `spec.md` §Definition of Done:
  ```bash
  grep -rn "apply_filters.*acrossai_mcp_embed_transports" public/Renderers/UserServers/ ; echo "$?"   # expect: nothing found, exit 1
  grep -rn "apply_filters.*acrossai_mcp_client_classes" public/Renderers/UserServers/ ; echo "$?"    # expect: nothing found, exit 1
  grep -rn "_embeds_enabled\|_embeds_clients" public/Renderers/UserServers/ ; echo "$?"             # expect: nothing found, exit 1
  grep -rn "UserServers" includes/ ; echo "$?"                                                       # expect: nothing found, exit 1
  grep -rn "add_shortcode" public/Renderers/UserServers/ | wc -l                                     # expect: exactly 1
  ```
  Any unexpected hit MUST be reviewed before merge. Zero hits + one hit are the pass conditions
- [x] T024 [P] Run `composer run phpcs` — expect zero errors and zero warnings on `public/Renderers/UserServers/` + `tests/phpunit/Public/Renderers/UserServers/` + touched lines in `includes/Main.php`. Fix any WPCS strict-profile findings before proceeding
- [x] T025 [P] Run `composer run phpstan` — expect zero errors at level 8 on the same paths. Common false-positives to expect: none — the code should type-check cleanly given `?int $user_id` + `array` return types + `use` imports
- [x] T026 [P] Run `npm run validate-packages` — expect green. F038 ships zero new npm deps so this is a regression check only
- [x] T027 Manually walk through `quickstart.md` Tests 1–11 against the local install (LocalWP `wordpress-7-0.local`). Screenshot the DOM for Tests 2, 4, 5, 8 (multi-server, empty message, double-render single `<style>`) for the PR description. Verify each test passes
- [x] T028 Update `README.txt` — under `= Unreleased =` section append the F038 changelog bullet verbatim from `docs/planings-tasks/038-user-accessible-mcp-servers-shortcode.md` §TASK-6 (Memory hygiene). Create the Unreleased section if absent
- [x] T029 Update `docs/memory/DECISIONS.md` + `docs/memory/INDEX.md` — append the next `D` slot entry (D40 if D39 is the current tail): title `User-scoped enumeration primitives compose existing gates — never re-implement them`, body per `docs/planings-tasks/038-user-accessible-mcp-servers-shortcode.md` §TASK-6. Companion `INDEX.md` row per FR-025 (Memory Hub) format
- [x] T030 Update `docs/memory/INDEX.md` "Security Reviews" table — append row for `docs/security-reviews/2026-07-29-037-user-accessible-mcp-servers-shortcode-plan.md` (phase=plan, risk=LOW, findings C:0 H:0 M:0 L:1 I:4, constraints A01,A03). Row format matches existing entries at line 195–196 of INDEX.md
- [x] T031 Update `docs/memory/WORKLOG.md` — append a 2026-07-29 F038 entry summarizing: composition-only feature on F015 + F037; shipped `AbstractUserServersRenderer` (extension surface) + `UserServersBlock` (final singleton shortcode); D36 precedent-based deviation documented + logged in plan.md §Complexity Tracking; zero DB / REST / admin changes; two new filters + one new shortcode; SEC-001..SEC-005 all documentation-only

**Checkpoint**: All grep-gates pass, all quality gates green, changelog + memory index + worklog updated. PR ready.

---

## Dependencies

Story completion order (constrained by shared code, not shared data):

```text
Phase 1 Setup ─┐
               ├── Phase 3 US1 (MVP) ─┬── Phase 4 US2 ──┐
Phase 2 SKIP ──┘                      │                 ├── Phase 6 Polish
                                      └── Phase 5 US3 ──┘
```

- **US2** depends on US1's Abstract base (T005–T007) but NOT on US1's Concrete block. In practice both land in the same PR — no parallel-branch benefit.
- **US3** depends on US1's Concrete block (T008–T013) — the HTML filter fires inside `render_shortcode()`. Also lands in the same PR.
- **Phase 6** runs after every story completes.

## Parallel Execution Examples

Within US1, safe parallel groups (different files, no data dependencies):

- **After T007**: T008 (create UserServersBlock.php) and T014 (write AbstractUserServersRendererTest.php) can run in parallel — different files, and the test file can compile against the abstract base's public shape even before UserServersBlock exists (test writes mock subclass).
- **After T013 (wiring)**: T014 + T015 unit tests run against real code; either can run first.

Within Phase 6, all quality-gate tasks (T023, T024, T025, T026) are independent and can run concurrently — different tools, no shared state.

## Implementation Strategy — MVP first

**MVP scope = Phase 1 (Setup) + Phase 3 (US1)**. Ships the shortcode end-to-end. Users see the correct servers.

**Ship US2 + US3 in the same PR** — both are strictly test-file additions (no new production code). Skipping them delays the durability contract for companion plugins without any user-visible cost, so the marginal benefit of splitting the PR is low.

**Phase 6 gates the PR** — grep + PHPCS + PHPStan + quickstart before merge. No shortcuts.

## Task count summary

- Setup: 4 (T001–T004)
- Foundational: 0 (skipped)
- US1 (P1, MVP): 12 (T005–T016)
- US2 (P1): 3 (T017–T019)
- US3 (P2): 3 (T020–T022)
- Polish: 9 (T023–T031)
- **Total: 31 tasks**

Independent test criteria are captured per story in the Independent Test paragraph above each story's task block, and enumerated end-to-end in `quickstart.md` (11 tests).
