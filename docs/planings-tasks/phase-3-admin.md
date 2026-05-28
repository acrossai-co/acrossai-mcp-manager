# Phase 3 — Admin UI Migration

## Source Files to Read First

> Before writing any spec or code, read these files from the **source repo**.
> Full paths are in [`source-map.md`](source-map.md).

```
SOURCE repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager/

Read these files:
  src/Admin/Settings.php
  src/Admin/SettingsRenderer.php
  src/Admin/ApplicationPasswords.php
  src/Admin/MCPServerListTable.php
  src/Admin/CliAuthLogListTable.php
  src/Admin/ConnectorAuditLogListTable.php

TARGET repo root:
/Users/raftaar1191/local-sites/wordpress-7-0/app/public/wp-content/mcp-migration/acrossai-mcp-manager-new/

Write new files into:
  admin/Partials/Settings.php
  admin/Partials/SettingsRenderer.php
  admin/Partials/ApplicationPasswords.php
  admin/Partials/MCPServerListTable.php
  admin/Partials/CliAuthLogListTable.php
  admin/Partials/ConnectorAuditLogListTable.php

Extend (do NOT replace) these existing files:
  admin/Main.php               ← add enqueue calls here
  admin/Partials/Menu.php      ← extend if needed
  includes/Main.php            ← wire all admin hooks via Loader here
```

---

## What this phase covers

Migrates all admin-facing classes from `src/Admin/` into `admin/Partials/`
and wires them into `includes/Main.php::define_admin_hooks()` via the Loader.

### Old files to migrate

| Old path | New path | Responsibility |
|---|---|---|
| `src/Admin/Settings.php` | `admin/Partials/Settings.php` | Admin menu + server list + edit page |
| `src/Admin/SettingsRenderer.php` | `admin/Partials/SettingsRenderer.php` | HTML rendering for settings tabs |
| `src/Admin/ApplicationPasswords.php` | `admin/Partials/ApplicationPasswords.php` | Application password management |
| `src/Admin/MCPServerListTable.php` | `admin/Partials/MCPServerListTable.php` | WP_List_Table for servers |
| `src/Admin/CliAuthLogListTable.php` | `admin/Partials/CliAuthLogListTable.php` | WP_List_Table for CLI auth log |
| `src/Admin/ConnectorAuditLogListTable.php` | `admin/Partials/ConnectorAuditLogListTable.php` | WP_List_Table for OAuth audit log |

### Critical hook migration

The old `Settings` constructor calls `add_action()` directly:
```php
// OLD (WRONG — must not exist in new code)
add_action( 'admin_init', array( $this, 'handle_actions' ), 5 );
add_action( 'admin_init', array( $this, 'register_settings' ) );
add_action( 'admin_menu', array( $this, 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
```

In the new code these become (in `includes/Main.php::define_admin_hooks()`):
```php
// NEW (correct — all through Loader)
$settings = new Admin\Partials\Settings();
$this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );
$this->loader->add_action( 'admin_init', $settings, 'register_settings' );
$this->loader->add_action( 'admin_menu', $settings, 'register_menu' );
$this->loader->add_action( 'admin_enqueue_scripts', $settings, 'enqueue_assets' );
```

---

## Spec-Kit Steps

### Step 1: `/speckit.specify`

```
/speckit.specify

Feature: Admin UI — Settings, List Tables, and Asset Enqueue
Feature number: 003

We are migrating all admin-facing PHP classes from src/Admin/ into admin/Partials/
and wiring their hooks through the Loader in includes/Main.php.

Functional requirements:

1. Admin menu structure must be preserved:
   - Top-level menu: "MCP Manager" (slug: acrossai_mcp_manager)
   - Submenus: Servers, CLI Auth Log, Access Control (if access control lib present)
   - Plugin action links: "Settings" link points to the plugin admin page

2. Server list page (?page=acrossai_mcp_manager):
   - Uses WP_List_Table to display all MCP server rows from the DB
   - Columns: Name, Slug, Status (enabled/disabled), Registered From,
     Route Namespace, Route, Version, Actions
   - Bulk actions: enable, disable, delete
   - Row actions: Edit, Toggle Status, Delete
   - All toggle/delete actions require a valid nonce and manage_options capability

3. Server edit page (?page=acrossai_mcp_manager&action=edit&server=ID):
   - Tabbed interface: General, Tokens, Access Control, Claude Connector
   - General tab: edit name, description, route namespace, route, version
   - Tokens tab: Application Passwords management for the server
   - Access Control tab: delegated to AccessControlManager
   - Claude Connector tab: OAuth client ID, secret, redirect URI fields
   - Save action: nonce check + manage_options + sanitize + DB update

4. Admin notices:
   - Show dismissible notice if the MCP adapter package (\WP\MCP\Plugin) is not installed
   - Show success/error admin notices after settings save or server toggle

5. Asset enqueue (admin_enqueue_scripts):
   - Only enqueue on plugin admin pages (guard with get_current_screen())
   - Read version + deps from build/js/backend.asset.php and build/css/backend.asset.php
   - Never hardcode version or dependency array

6. All classes remove direct add_action() / add_filter() calls from constructors.
   Hooks are registered externally via Loader in Main::define_admin_hooks().

7. DB calls go through the new BerlinDB Query classes (Phase 1) — not static
   MCPServerTable:: calls.

8. Namespace: AcrossAI_MCP_Manager\Admin\Partials for all classes in admin/Partials/
```

### Step 2: `/speckit.plan`

```
/speckit.plan

Technical approach for Admin UI migration:

File placement:
- admin/Partials/Settings.php (namespace: AcrossAI_MCP_Manager\Admin\Partials)
- admin/Partials/SettingsRenderer.php
- admin/Partials/ApplicationPasswords.php
- admin/Partials/MCPServerListTable.php   (extends WP_List_Table)
- admin/Partials/CliAuthLogListTable.php  (extends WP_List_Table)
- admin/Partials/ConnectorAuditLogListTable.php (extends WP_List_Table)

Constructors: Remove ALL add_action() / add_filter() calls.
Each class receives its dependencies via constructor injection
(e.g. Settings receives MCPServer\Query and ApplicationPasswords instances).

Hook wiring in includes/Main.php::define_admin_hooks():
  $settings = new Admin\Partials\Settings( new Includes\Database\MCPServer\Query(), new Admin\Partials\ApplicationPasswords() );
  $this->loader->add_action( 'admin_init', $settings, 'handle_actions', 5 );
  $this->loader->add_action( 'admin_init', $settings, 'register_settings' );
  $this->loader->add_action( 'admin_menu', $settings, 'register_menu' );
  $this->loader->add_action( 'admin_enqueue_scripts', $settings, 'enqueue_assets' );

  // Missing adapter notice
  if ( ! class_exists('\WP\MCP\Plugin') ) {
      $this->loader->add_action( 'admin_notices', $settings, 'render_missing_adapter_notice' );
  }

  $main_menu = new Admin\Partials\Menu( $this->get_plugin_name(), $this->get_version() );
  $this->loader->add_action( 'admin_menu', $main_menu, 'main_menu' );
  $this->loader->add_action( 'plugin_action_links', $main_menu, 'plugin_action_links', 1000, 2 );

DB calls: Replace all ACROSSAI_MCP_MANAGER\Database\MCPServerTable::method()
  with $this->mcp_query->method() where $this->mcp_query is the injected
  MCPServer\Query instance.

Asset enqueue: Load manifest at top of enqueue_assets() method:
  $asset = include ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/backend.asset.php';
  wp_enqueue_script( 'acrossai-mcp-backend', ...$asset['dependencies'], $asset['version'] );

Access control integration: Settings::render_access_control_tab() receives the
  AccessControlManager instance from Main.php (passed as constructor arg or via
  a dedicated setter called in define_admin_hooks()).
```

### Step 3 + 4: `/speckit.tasks` then `/speckit.implement`

---

## Success Criteria

- [ ] All six files exist in `admin/Partials/`
- [ ] No `add_action()` / `add_filter()` calls inside class constructors
- [ ] Admin menu appears at `/wp-admin/admin.php?page=acrossai_mcp_manager`
- [ ] Server list loads and displays DB rows
- [ ] Server edit page saves with nonce + capability check enforced
- [ ] Assets enqueue only on plugin pages (not globally)
- [ ] Asset version/deps read from `build/*.asset.php`
- [ ] PHPCS passes on all new files
