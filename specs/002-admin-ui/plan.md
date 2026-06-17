# Implementation Plan: Admin UI — Settings, List Tables, and Asset Enqueue

**Branch**: `002-admin-ui` | **Date**: 2026-06-17 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `specs/002-admin-ui/spec.md`

---

## Summary

Migrate six admin-facing classes from the source repo's `src/Admin/` into the
new repo's `admin/Partials/` namespace `AcrossAI_MCP_Manager\Admin\Partials`,
wire every hook through `Includes\Main::define_admin_hooks()` via the Loader,
and swap every `MCPServerTable::` static call for the BerlinDB
`Includes\Database\MCPServer\Query` instance method.

The port is **1:1 by file** (clarified 2026-06-17 Q1): no structural splitting
of the 2615-line `Settings.php`. Four surgical changes are applied per file:

1. Namespace declaration
2. Remove every `add_action()` / `add_filter()` from the constructor
3. Replace every `MCPServerTable::*()` call with the equivalent BerlinDB
   `MCPServer\Query` instance method (and `CliAuthLogTable::*()` →
   `CliAuthLog\Query`)
4. Tighten sanitisers per FR-009 / FR-012 to the most-specific WordPress
   function

Every class continues to follow the constitution's singleton pattern
(`protected static $_instance` + `public static instance(): self` + private
constructor). `Includes\Main::define_admin_hooks()` resolves each singleton
to a **named local variable** before passing it to the Loader — never inline.

## Technical Context

| Field | Value |
|---|---|
| Language / version | PHP 8.0+ (plugin minimum 7.4; constitution target 8.0); JS via `@wordpress/scripts` |
| Primary dependencies | WordPress 6.9+, BerlinDB (vendor), `automattic/jetpack-autoloader ^5.0`, `@wordpress/scripts` build pipeline |
| Storage | Custom DB tables created by `Includes\Database\MCPServer\Table` and `CliAuthLog\Table` (BerlinDB); user meta for notice dismissal |
| Testing | PHPUnit 9.x with `wp-phpunit`, PHPStan level 8, PHPCS WPCS strict, ESLint, Jest for JS (`src/js/` unchanged this phase) |
| Target platform | WordPress 6.9+ admin (`wp-admin/`); single-site only this phase |
| Project type | WordPress plugin (existing directory layout per constitution Architecture & UI Standards) |
| Performance goals | Admin pages render in ≤500 ms p95 with ≤200 MCP server rows; bulk action over 50 rows completes in ≤2 s |
| Constraints | No `MCPServerTable::` static calls in `admin/` (FR-022); no `add_action`/`add_filter` outside `Loader.php` and `Main.php` (FR-020 / FR-021); no hardcoded asset version/deps (FR-018) |
| Scale / scope | ~3700 lines of source PHP to port (Settings 2615 + ApplicationPasswords 423 + 3 list tables 667 + SettingsRenderer 25); six new files + one extension to `includes/Main.php` |

### Hard prerequisite (P0 dependency)

`Includes\Database\MCPServer\Query` and `Includes\Database\CliAuthLog\Query`
**do not yet exist** in the new repo (`ls includes/Database/` is empty as of
2026-06-17). The source repo also lacks them — only the old static
`MCPServerTable.php` / `CliAuthLogTable.php` exist. Per FR-023, this phase
cannot ship until those Query classes are built by a sibling phase.

**Resolution**: This plan assumes the Query classes are delivered before any
Phase 2 task that calls them. The `/speckit-tasks` phase MUST insert a
verification gate as task T000: `verify includes/Database/MCPServer/Query.php
exists and exposes query() / add_item() / update_item() / delete_item()`.
Implementation MUST NOT begin if T000 fails — escalate as a cross-phase
blocker.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Compliance | Notes |
|---|---|---|
| I. Modular Architecture | ✅ | Six self-contained partials, each owning one screen/concern. Shared helpers stay in `includes/Utilities/` (none needed this phase). |
| II. WordPress Standards Compliance | ✅ | WPCS strict, PHPStan 8, ESLint, no deprecated functions. PHPCS Phase 1 baseline exclusions preserved in `phpcs.xml.dist`. |
| III. Security First (NON-NEGOTIABLE) | ✅ | Sanitisers per FR-009/FR-012, nonce + capability on every state-changing handler, BerlinDB Query uses `$wpdb->prepare()` internally, OAuth Client Secret masked on render, Application Passwords stay hashed-on-store. |
| IV. User-Centric Design (NON-NEGOTIABLE) | ✅ (pre-approved exception) | The constitution explicitly pre-approves `WP_List_Table` + tabbed settings form for the MCP Manager parent menu. No new screens outside this carve-out are added. |
| V. Extensibility Without Core Modification | ✅ | All hooks via Loader. `wpb-access-control` and `\WP\MCP\Plugin` are optional integrations — every call sites is `class_exists()`-guarded. |
| VI. Reusability & DRY Principle | ✅ | No new utility duplicates. `npm run validate-packages` runs in DoD. Source code that is **already duplicated** inside `src/Admin/Settings.php` (multiple inline render helpers) is preserved as-is per the 1:1 port decision — any deduplication is a separate follow-up. |
| VII. Definition of Done | ✅ | Spec DoD gates map 1:1 to the constitution gates. |
| Boot Flow Rule (singleton + named variable + private ctor) | ✅ | User's original constructor-injection sketch was reinterpreted at the clarification gate (chosen Option A) to use `Settings::instance()` etc., consistent with Phase 1's Menu.php and the constitution Module Contract item 2. |
| Admin Partials Rule | ✅ | All six ported files declare namespace `AcrossAI_MCP_Manager\Admin\Partials`. |
| PHP Namespace Rule | ✅ | Namespaces mirror directory paths. |
| Module Contract (private ctor, deps via `::instance()`, no sibling-to-sibling) | ✅ | Settings calls `ApplicationPasswords::instance()` internally when delegating to the Tokens tab. No sibling instance is constructor-injected. `MCPServer\Query` is a vendor (BerlinDB) class instantiated per-query inside Settings methods — `new MCPServer\Query()` is permitted for library classes, the prohibition applies only to feature classes. |

**Result**: All gates pass. **Complexity Tracking section is empty** — no
justified deviations.

## Project Structure

### Documentation (this feature)

```text
specs/002-admin-ui/
├── plan.md              # THIS FILE
├── spec.md              # Feature spec (input)
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
│   ├── loader-wiring.md       # Contract: what hooks Main::define_admin_hooks() registers
│   └── notice-dismissal.md    # Contract: JS↔PHP for the adapter-missing notice
├── checklists/
│   └── requirements.md  # Quality checklist (from /speckit-specify)
└── tasks.md             # Phase 2 output (NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
admin/
├── Main.php                              # EXTEND — implement enqueue_assets() guarded by get_current_screen()
└── Partials/
    ├── Menu.php                          # EXTEND — flesh out register_menu() + plugin_action_links()
    ├── Settings.php                      # NEW — 1:1 port from src/Admin/Settings.php (~2615 lines)
    ├── SettingsRenderer.php              # NEW — 1:1 port from src/Admin/SettingsRenderer.php (~25 lines)
    ├── ApplicationPasswords.php          # NEW — 1:1 port from src/Admin/ApplicationPasswords.php (~423 lines)
    ├── MCPServerListTable.php            # NEW — 1:1 port; Query swap; extends WP_List_Table
    └── CliAuthLogListTable.php           # NEW — 1:1 port; Query swap; extends WP_List_Table

includes/
└── Main.php                              # EXTEND — replace Phase 1 TODO stubs in define_admin_hooks()
                                          #          with actual Loader wiring per FR-021 (see Phase 1
                                          #          contracts/loader-wiring.md for the exact contract)

build/
├── js/backend.asset.php                  # EXISTS (from prior phase) — consumed by Admin\Main::enqueue_assets()
└── css/backend.asset.php                 # EXISTS (from prior phase)

# NOT in this phase (deferred):
# - admin/Partials/ConnectorAuditLogListTable.php  → Phase 6 (OAuth/Claude Connectors)
# - includes/Database/MCPServer/Query.php          → P0 dependency (must exist before Phase 2 starts)
# - includes/Database/CliAuthLog/Query.php         → P0 dependency
```

**Structure Decision**: Existing WordPress-plugin layout from the
constitution Architecture & UI Standards. No new top-level directories.
Every new file lives under `admin/Partials/`. The only edit outside
`admin/` is replacing the Phase 1 TODO stubs in `includes/Main.php`.

## Phase 0 — Outline & Research

Five focused research outputs land in `research.md`:

1. **BerlinDB Query method-name mapping** — for every `MCPServerTable::*()`
   call site in the source `src/Admin/Settings.php`, record the equivalent
   `MCPServer\Query` instance method. The source uses:
   `get_all()`, `get_by_id()`, `slug_exists()`, `create_server()`,
   `update_server()`, `update_claude_connector_settings()`,
   `delete_server()`, `toggle_status()`. These map to BerlinDB's
   `query([])`, `query(['id'=>$id])` (with `number=>1`),
   `query(['slug'=>$slug, 'number'=>1])`, `add_item([])`,
   `update_item($id, [])`, `update_item($id, ['claude_*' => ...])`,
   `delete_item($id)`, `update_item($id, ['status' => $flip])`. Document
   the canonical map so each call site is replaced consistently.

2. **Singleton pattern retrofit for ported files** — confirm the four
   ceremony lines added to each new partial: `protected static $_instance =
   null;`, `private function __construct() { ... }`,
   `public static function instance(): self { ... }`, and removal of
   `add_action`/`add_filter` from the constructor body. Note the small risk
   in `Settings.php`: the source constructor body sets `$this->plugin_name`
   and `$this->version` from `ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG` and
   `ACROSSAI_MCP_MANAGER_VERSION` constants — those are safe pure assigns
   and stay in the new constructor body.

3. **Notice-dismissal endpoint choice — admin-ajax vs REST** — FR-015
   requires a small write endpoint when the user clicks the X. Two options:
   - **Admin-ajax** (`wp_ajax_acrossai_mcp_dismiss_adapter_notice`):
     standard core pattern, no REST namespace pollution, smaller surface.
   - **REST** (`/wp-json/acrossai-mcp/v1/notices/dismiss`): future-proof
     but the spec says "this phase adds no REST routes". Choose **admin-ajax**
     to honour the spec scope.

4. **Settings::render_access_control_tab callable resolution** — the user
   input proposed passing the `AccessControlManager` instance via
   constructor/setter. Under the singleton pattern this becomes: when the
   tab body executes, `Settings::render_access_control_tab()` calls
   `\WPBoilerplate\AccessControl\AccessControlManager::instance()`
   internally (vendor singleton). The Settings class does NOT hold a
   long-lived reference. `class_exists()` guard wraps the call.

5. **`get_current_screen()` whitelist for the asset guard** — confirm the
   exact screen IDs produced by `add_menu_page('mcp-manager', ..., 'acrossai_mcp_manager', ...)`
   and `add_submenu_page('acrossai_mcp_manager', ..., '<submenu_slug>', ...)`.
   WordPress generates `toplevel_page_acrossai_mcp_manager` for the parent
   and `mcp-manager_page_<submenu_slug>` for submenus (the menu-title slug,
   lowercased + hyphens, plus `_page_` + child slug). Record the exact list
   so `Admin\Main::enqueue_assets()` can match by `$screen->id`.

## Phase 1 — Design & Contracts

### data-model.md

Captures four entities surfaced by this phase:

- **MCP Server row** (`{wpdb->prefix}acrossai_mcp_servers`) — BerlinDB
  schema reference; column list inherited from source `MCPServerTable.php`
  schema (carried over verbatim by the prerequisite Query phase).
- **CLI Auth Log entry** (`{wpdb->prefix}acrossai_mcp_cli_auth_log`) —
  read-only from this phase; column list inherited.
- **Application Password** (per-server, WordPress core data model) —
  metadata stored as application password user meta scoped to a synthetic
  per-server user (carried over verbatim from source).
- **Notice dismissal flag** — single `user_meta` row keyed
  `acrossai_mcp_dismissed_adapter_notice` (sticky, per Q3 clarification).

State transitions captured for the MCP Server row: `enabled ↔ disabled` via
toggle; **created → enabled → (toggled) → deleted** for the lifecycle. No
state machine beyond binary status.

### contracts/loader-wiring.md

The single contract that locks down what `Includes\Main::define_admin_hooks()`
registers. Authoritative for FR-021. Documents:

- Named singleton variable per partial
  (`$menu = Menu::instance();` etc.)
- Hook name, priority, accepted-args, callable for every wiring line
- The `class_exists()` guard wrapping the access-control wiring
- The `class_exists('\WP\MCP\Plugin')`-NEGATED guard wrapping the
  adapter-missing notice wiring

### contracts/notice-dismissal.md

The admin-ajax contract for the adapter-missing notice dismissal click:

- Endpoint: `wp-admin/admin-ajax.php?action=acrossai_mcp_dismiss_adapter_notice`
- Method: POST
- Body: `_ajax_nonce` (required)
- Server-side: `check_ajax_referer('acrossai_mcp_dismiss_adapter_notice')` +
  `current_user_can('manage_options')` + `update_user_meta($user_id,
  'acrossai_mcp_dismissed_adapter_notice', 1)`
- Response: `wp_send_json_success()` on success, `wp_send_json_error()` on
  failure
- JS contract: small inline script enqueued only when the notice is
  rendered, attached to the dismiss button's `click` event; reuses the
  standard `wp.updates` / `dismissNotice` UX where available, otherwise
  posts directly with `wp.apiFetch` or a fetch().

### quickstart.md

Manual verification script for a human or a CI job:

1. Activate the plugin on a clean WP 6.9 / PHP 8.0 install.
2. Confirm the parent menu and submenus render (US1).
3. Confirm the "Settings" plugin-action link points to `?page=acrossai_mcp_manager` (US1.4).
4. Create a new server via the "Add New" form (US2.7); confirm row appears.
5. Toggle the row's status (US2.2); confirm admin notice.
6. Edit each of the four tabs and save; confirm persistence (US3).
7. Submit a forged request with the nonce removed; confirm `wp_die()` (SC-002).
8. Impersonate an Editor; confirm `wp_die()` on toggle (SC-003).
9. Open Dashboard; confirm no `backend.js` / `backend.css` enqueued (SC-004).
10. Remove the MCP adapter package; confirm dismissible notice (US4); dismiss; reload; confirm gone (SC-005).
11. Deactivate `wpb-access-control`; confirm Access Control submenu gone and tab renders info notice (SC-006).

### Agent context update

This plan path will be inserted between the `<!-- SPECKIT START -->` and
`<!-- SPECKIT END -->` markers in `CLAUDE.md` so downstream agents pick up
`specs/002-admin-ui/plan.md` as the active plan.

## Complexity Tracking

> *Empty by design — Constitution Check passes without justified deviation.*

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| *(none)* | *(n/a)* | *(n/a)* |
