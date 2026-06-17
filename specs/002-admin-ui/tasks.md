---

description: "Task list for Phase 2 — Admin UI Migration"
---

# Tasks: Admin UI — Settings, List Tables, and Asset Enqueue

**Feature**: `specs/002-admin-ui/` | **Branch**: `002-admin-ui`
**Input**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`, `security-review-plan.md`

**Tests**: Spec DoD requires "PHPUnit tests written and passing for all new
PHP logic" → tests **ARE included** in this task list. Tests follow each
implementation, not before (this is a 1:1 port — source code already exists
and is the contract; TDD against existing code is regression-style).

**Organization**: Tasks are grouped by user story so each story is independently completable and testable. Mapping: US1=Menu · US2=List/Create · US3=Edit/Tabs · US4=Notice · US5=Assets · US6=Loader invariant.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Maps task to user story (US1…US6); Setup/Foundational/Polish have no story label
- File paths are absolute or repo-root-relative; every code task names the exact file

## Path Conventions

- WordPress plugin layout (constitution Architecture & UI Standards):
  - `admin/Main.php` (extend)
  - `admin/Partials/*.php` (new — namespace `AcrossAI_MCP_Manager\Admin\Partials`)
  - `includes/Main.php` (extend — Loader wiring)
  - `src/js/` JS sources; `build/js/backend.{js,asset.php}` built artefacts
  - `tests/phpunit/admin/Partials/*Test.php` for unit tests
- Source repo to port from: `../acrossai-mcp-manager/src/Admin/*.php`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Pre-flight checks before any code change.

- [ ] T001 Verify the feature directory is intact — `specs/002-admin-ui/{spec,plan,research,data-model,quickstart}.md` and `contracts/{loader-wiring,notice-dismissal}.md` all present
- [ ] T002 [P] Confirm `phpcs.xml.dist` Phase 1 baseline exclusions are still present (filename casing, `$_instance` prefix, file docblocks, `namespace Public`, PSR12 header) — D5 invariant
- [ ] T003 [P] Confirm `build/js/backend.asset.php` and `build/css/backend.asset.php` exist (run `npm run build` if absent so US5 verification can succeed)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Hard gates and skeletons that every user story depends on.

**⚠️ CRITICAL**: No user story work may begin until **T004** passes.

- [ ] T004 **P0 GATE — STOP if it fails**: Verify `includes/Database/MCPServer/Query.php` and `includes/Database/CliAuthLog/Query.php` exist and expose `query()`, `add_item()`, `update_item()`, `delete_item()`. If absent, do NOT proceed — escalate as a cross-phase blocker per `plan.md` Hard Prerequisite. (FR-023, plan.md P0)
- [ ] T005 [P] Create `admin/Partials/Settings.php` with singleton skeleton (namespace, `defined('ABSPATH') || exit`, `protected static $_instance`, `public static instance(): self`, `private __construct()` that only assigns `$plugin_name` and `$version`; **no `add_action`/`add_filter`**) — per research.md R2
- [ ] T006 [P] Create `admin/Partials/SettingsRenderer.php` with singleton skeleton (same pattern as T005)
- [ ] T007 [P] Create `admin/Partials/ApplicationPasswords.php` with singleton skeleton (same pattern as T005)
- [ ] T008 [P] Create `admin/Partials/MCPServerListTable.php` skeleton extending `\WP_List_Table` (require the core file inside the constructor body before parent::__construct via `if (!class_exists('WP_List_Table')) { require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }`; singleton retrofit retained)
- [ ] T009 [P] Create `admin/Partials/CliAuthLogListTable.php` skeleton (same pattern as T008)
- [ ] T010 Verify existing `admin/Partials/Menu.php` (from Phase 1) still has its singleton skeleton intact and is ready to extend in US1

**Checkpoint**: Foundation ready — all six partials exist as empty singleton shells; the Query prerequisite is confirmed. User stories can begin.

---

## Phase 3: User Story 1 — MCP Manager menu structure (P1) 🎯 MVP

**Goal**: Top-level "MCP Manager" menu + Servers / CLI Auth Log / (conditional) Access Control submenus + "Settings" plugin-action link.

**Independent Test**: Activate plugin on clean WP 6.9 → wp-admin sidebar shows the menu + submenus matching spec.md US1 scenarios 1–5; Plugins screen plugin row has "Settings" link to `?page=acrossai_mcp_manager`.

### Implementation for User Story 1

- [ ] T011 [US1] Implement `Menu::register_menu()` in `admin/Partials/Menu.php` per FR-001/FR-002 — `add_menu_page()` for the parent + `add_submenu_page()` for Servers (slug `acrossai_mcp_manager`), CLI Auth Log (slug `acrossai_mcp_manager_cli_auth_log`), and Access Control (slug `acrossai_mcp_manager_access_control`, **guarded by `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')`**) — D8
- [ ] T012 [US1] Implement `Menu::plugin_action_links()` in `admin/Partials/Menu.php` per FR-003 — prepend a "Settings" anchor; the anchor href MUST be `esc_url( admin_url( 'admin.php?page=acrossai_mcp_manager' ) )` to satisfy S5/B6/F4
- [ ] T013 [US1] In `includes/Main.php::define_admin_hooks()`, replace the Phase 1 `Menu` TODO stub with the canonical wiring from `contracts/loader-wiring.md` §2: resolve `$menu = Menu::instance();`, register `admin_menu` and `plugin_action_links_<basename>` filter (use `ACROSSAI_MCP_MANAGER_PLUGIN_BASENAME` constant); prefer `use` import or leading-`\` FQN per A6

### Tests for User Story 1

- [ ] T014 [P] [US1] PHPUnit test in `tests/phpunit/admin/Partials/MenuTest.php` — assert that after `Menu::instance()->register_menu()` runs, `$GLOBALS['menu']` contains an entry with slug `acrossai_mcp_manager`, that the Access Control submenu is present when the vendor class is mocked-existing and absent when mocked-missing
- [ ] T015 [P] [US1] PHPUnit test asserting `Menu::plugin_action_links()` prepends a "Settings" anchor whose href starts with `<a href="http` and contains `admin.php?page=acrossai_mcp_manager` (proves esc_url wrap path)

**Checkpoint**: Menu fully functional. Quickstart §1 passes.

---

## Phase 4: User Story 2 — Server list + create form (P1)

**Goal**: `WP_List_Table` for servers with bulk + row actions and a working "Add New" create form, all gated by nonce + `manage_options`.

**Independent Test**: Three seeded server rows render in the list with the eight required columns; toggle-status flips DB + admin notice; bulk-delete two rows works; create-form rejects duplicate slug with error notice; forged-nonce / non-admin requests are `wp_die()`'d.

### Implementation for User Story 2

- [ ] T016 [P] [US2] Port `MCPServerListTable` body from `../acrossai-mcp-manager/src/Admin/MCPServerListTable.php` into `admin/Partials/MCPServerListTable.php` per FR-004/FR-006 — column callbacks `column_name/slug/status/registered_from/route_namespace/route/version/actions`; `get_bulk_actions()` returns `enable/disable/delete`; row actions emit `Edit/Toggle Status/Delete` anchors **all wrapped with `esc_url(admin_url(...))` and `wp_nonce_url(...)`** (S5/B6/F4). Reads use `( new \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query() )->query( [] )` per research.md R1 — **no `MCPServerTable::` static calls**
- [ ] T017 [US2] Port `Settings::register_menu` body (registers the list page render callback) and `Settings::render_list_page` (renders the heading, "Add New" button anchor with `esc_url(admin_url(...))`, instantiates `MCPServerListTable`, calls `prepare_items()` + `display()`) into `admin/Partials/Settings.php`
- [ ] T018 [US2] Port `Settings::handle_actions` body into `admin/Partials/Settings.php` for **toggle / delete / bulk_enable / bulk_disable / bulk_delete** — each branch MUST call `check_admin_referer()` (nonce action per source: `acrossai_mcp_toggle_<id>`, `acrossai_mcp_delete_<id>`, `bulk-mcp_servers`), verify `current_user_can('manage_options')`, sanitise IDs with `absint()`, then call the corresponding `MCPServer\Query` method per research.md R1 (toggle = read row → flip status → `update_item`; delete = `delete_item`; bulk_enable/disable = loop `update_item`; bulk_delete = loop `delete_item`). After the write, `wp_safe_redirect()` to the list page with a `?notice=...` query var consumed by T035. **No `MCPServerTable::` calls remain.** FR-006, FR-007.
- [ ] T019 [US2] Port `Settings::handle_create_server` (FR-007a) into `admin/Partials/Settings.php` — nonce action `acrossai_mcp_create_server`, `manage_options` cap, sanitise (Name=`sanitize_text_field`, Description=`sanitize_textarea_field`, Slug+Route Namespace+Route+Version=`sanitize_text_field`). Slug-collision check via `( new MCPServer\Query() )->query( [ 'slug' => $slug, 'number' => 1 ] )` → if non-empty, redirect with `?notice=slug_exists` and no insert. Insert via `MCPServer\Query::add_item([...])` per R1; on success, redirect to the new server's edit page with `?notice=server_created`. FR-007a, F4 reminder for any `admin_url()` in redirect targets.
- [ ] T020 [US2] Port `Settings::register_settings` body into `admin/Partials/Settings.php` (settings API registration carried over verbatim from source — no semantic changes)
- [ ] T021 [US2] In `includes/Main.php::define_admin_hooks()`, replace the Phase 1 `Settings` TODO stub with the wiring from `contracts/loader-wiring.md` §3 + §7 — register `Settings::handle_actions` on `admin_init` priority 5, `Settings::register_settings` on `admin_init`, and `Settings::render_menu` (the list page render callback) is invoked via the `add_menu_page()` callback wired in T013/T017 — no separate hook needed

### Tests for User Story 2

- [ ] T022 [P] [US2] PHPUnit test in `tests/phpunit/admin/Partials/MCPServerListTableTest.php` — seed three rows via `MCPServer\Query::add_item`, call `prepare_items()` + capture `$this->items`, assert count = 3, columns = 8, bulk actions = `[enable, disable, delete]`
- [ ] T023 [P] [US2] PHPUnit test in `tests/phpunit/admin/Partials/SettingsActionsTest.php` — set up `$_REQUEST` for a toggle action with a valid nonce + admin user, call `Settings::handle_actions()`, assert the row status flipped; repeat with nonce removed → assert no DB write
- [ ] T024 [P] [US2] PHPUnit test for `Settings::handle_create_server` — submit a valid create → assert `MCPServer\Query::query()` finds the new row; submit a duplicate slug → assert no insert + redirect-notice query var = `slug_exists`

**Checkpoint**: List + create + bulk + row actions all functional. Quickstart §2, §3, §5 pass.

---

## Phase 5: User Story 3 — Four-tab server edit page (P1)

**Goal**: Edit URL renders General / Tokens / Access Control / Claude Connector tabs; each tab saves with nonce + cap + sanitiser + Query persistence; CLI Auth Log submenu lists log rows read-only.

**Independent Test**: Open edit URL → four tabs render with General active; save General changes Name persists; Tokens tab manages Application Passwords with hashed storage; Access Control delegates to vendor when present, renders info notice when absent; Claude Connector tab masks Secret on re-render; CLI Auth Log submenu shows log rows.

### Implementation for User Story 3

- [ ] T025 [P] [US3] Port `ApplicationPasswords` body 1:1 from `../acrossai-mcp-manager/src/Admin/ApplicationPasswords.php` into `admin/Partials/ApplicationPasswords.php` — **strip every `add_action()`/`add_filter()` from the constructor**; preserve hashed-storage contract (S3, Constitution §III bullet 7); preserve namespace `AcrossAI_MCP_Manager\Admin\Partials`
- [ ] T026 [P] [US3] Port `SettingsRenderer` 1:1 (25-line helper) from `../acrossai-mcp-manager/src/Admin/SettingsRenderer.php` into `admin/Partials/SettingsRenderer.php` — no constructor hooks; namespace updated
- [ ] T027 [P] [US3] Port `CliAuthLogListTable` body 1:1 from `../acrossai-mcp-manager/src/Admin/CliAuthLogListTable.php` into `admin/Partials/CliAuthLogListTable.php` — DB reads via `( new CliAuthLog\Query() )->query( [...] )`; read-only; no `CliAuthLogTable::` static calls (FR-022)
- [ ] T028 [US3] Port `Settings::render_edit_page` body into `admin/Partials/Settings.php` — accept `$server_id` from `$_GET['server']`; if missing-row, `wp_safe_redirect` to list with `?notice=server_not_found` (FR-014); render tab navigation with the current `tab` query var (default `general`); render the active tab's body by dispatching to `render_<tab>_tab( $server_id )`
- [ ] T029 [US3] Port `Settings::render_general_tab` per FR-009 — form fields for Name, Description, Route Namespace, Route, Version; `wp_nonce_field('acrossai_mcp_update_<server_id>')`; submit button labeled "Save General"; values escaped at output (`esc_attr` for inputs, `esc_textarea` for description, `esc_url` for any `admin_url()` action targets)
- [ ] T030 [US3] Port `Settings::render_tokens_tab` — delegate body to `ApplicationPasswords::instance()->render_for_server( $server_id )` (Module Contract item 2; sibling singleton called at use-site, never held as a long-lived reference)
- [ ] T031 [US3] Port `Settings::render_access_control_tab` per research.md R4 — `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')` guard; when present, delegate to vendor singleton; when absent, render `notice notice-info` with translated message and no inputs (FR-011, US3.5)
- [ ] T032 [US3] Port `Settings::render_claude_connector_tab` per FR-012 — form fields for Client ID, Client Secret (masked on re-render after first save — use `str_repeat('•', 12)` placeholder when row's `claude_oauth_client_secret` is non-empty AND form was not just submitted), Redirect URI; nonce `acrossai_mcp_claude_connector_<server_id>`; href targets use `esc_url(admin_url(...))`
- [ ] T033 [US3] Port `Settings::handle_update_server` (General-tab save) per FR-009/FR-013 — `check_admin_referer('acrossai_mcp_update_<server_id>')`; `manage_options`; sanitise per spec.md table; persist via `MCPServer\Query::update_item( $server_id, $data )` per R1; redirect with `?notice=server_saved`. **No `MCPServerTable::update_server()` call**.
- [ ] T034 [US3] Port `Settings::handle_claude_connector_update` per FR-012/FR-013 — nonce `acrossai_mcp_claude_connector_<server_id>`; sanitise Client ID + Secret with `sanitize_text_field`, Redirect URI with `esc_url_raw`; persist via `MCPServer\Query::update_item( $server_id, [ 'claude_oauth_client_id' => ..., 'claude_oauth_client_secret' => ..., 'claude_oauth_redirect_uri' => ... ] )` per R1
- [ ] T035 [US3] Port `Settings::render_action_result_notice` body (FR-016) into `admin/Partials/Settings.php` — read `$_GET['notice']`, map via centralised array to translated message + severity (`success/error`), render with `esc_html()`. Notice keys cover at minimum: `server_created`, `server_saved`, `server_deleted`, `server_toggled`, `slug_exists`, `server_not_found`, `bulk_completed`
- [ ] T036 [US3] Port `Settings::render_cli_auth_log_page` body — instantiate `CliAuthLogListTable`, call `prepare_items()` + `display()`; this becomes the callback for the CLI Auth Log submenu registered in T011
- [ ] T037 [US3] In `includes/Main.php::define_admin_hooks()`, add the `ApplicationPasswords` hook lines from `contracts/loader-wiring.md` §5 — `init` for `register_post_type` (if present in source class), `wp_authenticate_application_password_errors` filter, and any other hooks the source class needs (read its constructor to enumerate)
- [ ] T038 [US3] In `includes/Main.php::define_admin_hooks()`, add the **guarded** Access Control wiring from `contracts/loader-wiring.md` §8 — wrap in `if ( class_exists('\WPBoilerplate\AccessControl\AccessControlManager') ) { ... }`, resolve the vendor singleton to a named variable, wire any required submenu hook
- [ ] T039 [US3] In `includes/Main.php::define_admin_hooks()`, register `Settings::render_action_result_notice` on `admin_notices` (contracts/loader-wiring.md §7)

### Tests for User Story 3

- [ ] T040 [P] [US3] PHPUnit test in `tests/phpunit/admin/Partials/SettingsEditTest.php` — assert `render_edit_page` redirects on missing server ID; assert tab dispatch routes to `render_general_tab` by default; assert Access Control tab renders info notice when the vendor class is mocked-missing
- [ ] T041 [P] [US3] PHPUnit test for `Settings::handle_update_server` — seed a row, POST a valid update with sanitised values + nonce, assert the row is updated via Query; assert nonce-missing case rejects
- [ ] T042 [P] [US3] PHPUnit test for `Settings::handle_claude_connector_update` — verify Client Secret persisted as-is (1:1 port behaviour, F3 advisory acknowledged) and Redirect URI sanitised via `esc_url_raw`
- [ ] T043 [P] [US3] PHPUnit smoke test for `ApplicationPasswords` — assert constructor produces no hook side-effects; assert `render_for_server()` produces output (just shape, not content) when invoked

**Checkpoint**: Edit-page tabs functional; CLI Auth Log submenu lists rows. Quickstart §4 + §8 pass.

---

## Phase 6: User Story 4 — Adapter-missing notice (P2)

**Goal**: Dismissible warning notice when `\WP\MCP\Plugin` is absent; sticky per-user dismissal via user meta `acrossai_mcp_dismissed_adapter_notice`.

**Independent Test**: Without the adapter, every wp-admin page shows the dismissible notice; click X → reload → notice gone for that user; second admin in incognito sees notice; install adapter → notice gone for all.

### Implementation for User Story 4

- [ ] T044 [US4] Implement `Settings::render_missing_adapter_notice` in `admin/Partials/Settings.php` per `contracts/notice-dismissal.md` "PHP render guard" — short-circuit on `class_exists('\WP\MCP\Plugin')` AND on user-meta truthy; render `notice notice-warning is-dismissible acrossai-mcp-adapter-notice` div with `data-nonce` attr via `esc_attr( wp_create_nonce('acrossai_mcp_dismiss_adapter_notice') )` and `esc_html__()` message body
- [ ] T045 [US4] Implement `Settings::handle_adapter_notice_dismissal` per `contracts/notice-dismissal.md` "PHP handler" — `check_ajax_referer('acrossai_mcp_dismiss_adapter_notice')`; `current_user_can('manage_options')` → on fail `wp_send_json_error(['message'=>'forbidden'], 403)`; `update_user_meta(get_current_user_id(), 'acrossai_mcp_dismissed_adapter_notice', 1)`; `wp_send_json_success()`
- [ ] T046 [US4] In `includes/Main.php::define_admin_hooks()`, add lines from `contracts/loader-wiring.md` §6 — `admin_notices` for `render_missing_adapter_notice` (registered unconditionally — renderer self-guards) AND `wp_ajax_acrossai_mcp_dismiss_adapter_notice` for `handle_adapter_notice_dismissal`
- [ ] T047 [US4] Add the small inline dismiss-JS to `src/js/admin-notices.js` per `contracts/notice-dismissal.md` "JS" section — fire-and-forget POST to `ajaxurl` with `action` and `_ajax_nonce` from `data-nonce`; bundled into `backend.js` by the existing `@wordpress/scripts` build (verify `npm run build` includes it)

### Tests for User Story 4

- [ ] T048 [P] [US4] PHPUnit test in `tests/phpunit/admin/Partials/SettingsNoticeTest.php` — assert `render_missing_adapter_notice` outputs nothing when `class_exists('\WP\MCP\Plugin')` is true (use a mock); assert it outputs nothing when user-meta `acrossai_mcp_dismissed_adapter_notice` is `1`; assert it outputs an `is-dismissible` notice with a `data-nonce` attr in the default case
- [ ] T049 [P] [US4] PHPUnit test for `handle_adapter_notice_dismissal` — verify nonce-pass + cap-pass → user_meta written + `wp_send_json_success` called; verify cap-fail → `wp_send_json_error` with 403

**Checkpoint**: Notice renders + dismisses correctly. Quickstart §7 passes.

---

## Phase 7: User Story 5 — Plugin-page-only asset enqueue (P2)

**Goal**: `backend.js` + `backend.css` load only on plugin admin pages; version + dependency array sourced from `*.asset.php`; missing build artefacts degrade gracefully.

**Independent Test**: Dashboard page source contains no `backend.js`/`backend.css`; Servers page loads both with version matching `build/js/backend.asset.php`.

### Implementation for User Story 5

- [ ] T050 [US5] Implement `Admin\Main::enqueue_styles()` and `Admin\Main::enqueue_scripts()` in `admin/Main.php` per FR-017/FR-018/FR-019 and research.md R5 — both methods MUST:
  1. Call `get_current_screen()`; bail if null
  2. Match `$screen->id` against the whitelist `[ 'toplevel_page_acrossai_mcp_manager', 'mcp-manager_page_acrossai_mcp_manager_cli_auth_log', 'mcp-manager_page_acrossai_mcp_manager_access_control' ]`; bail if no match
  3. Guard `file_exists()` on each `*.asset.php` before `include`; bail silently if absent (FR-019)
  4. Call `wp_enqueue_script()` / `wp_enqueue_style()` with `version` and `dependencies` sourced from the included asset array — **no literal version string, no literal dependency array**
- [ ] T051 [US5] In `includes/Main.php::define_admin_hooks()`, register `admin_enqueue_scripts` for `Admin\Main::enqueue_styles` and `Admin\Main::enqueue_scripts` (contracts/loader-wiring.md §4)

### Tests for User Story 5

- [ ] T052 [P] [US5] PHPUnit test in `tests/phpunit/admin/MainEnqueueTest.php` — mock `get_current_screen()` to return a non-plugin screen → assert `wp_scripts()->is_queued('acrossai_mcp_manager') === false`; mock a plugin screen → assert the script is queued and its version equals the value from `build/js/backend.asset.php`
- [ ] T053 [P] [US5] PHPUnit test asserting the `file_exists()` guard — temporarily move the asset file aside, mock a plugin screen → assert no fatal, no enqueue

**Checkpoint**: Asset guard works; non-plugin pages don't carry the bundles. Quickstart §6 + SC-004 pass.

---

## Phase 8: User Story 6 — Loader-wiring invariant (P1, consolidation)

**Goal**: Every hook for this phase's classes is wired through the Loader in `includes/Main.php::define_admin_hooks()`; zero `add_action`/`add_filter` calls in any constructor under `admin/`; `define_admin_hooks()` body matches `contracts/loader-wiring.md` canonical form.

**Independent Test**: `grep -rn 'add_action\|add_filter' admin/` returns empty; `grep -n 'loader->add_action\|loader->add_filter' includes/Main.php` shows ≥10 lines covering Menu, Settings (×3 hooks), Admin\Main (×2 hooks), ApplicationPasswords, conditional AccessControl, adapter notice (×2 hooks), action-result notice.

### Implementation for User Story 6

- [ ] T054 [US6] Audit `includes/Main.php::define_admin_hooks()` against `contracts/loader-wiring.md` canonical body — confirm:
  1. Every named-singleton-variable resolution is present (`$admin_main`, `$menu`, `$settings`, `$application_passwords`)
  2. **Every passing-as-instance is via the named variable**, never `ClassName::instance()` inline (Constitution Boot Flow Rule)
  3. Phase 1 TODOs for `Admin\Main`, `Menu`, `Settings`, `ApplicationPasswords` are **deleted**; Phase 1 TODOs for `REST\CliController`, `Includes\MCP\Controller`, `Includes\OAuth\ClaudeConnectors` are **preserved**
  4. The Access Control wiring block is wrapped in `if ( class_exists('\WPBoilerplate\AccessControl\AccessControlManager') )` (D8, A6/B1 namespace-safe)
- [ ] T055 [US6] Verify A6 compliance — every sub-namespace class reference in `includes/Main.php` MUST use either a `use` statement at file top OR a leading-`\` FQN; **bare relative names are forbidden** (B1 silent-fail). If the current style is leading-`\` FQN and the file has ≥3 references, refactor to `use` imports for readability (memory A6 preference, non-blocking)
- [ ] T056 [US6] Run `grep -rn 'add_action\|add_filter' admin/` — expected **empty** (FR-020, quickstart §9.1). If any leak, move the call into `includes/Main.php` Loader registration
- [ ] T057 [US6] Run `grep -nE 'loader->(add_action|add_filter)' includes/Main.php | wc -l` — expected **≥ 10** lines (FR-021, contracts/loader-wiring.md). If fewer, audit the contract to find the missing wirings

### Tests for User Story 6

- [ ] T058 [US6] PHPUnit integration test in `tests/phpunit/includes/DefineAdminHooksTest.php` — instantiate `Includes\Main`, trigger `define_admin_hooks()`, then assert (via `has_action()` / `has_filter()`) that all of the following are registered: `admin_menu` (Menu + Settings render callback), `plugin_action_links_<basename>`, `admin_init` priority 5 (Settings::handle_actions), `admin_init` (Settings::register_settings), `admin_notices` (×2 — adapter notice + action-result notice), `wp_ajax_acrossai_mcp_dismiss_adapter_notice`, `admin_enqueue_scripts` (×2)

**Checkpoint**: Loader is the exhaustive hook-registration point; constitution A1/A2 honoured.

---

## Phase 9: Polish & Cross-Cutting (DoD gates + security follow-ups)

**Purpose**: Final gate checks before merge. Most tasks are parallelizable static-analysis or doc updates.

### Required verification gates

- [ ] T059 [P] Run `grep -rn 'MCPServerTable::\|CliAuthLogTable::' admin/` — expected **empty** (FR-022 + quickstart §9.2). If any match, replace per research.md R1 BerlinDB map
- [ ] T060 [P] **TASK-SEC-002 (security-review-plan.md SEC-002)** — Run `grep -rn 'admin_url' admin/ | grep -v 'esc_url\|esc_attr'` — expected **empty** (B6/S5/F4). If any `admin_url(...)` is not wrapped, wrap it
- [ ] T061 [P] Run `vendor/bin/phpcs admin/ includes/Main.php` — expected **zero errors, zero warnings** (DoD gate); Phase 1 baseline exclusions in `phpcs.xml.dist` remain authoritative
- [ ] T062 [P] Run `vendor/bin/phpstan analyse admin/ includes/Main.php --level=8` — expected **zero errors** (DoD gate)
- [ ] T063 [P] Run `vendor/bin/phpunit tests/phpunit/admin/ tests/phpunit/includes/` — expected **all green** (DoD gate)
- [ ] T064 [P] Run `npm run build` to refresh `build/js/backend.asset.php` + `build/css/backend.asset.php` (so US5 verification sees real production version + deps)
- [ ] T065 [P] Run `npm run validate-packages` — expected **pass** (DoD gate)

### Security follow-up notes (advisory — record only)

- [ ] T066 [P] **TASK-SEC-001 (SEC-001)** — Add a "Follow-up: at-rest encryption for outbound client secrets" note to `specs/002-admin-ui/data-model.md` Entity E1 under the `claude_oauth_client_secret` row; reference Phase 6 as the candidate phase to implement and Constitution §III as the rule needing extension
- [ ] T067 [P] **TASK-SEC-003 (SEC-003)** — Add a "Follow-up: tighten slug sanitiser to `sanitize_key()`" note to `specs/002-admin-ui/data-model.md` Entity E1 under the `slug` row; reference a future normalisation phase

### Final manual verification

- [ ] T068 Execute the full quickstart.md walk (§1–§8) end-to-end on a clean WP 6.9 / PHP 8.0 install with `WP_DEBUG=true` and `WP_DEBUG_LOG=true` — confirm zero PHP notices/warnings in the debug log
- [ ] T069 Mark spec.md §Success Criteria → Definition of Done Gates checkboxes complete; mark plan.md Status as "Ready for review" — Phase 2 ships

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies; can start immediately
- **Foundational (Phase 2)**: Depends on Setup; **T004 is a hard P0 gate** — no story may begin if it fails
- **US1 (Phase 3)**: Depends on Foundational (T010 ready)
- **US2 (Phase 4)**: Depends on Foundational (T005, T008) — does NOT depend on US1 (can run in parallel)
- **US3 (Phase 5)**: Depends on Foundational (T005, T006, T007, T009) — does NOT depend on US1 or US2 (can run in parallel)
- **US4 (Phase 6)**: Depends on Foundational (T005) — does NOT depend on US1/US2/US3 (can run in parallel)
- **US5 (Phase 7)**: Depends on Foundational (needs `Admin\Main` from existing repo) — independent
- **US6 (Phase 8)**: Depends on **all** of US1–US5 having registered their respective hooks (this is the consolidation phase)
- **Polish (Phase 9)**: Depends on US6 completion

### User Story Dependencies (intra-phase)

- **US1**: T011 → T012 → T013 → tests (T014, T015 parallel)
- **US2**: T016 ⫽ T017 (different files, parallel) → T018 → T019 → T020 → T021 → tests (T022–T024 parallel)
- **US3**: T025 ⫽ T026 ⫽ T027 (3 parallel files) → T028 → T029 → T030 → T031 → T032 → T033 → T034 → T035 → T036 → T037 → T038 → T039 → tests (T040–T043 parallel)
- **US4**: T044 → T045 → T046 → T047 → tests (T048, T049 parallel)
- **US5**: T050 → T051 → tests (T052, T053 parallel)
- **US6**: T054 → T055 → T056 → T057 → T058
- **Polish**: T059–T067 fully parallel ([P]); T068 → T069 sequential at the end

### Parallel Opportunities

- All Setup [P] tasks (T002, T003) run in parallel
- All Foundational skeleton tasks T005–T009 [P] run in parallel after T004 passes
- Once Foundational is done, US1–US5 can run in parallel by separate developers
- Within US3 the three new ports (T025, T026, T027) run in parallel
- All Polish gates T059–T067 [P] run in parallel
- All test tasks within a phase marked [P] run in parallel

---

## Parallel Example: User Story 3 kick-off

```bash
# Once T024 (US2 final test) is done and T004 (P0 gate) passed,
# three developers / agents can start US3 implementation simultaneously:
Task: "Port ApplicationPasswords class to admin/Partials/ApplicationPasswords.php"   # T025
Task: "Port SettingsRenderer to admin/Partials/SettingsRenderer.php"                # T026
Task: "Port CliAuthLogListTable to admin/Partials/CliAuthLogListTable.php"          # T027
# All three touch separate files → no conflicts.
```

## Parallel Example: Polish phase

```bash
# Eight static checks run concurrently after US6 closes:
Task: "grep MCPServerTable:: in admin/"          # T059
Task: "grep admin_url without esc in admin/"    # T060 (SEC-002)
Task: "phpcs admin/ includes/Main.php"          # T061
Task: "phpstan level 8"                          # T062
Task: "phpunit tests/phpunit/{admin,includes}/"  # T063
Task: "npm run build"                            # T064
Task: "npm run validate-packages"                # T065
Task: "data-model.md follow-up notes T066+T067"  # T066, T067
```

---

## Implementation Strategy

### MVP First (US1 + US2 + US6)

1. Phase 1 Setup (T001–T003)
2. Phase 2 Foundational — **T004 is the hard gate** (T005–T010)
3. Phase 3 US1 — Menu structure renders
4. Phase 4 US2 — Server list + create + actions
5. Phase 8 US6 (partial) — wire Menu + Settings hooks
6. **STOP and VALIDATE**: Quickstart §1, §2, §3, §5 — site admin can find the menu, see servers, toggle/delete/create. Already a shippable increment.

### Incremental Delivery

7. Phase 5 US3 — Edit page tabs → ship
8. Phase 6 US4 — Adapter-missing notice → ship
9. Phase 7 US5 — Asset guard → ship
10. Phase 8 US6 (final) — full Loader audit; T054–T058
11. Phase 9 Polish — DoD gates → merge

### Parallel Team Strategy

With three developers after T004 passes:
- **Dev A**: US1 (small, fast) → then US4 (small)
- **Dev B**: US2 (medium, list + create)
- **Dev C**: US3 (large, 2615-line port + 3 supporting files + tabs)
- All three converge on T054–T058 for US6 consolidation
- One dev runs Polish in parallel as gates become unblocked

---

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks
- [Story] label maps task to user story for traceability
- Each user story is independently completable and testable
- Verify tests pass after implementation (this is a regression-style port, not TDD)
- Commit after each task or logical group of [P] tasks
- Stop at any **Checkpoint** to validate the story independently
- Avoid: cross-story file conflicts; touching files outside the story's scope; introducing abstractions beyond the 1:1 port (Q1 clarification)
- **F4 reminder** (SEC-002): every `admin_url(...)` MUST be `esc_url()`-wrapped — the T060 grep gate is the canonical enforcement; reviewers should also flag manually
- **F5 P0 dependency** (T004 hard gate): if Query classes do not yet exist, escalate to whichever phase owns them before proceeding

---

## Task Count Summary

| Phase | Task IDs | Count |
|---|---|---|
| 1 — Setup | T001–T003 | 3 |
| 2 — Foundational | T004–T010 | 7 |
| 3 — US1 Menu | T011–T015 | 5 |
| 4 — US2 List + Create | T016–T024 | 9 |
| 5 — US3 Edit Tabs | T025–T043 | 19 |
| 6 — US4 Notice | T044–T049 | 6 |
| 7 — US5 Assets | T050–T053 | 4 |
| 8 — US6 Loader | T054–T058 | 5 |
| 9 — Polish | T059–T069 | 11 |
| **Total** | | **69** |

- Implementation tasks: 47
- Test tasks: 12 (T014, T015, T022, T023, T024, T040, T041, T042, T043, T048, T049, T052, T053, T058 — 14)
- Gate / verification / doc tasks: ~8

(Exact count: 69 tasks total, ~47 implementation, 14 tests, 8 gates.)
