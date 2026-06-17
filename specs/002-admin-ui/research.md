# Research — Phase 2: Admin UI Migration

**Date**: 2026-06-17 | **Branch**: `002-admin-ui`

---

## R1. BerlinDB Query method-name mapping

**Decision**: Every `MCPServerTable::*()` static call in the source
`src/Admin/Settings.php` is replaced per this canonical map. The map is
authoritative — no ad-hoc swaps in the implementation.

| Source call (forbidden in new code) | BerlinDB replacement (new code) |
|---|---|
| `MCPServerTable::get_all()` | `( new MCPServer\Query() )->query( [] )` (returns array of row objects) |
| `MCPServerTable::get_by_id( $id )` | `( new MCPServer\Query() )->query( [ 'id' => $id, 'number' => 1 ] )[0] ?? null` |
| `MCPServerTable::slug_exists( $slug )` | `! empty( ( new MCPServer\Query() )->query( [ 'slug' => $slug, 'number' => 1 ] ) )` |
| `MCPServerTable::create_server( $name, $description, $namespace, $route, $version )` | `( new MCPServer\Query() )->add_item( [ 'name' => $name, 'description' => $description, 'route_namespace' => $namespace, 'route' => $route, 'version' => $version, 'status' => 'enabled' ] )` (returns new ID) |
| `MCPServerTable::update_server( $id, $data )` | `( new MCPServer\Query() )->update_item( $id, $data )` |
| `MCPServerTable::update_claude_connector_settings( $id, $data )` | `( new MCPServer\Query() )->update_item( $id, $data )` (BerlinDB accepts the same column-keyed array; no separate method needed) |
| `MCPServerTable::delete_server( $id )` | `( new MCPServer\Query() )->delete_item( $id )` |
| `MCPServerTable::toggle_status( $id )` | Read current row first (`get_by_id` map above), flip status, then `update_item( $id, [ 'status' => $flipped ] )`. Two-step is required because BerlinDB has no native toggle helper. |

**Rationale**: BerlinDB exposes a uniform 4-method interface
(`query` / `add_item` / `update_item` / `delete_item`) over every custom
table. Source code's bespoke statics (`slug_exists`, `toggle_status`) are
expressed via that uniform interface — no new helper class.

**Alternatives considered**:
- Wrap BerlinDB in a `MCPServerRepository` facade that mimics the old
  static signature: rejected — adds an abstraction layer for one consumer,
  violates Constitution Principle VI (DRY/reusability prefers vendor
  packages over new helpers).
- Add `slug_exists()` and `toggle_status()` helpers to `MCPServer\Query` as
  custom BerlinDB methods: rejected — the prerequisite Query class is owned
  by an earlier DB phase; modifying it here couples Phase 2 to that phase
  and risks cross-phase merge churn.

**Risk**: BerlinDB's `update_item()` accepts only known column names. If
`update_claude_connector_settings()` in the source repo passes a key that
doesn't map to a BerlinDB-declared column, the write silently no-ops. The
implementation MUST verify against the prerequisite `Includes\Database\MCPServer\Table::set_schema()`
column list before merging.

---

## R2. Singleton-pattern retrofit for ported files

**Decision**: Every new file under `admin/Partials/` declares the standard
singleton ceremony — identical to Phase 1's `admin/Partials/Menu.php`:

```php
namespace AcrossAI_MCP_Manager\Admin\Partials;

defined( 'ABSPATH' ) || exit;

class Settings {

    /** @var Settings|null */
    protected static $_instance = null;

    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    public static function instance(): self {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        $this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
        $this->version     = ACROSSAI_MCP_MANAGER_VERSION;
        // NO add_action / add_filter here.
    }

    // ...all source methods follow, unchanged except for the four migration changes.
}
```

The source `Settings::__construct()` body currently registers four
`add_action` lines (`admin_init` ×2, `admin_menu`, `admin_enqueue_scripts`).
All four MOVE to `Includes\Main::define_admin_hooks()`. Nothing else in the
source constructor needs to change.

**Rationale**: Matches Constitution Architecture & UI Standards — Boot
Flow Rule, and matches the existing Phase 1 partial. Identical ceremony
across the codebase reduces cognitive load.

**Alternatives considered**:
- Constructor injection of `MCPServer\Query` (user's original sketch):
  rejected at clarification gate — violates Constitution Module Contract
  item 2.
- Static-only Settings with no instance state: rejected — source code uses
  `$this->plugin_name` and `$this->version` for rendering, and the static
  rewrite would touch every render method.

---

## R3. Notice-dismissal endpoint choice — admin-ajax over REST

**Decision**: Admin-ajax. Endpoint
`wp-admin/admin-ajax.php?action=acrossai_mcp_dismiss_adapter_notice`.

**Rationale**:
- Spec scope: "This phase adds no REST routes." (spec.md §REST API Contract)
- Admin-ajax is the WordPress-native dismissible-notice pattern: simpler
  surface, no REST namespace pollution, no `permission_callback` ceremony.
- The endpoint is admin-only, manage_options-gated, nonce-protected — no
  benefit from REST's URL structure.

**Alternatives considered**:
- REST endpoint `POST /wp-json/acrossai-mcp/v1/notices/dismiss`: rejected —
  out of spec scope; would land in Phase 6.
- Per-page-load `?dismiss_notice=adapter` query parameter + page reload:
  rejected — UX regression vs the standard X-button.

---

## R4. Settings::render_access_control_tab callable resolution

**Decision**: When the Access Control tab body executes,
`Settings::render_access_control_tab()` calls
`\WPBoilerplate\AccessControl\AccessControlManager::instance()` directly,
inside a `class_exists()` guard. Settings does NOT hold a long-lived
reference to the manager.

```php
public function render_access_control_tab( int $server_id ): void {
    if ( ! class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
        echo '<div class="notice notice-info inline"><p>';
        esc_html_e( 'Access Control requires the wpb-access-control package.', 'acrossai-mcp-manager' );
        echo '</p></div>';
        return;
    }
    \WPBoilerplate\AccessControl\AccessControlManager::instance()->render_tab( $server_id );
}
```

**Rationale**: Vendor classes follow their own conventions — the
constitution's Module Contract item 2 ("dependencies via `::instance()`")
applies. Holding a long-lived reference would defeat the whole point of
the singleton (resolve at use-site, not at boot).

**Alternatives considered**:
- Pass manager via constructor of Settings: rejected at clarification gate
  (Option A path).
- Pass manager via a setter called from `define_admin_hooks()`: rejected —
  same root issue, just delayed.

---

## R5. `get_current_screen()` whitelist for the asset guard

**Decision**: `Admin\Main::enqueue_assets()` whitelists these exact screen
IDs (matching the slugs from FR-001 / FR-002):

| Screen ID | Page |
|---|---|
| `toplevel_page_acrossai_mcp_manager` | Servers list page (parent) and edit page (`?action=edit&server=ID`) |
| `mcp-manager_page_acrossai_mcp_manager_cli_auth_log` | CLI Auth Log submenu |
| `mcp-manager_page_acrossai_mcp_manager_access_control` | Access Control submenu (only when vendor pkg present) |

WordPress derives the submenu prefix from the parent menu **title** —
sanitised to lowercase + hyphens. Parent title is `"MCP Manager"` → prefix
`mcp-manager`. The full submenu screen ID format is
`{parent_title_slug}_page_{submenu_slug}`.

The implementation matches via:

```php
$screen = get_current_screen();
if ( ! $screen ) {
    return;
}
$plugin_screens = [
    'toplevel_page_acrossai_mcp_manager',
    'mcp-manager_page_acrossai_mcp_manager_cli_auth_log',
    'mcp-manager_page_acrossai_mcp_manager_access_control',
];
if ( ! in_array( $screen->id, $plugin_screens, true ) ) {
    return;
}
```

**Rationale**: The `$screen->id` match is deterministic per the WordPress
core menu-slug algorithm (`sanitize_title( $menu_title )`). Matching on
`$_GET['page']` would also work but breaks when WordPress re-routes via
referer (e.g., after a redirect). The screen-ID list is canonical.

**Alternatives considered**:
- Match on `$_GET['page']` prefix: rejected — fragile under redirects.
- Match on `is_admin() && current_action() === '...'`: rejected — too broad,
  enqueues on every wp-admin POST handler.

**Risk**: If a future submenu is added without updating this whitelist,
its assets won't enqueue. Mitigation: a unit-test snapshot on the
whitelist forces the dev to update both at once.
