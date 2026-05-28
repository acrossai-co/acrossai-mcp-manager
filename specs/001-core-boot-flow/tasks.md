# Tasks: Core Boot Flow — WPBoilerplate Loader Migration

**Feature**: 001 | **Branch**: `feature/issue-3` | **Date**: 2026-05-29
**Input**: `specs/001-core-boot-flow/plan.md`, `spec.md`, `research.md`, `data-model.md`
**Governance**: APPROVED (`governance-summary.md`) | **Security**: APPROVED_WITH_CONDITIONS (`security-constraints.md`)

**Tests**: No PHPUnit tasks in this phase (not requested in spec). Validation via PHPCS + PHPStan only.

**Organization**: Tasks follow plan.md Phase A → B → C → D mapped to user stories from spec.md.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no incomplete dependencies)
- **[Story]**: Primary user story this task delivers (`[US1]`–`[US6]`)
- Exact file paths in every description

---

## Phase 1: Setup — Verify Root File

**Purpose**: Confirm `acrossai-mcp-manager.php` already satisfies all FR-010 requirements before touching any other file. Expected to be a no-op (no code changes).

- [X] T001 Verify `acrossai-mcp-manager.php` contains all required elements: `define('ACROSSAI_MCP_MANAGER_PLUGIN_FILE', __FILE__)` at file scope, `register_activation_hook()` at file root (not inside a class), `acrossai_mcp_manager_activate()` → `Activator::activate()`, and `acrossai_mcp_manager_run()` → `Main::instance()` on `plugins_loaded`

---

## Phase 2: Foundational — Singleton Pattern for Existing Classes

**Purpose**: Add the constitution-required singleton pattern to the three existing boilerplate classes. These MUST be complete before Phase 3+ can call `::instance()` on them.

**⚠️ CRITICAL**: Tasks T009, T010, T012 in Phase 5 depend on `::instance()` being available on these classes.

- [X] T002 Add singleton pattern to `admin/Main.php`: add `protected static $_instance = null`, `public static function instance(): self` factory, and make constructor `private` — keep existing constructor body intact
- [X] T003 [P] Add singleton pattern to `admin/Partials/Menu.php`: same three additions (`$_instance`, `instance()`, `private __construct`) — keep existing constructor body intact
- [X] T004 [P] Add singleton pattern to `public/Main.php`: same three additions — keep existing constructor body intact

**Checkpoint**: All three classes return instances via `ClassName::instance()` without `new`

---

## Phase 3: User Story 3 — All Six Constants Available (Priority: P1) 🎯 MVP

**Goal**: All 6 plugin constants are defined from a single location (`Main::define_constants()`) with correct literal values; `$this->version` is set immediately after.

**Independent Test**: After activation, `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG === 'acrossai-mcp-manager'` is true. Running `grep -rn "^[[:space:]]*define(" includes/ admin/ public/ | grep -v "Main.php"` returns zero results.

- [X] T005 [US3] Fix `includes/Main.php` `define_constants()`: change `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` from `$this->plugin_name` (null at call time — Bug B2) to the literal string `'acrossai-mcp-manager'`
- [X] T006 [US3] Add `$this->version = ACROSSAI_MCP_MANAGER_VERSION;` to `includes/Main.php` constructor immediately after the `$this->define_constants()` call and before `$this->plugin_name` assignment

**Checkpoint**: US3 acceptance scenarios pass — constants defined with correct values, no `define()` outside `define_constants()`

---

## Phase 4: User Story 2 — Boot Sequence Strict Order (Priority: P1)

**Goal**: Constructor calls exactly five init methods in the documented order; `load_dependencies()` contains only `Loader::instance()`.

**Independent Test**: Read `includes/Main.php` constructor — method call order is `define_constants()` → `load_composer_dependencies()` → `load_dependencies()` → `set_locale()` → `load_hooks()`. Running `grep -A20 "function load_dependencies"` shows only `$this->loader = Loader::instance()`.

- [X] T007 [US2] Verify and enforce constructor sequence in `includes/Main.php`: method calls must appear in this exact order: `$this->define_constants()` → `$this->version = ...` → `$this->plugin_name = ...` → `$this->plugin_dir = ...` → `$this->load_composer_dependencies()` → `$this->load_dependencies()` → `$this->set_locale()` → `$this->load_hooks()`
- [X] T008 [US2] Verify `includes/Main.php` `load_dependencies()` contains only `$this->loader = Loader::instance();` — remove any `boot()`, `register_hooks()`, or hook-registration calls if present

**Checkpoint**: US2 acceptance scenarios pass — boot sequence order correct, `load_dependencies()` is Loader-only

---

## Phase 5: User Story 4 — All Module Hooks Wired Through Loader (Priority: P1)

**Goal**: `define_admin_hooks()` uses `::instance()` for all active classes and has TODO stubs for all unmigrated modules. `define_public_hooks()` uses `::instance()`. Zero `add_action()`/`add_filter()` calls in any constructor.

**Independent Test**: `grep -rn "add_action\|add_filter" includes/ admin/ public/ | grep -v "Loader.php\|Main.php"` returns zero results.

- [X] T009 [US4] Convert `define_admin_hooks()` in `includes/Main.php`: replace `new \AcrossAI_MCP_Manager\Admin\Main(...)` with `$plugin_admin = \AcrossAI_MCP_Manager\Admin\Main::instance();` and update all Loader calls to use `$plugin_admin`
- [X] T010 [US4] Convert `define_admin_hooks()` in `includes/Main.php`: replace `new \AcrossAI_MCP_Manager\Admin\Partials\Menu(...)` with `$main_menu = \AcrossAI_MCP_Manager\Admin\Partials\Menu::instance();` and update all Loader calls to use `$main_menu`
- [X] T011 [US4] Add all TODO stub comment blocks to `define_admin_hooks()` in `includes/Main.php` for unmigrated modules: Settings (phase 3, 2 hooks), ApplicationPasswords (phase N), MCP\Controller (phase 4, 1 hook), REST\CliController (phase 5, 1 hook), OAuth\ClaudeConnectors (phase 6, all 10 hooks from research.md), AccessControl `rest_pre_dispatch` filter (phase 7)
- [X] T012 [US4] Convert `define_public_hooks()` in `includes/Main.php`: replace `new \AcrossAI_MCP_Manager\Public\Main(...)` with `$plugin_public = \AcrossAI_MCP_Manager\Public\Main::instance();` and update all Loader calls
- [X] T013 [US4] Add TODO stub comment block for `Public\Partials\FrontendAuth` with all 5 hooks (from research.md) to `define_public_hooks()` in `includes/Main.php`

**Checkpoint**: US4 acceptance scenarios pass — all active hooks via Loader, TODO stubs for all unmigrated modules, no `add_action` in constructors

---

## Phase 6: User Story 1 — Plugin Boots Without Errors (Priority: P1)

**Goal**: `load_hooks()` kill switch is present so third-party plugins can suppress all hook registration. Plugin activates on WP 6.9 / PHP 8.0 with `WP_DEBUG=true` and shows the admin menu.

**Independent Test**: Activate plugin → no fatal errors or notices in debug log. Add `add_filter('acrossai_mcp_manager_load', '__return_false')` → admin menu disappears.

- [X] T014 [US1] Ensure `load_hooks()` in `includes/Main.php` gates all hook registration behind `if ( ! apply_filters( 'acrossai_mcp_manager_load', true ) ) { return; }` before calling `define_admin_hooks()` and `define_public_hooks()` (FR-005 / C-SR-006 guard)

**Checkpoint**: US1 acceptance scenarios 1–3 pass — boot completes without errors, kill switch works, admin menu visible

---

## Phase 7: User Story 5 — Compat Helpers Available (Priority: P2)

**Goal**: `includes/Compat.php` exists with correct namespace, ABSPATH guard, constants, and all 8 static methods ported verbatim from source.

**Independent Test**: `Compat::str_contains('hello', 'ell')` → `true`. `Compat::supports('8.0')` → `true` on PHP 8.0+. File has `namespace AcrossAI_MCP_Manager\Includes`.

- [X] T015 [US5] Create `includes/Compat.php`: add ABSPATH guard (`defined('ABSPATH') || exit;`), namespace `AcrossAI_MCP_Manager\Includes`, class `Compat`, class constants `PHP_MIN = '7.4'` and `PHP_MAX = '8.5'` — no `require_once` (PSR-4 autoloaded by Jetpack autoloader)
- [X] T016 [P] [US5] Port static methods `str_contains()`, `str_starts_with()`, `str_ends_with()` verbatim from `src/Core/Compat.php` into `includes/Compat.php`
- [X] T017 [P] [US5] Port static methods `array_is_list()`, `array_key_first()`, `array_key_last()` verbatim from `src/Core/Compat.php` into `includes/Compat.php`
- [X] T018 [US5] Port static methods `supports()` and `in_range()` verbatim from `src/Core/Compat.php` into `includes/Compat.php`

**Checkpoint**: US5 acceptance scenarios pass — namespace correct, all 8 methods callable, no require_once

---

## Phase 8: User Story 6 — Activation Bootstraps DB Tables and Rewrite Rules (Priority: P2)

**Goal**: `Activator::activate()` uses `use` imports (C-SR-001), guards all 3 DB calls with `class_exists()`, registers all 4 rewrite rules with literal path strings, and flushes rewrite rules.

**Independent Test**: Activate plugin → `wp rewrite list` shows 4 MCP rules. Deactivate + reactivate → no errors (idempotent). `grep -n "use " includes/Activator.php` shows 3 `use` import lines for DB Query classes.

- [X] T019 [US6] Add three `use` import aliases at the top of `includes/Activator.php` class file (after `namespace` declaration): `use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;`, `use AcrossAI_MCP_Manager\Includes\Database\CliAuthLog\Query as CliAuthLogQuery;`, `use AcrossAI_MCP_Manager\Includes\Database\ConnectorAuditLog\Query as ConnectorAuditLogQuery;` — MUST NOT use bare relative names (C-SR-001 / Bug B1)
- [X] T020 [US6] Add `class_exists( MCPServerQuery::class )` guard with `MCPServerQuery::maybe_create_table()` call inside `Activator::activate()` in `includes/Activator.php` — silent no-op when class absent
- [X] T021 [US6] Add `class_exists( CliAuthLogQuery::class )` guard with `CliAuthLogQuery::maybe_create_table()` and `class_exists( ConnectorAuditLogQuery::class )` guard with `ConnectorAuditLogQuery::maybe_create_table()` inside `Activator::activate()` in `includes/Activator.php`
- [X] T022 [US6] Add all 4 `add_rewrite_rule()` calls inside `Activator::activate()` in `includes/Activator.php` using literal strings: `'^acrossai-mcp-manager/?$'` → `index.php?mcp_frontend_auth=1`, `'^acrossai-mcp-connectors/oauth/authorize/?$'` → `index.php?mcp_oauth_authorize=1`, `'^\.well-known/oauth-authorization-server/?$'` → `index.php?mcp_oauth_metadata=1`, `'^\.well-known/oauth-protected-resource/?$'` → `index.php?mcp_oauth_metadata_resource=1`
- [X] T023 [US6] Add `flush_rewrite_rules();` at end of `Activator::activate()` body in `includes/Activator.php`

**Checkpoint**: US6 acceptance scenarios pass — rewrite rules registered, DB tables bootstrapped or silently skipped, re-activation idempotent

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: PHPCS and PHPStan validation across all changed files; hook-call audit; success criteria sign-off.

- [X] T024 Run `vendor/bin/phpcs includes/Main.php includes/Compat.php includes/Activator.php admin/Main.php admin/Partials/Menu.php public/Main.php` and fix all WPCS strict violations in each file
- [X] T025 [P] Run `vendor/bin/phpstan analyse includes/Main.php includes/Compat.php includes/Activator.php admin/Main.php admin/Partials/Menu.php public/Main.php --level=8` and fix all type errors
- [X] T026 [P] Run hook-call audit: `grep -rn "add_action\|add_filter" includes/ admin/ public/ | grep -v "Loader.php\|Main.php"` — must return zero results; fix any violations found
- [X] T027 Verify all 7 success criteria in `docs/planings-tasks/phase-2-core-boot.md` are met and check them off

---

## Dependencies

```
T001                    → standalone verification; run first
T002, T003, T004        → must complete before T009, T010, T012
T005, T006              → can start after T001; T006 depends on T005
T007, T008              → can start after T005, T006
T009, T010              → must start after T002, T003 AND T007, T008
T011                    → after T009, T010
T012                    → must start after T004 AND T007
T013                    → after T012
T014                    → after T008 (load_hooks context)
T015                    → independent of Main.php changes
T016, T017              → parallel, both after T015
T018                    → after T015 (same file, different methods)
T019                    → independent of Main.php; can start after T001
T020, T021              → after T019
T022                    → after T020, T021 (same method)
T023                    → after T022
T024-T027               → must run AFTER all T001–T023 complete
```

### User Story Completion Order

```
US3 (T005–T006) → US2 (T007–T008) → US4 (T009–T013) → US1 (T014)
                                       ↓ independent
US5 (T015–T018) can run alongside US2/US4
US6 (T019–T023) can run alongside US2/US4/US5
```

## Parallel Execution Examples

```bash
# Wave 1 — independent
T001 + T002 + T003 + T004 + T015 + T019

# Wave 2 — after T002-T004 done; T015 done
T005 + T016 + T017 + T020

# Wave 3 — after T005
T006 + T018 + T021

# Wave 4 — after T006; T018 done; T021 done
T007 + T022

# Wave 5 — after T007
T008 + T009 + T010 + T012 + T023

# Wave 6 — after T008-T010, T012
T011 + T013 + T014

# Wave 7 — validation (after all impl complete)
T024 + T025 + T026 then T027
```

## Implementation Strategy

**MVP scope** (P1 stories only — deliver first):
Tasks T001–T014 cover US1–US4 (all P1). Plugin boots correctly with Loader pattern.

**P2 scope** (complete after MVP is validated):
Tasks T015–T023 cover US5 (Compat) and US6 (Activator).

**Delivery order**: Setup (T001) → Foundational (T002–T004) → US3 (T005–T006) → US2 (T007–T008) → US4 (T009–T013) → US1 (T014) → US5 (T015–T018) → US6 (T019–T023) → Polish (T024–T027)
