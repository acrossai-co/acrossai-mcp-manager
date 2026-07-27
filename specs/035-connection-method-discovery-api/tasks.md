---
description: "Task list for F035 — Public Connection-Method Discovery API"
---

# Tasks: F035 Public Connection-Method Discovery API

**Input**: Design documents from `specs/035-connection-method-discovery-api/`
**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/ConnectionMethodRegistry.contract.md](./contracts/ConnectionMethodRegistry.contract.md), [security-constraints.md](./security-constraints.md), [memory-synthesis.md](./memory-synthesis.md)

**Tests**: REQUIRED per AGENTS.md ("Feature is NOT complete without: PHPCS validation, Security review, Unit tests") + Constitution §VII Definition of Done. All test tasks below are mandatory, not optional.

**Organization**: Tasks are grouped by user story from `spec.md` (US1 P1 unified enumeration, US2 P2 NPM extensibility, US3 P3 cross-category curation). Each phase is an independently testable increment. Security-review findings from 2026-07-26 plan-review folded in as `[SEC-035-XXX]`-tagged tasks under the phase that owns the surface they protect (SEC-035-001 → US2; SEC-035-002/003/004 → Phase 6 Polish, documentation only).

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: `[US1]` / `[US2]` / `[US3]` — required for user-story phase tasks; setup/foundational/polish get no label

## Path Conventions

- Source: `public/Discovery/ConnectionMethodRegistry.php` (new), `public/Renderers/NpmClientBlock.php` (light touch)
- Tests: `tests/phpunit/Public/Discovery/` (new directory, new PHPUnit suite `discovery`)
- CI: `phpunit.xml.dist`, `.github/workflows/phpunit.yml`
- Memory: `docs/memory/INDEX.md` (Security Reviews row already added), `README.txt`, `CLAUDE.md`

---

## Phase 1: Setup (Pre-Flight Baseline)

**Purpose**: Capture pre-refactor state so post-refactor grep gates + byte-identical render assertion have a comparison baseline.

- [x] T001 Capture pre-flight baseline into a session note. Run and save to `/tmp/f035-preflight.txt`:
  - `grep -rEn 'acrossai_mcp_npm_login_enabled|command_template' --include='*.php' public/Renderers/NpmClientBlock.php` — current NPM template + option-name usage inside the Renderer.
  - Visit `?page=acrossai_mcp_manager&action=edit&server=<id>&tab=npm` on a local install; capture rendered HTML DOM (view-source + save) as `/tmp/f035-npm-preflight.html`. Used by T028 for byte-identity manual verification.
  - `grep -rEn '\bConnectionMethodRegistry\b|public\\Discovery\\' --include='*.php' .` — MUST return zero hits (the class does not yet exist). Baseline for SC-006 grep gate.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: PHPUnit suite registration + NpmClientBlock helper extraction. Blocks US1–US3 tests from running in isolation.

- [x] T002 In `public/Renderers/NpmClientBlock.php` add a new `public static function get_default_npm_method(): array` returning the built-in NPM DTO exactly per `data-model.md` §Category `npm` "Built-in seed":
  ```php
  array(
      'category'    => 'npm',
      'slug'        => 'npx-acrossai-mcp-manager',
      'name'        => __( 'NPX', 'acrossai-mcp-manager' ),
      'description' => __( 'Run the AcrossAI MCP bridge as a local process via npx.', 'acrossai-mcp-manager' ),
      'icon'        => '',
      'meta'        => array(
          'command_template' => 'npx -y @acrossai/mcp-manager --siteurl=%s --server=%s',
          'enabled_option'   => 'acrossai_mcp_npm_login_enabled',
      ),
  );
  ```
  Do NOT modify `render_body()` yet — that's T003. Do NOT touch any other method, constant, or import in this file.
- [x] T003 [SC-007] In `public/Renderers/NpmClientBlock.php` refactor `render_body()` to source the NPM template + option-name from `self::get_default_npm_method()` (single source of truth per FR-015). Rendered NPM tab output MUST remain byte-identical to pre-refactor state (verify against `/tmp/f035-npm-preflight.html` from T001). Depends on T002.
- [x] T004 Register the new `discovery` PHPUnit suite. In `phpunit.xml.dist` add:
  ```xml
  <testsuite name="discovery">
    <directory>tests/phpunit/Public/Discovery/</directory>
  </testsuite>
  ```
  Insert alongside the existing `renderers` and `mcpclients` suite blocks. Suite uses `tests/bootstrap-wp.php` (WP bootstrap) per spec §Assumptions.
- [x] T005 In `.github/workflows/phpunit.yml` add one matrix step invoking `--testsuite=discovery` with `tests/bootstrap-wp.php`. Mirror the shape of the F030 `abilities` / `database` / `mcp` suite steps (WP-bootstrap style). Verify the new step's name follows the "PHPUnit (integration — discovery) — PHP 8.4 / WP latest" convention so branch-protection contexts remain traceable per B27.
- [x] T006 Create the test directory: `tests/phpunit/Public/Discovery/` (empty for now; test files land in Phase 3–5).

**Checkpoint**: NPM tab render byte-identical (`/tmp/f035-npm-preflight.html` diff clean). New PHPUnit suite `discovery` registered in `phpunit.xml.dist` + CI job. Foundation ready — US1 tests can be authored + run against `tests/phpunit/Public/Discovery/`.

---

## Phase 3: User Story 1 — Third-party plugin enumerates all connection methods in one call (Priority: P1) 🎯 MVP

**Goal**: A companion-plugin developer can call `ConnectionMethodRegistry::instance()->get_all()` and receive a JSON-serializable three-category array of DTOs — one canonical entry point replacing three separate registries (NPM manual, MCP clients via MCPClientsBlock, AI connectors via ConnectorProfileRegistry). US1 covers the shape guarantee (six required top-level keys per DTO) + per-category getters + `find()` lookup.

**Independent Test**: With ONLY Phase 3 tasks complete (Phase 4/5 not yet started — the two new filters not yet firing), calling `ConnectionMethodRegistry::instance()->get_all()` returns `array( 'npm' => array( 1 built-in ), 'clients' => array( 8 F034 built-ins ), 'ai_connectors' => array() )` on a fresh install. Every DTO round-trips through `wp_json_encode()` losslessly (SC-001).

### Tests for User Story 1 (write FIRST, ensure they FAIL against pre-implementation)

- [x] T007 [P] [US1] Write `tests/phpunit/Public/Discovery/ConnectionMethodRegistryTest.php` skeleton with `WP_UnitTestCase`. In `setUp()` call `ConnectionMethodRegistry::instance()->flush_cache()` for test isolation (see R2). Cover in this file:
  - `test_singleton_returns_same_instance()` — `A::instance() === A::instance()` (FR-002).
  - `test_get_all_returns_three_keyed_array()` — return has exactly `'npm'`, `'clients'`, `'ai_connectors'` keys, no more, no less (FR-004).
  - `test_get_all_default_client_count()` — `count( $all['clients'] )` equals 8 (F034 built-in count), `count( $all['ai_connectors'] )` equals 0, `count( $all['npm'] )` equals 1.
  - `test_every_dto_has_six_top_level_keys()` — data-provider parameterized over `get_all()` flattened output; each DTO has `category`, `slug`, `name`, `description`, `icon`, `meta` (SC-002).
  - `test_dto_is_json_round_trip_safe()` — `wp_json_encode()` + `json_decode(..., true)` round trip preserves structure (SC-001).
  - `test_find_returns_dto_on_match()` — `find( 'client', 'claude-desktop' )` returns the expected DTO.
  - `test_find_returns_null_on_unknown_category()` — `find( 'bogus-category', 'anything' )` returns `null`, no exception, no `_doing_it_wrong`.
  - `test_find_returns_null_on_unknown_slug()` — `find( 'client', 'nonexistent' )` returns `null`.
  - `test_get_clients_dto_shape()` — every `client` DTO has `meta.class`, `meta.config_file`, `meta.top_level_key`.
  - `test_get_ai_connectors_empty_by_default()` — `[]` on fresh install.
  - `test_flush_cache_forces_reassembly()` — call `get_all()`, register a `acrossai_mcp_connection_methods` filter that removes `npm`, call `get_all()` again (still cached), call `flush_cache()`, call `get_all()` (reflects filter).

### Implementation for User Story 1

- [x] T007a [P] [SC-007] Write `tests/phpunit/Public/Discovery/NpmDefaultHelperTest.php` — small `WP_UnitTestCase` covering `NpmClientBlock::get_default_npm_method()`:
  - `test_returns_six_top_level_keys()` — asserts `category`, `slug`, `name`, `description`, `icon`, `meta` all present.
  - `test_category_is_npm()` — asserts `category === 'npm'`.
  - `test_slug_matches_built_in()` — asserts `slug === 'npx-acrossai-mcp-manager'`.
  - `test_meta_has_command_template_and_enabled_option()` — asserts `meta.command_template === 'npx -y @acrossai/mcp-manager --siteurl=%s --server=%s'` AND `meta.enabled_option === 'acrossai_mcp_npm_login_enabled'`.
  Closes the SC-007 automated-regression coverage gap identified by `/speckit-analyze` C1 — template-drift on either the command template or the option name breaks CI immediately rather than surfacing only during T030 manual DOM diff.
- [x] T008 [US1] Create `public/Discovery/` directory + `public/Discovery/ConnectionMethodRegistry.php` file with the class scaffold per `contracts/ConnectionMethodRegistry.contract.md` §Class Shape: namespace `AcrossAI_MCP_Manager\Public\Discovery`, `final class ConnectionMethodRegistry`, `@experimental until plugin 1.0.0` class docblock (cite DEC-CLIENT-RENDERER-PUBLIC-API), `protected static ?self $_instance = null`, `private ?array $assembled_cache = null`, `private function __construct() {}`, `public static function instance(): self` (A2 pattern). Add explicit `use` imports for `AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient`, `AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry`, `AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock` (per A6 — never bare relative names). Class-level docblock MUST include SEC-035-002 consumer-security callout (see T020).
- [x] T009 [US1] In `ConnectionMethodRegistry.php` implement `public function get_clients(): array` per contract §`get_clients()`: call `AbstractMCPClient::get_all_registered_clients()`, map each instance to a DTO with `category => 'client'`, `slug => $c->get_client_slug()`, `name => $c->get_client_name()`, `description => $c->get_description()`, `icon => $c->get_icon()`, `meta => array( 'config_file' => $c->get_config_file(), 'top_level_key' => $c->get_top_level_key(), 'class' => get_class( $c ) )`. MUST NOT call `apply_filters( 'acrossai_mcp_client_classes', ... )` directly (SC-005 grep gate).
- [x] T010 [US1] In `ConnectionMethodRegistry.php` implement `public function get_ai_connectors(): array` per contract §`get_ai_connectors()`: call `ConnectorProfileRegistry::instance()->get_profiles()`, map each `AbstractConnectorProfile` to a DTO with `category => 'ai_connector'`, `slug => $p->get_slug()`, `name => $p->get_name()`, `description => ''` (profile lacks a description getter — safe empty string), `icon => $p->get_icon_url()`, `meta => array( 'icon_url' => $p->get_icon_url(), 'has_redirect_whitelist' => ! empty( $p->get_redirect_uri_whitelist() ), 'class' => get_class( $p ) )`. MUST NOT call `apply_filters( 'acrossai_mcp_manager_connector_profiles', ... )` directly (SC-005 grep gate).
- [x] T011 [US1] In `ConnectionMethodRegistry.php` implement `public function get_all(): array` per contract §`get_all()`: guard on `$this->assembled_cache !== null` (memoization return), otherwise assemble `array( 'npm' => $this->get_npm_methods(), 'clients' => $this->get_clients(), 'ai_connectors' => $this->get_ai_connectors() )`. Cross-category filter firing lives in T017 (US3) — leave a `// US3: apply_filters here` placeholder marker for T017 to replace. Cache the assembled result to `$this->assembled_cache` before returning. NOTE: `get_npm_methods()` doesn't exist yet at this task's completion — will exist post-T014 (US2). T011 can compile against a temporary skeleton `public function get_npm_methods(): array { return array( NpmClientBlock::get_default_npm_method() ); }` that T014 replaces with the filter-firing implementation.
- [x] T012 [US1] In `ConnectionMethodRegistry.php` implement `public function find( string $category, string $slug ): ?array` per contract §`find()`: `$all = $this->get_all();` → `if ( ! isset( $all[ $category ] ) ) return null;` → iterate `$all[ $category ]` and return first DTO where `$dto['slug'] === $slug`, else `null`. Zero validation, zero `_doing_it_wrong`.
- [x] T013 [US1] In `ConnectionMethodRegistry.php` implement `public function flush_cache(): void { $this->assembled_cache = null; }` per R2. Add PHPDoc noting production-shape naming (not `_reset_for_tests()` per B23) — documented as a supported surface for both PHPUnit `setUp()` and companion plugins that need mid-request filter re-registration.

**Checkpoint**: US1 tests (T007) go from RED → GREEN. `ConnectionMethodRegistry::instance()->get_all()` returns three-category array with 1 built-in NPM + 8 F034 clients + 0 AI connectors. `find()` returns matching DTO or `null`. Memoization works via `flush_cache()`. `get_npm_methods()` skeleton in T011 will be replaced by the filter-firing implementation in T014 (US2) — pre-Phase-4 shape is still spec-compliant but doesn't yet fire the filter.

---

## Phase 4: User Story 2 — NPM becomes extensible via filter (Priority: P2)

**Goal**: Third-party plugins can append custom NPM methods via `acrossai_mcp_npm_methods`. Malformed contributions silently dropped + `_doing_it_wrong` under `WP_DEBUG` (FR-009b — key AND type validation per SEC-035-001). Later-wins dedup by slug (FR-009a).

**Independent Test**: With Phase 3 + Phase 4 complete (US3 not yet started), register an mu-plugin that hooks `acrossai_mcp_npm_methods` to append a fake NPM DTO (slug `test-yarn`). `get_npm_methods()` returns 2 items: the built-in + the fake. Register a second callback returning malformed entries (missing keys, wrong types); assert dropped + `_doing_it_wrong` fires.

### Tests for User Story 2

- [x] T014a [P] [US2] Extend `ConnectionMethodRegistryTest.php` with US2 test methods (all in the same file — same SUT):
  - `test_npm_filter_fires_with_default_seed()` — `apply_filters( 'acrossai_mcp_npm_methods', ... )` gets a 1-item array seeded from `NpmClientBlock::get_default_npm_method()`.
  - `test_npm_filter_contribution_appears_in_output()` — filter callback appending a valid DTO → returned list includes both.
  - `test_npm_filter_duplicate_slug_later_wins()` [FR-009a] — filter callback appending a DTO with slug `npx-acrossai-mcp-manager` (built-in slug); output has 1 item (the appended one, not the built-in).
  - `test_npm_malformed_dto_missing_key_dropped()` [FR-009b] — filter callback returning `array( array( 'slug' => 'x' ) )` (missing 5 keys); output does NOT contain it; asserted via reflection or a `_doing_it_wrong` bridge helper that the WP core `doing_it_wrong_trigger_error` fires under `WP_DEBUG`.
  - `test_npm_malformed_dto_type_mismatch_dropped()` [SEC-035-001 / FR-009b'] — filter callback returning entries with `slug => array( 'not', 'string' )`, `meta => 'not-array'`, `name => new \stdClass()`; each dropped; `_doing_it_wrong` fires for each.
  - `test_get_npm_methods_does_not_fire_cross_category_filter()` [FR-012 / SC-004] — register `acrossai_mcp_connection_methods` callback that would remove `npm`; call `get_npm_methods()` in isolation; assert callback NOT invoked.

### Implementation for User Story 2

- [x] T014 [US2] In `ConnectionMethodRegistry.php` replace the T011 skeleton `get_npm_methods()` with the full implementation per contract §`get_npm_methods()`:
  1. Seed `$methods = array( NpmClientBlock::get_default_npm_method() );`
  2. `$methods = apply_filters( 'acrossai_mcp_npm_methods', $methods );`
  3. Type-guard: `if ( ! is_array( $methods ) ) { $methods = array( NpmClientBlock::get_default_npm_method() ); }` (defensive — a filter callback that returns non-array is malformed at the top level; recover with seed).
  4. Validate each entry via a new `private function validate_npm_dto( array $dto ): bool` helper (T015) — invalid entries dropped, `_doing_it_wrong( 'ConnectionMethodRegistry::get_npm_methods', __( '...', 'acrossai-mcp-manager' ), '0.1.9' )` under `WP_DEBUG`.
  5. Dedup by slug — later-wins per FR-009a. Iterate keyed on `$dto['slug']`, later assignment overwrites earlier.
  6. `array_values` reindex before return.
- [x] T015 [SEC-035-001] [US2] In `ConnectionMethodRegistry.php` add `private function validate_npm_dto( array $dto ): bool`: return `false` if any of the six required keys is missing OR if any of the five string-typed keys (`category`, `slug`, `name`, `description`, `icon`) fails `is_string()` OR if `meta` fails `is_array()`. This closes SEC-035-001 (a malicious filter contribution with `slug => array()` / `meta => 'string'` cannot reach downstream consumers). Direct application of the tightened FR-009b'.

**Checkpoint**: US2 tests (T014a) go from RED → GREEN. `acrossai_mcp_npm_methods` filter fires exactly once inside `get_npm_methods()`. Malformed contributions dropped with `_doing_it_wrong`. Later-wins dedup works. SEC-035-001 type-validation closed.

---

## Phase 5: User Story 3 — Cross-category filter enables holistic customization (Priority: P3)

**Goal**: Third-party plugins can hook `acrossai_mcp_connection_methods` to modify the entire assembled result (remove categories, prepend/append DTOs, decorate `meta`). Malformed callback return (non-array OR missing category keys) → discard filter return, use pre-filter result, fire `_doing_it_wrong` (FR-012a).

**Independent Test**: With all three phases complete, register an mu-plugin that hooks `acrossai_mcp_connection_methods` to remove `npm`; assert `get_all()['npm']` is empty; `clients` and `ai_connectors` unchanged. Register a second callback returning `null`; assert `get_all()` returns the pre-filter assembled result + `_doing_it_wrong` fires.

### Tests for User Story 3

- [x] T016a [P] [US3] Extend `ConnectionMethodRegistryTest.php` with US3 test methods:
  - `test_cross_category_filter_fires_once_in_get_all()` — filter callback increments a counter; call `get_all()` twice (once cached); counter is 1 (memoization prevents re-fire).
  - `test_cross_category_filter_can_remove_category()` — callback returns `array_merge( $a, array( 'npm' => array() ) )`; `get_all()['npm']` is `[]`; other categories unchanged.
  - `test_cross_category_filter_malformed_returns_prefilter()` [FR-012a] — callback returns `null` (non-array); `get_all()` returns the pre-filter three-category array; `_doing_it_wrong` fires under `WP_DEBUG`.
  - `test_cross_category_filter_missing_category_key_returns_prefilter()` [FR-012a] — callback returns `array( 'npm' => array(), 'clients' => array() )` (missing `ai_connectors`); pre-filter result used; `_doing_it_wrong` fires.
  - `test_cross_category_filter_does_not_fire_on_per_category_getters()` [FR-012 / SC-004] — callback increments counter; call `get_clients()`, `get_npm_methods()`, `get_ai_connectors()` individually; counter is 0.

### Implementation for User Story 3

- [x] T016 [US3] In `ConnectionMethodRegistry.php` `get_all()` method (replacing the T011 `// US3: apply_filters here` placeholder):
  1. Assemble pre-filter three-category array as before.
  2. Fire `$filtered = apply_filters( 'acrossai_mcp_connection_methods', $assembled );`
  3. Validate: `is_array( $filtered ) && isset( $filtered['npm'], $filtered['clients'], $filtered['ai_connectors'] ) && is_array( $filtered['npm'] ) && is_array( $filtered['clients'] ) && is_array( $filtered['ai_connectors'] )`. If FALSE → discard `$filtered`, use `$assembled`, `_doing_it_wrong( 'ConnectionMethodRegistry::get_all', __( '...', 'acrossai-mcp-manager' ), '0.1.9' )` under `WP_DEBUG`.
  4. Cache to `$this->assembled_cache` and return.

**Checkpoint**: US3 tests (T016a) go from RED → GREEN. `acrossai_mcp_connection_methods` fires exactly once per `get_all()` call (memoized). Malformed callback return falls back to pre-filter result. Per-category getters do NOT fire the cross-category filter.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Grep-gate verification, memory hygiene, changelog, quality gates, documentation callouts (SEC-035-002/003/004), CLAUDE.md pointer, manual verification.

### Grep-gate verification (SC-005, SC-006)

- [x] T017 [SC-005] Run grep audit from `spec.md` SC-005 (delegation, not re-firing):
  - `grep -rEn 'apply_filters.*acrossai_mcp_client_classes|apply_filters.*acrossai_mcp_manager_connector_profiles' public/Discovery/` MUST return zero hits.
  - `grep -rEn '\bacrossai_mcp_client_classes\b|\bacrossai_mcp_manager_connector_profiles\b' public/Discovery/` MUST return zero hits (proves no bare-string reference either — B15 completeness).
- [x] T018 [SC-006] Run grep audit from `spec.md` SC-006 (one-way layering):
  - `grep -rEn '\bConnectionMethodRegistry\b' --include='*.php' includes/` MUST return zero hits.
  - `grep -rEn 'use AcrossAI_MCP_Manager\\\\Public\\\\Discovery' --include='*.php' includes/` MUST return zero hits.
- [x] T019 [A1] Run `grep -rEn 'add_filter|add_action' --include='*.php' public/Discovery/` — MUST return zero hits (F035 defines filters, never registers them; A1 preserved).

### Documentation callouts (SEC-035-002/003/004)

- [x] T020 [SEC-035-002] In `ConnectionMethodRegistry.php` class-level docblock add a `## Consumer Security Responsibility` section:
  > DTO string fields (`name`, `description`, `icon`, `meta.*`) are contributed by admin-installed companion plugins and are NOT pre-escaped by this class. If you render these values into an admin page, REST response, or frontend HTML, escape at the render boundary using the most-specific WordPress escaping function (`esc_html()`, `esc_attr()`, `esc_url()` per context). Mirrors F034 SEC-034-001 preservation invariant.
- [x] T021 [SEC-035-002] In `quickstart.md` §1 (Enumerate every connection method), immediately after the `printf` example, add the same callout as a `> **Security note**:` block.
- [x] T022 [SEC-035-003] In `data-model.md` §Category `npm` `meta` sub-shape, expand the `enabled_option` row description to include the consumer contract: "Treated as a boolean gate flag. Consumers MUST verify the return of `get_option( $dto['meta']['enabled_option'] )` is truthy before considering the NPM method enabled. Consumers MUST NOT use this field as a general-purpose option-name channel or leak the returned value into their UI."
- [x] T023 [SEC-035-004] In `quickstart.md` §Memoization, replace the current final paragraph with the tightened security-implication callout from `docs/security-reviews/2026-07-26-035-connection-method-discovery-api-plan.md` §SEC-035-004 Remediation.

### Memory hygiene + changelog + pointer

- [x] T024 [P] In `README.txt` under `== Changelog ==` add a new `= Unreleased =` section (create if missing) with the F035 bullet:
  > * **Feature 035 — Public connection-method discovery API.** New `AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry` singleton exposes every registered NPM method, MCP client, and AI connector as a unified list of plain-associative-array DTOs — one canonical entry point (`get_all()`) replaces three separate registries with three different shapes. Third-party plugins (e.g. planned BuddyBoss add-on) can now enumerate every connection method the site supports without re-implementing per-category lookup loops. Adds two new extensibility filters: `acrossai_mcp_npm_methods` (NPM extension seam — new; NPM had no extension surface before this release) and `acrossai_mcp_connection_methods` (fires once on the assembled `get_all()` result for cross-category concerns without duplicating three separate filter registrations). Delegates transparently to `AbstractMCPClient::get_all_registered_clients()` (F034) and `ConnectorProfileRegistry::get_profiles()` (F021) — never re-fires their filters. `NpmClientBlock` grows a new `get_default_npm_method()` static helper so the NPM template + option gate has a single source of truth (byte-identical NPM tab render preserved). New `discovery` PHPUnit suite added; no admin UI, no REST routes, no database changes. Marked `@experimental until plugin 1.0.0` per `DEC-CLIENT-RENDERER-PUBLIC-API`.
- [x] T025 [P] In `CLAUDE.md` (root) — already updated by plan phase to `Active plan: specs/035-connection-method-discovery-api/plan.md`. Verify no stale reference. No-op if already correct.
- [x] T026 Memory hygiene — no new decision entry required. The F035 shipping surface is a pure application of existing patterns (D35 canonical enumeration, B32 canonical resolver defence, DEC-CLIENT-RENDERER-PUBLIC-API `public/` layer policy). If `/speckit-memory-md-capture-from-diff` after implementation surfaces a novel lesson (e.g., bootstrap-wp integration gotcha, memoization+PHPStan interaction), capture then — not now.

### Quality gates

- [x] T027 [P] Run `vendor/bin/phpcs public/Discovery/ public/Renderers/NpmClientBlock.php tests/phpunit/Public/Discovery/` — MUST report zero errors and zero warnings.
- [x] T028 [P] Run `vendor/bin/phpstan analyse public/Discovery/ public/Renderers/NpmClientBlock.php --memory-limit=4G --no-progress` — MUST report zero errors at level 8.
- [x] T029 Run `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite=discovery` — new suite MUST be green. Also verify no regression in `--testsuite=renderers` (touched `NpmClientBlock.php`), `--testsuite=mcpclients`, and other pre-existing suites. Depends on T027, T028.
- [x] T030 Manual verification checklist from `spec.md` §Success Criteria + `security-constraints.md`:
  - (a) Load the server-edit → NPM tab on a local install; diff rendered HTML against `/tmp/f035-npm-preflight.html` (T001 baseline) — MUST be byte-identical (SC-007).
  - (b) Register an mu-plugin exercising all four quickstart examples (enumerate + find + add NPM method + cross-category filter); each returns expected results.
  - (c) Register an mu-plugin with a MALICIOUS callback returning DTOs with `slug => array()` / `meta => 'string'` (SEC-035-001 exercise); verify entries dropped + `debug.log` under `WP_DEBUG=true` shows `_doing_it_wrong` warnings.
  - (d) Verify `wpApiSettings.rootURL` / MCP-related admin pages unchanged (F035 adds zero admin UI — visual sanity).
  Depends on T029.

**Checkpoint**: All spec Success Criteria met. Ready for `/speckit-git-commit` + PR.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: No dependencies — T001 first.
- **Foundational (Phase 2)**: Depends on Phase 1. T002 → T003 (both touch `NpmClientBlock.php`, sequential). T004 + T005 + T006 in parallel (different files).
- **US1 (Phase 3)**: Depends on Phase 2. T007 test file lands after T006 dir creation. T008 (class scaffold) blocks T009–T013 (all touch the same file, sequential).
- **US2 (Phase 4)**: Depends on Phase 3 (T014 replaces T011's skeleton).
- **US3 (Phase 5)**: Depends on Phase 3 (T016 replaces T011's placeholder marker inside `get_all()`).
- **Polish (Phase 6)**: Depends on Phase 3 + 4 + 5 completing.

### User story dependencies

- **US1 (P1)**: Delivers the unified enumeration MVP. Independent — can be demoed by calling `get_all()` from an mu-plugin/unit test without US2's NPM extension or US3's cross-category filter.
- **US2 (P2)**: Delivers NPM extensibility symmetry. Depends on US1 (extends `get_npm_methods()` from skeleton to full).
- **US3 (P3)**: Delivers cross-category curation. Depends on US1 (extends `get_all()` with the second filter). Independent of US2.

### Within-story ordering

- Tests written FIRST, ensure FAIL against pre-implementation code (per Constitution §II + AGENTS.md).
- Class scaffold (T008) before any method implementation (T009–T013).
- T009 + T010 can run in parallel (different `get_*` methods on same file — the file being new, no merge conflict risk if one developer authors both back-to-back).
- T011 (`get_all` skeleton) depends on T009 + T010 (needs them callable). T012 + T013 can then run in parallel with each other after T011.
- T014 (US2 full implementation) depends on T011 skeleton existing. T015 (validate_npm_dto helper) can land alongside T014 (same file, same commit).
- T016 (US3 full implementation) depends on T011 skeleton existing. Independent of T014.

### Parallel opportunities

- **T004 + T005 + T006 (Phase 2)**: Different files; parallel.
- **T007 [P] + T014a [P] + T016a [P]**: All extend the same test file — SEQUENTIAL, not parallel, to avoid merge conflicts. Marked [P] only if authored back-to-back in one commit.
- **T017 + T018 + T019 (grep gates)**: Independent commands; parallel.
- **T020–T023 (documentation callouts)**: Different files/sections; parallel.
- **T024 + T025 (memory + pointer)**: Different files; parallel.
- **T027 + T028 (PHPCS + PHPStan)**: Independent quality gates; parallel.

### Cross-cut

- SEC-035-001 (LOW security finding) → folded into **T015** (Phase 4 helper + Phase 4 test coverage in T014a).
- SEC-035-002/003/004 (INFO security findings) → folded into **T020–T023** (Phase 6 documentation).
- Every grep gate has one dedicated task with the exact command spelled out (SC-005 → T017, SC-006 → T018, A1 preservation → T019). Prevents B26 "grep gate hard-codes allow-list silently skips new layer" pattern.

---

## Parallel Example: User Story 1 method fan-out

After T008 (class scaffold) lands:

```bash
# T009 + T010 fan out on the same new file — safe if authored back-to-back in one commit.
Task: T009 [US1] Implement get_clients() (delegates to F034 canonical enumeration)
Task: T010 [US1] Implement get_ai_connectors() (delegates to F021 canonical enumeration)
# T011 (get_all skeleton) awaits both.
# T012 + T013 fan out after T011.
```

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Complete Phase 1 + Phase 2 (T001–T006).
2. Complete Phase 3 (US1): T007–T013.
3. **STOP and VALIDATE**: `vendor/bin/phpunit --bootstrap tests/bootstrap-wp.php --testsuite=discovery` green. Register test mu-plugin exercising quickstart §1 + §2 (enumerate + find); verify unified 3-category output.
4. This is a shippable MVP — BuddyBoss add-on can consume `get_all()` immediately (US2 + US3 extensibility filters can land in a follow-up minor release).

### Recommended: Ship all three stories together

The two new filters (US2 + US3) are the reason F035 exists as a public API rather than a private helper. Shipping only US1 would deliver "one canonical enumeration" but not "extensibility seams for both NPM and cross-category." Recommended: complete all six phases as one PR.

### Parallel team strategy

With multiple developers post-Phase-2:
- Developer A: T007 + T008 + T009 + T010 + T011 (US1 core)
- Developer B: waits for A's T011 skeleton, then T014 + T015 + T014a (US2)
- Developer C: waits for A's T011 placeholder, then T016 + T016a (US3)
- Developer D: T020–T024 (documentation + changelog) in parallel with A, B, C

---

## Notes

- [P] tasks = different files OR distinct-region back-to-back writes. Safe to fan out.
- [US1] / [US2] / [US3] label maps task to spec.md user story for traceability.
- Every task cites an exact file path. No vague tasks.
- Tests written BEFORE implementation — new tests MUST fail against pre-implementation code and pass after their corresponding implementation task completes.
- Commit after each task or per-phase logical group.
- SEC-035-001 is a plan-phase security-review preservation invariant — DO NOT skip T015; per DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX pattern.
- Grep gates SC-005, SC-006, A1 have dedicated tasks (T017, T018, T019) with the exact command spelled out to prevent B26 "grep gate hard-codes allow-list silently skips new layer" pattern.
- Feature-number discrepancy noted at the top of `spec.md`: brief filename says F036, spec dir + branch use `035-` per next-sequential numbering after F034. No functional impact; resolve at merge if desired.

---

## SEC-035-001..004 Coverage Matrix

Per DEC-F025-TASKS-REVIEW-PRESERVATION-INVARIANT-AND-COVERAGE-MATRIX, every plan-review finding maps to a remediation task ID:

| Finding | Severity | Owner Task(s) | Verification |
|---|---|---|---|
| SEC-035-001 | LOW | T014 (implementation), T015 (helper), T014a (test `test_npm_malformed_dto_type_mismatch_dropped`) | Contract §`get_npm_methods()` post-condition; SC-008 |
| SEC-035-002 | INFO | T020 (docblock), T021 (quickstart callout) | Manual doc review |
| /speckit-analyze C1 | MEDIUM | T007a (NpmDefaultHelperTest) | Automated SC-007 template-drift regression added — closes gap identified by 2026-07-26 analyze pass |
| /speckit-analyze C2 | LOW | T008 (docblock ref fixed) | Docblock cross-reference typo corrected: (see T024) → (see T020) |
| SEC-035-003 | INFO | T022 (data-model.md expansion) | Manual doc review |
| SEC-035-004 | INFO | T023 (quickstart §Memoization tightening) | Manual doc review |
