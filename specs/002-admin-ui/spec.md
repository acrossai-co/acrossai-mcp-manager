# Feature Specification: Admin UI — Settings, List Tables, and Asset Enqueue

**Feature Number**: 002
**Feature Branch**: `002-admin-ui`
**Created**: 2026-06-17
**Status**: Draft
**Spec**: `specs/002-admin-ui/spec.md`
**Input**: User description: "Feature: Admin UI — Settings, List Tables, and Asset Enqueue"

---

## Clarifications

### Session 2026-06-17

- Q: How should the 2615-line `src/Admin/Settings.php` be partitioned when migrated into `admin/Partials/`? → A: 1:1 port — keep it as one `Settings.php` file; apply only the four changes named in the user input (namespace, no constructor hooks, `MCPServerTable::` → `MCPServer\Query`, sanitiser audit). No structural split in this phase.
- Q: Is the "Create New Server" form in scope for Phase 2? → A: In scope, ported 1:1 from the source. Explicit FR-007a added and an acceptance scenario added to US2. The handler keeps nonce action `acrossai_mcp_create_server`, the slug-collision rejection path, `manage_options` enforcement, and now writes via `MCPServer\Query::add_item()` instead of `MCPServerTable::create_server()`.
- Q: What is the lifetime of the adapter-missing notice dismissal? → A: Sticky per-user. Stored as user meta key `acrossai_mcp_dismissed_adapter_notice = 1`. Once dismissed it never re-appears for that user — regardless of plugin version. The notice is suppressed for everyone when `class_exists('\WP\MCP\Plugin')` returns true.
- Q: T004 P0 gate failed — `includes/Database/MCPServer/Query.php` and `CliAuthLog/Query.php` don't exist and the source repo only has hand-rolled static `MCPServerTable`/`CliAuthLogTable`. How should the Query layer be built? → A: **Hand-rolled Query classes, no composer dep.** Build `includes/Database/{MCPServer,CliAuthLog}/{Schema,Table,Row,Query}.php` as plain PHP classes with the four-method instance interface (`query`/`add_item`/`update_item`/`delete_item`) plus the static `Query::maybe_create_table()` that Phase 1 Activator calls. All DB I/O uses `$wpdb->prepare()` internally. Spec/plan reference these as "BerlinDB Query classes" — the contract is the **interface**, not the BerlinDB library. Column names match the source schema verbatim (`server_name`, `server_slug`, `is_enabled`, `claude_connector_*`) — `data-model.md`'s speculative renames are corrected to match.

---

## User Scenarios & Testing

### User Story 1 — Site Admin Discovers the MCP Manager Menu (P1)

A WordPress administrator logs into wp-admin after Phase 2 ships. A top-level
"MCP Manager" menu appears in the sidebar. Clicking it reveals submenu entries
for "Servers" (the default page) and "CLI Auth Log". When the
`wpb-access-control` vendor package is also installed, a third submenu "Access
Control" appears. On the Plugins screen, the plugin row shows a "Settings"
action link that deep-links to `?page=acrossai_mcp_manager`.

**Why this priority**: This is the user's only entry point to every admin
feature delivered in this phase. Without the menu, no other story is reachable.

**Independent Test**: Activate the plugin on a clean WP 6.9 install, log in as
admin, confirm the menu structure matches the expected slugs and that the
plugin-row "Settings" link navigates to the servers page.

**Acceptance Scenarios**:

1. **Given** the plugin is active and the current user has `manage_options`,
   **When** wp-admin loads, **Then** a top-level menu titled "MCP Manager"
   with slug `acrossai_mcp_manager` is rendered with submenu "Servers" mapped
   to the same slug and submenu "CLI Auth Log".
2. **Given** the `wpb-access-control` package is present, **When** the menu
   is built, **Then** an "Access Control" submenu is appended.
3. **Given** `wpb-access-control` is absent, **When** the menu is built,
   **Then** no "Access Control" submenu is rendered and no fatal/notice occurs.
4. **Given** the admin opens the Plugins screen, **When** the plugin row is
   rendered, **Then** a "Settings" action link appears that points to
   `admin.php?page=acrossai_mcp_manager`.
5. **Given** the current user lacks `manage_options`, **When** wp-admin loads,
   **Then** no "MCP Manager" menu is registered for that user.

---

### User Story 2 — Site Admin Manages MCP Servers from a List Table (P1)

A site admin opens `?page=acrossai_mcp_manager` and sees a WordPress-native
`WP_List_Table` of every MCP server row in the database. They can enable,
disable, or delete individual rows from the row-action menu, or bulk-enable,
bulk-disable, or bulk-delete multiple rows via the checkboxes and bulk-action
dropdown. Every state-changing action is gated by a valid nonce and the
`manage_options` capability.

**Why this priority**: Server management is the core admin job of this plugin.
Without it the menu is decorative.

**Independent Test**: With three server rows seeded in the DB, open the list
page, toggle one row's status via the row action, confirm the status column
updates and an admin notice appears; then select two rows, run the "Delete"
bulk action, confirm both rows are removed and a success notice appears.

**Acceptance Scenarios**:

1. **Given** there are N MCP server rows in the DB, **When** the list page
   renders, **Then** the table shows N rows with columns: Name, Slug, Status
   (enabled/disabled), Registered From, Route Namespace, Route, Version, and
   Actions.
2. **Given** a row's status is "enabled", **When** the admin clicks the row
   action "Toggle Status" with a valid nonce, **Then** the row status flips to
   "disabled" in the DB and a success admin notice is shown on reload.
3. **Given** the admin selects two rows and the "Delete" bulk action, **When**
   they submit with a valid nonce, **Then** both rows are removed from the DB
   and a success admin notice reports the deleted count.
4. **Given** a state-changing request arrives with a missing or invalid nonce,
   **When** the handler runs, **Then** the request is rejected via
   `wp_die()` / `check_admin_referer()` before any DB write occurs.
5. **Given** the requesting user lacks `manage_options`, **When** a state-
   changing request is submitted, **Then** the handler rejects the request and
   no DB write occurs.
6. **Given** the row action "Edit" is clicked, **When** the link is followed,
   **Then** the browser navigates to
   `?page=acrossai_mcp_manager&action=edit&server={ID}`.
7. **Given** the admin clicks "Add New" on the list page, **When** they submit
   the form with a valid nonce (`acrossai_mcp_create_server`), `manage_options`
   capability, a unique slug, and required fields populated, **Then** a new
   row is inserted via `MCPServer\Query::add_item()` and a success admin
   notice reports the new server name.
8. **Given** the admin submits the create form with a slug that already
   exists, **When** the handler runs, **Then** no row is inserted and an
   error admin notice "Slug already in use" is shown.

---

### User Story 3 — Site Admin Edits a Server Across Four Tabs (P1)

From the edit URL the admin sees a tabbed interface — General, Tokens, Access
Control, Claude Connector — and can save any tab's form. Each save passes a
nonce check, a `manage_options` capability check, sanitises input with the
most-specific WordPress sanitiser, and persists through the BerlinDB
`MCPServer\Query` class. A confirmation admin notice appears after each save.

**Why this priority**: Editing is the second core admin job. Without it the
admin can only enable/disable existing rows but cannot configure them.

**Independent Test**: Open the edit page for an existing server, change the
Name and Route Namespace on the General tab, save; reload and confirm the
new values persisted. Repeat for each of the other three tabs (Tokens
generates an Application Password row; Access Control delegates to the vendor
manager; Claude Connector stores OAuth client credentials).

**Acceptance Scenarios**:

1. **Given** the edit page is loaded for an existing server, **When** rendered,
   **Then** four tabs are present: General, Tokens, Access Control, Claude
   Connector — defaulting to General.
2. **Given** the General tab is active, **When** the admin edits Name,
   Description, Route Namespace, Route, or Version and saves with a valid
   nonce, **Then** all fields are sanitised at the boundary and persisted via
   `MCPServer\Query::update_item()` (or equivalent BerlinDB method) — **never**
   via `MCPServerTable::` static calls.
3. **Given** the Tokens tab is active, **When** the admin creates or revokes
   an Application Password for the server, **Then** the operation is delegated
   to the migrated `ApplicationPasswords` partial class.
4. **Given** the Access Control tab is active **and** `wpb-access-control` is
   installed, **When** the tab renders, **Then** rendering is delegated to
   `\WPBoilerplate\AccessControl\AccessControlManager`.
5. **Given** `wpb-access-control` is absent, **When** the Access Control tab
   is requested, **Then** the tab renders a graceful informational notice (no
   fatal) explaining the feature requires the package.
6. **Given** the Claude Connector tab is active, **When** the admin enters
   OAuth Client ID, Client Secret, and Redirect URI and saves with a valid
   nonce, **Then** the three values are sanitised (text / text / URL) and
   persisted to the server row.
7. **Given** any tab save submission, **When** the nonce is missing or invalid
   or the user lacks `manage_options`, **Then** the request is rejected and no
   DB write occurs.

---

### User Story 4 — Site Admin Sees an Adapter-Missing Notice (P2)

A site admin who has not installed the `wordpress/mcp-adapter` Composer
package sees a dismissible admin notice on every wp-admin screen that
explains the package is required for MCP servers to respond. Once dismissed
for the user, the notice does not reappear on subsequent page loads.

**Why this priority**: Surfaces a configuration prerequisite that otherwise
manifests as silently broken endpoints. Important but not blocking — the menu
and DB still work without the adapter.

**Independent Test**: Uninstall / remove the adapter package, load wp-admin,
confirm the notice renders with a dismiss button; click dismiss, reload,
confirm the notice does not reappear for that user. Reinstall the package,
confirm the notice is gone for all users.

**Acceptance Scenarios**:

1. **Given** `\WP\MCP\Plugin` (the MCP adapter package class) does not exist,
   **When** any wp-admin page renders, **Then** a dismissible notice is shown
   via the `admin_notices` hook.
2. **Given** the notice is dismissible, **When** the user dismisses it,
   **Then** the dismissal is persisted per-user and the notice does not
   render again for that user.
3. **Given** `\WP\MCP\Plugin` exists, **When** any wp-admin page renders,
   **Then** the missing-adapter notice does not render.

---

### User Story 5 — Admin Assets Load Only on Plugin Pages (P2)

A site admin browsing other plugins' admin pages does not pay the cost of
loading this plugin's backend JS and CSS bundles. The bundles load only when
`get_current_screen()` reports a plugin page, and the version + dependency
array are read from `build/js/backend.asset.php` and
`build/css/backend.asset.php` — never hardcoded.

**Why this priority**: Performance & correctness concern; enforces the
constitution rule against hardcoded asset version/deps.

**Independent Test**: Open a non-plugin admin page (e.g. Dashboard) and
inspect page source — neither `backend.js` nor `backend.css` is present. Open
`?page=acrossai_mcp_manager` and inspect — both load with the version and
dependencies declared by the asset PHP file.

**Acceptance Scenarios**:

1. **Given** the current admin screen is NOT a plugin page, **When**
   `admin_enqueue_scripts` fires, **Then** neither `backend.js` nor
   `backend.css` is enqueued.
2. **Given** the current admin screen IS `?page=acrossai_mcp_manager` (any
   action), **When** `admin_enqueue_scripts` fires, **Then** both bundles are
   enqueued.
3. **Given** the JS bundle is enqueued, **When** the call is inspected, **Then**
   its version + dependency array are sourced from
   `include build/js/backend.asset.php` — no literal version string or
   literal `array(...)` of deps appears in the enqueue call.
4. **Given** the CSS bundle is enqueued, **When** the call is inspected,
   **Then** its version + dependency array are sourced from
   `include build/css/backend.asset.php`.

---

### User Story 6 — Hooks Are Wired Through the Loader, Not Constructors (P1)

A developer auditing any class in `admin/Partials/` confirms that no
`add_action()` or `add_filter()` call appears in any constructor. Every hook
that connects these classes to WordPress is registered externally through the
Loader instance inside `Main::define_admin_hooks()` in `includes/Main.php`.

**Why this priority**: Constitutional invariant. Phase 1 established the
Loader; Phase 2 must not regress it. Without this story, the boot-flow
contract from Phase 1 silently breaks.

**Independent Test**:
`grep -rn "add_action\|add_filter" admin/` outside `Main.php` returns zero
results. `grep -n "loader->add_action\|loader->add_filter" includes/Main.php`
returns every hook that the admin partials require.

**Acceptance Scenarios**:

1. **Given** any class file under `admin/Partials/`, **When** its constructor
   is read, **Then** no `add_action(` or `add_filter(` call appears.
2. **Given** `Main::define_admin_hooks()` is invoked, **When** the Loader
   runs, **Then** the following hooks are registered for the migrated
   partials: `admin_menu` (Menu), `plugin_action_links_<basename>` (Menu),
   `admin_init` priority 5 (Settings::handle_actions), `admin_init`
   (Settings::register_settings), `admin_notices` (adapter-missing notice),
   `admin_enqueue_scripts` (Main / asset enqueue), plus
   `wp_create_application_password` and related hooks for the
   `ApplicationPasswords` partial.
3. **Given** the access control library is absent, **When**
   `define_admin_hooks()` runs, **Then** the access-control submenu and tab
   hooks are skipped via `class_exists()` guard — no fatal.

---

### Edge Cases

- **`wpb-access-control` package absent**: Access Control submenu is not
  registered; Access Control tab on the edit screen renders an informational
  notice; no fatal.
- **`\WP\MCP\Plugin` adapter absent**: Dismissible admin notice rendered on
  every wp-admin screen until dismissed per-user.
- **MCPServer DB row missing for the requested `server` ID on the edit
  screen**: Edit screen redirects to the list page with an error admin notice
  ("Server not found."), no PHP notice raised.
- **User lacks `manage_options` capability**: All state-changing handlers
  reject via `wp_die()`; menu items are not rendered to that user.
- **Nonce missing or invalid on any state-changing request** (toggle, delete,
  edit save): `check_admin_referer()` halts the request before any DB write.
- **Slug collision when creating a new server**: The handler rejects with an
  error admin notice; no row is inserted.
- **`build/js/backend.asset.php` or `build/css/backend.asset.php` missing**
  (fresh checkout, no build): `file_exists()` guard before `include` —
  asset enqueue is silently skipped; no fatal. (See Assumptions for the
  build-artefact promise.)
- **BerlinDB Query class absent**: This phase has a hard prerequisite that
  `Includes\Database\MCPServer\Query` and
  `Includes\Database\CliAuthLog\Query` exist (created in earlier DB phases).
  If absent at runtime, list pages render an error admin notice and an empty
  table — no fatal.

---

## Requirements

### Functional Requirements

#### Menu structure

- **FR-001**: `Admin\Partials\Menu::register_menu()` MUST register exactly one
  top-level menu via `add_menu_page()` with:
  - Page title and menu title `"MCP Manager"` (translatable)
  - Capability `manage_options`
  - Slug `acrossai_mcp_manager`
  - Callback that renders the Servers list page

- **FR-002**: After registering the parent menu, the same method MUST register
  the following submenus via `add_submenu_page()`:
  - Submenu **Servers** — parent + child slug both `acrossai_mcp_manager`
    (so the parent label rewrites to "Servers")
  - Submenu **CLI Auth Log** — slug `acrossai_mcp_manager_cli_auth_log`
  - Submenu **Access Control** — slug `acrossai_mcp_manager_access_control`
    — registered **only if** `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')`

- **FR-003**: `Admin\Partials\Menu::plugin_action_links()` MUST prepend a
  "Settings" link pointing to `admin.php?page=acrossai_mcp_manager` to the
  plugin action links array. The link MUST be filtered to the plugin's own
  row only — verified by hooking
  `plugin_action_links_<plugin_basename>` (not the global filter).

#### Server list page (`?page=acrossai_mcp_manager` with no `action`)

- **FR-004**: `Admin\Partials\MCPServerListTable` MUST extend
  `\WP_List_Table` (the constitution exception for the parent menu applies)
  and expose the columns: Name, Slug, Status, Registered From, Route
  Namespace, Route, Version, Actions.

- **FR-005**: The list table MUST source rows by calling the BerlinDB
  `Includes\Database\MCPServer\Query` class — for example
  `( new MCPServer\Query() )->query( [...] )`. Direct calls to
  `MCPServerTable::get_all()`, `MCPServerTable::get_by_id()`,
  `MCPServerTable::update_server()`, or any other static method on
  `MCPServerTable` MUST NOT appear anywhere in `admin/`.

- **FR-006**: The list table MUST expose bulk actions: `enable`, `disable`,
  `delete`, and row actions: `Edit`, `Toggle Status`, `Delete`.

- **FR-007**: Every state-changing bulk-action or row-action handler MUST:
  - Verify a nonce via `check_admin_referer()` (or
    `wp_verify_nonce()` for AJAX) before any DB write
  - Verify `current_user_can('manage_options')` before any DB write
  - Sanitise IDs with `absint()` at the request boundary
  - Persist via `MCPServer\Query` (per FR-005)
  - Redirect after the write with a success/error query var that triggers
    the admin notice in FR-016

- **FR-007a**: The list page MUST expose a "Create New Server" form (ported
  1:1 from the source repo). The handler MUST:
  - Verify nonce action `acrossai_mcp_create_server` via
    `check_admin_referer()`
  - Verify `current_user_can('manage_options')`
  - Sanitise Name → `sanitize_text_field()`, Description →
    `sanitize_textarea_field()`, Slug + Route Namespace + Route + Version
    → `sanitize_text_field()`
  - Reject with an error admin notice (`notice=slug_exists`) when the slug
    is already used by another row (slug-uniqueness check via
    `MCPServer\Query::query([ 'slug' => $slug, 'number' => 1 ])` or
    equivalent BerlinDB filter — **not** `MCPServerTable::slug_exists()`)
  - Persist via `Includes\Database\MCPServer\Query::add_item()` — never
    `MCPServerTable::create_server()`
  - Redirect to the edit page for the newly created row on success, with a
    `notice=server_created` query var that triggers the success notice

#### Server edit page (`?page=acrossai_mcp_manager&action=edit&server={ID}`)

- **FR-008**: The edit page MUST render a tabbed interface with exactly four
  tabs in this order: **General**, **Tokens**, **Access Control**, **Claude
  Connector**. The active tab is selected via a `tab` query var with default
  `general`.

- **FR-009**: The **General** tab MUST allow editing the following fields and
  persist them on save with the listed sanitiser:
  - Name → `sanitize_text_field()`
  - Description → `sanitize_textarea_field()`
  - Route Namespace → `sanitize_text_field()` (no slashes)
  - Route → `sanitize_text_field()` (leading slash preserved)
  - Version → `sanitize_text_field()`

- **FR-010**: The **Tokens** tab MUST delegate to the migrated
  `Admin\Partials\ApplicationPasswords` partial class, preserving the
  per-server Application Passwords workflow from the source repo.

- **FR-011**: The **Access Control** tab MUST:
  - When `\WPBoilerplate\AccessControl\AccessControlManager` exists,
    delegate rendering and persistence to that vendor class
  - When the class is absent, render an informational `notice notice-info`
    box explaining the package is required and provide no editable inputs

- **FR-012**: The **Claude Connector** tab MUST allow editing:
  - OAuth Client ID → `sanitize_text_field()`
  - OAuth Client Secret → `sanitize_text_field()` (treated as opaque secret;
    masked on render after first save)
  - Redirect URI → `esc_url_raw()` at sanitisation, `esc_url()` at output

- **FR-013**: Every save submission across all four tabs MUST:
  - Call `check_admin_referer()` against a tab-specific nonce action name
    (e.g. `acrossai_mcp_update_{server_id}` for General,
    `acrossai_mcp_claude_connector_{server_id}` for Claude Connector)
  - Verify `current_user_can('manage_options')`
  - Sanitise each field with the most-specific WordPress sanitiser (FR-009 /
    FR-012)
  - Persist via `Includes\Database\MCPServer\Query` — never
    `MCPServerTable::` static calls
  - Redirect back to the edit page with a `notice` query var that triggers
    the success / error admin notice (FR-016)

- **FR-014**: When the requested `server` ID does not exist in the DB, the
  edit page MUST redirect to the list page with a `notice=server_not_found`
  query var — no PHP notice, no fatal.

#### Admin notices

- **FR-015**: An `admin_notices` callback in `Admin\Partials\Settings` (or a
  dedicated `Admin\Partials\Notices` class — implementer's choice) MUST emit
  a **dismissible** `notice notice-warning is-dismissible` notice on every
  wp-admin page when `class_exists('\WP\MCP\Plugin')` returns `false`. The
  dismissal MUST be persisted per-user as user meta key
  `acrossai_mcp_dismissed_adapter_notice = 1` (sticky — never re-appears for
  the dismissing user, regardless of plugin version). Persistence MUST use
  the standard WordPress dismissible-notice JS contract:
  - The notice renders with `is-dismissible` class so core JS shows the X
    button
  - On click, a small admin-ajax (or REST) endpoint registered by this
    feature receives the dismissal, verifies the requesting user's nonce
    and `manage_options` capability, then writes the user-meta key
  - The render guard skips emit when the user-meta key is truthy

- **FR-016**: After every server save, toggle, or bulk action, a
  success-or-error admin notice MUST be rendered on the next page load.
  Notices are produced by inspecting a `notice` query var appended by the
  redirect in FR-007 / FR-013 and mapping it to a translated message via a
  centralised lookup. Notices MUST be escaped at output with `esc_html()`.

#### Asset enqueue

- **FR-017**: `Admin\Main::enqueue_assets()` (or equivalent) MUST be wired
  through the Loader on `admin_enqueue_scripts` and MUST guard its body with
  `get_current_screen()`. The guard MUST return early if the current screen's
  `id`, `base`, or `page` query var does not match a plugin admin page slug.
  Plugin admin page slugs to whitelist: `toplevel_page_acrossai_mcp_manager`
  and any `mcp-manager_page_acrossai_mcp_manager_*` submenu page IDs.

- **FR-018**: When the guard in FR-017 passes, the method MUST enqueue
  `backend.js` and `backend.css` with version + dependency arrays sourced
  exclusively from `include ACROSSAI_MCP_MANAGER_PLUGIN_PATH .
  'build/js/backend.asset.php'` and `... 'build/css/backend.asset.php'`. The
  enqueue call MUST NOT contain a literal version string or a literal
  dependency array.

- **FR-019**: A `file_exists()` guard MUST precede each `include` of the
  `*.asset.php` files. If the file is missing (fresh checkout, no build),
  enqueue MUST be silently skipped — no fatal, no notice.

#### Hook registration (Loader contract)

- **FR-020**: Every class in `admin/Partials/` MUST have a constructor that
  contains zero `add_action()` or `add_filter()` calls. Classes follow the
  Phase 1 pattern: protected `$_instance`, private constructor, public static
  `instance()`, and pure side-effect-free property initialisation in the
  constructor.

- **FR-021**: `Includes\Main::define_admin_hooks()` MUST wire the following
  hooks via `$this->loader->add_action()` / `add_filter()`, replacing the
  Phase 1 TODO stubs for these classes:
  - `Menu::register_menu` → `admin_menu`
  - `Menu::plugin_action_links` → `plugin_action_links_<plugin_basename>`
  - `Settings::handle_actions` → `admin_init` priority 5
  - `Settings::register_settings` → `admin_init`
  - `Settings::admin_notices` (or `Notices::render`) → `admin_notices`
  - `Main::enqueue_assets` → `admin_enqueue_scripts`
  - `ApplicationPasswords` hooks → their canonical WordPress hook names
    (carried over verbatim from the source class)
  - Access Control hooks (menu page, tab render) — **guarded** by
    `class_exists('\WPBoilerplate\AccessControl\AccessControlManager')`

#### DB access

- **FR-022**: Anywhere in `admin/` that previously called
  `MCPServerTable::*` (in the source repo's `src/Admin/Settings.php`) MUST
  instead call `Includes\Database\MCPServer\Query` (for server rows) or
  `Includes\Database\CliAuthLog\Query` (for CLI auth log rows). The
  migration MUST NOT introduce any new static helper to wrap these classes.

- **FR-023**: This phase has a **hard prerequisite** that
  `Includes\Database\MCPServer\Query` and `Includes\Database\CliAuthLog\Query`
  exist under `includes/Database/` before any Phase 2 task starts. Implementation
  MUST verify (and the plan MUST state) that these classes have been created
  before the Phase 2 work begins.

#### Namespace

- **FR-024**: Every class file under `admin/Partials/` MUST declare namespace
  `AcrossAI_MCP_Manager\Admin\Partials`. Every class file under `admin/`
  (the parent dir) MUST declare namespace `AcrossAI_MCP_Manager\Admin`.

### WordPress Requirements

| Field | Value |
|---|---|
| PHP version | 8.0+ (plugin minimum 7.4; constitution target 8.0) |
| WordPress version | 6.9+ |
| Multisite | Single-site only — not in scope for this increment |
| Required Composer packages | `automattic/jetpack-autoloader ^5.0`, BerlinDB (carried in by source repo) |
| Optional integrations | `wpboilerplate/wpb-access-control ^1.0` — Access Control submenu + tab degrade gracefully if absent; `wordpress/mcp-adapter` — surfaced via dismissible notice when absent |

### Module Placement

| File | Namespace | Action |
|---|---|---|
| `admin/Main.php` | `AcrossAI_MCP_Manager\Admin` | Extend (already exists) — implement `enqueue_assets()` |
| `admin/Partials/Menu.php` | `AcrossAI_MCP_Manager\Admin\Partials` | Extend (skeleton already exists) — implement `register_menu()`, `plugin_action_links()` |
| `admin/Partials/Settings.php` | `AcrossAI_MCP_Manager\Admin\Partials` | New — **1:1 port** from `src/Admin/Settings.php` (~2615 lines, one file). Only four changes applied: (1) namespace, (2) remove `add_action()` / `add_filter()` from constructor, (3) replace every `MCPServerTable::` static call with `Includes\Database\MCPServer\Query`, (4) audit sanitisers to match FR-009 / FR-012. **No structural split in this phase.** |
| `admin/Partials/MCPServerListTable.php` | `AcrossAI_MCP_Manager\Admin\Partials` | New — port from `src/Admin/MCPServerListTable.php`; route DB calls through `MCPServer\Query` |
| `admin/Partials/CliAuthLogListTable.php` | `AcrossAI_MCP_Manager\Admin\Partials` | New — port from `src/Admin/CliAuthLogListTable.php`; route DB calls through `CliAuthLog\Query` |
| `admin/Partials/ApplicationPasswords.php` | `AcrossAI_MCP_Manager\Admin\Partials` | New — port from `src/Admin/ApplicationPasswords.php`; strip constructor hooks |
| `admin/Partials/SettingsRenderer.php` | `AcrossAI_MCP_Manager\Admin\Partials` | New — port from `src/Admin/SettingsRenderer.php` (thin renderer) |
| `includes/Main.php` | `AcrossAI_MCP_Manager\Includes` | Extend — replace TODO stubs in `define_admin_hooks()` with actual Loader wiring per FR-021 |

**Hook Registration Rule**: ALL `add_action` / `add_filter` calls for this
feature MUST be wired only through the Loader inside
`Main::define_admin_hooks()`. Zero hook calls may appear in any class
constructor anywhere under `admin/`.

### Admin UI Requirements

**Pre-approved `WP_List_Table` exception applies** to:
- `admin/Partials/MCPServerListTable.php` — list table for the parent menu
  page (`?page=acrossai_mcp_manager`)
- `admin/Partials/CliAuthLogListTable.php` — list table for the CLI Auth Log
  submenu (this submenu is reached from the parent menu and inherits the
  exception)

No new admin **forms** in this phase fall outside the exception scope. The
edit-screen tabs use traditional WordPress form HTML rather than
`@wordpress/dataviews` `DataForm` — this is acceptable because the screens
are direct ports from the source repo and the constitution exception covers
"the MCP Manager parent menu only". The plan MUST document this scope
interpretation.

No new admin screens (beyond the four-tab edit page already in the source
repo) are created in this phase.

### REST API Contract

This phase adds no REST routes. (REST routes are added in Phase 6 — Claude
Connectors — and the existing CLI controller phase.)

### Database / Storage

This phase reads and writes via the BerlinDB Query classes created in earlier
phases:

| Table suffix | Read path | Write path |
|---|---|---|
| `acrossai_mcp_servers` | `Includes\Database\MCPServer\Query::query()` | `MCPServer\Query::add_item()`, `update_item()`, `delete_item()` |
| `acrossai_mcp_cli_auth_log` | `Includes\Database\CliAuthLog\Query::query()` | read-only — no writes from this phase |

No `$wpdb` direct calls and no `MCPServerTable::` static calls appear in
`admin/`.

### Security Checklist

*(Derived from Constitution §III)*

- [ ] All form/AJAX handlers in `admin/Partials/Settings.php` verify nonce
      via `check_admin_referer()` before any DB write
- [ ] All admin page render callbacks check
      `current_user_can('manage_options')` before output
- [ ] All bulk-action and row-action handlers in
      `MCPServerListTable.php` verify nonce via `check_admin_referer()` and
      capability before any DB write
- [ ] All user input sanitised at the request boundary with the most-specific
      function (per FR-009, FR-012)
- [ ] All output escaped at point of rendering with `esc_html()`,
      `esc_attr()`, `esc_url()`, or `esc_textarea()` as appropriate
- [ ] No raw `$wpdb` queries — all DB access through BerlinDB Query classes
      which use prepared statements internally
- [ ] OAuth Client Secret (Claude Connector tab) treated as opaque secret —
      stored as the user-supplied value; masked on re-render after first save
      (the secret is not an Application Password and is not subject to the
      Phase 1 hashed-token rule — it is the credential the plugin presents to
      Claude, not a credential the plugin receives)
- [ ] Application Passwords flow (Tokens tab) preserves the
      source repo's hashed-storage contract — passwords are shown once on
      creation and never persisted in plaintext

### Key Entities

- **MCP Server (row in `acrossai_mcp_servers`)**: Represents one
  configured MCP endpoint exposed by this site. Holds Name, Slug, Status,
  Registered-From, Route Namespace, Route, Version, OAuth Client ID /
  Secret / Redirect URI, and metadata managed by the Access Control package.
- **CLI Auth Log Entry (row in `acrossai_mcp_cli_auth_log`)**: Read-only
  record of a CLI authentication attempt. Surfaced in the CLI Auth Log
  submenu.
- **Application Password (per server)**: A WordPress-native Application
  Password scoped to one MCP server row; managed via the Tokens tab.

---

## Success Criteria

### Definition of Done Gates

- [ ] PHPCS: zero errors and zero warnings (`vendor/bin/phpcs`); Phase 1
      baseline exclusions remain in `phpcs.xml.dist`
- [ ] PHPStan level 8: zero errors (`vendor/bin/phpstan`)
- [ ] PHPUnit: tests written and passing for each new partial class
- [ ] Security checklist above: every applicable item verified
- [ ] All hooks wired in `Main::define_admin_hooks()` — none in any class
      constructor under `admin/`
- [ ] `WP_List_Table` exception applies only to the parent menu list tables;
      no new admin form falls outside the exception
- [ ] `grep -rn "MCPServerTable::" admin/` returns zero matches
- [ ] `grep -rn "add_action\|add_filter" admin/` returns zero matches outside
      `Main.php` (and zero in `Main.php` constructor)
- [ ] `npm run validate-packages` passes
- [ ] Plugin activates and the admin menu renders with the expected
      submenus on a clean WP 6.9 / PHP 8.0 install

### Measurable Outcomes

- **SC-001**: From a clean activation, a site admin can navigate to the
  Servers list page in two clicks (sidebar → MCP Manager) and toggle the
  status of an existing server in one more click (row action). Total: ≤ 3
  clicks.
- **SC-002**: Every state-changing request without a valid nonce returns the
  WordPress `wp_die()` screen — verified by submitting a forged request with
  the nonce param removed (one test per handler).
- **SC-003**: Every state-changing request from a non-`manage_options` user
  returns the WordPress `wp_die()` screen — verified by impersonating an
  Editor and submitting toggle / delete / save (one test per handler).
- **SC-004**: On a non-plugin admin page (Dashboard), neither `backend.js`
  nor `backend.css` is enqueued — verified by inspecting the
  `wp_scripts` / `wp_styles` registries.
- **SC-005**: When `\WP\MCP\Plugin` is absent, the dismissible adapter notice
  renders on every wp-admin page; once dismissed by a user it does not
  reappear for that user on subsequent loads — verified across two requests.
- **SC-006**: When `wpb-access-control` is absent, the Access Control
  submenu does not appear and the Access Control tab on the edit page
  renders an informational notice — verified by deactivating the package
  and reloading.

---

## Assumptions

- **Connector Audit Log scope**: `src/Admin/ConnectorAuditLogListTable.php`
  is NOT migrated in this phase. It is paired with the Claude Connectors
  OAuth feature owned by Phase 6 and will be migrated alongside that
  controller. This phase ports only the list tables and per-server tabs
  named in the user input: Servers, CLI Auth Log, and the four edit-screen
  tabs (General / Tokens / Access Control / Claude Connector).
- **BerlinDB Query classes already exist**: `Includes\Database\MCPServer\Query`
  and `Includes\Database\CliAuthLog\Query` are a hard prerequisite created
  before Phase 2 begins. Phase 1 stubbed only `class_exists()` guards on
  these — the plan for this phase MUST confirm the classes are in place
  before any task that calls them.
- **Build artefacts**: `build/js/backend.asset.php` and
  `build/css/backend.asset.php` are produced by the existing `npm run build`
  pipeline (carried over from the source repo). The `file_exists()` guard in
  FR-019 covers the case where a developer hasn't run the build yet.
- **Notice dismissal persistence**: The dismissible adapter notice uses
  WordPress's standard dismissible-notice JS contract; the dismissal flag is
  stored as user meta key `acrossai_mcp_dismissed_adapter_notice` (per-user,
  sticky — never reset on plugin upgrade). The dismissal endpoint requires a
  nonce + `manage_options`, so it is not abusable by non-admin users.
- **`WP_List_Table` exception scope interpretation**: The constitution's
  pre-approved exception covers both the parent-menu list page
  (Servers) and the CLI Auth Log submenu list page, since both are reached
  directly from the parent menu and use the same table pattern. The
  edit-screen tabbed form is also covered by the source-repo migration
  carve-out per the Admin UI Requirements section above.
- **Application Passwords contract preserved verbatim**: The source repo's
  hashed-storage and one-time-display contract for Application Passwords is
  preserved exactly. No security regression is introduced by this phase.
- **No new REST routes**: REST routes belong to Phase 6 (Claude Connectors)
  and the prior CLI controller phase — not Phase 2.
- **Multisite**: Single-site only. Network-level menus and per-site
  isolation behaviour are out of scope for this increment.
